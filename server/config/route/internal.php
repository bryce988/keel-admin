<?php

declare(strict_types=1);

use Webman\Route;
use app\internal\controller\PingController as InternalPingController;

/**
 * 内部服务（/internal/*）
 *
 * 由 config/route.php 载入——webman 只认 config/route.php 这一个入口
 * （框架的 Route::load() 里写死了 `$configPath . '/route.php'`），
 * 分端文件靠它 require 进来。声明规则见那边的顶部注释。
 */

// ---------------------------------------------------------------- 内部服务
// 只在内网可达，nginx 层应拒绝公网访问 /internal/*
Route::group('/internal', function () {
    Route::get('/ping', [InternalPingController::class, 'index']);
});
