<?php

declare(strict_types=1);

namespace app\common\model\concern;

use app\common\model\scope\DataScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * 让模型接入数据权限
 *
 * 用法：模型 use 本 trait 即可，需要时覆写归属列名。
 * 只有真正按部门/个人隔离的表才 use——角色、菜单、字典这类全局定义表不接入。
 */
trait HasDataScope
{
    public static function bootHasDataScope(): void
    {
        static::addGlobalScope(new DataScope());
    }

    /** 归属部门列；返回 null 表示该表不按部门隔离 */
    public function deptColumn(): ?string
    {
        return 'dept_id';
    }

    /** 归属人列；返回 null 表示该表没有「仅本人」的概念 */
    public function ownerColumn(): ?string
    {
        return 'owner_id';
    }

    /**
     * 显式绕过数据权限
     *
     * ⚠️ 只允许用于：定时任务、跨部门统计、管理员专用的全量导出。
     * 普通列表接口一律不得调用——绕过的地方都要能在 code review 时被一眼看到。
     */
    public static function withoutDataScope(): Builder
    {
        return static::query()->withoutGlobalScope(DataScope::class);
    }
}
