<?php
/**
 * keel admin
 * 模型基类
 *
 * 职责边界（CLAUDE.md 硬性约定）：模型只放字段、关联、Scope，
 * 不写业务流程、不开事务——那些在 service 层。
 *
 * 接口契约用 snake_case，与数据库字段名一致（docs/api.md §1.4），
 * 所以 toArray() 直接输出原始键名，全链路不做键名转换：
 * 同一个字段从数据库到前端只有一个名字，日志、报错、搜代码时不用在两种写法之间换算。
 *
 * 时间统一序列化为 'Y-m-d H:i:s'，不返回 ISO8601。
 *
 * SoftDeletes 不放在这里，只挂在四张主数据表上（用户、部门、角色、岗位）——
 * 它们被日志和历史记录长期引用，硬删会留下查不到人的 id。其余的表加了会出问题：
 *
 * - 两张日志表：LogCleanupService 按保留天数 delete() 回收空间，改成软删就不报错地白删，表永远不缩
 * - sys_permissions：被 sys_role_permissions 引用，软删后关联行指向一个查不到的节点
 * - 字典与参数：Guard::unique() 会把软删的行算进唯一性检查（唯一索引不含 deleted_at），
 *   删掉一个字典项再用同样的 value 新建就会撞 409，而冲突对象用户看不见
 * - 中间表：授权本来就是 delete + insert 整体重建，软删只会积压旧行
 *
 * 要给某张表加软删，三处一起改：模型 use SoftDeletes、schema.sql 建表语句、
 * migrate.php 的 $columnPatches（存量库靠它补列）。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasAudit;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

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
