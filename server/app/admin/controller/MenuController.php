<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\MenuService;
use app\common\support\Result;
use app\common\support\Validator;
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
    public function tree(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'keyword' => ['string|max:64',   '关键词'],
            'type'    => ['in:1,2,3,4,5',    '类型'],
            'status'  => ['in:0,1',          '状态'],
        ])->validated();

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
     * @param Request $request 请求体见 {@see self::validate()}
     *
     * @return Response 201，返回新建的权限点对象（含 id）
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\ConflictException  权限标识已存在（409 + `20401`）
     * @throws \app\common\exception\NotFoundException  上级节点不存在（404 + `10404`）
     */
    public function store(Request $request): Response
    {
        return Result::created(MenuService::create(self::validate($request))->toArray());
    }

    /**
     * 编辑权限点
     *
     * `PUT /admin/menus/{id}` · 权限点 `sys:menu:update` · 自动落操作日志
     *
     * 改动会顶所有用户的 `perm_version`，让 Redis 里的权限缓存失效——
     * 改完不用重新登录，下一次请求就是新权限。
     *
     * @param Request $request 请求体见 {@see self::validate()}
     * @param int     $id      权限点 ID
     *
     * @return Response 200，返回更新后的权限点对象
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\NotFoundException   权限点不存在（404 + `10404`）
     * @throws \app\common\exception\ConflictException  权限标识已被其他节点占用（409 + `20401`）
     * @throws \app\common\exception\BusinessException  上级节点是自己或自己的子节点（400 + `20403`）
     */
    public function update(Request $request, int $id): Response
    {
        return Result::ok(MenuService::update($id, self::validate($request))->toArray());
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

    /**
     * 五种类型共用的入参校验
     *
     * 五种类型的字段要求不同，但校验规则写在一处：不适用的字段由 service 的
     * `normalize()` 清空，这里只保证格式合法。分开写五套规则的话，
     * 「按钮不该有 component」这种约束会散落在五个地方，改一处漏四处。
     *
     * @param Request $request 请求体：`parent_id` 上级节点（0 为顶级）、`name` 名称（必填）、
     *                         `type` 类型（必填，1 目录 2 菜单 3 按钮 4 接口 5 字段）、
     *                         `perm_code` 权限标识（必填，唯一，命名 `模块:资源:操作`）、
     *                         `path` 路由路径、`component` 组件路径（目录填 `Layout`）、
     *                         `icon` EP 图标名、`api_method` / `api_path` 仅 type=4 用、
     *                         `visible` 是否进侧边栏、`keep_alive` 是否缓存页面、
     *                         `sort` 排序、`status` 0 停用 1 启用
     *
     * @return array 只含白名单内字段的数组
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    private static function validate(Request $request): array
    {
        return Validator::make($request->all(), [
            'parent_id'  => ['int|min:0',                '上级节点'],
            'name'       => ['required|string|max:64',   '名称'],
            'type'       => ['required|int|in:1,2,3,4,5', '类型'],
            'perm_code'  => ['required|code|max:128',    '权限标识'],
            'path'       => ['string|max:255',           '路由路径'],
            'component'  => ['string|max:255',           '组件路径'],
            'icon'       => ['string|max:64',            '图标'],
            'api_method' => ['in:GET,POST,PUT,DELETE,PATCH', '接口方法'],
            'api_path'   => ['string|max:255',           '接口路径'],
            'visible'    => ['int|in:0,1',               '是否显示'],
            'keep_alive' => ['int|in:0,1',               '是否缓存'],
            'sort'       => ['int|min:0|max:9999',       '排序'],
            'status'     => ['int|in:0,1',               '状态'],
        ])->validated();
    }
}
