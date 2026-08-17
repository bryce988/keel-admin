<?php

declare(strict_types=1);

namespace app\common\support;

use Illuminate\Database\Eloquent\Builder;
use support\Response;
use Webman\Http\Request;

/**
 * 列表分页
 *
 * 约定见 docs/api.md §1.3：pageSize 默认 20、上限 100，
 * 排序字段走白名单——不做白名单等于允许对任意列做全表排序，
 * 既是注入面也是慢查询的来源。
 */
final class Paginator
{
    public const DEFAULT_SIZE = 20;
    public const MAX_SIZE     = 100;

    /**
     * @param  Builder   $query      已经拼好筛选条件的查询
     * @param  string[]  $sortable   允许排序的**数据库字段名**白名单
     * @param  callable|null  $map   行映射，不传则用模型的 toArray()（camelCase）
     */
    public static function response(
        Builder $query,
        Request $request,
        array $sortable = ['id'],
        string $defaultField = 'id',
        string $defaultOrder = 'desc',
        ?callable $map = null,
    ): Response {
        $pageNum  = max(1, (int) $request->get('pageNum', 1));
        $pageSize = (int) $request->get('pageSize', self::DEFAULT_SIZE);
        $pageSize = min(self::MAX_SIZE, max(1, $pageSize));

        // 前端传 camelCase，白名单存数据库字段名
        $field = Arr::snake((string) $request->get('sortField', ''));
        $order = strtolower((string) $request->get('sortOrder', '')) === 'asc' ? 'asc' : 'desc';

        if (!in_array($field, $sortable, true)) {
            $field = $defaultField;
            $order = $defaultOrder;
        }

        $total = $query->toBase()->getCountForPagination();

        $list = $total === 0
            ? []
            : $query->orderBy($field, $order)
                ->forPage($pageNum, $pageSize)
                ->get()
                ->map($map ?? fn ($row) => $row->toArray())
                ->all();

        return Result::page($list, $total, $pageNum, $pageSize);
    }
}
