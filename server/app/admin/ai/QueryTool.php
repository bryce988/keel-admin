<?php
/**
 * keel admin
 * 后台查询工具的公共部分
 *
 * 只放「怎么把结果说给模型听」的东西（口径、字典翻译、日期区间）。
 * **查询本身一律走 `app/admin/service` 的 listQuery / rowMapper**——
 * 它们挂着数据权限与字段脱敏，这里不写任何过滤。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\ai;

use app\admin\service\DictService;
use app\common\ai\AiTool;
use app\common\model\scope\DataScope;

abstract class QueryTool extends AiTool
{
    /** 列表类工具默认返回的行数（模型要更多时可以传 limit，上限见 ToolBox::MAX_ROWS） */
    protected const DEFAULT_LIMIT = 20;

    /** 受数据权限影响的工具都要带口径，模型按系统提示词把它说给用户听 */
    protected function scopeNote(): ?string
    {
        $scope = DataScope::describe();

        return $scope === null ? null : "你的可见范围：{$scope}";
    }

    /** 字典值 → 文字。模型看到 status=0 不知道是停用还是失败，给它文字 */
    protected function dictLabels(string $code): array
    {
        try {
            $map = [];
            foreach (DictService::items($code) as $item) {
                $map[(string) $item['value']] = (string) $item['label'];
            }

            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    /** 日期区间参数的 schema，日志类工具共用 */
    protected static function dateProps(): array
    {
        return [
            'start_date' => ['type' => 'string', 'description' => '开始日期 YYYY-MM-DD，含当天。不传默认近 7 天'],
            'end_date'   => ['type' => 'string', 'description' => '结束日期 YYYY-MM-DD，含当天。不传默认今天'],
        ];
    }

    protected static function dateRules(): array
    {
        return [
            'start_date' => ['date_format:Y-m-d', '开始日期'],
            'end_date'   => ['date_format:Y-m-d', '结束日期'],
        ];
    }

    protected static function limitProp(): array
    {
        return ['type' => 'integer', 'description' => '最多返回几条，默认 ' . static::DEFAULT_LIMIT . '，最大 50。只需要数量时传 1'];
    }

    protected function limit(array $args): int
    {
        return max(1, min(50, (int) ($args['limit'] ?? static::DEFAULT_LIMIT)));
    }

    /** 列表页 URL 里的筛选参数：去掉空值，数组按 ProTable 的约定拼成逗号串 */
    protected static function linkQuery(array $query): array
    {
        $out = [];
        foreach ($query as $k => $v) {
            if ($v === null || $v === '' || $v === []) {
                continue;
            }
            $out[$k] = is_array($v) ? implode(',', $v) : (string) $v;
        }

        return $out;
    }

    /** 把筛选条件拼成人话，界面「查询了 N 项」里显示 */
    protected static function describeFilters(string $title, array $parts): string
    {
        $parts = array_values(array_filter($parts, static fn ($p) => $p !== null && $p !== ''));

        return $parts ? $title . '：' . implode('，', $parts) : $title;
    }
}
