<?php

declare(strict_types=1);

/**
 * 基础数据播种（幂等，可重复执行）
 *
 *   php scripts/seed.php           只播结构性数据：权限点、字典、参数、岗位
 *   php scripts/seed.php --demo    额外创建演示账号，用于验证数据权限
 *
 * 结构性数据按唯一键 upsert，不会覆盖你在界面上改过的名称之外的东西；
 * 演示账号只在不存在时创建，已存在则跳过（不重置密码）。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use app\admin\service\DictService;
use app\admin\service\PostService;
use app\common\support\Db;

$withDemo = in_array('--demo', $argv, true);
$now      = date('Y-m-d H:i:s');

// 等数据库就绪
$retry = 0;
while (true) {
    try {
        Db::conn()->getPdo();
        break;
    } catch (Throwable $e) {
        if (++$retry > 30) {
            fwrite(STDERR, "✗ 数据库连接失败：{$e->getMessage()}\n");
            exit(1);
        }
        sleep(1);
    }
}

// ─────────────────────────────────────────── 权限点与菜单
//
// 一棵树，type：1 目录 · 2 菜单 · 3 按钮 · 4 接口 · 5 数据(字段)
// 目录和菜单的 path/component 直接驱动前端动态路由，改这里就等于改菜单。
$tree = [
    /*
     * 首页（目录）→ 仪表盘（页面）
     *
     * 这块反复过两次，记一下免得再翻回来：最早是目录套一个页面，2026-08-19 拍平成
     * 一级菜单（理由是「底下只有一个页面，多一层只是多一次展开」），现在又改回目录——
     * 因为「首页」这一级往后要放不止一块内容（仪表盘之外还会有别的看板），
     * 而一级菜单一旦有了第二个页面就必须变成目录，那时候路径又得再改一次。
     *
     * ⚠️ 目录本身的 code（`home`）必须一并授权给角色，只给 `sys:dashboard:view`
     * 的话，`buildMenuTree` 从根找不到这条链，仪表盘会整条消失——
     * 普通员工会得到一个空侧边栏。见下面 $grants。
     */
    [
        'name' => '首页', 'code' => 'home', 'type' => 1,
        'path' => '/home', 'component' => 'Layout', 'icon' => 'HomeFilled', 'sort' => 10,
        'children' => [
            ['name' => '仪表盘', 'code' => 'sys:dashboard:view', 'type' => 2,
             'path' => '/home/dashboard', 'component' => 'views/dashboard/index.vue',
             'icon' => 'Odometer', 'sort' => 10],
        ],
    ],
    [
        'name' => '系统管理', 'code' => 'sys', 'type' => 1,
        'path' => '/system', 'component' => 'Layout', 'icon' => 'Setting', 'sort' => 90,
        'children' => [
            ['name' => '用户管理', 'code' => 'sys:user:list', 'type' => 2,
             'path' => '/system/user', 'component' => 'views/system/user/index.vue', 'icon' => 'User', 'sort' => 10,
             'children' => [
                 /*
                  * 详情单列一个权限点，不跟着列表走
                  *
                  * 列表页只给概要（姓名、部门、状态），详情才是完整档案——
                  * 用户详情带角色、备注、岗位，操作日志详情带请求参数与字段变更。
                  * 「能看名单」和「能看某个人的全部信息」是两回事，
                  * 合在 list 里就没法只给前者。
                  */
                 ['name' => '查看详情',   'code' => 'sys:user:detail',    'type' => 3, 'sort' => 0],
                 ['name' => '新增用户',   'code' => 'sys:user:create',    'type' => 3, 'sort' => 1],
                 ['name' => '编辑用户',   'code' => 'sys:user:update',    'type' => 3, 'sort' => 2],
                 ['name' => '删除用户',   'code' => 'sys:user:delete',    'type' => 3, 'sort' => 3],
                 ['name' => '重置密码',   'code' => 'sys:user:resetPwd',  'type' => 3, 'sort' => 4],
                 ['name' => '分配角色',   'code' => 'sys:user:grantRole', 'type' => 3, 'sort' => 5],
                 ['name' => '导入用户',   'code' => 'sys:user:import',    'type' => 3, 'sort' => 6],
                 ['name' => '导出用户',   'code' => 'sys:user:export',    'type' => 3, 'sort' => 7],
                 ['name' => '查看手机号', 'code' => 'sys:field:user:phone', 'type' => 5, 'sort' => 90, 'visible' => 0],
                 ['name' => '查看邮箱',   'code' => 'sys:field:user:email', 'type' => 5, 'sort' => 91, 'visible' => 0],
             ]],
            ['name' => '部门管理', 'code' => 'sys:dept:list', 'type' => 2,
             'path' => '/system/dept', 'component' => 'views/system/dept/index.vue', 'icon' => 'OfficeBuilding', 'sort' => 20,
             'children' => [
                 ['name' => '查看详情', 'code' => 'sys:dept:detail', 'type' => 3, 'sort' => 0],
                 ['name' => '新增部门', 'code' => 'sys:dept:create', 'type' => 3, 'sort' => 1],
                 ['name' => '编辑部门', 'code' => 'sys:dept:update', 'type' => 3, 'sort' => 2],
                 ['name' => '删除部门', 'code' => 'sys:dept:delete', 'type' => 3, 'sort' => 3],
             ]],
            ['name' => '岗位管理', 'code' => 'sys:post:list', 'type' => 2,
             'path' => '/system/post', 'component' => 'views/system/post/index.vue', 'icon' => 'Postcard', 'sort' => 30,
             'children' => [
                 ['name' => '查看详情', 'code' => 'sys:post:detail', 'type' => 3, 'sort' => 0],
                 ['name' => '新增岗位', 'code' => 'sys:post:create', 'type' => 3, 'sort' => 1],
                 ['name' => '编辑岗位', 'code' => 'sys:post:update', 'type' => 3, 'sort' => 2],
                 ['name' => '删除岗位', 'code' => 'sys:post:delete', 'type' => 3, 'sort' => 3],
             ]],
            ['name' => '角色管理', 'code' => 'sys:role:list', 'type' => 2,
             'path' => '/system/role', 'component' => 'views/system/role/index.vue', 'icon' => 'Avatar', 'sort' => 40,
             'children' => [
                 ['name' => '查看详情',     'code' => 'sys:role:detail',    'type' => 3, 'sort' => 0],
                 ['name' => '新增角色',     'code' => 'sys:role:create',    'type' => 3, 'sort' => 1],
                 ['name' => '编辑角色',     'code' => 'sys:role:update',    'type' => 3, 'sort' => 2],
                 ['name' => '删除角色',     'code' => 'sys:role:delete',    'type' => 3, 'sort' => 3],
                 ['name' => '分配功能权限', 'code' => 'sys:role:grantPerm', 'type' => 3, 'sort' => 4],
                 ['name' => '设置数据范围', 'code' => 'sys:role:grantData', 'type' => 3, 'sort' => 5],
             ]],
            ['name' => '菜单权限', 'code' => 'sys:menu:list', 'type' => 2,
             'path' => '/system/menu', 'component' => 'views/system/menu/index.vue', 'icon' => 'Menu', 'sort' => 50,
             'children' => [
                 ['name' => '查看详情', 'code' => 'sys:menu:detail', 'type' => 3, 'sort' => 0],
                 ['name' => '新增节点', 'code' => 'sys:menu:create', 'type' => 3, 'sort' => 1],
                 ['name' => '编辑节点', 'code' => 'sys:menu:update', 'type' => 3, 'sort' => 2],
                 ['name' => '删除节点', 'code' => 'sys:menu:delete', 'type' => 3, 'sort' => 3],
             ]],
        ],
    ],
    /*
     * 数据管理
     *
     * 字典从「系统管理」里挪出来单立一级：它是**业务侧**每天都要维护的东西
     * （加一个状态值、调一次排序），而系统管理里其余几项是搭好就基本不动的
     * 组织与权限配置，两者的使用频次和使用人差着一个量级。
     *
     * 路径跟着改成 `/data/dict`：动态路由是拍平的，父级换了不改子路径也能跑，
     * 但那样菜单里在「数据管理」下、地址栏却是 `/system/`，
     * 排查问题时按 URL 找不到人。
     */
    [
        'name' => '数据管理', 'code' => 'data', 'type' => 1,
        'path' => '/data', 'component' => 'Layout', 'icon' => 'Coin', 'sort' => 92,
        'children' => [
            ['name' => '数据字典', 'code' => 'sys:dict:list', 'type' => 2,
             'path' => '/data/dict', 'component' => 'views/system/dict/index.vue', 'icon' => 'Collection', 'sort' => 10,
             'children' => [
                 ['name' => '新增字典', 'code' => 'sys:dict:create', 'type' => 3, 'sort' => 1],
                 ['name' => '编辑字典', 'code' => 'sys:dict:update', 'type' => 3, 'sort' => 2],
                 ['name' => '删除字典', 'code' => 'sys:dict:delete', 'type' => 3, 'sort' => 3],
             ]],
            ['name' => '系统公告', 'code' => 'sys:notice:list', 'type' => 2,
             'path' => '/data/notice', 'component' => 'views/data/notice/index.vue', 'icon' => 'Bell', 'sort' => 20,
             'children' => [
                 ['name' => '查看详情', 'code' => 'sys:notice:detail',  'type' => 3, 'sort' => 0],
                 ['name' => '新增公告', 'code' => 'sys:notice:create',  'type' => 3, 'sort' => 1],
                 ['name' => '编辑公告', 'code' => 'sys:notice:update',  'type' => 3, 'sort' => 2],
                 ['name' => '删除公告', 'code' => 'sys:notice:delete',  'type' => 3, 'sort' => 3],
                 // 发布与撤回是同一个权限点：能发就能撤，反过来（只能撤不能发）没有使用场景
                 ['name' => '发布公告', 'code' => 'sys:notice:publish', 'type' => 3, 'sort' => 4],
             ]],
            /*
             * 数据导出
             *
             * 这一页只是「看任务 + 下载」。发起导出的权限点在各业务模块自己那儿
             * （sys:user:export 等），所以这里没有 create 之类的按钮权限。
             *
             * ⚠️ 谁有 xxx:export 就得有 sys:export:list，否则他导得出来、
             * 却看不到那条任务，也就永远下载不了。授权时两者要一起给。
             */
            ['name' => '数据导出', 'code' => 'sys:export:list', 'type' => 2,
             'path' => '/data/export', 'component' => 'views/data/export/index.vue', 'icon' => 'Download', 'sort' => 30,
             'children' => [
                 ['name' => '删除导出任务', 'code' => 'sys:export:delete', 'type' => 3, 'sort' => 1],
             ]],
        ],
    ],
    [
        'name' => '日志审计', 'code' => 'sys:log', 'type' => 1,
        'path' => '/log', 'component' => 'Layout', 'icon' => 'Document', 'sort' => 95,
        'children' => [
            ['name' => '操作日志', 'code' => 'sys:log:operation:list', 'type' => 2,
             'path' => '/log/operation', 'component' => 'views/log/operation/index.vue', 'icon' => 'Tickets', 'sort' => 10,
             'children' => [
                 ['name' => '查看详情',     'code' => 'sys:log:operation:detail', 'type' => 3, 'sort' => 0],
                 ['name' => '导出操作日志', 'code' => 'sys:log:operation:export', 'type' => 3, 'sort' => 1],
             ]],
            ['name' => '登录日志', 'code' => 'sys:log:login:list', 'type' => 2,
             'path' => '/log/login', 'component' => 'views/log/login/index.vue', 'icon' => 'Key', 'sort' => 20,
             'children' => [
                 ['name' => '导出登录日志', 'code' => 'sys:log:login:export', 'type' => 3, 'sort' => 1],
             ]],
        ],
    ],
    /*
     * 系统配置：放全站运行方式的开关，与「系统管理」（管人、管组织、管授权）分开
     *
     * 参数配置从「系统管理」挪到了这里，路径跟着从 `/system/param` 变成 `/config/param`。
     * 路由是菜单驱动的，改这一行就够了，前端不用发版——但**写死了旧路径的地方要跟着改**
     * （概览页的快捷入口 `DashboardService`、PROJECT.md 的路由表）。
     *
     * 目录 code `config` 同样要授权给用到它的角色，否则子菜单被 buildMenuTree 剪掉。
     * 内置角色里只有超管有 `sys:param:list`，所以这次只影响超管（`*`，自动全有）。
     */
    [
        'name' => '系统配置', 'code' => 'config', 'type' => 1,
        'path' => '/config', 'component' => 'Layout', 'icon' => 'SetUp', 'sort' => 96,
        'children' => [
            ['name' => '参数配置', 'code' => 'sys:param:list', 'type' => 2,
             'path' => '/config/param', 'component' => 'views/system/param/index.vue', 'icon' => 'Tools', 'sort' => 10,
             'children' => [
                 ['name' => '新增参数', 'code' => 'sys:param:create', 'type' => 3, 'sort' => 1],
                 ['name' => '编辑参数', 'code' => 'sys:param:update', 'type' => 3, 'sort' => 2],
                 ['name' => '删除参数', 'code' => 'sys:param:delete', 'type' => 3, 'sort' => 3],
             ]],
        ],
    ],
];

