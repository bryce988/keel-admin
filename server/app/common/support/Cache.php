<?php

declare(strict_types=1);

namespace app\common\support;

use Predis\Client;
use Predis\CommunicationException;

/**
 * Redis 客户端
 *
 * 用 predis（纯 PHP），不依赖 phpredis 扩展，容器构建更快。
 * 生产环境追求极致性能时可换成 ext-redis，只需改本文件，调用方无感。
 *
 * 与 Db 同理：连接是进程级基础设施，可以用 static 持有并复用。
 * 验证码、登录失败计数、token 黑名单必须存在这里而不是进程内变量——
 * webman 是多进程模型，进程内变量各存各的，两次请求可能落在不同进程上。
 *
 * ## 断连怎么处理：重试一次，而不是每次先 PING
 *
 * webman 的 worker 是常驻进程，连接会被 Redis 的 `timeout` 或中间的网络设备
 * 悄悄掐掉，下一条命令才发现。原来的做法是**每次取连接都先 `ping()`**，
 * 用一次往返换一个「连接还活着」的确认。
 *
 * 问题不在于 ping 慢（实测同机 docker 网络单次约 6μs，一个请求多付 12μs，
 * 占请求耗时的万分之四），而在于它把成本压在了每一次调用上：Redis 一旦挪到
 * 网络对端（托管实例，RTT 0.5ms 起步），同样的代码就变成每次缓存读写都翻倍。
 * 而断连本身是**罕见事件**，为罕见事件给每次调用加固定开销是反的。
 *
 * 现在改成乐观执行 + 失败重连重试一次。predis 自己不会重连，所以这段不能省——
 * 直接把 ping 删掉的话，空闲一段时间后的第一次访问会抛 ConnectionException。
 *
 * ⚠️ 重试对 `incr` 这类非幂等命令有个已知的边界：若连接是在命令**发出之后**断的，
 * 服务端可能已经执行过一次，重试会多加一次。这里可以接受——
 * 用它的是登录失败计数与限流，多算一次的后果是提前一点点锁定，方向是安全的。
 * 将来若拿它做计费类计数，要改成 Lua 脚本 + 请求唯一 ID 去重。
 */
class Cache
{
    private static ?Client $client = null;

    public static function conn(): Client
    {
        return self::$client ??= self::connect();
    }

    private static function connect(): Client
    {
        $params = [
            'scheme' => 'tcp',
            'host'   => Env::get('REDIS_HOST', '127.0.0.1'),
            'port'   => Env::int('REDIS_PORT', 6379),
            'database' => Env::int('REDIS_DB', 0),
            'timeout'  => 2.0,
        ];

        $password = Env::get('REDIS_PASSWORD');
        if (!empty($password)) {
            $params['password'] = $password;
        }

        return new Client($params);
    }

    /**
     * 执行一条命令，遇到连接层错误就重连并重试一次
     *
     * 只捕 `CommunicationException`（predis 所有网络错误的基类）。
     * 业务错误（WRONGTYPE、OOM 之类）是 `Predis\Response\ServerException`，
     * 不在这里重试——那些重试多少次都是同样的结果，只会把错误延后暴露。
     *
     * @template T
     * @param  callable(Client): T  $op
     * @return T
     */
    private static function run(callable $op): mixed
    {
        try {
            return $op(self::conn());
        } catch (CommunicationException) {
            // 连接已经不可用，扔掉重建。第二次再失败就让它抛出去——
            // 那说明 Redis 是真的连不上，不是一条陈旧连接的问题
            self::$client = null;

            return $op(self::conn());
        }
    }

    public static function get(string $key): ?string
    {
        return self::run(static fn (Client $c) => $c->get($key));
    }

    public static function set(string $key, mixed $value, int $ttl = 0): void
    {
        self::run(static fn (Client $c) => $ttl > 0
            ? $c->setex($key, $ttl, (string) $value)
            : $c->set($key, (string) $value));
    }

    public static function del(string $key): void
    {
        self::run(static fn (Client $c) => $c->del([$key]));
    }

    public static function exists(string $key): bool
    {
        return (bool) self::run(static fn (Client $c) => $c->exists($key));
    }

    /**
     * 原子占位：key 不存在时写入并返回 true，已存在返回 false
     *
     * 用于防重放的 nonce 与幂等键。必须是 SET NX EX 单条命令，
     * 拆成 exists + set 两步在并发下会双双通过。
     */
    public static function setNx(string $key, int $ttl, string $value = '1'): bool
    {
        return (bool) self::run(static fn (Client $c) => $c->set($key, $value, 'EX', $ttl, 'NX'));
    }

    /** 计数并在首次设置过期时间，用于登录失败次数与限流 */
    public static function incr(string $key, int $ttl): int
    {
        return self::run(static function (Client $c) use ($key, $ttl): int {
            $count = (int) $c->incr($key);
            if ($count === 1 && $ttl > 0) {
                $c->expire($key, $ttl);
            }

            return $count;
        });
    }

    public static function ttl(string $key): int
    {
        return (int) self::run(static fn (Client $c) => $c->ttl($key));
    }
}
