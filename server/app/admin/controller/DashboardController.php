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
    /**
     * 概览数据
     *
     * `GET /admin/dashboard/overview` · 权限点 `sys:dashboard:view`
     *
     * 一次返回首页要的全部区块：指标卡、近 7 天登录趋势、运行状态、最近操作、模块统计。
     * 拆成多个接口会让首页发五六个请求，而这些数字本来就该是同一时刻的快照。
     *
     * 其中的用户数、部门数等**受数据权限约束**——部门主管看到的是他管得到的那部分，
     * 不是全公司。归属过滤由模型全局 Scope 注入，service 里没有手写条件。
     *
     * @param Request $request 无参数
     *
     * @return Response 200，`{stats, login_trend, runtime, recent_logs, modules}`
     */
    public function overview(Request $request): Response
    {
        return Result::ok(DashboardService::overview());
    }
}
