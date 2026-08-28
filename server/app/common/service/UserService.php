<?php
/**
 * keel admin
 * 用户（RBAC 的分配层）
 *
 * ⚠️ 这里没有任何 `where dept_id in (...)`——数据权限由 SysUserModel 的全局 Scope 注入。
 * 想验证效果：换个部门主管账号调同一个接口，返回的行数会自己变少。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\service;

use app\common\constant\BizCode;
use app\common\exception\BusinessException;
use app\common\exception\ForbiddenException;
use app\common\exception\ValidationException;
use app\common\model\SysDeptModel;
use app\common\model\SysPostModel;
use app\common\model\SysUserModel;
use app\common\support\Arr;
use app\common\support\BatchResult;
use app\common\support\Ctx;
use app\common\support\Db;
use app\common\support\Guard;
use app\common\support\OpLog;
use app\common\support\Spreadsheet;
use Illuminate\Database\Eloquent\Builder;

class UserService
{
    /** 列表可排序字段白名单（数据库字段名） */
    public const SORTABLE = ['id', 'username', 'status', 'last_login_at', 'created_at'];

    /** 敏感字段 → 控制其可见性的权限点 */
    private const SENSITIVE_FIELDS = [
        'phone' => 'sys:field:user:phone',
        'email' => 'sys:field:user:email',
    ];

    /**
     * 归属数据检查登记表
     *
     * 停用一个账号前要确认他名下没有还在流转的数据，否则那些数据会变成无人认领。
     * 业务模块把自己的归属关系登记到这里——一处登记，停用检查自动覆盖，
     * 比在每个业务模块里各写一遍「记得检查用户停用」可靠得多。
     *
     * 格式：[模型类, 归属列, 展示名]
     */
    private const OWNERSHIP_CHECKS = [
        [SysDeptModel::class, 'leader_id', '部门负责人'],
        // 业务表示例：[OrderModel::class, 'owner_id', '进行中的订单'],
    ];

    /** 生成初始密码时用的字符集，去掉了 0/O/1/l/I 这些电话里念不清的 */
    private const PASSWORD_CHARS = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

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
     * 字段级权限在服务端落实：无权限的字段返回的就是脱敏值，
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

    // ================================================================ 详情

    public static function detail(int $id): array
    {
        /** @var SysUserModel $user */
        $user = Guard::found(SysUserModel::find($id));

        return $user->toArray() + [
            'role_ids' => $user->roles()->pluck('sys_roles.id')->map(fn ($v) => (int) $v)->all(),
        ];
    }

    // ================================================================ 增删改

    /**
     * 新建用户
     *
     * 不传密码则自动生成一个并只在这次响应里返回——管理员当面交给本人。
     * 明文密码不入库、不进日志（操作日志中间件按 key 名把 password 脱敏了）。
     */
    public static function create(array $data, array $roleIds = []): array
    {
        Guard::unique(SysUserModel::class, 'username', $data['username'], null, '账号已存在', BizCode::ACCOUNT_EXISTS);
        Guard::inDeptScope((int) ($data['dept_id'] ?? 0));

        $plain = (string) ($data['password'] ?? '');
        if ($plain === '') {
            $plain = self::randomPassword();
        }
        self::assertPasswordStrength($plain);

        if ($roleIds) {
            RoleService::assertAssignable(0, $roleIds);
        }

        return Db::transaction(function () use ($data, $roleIds, $plain) {
            $user = new SysUserModel();
            $user->fill(Arr::only($data, [
                'username', 'real_name', 'phone', 'email', 'dept_id', 'post_id', 'status', 'remark',
            ]));
            $user->password       = password_hash($plain, PASSWORD_DEFAULT);
            $user->pwd_updated_at = null;   // 留空 → 首次登录提示改密
            $user->is_super       = false;  // 超管只能由初始化脚本产生，不允许界面授予
            $user->save();

            self::syncRoles($user, $roleIds);

            OpLog::target("用户 {$user->username}({$user->id})");

            return $user->toArray() + ['initial_password' => $plain];
        });
    }

    public static function update(int $id, array $data, ?array $roleIds = null): SysUserModel
    {
        $user = self::findEditable($id);

        Guard::unique(SysUserModel::class, 'username', $data['username'], $id, '账号已存在', BizCode::ACCOUNT_EXISTS);

        // 踢出范围外由新值那一判拦住；旧值这一判防的是读写范围哪天不再相等
        Guard::inDeptScope((int) ($data['dept_id'] ?? $user->dept_id), (int) $user->dept_id);

        if ($roleIds !== null) {
            RoleService::assertAssignable($id, $roleIds);
        }

        $before = $user->toArray();

        return Db::transaction(function () use ($user, $data, $roleIds, $before, $id) {
            $user->fill(Arr::only($data, [
                'username', 'real_name', 'phone', 'email', 'dept_id', 'post_id', 'status', 'remark',
            ]));
            $user->save();

            if ($roleIds !== null) {
                self::syncRoles($user, $roleIds);
            }

            OpLog::target("用户 {$user->username}({$id})");
            OpLog::diff($before, $user->toArray());

            return $user;
        });
    }

    public static function delete(int $id): void
    {
        $user = self::findEditable($id);

        if ($user->id === Ctx::userId()) {
            throw new BusinessException('不能删除自己的账号', BizCode::CANNOT_OPERATE_SELF);
        }

        self::assertNoPendingHandover($user);

        OpLog::target("用户 {$user->username}({$id})");

        Db::transaction(function () use ($user, $id) {
            Db::table('sys_user_roles')->where('user_id', $id)->delete();
            $user->delete();   // 软删，账号记录保留以便日志追溯
        });
    }

    /** 启用 / 停用 */
    public static function setStatus(int $id, int $status): void
    {
        $user = self::findEditable($id);

        if ($user->id === Ctx::userId() && $status === SysUserModel::STATUS_DISABLED) {
            throw new BusinessException('不能停用自己的账号', BizCode::CANNOT_OPERATE_SELF);
        }

        if ($status === SysUserModel::STATUS_DISABLED) {
            self::assertNoPendingHandover($user);
        }

        $before = $user->status;

        $user->status = $status;
        $user->save();

        OpLog::target("用户 {$user->username}({$id})");
        OpLog::diff(['status' => $before], ['status' => $status]);
    }

    /** 重置密码；不传则生成一个，返回明文供管理员转交 */
    public static function resetPassword(int $id, string $plain = ''): string
    {
        $user = self::findEditable($id);

        if ($plain === '') {
            $plain = self::randomPassword();
        }
        self::assertPasswordStrength($plain);

        $user->password       = password_hash($plain, PASSWORD_DEFAULT);
        $user->pwd_updated_at = null;   // 强制本人下次登录时修改

        // 作废该用户的全部会话。这是最需要立刻生效的场景——管理员重置密码
        // 通常意味着账号疑似泄露，此时旧 refresh 还能用 7 天是不可接受的。
        //
        // ⚠️ 不能拿 pwd_updated_at 当判据：它在这里被置为 null（兼作「必须改密」标志），
        // 参与时间比较毫无意义，所以才单独有 token_version 这一列
        $user->token_version = (int) $user->token_version + 1;
        $user->save();

        OpLog::target("用户 {$user->username}({$id})");

        return $plain;
    }

    public static function grantRoles(int $id, array $roleIds): void
    {
        $user = self::findEditable($id);

        RoleService::assertAssignable($id, $roleIds);

        Db::transaction(function () use ($user, $roleIds, $id) {
            self::syncRoles($user, $roleIds);
            OpLog::target("用户 {$user->username}({$id})");
            OpLog::changes([['field' => 'roles', 'old' => '', 'new' => implode(',', $roleIds)]]);
        });
    }

    // ================================================================ 导入导出

    /** 导入模板的列名，导入与导出共用同一套，避免导出的文件改一改导不回去 */
    public const IMPORT_HEADERS = ['账号', '姓名', '手机号', '邮箱', '部门编码', '岗位编码', '备注'];

    /**
     * 导出
     *
     * 必须 chunk 分批：一次 `get()` 全表在常驻进程里就是内存炸弹，
     * 而且这条红线在 CLAUDE.md 里写着（大数据量查询用 chunk）。
     */
    public static function export(array $filters): string
    {
        $limit = (int) ParamService::value('sys.export.maxRows', 50000);
        $query = self::listQuery($filters);

        if ($query->toBase()->getCountForPagination() > $limit) {
            throw new BusinessException("导出数据量超过上限 {$limit} 行，请缩小筛选范围", BizCode::EXPORT_LIMIT_EXCEEDED);
        }

        $mapper = self::rowMapper();

        return Spreadsheet::writeXlsx('users', [
            'ID', '账号', '姓名', '手机号', '邮箱', '部门', '岗位', '状态', '最后登录', '创建时间',
        ], function (callable $emit) use ($query, $mapper) {
            $query->orderBy('id')->chunk(500, function ($rows) use ($emit, $mapper) {
                foreach ($rows as $row) {
                    $item = $mapper($row);
                    $emit([
                        $item['id'],
                        $item['username'],
                        $item['real_name'],
                        // 导出同样走脱敏：没有字段权限的人导出来也该是掩码，
                        // 否则「界面看不到就导出来看」会变成标准绕过手法
                        $item['phone'],
                        $item['email'],
                        $item['dept_name'],
                        $item['post_name'],
                        (int) $item['status'] === SysUserModel::STATUS_ENABLED ? '启用' : '停用',
                        $item['last_login_at'] ?? '',
                        $item['created_at'] ?? '',
                    ]);
                }
            });
        });
    }

    /**
     * 导入
     *
     * 逐行尽力执行：一行格式错不该让另外九十九行也白导。
     * 失败明细带上行号，用户才知道回去改哪一行。
     */
    public static function import(string $path): array
    {
        // withoutDataScope()：这里取全部部门/岗位，只为把「编码 → id」翻译出来，
        // 落到哪个部门由下面每一行的 Guard::inDeptScope() 判。分开做才能让
        // 「部门编码不存在」与「部门超出你的范围」给出不同的行内提示
        $deptIds = SysDeptModel::withoutDataScope()->pluck('id', 'code');
        $postIds = SysPostModel::withoutDataScope()->pluck('id', 'code');

        $result = BatchResult::make();

        Spreadsheet::eachRow($path, function (array $row, int $number) use ($result, $deptIds, $postIds) {
            $username = trim((string) ($row['账号'] ?? ''));

            try {
                if ($username === '') {
                    throw new BusinessException('账号不能为空');
                }

                $deptCode = trim((string) ($row['部门编码'] ?? ''));
                $postCode = trim((string) ($row['岗位编码'] ?? ''));

                if ($deptCode !== '' && !isset($deptIds[$deptCode])) {
                    throw new BusinessException("部门编码「{$deptCode}」不存在");
                }
                if ($postCode !== '' && !isset($postIds[$postCode])) {
                    throw new BusinessException("岗位编码「{$postCode}」不存在");
                }

                // create() 里也有同样一判，这里提前判是为了把部门编码写进提示：
                // 用户手上是 Excel，只说「超出数据范围」他不知道改哪一列
                Guard::inDeptScope(
                    (int) ($deptIds[$deptCode] ?? 0),
                    message: $deptCode === ''
                        ? '未填部门编码，而你没有创建无部门用户的权限'
                        : "部门编码「{$deptCode}」超出你的数据范围",
                );

                self::create([
                    'username'  => $username,
                    'real_name' => (string) ($row['姓名'] ?? ''),
                    'phone'     => (string) ($row['手机号'] ?? ''),
                    'email'     => (string) ($row['邮箱'] ?? ''),
                    'dept_id'   => (int) ($deptIds[$deptCode] ?? 0),
                    'post_id'   => (int) ($postIds[$postCode] ?? 0),
                    'status'    => 1,
                    'remark'    => (string) ($row['备注'] ?? ''),
                ]);

                $result->ok($number);
            } catch (\Throwable $e) {
                // 导入用行号而不是 id 标识失败项——用户手上只有那个 Excel
                $result->fail($number, "第 {$number} 行「{$username}」：" . $e->getMessage());
            }
        });

        return $result->toArray();
    }

    /** 导入模板：只有表头和一行示例 */
    public static function importTemplate(): string
    {
        return Spreadsheet::writeXlsx('users_template', self::IMPORT_HEADERS, function (callable $emit) {
            $emit(['zhangsan', '张三', '13800138000', 'zhangsan@example.com', 'DEPT-0002', 'POST-0002', '示例行，导入前请删除']);
        });
    }

    // ================================================================ 内部

    /**
     * 取出可编辑的用户
     *
     * 超级管理员是权限体系的最后一道保险：它跳过一切校验，
     * 一旦能被界面改角色、停用或删除，就可能出现「所有人都进不去」的死局。
     * 所以只能在数据库或初始化脚本里操作（docs/database.md §3.1）。
     */
    private static function findEditable(int $id): SysUserModel
    {
        /** @var SysUserModel $user */
        $user = Guard::found(SysUserModel::find($id));

        if ($user->is_super) {
            throw new ForbiddenException('不允许操作超级管理员', BizCode::SUPER_ADMIN_PROTECTED);
        }

        return $user;
    }

    /** 停用/删除前确认名下没有还在流转的数据 */
    private static function assertNoPendingHandover(SysUserModel $user): void
    {
        $pending = [];

        foreach (self::OWNERSHIP_CHECKS as [$modelClass, $column, $label]) {
            $count = $modelClass::query()
                ->withoutGlobalScopes()   // 交接检查要看全量，不能受操作者的数据权限影响
                ->where($column, $user->id)
                ->count();

            if ($count > 0) {
                $pending[] = "{$label} {$count} 项";
            }
        }

        if ($pending) {
            throw new BusinessException(
                '请先完成数据交接：' . implode('、', $pending), BizCode::DATA_HANDOVER_REQUIRED);
        }
    }

    private static function syncRoles(SysUserModel $user, array $roleIds): void
    {
        Db::table('sys_user_roles')->where('user_id', $user->id)->delete();

        foreach (array_unique(array_map('intval', $roleIds)) as $roleId) {
            Db::table('sys_user_roles')->insertOrIgnore(['user_id' => $user->id, 'role_id' => $roleId]);
        }

        // 授权变了，让该用户的权限缓存失效，下一个请求就按新角色判定
        PermissionService::bumpUsers([$user->id]);
    }

    private static function assertPasswordStrength(string $plain): void
    {
        $min = (int) ParamService::value('sys.pwd.minLength', 8);

        if (mb_strlen($plain) < $min) {
            throw new ValidationException(
                ['password' => ["密码长度不能少于 {$min} 位"]],
                '密码不符合安全策略', BizCode::PASSWORD_POLICY_VIOLATION);
        }
    }

    private static function randomPassword(int $length = 12): string
    {
        $max = strlen(self::PASSWORD_CHARS) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= self::PASSWORD_CHARS[random_int(0, $max)];
        }

        return $out;
    }
}
