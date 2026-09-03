<?php

declare(strict_types=1);

use Webman\Route;
use app\open\controller\EchoController;
use app\open\controller\PingController as OpenPingController;

/**
 * 开放平台（/open/*）
 *
 * 由 config/route.php 载入——webman 只认 config/route.php 这一个入口
 * （框架的 Route::load() 里写死了 `$configPath . '/route.php'`），
 * 分端文件靠它 require 进来。声明规则见那边的顶部注释。
 */

// ---------------------------------------------------------------- 开放平台
// IP 白名单与验签是应用级中间件，ping 也要过——这正是要验证的链路。
Route::group('/open', function () {
    Route::get('/ping', [OpenPingController::class, 'index'])->setParams(['skip_signature' => true]);
    Route::any('/echo', [EchoController::class, 'index']);
});
