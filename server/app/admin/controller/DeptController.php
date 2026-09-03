<?php
/**
 * keel admin
 * 部门管理
 *
 * 控制器只做「校验入参 → 调 service → 包响应」，不查库不开事务。
 * 归属过滤由模型的全局 Scope 注入，控制器与 service 都不写归属条件——
 * 部门主管拿到的只是他管得到的那部分子树。
 *
 * 本模块通用，各方法不再重复：权限点声明在 `config/route.php`，不写即 403（fail-closed）；
 * 入参校验见 `app\admin\validation\Dept\*`，失败一律 422 + 字段级 `details`；
 * 数据范围外的记录返回 404 而非 403（403 等于承认「这个 id 存在，只是你看不到」）；
 * 写操作自动落操作日志。错误码表见 docs/api.md §2.2。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\validation\Dept\StoreRequest;
use app\admin\validation\Dept\TreeRequest;
use app\admin\validation\Dept\UpdateRequest;
use app\admin\service\DeptService;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

class DeptController
{
    /**
     * 部门树
     * @url GET /admin/depts/tree
     * @perm sys:dept:list | sys:user:list
     * @description 不分页，一次返回整棵树，子节点在 `children`。
     * 用户列表的部门筛选也打这个接口，所以两个权限点任一命中即可——
     * 否则只有用户管理权限的人打不开筛选下拉。
     */
    public function tree(TreeRequest $request): Response
    {
        return Result::ok(DeptService::tree($request->validated()));
    }

    /**
     * 部门详情
     * @url GET /admin/depts/{id}
     * @perm sys:dept:list
     */
    public function show(Request $request, int $id): Response
    {
        return Result::ok(DeptService::detail($id));
    }

    /**
     * 新增部门
     * @url POST /admin/depts
     * @perm sys:dept:create
     */
    public function store(StoreRequest $request): Response
    {
        $data = $request->validated();

        return Result::created(DeptService::create($data)->toArray());
    }

    /**
     * 编辑部门
     * @url PUT /admin/depts/{id}
     * @perm sys:dept:update
     * @description 改 `parent_id` 等于移动整棵子树，service 会挡住「把自己挂到自己子孙下面」
     * 这种会形成环的操作。
     * @error 400 `20202` 上级是自己或自己的子部门
     */
    public function update(UpdateRequest $request, int $id): Response
    {
        $data = $request->validated();

        return Result::ok(DeptService::update($id, $data)->toArray());
    }

    /**
     * 删除部门
     * @url DELETE /admin/depts/{id}
     * @perm sys:dept:delete
     * @description 硬删除，但有引用就拒绝——子部门、部门下的用户、部门下的岗位任一存在都不让删。
     * 想「下线」一个部门应该改状态为停用，不是删。
     * @error 409 `20203` 部门下存在子部门、用户或岗位
     */
    public function destroy(Request $request, int $id): Response
    {
        DeptService::delete($id);

        return Result::noContent();
    }
}