/** 按 perm_code upsert，返回节点 id；父子关系用 code 解析，不依赖自增 id */
function upsertPermission(array $node, int $parentId, string $now): int
{
    $row = [
        'parent_id'  => $parentId,
        'name'       => $node['name'],
        'type'       => $node['type'],
        'perm_code'  => $node['code'],
        'path'       => $node['path'] ?? '',
        'component'  => $node['component'] ?? '',
        'icon'       => $node['icon'] ?? '',
        'visible'    => $node['visible'] ?? 1,
        'keep_alive' => $node['keep_alive'] ?? 1,
        'sort'       => $node['sort'] ?? 0,
        'status'     => 1,
        'updated_at' => $now,
    ];

    $exists = Db::table('sys_permissions')->where('perm_code', $node['code'])->first();

    if ($exists) {
        Db::table('sys_permissions')->where('id', $exists->id)->update($row);
        $id = (int) $exists->id;
    } else {
        $row['created_at'] = $now;
        $id = (int) Db::table('sys_permissions')->insertGetId($row);
    }

    foreach ($node['children'] ?? [] as $child) {
        upsertPermission($child, $id, $now);
    }

    return $id;
}

foreach ($tree as $node) {
    upsertPermission($node, 0, $now);
}

/**
 * 退役的权限点
 *
 * upsert 只增不减：从 $tree 里删掉一个 code，存量库里那一行会永远留着。
 * 空库看不出问题，生产库上就是「菜单已经不该有了，侧边栏还在渲染它」，
 * 而且授权关系还挂着。所以退役的 code 要显式登记在这里，每次 seed 幂等清掉。
 *
 * 只处理登记的这几个 code 本身——如果退役的是个还带子节点的目录，
 * 子节点要一并登记，别指望这里级联。
 */
