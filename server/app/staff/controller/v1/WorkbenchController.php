<?php
/**
 * keel admin
 * 工作台（首页）
 *
 * 一个接口把 App 首页要的东西一次性给全：我是谁、我能干什么、概览数字。
 * 后台那边这是三个接口（auth/profile + dashboard/overview），在宽屏上无所谓，
 * 在手机上每多一次往返就多一次转圈——弱网下这是用户能感觉到的差别。
 *
 * 这就是「接口分端」的第一个真实收益：同一批 service，换一种编排。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\staff\controller\v1;

use app\common\service\DashboardService;
use app\common\service\PermissionService;
use app\common\support\Ctx;
use app\common\support\Result;
use app\staff\support\StaffPresenter;
use support\Response;
use Webman\Http\Request;

class WorkbenchController
{
    /**
     * 工作台聚合
     * @url GET /staff/v1/workbench
     * @perm 登录即可
     * @description 返回 `{user, permissions, dashboard:{visible, stats}}`。
     * 概览需要 `sys:dashboard:view`，**没有这个权限点时返回 `visible=false` 而不是 403**：
     * 整个首页不该因为其中一块没权限就整体失败。
     *
     * `visible` 由服务端算，不让客户端拿缓存的权限点自己判断——权限是登录那一刻的快照，
     * 撤权之后客户端还以为自己有，界面上就是一块永远加载失败的区域。
     */
    public function index(Request $request): Response
    {
        $user = Ctx::user() ?? [];
        $canDashboard = PermissionService::has($user, 'sys:dashboard:view');

        return Result::ok(StaffPresenter::identity($user) + [
            'dashboard' => [
                'visible' => $canDashboard,
                'stats'   => $canDashboard ? (DashboardService::overview()['stats'] ?? []) : [],
            ],
        ]);
    }
}
