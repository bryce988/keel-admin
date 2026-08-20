<?php

declare(strict_types=1);

use app\common\support\Env;

/**
 * 队列的 Redis 连接
 *
 * 与 `app\common\support\Cache` 共用同一组环境变量，但刻意用不同的 db：
 * 队列的 key 是 `{queue}` 前缀的 list/zset，缓存是散落的 string，
 * 混在一个 db 里，排查时 `KEYS *` 出来一片混乱，
 * 更要命的是缓存那边如果哪天需要 `FLUSHDB`，会把没消费完的任务一起清掉。
 *
 * `REDIS_QUEUE_DB` 不设则退回 `REDIS_DB + 1`。
 */
$db = Env::get('REDIS_QUEUE_DB') !== null
    ? Env::int('REDIS_QUEUE_DB')
    : Env::int('REDIS_DB', 0) + 1;

return [
    'default' => [
        'host'    => sprintf('redis://%s:%d', Env::get('REDIS_HOST', '127.0.0.1'), Env::int('REDIS_PORT', 6379)),
        'options' => [
            'auth'   => Env::get('REDIS_PASSWORD') ?: null,
            'db'     => $db,
            'prefix' => '',
            /*
             * 失败重试：第 n 次重试延迟 retry_seconds * n 秒
             *
             * 5 次之后进 `{queue}-failed`，不会自动丢弃——
             * 排查时用 `LRANGE {队列名}-failed 0 -1` 能把失败的原始消息捞出来。
             * 消费者里要么把异常吞掉自己记日志，要么让它抛出来走重试，
             * 不要抛了异常又在 catch 里当成功处理，那样重试机制形同虚设。
             */
            'max_attempts'  => 5,
            'retry_seconds' => 5,
        ],
    ],
];