$retired = [
    // 概览时期的目录 code。首页那一级现在用的是 `home`，这个不再出现在树里，
    // 存量库里的行要清掉——留着的话侧边栏会渲染一个点开空无一物的死目录
    'sys:dashboard',
];

$retiredIds = Db::table('sys_permissions')->whereIn('perm_code', $retired)->pluck('id')->all();
if ($retiredIds) {
    Db::table('sys_role_permissions')->whereIn('permission_id', $retiredIds)->delete();
    Db::table('sys_permissions')->whereIn('id', $retiredIds)->delete();
    echo '  ✓ 清理退役权限点 ' . count($retiredIds) . " 条\n";
}

echo '  ✓ 权限点 ' . Db::table('sys_permissions')->count() . " 条\n";

// ─────────────────────────────────────────── 角色授权
//
// 内置角色的功能权限。超级管理员不用配——is_super 的账号跳过一切校验，
// 这里给它全量只是为了角色详情页能正常展示。
$grants = [
    '超级管理员' => ['*'],
    /**
     * 部门主管：管得了本部门的人，看得到组织结构与日志，但碰不到系统级配置
     *
     * 刻意留出的空白也是演示的一部分：
     * - 没有 `sys:dict:list` / `sys:param:list` → 侧边栏根本不出现这两个菜单
     * - 没有 `sys:user:grantRole` / `sys:role:grantPerm` → 授权按钮不渲染，
     *   直接调接口也是 403（授权是系统管理员的活，主管能授权就等于能自我提权）
     * - 没有 `sys:user:delete` / `sys:user:import` → 破坏力大的批量操作不下放
     * - 有 `sys:field:user:phone` 但没有 `sys:field:user:email` → 手机号看得见、
     *   邮箱是掩码，一个账号上就能看出字段级权限的效果
     */
    '部门主管' => [
        // 目录 code 与页面 code 都要给：少了目录，子菜单会被 buildMenuTree 剪掉
        'home', 'sys:dashboard:view',
        'sys',
        'sys:user:list', 'sys:user:create', 'sys:user:update',
        'sys:user:resetPwd', 'sys:user:export',
        'sys:dept:list', 'sys:post:list', 'sys:role:list',
        // 详情从 list 里拆出来了，原先能看的现在要显式给，否则详情按钮会消失
        'sys:user:detail', 'sys:dept:detail', 'sys:post:detail', 'sys:role:detail',
        'sys:log:operation:detail',
        // 公告只给读：主管能看到发过什么，但发全员通知是系统管理员的活
        'data', 'sys:notice:list',
        // 他有 sys:user:export，就必须能看到自己的导出任务，否则导了也下不了
        'sys:export:list', 'sys:export:delete',
        'sys:log', 'sys:log:operation:list', 'sys:log:login:list',
        'sys:field:user:phone',
    ],

    // 普通员工：只有首页下的仪表盘。它是对照组——越权测试要有一个「什么都没有」的账号，
    // 才能验证 fail-closed 是真的关着，而不是碰巧没人去点。
    // 目录 code 不能漏，漏了这个账号会得到一个空侧边栏
    '普通员工' => ['home', 'sys:dashboard:view'],
];

