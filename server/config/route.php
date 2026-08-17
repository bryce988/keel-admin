<?php

declare(strict_types=1);

use app\admin\controller\AuthController;
use app\admin\controller\DeptController;
use app\admin\controller\DictController;
use app\admin\controller\UserController;
use app\client\controller\PingController as ClientPingController;
use app\client\controller\v1\ProfileController as ClientProfileController;
use app\common\middleware\AdminAuthMiddleware;
use app\common\middleware\ClientAuthMiddleware;
use app\common\middleware\OperationLogMiddleware;
use app\common\middleware\PermissionMiddleware;
use app\common\support\Result;
use app\internal\controller\PingController as InternalPingController;
use app\open\controller\EchoController;
use app\open\controller\PingController as OpenPingController;
use Webman\Route;

/**
 * 路由即权限清单
 *
 * 每个需登录的接口都必须在 setParams 里声明 `perm`：
 * - `'perm' => 'sys:user:list'`  需要该权限点
 * - `'perm' => ''`               登录即可（个人中心、字典这类）
 * - 不写                          → PermissionMiddleware 直接 403（fail-closed）
 *
 * 写接口再加 `log`，操作日志由中间件自动落库：
 *   'log' => ['module' => '系统管理/用户', 'action' => 1, 'title' => '新增用户']
 *   action：1新增 2修改 3删除 4导出 5授权 6其他
 */

// ---------------------------------------------------------------- 存活探测
// 根探测走闭包（不属于任何应用），用于负载均衡健康检查
Route::get('/ping', fn () => Result::ok(['pong' => true, 'app' => 'root']));
Route::get('/admin/ping', fn () => Result::ok(['pong' => true, 'app' => 'admin']));

// ---------------------------------------------------------------- 管理后台
// 公开接口：不需要登录
Route::group('/admin/auth', function () {
    Route::get('/captcha', [AuthController::class, 'captcha']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
});

// 需要登录的接口
Route::group('/admin', function () {
    // 个人相关：登录即可，不需要额外授权
    Route::post('/auth/logout', [AuthController::class, 'logout'])->setParams(['perm' => '']);
    Route::get('/auth/profile', [AuthController::class, 'profile'])->setParams(['perm' => '']);
    Route::put('/profile/password', [AuthController::class, 'changePassword'])->setParams([
        'perm' => '',
        'log'  => ['module' => '个人中心', 'action' => 2, 'title' => '修改密码'],
    ]);

    // 数据字典：所有页面的下拉与标签都依赖它，登录即可读
    Route::get('/dicts/batch', [DictController::class, 'batch'])->setParams(['perm' => '']);
    Route::get('/dicts/{code}/items', [DictController::class, 'items'])->setParams(['perm' => '']);

    // 部门树：既是部门管理的数据源，也是用户列表的筛选条件，任一权限满足即可读
    Route::get('/depts/tree', [DeptController::class, 'tree'])
        ->setParams(['perm' => ['sys:dept:list', 'sys:user:list']]);

    // 用户管理（M1 只做查询，增删改见 M2）
    Route::get('/users', [UserController::class, 'index'])->setParams(['perm' => 'sys:user:list']);
})->middleware([
    AdminAuthMiddleware::class,       // 认证：你是谁
    OperationLogMiddleware::class,    // 审计：声明了 log 的接口自动落库
    PermissionMiddleware::class,      // 鉴权：你能不能调这个接口
]);
// 审计**包住**鉴权而不是反过来：越权尝试同样要留痕，
// 「谁试图做什么但被拒了」和「谁做成了什么」在审计上一样重要。

// ---------------------------------------------------------------- C 端（App / 小程序）
// 一期只有空壳（PROJECT.md §8.8）。这里的路由必须指向 app\client 下的控制器，
// 否则拿不到 client 的应用中间件与异常处理器。
Route::group('/client', function () {
    // 公开：渠道头仍然必填，但不需要登录
    Route::get('/ping', [ClientPingController::class, 'index']);

    // 需登录：验证「员工 token 调 C 端接口一律 401」
    Route::group('/v1', function () {
        Route::get('/profile', [ClientProfileController::class, 'index']);
    })->middleware([ClientAuthMiddleware::class]);
});

// ---------------------------------------------------------------- 开放平台
// IP 白名单与验签是应用级中间件，ping 也要过——这正是要验证的链路。
Route::group('/open', function () {
    Route::get('/ping', [OpenPingController::class, 'index'])->setParams(['skip_signature' => true]);
    Route::any('/echo', [EchoController::class, 'index']);
});

// ---------------------------------------------------------------- 内部服务
// 只在内网可达，nginx 层应拒绝公网访问 /internal/*
Route::group('/internal', function () {
    Route::get('/ping', [InternalPingController::class, 'index']);
});

// 关闭默认路由：只有显式声明的路由可访问，避免控制器方法被意外暴露
Route::disableDefaultRoute();

// 404 兜底，保持与业务错误一致的响应结构
Route::fallback(fn () => Result::error(404, 10404, '接口不存在'));
