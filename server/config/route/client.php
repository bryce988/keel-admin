<?php

declare(strict_types=1);

use Webman\Route;
use app\client\controller\PingController as ClientPingController;
use app\client\controller\v1\ProfileController as ClientProfileController;
use app\common\middleware\ClientAuthMiddleware;

/**
 * C 端（/client/*）
 *
 * 由 config/route.php 载入——webman 只认 config/route.php 这一个入口
 * （框架的 Route::load() 里写死了 `$configPath . '/route.php'`），
 * 分端文件靠它 require 进来。声明规则见那边的顶部注释。
 */

// ---------------------------------------------------------------- C 端（App / 小程序）
// 一期只有空壳（PROJECT.md §8.8）。这里的路由必须指向 app\client 下的控制器，
// 否则拿不到 client 的应用中间件与异常处理器。
//
// 曾经在这里落过一套 app_users 的登录闭环，后来删了：员工移动端走的是
// /staff/v1/*（管理端身份），那套 C 端账号没有任何消费方，而脚手架里一张没人用的
// 用户表只会让 fork 的人困惑——真接 C 端时登录方式几乎必然是短信或微信授权，
// 手机号加密码那套照样要重写。
Route::group('/client', function () {
    // 公开：渠道头仍然必填，但不需要登录
    Route::get('/ping', [ClientPingController::class, 'index']);

    // 需登录：验证「员工 token 调 C 端接口一律 401」
    Route::group('/v1', function () {
        Route::get('/profile', [ClientProfileController::class, 'index']);
    })->middleware([ClientAuthMiddleware::class]);
});