$permIdByCode = Db::table('sys_permissions')->pluck('id', 'perm_code')->all();

/*
 * 按**名称**找角色，不是按编码
 *
 * 角色编码已改成由主键推导（ROLE-0001…），写死编码就再也对不上。
 * 而且这个查找原来是静默 `continue` 的：找不到就什么都不授，不报错、不提示，
 * 表现是「部门主管和普通员工权限全没了」而日志里一片安静。
 * 现在找不到会明确喊一声——内置角色本来就该在 schema.sql 里存在，
 * 找不到只可能是数据被改坏了，那是需要人看一眼的事。
 */
foreach ($grants as $roleName => $codes) {
    $role = Db::table('sys_roles')->where('name', $roleName)->first();
    if (!$role) {
        echo "  · 跳过授权：找不到内置角色「{$roleName}」\n";
        continue;
    }

    $ids = $codes === ['*']
        ? array_values($permIdByCode)
        : array_values(array_intersect_key($permIdByCode, array_flip($codes)));

    Db::table('sys_role_permissions')->where('role_id', $role->id)->delete();
    foreach (array_chunk($ids, 200) as $chunk) {
        Db::table('sys_role_permissions')->insertOrIgnore(
            array_map(fn ($pid) => ['role_id' => $role->id, 'permission_id' => $pid], $chunk)
        );
    }
}
echo "  ✓ 内置角色授权\n";

