<?php
/**
 * keel admin
 * 用户（员工账号）—— sys_users
 *
 * RBAC 里的「人」。角色、部门、岗位都挂在这张表上，日志与审计也都指回来，
 * 所以只软删不硬删——硬删会让「三个月前是谁操作的」变成一个查不到的 id。
 *
 * 数据权限的归属人就是自己（见 ownerColumn），「仅本人」范围下只看得到自己那条。
 * 超级管理员由初始化脚本产生，界面上不可授予、不可停用、不可删除。
 *
 * 字段说明以 database/schema.sql 的列注释为准，改表结构时两边一起改。
 *
 * @property int         $id             主键
 * @property string      $username       登录账号，全表唯一
 * @property string      $password       password_hash 加密，$hidden 已挡住不进接口输出
 * @property string      $real_name      姓名
 * @property string      $avatar         头像地址
 * @property string      $phone          手机号，受字段级权限控制（sys:field:user:phone）
 * @property string      $email          邮箱，同样受字段级权限控制
 * @property int         $dept_id        所属部门，0 = 未分配
 * @property int         $post_id        岗位，0 = 未设置
 * @property int         $status         状态：0 停用 · 1 启用（见 HasStatus）
 * @property bool        $is_super       超级管理员，跳过一切权限与数据范围校验
 * @property int         $perm_version   权限版本号，授权变更时递增使令牌里的 pv 失效
 * @property int         $token_version  会话版本号，改密/重置密码时递增使该用户全部令牌失效
 * @property ?Carbon     $pwd_updated_at 密码最后修改时间；null = 强制下次登录改密
 * @property ?Carbon     $last_login_at  最后登录时间
 * @property string      $last_login_ip  最后登录 IP，长度按 IPv6 留的
 * @property string      $remark         备注
 * @property int         $creator_id     创建人，由 HasAudit 自动填
 * @property int         $updater_id     最后修改人，由 HasAudit 自动填
 * @property Carbon      $created_at     创建时间
 * @property Carbon      $updated_at     更新时间
 * @property ?Carbon     $deleted_at     删除时间，null = 未删除
 *
 * @property-read ?SysDeptModel $dept    所属部门
 * @property-read ?SysPostModel $post    岗位
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasDataScope;
use app\common\model\concern\HasStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class SysUserModel extends BaseModel
{
    use SoftDeletes;
    use HasDataScope;
    use HasStatus;

    protected $table = 'sys_users';

    /** 密码永远不进接口输出；需要校验时用 $model->password 直接取属性 */
    protected $hidden = ['password'];

    protected $casts = [
        'dept_id'        => 'integer',
        'post_id'        => 'integer',
        'status'         => 'integer',
        'is_super'       => 'boolean',
        'perm_version'   => 'integer',
        'pwd_updated_at' => 'datetime',
        'last_login_at'  => 'datetime',
    ];

    /** 用户表的「归属人」就是自己 */
    public function ownerColumn(): ?string
    {
        return 'id';
    }

    public function dept(): BelongsTo
    {
        return $this->belongsTo(SysDeptModel::class, 'dept_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SysPostModel::class, 'post_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(SysRoleModel::class, 'sys_user_roles', 'user_id', 'role_id');
    }

    /** 授权变更后递增，使已签发 token 里的 pv 失效（docs/database.md §3.1） */
    public function bumpPermVersion(): void
    {
        $this->newQueryWithoutScopes()->where('id', $this->id)->increment('perm_version');
    }

    public function scopeKeyword($query, ?string $keyword)
    {
        if ($keyword === null || $keyword === '') {
            return $query;
        }

        return $query->where(function ($q) use ($keyword) {
            $q->where('username', 'like', "%{$keyword}%")
                ->orWhere('real_name', 'like', "%{$keyword}%")
                ->orWhere('phone', 'like', "%{$keyword}%");
        });
    }
}
