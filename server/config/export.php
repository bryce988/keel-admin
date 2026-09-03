<?php
/**
 * keel admin
 * 可导出的业务登记表
 *
 * 放在配置里而不是 `ExportService` 的常量里，是为了让依赖方向只有一条：
 * `app/common` 是四端共用的基础设施，不能反过来 use `app/admin` 的业务 service。
 * 登记表天然是「基础设施 ← 业务」的接线，配置正是接线该待的地方。
 *
 * 新增一种导出只改这一处：登记名字、权限点、以及「怎么生成文件」。
 * handler 是 `fn(array $params): array{path:string, rows:int}`——
 * 就是各模块原来那个同步导出方法，只把返回值从「路径」扩成「路径 + 行数」
 * （行数要显示在导出列表里，让人不用下载就知道有没有筛出东西）。
 *
 * ⚠️ `perm` 在这里再判一次，不是重复劳动：路由上的权限点管的是
 * 「谁能发起导出」（各模块自己的 export 权限），这里管的是消费时那个人
 * **现在**还有没有这个权限——排队期间被撤了权限，任务就不该再跑完。
 *
 * @author 火火
 */
declare(strict_types=1);

use app\admin\service\LogService;
use app\admin\service\UserService;

return [
    'biz' => [
        'user' => [
            'name'    => '用户',
            'perm'    => 'sys:user:export',
            'handler' => [UserService::class, 'export'],
            'file'    => '用户列表',
        ],
        'log_operation' => [
            'name'    => '操作日志',
            'perm'    => 'sys:log:operation:export',
            'handler' => [LogService::class, 'exportOperation'],
            'file'    => '操作日志',
        ],
        'log_login' => [
            'name'    => '登录日志',
            'perm'    => 'sys:log:login:export',
            'handler' => [LogService::class, 'exportLogin'],
            'file'    => '登录日志',
        ],
    ],
];