// ─────────────────────────────────────────── 岗位
/*
 * 岗位不带 code：编码由 PostService::makeCode() 按主键生成，这里插完再回写。
 *
 * 对齐用的唯一键也随之从 code 换成 name。这一步是必须的而不是顺手改的——
 * 编码从 POST-DEV 变成 POST-0002 之后，还按 code 找旧行会一个都找不到，
 * 于是每次播种都往库里再插一份「研发工程师」，越播越多。
 */
$posts = [
    ['name' => '技术负责人', 'dept_id' => 2, 'default_role_id' => 2, 'sort' => 1],
    ['name' => '研发工程师', 'dept_id' => 2, 'default_role_id' => 3, 'sort' => 2],
    ['name' => '运营专员',   'dept_id' => 3, 'default_role_id' => 3, 'sort' => 3],
];
foreach ($posts as $post) {
    $post += ['status' => 1, 'remark' => '', 'updated_at' => $now];
    $exists = Db::table('sys_posts')->where('name', $post['name'])->first();

    if ($exists) {
        // 不带 code，已有行的编码保持不动
        Db::table('sys_posts')->where('id', $exists->id)->update($post);
        continue;
    }

    // 占位值同 PostService::create()：code 是 NOT NULL + uk_code，
    // 三条一起插的话留空会在第二条上撞唯一索引
    $id = Db::table('sys_posts')->insertGetId(
        $post + ['code' => '~tmp~' . bin2hex(random_bytes(8)), 'created_at' => $now]
    );
    Db::table('sys_posts')->where('id', $id)->update(['code' => PostService::makeCode((int) $id)]);
}
echo "  ✓ 岗位 " . count($posts) . " 个\n";

