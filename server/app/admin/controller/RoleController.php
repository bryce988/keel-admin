<?php
/**
 * keel admin
 * 角色管理（RBAC 的授权层）
 *
 * 授权入口只有这一处。菜单权限页只负责定义权限点，不碰授权关系——
 * 同一件事有两个入口，迟早会出现两边行为不一致。
 * 三层职责分离：定义（菜单权限）→ 授权（本模块）→ 分配（用户）。
 *
 * 授权类接口一律全量覆盖，不是增量：传上来的就是最终集合，没传的一律取消。
 * 增量语义会让「取消某一项」没有对应的调用方式。保存后顶相关用户的 `perm_version`，
 * Redis 缓存随即失效，不需要重新登录，下一次请求就是新权限。
 *
 * 本模块通用，各方法不再重复：权限点声明在 `config/route.php`，不写即 403（fail-closed）；
 * 入参校验见 `app\admin\validation\Role\*`，失败一律 422 + 字段级 `details`；
 * 数据范围外的记录返回 404 而非 403；写操作自动落操作日志。错误码表见 docs/api.md §2.2。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\validation\Role\DataScopeRequest;
use app\admin\validation\Role\ListRequest;
use app\admin\validation\Role\StoreRequest;
use app\admin\validation\Role\UpdateRequest;
use app\admin\service\RoleService;
use app\admin\service\UserService;
use app\common\support\Paginator;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

class RoleController
{
    /**
     * 角色列表（分页）
     * @url GET /admin/roles
     * @perm sys:role:list
     * @description 筛选项 `data_scope`：1 全部 · 2 本部门及下级 · 3 本部门 · 4 仅本人 · 5 自定义。
     */
    public function index(ListRequest $request): Response
    {
        return Paginator::response(
            RoleService::listQuery($request->validated()),
            $request->request(),   // 分页与排序参数不在 ListRequest 白名单里，走原始 Request
            sortable: RoleService::SORTABLE,
            defaultField: 'sort',
            defaultOrder: 'asc',
            map: RoleService::rowMapper(),
        );
    }

    /**
     * 角色详情
     * @url GET /admin/roles/{id}
     * @perm sys:role:list
     * @description 授权抽屉的三个 tab 一次取全：功能权限 id 列表、数据范围与自定义部门、互斥角色 id 列表。
     */
    public function show(Request $request, int $id): Response
    {
        return Result::ok(RoleService::detail($id));
    }

    /**
     * 角色下拉项
     * @url GET /admin/roles/options
     * @perm sys:role:list | sys:user:list
     * @description 用户管理里分配角色要用它，所以两个权限点任一命中即可——
     * 否则只有用户管理权限的人打不开角色下拉。
     */
    public function options(Request $request): Response
    {
        return Result::ok(RoleService::options());
    }

    /**
     * 新增角色
     * @url POST /admin/roles
     * @perm sys:role:create
     * @description 新建的角色不带任何权限，要到授权抽屉里逐项勾选。
     */
    public function store(StoreRequest $request): Response
    {
        return Result::created(RoleService::create($request->validated())->toArray());
    }

    /**
     * 编辑角色
     * @url PUT /admin/roles/{id}
     * @perm sys:role:update
     * @description 内置角色不允许修改：它们被 `scripts/seed.php` 按编码 upsert，
     * 改了下次播种又会被覆盖回去，白改一场。
     * @error 403 `20302` 内置角色不允许修改
     * · 400 `20306` 继承关系成环
     */
    public function update(UpdateRequest $request, int $id): Response
    {
        return Result::ok(RoleService::update($id, $request->validated())->toArray());
    }

    /**
     * 删除角色
     * @url DELETE /admin/roles/{id}
     * @perm sys:role:delete
     * @error 403 `20302` 内置角色不允许删除 · 409 `20303` 角色下存在用户
     * · 409 `20308` 该角色被其他角色继承
     */
    public function destroy(Request $request, int $id): Response
    {
        RoleService::delete($id);

        return Result::noContent();
    }

    /**
     * 保存功能权限
     * @url PUT /admin/roles/{id}/permissions
     * @perm sys:role:grantPerm
     * @description 请求体 `permission_ids` 数组，全量覆盖。
     * 提交的 id 里含 `type=5` 的字段级权限点——字段权限不做单独界面，就在这棵树里勾（M2 定案）。
     * @error 403 `20302` 内置角色不允许修改
     */
    public function grantPermissions(Request $request, int $id): Response
    {
        $ids = array_map('intval', (array) $request->post('permission_ids', []));
        RoleService::grantPermissions($id, $ids);

        return Result::noContent();
    }

    /**
     * 保存数据范围
     * @url PUT /admin/roles/{id}/data-scope
     * @perm sys:role:grantData
     * @description 五种范围：1 全部 · 2 本部门及下级 · 3 仅本部门 · 4 仅本人 · 5 自定义部门。
     * 只有 5 才需要 `dept_ids`，其余会被忽略。
     * 这个值最终由模型的 `HasDataScope` 全局 Scope 消费，业务代码里不允许再手写归属过滤。
     * @error 400 `20307` 自定义范围至少要选一个部门
     */
    public function grantDataScope(DataScopeRequest $request, int $id): Response
    {
        $data = $request->validated();

        RoleService::grantDataScope($id, $data['data_scope'], (array) ($data['dept_ids'] ?? []));

        return Result::noContent();
    }

    /**
     * 保存互斥角色（职责分离，RBAC2）
     * @url PUT /admin/roles/{id}/mutexes
     * @perm sys:role:grantData
     * @description 请求体 `mutex_ids` 数组，全量覆盖，没传的关系会被解除。
     * 互斥关系双向写入：给 A 选了 B，库里同时写 A→B 与 B→A，从 B 那边打开也看得见。
     * ⚠️ 这里不校验存量分配：如果某人已经同时持有这两个角色，保存互斥不会报错也不会把他踢掉，
     * 约束要到下次有人动他角色时才生效。
     */
    public function saveMutexes(Request $request, int $id): Response
    {
        RoleService::saveMutexes($id, array_map('intval', (array) $request->post('mutex_ids', [])));

        return Result::noContent();
    }

    // ---------------------------------------------------------------- 成员

    /**
     * 角色成员列表（分页）
     * @url GET /admin/roles/{id}/members
     * @perm sys:role:list
     * @description 复用用户列表的排序白名单与行映射，所以成员列表里的手机号等字段
     * 同样受字段级权限约束——不会因为换了个入口就把脱敏绕过去。
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
     * @url POST /admin/roles/{id}/members
     * @perm sys:user:grantRole
     * @description 请求体 `user_ids` 数组。权限点用的是 `sys:user:grantRole` 而不是角色自己的——
     * 「谁能给人授角色」是一件事，从角色页进还是从用户页进只是入口不同。
     * 逐个用户走 {@see \app\admin\service\RoleService::assertAssignable()}，
     * 互斥与角色数上限的校验与用户页共用同一份实现。
     * @error 400 `20304` 角色互斥 · 400 `20305` 超出单账号角色数上限
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
     * @url DELETE /admin/roles/{id}/members/{userId}
     * @perm sys:user:grantRole
     */
    public function removeMember(Request $request, int $id, int $userId): Response
    {
        RoleService::removeMember($id, $userId);

        return Result::noContent();
    }
}
