<?php

declare(strict_types=1);

use app\admin\controller\AuthController;
use app\admin\controller\DeptController;
use app\admin\controller\DictController;
use app\admin\controller\UserController;
use app\common\middleware\AdminAuthMiddleware;
use app\common\middleware\OperationLogMiddleware;
use app\common\middleware\PermissionMiddleware;
use app\common\support\Result;
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
// 分端各一个，用于验证中间件与异常处理链路是否按端隔离
Route::get('/ping', fn () => Result::ok(['pong' => true, 'app' => 'root']));
Route::get('/admin/ping', fn () => Result::ok(['pong' => true, 'app' => 'admin']));
Route::get('/client/ping', fn () => Result::ok(['pong' => true, 'app' => 'client']));
Route::get('/open/ping', fn () => Result::ok(['pong' => true, 'app' => 'open']));

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

// 关闭默认路由：只有显式声明的路由可访问，避免控制器方法被意外暴露
Route::disableDefaultRoute();

// 404 兜底，保持与业务错误一致的响应结构
Route::fallback(fn () => Result::error(404, 10404, '接口不存在'));
