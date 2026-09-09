<?php

declare(strict_types=1);

namespace app\process;

use app\common\service\TaskLogService;
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
    /**
     * 计划任务登记表
     *
     * 抽成常量而不是散在 `onWorkerStart()` 里，是因为「队列监控」页要把它列出来
     * （`QueueService::tasks()`）。定时任务跑在自己的进程里，HTTP 进程既看不到
     * 它的 Crontab 实例、也没法问它「你注册了哪些任务」——只能共读同一份声明。
     *
     * 新增定时任务只改这里：`queue` 必须对应 `app/queue/` 下某个消费者的 `$queue`，
     * 否则任务照投不误、却永远没人消费（监控页会把这种队列标成「无消费者」）。
     *
     * @var list<array{name:string, rule:string, queue:string, desc:string}>
     */
    public const TASKS = [
        // 挑凌晨是因为这时候锁表影响最小；不挑整点是为了错开一堆默认写 0 0 * * * 的东西
        [
            'name'  => 'log-cleanup',
            'rule'  => '30 3 * * *',
            'queue' => 'keel:log-cleanup',
            'desc'  => '清理过期日志',
        ],
        // 排在日志清理之后十分钟：两件事都要删数据，挤在同一分钟只会让锁竞争没有必要地重叠
        [
            'name'  => 'export-cleanup',
            'rule'  => '40 3 * * *',
            'queue' => 'keel:export-cleanup',
            'desc'  => '清理过期的导出任务记录与文件',
        ],
    ];

    public function onWorkerStart(): void
    {
        foreach (self::TASKS as $task) {
            new Crontab($task['rule'], function () use ($task) {
                $this->dispatch($task);
            }, $task['name']);
        }

        Log::info('定时任务进程已启动', ['tasks' => array_column(self::TASKS, 'name')]);
    }

    /**
     * 投递到队列，并留下一条执行记录
     *
     * try/catch 收在一处：定时任务的回调里抛异常不会有人看见
     * （没有请求上下文、也没有中间件兜底），表现就是「这个任务某天起就不跑了」，
     * 而日志里一个字都没有。
     *
     * 执行记录**投递前**就写（`status = 排队中`），消费者拿到 `task_log_id`
     * 后回填结果。顺序不能反：先投递再写日志的话，消费进程可能在日志行还没
     * 插进去时就已经跑完并回填了——那次执行会永远停在「排队中」。
     *
     * @param  array{name:string, rule:string, queue:string, desc:string}  $task
     */
    private function dispatch(array $task): void
    {
        $logId = TaskLogService::start($task['name'], $task['desc'], $task['queue']);

        try {
            Redis::send($task['queue'], ['trigger' => 'cron', 'task_log_id' => $logId]);
        } catch (Throwable $e) {
            Log::error('定时任务投递失败', ['queue' => $task['queue'], 'error' => $e->getMessage()]);

            // 没进队列就不会有消费者来回填，不标一下会永远挂在「排队中」，
            // 与「投出去了但没人消费」混为一谈——两者的排查方向完全不同
            TaskLogService::markDispatchFailed($logId, $e->getMessage());
        }
    }
}
