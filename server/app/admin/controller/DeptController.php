<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\DeptService;
use app\common\support\Result;
use app\common\support\Validator;
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
     * @param Request $request 查询参数：`keyword` 名称/编码模糊匹配、`status` 0 停用 1 启用
     *
     * @return Response 200，树形数组，子节点在 `children`
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`，`details` 里是字段级错误）
     */
    public function tree(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'keyword' => ['string|max:64', '关键词'],
            'status'  => ['in:0,1',        '状态'],
        ])->validated();

        return Result::ok(DeptService::tree($filters));
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
     * @param Request $request 请求体见 {@see self::validate()}，其中 `name` 与 `code` 必填
     *
     * @return Response 201，返回新建的部门对象（含 id）
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`，`details` 里是字段级错误）
     * @throws \app\common\exception\ConflictException  部门编码已存在（409 + `20201`）
     */
    public function store(Request $request): Response
    {
        $data = self::validate($request);

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
     * @param Request $request 请求体见 {@see self::validate()}
     * @param int     $id      部门 ID
     *
     * @return Response 200，返回更新后的部门对象
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`，`details` 里是字段级错误）
     * @throws \app\common\exception\NotFoundException   部门不存在，或不在你的数据范围内（404 + `10404`）
     * @throws \app\common\exception\ConflictException  部门编码已被其他部门占用（409 + `20201`）
     * @throws \app\common\exception\BusinessException  上级部门是自己或自己的子部门（400 + `20202`）
     */
    public function update(Request $request, int $id): Response
    {
        $data = self::validate($request);

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

    /**
     * 新增与编辑共用的入参校验
     *
     * 抽出来是为了让两个入口的可填字段与约束**只有一份**：分开写迟早出现
     * 「新建时校验了、编辑时没校验」这种不对称。
     *
     * @param Request $request 请求体：`parent_id` 上级部门（0 为顶级）、`name` 部门名称（必填，≤64）、
     *                         `code` 部门编码（必填，唯一，仅限编码字符）、`leader_id` 负责人用户 ID、
     *                         `sort` 排序（0-9999，升序）、`status` 0 停用 1 启用
     *
     * @return array 只含白名单内字段的数组，未声明的字段会被丢弃
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`，`details` 里是字段级错误）
     */
    private static function validate(Request $request): array
    {
        return Validator::make($request->all(), [
            'parent_id' => ['int|min:0',                '上级部门'],
            'name'      => ['required|string|max:64',   '部门名称'],
            'code'      => ['required|code|max:64',     '部门编码'],
            'leader_id' => ['int|min:0',                '负责人'],
            'sort'      => ['int|min:0|max:9999',       '排序'],
            'status'    => ['int|in:0,1',               '状态'],
        ])->validated();
    }
}
