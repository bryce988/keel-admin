<?php

declare(strict_types=1);

namespace app\common\support;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use Illuminate\Events\Dispatcher;

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
                /*
                 * 常驻进程下连接会被 MySQL 的 wait_timeout 断开。
                 * **已实测**（把 wait_timeout 压到 5s，M4 验收项）：
                 *   · 普通查询遇到 2006 会自动 reconnect 后重跑，连接 ID 变化，
                 *     业务无感、无需重启进程
                 *   · 死连接上直接 beginTransaction 也能自动重连，
                 *     这是生产里最真实的场景（worker 闲置一夜后第一个写请求）
                 *   · **事务执行到一半断连不会重试**，直接抛 2006——这是 Laravel
                 *     的刻意设计：重连等于静默丢弃未提交的事务，重试可能造成部分写入。
                 *     抛出去让上层回滚才是对的，且下一次查询连接即自愈
                 * 所以这里不需要额外的心跳保活，只保证连接超时不过长。
                 */
            ],
        ]);

        // 模型事件（creating / updating）依赖事件分发器，
        // 不设置的话 HasAudit 里的回调会静默不执行——审计字段全是 0 且没有任何报错
        $capsule->setEventDispatcher(new Dispatcher(new Container()));

        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;
        self::watchSlowQueries();

        return $capsule;
    }

    /**
     * 慢查询监听 → sql 通道
     *
     * 全局 Scope 与关联预加载很容易在不经意间生成昂贵的 SQL，
     * 而列表接口慢下来时最难定位的就是「到底哪条语句慢」。
     * 阈值 0 表示关闭。
     */
    private static function watchSlowQueries(): void
    {
        $threshold = Env::int('SLOW_QUERY_MS', 500);
        if ($threshold <= 0) {
            return;
        }

        self::$capsule->getConnection()->listen(function ($query) use ($threshold) {
            if ($query->time < $threshold) {
                return;
            }

            try {
                \support\Log::channel('sql')->warning(sprintf(
                    '[%s] %.1fms | %s | %s',
                    Ctx::traceId(),
                    $query->time,
                    $query->sql,
                    json_encode($query->bindings, JSON_UNESCAPED_UNICODE)
                ));
            } catch (\Throwable) {
                // 命令行脚本里 webman 的日志组件可能未初始化，
                // 记不了日志也绝不能影响查询本身
            }
        });
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
