<?php
/**
 * keel admin
 * 一次工具调用的结果
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\ai;

final class ToolResult
{
    /**
     * @param list<array<string, mixed>>  $rows      已经过 presenter（字段权限）的行，AI 脱敏由 ToolRunner 统一再做一道
     * @param int                         $total     真实总数（rows 可能被截断）
     * @param string                      $label     人话描述：「用户列表：技术部及下属，在职」。界面「查询了 N 项」里显示它
     * @param string|null                 $scopeNote 口径：「你的可见范围：技术部及下属部门」。非空时回答里的数字必须带上它
     * @param array{path: string, query?: array<string, scalar>, label?: string}|null $link 「在列表中查看」
     * @param array<string, mixed>        $summary   聚合类工具的汇总结果（分组计数等），与 rows 二选一
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $total,
        public readonly string $label,
        public readonly ?string $scopeNote = null,
        public readonly ?array $link = null,
        public readonly array $summary = [],
    ) {
    }
}
