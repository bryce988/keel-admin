<?php
/**
 * keel admin
 * 菜单与权限点
 *
 * 本模块只定义权限点，不做授权——把权限给谁在角色管理里做。
 * 三层职责分离：定义（本模块）→ 授权（角色）→ 分配（用户）。
 *
 * 本模块通用，各方法不再重复：权限点声明在 `config/route.php`，不写即 403（fail-closed）；
 * 入参校验见 `app\admin\validation\Menu\*`，失败一律 422 + 字段级 `details`；
 * 写操作自动落操作日志。错误码表见 docs/api.md §2.2。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\validation\Menu\StoreRequest;
use app\admin\validation\Menu\TreeRequest;
use app\admin\validation\Menu\UpdateRequest;
use app\admin\service\MenuService;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

class MenuController
{
    /**
     * 权限点树
     * @url GET /admin/menus/tree
     * @perm sys:menu:list
     * @description 返回全部五种类型的节点（目录/菜单/按钮/接口/字段），不分页，子节点在 `children`。
     * 跟登录时下发的菜单不是一回事：那个只含 `type IN (1,2)` 且当前用户有权的节点，
     * 由 `GET /admin/auth/profile` 返回；这里是给管理员维护用的完整字典。
     */
    public function tree(TreeRequest $request): Response
    {
        $filters = $request->validated();

        return Result::ok(MenuService::tree($filters));
    }

    /**
     * 权限点详情
     * @url GET /admin/menus/{id}
     * @perm sys:menu:list
     */
    public function show(Request $request, int $id): Response
    {
        return Result::ok(MenuService::detail($id));
    }

    /**
     * 新增权限点
     * @url POST /admin/menus
     * @perm sys:menu:create
     * @description ⚠️ 新增权限点默认不授予任何角色。只建节点不去角色里勾选，
     * 对应的接口对所有人都是 403（fail-closed）。
     * @error 409 `20401` 权限标识已存在 · 404 `10404` 上级节点不存在
     */
    public function store(StoreRequest $request): Response
    {
        return Result::created(MenuService::create($request->validated())->toArray());
    }

    /**
     * 编辑权限点
     * @url PUT /admin/menus/{id}
     * @perm sys:menu:update
     * @description 改动会顶所有用户的 `perm_version`，让 Redis 里的权限缓存失效——
     * 改完不用重新登录，下一次请求就是新权限。
     * @error 409 `20401` 权限标识已被占用 · 400 `20403` 上级是自己或自己的子节点
     */
    public function update(UpdateRequest $request, int $id): Response
    {
        return Result::ok(MenuService::update($id, $request->validated())->toArray());
    }

    /**
     * 删除权限点
     * @url DELETE /admin/menus/{id}
     * @perm sys:menu:delete
     * @description 有子节点或已被任何角色引用都不让删，请改为停用。
     * 直接删掉一个在用的权限点，等于悄悄把一批人的功能关了，而且日志里只有一行删除记录。
     * @error 409 `20402` 已被角色引用 · 409 `20404` 节点下还有子节点
     */
    public function destroy(Request $request, int $id): Response
    {
        MenuService::delete($id);

        return Result::noContent();
    }
}
