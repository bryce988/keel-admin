<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\admin\validation\Dept\StoreRequest;
use app\admin\validation\Dept\TreeRequest;
use app\admin\validation\Dept\UpdateRequest;
use app\common\service\DeptService;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

/**
 * 部门管理
 *
 * 控制器只做「校验入参 → 调 service → 包响应」，不查库不开事务（CLAUDE.md 硬性约定）。
 */
class DeptController
{
    /**
     * 部门树
     *
     * `GET /admin/depts/tree` · 权限点 `sys:dept:list` 或 `sys:user:list`（任一命中即可）
     *
     * 不分页，一次返回整棵树。归属过滤由模型的全局 Scope 注入——部门主管拿到的
     * 只是他管得到的那部分子树，控制器与 service 都不写归属条件（CLAUDE.md 硬性约定）。
     *
     * 用户列表的部门筛选也用这个接口，所以两个权限点任一即可，
     * 否则只有用户管理权限的人打不开筛选下拉。
     *
     * @param TreeRequest $request 查询参数见 {@see TreeRequest}
     *
     * @return Response 200，树形数组，子节点在 `children`
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`，`details` 里是字段级错误）
     */
    public function tree(TreeRequest $request): Response
    {
        return Result::ok(DeptService::tree($request->validated()));
    }

    /**
     * 部门详情
     *
     * `GET /admin/depts/{id}` · 权限点 `sys:dept:list`
     *
     * @param Request $request 无查询参数
     * @param int     $id      部门 ID，路由已限定为纯数字
     *
     * @return Response 200，部门对象
     *
     * @throws \app\common\exception\NotFoundException   部门不存在，或不在你的数据范围内（404 + `10404`）
     */
    public function show(Request $request, int $id): Response
    {
        return Result::ok(DeptService::detail($id));
    }

    /**
     * 新增部门
     *
     * `POST /admin/depts` · 权限点 `sys:dept:create` · 自动落操作日志
     *
     * @param StoreRequest $request 请求体见 {@see StoreRequest}，其中 `name` 与 `code` 必填
     *
     * @return Response 201，返回新建的部门对象（含 id）
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`，`details` 里是字段级错误）
     * @throws \app\common\exception\ConflictException  部门编码已存在（409 + `20201`）
     */
    public function store(StoreRequest $request): Response
    {
        $data = $request->validated();

        return Result::created(DeptService::create($data)->toArray());
    }

    /**
     * 编辑部门
     *
     * `PUT /admin/depts/{id}` · 权限点 `sys:dept:update` · 自动落操作日志
     *
     * 改 `parent_id` 等于移动整棵子树，service 会挡住「把自己挂到自己子孙下面」
     * 这种会形成环的操作。
     *
     * @param UpdateRequest $request 请求体见 {@see UpdateRequest}
     * @param int           $id      部门 ID
     *
     * @return Response 200，返回更新后的部门对象
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`，`details` 里是字段级错误）
     * @throws \app\common\exception\NotFoundException   部门不存在，或不在你的数据范围内（404 + `10404`）
     * @throws \app\common\exception\ConflictException  部门编码已被其他部门占用（409 + `20201`）
     * @throws \app\common\exception\BusinessException  上级部门是自己或自己的子部门（400 + `20202`）
     */
    public function update(UpdateRequest $request, int $id): Response
    {
        $data = $request->validated();

        return Result::ok(DeptService::update($id, $data)->toArray());
    }

    /**
     * 删除部门
     *
     * `DELETE /admin/depts/{id}` · 权限点 `sys:dept:delete` · 自动落操作日志
     *
     * 硬删除，但有引用就拒绝——子部门、部门下的用户、部门下的岗位任一存在都不让删。
     * 想「下线」一个部门应该改状态为停用，不是删。
     *
     * @param Request $request 无请求体
     * @param int     $id      部门 ID
     *
     * @return Response 204，无响应体
     *
     * @throws \app\common\exception\NotFoundException   部门不存在，或不在你的数据范围内（404 + `10404`）
     * @throws \app\common\exception\ConflictException  部门下存在子部门、用户或岗位（409 + `20203`）
     */
    public function destroy(Request $request, int $id): Response
    {
        DeptService::delete($id);

        return Result::noContent();
    }
}
