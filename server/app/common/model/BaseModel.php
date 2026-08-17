<?php

declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasAudit;
use app\common\support\Arr;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * 模型基类
 *
 * 职责边界（CLAUDE.md 硬性约定）：模型只放**字段、关联、Scope**，
 * 不写业务流程、不开事务——那些在 service 层。
 *
 * 两个默认行为值得注意：
 * - toArray() 输出 camelCase，直接符合接口契约；需要数据库原始键名时用 toRaw()
 * - 时间统一序列化为 'Y-m-d H:i:s'，不返回 ISO8601（docs/api.md §1.4）
 */
abstract class BaseModel extends Model
{
    use HasAudit;

    /**
     * 反向白名单：除这些之外都允许批量赋值。
     * 审计字段与主键必须挡住，否则前端可以伪造创建人。
     */
    protected $guarded = ['id', 'creator_id', 'updater_id', 'created_at', 'updated_at', 'deleted_at'];

    protected $dateFormat = 'Y-m-d H:i:s';

    /** 接口输出：camelCase */
    public function toArray(): array
    {
        return Arr::camelKeys(parent::toArray());
    }

    /** 数据库原始键名，用于内部比对（如操作日志的字段级变更） */
    public function toRaw(): array
    {
        return parent::toArray();
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
