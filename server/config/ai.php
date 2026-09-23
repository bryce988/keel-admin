<?php
/**
 * AI 助手「小k」的工具登记表与单价（docs/ai-tech.md §5.2、§3.7）
 *
 * 工具类放 `app/admin/ai/`：它们要调 `app/admin/service/*`，而 `app/common` 禁止 use
 * 任何端的代码，所以用配置接线——与导出登记表 `config/export.php` 同一个模式。
 * 业务模块接入时往 tools 里加一行，AI 层不用改。
 */

return [
    'tools' => [
        app\admin\ai\GetMyProfileTool::class,
        app\admin\ai\SearchContactsTool::class,
        app\admin\ai\SearchUsersTool::class,
        app\admin\ai\CountUsersTool::class,
        app\admin\ai\GetUserTool::class,
        app\admin\ai\ListDeptsTool::class,
        app\admin\ai\ListPostsTool::class,
        app\admin\ai\ListRolesTool::class,
        app\admin\ai\SearchNoticesTool::class,
        app\admin\ai\SearchLoginLogsTool::class,
        app\admin\ai\SearchOperationLogsTool::class,
        app\admin\ai\SystemOverviewTool::class,
    ],

    /*
     * 单价：USD / 1M tokens，[非高峰, 高峰]
     *
     * 2026-09-23 抄自 api-docs.deepseek.com 的价格页，调价或换模型时手改。
     * 高峰：UTC 周一至周五 01:00–04:00、06:00–10:00（北京时间 9–12 点、14–18 点），
     * 中国法定节假日除外——节假日不单独判，按高峰算，宁可高估。
     * 这是估算，对账以 DeepSeek 控制台为准。没登记的模型按 deepseek-flash 估。
     */
    'prices' => [
        'deepseek-flash'  => ['hit' => [0.003, 0.006], 'miss' => [0.15, 0.3],  'out' => [0.6, 1.2]],
        'deepseek-v4-pro' => ['hit' => [0.022, 0.044], 'miss' => [0.66, 1.32], 'out' => [1.98, 3.96]],
    ],
];
