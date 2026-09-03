<?php
/**
 * keel admin
 * 数据导出（异步）
 *
 * 「点导出 → 立刻下载」在数据量大起来之后是必然失败的设计：请求要等文件生成完
 * 才有响应，几万行就是几十秒，中间任何一层（浏览器、nginx、负载均衡）超时都会
 * 让用户看到一个失败页，而文件其实已经在服务器上生成好了。而且 webman 是常驻
 * 内存的多进程模型，一个 worker 卡在导出上，这段时间它一个请求都接不了。
 *
 * 所以改成：点导出 → 建一条任务并投队列（毫秒级返回）→ 消费进程慢慢生成 →
 * 用户回到「数据导出」页下载。
 *
 * ## 消费进程里必须还原发起人身份（这是本模块最要命的一点）
 *
 * 数据权限与字段脱敏都读 `Ctx::user()`：
 * - `DataScope::apply()` 在 `Ctx::user() === null` 时**不注入任何条件**（那是给
 *   命令行脚本留的口子）
 * - `UserService::rowMapper()` 按当前用户的字段权限决定手机号给不给全
 *
 * 队列消费进程没有请求上下文，不还原身份的话，部门主管发起的导出会生成一份
 * **全公司**的名单，而且手机号还是明文——一次点击就把两道权限一起绕过去了。
 * 还原之后必须 `Ctx::clear()`：消费进程是常驻的，上一条任务的身份留下来，
 * 下一条就顶着别人的权限跑（CLAUDE.md §webman 红线）。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\service;

use app\common\constant\BizCode;
use app\common\constant\HttpStatus;
use app\common\exception\ApiException;
use app\common\exception\BusinessException;
use app\common\model\SysExportTaskModel;
use app\common\support\Ctx;
use app\common\support\Guard;
use app\common\support\OpLog;
use Illuminate\Database\Eloquent\Builder;
use support\Log;
use Throwable;
use Webman\RedisQueue\Redis;

class ExportService
{
    public const SORTABLE = ['id', 'status', 'created_at', 'finished_at'];

    /** 队列名，与 `app/queue/ExportConsumer.php` 的 $queue 必须一致 */
    public const QUEUE = 'keel:export';

    /**
     * 可导出的业务
     *
     * 登记表在 `config/export.php`——handler 指向各端自己的 service，
     * 写在这里就成了 `app/common` 反向依赖 `app/admin`。
     * 每次读一次配置：webman 的配置在进程启动时就已解析进内存，这里只是取数组。
     *
     * @return array<string, array{name:string, perm:string, handler:callable, file:string}>
     */
    public static function biz(): array
    {
        return config('export.biz', []);
    }

    // ---------------------------------------------------------------- 发起

    /**
     * 建任务并投递
     *
     * 筛选条件整份存下来：排队期间用户可能已经改了界面上的筛选、甚至关了页面，
     * 导出的必须是他**点下去那一刻**看到的那批数据。
     */
    public static function enqueue(string $biz, array $params): SysExportTaskModel
    {
        $meta = self::biz()[$biz] ?? throw new BusinessException("未知的导出类型：{$biz}");
        $user = Ctx::user() ?? [];

        $task = new SysExportTaskModel();
        $task->biz          = $biz;
        $task->biz_name     = $meta['name'];
        $task->params       = json_encode($params, JSON_UNESCAPED_UNICODE) ?: '{}';
        $task->status       = SysExportTaskModel::STATUS_PENDING;
        $task->creator_name = (string) ($user['real_name'] ?? ($user['username'] ?? ''));
        $task->dept_id      = (int) ($user['dept_id'] ?? 0);
        $task->save();

        /*
         * 投递失败不能让接口成功
         *
         * 任务行已经建好了，但没人会去消费它——界面上就是一条永远「排队中」的记录。
         * 与其留个假象，不如当场标失败并告诉用户，让他重试一次。
         */
        try {
            Redis::send(self::QUEUE, ['id' => (int) $task->id]);
        } catch (Throwable $e) {
            $task->status      = SysExportTaskModel::STATUS_FAILED;
            $task->error_msg   = '任务投递失败，请重试';
            $task->finished_at = date('Y-m-d H:i:s');
            $task->save();

            Log::error('导出任务投递失败', ['task' => $task->id, 'error' => $e->getMessage()]);

            throw new BusinessException('导出服务暂时不可用，请稍后重试');
        }

        OpLog::target("导出任务 {$meta['name']}({$task->id})");

        return $task;
    }

    // ---------------------------------------------------------------- 消费

    /**
     * 生成文件（队列消费进程调用）
     *
     * 全程不抛异常给调用方：失败也是一种结果，要落到任务行上让用户看见。
     * 抛出去只会进队列的重试与失败队列，而用户面前那条记录一直是「处理中」。
     */
    public static function run(int $taskId): void
    {
        /** @var ?SysExportTaskModel $task */
        $task = SysExportTaskModel::withoutDataScope()->find($taskId);

        if (!$task) {
            Log::warning('导出任务不存在，跳过', ['task' => $taskId]);

            return;
        }

        // 重复投递（队列重试、手工补投）时不重复生成：已经完成的任务再跑一遍
        // 只会把文件名换掉，而用户可能正拿着旧链接
        if ((int) $task->status !== SysExportTaskModel::STATUS_PENDING) {
            return;
        }

        $task->status     = SysExportTaskModel::STATUS_RUNNING;
        $task->started_at = date('Y-m-d H:i:s');
        $task->save();

        try {
            self::impersonate($task, function () use ($task) {
                $meta = self::biz()[$task->biz] ?? throw new BusinessException('导出类型已下线');

                if (!PermissionService::has(Ctx::user() ?? [], (string) $meta['perm'])) {
                    throw new BusinessException('发起人已不具备该导出权限');
                }

                $params = json_decode((string) $task->params, true) ?: [];
                $result = ($meta['handler'])($params);
                $path   = (string) $result['path'];

                $task->status      = SysExportTaskModel::STATUS_DONE;
                $task->row_count   = (int) ($result['rows'] ?? 0);
                $task->file_path   = $path;
                $task->file_name   = sprintf('%s_%s.xlsx', $meta['file'], date('Ymd_His'));
                $task->file_size   = (int) (@filesize($path) ?: 0);
                $task->expired_at  = date('Y-m-d H:i:s', time() + self::retainSeconds());
                $task->finished_at = date('Y-m-d H:i:s');
                $task->save();
            });
        } catch (Throwable $e) {
            $task->status = SysExportTaskModel::STATUS_FAILED;
            // 业务异常的话把原话给用户（「超过上限 50000 行」这种他自己能处理）；
            // 其他异常只给一句笼统的，细节进日志——堆栈里可能有连接串
            $task->error_msg   = $e instanceof BusinessException
                ? mb_substr($e->getMessage(), 0, 200)
                : '导出失败，请联系管理员';
            $task->finished_at = date('Y-m-d H:i:s');
            $task->save();

            Log::error('导出任务失败', [
                'task'  => $task->id,
                'biz'   => $task->biz,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 顶着发起人的身份执行
     *
     * `Ctx::clear()` 放在 finally 里，而且**必须**清：消费进程是常驻的，
     * 不清的话下一条任务会顶着上一条发起人的数据权限与字段权限跑。
     *
     * 账号被停用/删除时 `AuthService::loadUser()` 会抛异常，任务随之失败——
     * 这是对的：人都走了，他排队里的导出不该还在生成一份带手机号的名单。
     */
    private static function impersonate(SysExportTaskModel $task, callable $work): void
    {
        try {
            Ctx::set('user', AuthService::loadUser((int) $task->creator_id));
            $work();
        } finally {
            Ctx::clear();
        }
    }

    // ---------------------------------------------------------------- 列表与下载

    public static function listQuery(array $filters): Builder
    {
        $query = SysExportTaskModel::query();

        if (($filters['biz'] ?? '') !== '') {
            $query->where('biz', (string) $filters['biz']);
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('status', (int) $filters['status']);
        }

        return $query;
    }

    public static function rowMapper(): callable
    {
        return fn (SysExportTaskModel $row): array => [
            'id'           => $row->id,
            'biz'          => $row->biz,
            'biz_name'     => $row->biz_name,
            'status'       => $row->status,
            'row_count'    => $row->row_count,
            'file_name'    => $row->file_name,
            'file_size'    => $row->file_size,
            'error_msg'    => $row->error_msg,
            'creator_name' => $row->creator_name,
            'expired_at'   => $row->expired_at?->format('Y-m-d H:i:s'),
            'finished_at'  => $row->finished_at?->format('Y-m-d H:i:s'),
            'created_at'   => $row->created_at?->format('Y-m-d H:i:s'),
            /*
             * 能不能下载由服务端算，不让前端按 status 自己判
             *
             * 文件会被回收（过期、或者容器重建把 runtime 清了），此时 status 仍是
             * 「已完成」。前端只看 status 的话，下载按钮点下去才发现文件没了。
             */
            'downloadable' => self::downloadable($row),
            // 路径是服务器内部信息，永远不下发
        ];
    }

    private static function downloadable(SysExportTaskModel $task): bool
    {
        return (int) $task->status === SysExportTaskModel::STATUS_DONE
            && $task->file_path !== ''
            && is_file($task->file_path);
    }

    /**
     * 取下载信息
     *
     * 找不到走 404（数据权限外的记录同样是 404，不承认它存在）；
     * 文件没了走 410 Gone 而不是 404——记录还在、只是文件过期了，
     * 用户看到的应该是「重新导出一次」而不是「这条记录不存在」。
     */
    public static function download(int $id): array
    {
        /** @var SysExportTaskModel $task */
        $task = Guard::found(SysExportTaskModel::find($id));

        if (!self::downloadable($task)) {
            // 直接用基类而不是新开一个异常类：410 全站只有这一个抛出点，
            // 为它单独立一个类只会多一个文件
            throw new ApiException(
                HttpStatus::GONE,
                BizCode::EXPORT_FILE_GONE,
                '文件已过期或已被回收，请重新导出',
            );
        }

        return ['path' => (string) $task->file_path, 'name' => (string) $task->file_name];
    }

    public static function delete(int $id): void
    {
        /** @var SysExportTaskModel $task */
        $task = Guard::found(SysExportTaskModel::find($id));

        OpLog::target("导出任务 {$task->biz_name}({$task->id})");

        // 先删文件再删记录：反过来的话记录没了，文件就成了没人认领的垃圾
        if ($task->file_path !== '' && is_file($task->file_path)) {
            @unlink($task->file_path);
        }

        $task->delete();
    }

    /**
     * 清理过期任务（定时任务调用）
     *
     * 文件本身由 `Spreadsheet` 在写新文件时顺手回收，这里管的是**记录**：
     * 留着一堆下载不了的行，列表翻两页全是过期项，真正能下的反而找不着。
     *
     * @return array{deleted:int}
     */
    public static function cleanup(): array
    {
        $keepDays = max(1, (int) ParamService::value('sys.export.retainDays', 3));
        $before   = date('Y-m-d H:i:s', time() - $keepDays * 86400);

        $deleted = 0;

        // withoutDataScope：定时任务没有登录态，全表清理，属于 trait 注释里
        // 明确允许的三种绕过场景之一
        SysExportTaskModel::withoutDataScope()
            ->where('created_at', '<', $before)
            ->chunkById(500, function ($tasks) use (&$deleted) {
                foreach ($tasks as $task) {
                    if ($task->file_path !== '' && is_file($task->file_path)) {
                        @unlink($task->file_path);
                    }
                    $task->delete();
                    $deleted++;
                }
            });

        return ['deleted' => $deleted];
    }

    /** 文件保留时长（秒），与 Spreadsheet 的回收阈值同源 */
    public static function retainSeconds(): int
    {
        return max(1, (int) ParamService::value('sys.export.retainDays', 3)) * 86400;
    }
}
