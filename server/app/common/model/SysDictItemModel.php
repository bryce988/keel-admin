<?php

declare(strict_types=1);

namespace app\common\model;

/**
 * 字典项
 *
 * tag_type 是全站状态色一致性的来源：前端 <DictTag> 直接按它渲染，
 * 页面里不再各写各的颜色判断（CLAUDE.md：不写死颜色、不写死枚举）。
 */
class SysDictItemModel extends BaseModel
{
    protected $table = 'sys_dict_items';

    protected $casts = [
        'sort'   => 'integer',
        'status' => 'integer',
    ];

    public function auditColumns(): array
    {
        return [];
    }
}
