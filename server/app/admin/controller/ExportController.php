<?php
/**
 * keel admin
 * 数据导出
 *
 * 这里只管「任务」本身：看列表、下载、删除。**发起导出不在这里**——
 * 那是各业务模块自己的接口（`/admin/users/export` 等），因为「谁能导出用户」
 * 由 `sys:user:export` 决定，而这个权限点属于用户模块。
 * 做成一个通用的 `POST /admin/exports?biz=user` 反而会把 fail-closed 打穿：
 * 路由上只能声明一个权限点，而它要覆盖的是 N 种业务各自的导出权限。
 *
 * 看得到哪些任务由数据权限决定（`SysExportTaskModel` 的归属人列是 `creator_id`）：
 * 「仅本人」范围只看得到自己发起的，部门主管看得到本部门的。
 *
 * 本模块通用：权限点声明在 `config/route.php`，不写即 403（fail-closed）；
 * 数据范围外的记录返回 404 而非 403。错误码表见 docs/api.md §2.2。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\validation\Export\ListRequest;
use app\common\service\ExportService;
use app\common\support\OpLog;
use app\common\support\Paginator;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

class ExportController
{
    /**
     * 导出任务列表（分页）
     * @url GET /admin/exports
     * @perm sys:export:list
     * @description 默认按创建时间倒序。每行带 `downloadable`——能不能下载由服务端算，
     * 因为文件会被回收而 `status` 仍是「已完成」，前端只看状态会给出一个点了报错的按钮。
     */
    public function index(ListRequest $request): Response
    {
        return Paginator::response(
            ExportService::listQuery($request->validated()),
            $request->request(),
            sortable: ExportService::SORTABLE,
            defaultField: 'created_at',
            defaultOrder: 'desc',
            map: ExportService::rowMapper(),
        );
    }

    /**
     * 下载导出文件
     * @url GET /admin/exports/{id}/download
     * @perm sys:export:list
     * @description 权限与列表同一个：看得见就下得了——看得见本身已经过了数据权限。
     * @error 404 任务不存在，或不在你的数据范围内
     * @error 410 `20702` 文件已过期或被回收，任务记录还在
     */
    public function download(Request $request, int $id): Response
    {
        $file = ExportService::download($id);

        // 下载不落操作日志：它是读操作，且一份导出可能被下载很多次，
        // 记下来会把真正的写操作淹掉。「谁导出了什么」在发起那一刻已经记过
        return Result::download($file['path'], $file['name']);
    }

    /**
     * 删除导出任务
     * @url DELETE /admin/exports/{id}
     * @perm sys:export:delete
     * @description 文件与记录一起删。先删文件再删记录——反过来的话记录没了，
     * 文件就成了没人认领的垃圾，只能等回收周期。
     */
    public function destroy(Request $request, int $id): Response
    {
        ExportService::delete($id);

        return Result::noContent();
    }
}
