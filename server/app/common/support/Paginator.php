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
     * @param  string[]  $sortable   允许排序的数据库字段名白名单
     * @param  callable|null  $map   逐行映射，不传则用模型的 toArray()
     * @param  callable|null  $mapPage  整页映射：拿到整个 Collection、返回 list<array>
     *
     * `$mapPage` 与 `$map` 二选一（同时给以 `$mapPage` 为准），它存在的唯一理由是
     * **批量预取**。逐行映射器天生看不到同页的其他行，于是「每行再查一次」的写法
     * 会被分页伪装成没问题——一页 20 行、每行 4 次关联查询就是 80 条 SQL，
     * 而分页参数越小越看不出来。字典项列表的引用数就是这么来的（见 DictService）。
     *
     * 需要按行做点缀（格式化时间、拼字符串）时仍然用 `$map`，那是纯内存操作。
     */
    public static function response(
        Builder $query,
        Request $request,
        array $sortable = ['id'],
        string $defaultField = 'id',
        string $defaultOrder = 'desc',
        ?callable $map = null,
        ?callable $mapPage = null,
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

        $list = [];
        if ($total > 0) {
            $rows = $query->orderBy($field, $order)
                ->forPage($pageNum, $pageSize)
                ->get();

            $list = $mapPage !== null
                ? array_values($mapPage($rows))
                : $rows->map($map ?? fn ($row) => $row->toArray())->all();
        }

        return Result::page($list, $total, $pageNum, $pageSize);
    }
}
