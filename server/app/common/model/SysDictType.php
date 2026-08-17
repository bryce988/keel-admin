<?php

declare(strict_types=1);

namespace app\common\model;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** 字典类型 */
class SysDictType extends BaseModel
{
    protected $table = 'sys_dict_types';

    protected $casts = ['status' => 'integer'];

    public function auditColumns(): array
    {
        return [];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SysDictItem::class, 'type_code', 'code');
    }
}
