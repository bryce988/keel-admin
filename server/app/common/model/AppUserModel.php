<?php
/**
 * keel admin
 * C 端用户（App / 小程序）
 *
 * 与 `SysUserModel` 是两套身份体系，除了都叫「用户」之外没有任何关系：
 * 表不同、令牌 type 不同、中间件不同，也没有角色与数据权限
 * （C 端只做归属校验与功能开关，见 PROJECT.md §8.1）。
 *
 * 类名不叫 `SysAppUserModel`：`Sys*Model` 对应的是 `sys_` 前缀的后台表，
 * 这张表是 `app_users`，跟着表名走才对得上。
 *
 * @property int    $id
 * @property string $phone         手机号，登录账号
 * @property string $password      password_hash 加密
 * @property string $nickname
 * @property string $avatar
 * @property int    $status        0封禁 1正常
 * @property int    $token_version 会话版本号，改密时递增
 * @property string $last_login_at
 * @property string $last_login_ip
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasStatus;

class AppUserModel extends BaseModel
{
    use HasStatus;

    protected $table = 'app_users';

    /** 密码永不出现在任何响应里，序列化阶段就摘掉，不指望每个 mapper 都记得 */
    protected $hidden = ['password'];

    protected $casts = [
        'status'        => 'integer',
        'token_version' => 'integer',
    ];

    /**
     * 这张表没有 creator_id / updater_id
     *
     * C 端用户是自己注册的，「创建人」填谁都不对：填 0 是假数据，
     * 填当前登录者更荒唐——注册时还没有人登录。
     */
    public function auditColumns(): array
    {
        return [];
    }
}
