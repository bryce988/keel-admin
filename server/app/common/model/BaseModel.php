<?php

declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasAudit;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * 模型基类
 *
 * 职责边界（CLAUDE.md 硬性约定）：模型只放**字段、关联、Scope**，
 * 不写业务流程、不开事务——那些在 service 层。
 *
 * 接口契约用 snake_case，与数据库字段名一致（docs/api.md §1.4），
 * 所以 toArray() 直接输出原始键名，**全链路不做键名转换**：
 * 同一个字段从数据库到前端只有一个名字，日志、报错、搜代码时不用在两种写法之间换算。
 *
 * 时间统一序列化为 'Y-m-d H:i:s'，不返回 ISO8601。
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

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
