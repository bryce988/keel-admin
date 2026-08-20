<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\admin\validation\Menu\StoreRequest;
use app\admin\validation\Menu\TreeRequest;
use app\admin\validation\Menu\UpdateRequest;
use app\common\service\MenuService;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

/**
 * 菜单与权限点
 *
 * **本模块只定义权限点，不做授权**。把权限给谁在角色管理里做。
 */
class MenuController
{
    /**
     * 权限点树
     *
     * `GET /admin/menus/tree` · 权限点 `sys:menu:list`
     *
     * 返回**全部五种类型**的节点（目录/菜单/按钮/接口/字段），不分页。
     * 注意这跟登录时下发的菜单不是一回事：那个只含 `type IN (1,2)` 且当前用户有权的节点，
     * 由 `GET /admin/auth/profile` 返回。这里是给管理员维护用的完整字典。
     *
     * @param Request $request 查询参数：`keyword` 名称/标识模糊匹配、
     *                         `type` 1 目录 2 菜单 3 按钮 4 接口 5 字段、`status` 0 停用 1 启用
     *
     * @return Response 200，树形数组，子节点在 `children`
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    public function tree(TreeRequest $request): Response
    {
        $filters = $request->validated();

        return Result::ok(MenuService::tree($filters));
    }

    /**
     * 权限点详情
     *
     * `GET /admin/menus/{id}` · 权限点 `sys:menu:list`
     *
     * @param Request $request 无查询参数
     * @param int     $id      权限点 ID
     *
     * @return Response 200，权限点对象
     *
     * @throws \app\common\exception\NotFoundException   权限点不存在（404 + `10404`）
     */
    public function show(Request $request, int $id): Response
    {
        return Result::ok(MenuService::detail($id));
    }

    /**
     * 新增权限点
     *
     * `POST /admin/menus` · 权限点 `sys:menu:create` · 自动落操作日志
     *
     * ⚠️ 新增权限点**默认不授予任何角色**。只建节点不去角色里勾选，
     * 对应的接口对所有人都是 403（fail-closed，见 CLAUDE.md）。
     *
     * @param StoreRequest $request 请求体见 {@see StoreRequest}
     *
     * @return Response 201，返回新建的权限点对象（含 id）
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\ConflictException  权限标识已存在（409 + `20401`）
     * @throws \app\common\exception\NotFoundException  上级节点不存在（404 + `10404`）
     */
    public function store(StoreRequest $request): Response
    {
        return Result::created(MenuService::create($request->validated())->toArray());
    }

    /**
     * 编辑权限点
     *
     * `PUT /admin/menus/{id}` · 权限点 `sys:menu:update` · 自动落操作日志
     *
     * 改动会顶所有用户的 `perm_version`，让 Redis 里的权限缓存失效——
     * 改完不用重新登录，下一次请求就是新权限。
     *
     * @param UpdateRequest $request 请求体见 {@see UpdateRequest}
     * @param int           $id      权限点 ID
     *
     * @return Response 200，返回更新后的权限点对象
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\NotFoundException   权限点不存在（404 + `10404`）
     * @throws \app\common\exception\ConflictException  权限标识已被其他节点占用（409 + `20401`）
     * @throws \app\common\exception\BusinessException  上级节点是自己或自己的子节点（400 + `20403`）
     */
    public function update(UpdateRequest $request, int $id): Response
    {
        return Result::ok(MenuService::update($id, $request->validated())->toArray());
    }

    /**
     * 删除权限点
     *
     * `DELETE /admin/menus/{id}` · 权限点 `sys:menu:delete` · 自动落操作日志
     *
     * 有子节点或已被任何角色引用都不让删，请改为停用。
     * 直接删掉一个在用的权限点，等于悄悄把一批人的功能关了，而且日志里只有一行删除记录。
     *
     * @param Request $request 无请求体
     * @param int     $id      权限点 ID
     *
     * @return Response 204，无响应体
     *
     * @throws \app\common\exception\NotFoundException   权限点不存在（404 + `10404`）
     * @throws \app\common\exception\ConflictException  存在子节点，或已被角色引用（409 + `20402`）
     */
    public function destroy(Request $request, int $id): Response
    {
        MenuService::delete($id);

        return Result::noContent();
    }
}
