<?php
/**
 * keel admin
 * 队列与定时任务监控
 *
 * 只读 Redis 里的实时状态，**不建表**：队列的 waiting / delayed / failed 三份数据
 * 本身就存在 Redis 里，再抄一份进 MySQL 只会有两个都不准的事实源。
 * 代价是这里看不到「历史执行记录」（成功的任务消费完就没了），
 * 要那个得让每个消费者自己落库，那是业务的事，不是脚手架的事。
 *
 * ## Redis 里的三种结构（`workerman/redis-queue` 的 Client 常量，不是我们定的）
 *
 *     {redis-queue}-waiting<队列名>   list    每个队列一条，LLEN 即积压数
 *     {redis-queue}-delayed          zset    **全局一条**，score 是到期时间戳
 *     {redis-queue}-failed           list    **全局一条**，重试 5 次仍失败的原始消息
 *
 * 后两者是所有队列混在一起的，所以「某个队列有多少延迟/失败」只能把成员捞出来
 * 按 payload 里的 `queue` 字段分组。全量捞可能是几十万条，因此只取最近
 * `SCAN_LIMIT` 条做分组，总数仍用 ZCARD/LLEN 取准值——界面上二者不一致时
 * （`sampled = true`）说明积压已经超出采样窗口，那本身就是要处理的信号。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\service;

use app\common\exception\BusinessException;
use app\common\exception\NotFoundException;
use app\common\model\SysTaskLogModel;
use app\common\support\Env;
use app\common\support\OpLog;
use app\process\TaskProcess;
use Illuminate\Database\Eloquent\Builder;
use Redis as PhpRedis;
use ReflectionClass;
use Throwable;
use Webman\RedisQueue\Consumer;
use Webman\RedisQueue\Redis;
use Webman\RedisQueue\RedisConnection;
use Workerman\Crontab\Parser;

class QueueService
{
    /** @see \Workerman\RedisQueue\Client::QUEUE_WAITING */
    private const KEY_WAITING = '{redis-queue}-waiting';

    /** @see \Workerman\RedisQueue\Client::QUEUE_DELAYED */
    private const KEY_DELAYED = '{redis-queue}-delayed';

    /** @see \Workerman\RedisQueue\Client::QUEUE_FAILED */
    private const KEY_FAILED = '{redis-queue}-failed';

    /**
     * 分组与列表的采样窗口
     *
     * 定这个上限是因为 delayed/failed 是全局结构：真出事的时候（下游挂了、
     * 消费者一直抛异常）它能涨到几十万条，一次 LRANGE 全量取回来足以把
     * 这个 worker 的内存打爆——而这恰恰是最需要打开监控页的时刻。
     */
    private const SCAN_LIMIT = 2000;

    /** 找下次执行时间时最多往后推几分钟（2 天）。日更任务必定命中，更稀疏的规则返回 null */
    private const NEXT_RUN_LOOKAHEAD = 2880;

    // ---------------------------------------------------------------- 概览

    /**
     * 队列 + 进程 + 定时任务的全景
     *
     * Redis 连不上时不整页报错：进程与定时任务这两块是读配置得来的，
     * 与 Redis 无关，正好留着告诉运维「该看的是 Redis 而不是队列」。
     */
    public static function overview(): array
    {
        try {
            $queues = self::queues();
            $redis  = self::redisStatus();
        } catch (Throwable $e) {
            $queues = [];
            $redis  = ['connected' => false, 'error' => $e->getMessage()];
        }

        return [
            'queues'    => $queues,
            'redis'     => $redis,
            'processes' => self::processes(),
            'tasks'     => self::tasks(),
        ];
    }

    /**
     * 每个队列一行
     *
     * 队列有两个来源，缺一不可：
     * - `app/queue/` 下的消费者（声明了但可能一条消息都没有，也要显示）
     * - Redis 里已存在的 waiting key（**没有**对应消费者的队列——投递方拼错了
     *   队列名、或者消费者被删了。这种队列不显示的话，表现就是「消息投出去了，
     *   石沉大海」，而监控页一片正常）
     *
     * @return list<array<string, mixed>>
     */
    private static function queues(): array
    {
        $conn      = self::conn();
        $consumers = self::consumers();
        $delayed   = self::groupByQueue(self::delayedMembers($conn));
        $failed    = self::groupByQueue($conn->lRange(self::KEY_FAILED, 0, self::SCAN_LIMIT - 1) ?: []);

        $names = array_unique(array_merge(array_keys($consumers), self::waitingQueueNames($conn)));
        sort($names);

        $rows = [];
        foreach ($names as $name) {
            $rows[] = [
                'queue'      => $name,
                'desc'       => $consumers[$name]['desc'] ?? '',
                'consumer'   => $consumers[$name]['class'] ?? '',
                'connection' => $consumers[$name]['connection'] ?? '',
                // 没有消费者的队列：积压只会一直涨。这是本页最该被一眼看到的异常
                'orphan'     => !isset($consumers[$name]),
                'waiting'    => (int) $conn->lLen(self::KEY_WAITING . $name),
                'delayed'    => count($delayed[$name] ?? []),
                'failed'     => count($failed[$name] ?? []),
            ];
        }

        return $rows;
    }

    /**
     * Redis 与两个全局结构的总量
     *
     * `delayed_total` / `failed_total` 取的是准确总数（ZCARD/LLEN），
     * 而上面每行的 delayed/failed 是采样窗口内的计数。`sampled` 为真时
     * 两者对不上是正常的，前端要如实说明，不能把采样数当成总数展示。
     */
    private static function redisStatus(): array
    {
        $conn        = self::conn();
        $delayed     = (int) $conn->zCard(self::KEY_DELAYED);
        $failed      = (int) $conn->lLen(self::KEY_FAILED);
        $info        = $conn->info('memory') ?: [];
        $queueConfig = config('plugin.webman.redis-queue.redis.default', []);

        return [
            'connected'     => true,
            'db'            => (int) ($queueConfig['options']['db'] ?? 0),
            'used_memory'   => (string) ($info['used_memory_human'] ?? ''),
            'delayed_total' => $delayed,
            'failed_total'  => $failed,
            'sampled'       => $delayed > self::SCAN_LIMIT || $failed > self::SCAN_LIMIT,
            'scan_limit'    => self::SCAN_LIMIT,
            'max_attempts'  => (int) ($queueConfig['options']['max_attempts'] ?? 0),
            'retry_seconds' => (int) ($queueConfig['options']['retry_seconds'] ?? 0),
        ];
    }

    /**
     * 进程编制
     *
     * 读的是**配置**而不是实际存活的进程数：HTTP worker 与消费进程是各自独立的
     * 进程，彼此看不到对方的运行状态，想拿真实存活数得去解析 workerman 的
     * 状态文件（`workerman/workerman` 的 `-s status`），那是另一件事。
     * 配置值回答的是「本该跑几个」，配合下面 `pid`/`memory_mb` 的自报，
     * 足够回答「队列在不在跑」——真不跑的话积压会涨，那一列才是硬指标。
     */
    private static function processes(): array
    {
        $consumer = config('plugin.webman.redis-queue.process.consumer', []);

        return [
            'http'          => (int) config('process.webman.count', 0),
            'task'          => (int) config('process.task.count', 0),
            'consumer'      => (int) ($consumer['count'] ?? 0),
            'consumer_dir'  => str_replace(base_path() . '/', '', (string) ($consumer['constructor']['consumer_dir'] ?? '')),
            'queue_workers' => Env::int('QUEUE_WORKERS', 2),
            // 当前这个 HTTP worker 的自报，用来确认「你看到的数据是哪个进程给的」
            'pid'           => getmypid(),
            'memory_mb'     => round(memory_get_usage(true) / 1048576, 1),
        ];
    }

    /**
     * 定时任务列表
     *
     * @return list<array<string, mixed>>
     */
    public static function tasks(): array
    {
        $consumers = self::consumers();

        return array_map(static function (array $task) use ($consumers): array {
            return $task + [
                // 定时任务只负责投递，真干活的是队列消费者。队列名对不上（改了名、
                // 删了消费者）时任务照跑不误，只是没人接活——这里显式标出来
                'consumer' => $consumers[$task['queue']]['class'] ?? '',
                'orphan'   => !isset($consumers[$task['queue']]),
                'next_run' => self::nextRun($task['rule']),
            ];
        }, TaskProcess::TASKS);
    }

    /**
     * 下次执行时间
     *
     * `Workerman\Crontab\Parser::parse()` 只回答「这一分钟命不命中」，不算下一次，
     * 所以只能逐分钟往后试。上限 2 天：日更任务必定命中，`0 0 1 1 *` 这种
     * 一年一次的规则返回 null——为了一个展示字段扫 52 万次不值得。
     */
    private static function nextRun(string $rule): ?string
    {
        $parser = new Parser();
        $ts     = strtotime(date('Y-m-d H:i:00')) + 60;

        for ($i = 0; $i < self::NEXT_RUN_LOOKAHEAD; $i++, $ts += 60) {
            try {
                if ($parser->parse($rule, $ts)) {
                    return date('Y-m-d H:i:s', $ts);
                }
            } catch (Throwable) {
                return null;  // 规则写错了，不是这个接口该报的错
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- 执行日志

    /** 列表可排序字段白名单 */
    public const TASK_LOG_SORTABLE = ['id', 'created_at', 'finished_at', 'duration_ms'];

    /**
     * 定时任务执行记录（分页查询）
     *
     * 这是本页唯一**落库**的东西：Redis 里的队列状态是「此刻」的快照，
     * 消费成功的消息消费完就没了，「昨天凌晨那次清理跑没跑、删了多少行」
     * 只能靠表回答（写入见 `TaskLogService`）。
     *
     * 没有数据权限：`sys_task_logs` 没有部门列，定时任务也不属于任何部门，
     * 能不能看由权限点决定。
     */
    public static function taskLogQuery(array $filters): Builder
    {
        return SysTaskLogModel::query()
            ->when(($filters['task_name'] ?? '') !== '', fn ($q) => $q->where('task_name', $filters['task_name']))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($q) => $q->where('status', (int) $filters['status']));
    }

    // ---------------------------------------------------------------- 失败任务

    /**
     * 失败任务列表（采样窗口内，内存分页）
     *
     * 不用 Redis 分页：`{redis-queue}-failed` 是所有队列混在一条 list 里的，
     * 按队列筛选之后 LRANGE 的下标与结果集的下标对不上，只能整段取回来再筛。
     * 窗口封顶 SCAN_LIMIT 条，够用——失败任务堆到两千条时，
     * 要做的是修消费者，而不是翻到第 87 页。
     *
     * @return array{list: list<array<string, mixed>>, total: int}
     */
    public static function failed(array $filters, int $pageNum, int $pageSize): array
    {
        $members = self::conn()->lRange(self::KEY_FAILED, 0, self::SCAN_LIMIT - 1) ?: [];
        $queue   = trim((string) ($filters['queue'] ?? ''));

        $rows = [];
        foreach ($members as $member) {
            $package = self::decode($member);
            if ($package === null || ($queue !== '' && ($package['queue'] ?? '') !== $queue)) {
                continue;
            }
            $rows[] = self::rowOf($package);
        }

        return [
            'total' => count($rows),
            'list'  => array_slice($rows, ($pageNum - 1) * $pageSize, $pageSize),
        ];
    }

    /**
     * 重新入队
     *
     * `attempts` 归零后按原队列名 LPUSH 回 waiting——不归零的话消费者一取到
     * 就发现 attempts 已经超过 max_attempts，立刻又打回 failed，
     * 点「重试」什么都不会发生，还看不出为什么。
     */
    public static function retry(string $id): array
    {
        [$member, $package] = self::findFailed($id);

        $queue = (string) ($package['queue'] ?? '');
        if ($queue === '') {
            throw new BusinessException('这条失败消息没有队列名，无法重投');
        }

        $conn = self::conn();

        // 先删后投：反过来的话，两步之间进程挂掉会留下一条重复消息。
        // 删失败（这一瞬间别人也点了重试）就直接停手，不能投出去
        if ((int) $conn->lRem(self::KEY_FAILED, $member, 1) !== 1) {
            throw new BusinessException('这条失败消息刚刚已被处理，请刷新后重试');
        }

        $package['attempts'] = 0;
        $conn->lPush(self::KEY_WAITING . $queue, self::encode($package));

        OpLog::target("队列 {$queue} / 消息 {$id}");

        return self::rowOf($package);
    }

    /** 丢弃一条失败消息（不可恢复：Redis 里删掉就没有别处存着了） */
    public static function discard(string $id): void
    {
        [$member, $package] = self::findFailed($id);

        if ((int) self::conn()->lRem(self::KEY_FAILED, $member, 1) !== 1) {
            throw new BusinessException('这条失败消息刚刚已被处理，请刷新后重试');
        }

        OpLog::target('队列 ' . ($package['queue'] ?? '') . " / 消息 {$id}");
    }

    /**
     * 按消息 id 在采样窗口里定位原始成员
     *
     * 返回原始字符串而不是重新编码的 JSON：LREM 按**值**匹配，
     * 重新编码出来的字符串（键顺序、转义、缩进）与写进去的那一条一旦有一点不同，
     * 删除就静默地删不掉——消费失败的消息于是永远删不掉。
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function findFailed(string $id): array
    {
        foreach (self::conn()->lRange(self::KEY_FAILED, 0, self::SCAN_LIMIT - 1) ?: [] as $member) {
            $package = self::decode($member);
            if ($package !== null && (string) ($package['id'] ?? '') === $id) {
                return [$member, $package];
            }
        }

        throw new NotFoundException('失败任务不存在，或已超出可操作的范围');
    }

    // ---------------------------------------------------------------- 内部

    private static function conn(): RedisConnection
    {
        return Redis::connection('default');
    }

    /**
     * `app/queue/` 下的消费者，按队列名索引
     *
     * 用反射读默认属性而不是 new 一个出来：消费者的构造函数将来可能要注入东西，
     * 监控页没有理由把它们实例化一遍。
     *
     * @return array<string, array{class:string, connection:string, desc:string}>
     */
    private static function consumers(): array
    {
        $result = [];

        foreach (glob(app_path() . '/queue/*.php') ?: [] as $file) {
            $class = 'app\\queue\\' . basename($file, '.php');
            if (!class_exists($class) || !is_subclass_of($class, Consumer::class)) {
                continue;
            }

            $props = (new ReflectionClass($class))->getDefaultProperties();
            $queue = (string) ($props['queue'] ?? '');
            if ($queue === '') {
                continue;
            }

            $result[$queue] = [
                'class'      => basename($file, '.php'),
                'connection' => (string) ($props['connection'] ?? 'default'),
                // 可选的一句用途说明。没写就空着——不编一个由类名推导的假说明，
                // 「ExportConsumer → 导出消费者」这种同义反复比空白更浪费一列
                'desc'       => (string) ($props['desc'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * Redis 里实际存在的 waiting 队列名
     *
     * 用 SCAN 而不是 KEYS：队列 db 平时只有几个 key，但监控页恰恰是在出事的时候
     * 打开的，那时 db 里可能有几十万个 key，一条 KEYS 就把 Redis 卡住了。
     *
     * @return list<string>
     */
    private static function waitingQueueNames(RedisConnection $conn): array
    {
        // 默认策略下 SCAN 空批次会返回 false，中途就会被误判成迭代结束
        $conn->setOption(PhpRedis::OPT_SCAN, PhpRedis::SCAN_RETRY);

        $names  = [];
        $cursor = null;

        do {
            $keys = $conn->scan($cursor, self::KEY_WAITING . '*', 200);
            foreach ($keys ?: [] as $key) {
                $names[] = substr((string) $key, strlen(self::KEY_WAITING));
            }
        } while ($cursor > 0 && count($names) < self::SCAN_LIMIT);

        return $names;
    }

    /**
     * delayed zset 的采样成员
     *
     * @return list<string>
     */
    private static function delayedMembers(RedisConnection $conn): array
    {
        return array_map('strval', $conn->zRange(self::KEY_DELAYED, 0, self::SCAN_LIMIT - 1) ?: []);
    }

    /**
     * 把混在一起的成员按 payload 里的 queue 字段分组
     *
     * @param  list<string>  $members
     * @return array<string, list<array<string, mixed>>>
     */
    private static function groupByQueue(array $members): array
    {
        $grouped = [];

        foreach ($members as $member) {
            $package = self::decode($member);
            if ($package === null) {
                continue;
            }
            $grouped[(string) ($package['queue'] ?? '')][] = $package;
        }

        return $grouped;
    }

    /**
     * 一条消息在界面上的样子
     *
     * `data` 原样给（它是投递方自己塞的业务参数，脱敏与否由投递方决定），
     * 但封顶 2KB：导出任务的 params 可能整份筛选条件都在里面，
     * 几百条一起返回会把响应撑到几 MB。
     */
    private static function rowOf(array $package): array
    {
        $data = json_encode($package['data'] ?? null, JSON_UNESCAPED_UNICODE) ?: 'null';

        return [
            'id'           => (string) ($package['id'] ?? ''),
            'queue'        => (string) ($package['queue'] ?? ''),
            'attempts'     => (int) ($package['attempts'] ?? 0),
            'max_attempts' => (int) ($package['max_attempts'] ?? 0),
            'delay'        => (int) ($package['delay'] ?? 0),
            'created_at'   => isset($package['time']) ? date('Y-m-d H:i:s', (int) $package['time']) : '',
            'data'         => mb_strlen($data) > 2048 ? mb_substr($data, 0, 2048) . '…' : $data,
        ];
    }

    /** @return array<string, mixed>|null 解不开就跳过：队列 db 里可能有别人的 key */
    private static function decode(string $member): ?array
    {
        $package = json_decode($member, true);

        return is_array($package) ? $package : null;
    }

    private static function encode(array $package): string
    {
        return json_encode($package, JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
