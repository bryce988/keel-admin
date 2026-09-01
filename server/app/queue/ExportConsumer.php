<?php

declare(strict_types=1);

namespace app\queue;

use app\common\service\ExportService;
use support\Log;
use Throwable;
use Webman\RedisQueue\Consumer;

/**
 * 消费「生成导出文件」任务
 *
 * 导出正是队列存在的理由：几万行的 xlsx 要几十秒，放在 HTTP 请求里
 * 既会超时（浏览器、nginx 任一层），又会让那个 worker 在这段时间里
 * 一个请求都接不了——webman 是常驻内存的多进程模型，worker 数是固定的。
 *
 * 真正的活全在 `ExportService::run()` 里，包括**还原发起人身份**这件要命的事
 * （不还原的话数据权限与字段脱敏一起失效，见那边的注释）。
 */
class ExportConsumer implements Consumer
{
    public string $queue = ExportService::QUEUE;

    public string $connection = 'default';

    public function consume($data): void
    {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            Log::warning('导出队列收到无效消息', ['data' => $data]);

            return;
        }

        ExportService::run($id);
    }

    /**
     * 兜底
     *
     * `run()` 内部已经把失败写回任务行了，走到这里说明是它自己都没跑起来
     * （比如数据库连不上）。此时任务会一直停在「处理中」——只能靠日志发现，
     * 所以这条记 error 并带上任务 id，方便手工补投。
     */
    public function onConsumeFailure(Throwable $e, $package): void
    {
        Log::error('导出队列消费失败', [
            'task'     => $package['data']['id'] ?? 0,
            'error'    => $e->getMessage(),
            'attempts' => $package['attempts'] ?? 0,
        ]);
    }
}
