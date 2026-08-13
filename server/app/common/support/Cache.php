<?php

declare(strict_types=1);

namespace app\common\support;

use Predis\Client;

/**
 * Redis 客户端
 *
 * 用 predis（纯 PHP），不依赖 phpredis 扩展，容器构建更快。
 * 生产环境追求极致性能时可换成 ext-redis，只需改本文件，调用方无感。
 *
 * 与 Db 同理：连接是进程级基础设施，可以用 static 持有并复用。
 * 验证码、登录失败计数、token 黑名单必须存在这里而不是进程内变量——
 * webman 是多进程模型，进程内变量各存各的，两次请求可能落在不同进程上。
 */
class Cache
{
    private static ?Client $client = null;

    public static function conn(): Client
    {
        if (self::$client instanceof Client) {
            try {
                self::$client->ping();

                return self::$client;
            } catch (\Throwable) {
                self::$client = null;   // 连接被服务端断开，重建
            }
        }

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

        return self::$client = new Client($params);
    }

    public static function get(string $key): ?string
    {
        return self::conn()->get($key);
    }

    public static function set(string $key, mixed $value, int $ttl = 0): void
    {
        $ttl > 0
            ? self::conn()->setex($key, $ttl, (string) $value)
            : self::conn()->set($key, (string) $value);
    }

    public static function del(string $key): void
    {
        self::conn()->del([$key]);
    }

    public static function exists(string $key): bool
    {
        return (bool) self::conn()->exists($key);
    }

    /** 计数并在首次设置过期时间，用于登录失败次数与限流 */
    public static function incr(string $key, int $ttl): int
    {
        $client = self::conn();
        $count  = (int) $client->incr($key);
        if ($count === 1 && $ttl > 0) {
            $client->expire($key, $ttl);
        }

        return $count;
    }

    public static function ttl(string $key): int
    {
        return (int) self::conn()->ttl($key);
    }
}
