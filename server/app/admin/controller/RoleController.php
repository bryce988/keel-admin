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

    public function show(Request $request, int $id): Response
    {
        return Result::ok(RoleService::detail($id));
    }

    /** 下拉项，用户管理分配角色时用 */
    public function options(Request $request): Response
    {
        return Result::ok(RoleService::options());
    }

    public function store(Request $request): Response
    {
        return Result::created(RoleService::create(self::validate($request))->toArray());
    }

    public function update(Request $request, int $id): Response
    {
        return Result::ok(RoleService::update($id, self::validate($request))->toArray());
    }

    public function destroy(Request $request, int $id): Response
    {
        RoleService::delete($id);

        return Result::noContent();
    }

    /** 保存功能权限（含 type=5 的字段级权限点） */
    public function grantPermissions(Request $request, int $id): Response
    {
        $ids = array_map('intval', (array) $request->post('permission_ids', []));
        RoleService::grantPermissions($id, $ids);

        return Result::noContent();
    }

    /** 保存数据范围 */
    public function grantDataScope(Request $request, int $id): Response
    {
        $data = Validator::make($request->all(), [
            'data_scope' => ['required|int|in:1,2,3,4,5', '数据范围'],
            'dept_ids'   => ['array',                     '部门'],
        ])->validated();

        RoleService::grantDataScope($id, $data['data_scope'], (array) ($data['dept_ids'] ?? []));

        return Result::noContent();
    }

    /** 保存互斥角色（职责分离） */
    public function saveMutexes(Request $request, int $id): Response
    {
        RoleService::saveMutexes($id, array_map('intval', (array) $request->post('mutex_ids', [])));

        return Result::noContent();
    }

    // ---------------------------------------------------------------- 成员

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

    public function addMembers(Request $request, int $id): Response
    {
        $ids = array_filter(array_map('intval', (array) $request->post('user_ids', [])));
        if ($ids) {
            RoleService::addMembers($id, $ids);
        }

        return Result::noContent();
    }

    public function removeMember(Request $request, int $id, int $userId): Response
    {
        RoleService::removeMember($id, $userId);

        return Result::noContent();
    }

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
