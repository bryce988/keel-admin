<?php

declare(strict_types=1);

namespace app\common\bootstrap;

use app\common\support\Db;
use Webman\Bootstrap;
use Workerman\Worker;

/**
 * 进程启动时初始化 Eloquent
 *
 * 模型必须先拿到连接解析器才能用，不能等到第一次 Db::table() 才懒加载：
 * 直接 `SysUserModel::query()` 的路径不经过 Db::table()。
 * 这里在每个 worker 进程启动时跑一次，属于进程级基础设施初始化（PROJECT.md §14）。
 */
class Database implements Bootstrap
{
    public static function start(?Worker $worker): void
    {
        Db::boot();
    }
}
