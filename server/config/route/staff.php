<?php

declare(strict_types=1);

use Webman\Route;
use app\common\middleware\AdminAuthMiddleware;
use app\common\middleware\OperationLogMiddleware;
use app\common\middleware\PermissionMiddleware;
use app\staff\controller\v1\AuthController as StaffAuthController;
use app\staff\controller\v1\ProfileController as StaffProfileController;
use app\staff\controller\v1\WorkbenchController as StaffWorkbenchController;

/**
 * 员工移动端（/staff/v1/*）
 *
 * 由 config/route.php 载入——webman 只认 config/route.php 这一个入口
 * （框架的 Route::load() 里写死了 `$configPath . '/route.php'`），
 * 分端文件靠它 require 进来。声明规则见那边的顶部注释。
 */

// ---------------------------------------------------------------- 员工移动端
// 身份与后台**同一套**（同一张 sys_users、同一个令牌、同一份权限点），
// 但接口另开一套——理由见 PROJECT.md §8.1：移动端要聚合与瘦身，
// 且迟早要长出强制更新、推送注册这类后台没有的东西。
//
// 鉴权中间件与后台完全一样：AdminAuthMiddleware 认的是 type=admin 的令牌，
// PermissionMiddleware 依旧 fail-closed（不写 perm 就是 403）。
Route::group('/staff/v1', function () {
    // 公开：渠道头仍然必填，但不需要登录
    Route::get('/auth/captcha', [StaffAuthController::class, 'captcha']);
    Route::post('/auth/login', [StaffAuthController::class, 'login']);
});

Route::group('/staff/v1', function () {
    Route::post('/auth/logout', [StaffAuthController::class, 'logout'])->setParams(['perm' => '']);

    // 工作台：概览要 sys:dashboard:view，但这里声明 '' —— 没权限的人也该看到首页，
    // 只是概览那一块返回 visible=false（判断在 WorkbenchController 里）
    Route::get('/workbench', [StaffWorkbenchController::class, 'index'])->setParams(['perm' => '']);

    Route::get('/profile', [StaffProfileController::class, 'index'])->setParams(['perm' => '']);
    Route::put('/profile', [StaffProfileController::class, 'update'])->setParams([
        'perm' => '',
        'log'  => ['module' => '个人中心', 'action' => 2, 'title' => '修改资料（移动端）'],
    ]);
    Route::post('/profile/avatar', [StaffProfileController::class, 'avatar'])->setParams([
        'perm' => '',
        'log'  => ['module' => '个人中心', 'action' => 2, 'title' => '更换头像（移动端）'],
    ]);
})->middleware([
    AdminAuthMiddleware::class,       // 认证：与后台同一个令牌体系
    OperationLogMiddleware::class,    // 审计：手机上改的资料同样要留痕
    PermissionMiddleware::class,      // 鉴权：fail-closed，不写 perm 就是 403
]);
