<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\RoleService;
use app\common\service\UserService;
use app\common\support\Paginator;
use app\common\support\Result;
use app\common\support\Validator;
use support\Response;
use Webman\Http\Request;

/**
 * 角色管理（RBAC 的**授权**层）
 *
 * **授权入口只有这一处**。菜单权限页只负责定义权限点，不碰授权关系——
 * 同一件事有两个入口，迟早会出现两边行为不一致。
 */
class RoleController
{
    /**
     * 角色列表（分页）
     *
     * `GET /admin/roles` · 权限点 `sys:role:list`
     *
     * @param Request $request 查询参数：`keyword` 名称/编码模糊匹配、`status` 0 停用 1 启用、
     *                         `data_scope` 1 全部 2 本部门及下级 3 本部门 4 仅本人 5 自定义
     *
     * @return Response 200，`{list, total, page, page_size}`
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'keyword'    => ['string|max:64', '关键词'],
            'status'     => ['in:0,1',        '状态'],
            'data_scope' => ['in:1,2,3,4,5',  '数据范围'],
        ])->validated();

        return Paginator::response(
            RoleService::listQuery($filters),
            $request,
            sortable: RoleService::SORTABLE,
            defaultField: 'sort',
            defaultOrder: 'asc',
            map: RoleService::rowMapper(),
        );
    }

    /**
     * 角色详情
     *
     * `GET /admin/roles/{id}` · 权限点 `sys:role:list`
     *
     * 授权抽屉的三个 tab 一次取全：功能权限 id 列表、数据范围与自定义部门、互斥角色 id 列表。
     *
     * @param Request $request 无查询参数
     * @param int     $id      角色 ID
     *
     * @return Response 200，角色对象（含 `permission_ids`、`dept_ids`、`mutex_ids`）
     *
     * @throws \app\common\exception\NotFoundException   角色不存在（404 + `10404`）
     */
    public function show(Request $request, int $id): Response
    {
        return Result::ok(RoleService::detail($id));
    }

    /**
     * 角色下拉项
     *
     * `GET /admin/roles/options` · 权限点 `sys:role:list` 或 `sys:user:list`（任一命中即可）
     *
     * 用户管理里分配角色要用它，所以两个权限点任一即可——
     * 只有用户管理权限的人否则打不开角色下拉。
     *
     * @param Request $request 无参数
     *
     * @return Response 200，`[{id, name, code}]`，只含启用中的角色
     */
    public function options(Request $request): Response
    {
        return Result::ok(RoleService::options());
    }

    /**
     * 新增角色
     *
     * `POST /admin/roles` · 权限点 `sys:role:create` · 自动落操作日志
     *
     * 新建的角色**不带任何权限**，要到授权抽屉里逐项勾选。
     *
     * @param Request $request 请求体见 {@see self::validate()}
     *
     * @return Response 201，返回新建的角色（含 id）
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\ConflictException  角色编码已存在（409 + `20301`）
     * @throws \app\common\exception\BusinessException  继承关系形成环（400 + `20306`）
     */
    public function store(Request $request): Response
    {
        return Result::created(RoleService::create(self::validate($request))->toArray());
    }

    /**
     * 编辑角色
     *
     * `PUT /admin/roles/{id}` · 权限点 `sys:role:update` · 自动落操作日志
     *
     * 内置角色不允许修改：它们被 `scripts/seed.php` 按编码 upsert，
     * 改了下次播种又会被覆盖回去，白改一场。
     *
     * @param Request $request 请求体见 {@see self::validate()}
     * @param int     $id      角色 ID
     *
     * @return Response 200，返回更新后的角色
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\NotFoundException   角色不存在（404 + `10404`）
     * @throws \app\common\exception\ForbiddenException 内置角色不允许修改（403 + `20302`）
     * @throws \app\common\exception\ConflictException  角色编码已被占用（409 + `20301`）
     * @throws \app\common\exception\BusinessException  继承关系形成环（400 + `20306`）
     */
    public function update(Request $request, int $id): Response
    {
        return Result::ok(RoleService::update($id, self::validate($request))->toArray());
    }

