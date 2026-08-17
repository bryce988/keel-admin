<?php

declare(strict_types=1);

namespace app\common\model\concern;

use app\common\support\Ctx;
use Illuminate\Database\Eloquent\Model;

/**
 * 审计字段自动填充
 *
 * creator_id / updater_id 由框架写入，业务代码不要手动赋值，
 * 也不允许前端传入（BaseModel 的 $guarded 已挡住）。
 */
trait HasAudit
{
    public static function bootHasAudit(): void
    {
        static::creating(function (Model $model) {
            /** @var self $model */
            $cols = $model->auditColumns();
            $uid  = Ctx::userId();

            if (in_array('creator_id', $cols, true) && empty($model->getAttribute('creator_id'))) {
                $model->setAttribute('creator_id', $uid);
            }
            if (in_array('updater_id', $cols, true)) {
                $model->setAttribute('updater_id', $uid);
            }
        });

        static::updating(function (Model $model) {
            /** @var self $model */
            if (in_array('updater_id', $model->auditColumns(), true)) {
                $model->setAttribute('updater_id', Ctx::userId());
            }
        });
    }

    /** 本表实际拥有的审计列，没有的表覆写为 [] */
    public function auditColumns(): array
    {
        return ['creator_id', 'updater_id'];
    }
}
