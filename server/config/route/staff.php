<?php

declare(strict_types=1);

use Webman\Route;
use app\common\middleware\AdminAuthMiddleware;
use app\common\middleware\OperationLogMiddleware;
use app\common\middleware\PermissionMiddleware;
use app\staff\controller\v1\AuthController as StaffAuthController;
use app\staff\controller\v1\ChatController as StaffChatController;
use app\staff\controller\v1\NoticeController as StaffNoticeController;
use app\staff\controller\v1\ProfileController as StaffProfileController;
use app\staff\controller\v1\UploadController as StaffUploadController;
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
    // 刷新必须免登录：access 过期后调不动需要鉴权的接口，
    // 刷新接口自己再要求登录就成了死锁
    Route::post('/auth/refresh', [StaffAuthController::class, 'refresh']);
});

Route::group('/staff/v1', function () {
    Route::post('/auth/logout', [StaffAuthController::class, 'logout'])->setParams(['perm' => '']);

    // 工作台：概览要 sys:dashboard:view，但这里声明 '' —— 没权限的人也该看到首页，
    // 只是概览那一块返回 visible=false（判断在 WorkbenchController 里）
    Route::get('/workbench', [StaffWorkbenchController::class, 'index'])->setParams(['perm' => '']);

    // 消息（系统公告）：接收端不需要权限点——公告是发给所有员工的，
    // 「谁能发」才要 sys:notice:*，那在后台
    Route::get('/notices', [StaffNoticeController::class, 'index'])->setParams(['perm' => '']);
    Route::post('/notices/read-all', [StaffNoticeController::class, 'readAll'])->setParams(['perm' => '']);
    // {id} 放在 read-all 之后：否则 read-all 会被当成 id 匹配掉
    Route::get('/notices/{id:\d+}', [StaffNoticeController::class, 'show'])->setParams(['perm' => '']);

    /*
     * 即时通讯：与后台**同一份 ChatService**，只是接口前缀不同
     *
     * 这里声明 'chat:use'，和后台一致——身份与授权两端共用，
     * 没被授予 chat:use 的人在手机上同样调不动（fail-closed）。
     * 可见性仍由 ChatService::assertMember() 把守，非成员 404。
     */
    // 通用上传：与后台同一份 UploadService，只是入口分端。
    // 让移动端直接打 /admin/upload 虽然能过鉴权（两端同一套身份），
    // 但限流、渠道头、审计口径、网关路由都按前缀治理，混着调就分不开了
    Route::post('/upload', [StaffUploadController::class, 'store'])->setParams(['perm' => '']);

    Route::get('/chat/contacts', [StaffChatController::class, 'contacts'])->setParams(['perm' => 'chat:use']);
    // 同事名片（点消息头像、点通讯录里的人）。权限点与后台 /admin/contacts/{id} 一致：
    // 看资料是「通讯录」的能力，不是「聊天」的——只授了 chat:use 的人看不到名片
    Route::get('/chat/contacts/{id:\d+}', [StaffChatController::class, 'contact'])->setParams(['perm' => 'contact:view']);
    // 固定路径排在 {id} 之前
    Route::get('/chat/unread', [StaffChatController::class, 'unread'])->setParams(['perm' => 'chat:use']);
    Route::get('/chat/conversations', [StaffChatController::class, 'conversations'])->setParams(['perm' => 'chat:use']);
    Route::post('/chat/conversations', [StaffChatController::class, 'open'])->setParams(['perm' => 'chat:use']);
    Route::get('/chat/conversations/{id:\d+}', [StaffChatController::class, 'detail'])->setParams(['perm' => 'chat:use']);
    Route::get('/chat/conversations/{id:\d+}/messages', [StaffChatController::class, 'messages'])->setParams(['perm' => 'chat:use']);
    Route::post('/chat/conversations/{id:\d+}/messages', [StaffChatController::class, 'send'])->setParams(['perm' => 'chat:use']);
    Route::post('/chat/conversations/{id:\d+}/read', [StaffChatController::class, 'read'])->setParams(['perm' => 'chat:use']);
    // 撤回挂在 /chat/messages/ 下而不是会话路径下：撤回的对象是一条消息，
    // 而 id 在全表唯一，不需要再带会话 id（会话归属由 service 自己查出来校验）
    Route::post('/chat/messages/{id:\d+}/recall', [StaffChatController::class, 'recall'])->setParams(['perm' => 'chat:use']);

    // 群聊。建群走 /chat/groups 而不是 /chat/conversations（后者是打开单聊）；
    // 群资料与解散挂 /{id}/group，与 /{id}（只把会话从我的列表移除）区分开
    Route::post('/chat/groups', [StaffChatController::class, 'createGroup'])->setParams(['perm' => 'chat:use']);
    Route::get('/chat/conversations/{id:\d+}/members', [StaffChatController::class, 'members'])->setParams(['perm' => 'chat:use']);
    Route::post('/chat/conversations/{id:\d+}/members', [StaffChatController::class, 'addMembers'])->setParams(['perm' => 'chat:use']);
    Route::delete('/chat/conversations/{id:\d+}/members/{uid:\d+}', [StaffChatController::class, 'removeMember'])->setParams(['perm' => 'chat:use']);
    Route::put('/chat/conversations/{id:\d+}/group', [StaffChatController::class, 'updateGroup'])->setParams(['perm' => 'chat:use']);
    Route::delete('/chat/conversations/{id:\d+}/group', [StaffChatController::class, 'dissolve'])->setParams(['perm' => 'chat:use']);
    Route::put('/chat/conversations/{id:\d+}/settings', [StaffChatController::class, 'settings'])->setParams(['perm' => 'chat:use']);
    Route::delete('/chat/conversations/{id:\d+}', [StaffChatController::class, 'remove'])->setParams(['perm' => 'chat:use']);

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
