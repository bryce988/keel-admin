<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\exception\ApiException;
use app\common\model\SysOperationLogModel;
use app\common\support\Arr;
use app\common\support\ClientIp;
use app\common\support\Ctx;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 操作日志
 *
 * 路由上声明即记录，控制器与 service 不用写一行落库代码：
 *
 *   Route::post('/users', [C::class, 'store'])->setParams([
 *       'perm' => 'sys:user:create',
 *       'log'  => ['module' => '系统管理/用户', 'action' => 1, 'title' => '新增用户'],
 *   ]);
 *
 * 失败的操作同样入库（status=0 + error_msg），审计时「谁试图做什么但被拒了」
 * 和「谁做成了什么」一样重要。
 */
class OperationLogMiddleware implements MiddlewareInterface
{
    /** 命中这些片段的入参一律不落库 */
    private const SENSITIVE = ['password', 'secret', 'token', 'captcha', 'credential', 'privatekey'];

    /**
     * 命中这些片段的入参**部分**脱敏（138****8000）
     *
     * 手机号受字段级权限保护（`sys:field:user:phone`），可操作日志是能导出的，
     * 而且带数据权限——部门主管看得到下属的日志。原样落库等于开了一个
     * 「从日志里读别人明文手机号」的旁路，把界面上辛苦做的脱敏绕过去。
     *
     * 不用全掩码是因为 `changes` 里存的就是 `138****8000`：
     * 同一条日志两个字段对不上，看的人会以为系统出了错。
     */
    private const PARTIAL = ['phone', 'mobile'];

    public function process(Request $request, callable $handler): Response
    {
        $meta = $request->route?->param('log');

        if (!is_array($meta)) {
            return $handler($request);
        }

        $start = microtime(true);

        try {
            $response = $handler($request);

            // ⚠️ 成败必须看**响应状态码**，不能靠 catch：
            // webman 给每一层中间件都套了 try/catch，内层抛的异常在到达这里之前
            // 已经被转成 Response 了（vendor/workerman/webman-framework/src/App.php 的 array_reduce）。
            // 早期版本用 catch 判定，结果所有失败操作都被记成成功。
            $status = $response->getStatusCode();
            $this->write($request, $meta, $start, $status < 400, $this->messageOf($response, $status));

            return $response;
        } catch (Throwable $e) {
            // 兜底：中间件链之外抛出的异常
            $message = $e instanceof ApiException ? $e->getMessage() : '服务异常：' . $e->getMessage();
            $this->write($request, $meta, $start, false, $message);

            throw $e;
        }
    }

    /** 错误响应体里的 message，用于日志的失败原因 */
    private function messageOf(Response $response, int $status): string
    {
        if ($status < 400) {
            return '';
        }

        $body = json_decode((string) $response->rawBody(), true);

        return is_array($body) && isset($body['message'])
            ? (string) $body['message']
            : 'HTTP ' . $status;
    }

    private function write(Request $request, array $meta, float $start, bool $success, string $error): void
    {
        try {
            $user = Ctx::user() ?? [];

            SysOperationLogModel::create([
                'trace_id'   => Ctx::traceId(),
                'user_id'    => (int) ($user['id'] ?? 0),
                'username'   => (string) ($user['username'] ?? ''),
                'dept_id'    => (int) ($user['dept_id'] ?? 0),
                'module'     => (string) ($meta['module'] ?? ''),
                'action'     => (int) ($meta['action'] ?? SysOperationLogModel::ACTION_OTHER),
                'title'      => (string) ($meta['title'] ?? ''),
                'target'     => (string) Ctx::get('log.target', ''),
                'api_method' => $request->method(),
                'api_path'   => $request->path(),
                'ip'         => ClientIp::of($request),
                'user_agent' => mb_substr((string) $request->header('user-agent', ''), 0, 255),
                'params'     => $this->maskParams($request),
                'changes'    => Ctx::get('log.changes'),
                'status'     => $success ? 1 : 0,
                'error_msg'  => mb_substr($error, 0, 500),
                'duration'   => (int) round((microtime(true) - $start) * 1000),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // 记日志失败绝不能影响主流程，转为错误日志
            \support\Log::channel('error')->error('[操作日志写入失败] ' . $e->getMessage());
        }
    }

    /** 密码、验证码等一律替换为掩码，不进数据库 */
    private function maskParams(Request $request): array
    {
        $params = $request->all();

        array_walk_recursive($params, function (&$value, $key) {
            $lower = strtolower((string) $key);
            foreach (self::SENSITIVE as $word) {
                if (str_contains($lower, $word)) {
                    $value = '******';

                    return;
                }
            }
            foreach (self::PARTIAL as $word) {
                if (str_contains($lower, $word) && is_string($value)) {
                    $value = Arr::mask($value);

                    return;
                }
            }
            if (is_string($value) && mb_strlen($value) > 500) {
                $value = mb_substr($value, 0, 500) . '…';
            }
        });

        return $params;
    }
}
