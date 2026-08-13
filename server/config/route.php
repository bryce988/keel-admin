<?php

declare(strict_types=1);

use app\admin\controller\AuthController;
use app\common\middleware\AdminAuthMiddleware;
use app\common\support\Result;
use Webman\Route;

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
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/profile', [AuthController::class, 'profile']);
    Route::put('/profile/password', [AuthController::class, 'changePassword']);
})->middleware([
    AdminAuthMiddleware::class,
]);

// 关闭默认路由：只有显式声明的路由可访问，避免控制器方法被意外暴露
Route::disableDefaultRoute();

// 404 兜底，保持与业务错误一致的响应结构
Route::fallback(fn () => Result::error(404, 10404, '接口不存在'));
