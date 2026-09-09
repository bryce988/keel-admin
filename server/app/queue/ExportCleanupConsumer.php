<?php

declare(strict_types=1);

namespace app\queue;

use app\common\service\ExportService;
use app\common\service\TaskLogService;
use support\Log;
use Throwable;
use Webman\RedisQueue\Consumer;

/**
 * 消费「清理过期导出」任务
 *
 * 与日志清理同样的理由绕队列：定时任务进程只有一个，任何耗时操作都会
 * 推迟它后面所有的计划任务（见 `TaskProcess` 顶部）。
 *
 * 清的是**记录**。文件本身由 `Spreadsheet` 在写新文件时顺手回收——
 * 但只靠那一条不够：一段时间没人导出就没人触发回收，而过期记录会一直堆着，
 * 列表翻两页全是下不了的行。
 */
class ExportCleanupConsumer implements Consumer
{
    /** 用途说明，给「队列监控」页显示 */
    public string $desc = '清理过期的导出任务记录';

    public string $queue = 'keel:export-cleanup';

    public string $connection = 'default';

    public function consume($data): void
    {
        // track() 负责计时、把结果写回 sys_task_logs，并原样抛出异常
        // （吞掉异常等于告诉队列「处理成功」，重试就失效了）。
        // $data 里没有 task_log_id 时它不记日志、照常执行
        $result = TaskLogService::track($data, fn () => ExportService::cleanup());

        Log::info('队列：导出清理', $result + ['trigger' => $data['trigger'] ?? 'unknown']);
    }

    public function onConsumeFailure(Throwable $e, $package): void
    {
        Log::error('队列：导出清理失败', [
            'error'    => $e->getMessage(),
            'attempts' => $package['attempts'] ?? 0,
        ]);
    }
}
