<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\DictService;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

/**
 * 数据字典（只读）
 *
 * 前端所有下拉与状态标签都从这里取，页面里不写死枚举。
 * 字典维护（增删改）属于 M2 的字典模块。
 */
class DictController
{
    /** 单个字典的选项 */
    public function items(Request $request, string $code): Response
    {
        return Result::ok(DictService::items($code));
    }

    /** 批量预热：/admin/dicts/batch?codes=user_status,enable_status */
    public function batch(Request $request): Response
    {
        $codes = array_filter(array_map('trim', explode(',', (string) $request->get('codes', ''))));

        return Result::ok(DictService::batch($codes));
    }
}
