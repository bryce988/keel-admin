<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use app\common\exception\ValidationException;
use app\common\model\SysLoginLogModel;
use app\common\model\SysUserModel;
use app\common\support\Arr;
use app\common\support\Guard;
use app\common\support\OpLog;
use Illuminate\Database\Eloquent\Builder;

/**
 * 个人中心
 *
 * 与 UserService 最本质的区别：**这里没有「改别人」这条路径**。
 * 所有方法的第一个参数都是当前登录用户 id（由控制器从令牌取），
 * 请求体里的 id 一律不看——越权在结构上就不成立，因此这些接口
 * 也不需要任何权限点（route.php 里 `perm => ''`）。
 *
 * 单独开一个 service 而不是往 UserService 里加方法，就是为了让
 * 「能改自己」和「能改别人」在代码上分家：后者每个入口都要过权限点与
 * 数据权限，前者一个都不需要，混在一起迟早有人把两套规则接错。
 */
class ProfileService
{
    /** 我的登录记录允许排序的字段 */
    public const LOGIN_SORTABLE = ['id', 'created_at'];

    // ---------------------------------------------------------------- 资料

    /**
     * 个人资料
     *
     * 手机号按 §5 的规则脱敏后再返回。看自己的号码也脱敏是刻意的：
     * 界面上本来就不需要看全，而换绑时提交的是新号码，不依赖回显。
     */
    public static function detail(int $userId): array
    {
        /** @var SysUserModel $user */
        $user = Guard::found(SysUserModel::withoutDataScope()->with(['dept', 'post'])->find($userId));

        return [
            'id'             => $user->id,
            'username'       => $user->username,
            'real_name'      => $user->real_name,
            'avatar'         => $user->avatar,
            'phone'          => Arr::mask((string) $user->phone),
            'email'          => $user->email,
            'dept_name'      => $user->dept?->name ?? '',
            'post_name'      => $user->post?->name ?? '',
            'roles'          => $user->roles()->pluck('sys_roles.name')->all(),
            'status'         => $user->status,
            'is_super'       => (bool) $user->is_super,
            'pwd_updated_at' => $user->pwd_updated_at?->format('Y-m-d H:i:s'),
            'last_login_at'  => $user->last_login_at?->format('Y-m-d H:i:s'),
            'last_login_ip'  => $user->last_login_ip,
            'created_at'     => $user->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 修改资料
     *
     * 白名单三个字段。**不要**写成 `$user->fill($data)`：那样前端多传一个
     * `is_super` 或 `dept_id` 就直接提权/换部门了，而这两个字段恰恰是
     * 数据权限的判定依据。能改什么在这里穷举，是这个方法唯一的安全边界。
     */
    public static function update(int $userId, array $data): array
    {
        /** @var SysUserModel $user */
        $user = Guard::found(SysUserModel::withoutDataScope()->find($userId));

        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['email' => ['邮箱格式不正确']]);
        }

        $realName = trim((string) ($data['real_name'] ?? ''));
        if ($realName === '') {
            throw new ValidationException(['real_name' => ['请输入姓名']]);
        }

        OpLog::target("个人资料 {$user->username}");
        OpLog::diff($user->only(['real_name', 'email', 'avatar']), [
            'real_name' => $realName,
            'email'     => $email,
            'avatar'    => (string) ($data['avatar'] ?? $user->avatar),
        ]);

        $user->real_name = $realName;
        $user->email     = $email;
        $user->avatar    = (string) ($data['avatar'] ?? $user->avatar);
        $user->save();

        return self::detail($userId);
    }

    // ---------------------------------------------------------------- 换绑手机

    /**
     * 换绑手机号
     *
     * 用**当前密码**验证身份而不是短信验证码：Keel 不含业务逻辑，
     * 绑死某家短信服务商等于替使用者做选型。要接短信的项目把这里的
     * `password_verify` 换成验证码校验即可，调用方与前端都不用动。
     */
    public static function changePhone(int $userId, string $phone, string $password): void
    {
        /** @var SysUserModel $user */
        $user = Guard::found(SysUserModel::withoutDataScope()->find($userId));

        if (!password_verify($password, (string) $user->password)) {
            // 复用「原密码错误」，与修改密码同一个码：对用户来说是同一件事
            throw new BusinessException('密码错误', 20005);
        }

        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            throw new ValidationException(['phone' => ['手机号格式不正确']]);
        }

        if ($phone === $user->phone) {
            throw new ValidationException(['phone' => ['新手机号与当前号码相同']]);
        }

        /*
         * 手机号没有唯一索引（允许为空、历史数据允许重复），所以只能查一遍。
         * 走 Guard::unique 而不是手写查询：它内部是 withoutGlobalScopes()，
         * 既绕开数据权限（看不见的人一样占着号码）也算上软删记录——
         * 漏掉任何一种，都会出现两个账号绑同一个号，将来接短信登录就撞了。
         */
        Guard::unique(SysUserModel::class, 'phone', $phone, $userId, '手机号已被其他账号使用', 20106);

        OpLog::target("个人资料 {$user->username}");
        // 新旧号码都脱敏后再进日志：操作日志本身是可导出的，
        // 明文写进去等于开了一个绕过字段级权限的口子
        OpLog::diff(
            ['phone' => Arr::mask((string) $user->phone)],
            ['phone' => Arr::mask($phone)]
        );

        $user->phone = $phone;
        $user->save();
    }

    // ---------------------------------------------------------------- 登录记录

    /**
     * 我的登录记录
     *
     * **刻意绕过数据权限**：这是「我自己的」记录，与「我能看多大范围」无关。
     * 不绕的话，数据范围为「仅本人」的账号反而正常，而部门主管会在
     * 个人中心里看到整个部门的登录记录——同一个页面对不同人含义不同，是 bug 不是特性。
     * 绕过之后立刻用 user_id 收紧，范围只会比原来更小。
     */
    public static function loginQuery(int $userId): Builder
    {
        return SysLoginLogModel::withoutDataScope()->where('user_id', $userId);
    }

    public static function loginRowMapper(): callable
    {
        // 个人中心不显示账号列（全是自己），其余沿用日志页的字段
        return fn (SysLoginLogModel $row): array => [
            'id'         => $row->id,
            'ip'         => $row->ip,
            'location'   => $row->location,
            'browser'    => $row->browser,
            'os'         => $row->os,
            'type'       => $row->type,
            'status'     => $row->status,
            'msg'        => $row->msg,
            'created_at' => $row->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
