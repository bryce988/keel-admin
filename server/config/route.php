<?php

declare(strict_types=1);

use app\admin\controller\AuthController;
use app\admin\controller\DashboardController;
use app\admin\controller\DeptController;
use app\admin\controller\DictController;
use app\admin\controller\ExportController;
use app\admin\controller\LogController;
use app\admin\controller\MenuController;
use app\admin\controller\NoticeController;
use app\admin\controller\ParamController;
use app\admin\controller\PostController;
use app\admin\controller\ProfileController;
use app\admin\controller\RoleController;
use app\admin\controller\UserController;
use app\client\controller\PingController as ClientPingController;
use app\client\controller\v1\ProfileController as ClientProfileController;
use app\common\constant\BizCode;
use app\common\constant\HttpStatus;
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
    // 邮箱登录：先发码后登录，两步都免登录。发码那步自带图形验证码与失败计数，
    // 与账号密码登录共用同一套锁定（AuthService::gate）
    Route::post('/email/code', [AuthController::class, 'emailCode']);
    Route::post('/login/email', [AuthController::class, 'loginByEmail']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
});

// 登录页要用的少量参数（系统名、Logo、页脚）——此时还没有 token，
// 所以不能放进下面的登录态分组。白名单在 ParamService::PUBLIC_KEYS 里
Route::get('/admin/params/public', [ParamController::class, 'publicParams']);

