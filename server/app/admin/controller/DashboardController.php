<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\DashboardService;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

/**
 * 系统概览（只读）
 *
 * 这里补上了 `sys:dashboard:view` 的后端落点——在 M2.7 核对权限点时发现，
 * 它是权限树里唯一一个「有菜单、没有任何路由使用」的权限，
 * 也就是说勾不勾它对服务端毫无影响。现在概览接口认它了。
 */
class DashboardController
{
    public function overview(Request $request): Response
    {
        return Result::ok(DashboardService::overview());
    }
}