// ─────────────────────────────────────────── 数据字典
//
// 前端所有枚举与状态色都从这里来，页面里不允许写死（CLAUDE.md 前端约定）
$dicts = [
    'common_status' => ['通用状态', [
        ['正常', '1', 'success'], ['待处理', '2', 'warning'], ['异常', '3', 'danger'],
        ['进行中', '4', 'primary'], ['已归档', '5', 'info'],
    ]],
    'enable_status' => ['启用状态', [['启用', '1', 'success'], ['停用', '0', 'info']]],
    'user_status'   => ['用户状态', [['启用', '1', 'success'], ['停用', '0', 'info']]],
    'data_scope'    => ['数据范围', [
        ['全部', '1', 'danger'], ['本部门及下属', '2', 'warning'], ['本部门', '3', 'primary'],
        ['仅本人', '4', 'info'], ['自定义', '5', 'success'],
    ]],
    'perm_type'     => ['权限类型', [
        ['目录', '1', 'info'], ['菜单', '2', 'primary'], ['按钮', '3', 'success'],
        ['接口', '4', 'warning'], ['数据', '5', 'danger'],
    ]],
    'log_action'    => ['操作类型', [
        ['新增', '1', 'success'], ['修改', '2', 'primary'], ['删除', '3', 'danger'],
        ['导出', '4', 'warning'], ['授权', '5', 'info'], ['其他', '6', 'info'],
    ]],
    'log_status'    => ['执行结果', [['成功', '1', 'success'], ['失败', '0', 'danger']]],
    // 3 是邮箱登录的发码动作：它不是一次登录，但要留在同一条时间线上，
    // 否则「有人拿着我的密码在申请验证码」这件事在后台里查不到
    'login_type'    => ['登录类型', [
        ['登录', '1', 'primary'], ['登出', '2', 'info'], ['发送验证码', '3', 'warning'],
    ]],
    'notice_type'   => ['公告类型', [
        ['通知', 'notice', 'primary'], ['公告', 'announcement', 'success'],
        ['维护', 'maintenance', 'warning'], ['紧急', 'urgent', 'danger'],
    ]],
    // 单开一份而不是复用 enable_status：公告的 0/1 是「草稿 / 已发布」，
    // 与「停用 / 启用」不是一回事，共用字典会让列表里的公告显示成「已停用」
    'export_status' => ['导出状态', [
        ['排队中', '0', 'info'], ['处理中', '1', 'primary'],
        ['已完成', '2', 'success'], ['失败', '3', 'danger'],
    ]],
    'export_biz'    => ['导出业务', [
        ['用户', 'user', 'primary'], ['操作日志', 'log_operation', 'info'],
        ['登录日志', 'log_login', 'info'],
    ]],
    'notice_status' => ['公告状态', [['草稿', '0', 'info'], ['已发布', '1', 'success']]],
    'yes_no'        => ['是否', [['是', '1', 'success'], ['否', '0', 'info']]],
    'gender'        => ['性别', [['男', '1', 'primary'], ['女', '2', 'danger'], ['未知', '0', 'info']]],
];

foreach ($dicts as $code => [$name, $items]) {
    $type   = ['name' => $name, 'code' => $code, 'status' => 1, 'remark' => '', 'updated_at' => $now];
    $exists = Db::table('sys_dict_types')->where('code', $code)->first();
    $exists
        ? Db::table('sys_dict_types')->where('id', $exists->id)->update($type)
        : Db::table('sys_dict_types')->insert($type + ['created_at' => $now]);

    foreach ($items as $i => [$label, $value, $tag]) {
        $item = [
            'type_code' => $code, 'label' => $label, 'value' => $value,
            'tag_type'  => $tag, 'sort' => $i + 1, 'status' => 1, 'remark' => '',
            'updated_at' => $now,
        ];
        $has = Db::table('sys_dict_items')->where('type_code', $code)->where('value', $value)->first();
        $has
            ? Db::table('sys_dict_items')->where('id', $has->id)->update($item)
            : Db::table('sys_dict_items')->insert($item + ['created_at' => $now]);
    }
}
echo '  ✓ 字典 ' . count($dicts) . " 类\n";

/*
 * 退役的字典项
 *
 * 上面那个循环只 upsert，从来不删——所以枚举「少了一个值」时，旧项会一直留在库里：
 * 筛选下拉里还看得见「试用期」，选中之后永远筛不出任何数据。
 *
 * 不做「把 seed 之外的项全删掉」，那会连用户自己在字典管理里加的项一起清掉。
 * 要退役哪一项就在这里点名，按 (type_code, value) 精确删，重复执行不出错。
 */
$retired = [
    // 用户状态归并为两档：试用期是人事状态，登录与鉴权从来没看过它（见 HasStatus）
    ['user_status', '2'],
];

$dropped = 0;
foreach ($retired as [$code, $value]) {
    $dropped += Db::table('sys_dict_items')->where('type_code', $code)->where('value', $value)->delete();
}
if ($dropped > 0) {
    echo "  ✓ 清理退役字典项 {$dropped} 条\n";
}