// 需要登录的接口
Route::group('/admin', function () {
    // 个人相关：登录即可，不需要额外授权
    Route::post('/auth/logout', [AuthController::class, 'logout'])->setParams(['perm' => '']);
    Route::get('/auth/profile', [AuthController::class, 'profile'])->setParams(['perm' => '']);
    Route::put('/profile/password', [AuthController::class, 'changePassword'])->setParams([
        'perm' => '',
        'log'  => ['module' => '个人中心', 'action' => 2, 'title' => '修改密码'],
    ]);

    // 个人中心：id 全部取自令牌，没有「改别人」的路径，所以一个权限点都不需要
    Route::get('/profile', [ProfileController::class, 'index'])->setParams(['perm' => '']);
    Route::put('/profile', [ProfileController::class, 'update'])->setParams([
        'perm' => '',
        'log'  => ['module' => '个人中心', 'action' => 2, 'title' => '修改个人资料'],
    ]);
    Route::post('/profile/avatar', [ProfileController::class, 'avatar'])->setParams([
        'perm' => '',
        'log'  => ['module' => '个人中心', 'action' => 2, 'title' => '更换头像'],
    ]);
    Route::put('/profile/phone', [ProfileController::class, 'changePhone'])->setParams([
        'perm' => '',
        'log'  => ['module' => '个人中心', 'action' => 2, 'title' => '换绑手机号'],
    ]);
    Route::get('/profile/logins', [ProfileController::class, 'logins'])->setParams(['perm' => '']);

    // 系统概览：数据都受数据权限约束，部门主管看到的是他管得到的那部分
    Route::get('/dashboard/overview', [DashboardController::class, 'overview'])
        ->setParams(['perm' => 'sys:dashboard:view']);

    /*
     * 我的消息（顶栏铃铛）—— 登录即可，不挂权限点
     *
     * 公告的受众是每一个登录用户，给它挂权限点等于「没被授权的人收不到全员通知」。
     * 越权面由结构挡住：用户 id 只从令牌取，路径里没有 user_id 这类参数。
     *
     * 也不落操作日志：轮询接口每分钟一次、每个在线用户一条，
     * 记下来只会把真正的操作淹掉（读公告不是需要审计的行为）。
     */
    Route::get('/my/notices', [NoticeController::class, 'bell'])->setParams(['perm' => '']);
    // 固定路径排在 {id} 之前，否则 read-all 会被当成 id 匹配掉
    Route::post('/my/notices/read-all', [NoticeController::class, 'readAll'])
        ->setParams(['perm' => '']);
    Route::get('/my/notices/{id:\d+}', [NoticeController::class, 'readOne'])
        ->setParams(['perm' => '']);

    // 数据字典：所有页面的下拉与标签都依赖它，登录即可读
    Route::get('/dicts/batch', [DictController::class, 'batch'])->setParams(['perm' => '']);
    Route::get('/dicts/{code}/items', [DictController::class, 'items'])->setParams(['perm' => '']);

    // ---------------- 部门 ----------------
    // 部门树既是部门管理的数据源，也是用户列表的筛选条件，任一权限满足即可读
    Route::get('/depts/tree', [DeptController::class, 'tree'])
        ->setParams(['perm' => ['sys:dept:list', 'sys:user:list']]);
    Route::get('/depts/{id:\d+}', [DeptController::class, 'show'])
        ->setParams(['perm' => 'sys:dept:detail']);
    Route::post('/depts', [DeptController::class, 'store'])->setParams([
        'perm' => 'sys:dept:create',
        'log'  => ['module' => '系统管理/部门', 'action' => 1, 'title' => '新增部门'],
    ]);
    Route::put('/depts/{id:\d+}', [DeptController::class, 'update'])->setParams([
        'perm' => 'sys:dept:update',
        'log'  => ['module' => '系统管理/部门', 'action' => 2, 'title' => '编辑部门'],
    ]);
    Route::delete('/depts/{id:\d+}', [DeptController::class, 'destroy'])->setParams([
        'perm' => 'sys:dept:delete',
        'log'  => ['module' => '系统管理/部门', 'action' => 3, 'title' => '删除部门'],
    ]);

    // ---------------- 岗位 ----------------
    Route::get('/posts', [PostController::class, 'index'])->setParams(['perm' => 'sys:post:list']);
    // 固定路径排在 {id} 之前，否则 options 会被当成 id 匹配掉
    Route::get('/posts/options', [PostController::class, 'options'])
        ->setParams(['perm' => ['sys:post:list', 'sys:user:list']]);
    Route::get('/posts/{id:\d+}', [PostController::class, 'show'])
        ->setParams(['perm' => 'sys:post:detail']);
    Route::post('/posts', [PostController::class, 'store'])->setParams([
        'perm' => 'sys:post:create',
        'log'  => ['module' => '系统管理/岗位', 'action' => 1, 'title' => '新增岗位'],
    ]);
    Route::put('/posts/{id:\d+}', [PostController::class, 'update'])->setParams([
        'perm' => 'sys:post:update',
        'log'  => ['module' => '系统管理/岗位', 'action' => 2, 'title' => '编辑岗位'],
    ]);
    Route::delete('/posts/{id:\d+}', [PostController::class, 'destroy'])->setParams([
        'perm' => 'sys:post:delete',
        'log'  => ['module' => '系统管理/岗位', 'action' => 3, 'title' => '删除岗位'],
    ]);
    Route::post('/posts/batch-delete', [PostController::class, 'batchDestroy'])->setParams([
        'perm' => 'sys:post:delete',
        'log'  => ['module' => '系统管理/岗位', 'action' => 3, 'title' => '批量删除岗位'],
    ]);

    // ---------------- 数据导出 ----------------
    // 发起导出**不在这里**：那是各业务模块自己的接口（/users/export 等），
    // 因为「谁能导出用户」由 sys:user:export 决定。这里只管任务本身。
    Route::get('/exports', [ExportController::class, 'index'])
        ->setParams(['perm' => 'sys:export:list']);
    // 下载与列表同权限：看得见就下得了——看得见本身已经过了数据权限
    Route::get('/exports/{id:\d+}/download', [ExportController::class, 'download'])
        ->setParams(['perm' => 'sys:export:list']);
    Route::delete('/exports/{id:\d+}', [ExportController::class, 'destroy'])->setParams([
        'perm' => 'sys:export:delete',
        'log'  => ['module' => '数据管理/导出', 'action' => 3, 'title' => '删除导出任务'],
    ]);

    // ---------------- 系统公告（管理端）----------------
    // 接收端那三个接口在上面「我的消息」处，两组权限口径完全不同，别接错
    Route::get('/notices', [NoticeController::class, 'index'])
        ->setParams(['perm' => 'sys:notice:list']);
    /*
     * 详情接口的权限写成数组，是因为它有两个消费方
     *
     * 一个是「详情」按钮（sys:*:detail），另一个是**编辑表单的回填**——列表只给
     * 摘要，点编辑得把正文取回来。只声明 detail 的话，有编辑权限却没有详情权限的人
     * 点编辑会拿到 403，而他明明有权改这条。
     */
    Route::get('/notices/{id:\d+}', [NoticeController::class, 'show'])
        ->setParams(['perm' => ['sys:notice:detail', 'sys:notice:update']]);
    Route::post('/notices', [NoticeController::class, 'store'])->setParams([
        'perm' => 'sys:notice:create',
        'log'  => ['module' => '数据管理/公告', 'action' => 1, 'title' => '新增公告'],
    ]);
    Route::put('/notices/{id:\d+}', [NoticeController::class, 'update'])->setParams([
        'perm' => 'sys:notice:update',
        'log'  => ['module' => '数据管理/公告', 'action' => 2, 'title' => '编辑公告'],
    ]);
    // 发布与撤回单独立接口而不是用编辑改 status：它们在操作日志里要各自成条，
    // 「谁在什么时候把这条发给了全员」是公告唯一真正需要审计的事
    Route::post('/notices/{id:\d+}/publish', [NoticeController::class, 'publish'])->setParams([
        'perm' => 'sys:notice:publish',
        'log'  => ['module' => '数据管理/公告', 'action' => 6, 'title' => '发布公告'],
    ]);
    Route::post('/notices/{id:\d+}/revoke', [NoticeController::class, 'revoke'])->setParams([
        'perm' => 'sys:notice:publish',
        'log'  => ['module' => '数据管理/公告', 'action' => 6, 'title' => '撤回公告'],
    ]);
    Route::delete('/notices/{id:\d+}', [NoticeController::class, 'destroy'])->setParams([
        'perm' => 'sys:notice:delete',
        'log'  => ['module' => '数据管理/公告', 'action' => 3, 'title' => '删除公告'],
    ]);
    Route::post('/notices/batch-delete', [NoticeController::class, 'batchDestroy'])->setParams([
        'perm' => 'sys:notice:delete',
        'log'  => ['module' => '数据管理/公告', 'action' => 3, 'title' => '批量删除公告'],
    ]);

    // ---------------- 角色（授权层）----------------
    Route::get('/roles', [RoleController::class, 'index'])->setParams(['perm' => 'sys:role:list']);
    Route::get('/roles/options', [RoleController::class, 'options'])
        ->setParams(['perm' => ['sys:role:list', 'sys:user:list']]);
    // 授权抽屉打开时要先取角色详情（已授权的权限点、数据范围、互斥角色都在里面），
    // 所以两个授权权限点也要放行——只声明 detail 的话，有授权权限的人打不开授权抽屉
    Route::get('/roles/{id:\d+}', [RoleController::class, 'show'])->setParams([
        'perm' => ['sys:role:detail', 'sys:role:update', 'sys:role:grantPerm', 'sys:role:grantData'],
    ]);
    Route::get('/roles/{id:\d+}/members', [RoleController::class, 'members'])
        ->setParams(['perm' => 'sys:role:list']);
    Route::post('/roles', [RoleController::class, 'store'])->setParams([
        'perm' => 'sys:role:create',
        'log'  => ['module' => '系统管理/角色', 'action' => 1, 'title' => '新增角色'],
    ]);
    Route::put('/roles/{id:\d+}', [RoleController::class, 'update'])->setParams([
        'perm' => 'sys:role:update',
        'log'  => ['module' => '系统管理/角色', 'action' => 2, 'title' => '编辑角色'],
    ]);
    Route::delete('/roles/{id:\d+}', [RoleController::class, 'destroy'])->setParams([
        'perm' => 'sys:role:delete',
        'log'  => ['module' => '系统管理/角色', 'action' => 3, 'title' => '删除角色'],
    ]);
    Route::put('/roles/{id:\d+}/permissions', [RoleController::class, 'grantPermissions'])->setParams([
        'perm' => 'sys:role:grantPerm',
        'log'  => ['module' => '系统管理/角色', 'action' => 5, 'title' => '保存功能权限'],
    ]);
    Route::put('/roles/{id:\d+}/data-scope', [RoleController::class, 'grantDataScope'])->setParams([
        'perm' => 'sys:role:grantData',
        'log'  => ['module' => '系统管理/角色', 'action' => 5, 'title' => '保存数据范围'],
    ]);
    Route::put('/roles/{id:\d+}/mutexes', [RoleController::class, 'saveMutexes'])->setParams([
        'perm' => 'sys:role:grantData',
        'log'  => ['module' => '系统管理/角色', 'action' => 5, 'title' => '保存互斥角色'],
    ]);
    Route::post('/roles/{id:\d+}/members', [RoleController::class, 'addMembers'])->setParams([
        'perm' => 'sys:user:grantRole',
        'log'  => ['module' => '系统管理/角色', 'action' => 5, 'title' => '添加角色成员'],
    ]);
    Route::delete('/roles/{id:\d+}/members/{userId:\d+}', [RoleController::class, 'removeMember'])->setParams([
        'perm' => 'sys:user:grantRole',
        'log'  => ['module' => '系统管理/角色', 'action' => 5, 'title' => '移除角色成员'],
    ]);

    // ---------------- 菜单与权限点（只定义，不授权）----------------
    Route::get('/menus/tree', [MenuController::class, 'tree'])
        ->setParams(['perm' => 'sys:menu:list']);
    Route::get('/menus/{id:\d+}', [MenuController::class, 'show'])
        ->setParams(['perm' => 'sys:menu:detail']);
    Route::post('/menus', [MenuController::class, 'store'])->setParams([
        'perm' => 'sys:menu:create',
        'log'  => ['module' => '系统管理/菜单权限', 'action' => 1, 'title' => '新增权限点'],
    ]);
    Route::put('/menus/{id:\d+}', [MenuController::class, 'update'])->setParams([
        'perm' => 'sys:menu:update',
        'log'  => ['module' => '系统管理/菜单权限', 'action' => 2, 'title' => '编辑权限点'],
    ]);
    Route::delete('/menus/{id:\d+}', [MenuController::class, 'destroy'])->setParams([
        'perm' => 'sys:menu:delete',
        'log'  => ['module' => '系统管理/菜单权限', 'action' => 3, 'title' => '删除权限点'],
    ]);

    // ---------------- 用户（分配层）----------------
    Route::get('/users', [UserController::class, 'index'])->setParams(['perm' => 'sys:user:list']);
    // 固定路径要排在 {id} 之前，否则 export / import-template 会被当成 id 匹配掉
    // 导出现在是「发起一个任务」，属于要留痕的动作，所以补了 log 声明；
    // 原来同步下载时没记，是因为它就是一次读
    Route::get('/users/export', [UserController::class, 'export'])->setParams([
        'perm' => 'sys:user:export',
        'log'  => ['module' => '系统管理/用户', 'action' => 4, 'title' => '导出用户'],
        'log'  => ['module' => '系统管理/用户', 'action' => 4, 'title' => '导出用户'],
    ]);
    Route::get('/users/import-template', [UserController::class, 'importTemplate'])
        ->setParams(['perm' => 'sys:user:import']);
    Route::post('/users/import', [UserController::class, 'import'])->setParams([
        'perm' => 'sys:user:import',
        'log'  => ['module' => '系统管理/用户', 'action' => 1, 'title' => '导入用户'],
    ]);
    Route::get('/users/{id:\d+}', [UserController::class, 'show'])
        ->setParams(['perm' => ['sys:user:detail', 'sys:user:update']]);
    Route::post('/users', [UserController::class, 'store'])->setParams([
        'perm' => 'sys:user:create',
        'log'  => ['module' => '系统管理/用户', 'action' => 1, 'title' => '新增用户'],
    ]);
    Route::put('/users/{id:\d+}', [UserController::class, 'update'])->setParams([
        'perm' => 'sys:user:update',
        'log'  => ['module' => '系统管理/用户', 'action' => 2, 'title' => '编辑用户'],
    ]);
    Route::delete('/users/{id:\d+}', [UserController::class, 'destroy'])->setParams([
        'perm' => 'sys:user:delete',
        'log'  => ['module' => '系统管理/用户', 'action' => 3, 'title' => '删除用户'],
    ]);
    Route::put('/users/{id:\d+}/status', [UserController::class, 'setStatus'])->setParams([
        'perm' => 'sys:user:update',
        'log'  => ['module' => '系统管理/用户', 'action' => 2, 'title' => '启用/停用用户'],
    ]);
    Route::put('/users/{id:\d+}/roles', [UserController::class, 'grantRoles'])->setParams([
        'perm' => 'sys:user:grantRole',
        'log'  => ['module' => '系统管理/用户', 'action' => 5, 'title' => '分配角色'],
    ]);
    Route::put('/users/{id:\d+}/password/reset', [UserController::class, 'resetPassword'])->setParams([
        'perm' => 'sys:user:resetPwd',
        'log'  => ['module' => '系统管理/用户', 'action' => 2, 'title' => '重置密码'],
    ]);

    // ---------------- 数据字典（维护）----------------
    // 读接口在上面，登录即可；这里全是维护接口，要 sys:dict:*
    Route::get('/dicts', [DictController::class, 'index'])->setParams(['perm' => 'sys:dict:list']);
    Route::post('/dicts', [DictController::class, 'store'])->setParams([
        'perm' => 'sys:dict:create',
        'log'  => ['module' => '系统管理/字典', 'action' => 1, 'title' => '新增字典'],
    ]);
    Route::put('/dicts/{id:\d+}', [DictController::class, 'update'])->setParams([
        'perm' => 'sys:dict:update',
        'log'  => ['module' => '系统管理/字典', 'action' => 2, 'title' => '编辑字典'],
    ]);
    Route::delete('/dicts/{id:\d+}', [DictController::class, 'destroy'])->setParams([
        'perm' => 'sys:dict:delete',
        'log'  => ['module' => '系统管理/字典', 'action' => 3, 'title' => '删除字典'],
    ]);
    // {code} 段限定为编码字符，否则会把 /dicts/12 这样的 id 路径一起吃掉
    Route::get('/dicts/{code:[A-Za-z0-9_.-]+}/items/all', [DictController::class, 'allItems'])
        ->setParams(['perm' => 'sys:dict:list']);

    Route::post('/dict-items', [DictController::class, 'storeItem'])->setParams([
        'perm' => 'sys:dict:create',
        'log'  => ['module' => '系统管理/字典', 'action' => 1, 'title' => '新增字典项'],
    ]);
    Route::put('/dict-items/{id:\d+}', [DictController::class, 'updateItem'])->setParams([
        'perm' => 'sys:dict:update',
        'log'  => ['module' => '系统管理/字典', 'action' => 2, 'title' => '编辑字典项'],
    ]);
    Route::delete('/dict-items/{id:\d+}', [DictController::class, 'destroyItem'])->setParams([
        'perm' => 'sys:dict:delete',
        'log'  => ['module' => '系统管理/字典', 'action' => 3, 'title' => '删除字典项'],
    ]);
    Route::post('/dict-items/batch-delete', [DictController::class, 'batchDestroyItem'])->setParams([
        'perm' => 'sys:dict:delete',
        'log'  => ['module' => '系统管理/字典', 'action' => 3, 'title' => '批量删除字典项'],
    ]);

    // ---------------- 参数配置 ----------------
    Route::get('/params', [ParamController::class, 'index'])->setParams(['perm' => 'sys:param:list']);
    Route::get('/params/groups', [ParamController::class, 'groups'])->setParams(['perm' => 'sys:param:list']);
    Route::put('/params', [ParamController::class, 'batchUpdate'])->setParams([
        'perm' => 'sys:param:update',
        'log'  => ['module' => '系统管理/参数', 'action' => 2, 'title' => '保存参数'],
    ]);
    Route::get('/params/{id:\d+}', [ParamController::class, 'show'])->setParams(['perm' => 'sys:param:list']);
    Route::post('/params', [ParamController::class, 'store'])->setParams([
        'perm' => 'sys:param:create',
        'log'  => ['module' => '系统管理/参数', 'action' => 1, 'title' => '新增参数'],
    ]);
    Route::put('/params/{id:\d+}', [ParamController::class, 'update'])->setParams([
        'perm' => 'sys:param:update',
        'log'  => ['module' => '系统管理/参数', 'action' => 2, 'title' => '编辑参数'],
    ]);
    Route::delete('/params/{id:\d+}', [ParamController::class, 'destroy'])->setParams([
        'perm' => 'sys:param:delete',
        'log'  => ['module' => '系统管理/参数', 'action' => 3, 'title' => '删除参数'],
    ]);

    // ---------------- 日志审计（只读）----------------
    // 没有写接口：操作日志由中间件落库，登录日志由 AuthService 落库
    Route::get('/logs/operation', [LogController::class, 'operation'])
        ->setParams(['perm' => 'sys:log:operation:list']);
    // 固定路径排在 {id} 之前，否则 export 会被当成 id 匹配掉
    Route::get('/logs/operation/export', [LogController::class, 'exportOperation'])->setParams([
        'perm' => 'sys:log:operation:export',
        'log'  => ['module' => '日志审计/操作日志', 'action' => 4, 'title' => '导出操作日志'],
    ]);
    Route::get('/logs/operation/{id:\d+}', [LogController::class, 'operationDetail'])
        ->setParams(['perm' => 'sys:log:operation:detail']);

    Route::get('/logs/login', [LogController::class, 'login'])
        ->setParams(['perm' => 'sys:log:login:list']);
    Route::get('/logs/login/export', [LogController::class, 'exportLogin'])->setParams([
        'perm' => 'sys:log:login:export',
        'log'  => ['module' => '日志审计/登录日志', 'action' => 4, 'title' => '导出登录日志'],
    ]);
})->middleware([
    AdminAuthMiddleware::class,       // 认证：你是谁
    OperationLogMiddleware::class,    // 审计：声明了 log 的接口自动落库
    PermissionMiddleware::class,      // 鉴权：你能不能调这个接口
]);
// 审计包住鉴权而不是反过来：越权尝试同样要留痕，
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
Route::fallback(fn () => Result::error(HttpStatus::NOT_FOUND, BizCode::NOT_FOUND, '接口不存在'));
