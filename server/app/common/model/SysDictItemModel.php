<?php
/**
 * keel admin
 * 字典项 —— sys_dict_items
 *
 * tag_type 是全站状态色一致性的来源：前端 <DictTag> 直接按它渲染，
 * 页面里不再各写各的颜色判断。
 *
 * ⚠️ value 一经使用不可修改——它已经存进了业务表，改了等于把历史数据的含义改掉。
 * 要换含义就新建一项、把旧项停用。
 *
 * 字段说明以 database/schema.sql 的列注释为准，改表结构时两边一起改。
 *
 * @property int    $id         主键
 * @property string $type_code  所属字典编码，对应 sys_dict_types.code
 * @property string $label      显示文案
 * @property string $value      存储值，一经使用不可修改
 * @property string $tag_type   标签颜色：success / warning / danger / primary / info
 * @property int    $sort       排序，值越小越靠前
 * @property int    $status     状态：0 停用 · 1 启用（见 HasStatus）
 * @property string $remark     备注
 * @property Carbon $created_at 创建时间
 * @property Carbon $updated_at 更新时间
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasStatus;
use Illuminate\Support\Carbon;

class SysDictItemModel extends BaseModel
{
    use HasStatus;

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
