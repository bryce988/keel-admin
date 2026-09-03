<?php

declare(strict_types=1);

use app\common\constant\BizCode;
use app\common\constant\HttpStatus;
use app\common\support\Result;
use Webman\Route;

/**
 * 路由入口
 *
 * ## 为什么是「一个入口 + require 分端文件」
 *
 * webman 只认 `config/route.php` 这一个文件——框架的 `Route::load()` 里写死了
 * `$configPath . '/route.php'`（vendor/workerman/webman-framework/src/Route.php），
 * 除此之外只会扫插件目录下的 route.php（config/plugin 里那层），
 * 没有「按应用自动加载」这回事。
 * 所以分端文件不能靠约定被发现，必须在这里显式 require。
 *
 * 用 `require` 而不是 `require_once`：路由注册是**有副作用**的（往 collector 里塞规则），
 * 万一将来 `Route::load()` 被调用第二次，once 会让第二次什么都不注册，
 * 表现是「路由全没了」而不是报错。
 *
 * ## 路由即权限清单
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

// ---------------------------------------------------------------- 分端路由
// 一端一个文件，各自带自己的 use。新增一个端：加文件 + 在这里加一行 require，
// 另外别忘了 config/middleware.php、config/exception.php 与 nginx 的转发前缀
require __DIR__ . '/route/admin.php';
require __DIR__ . '/route/staff.php';
require __DIR__ . '/route/client.php';
require __DIR__ . '/route/open.php';
require __DIR__ . '/route/internal.php';

// ---------------------------------------------------------------- 跨域预检
// 浏览器对带自定义头（X-Channel、Authorization）的请求会先发一次 OPTIONS，
// 而上面的路由只登记了 GET/POST/PUT。不显式登记 OPTIONS 的话它会落到 fallback，
// 而 **fallback 拿不到全局中间件**（实测：Route::fallback 的响应上没有任何中间件加的头），
// CorsMiddleware 就没机会应答，浏览器只看到一句「跨域失败」。
//
// 这里只负责让请求能进到中间件管道，响应头由 CorsMiddleware 按白名单决定加不加——
// 所以登记这条路由本身不等于「对所有人开放跨域」。
Route::options('/[{path:.*}]', fn () => response('', 204));

// 关闭默认路由：只有显式声明的路由可访问，避免控制器方法被意外暴露
Route::disableDefaultRoute();

// 404 兜底，保持与业务错误一致的响应结构
Route::fallback(fn () => Result::error(HttpStatus::NOT_FOUND, BizCode::NOT_FOUND, '接口不存在'));
