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
// 脚手架不预设 C 端账号体系：各家的登录方式（短信、微信、Apple）与用户表字段都不同，
// 预置一套只会被推翻重写。这里保证的是「接入不改架构」——渠道头、令牌隔离、
// 分端异常处理器都已就位，接 C 端时在 app/client 下加控制器与 service 即可。
//
// ⚠️ 注意员工在手机上办公**不走这个端**：那是管理端身份的移动端页面（/staff/v1/*），
// 两套身份体系永不混用（PROJECT.md §8.4）。
Route::group('/client', function () {
    // 公开：渠道头仍然必填，但不需要登录
    Route::get('/ping', [ClientPingController::class, 'index']);

    // 需登录：验证「员工 token 调 C 端接口一律 401」
    Route::group('/v1', function () {
        Route::get('/profile', [ClientProfileController::class, 'index']);
    })->middleware([ClientAuthMiddleware::class]);
});
