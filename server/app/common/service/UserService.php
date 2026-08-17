<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\model\SysUserModel;
use app\common\support\Arr;
use app\common\support\Ctx;
use Illuminate\Database\Eloquent\Builder;

/**
 * 用户查询
 *
 * ⚠️ 这里**没有**任何 `where dept_id in (...)`——数据权限由 SysUserModel 的全局 Scope 注入。
 * 想验证效果：换个部门主管账号调同一个接口，返回的行数会自己变少。
 */
class UserService
{
    /** 列表可排序字段白名单（数据库字段名） */
    public const SORTABLE = ['id', 'username', 'status', 'last_login_at', 'created_at'];

    /** 敏感字段 → 控制其可见性的权限点 */
    private const SENSITIVE_FIELDS = [
        'phone' => 'sys:field:user:phone',
        'email' => 'sys:field:user:email',
    ];

    public static function listQuery(array $filters): Builder
    {
        $query = SysUserModel::query()->with(['dept:id,name', 'post:id,name']);

        $query->keyword($filters['keyword'] ?? null);

        if (($filters['status'] ?? '') !== '') {
            $query->where('status', (int) $filters['status']);
        }

        // 按部门筛选时连同下级一起：树上点父节点却只看到父节点的人不符合直觉
        if (!empty($filters['dept_id'])) {
            $query->whereIn('dept_id', DeptService::subtreeIds((int) $filters['dept_id']));
        }

        return $query;
    }

    /**
     * 行映射
     *
     * 字段级权限在**服务端**落实：无权限的字段返回的就是脱敏值，
     * 而不是前端拿到明文再打码——后者用 F12 一看就穿（PROJECT.md §15 验收项）。
     */
    public static function rowMapper(): callable
    {
        $user    = Ctx::user() ?? [];
        $allowed = [];
        foreach (self::SENSITIVE_FIELDS as $field => $permCode) {
            $allowed[$field] = PermissionService::has($user, $permCode);
        }

        return fn (SysUserModel $row): array => [
            'id'            => $row->id,
            'username'      => $row->username,
            'real_name'     => $row->real_name,
            'avatar'        => $row->avatar,
            'phone'         => $allowed['phone'] ? $row->phone : Arr::mask((string) $row->phone),
            'email'         => $allowed['email'] ? $row->email : self::maskEmail((string) $row->email),
            'dept_id'       => $row->dept_id,
            'dept_name'     => $row->dept?->name ?? '',
            'post_name'     => $row->post?->name ?? '',
            'status'        => $row->status,
            'is_super'      => $row->is_super,
            'last_login_at' => $row->last_login_at?->format('Y-m-d H:i:s'),
            'created_at'    => $row->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private static function maskEmail(string $email): string
    {
        if ($email === '' || !str_contains($email, '@')) {
            return $email;
        }
        [$name, $domain] = explode('@', $email, 2);

        return Arr::mask($name, 1, 0) . '@' . $domain;
    }
}