    /**
     * 删除角色
     *
     * `DELETE /admin/roles/{id}` · 权限点 `sys:role:delete` · 自动落操作日志
     *
     * @param Request $request 无请求体
     * @param int     $id      角色 ID
     *
     * @return Response 204，无响应体
     *
     * @throws \app\common\exception\NotFoundException   角色不存在（404 + `10404`）
     * @throws \app\common\exception\ForbiddenException 内置角色不允许删除（403 + `20302`）
     * @throws \app\common\exception\ConflictException  角色下还有成员（409 + `20303`）
     */
    public function destroy(Request $request, int $id): Response
    {
        RoleService::delete($id);

        return Result::noContent();
    }

    /**
     * 保存功能权限
     *
     * `PUT /admin/roles/{id}/permissions` · 权限点 `sys:role:grantPerm` · 自动落操作日志
     *
     * **全量覆盖**，不是增量：传上来的就是这个角色最终的权限集合，没传的一律取消。
     * 增量语义会让「取消某个权限」没有对应的调用方式。
     *
     * 提交的 id 里含 `type=5` 的字段级权限点——字段权限不做单独界面，
     * 就在这棵树里勾（M2 定案）。
     *
     * 保存后会顶该角色下所有用户的 `perm_version`，Redis 缓存随即失效：
     * **不需要重新登录，下一次请求就是新权限**。
     *
     * @param Request $request 请求体：`permission_ids` 权限点 ID 数组；空数组表示清空该角色所有权限
     * @param int     $id      角色 ID
     *
     * @return Response 204，无响应体
     *
     * @throws \app\common\exception\NotFoundException   角色不存在（404 + `10404`）
     */
    public function grantPermissions(Request $request, int $id): Response
    {
        $ids = array_map('intval', (array) $request->post('permission_ids', []));
        RoleService::grantPermissions($id, $ids);

        return Result::noContent();
    }

    /**
     * 保存数据范围
     *
     * `PUT /admin/roles/{id}/data-scope` · 权限点 `sys:role:grantData` · 自动落操作日志
     *
     * 五种范围：1 全部、2 本部门及下级、3 仅本部门、4 仅本人、5 自定义部门。
     * 只有 5 才需要 `dept_ids`，其余会被忽略。
     *
     * 这个值最终由模型的 `HasDataScope` 全局 Scope 消费，业务代码里
     * **不允许**再手写归属过滤（CLAUDE.md 硬性约定）。
     *
     * @param Request $request 请求体：`data_scope` 1-5（必填）、`dept_ids` 部门 ID 数组（范围 5 时用）
     * @param int     $id      角色 ID
     *
     * @return Response 204，无响应体
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\NotFoundException   角色不存在（404 + `10404`）
     */
    public function grantDataScope(Request $request, int $id): Response
    {
        $data = Validator::make($request->all(), [
            'data_scope' => ['required|int|in:1,2,3,4,5', '数据范围'],
            'dept_ids'   => ['array',                     '部门'],
        ])->validated();

        RoleService::grantDataScope($id, $data['data_scope'], (array) ($data['dept_ids'] ?? []));

        return Result::noContent();
    }

    /**
     * 保存互斥角色（职责分离，RBAC2）
     *
     * `PUT /admin/roles/{id}/mutexes` · 权限点 `sys:role:grantData` · 自动落操作日志
     *
     * 互斥关系**双向写入**：给 A 选了 B，库里同时写 A→B 与 B→A，
     * 从 B 那边打开也看得见。全量覆盖，没传的关系会被解除。
     *
     * ⚠️ 这里**不校验存量分配**：如果某人已经同时持有这两个角色，
     * 保存互斥不会报错也不会把他踢掉，约束要到下次有人动他角色时才生效。
     *
     * @param Request $request 请求体：`mutex_ids` 与之互斥的角色 ID 数组；自己会被自动剔除
     * @param int     $id      角色 ID
     *
     * @return Response 204，无响应体
     *
     * @throws \app\common\exception\NotFoundException   角色不存在（404 + `10404`）
     */
    public function saveMutexes(Request $request, int $id): Response
    {
        RoleService::saveMutexes($id, array_map('intval', (array) $request->post('mutex_ids', [])));

        return Result::noContent();
    }

