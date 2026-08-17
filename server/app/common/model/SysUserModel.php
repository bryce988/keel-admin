<?php

declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasDataScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 用户（员工账号）
 *
 * @property int    $id
 * @property string $username
 * @property string $password
 * @property int    $dept_id
 * @property int    $status
 * @property bool   $is_super
 * @property int    $perm_version
 */
class SysUser extends BaseModel
{
    use SoftDeletes;
    use HasDataScope;

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
        return $this->belongsTo(SysDept::class, 'dept_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SysPost::class, 'post_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(SysRole::class, 'sys_user_roles', 'user_id', 'role_id');
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
