<?php

declare(strict_types=1);

namespace app\common\support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;

/**
 * Eloquent / 查询构造器入口
 *
 * ⚠️ 关于常驻内存下的 static：
 * 这里的 static 持有的是「数据库连接」这类进程级基础设施，不是请求态数据，
 * 每个 worker 进程初始化一次并在后续请求间复用，这是正确用法。
 * 禁止用 static 持有当前登录用户等请求相关状态（见 PROJECT.md §14.1）。
 */
class Db
{
    private static ?Capsule $capsule = null;

    public static function boot(): Capsule
    {
        if (self::$capsule !== null) {
            return self::$capsule;
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver'    => 'mysql',
            'host'      => Env::get('DB_HOST', '127.0.0.1'),
            'port'      => Env::int('DB_PORT', 3306),
            'database'  => Env::get('DB_DATABASE', 'keel'),
            'username'  => Env::get('DB_USERNAME', 'root'),
            'password'  => Env::get('DB_PASSWORD', ''),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
            'prefix'    => '',
            'strict'    => true,
            'options'   => [
                \PDO::ATTR_TIMEOUT => 3,
                // 常驻进程下连接可能被 MySQL 的 wait_timeout 断开，
                // Eloquent 自带断线重连检测，这里只保证超时不过长
            ],
        ]);

        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        return self::$capsule = $capsule;
    }

    public static function table(string $table): Builder
    {
        return self::boot()->getConnection()->table($table);
    }

    public static function conn(): \Illuminate\Database\Connection
    {
        return self::boot()->getConnection();
    }

    /** 事务边界统一在 service 层通过本方法开启 */
    public static function transaction(callable $callback): mixed
    {
        return self::conn()->transaction($callback);
    }
}
