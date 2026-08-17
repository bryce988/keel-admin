<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\DeptService;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

/**
 * 部门（只读）
 *
 * 树接口给用户列表的筛选面板用；部门维护属于 M2。
 */
class DeptController
{
    public function tree(Request $request): Response
    {
        return Result::ok(DeptService::tree());
    }
}
