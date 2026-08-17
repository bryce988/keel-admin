<?php

declare(strict_types=1);

namespace app\common\support;

use Illuminate\Database\Eloquent\Builder;
use support\Response;
use Webman\Http\Request;

/**
 * 列表分页
 *
 * 约定见 docs/api.md §1.3：`page_size` 默认 20、上限 100，
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
        $pageNum  = max(1, (int) $request->get('page_num', 1));
        $pageSize = (int) $request->get('page_size', self::DEFAULT_SIZE);
        $pageSize = min(self::MAX_SIZE, max(1, $pageSize));

        // 入参与白名单都是数据库字段名，不做任何转换
        $field = (string) $request->get('sort_field', '');
        $order = strtolower((string) $request->get('sort_order', '')) === 'asc' ? 'asc' : 'desc';

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
