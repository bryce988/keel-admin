<?php

declare(strict_types=1);

namespace app\process;

use support\Log;
use Throwable;
use Webman\RedisQueue\Redis;
use Workerman\Crontab\Crontab;

/**
 * 定时任务进程
 *
 * ⚠️ 在 `config/process.php` 里必须 `count => 1`。
 * 多开几个进程不会让任务跑得更快，只会让同一个任务在同一时刻被触发 N 次——
 * 清理类任务重复执行也许无害，发通知、结算这类重复一次就是事故。
 *
 * ⚠️ 这个进程里不要写耗时逻辑。它只有一个进程，一个任务卡住，
 * 后面所有计划任务跟着延后。真正的活投进队列，由消费进程去干
 * （见 `app/queue/`，PROJECT.md §14.7）。
 *
 * 规则支持 5 段（分 时 日 月 周）与 6 段（秒 分 时 日 月 周），
 * 按位数自动判别（`Workerman\Crontab\Parser::parseDate`）。
 */
class TaskProcess
{
    public function onWorkerStart(): void
    {
        // 每天 03:30 清理过期日志。挑凌晨是因为这时候锁表影响最小；
        // 不挑整点是为了错开一堆默认写 0 0 * * * 的东西
        new Crontab('30 3 * * *', function () {
            $this->dispatch('keel:log-cleanup', ['trigger' => 'cron']);
        }, 'log-cleanup');

        // 每天 03:40 清理过期的导出任务记录。排在日志清理之后十分钟，
        // 两件事都要删数据，挤在同一分钟只会让锁竞争没有必要地重叠
        new Crontab('40 3 * * *', function () {
            $this->dispatch('keel:export-cleanup', ['trigger' => 'cron']);
        }, 'export-cleanup');

        Log::info('定时任务进程已启动', ['tasks' => ['log-cleanup', 'export-cleanup']]);
    }

    /**
     * 投递到队列
     *
     * 单独抽出来是为了把 try/catch 收在一处：定时任务的回调里抛异常
     * 不会有人看见（没有请求上下文、也没有中间件兜底），
     * 表现就是「这个任务某天起就不跑了」，而日志里一个字都没有。
     */
    private function dispatch(string $queue, array $payload): void
    {
        try {
            Redis::send($queue, $payload);
        } catch (Throwable $e) {
            Log::error('定时任务投递失败', ['queue' => $queue, 'error' => $e->getMessage()]);
        }
    }
}