/*
 * 清掉字典缓存
 *
 * DictService 把每类字典缓存在 Redis 里（TTL = sys.cache.ttl，默认 300 秒），
 * 而上面是直接写库的，缓存不会自己失效。不清的话，改完标签重新播种，
 * 前端最长还要拿五分钟旧值——「部署完了但界面没变」最容易被当成改动没生效。
 *
 * Redis 连不上不算致命：缓存过期后自愈，不该把整个播种拖挂。
 */
try {
    foreach (array_keys($dicts) as $code) {
        DictService::forget((string) $code);
    }
    echo "  ✓ 字典缓存已清\n";
} catch (Throwable $e) {
    echo "  · 字典缓存未清（{$e->getMessage()}），最长 sys.cache.ttl 秒后自动过期\n";
}

// ─────────────────────────────────────────── 系统参数
// 第 6 位是 is_secret：密钥类参数只写不读，接口返回掩码（docs/api.md §9）
$params = [
    ['sys.name',             'Keel Admin', 'basic',    'string', '系统名称'],
    ['sys.logo',             '',           'basic',    'string', '登录页 Logo 地址'],
    ['sys.footer',           'Keel v1.0.0 · MIT License', 'basic', 'string', '登录页页脚文案'],
    ['sys.page.size',        '20',         'basic',    'int',    '默认分页条数'],
    ['sys.upload.maxSize',   '20971520',   'advanced', 'int',    '单文件上传上限（字节）'],
    // 头像单开一档：全局的 20MB 对一张头像太宽松，而收进来就要长期占盘
    ['sys.upload.avatarMaxSize', '2097152', 'advanced', 'int',   '头像上传上限（字节）'],
    ['sys.export.maxRows',   '50000',      'advanced', 'int',    '单次导出最大行数'],
    // 导出文件保留天数。改小了会让「昨天发起、今天来下载」变成文件已过期，
    // 改大了占磁盘——runtime/exports 没有容量上限，只有这一个阈值管着
    ['sys.export.retainDays', '3',          'advanced', 'int',    '导出文件保留天数'],
    ['sys.log.retainDays',   '180',        'advanced', 'int',    '日志保留天数'],
    ['sys.cache.ttl',        '300',        'advanced', 'int',    '字典缓存秒数'],
    ['sys.role.maxPerUser',  '5',          'security', 'int',    '单账号最多可持有的角色数'],
    ['sys.pwd.minLength',    '8',          'security', 'int',    '密码最小长度'],
    ['sys.pwd.expireDays',   '90',         'security', 'int',    '密码有效期（天）'],
    ['sys.login.failLimit',  '5',          'security', 'int',    '连续失败锁定次数'],
    ['sys.login.lockMinutes','30',         'security', 'int',    '锁定时长（分钟）'],
    ['sys.session.timeout',  '1800',       'security', 'int',    '无操作登出秒数'],
    // 集成组给的是空壳：脚手架不预置任何真实凭据，
    // 但要留出 is_secret 的样例，否则「只写不读」这条链路没人走
    ['sys.sms.provider',     'aliyun',     'integration', 'string', '短信服务商'],
    ['sys.sms.accessKey',    '',           'integration', 'string', '短信 AccessKey', 1],
    ['sys.oss.endpoint',     '',           'integration', 'string', '对象存储 Endpoint'],
    ['sys.oss.accessSecret', '',           'integration', 'string', '对象存储 AccessSecret', 1],
    /*
     * 邮件（邮箱登录用）
     *
     * 全部留空，由使用者自己填——脚手架不预置任何真实凭据。
     * `sys.mail.host` 与 `sys.mail.from` 都有值时，登录页才多出「邮箱登录」入口。
     *
     * 这几项**参数表优先、`.env` 兜底**（见 MailService::conf）：容器化部署
     * 可以继续把 SMTP 放在 `.env` 里一次配好、界面留空；要在界面上改的就填这里。
     *
     * ⚠️ 口令是 is_secret：列表接口只回掩码，保存时提交掩码等于「不改」。
     * 但要清楚它挡不住的是什么——有 `sys:param:update` 的人虽然看不到旧口令，
     * 却能把 host 改到自己的服务器上，之后全站的邮箱验证码都投到那里。
     * 这个权限点该只给运维。
     */
    // 第 7 位是 remark：说明写这里而不是塞进 name——name 是界面上的字段标签，
    // 写长了会折成两行，把整组表单撑得参差不齐
    ['sys.mail.host',       '',    'system', 'string', 'SMTP 服务器', 0, 'QQ 邮箱是 smtp.qq.com'],
    ['sys.mail.port',       '465', 'system', 'int',    'SMTP 端口',   0, 'ssl 用 465，tls 用 587'],
    ['sys.mail.encryption', 'ssl', 'system', 'string', '加密方式',    0, 'ssl(465) / tls(587) / none'],
    ['sys.mail.username',   '',    'system', 'string', 'SMTP 账号',   0, '通常就是完整的邮箱地址'],
    ['sys.mail.password',   '',    'system', 'string', 'SMTP 密码',   1, 'QQ / 163 等要填授权码，不是邮箱的登录密码'],
    ['sys.mail.from',       '',    'system', 'string', '发件人地址',  0, '多数服务商要求与 SMTP 账号一致，否则被拒收'],
    ['sys.mail.fromName',   '',    'system', 'string', '发件人显示名', 0, '留空取系统名称'],
];
foreach ($params as $row) {
    [$key, $value, $group, $type, $name] = $row;
    $isSecret = (int) ($row[5] ?? 0);
    $remark   = (string) ($row[6] ?? '');

    $exists = Db::table('sys_params')->where('param_key', $key)->first();
    if ($exists) {
        // 只补齐元信息，不覆盖已改过的值。remark 也算元信息——
        // 它是给运维看的说明，改了措辞就该在存量库里一起更新
        Db::table('sys_params')->where('id', $exists->id)->update([
            'name' => $name, 'group' => $group, 'value_type' => $type,
            'is_builtin' => 1, 'is_secret' => $isSecret, 'remark' => $remark, 'updated_at' => $now,
        ]);
        continue;
    }
    Db::table('sys_params')->insert([
        'group' => $group, 'name' => $name, 'param_key' => $key, 'param_value' => $value,
        'value_type' => $type, 'is_builtin' => 1, 'is_secret' => $isSecret, 'remark' => $remark,
        'created_at' => $now, 'updated_at' => $now,
    ]);
}
echo '  ✓ 系统参数 ' . count($params) . " 项\n";

