<?php
/**
 * keel admin
 * 通讯录
 *
 * **全员可见的组织架构只读视图**，与「用户管理」是两回事：
 *
 * |            | 用户管理 `/admin/users`        | 通讯录 `/admin/contacts` |
 * |------------|-------------------------------|--------------------------|
 * | 授权       | `sys:user:*`（管理能力）       | `contact:view`（全员）    |
 * | 数据范围   | 受数据权限约束（只看管得到的） | **全公司**               |
 * | 能做什么   | 增删改、分配角色、重置密码     | 只读                      |
 * | 谁在用     | 系统管理员、部门主管           | 所有人                    |
 *
 * 不复用 `UserService` 正是因为授权面完全不同——普通员工没有 `sys:user:list`，
 * 却必须能查到同事的分机号。这与聊天不复用 `/admin/users` 是同一条理由。
 *
 * ## 两条容易混淆的边界
 *
 * 1. **数据范围放开，不等于字段放开。** 通讯录让所有人看到全公司的人，
 *    但手机号、邮箱仍然受字段级权限管（`sys:field:user:phone` / `:email`），
 *    没授权的看到的是掩码。「能看到这个人」和「能看到他的手机号」是两件事
 * 2. **只收在职员工**（`status=1`）。停用的账号不该出现在通讯录里——
 *    但历史聊天记录里仍然读得出他是谁（消息行冗余了 sender_name）
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\service;

use app\common\exception\NotFoundException;
use app\common\model\SysDeptModel;
use app\common\model\SysUserModel;
use app\common\model\scope\DataScope;
use app\common\support\Arr;
use app\common\support\Ctx;

class ContactService
{
    /** 敏感字段 → 控制其可见性的权限点。与 UserService 用的是同一批权限点 */
    private const SENSITIVE_FIELDS = [
        'phone' => 'sys:field:user:phone',
        'email' => 'sys:field:user:email',
    ];

    /** 单页上限。通讯录是浏览场景，一次给太多既慢又没人往下翻 */
    private const MAX_PAGE_SIZE = 100;

    /**
     * 部门树
     *
     * ⚠️ **必须 `withoutDataScope()`**。`SysDeptModel` 挂着数据权限，
     * 不绕开的话部门主管只看得到自己那一枝，通讯录就成了「部门通讯录」——
     * 而它存在的意义恰恰是让人找到**别的**部门的同事。
     *
     * 只返回启用的部门：停用的部门不该出现在浏览入口里，
     * 但它底下的人如果还在职，仍然搜得到（搜索走的是人不是树）。
     */
    public static function deptTree(): array
    {
        $rows = SysDeptModel::withoutDataScope()
            ->where('status', 1)
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['id', 'parent_id', 'name']);

        $byParent = [];
        foreach ($rows as $r) {
            $byParent[(int) $r->parent_id][] = [
                'id'   => (int) $r->id,
                'name' => (string) $r->name,
            ];
        }

        return self::buildTree($byParent, 0);
    }

    /** 递归拼树。空树返回 `[]` 而不是 null（api.md §1.4） */
    private static function buildTree(array $byParent, int $parentId): array
    {
        $nodes = [];

        foreach ($byParent[$parentId] ?? [] as $node) {
            $children = self::buildTree($byParent, $node['id']);
            // 叶子节点也给 children: []，前端不用判两种形态
            $node['children'] = $children;
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * 人员列表
     *
     * `dept_id` 含子部门：点「技术部」要看到它下面所有小组的人，
     * 而不是只看到挂在技术部这一级的。用 `ancestors` 前缀匹配实现——
     * 部门树本来就维护着祖级路径，比递归查子孙便宜得多。
     *
     * @param array{dept_id?: int, include_children?: bool, keyword?: string, page_num?: int, page_size?: int} $params
     */
    public static function list(array $params): array
    {
        $pageNum  = max(1, (int) ($params['page_num'] ?? 1));
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, (int) ($params['page_size'] ?? 20)));
        $deptId   = (int) ($params['dept_id'] ?? 0);
        $keyword  = trim((string) ($params['keyword'] ?? ''));

        // 见类注释：通讯录是全公司可见的，数据权限在这里必须绕开
        $q = SysUserModel::withoutDataScope()->where('status', 1);

        if ($deptId > 0) {
            /*
             * 两种口径，调用方自己选
             *
             * - 含子部门（默认）：平铺列表用。点「技术部」要看到底下所有小组的人
             * - 仅本级：**树形展示用**。树里子部门是独立节点，父节点再把子部门的人
             *   算进来，同一个人会在两个地方各出现一次
             */
            $ids = ($params['include_children'] ?? true)
                ? self::deptWithDescendants($deptId)
                : [$deptId];
            $q->whereIn('dept_id', $ids);
        }

        if ($keyword !== '') {
            $q->where(function ($w) use ($keyword) {
                $w->where('real_name', 'like', "%{$keyword}%")
                  ->orWhere('username', 'like', "%{$keyword}%");
            });
        }

        $total = (clone $q)->count();

        $rows = $q->with(self::relations())
            ->orderBy('dept_id')
            ->orderBy('id')
            ->forPage($pageNum, $pageSize)
            ->get();

        $mapper = self::rowMapper();

        return [
            'list'      => $rows->map($mapper)->all(),
            'total'     => $total,
            'page_num'  => $pageNum,
            'page_size' => $pageSize,
        ];
    }

    /**
     * 某个部门及其全部子孙的 id
     *
     * 靠 `ancestors`（祖级路径，形如 `0,1,3`）前缀匹配，不递归查表。
     * 匹配时要带上逗号（`%,{id},%` 与结尾 `%,{id}`），否则 `1` 会匹配到 `11`、`21`。
     */
    private static function deptWithDescendants(int $deptId): array
    {
        $ids = SysDeptModel::withoutDataScope()
            ->where(function ($w) use ($deptId) {
                $w->where('id', $deptId)
                  ->orWhere('ancestors', 'like', "%,{$deptId},%")
                  ->orWhere('ancestors', 'like', "%,{$deptId}");
            })
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();

        // 兜底：部门不存在时返回一个不可能命中的值，而不是空数组——
        // whereIn([]) 在某些版本里会被优化成「无条件」，那就成了列出全公司
        return $ids ?: [-1];
    }

    /** 人员详情。不存在或已停用都按 404 处理，不区分——都是「通讯录里没这个人」 */
    public static function detail(int $id): array
    {
        $user = SysUserModel::withoutDataScope()
            ->with(self::relations())
            ->where('status', 1)
            ->find($id);

        if (!$user) {
            throw new NotFoundException();
        }

        return (self::rowMapper())($user);
    }

    /**
     * 部门、岗位的预加载**也要绕开数据权限**
     *
     * ⚠️ 只给主查询加 withoutDataScope() 不够：部门表与岗位表自己也接了数据权限，
     * 预加载时各自再套一次。普通员工（数据范围 = 本部门）查到别的部门的同事，
     * 关联被过滤成 null，部门名就是空的——人找得到，却不知道他在哪个部门，
     * 而那恰恰是通讯录最该告诉你的事
     */
    private static function relations(): array
    {
        $unscoped = static fn ($q) => $q->withoutGlobalScope(DataScope::class);

        return ['dept' => $unscoped, 'post' => $unscoped];
    }

    /**
     * 行映射
     *
     * 字段级权限在这里生效：有权限给明文，没有给掩码。
     * **不是「没权限就不返回这个字段」**——前端要能稳定地渲染一列，
     * 时有时无会让表格结构跳变，而掩码本身也传达了「这里有值但你看不到」。
     */
    private static function rowMapper(): callable
    {
        $user    = Ctx::user() ?? [];
        $allowed = [];

        foreach (self::SENSITIVE_FIELDS as $field => $permCode) {
            $allowed[$field] = PermissionService::has($user, $permCode);
        }

        return static fn (SysUserModel $row): array => [
            'id'        => (int) $row->id,
            'username'  => (string) $row->username,
            'real_name' => (string) ($row->real_name ?: $row->username),
            'avatar'    => (string) $row->avatar,
            'phone'     => $allowed['phone'] ? (string) $row->phone : Arr::mask((string) $row->phone),
            'email'     => $allowed['email'] ? (string) $row->email : Arr::maskEmail((string) $row->email),
            'dept_id'   => (int) $row->dept_id,
            'dept_name' => (string) ($row->dept?->name ?? ''),
            'post_name' => (string) ($row->post?->name ?? ''),
            // 通讯录不返回 status（只收在职的，返回了也永远是 1）、
            // 不返回 is_super（那是权限信息，与「找人」无关）、
            // 不返回 last_login_at（同事什么时候登录过不关你的事）
        ];
    }
}