    // ---------------------------------------------------------------- 成员

    /**
     * 角色成员列表（分页）
     *
     * `GET /admin/roles/{id}/members` · 权限点 `sys:role:list`
     *
     * 复用用户列表的排序白名单与行映射，所以成员列表里的手机号等字段
     * 同样受字段级权限约束——不会因为换了个入口就把脱敏绕过去。
     *
     * @param Request $request 分页与排序参数
     * @param int     $id      角色 ID
     *
     * @return Response 200，`{list, total, page, page_size}`
     *
     * @throws \app\common\exception\NotFoundException   角色不存在（404 + `10404`）
     */
    public function members(Request $request, int $id): Response
    {
        return Paginator::response(
            RoleService::memberQuery($id),
            $request,
            sortable: UserService::SORTABLE,
            defaultField: 'id',
            defaultOrder: 'asc',
            map: UserService::rowMapper(),
        );
    }

    /**
     * 批量添加角色成员
     *
     * `POST /admin/roles/{id}/members` · 权限点 `sys:user:grantRole` · 自动落操作日志
     *
     * 权限点用的是 `sys:user:grantRole` 而不是角色自己的——
     * 「谁能给人授角色」是一件事，从角色页进还是从用户页进只是入口不同。
     *
     * 逐个用户走 {@see \app\common\service\RoleService::assertAssignable()}，
     * 互斥与角色数上限的校验与用户页**共用同一份实现**。
     *
     * @param Request $request 请求体：`user_ids` 用户 ID 数组；空数组直接返回，不报错
     * @param int     $id      角色 ID
     *
     * @return Response 204，无响应体
     *
     * @throws \app\common\exception\NotFoundException   角色不存在（404 + `10404`）
     * @throws \app\common\exception\BusinessException 与已有角色互斥（400 + `20304`）、
     *                                                     超出单账号角色数上限（400 + `20305`）
     */
    public function addMembers(Request $request, int $id): Response
    {
        $ids = array_filter(array_map('intval', (array) $request->post('user_ids', [])));
        if ($ids) {
            RoleService::addMembers($id, $ids);
        }

        return Result::noContent();
    }

    /**
     * 移除角色成员
     *
     * `DELETE /admin/roles/{id}/members/{userId}` · 权限点 `sys:user:grantRole` · 自动落操作日志
     *
     * @param Request $request 无请求体
     * @param int     $id      角色 ID
     * @param int     $userId  用户 ID
     *
     * @return Response 204，无响应体
     *
     * @throws \app\common\exception\NotFoundException   角色不存在（404 + `10404`）
     */
    public function removeMember(Request $request, int $id, int $userId): Response
    {
        RoleService::removeMember($id, $userId);

        return Result::noContent();
    }

    /**
     * 新增与编辑共用的入参校验
     *
     * @param Request $request 请求体：`name` 角色名称（必填，≤64）、`code` 角色编码（必填，唯一）、
     *                         `parent_id` 继承自哪个角色（0 为不继承，RBAC1）、
     *                         `data_scope` 数据范围 1-5、`sort` 排序、`status` 0 停用 1 启用、`remark` 备注
     *
     * @return array 只含白名单内字段的数组
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    private static function validate(Request $request): array
    {
        return Validator::make($request->all(), [
            'name'       => ['required|string|max:64', '角色名称'],
            'code'       => ['required|code|max:64',   '角色编码'],
            'parent_id'  => ['int|min:0',              '继承自'],
            'data_scope' => ['int|in:1,2,3,4,5',       '数据范围'],
            'sort'       => ['int|min:0|max:9999',     '排序'],
            'status'     => ['int|in:0,1',             '状态'],
            'remark'     => ['string|max:255',         '备注'],
        ])->validated();
    }
}
