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

use app\common\service\DictService;
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
    // 概览是一级菜单，不套目录：底下只有一个页面，为它单独立一层
    // 只会让侧边栏多一次展开点击，面包屑还会出现「概览 / 系统概览」这种同义重复。
    // 一级节点直接当页面用是支持的（component 不填 Layout 即可），见 docs/api.md §菜单
    [
        'name' => '概览', 'code' => 'sys:dashboard:view', 'type' => 2,
        'path' => '/dashboard', 'component' => 'views/dashboard/index.vue', 'icon' => 'Odometer', 'sort' => 10,
    ],
    [
        'name' => '系统管理', 'code' => 'sys', 'type' => 1,
        'path' => '/system', 'component' => 'Layout', 'icon' => 'Setting', 'sort' => 90,
        'children' => [
            ['name' => '用户管理', 'code' => 'sys:user:list', 'type' => 2,
             'path' => '/system/user', 'component' => 'views/system/user/index.vue', 'icon' => 'User', 'sort' => 10,
             'children' => [
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
                 ['name' => '新增部门', 'code' => 'sys:dept:create', 'type' => 3, 'sort' => 1],
                 ['name' => '编辑部门', 'code' => 'sys:dept:update', 'type' => 3, 'sort' => 2],
                 ['name' => '删除部门', 'code' => 'sys:dept:delete', 'type' => 3, 'sort' => 3],
             ]],
            ['name' => '岗位管理', 'code' => 'sys:post:list', 'type' => 2,
             'path' => '/system/post', 'component' => 'views/system/post/index.vue', 'icon' => 'Postcard', 'sort' => 30,
             'children' => [
                 ['name' => '新增岗位', 'code' => 'sys:post:create', 'type' => 3, 'sort' => 1],
                 ['name' => '编辑岗位', 'code' => 'sys:post:update', 'type' => 3, 'sort' => 2],
                 ['name' => '删除岗位', 'code' => 'sys:post:delete', 'type' => 3, 'sort' => 3],
             ]],
            ['name' => '角色管理', 'code' => 'sys:role:list', 'type' => 2,
             'path' => '/system/role', 'component' => 'views/system/role/index.vue', 'icon' => 'Avatar', 'sort' => 40,
             'children' => [
                 ['name' => '新增角色',     'code' => 'sys:role:create',    'type' => 3, 'sort' => 1],
                 ['name' => '编辑角色',     'code' => 'sys:role:update',    'type' => 3, 'sort' => 2],
                 ['name' => '删除角色',     'code' => 'sys:role:delete',    'type' => 3, 'sort' => 3],
                 ['name' => '分配功能权限', 'code' => 'sys:role:grantPerm', 'type' => 3, 'sort' => 4],
                 ['name' => '设置数据范围', 'code' => 'sys:role:grantData', 'type' => 3, 'sort' => 5],
             ]],
            ['name' => '菜单权限', 'code' => 'sys:menu:list', 'type' => 2,
             'path' => '/system/menu', 'component' => 'views/system/menu/index.vue', 'icon' => 'Menu', 'sort' => 50,
             'children' => [
                 ['name' => '新增节点', 'code' => 'sys:menu:create', 'type' => 3, 'sort' => 1],
                 ['name' => '编辑节点', 'code' => 'sys:menu:update', 'type' => 3, 'sort' => 2],
                 ['name' => '删除节点', 'code' => 'sys:menu:delete', 'type' => 3, 'sort' => 3],
             ]],
            ['name' => '数据字典', 'code' => 'sys:dict:list', 'type' => 2,
             'path' => '/system/dict', 'component' => 'views/system/dict/index.vue', 'icon' => 'Collection', 'sort' => 60,
             'children' => [
                 ['name' => '新增字典', 'code' => 'sys:dict:create', 'type' => 3, 'sort' => 1],
                 ['name' => '编辑字典', 'code' => 'sys:dict:update', 'type' => 3, 'sort' => 2],
                 ['name' => '删除字典', 'code' => 'sys:dict:delete', 'type' => 3, 'sort' => 3],
             ]],
            ['name' => '参数配置', 'code' => 'sys:param:list', 'type' => 2,
             'path' => '/system/param', 'component' => 'views/system/param/index.vue', 'icon' => 'Tools', 'sort' => 70,
             'children' => [
                 ['name' => '新增参数', 'code' => 'sys:param:create', 'type' => 3, 'sort' => 1],
                 ['name' => '编辑参数', 'code' => 'sys:param:update', 'type' => 3, 'sort' => 2],
                 ['name' => '删除参数', 'code' => 'sys:param:delete', 'type' => 3, 'sort' => 3],
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
                 ['name' => '导出操作日志', 'code' => 'sys:log:operation:export', 'type' => 3, 'sort' => 1],
             ]],
            ['name' => '登录日志', 'code' => 'sys:log:login:list', 'type' => 2,
             'path' => '/log/login', 'component' => 'views/log/login/index.vue', 'icon' => 'Key', 'sort' => 20,
             'children' => [
                 ['name' => '导出登录日志', 'code' => 'sys:log:login:export', 'type' => 3, 'sort' => 1],
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
    // 概览原来是目录（sys:dashboard）套一个页面，2026-08-19 改成一级菜单后不再需要
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
// 内置角色的功能权限。ROLE_SUPER 不用配——is_super 的账号跳过一切校验，
// 这里给它全量只是为了角色详情页能正常展示。
$grants = [
    'ROLE_SUPER'    => ['*'],
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
    'ROLE_DEPT_MGR' => [
        'sys:dashboard:view',
        'sys',
        'sys:user:list', 'sys:user:create', 'sys:user:update',
        'sys:user:resetPwd', 'sys:user:export',
        'sys:dept:list', 'sys:post:list', 'sys:role:list',
        'sys:log', 'sys:log:operation:list', 'sys:log:login:list',
        'sys:field:user:phone',
    ],

    // 普通员工：只有概览。它是对照组——越权测试要有一个「什么都没有」的账号，
    // 才能验证 fail-closed 是真的关着，而不是碰巧没人去点
    'ROLE_STAFF'    => ['sys:dashboard:view'],
];

$permIdByCode = Db::table('sys_permissions')->pluck('id', 'perm_code')->all();

foreach ($grants as $roleCode => $codes) {
    $role = Db::table('sys_roles')->where('code', $roleCode)->first();
    if (!$role) {
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
$posts = [
    ['name' => '技术负责人', 'code' => 'POST-TECH-LEAD', 'dept_id' => 2, 'default_role_id' => 2, 'sort' => 1],
    ['name' => '研发工程师', 'code' => 'POST-DEV',       'dept_id' => 2, 'default_role_id' => 3, 'sort' => 2],
    ['name' => '运营专员',   'code' => 'POST-OPS',       'dept_id' => 3, 'default_role_id' => 3, 'sort' => 3],
];
foreach ($posts as $post) {
    $post += ['status' => 1, 'remark' => '', 'updated_at' => $now];
    $exists = Db::table('sys_posts')->where('code', $post['code'])->first();
    $exists
        ? Db::table('sys_posts')->where('id', $exists->id)->update($post)
        : Db::table('sys_posts')->insert($post + ['created_at' => $now]);
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
    'login_type'    => ['登录类型', [['登录', '1', 'primary'], ['登出', '2', 'info']]],
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
];
foreach ($params as $row) {
    [$key, $value, $group, $type, $name] = $row;
    $isSecret = (int) ($row[5] ?? 0);

    $exists = Db::table('sys_params')->where('param_key', $key)->first();
    if ($exists) {
        // 只补齐元信息，不覆盖已改过的值
        Db::table('sys_params')->where('id', $exists->id)->update([
            'name' => $name, 'group' => $group, 'value_type' => $type,
            'is_builtin' => 1, 'is_secret' => $isSecret, 'updated_at' => $now,
        ]);
        continue;
    }
    Db::table('sys_params')->insert([
        'group' => $group, 'name' => $name, 'param_key' => $key, 'param_value' => $value,
        'value_type' => $type, 'is_builtin' => 1, 'is_secret' => $isSecret, 'remark' => '',
        'created_at' => $now, 'updated_at' => $now,
    ]);
}
echo '  ✓ 系统参数 ' . count($params) . " 项\n";

// ─────────────────────────────────────────── 演示账号
if ($withDemo) {
    $demoPassword = 'demo123456';
    $demoUsers = [
        ['username' => 'manager', 'real_name' => '王强', 'dept_id' => 2, 'post_id' => 1, 'role' => 'ROLE_DEPT_MGR', 'phone' => '13800138001'],
        ['username' => 'dev01',   'real_name' => '李娜', 'dept_id' => 2, 'post_id' => 2, 'role' => 'ROLE_STAFF',    'phone' => '13800138002'],
        ['username' => 'ops01',   'real_name' => '赵敏', 'dept_id' => 3, 'post_id' => 3, 'role' => 'ROLE_STAFF',    'phone' => '13800138003'],
    ];

    $created = 0;
    foreach ($demoUsers as $demo) {
        if (Db::table('sys_users')->where('username', $demo['username'])->exists()) {
            continue;
        }
        $role = Db::table('sys_roles')->where('code', $demo['role'])->first();

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