// ─────────────────────────────────────────── 演示账号
if ($withDemo) {
    $demoPassword = 'demo123456';
    $demoUsers = [
        ['username' => 'manager', 'real_name' => '王强', 'dept_id' => 2, 'post_id' => 1, 'role' => '部门主管', 'phone' => '13800138001'],
        ['username' => 'dev01',   'real_name' => '李娜', 'dept_id' => 2, 'post_id' => 2, 'role' => '普通员工', 'phone' => '13800138002'],
        ['username' => 'ops01',   'real_name' => '赵敏', 'dept_id' => 3, 'post_id' => 3, 'role' => '普通员工', 'phone' => '13800138003'],
    ];

    $created = 0;
    foreach ($demoUsers as $demo) {
        if (Db::table('sys_users')->where('username', $demo['username'])->exists()) {
            continue;
        }
        $role = Db::table('sys_roles')->where('name', $demo['role'])->first();

        Db::transaction(function () use ($demo, $role, $demoPassword, $now) {
            $uid = Db::table('sys_users')->insertGetId([
                'username'   => $demo['username'],
                'password'   => password_hash($demoPassword, PASSWORD_DEFAULT),
                'real_name'  => $demo['real_name'],
                'phone'      => $demo['phone'],
                'email'      => $demo['username'] . '@example.com',
                'dept_id'    => $demo['dept_id'],
                'post_id'    => $demo['post_id'],
                'status'     => 1,
                'is_super'   => 0,
                'remark'     => '演示账号',
                'created_at' => $now,
                'updated_at' => $now,
                'pwd_updated_at' => $now,
            ]);
            if ($role) {
                Db::table('sys_user_roles')->insertOrIgnore(['user_id' => $uid, 'role_id' => $role->id]);
            }
        });
        $created++;
    }

    echo $created > 0
        ? "  ✓ 演示账号 {$created} 个（密码 {$demoPassword}）\n"
        : "  演示账号已存在，跳过\n";
}

// 授权可能变了，递增权限版本号让 Redis 里的权限缓存失效
Db::table('sys_users')->increment('perm_version');

echo "  ✓ 播种完成\n";
