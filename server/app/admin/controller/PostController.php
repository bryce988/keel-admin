<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\PostService;
use app\common\support\BatchResult;
use app\common\support\OpLog;
use app\common\support\Paginator;
use app\common\support\Result;
use app\common\support\Validator;
use support\Response;
use Webman\Http\Request;

/**
 * 岗位管理
 */
class PostController
{
    /**
     * 岗位列表（分页）
     *
     * `GET /admin/posts` · 权限点 `sys:post:list`
     *
     * 排序字段走白名单 `PostService::SORTABLE`，默认按 `sort` 升序；
     * 白名单之外的 `sort_by` 会被忽略而不是报错——排序参数来自用户点表头，
     * 为它返回 422 只会让界面卡住。
     *
     * @param Request $request 查询参数：`keyword` 名称/编码模糊匹配、`status` 0 停用 1 启用、
     *                         `dept_id` 所属部门；分页与排序参数见 {@see \app\common\support\Paginator}
     *
     * @return Response 200，`{list, total, page, page_size}`
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'keyword' => ['string|max:64', '关键词'],
            'status'  => ['in:0,1',        '状态'],
            'dept_id' => ['int|min:1',     '部门'],
        ])->validated();

        return Paginator::response(
            PostService::listQuery($filters),
            $request,
            sortable: PostService::SORTABLE,
            defaultField: 'sort',
            defaultOrder: 'asc',
            map: PostService::rowMapper(),
        );
    }

    /**
     * 岗位详情
     *
     * `GET /admin/posts/{id}` · 权限点 `sys:post:list`
     *
     * @param Request $request 无查询参数
     * @param int     $id      岗位 ID
     *
     * @return Response 200，岗位对象
     *
     * @throws \app\common\exception\NotFoundException   岗位不存在，或不在你的数据范围内（404 + `10404`）
     */
    public function show(Request $request, int $id): Response
    {
        return Result::ok(PostService::detail($id));
    }

    /**
     * 新增岗位
     *
     * `POST /admin/posts` · 权限点 `sys:post:create` · 自动落操作日志
     *
     * @param Request $request 请求体见 {@see self::validate()}
     *
     * @return Response 201，返回新建的岗位对象（含 id）
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\ConflictException  岗位编码已存在（409 + `20201`）
     */
    public function store(Request $request): Response
    {
        return Result::created(PostService::create(self::validate($request))->toArray());
    }

    /**
     * 编辑岗位
     *
     * `PUT /admin/posts/{id}` · 权限点 `sys:post:update` · 自动落操作日志
     *
     * @param Request $request 请求体见 {@see self::validate()}
     * @param int     $id      岗位 ID
     *
     * @return Response 200，返回更新后的岗位对象
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\NotFoundException   岗位不存在，或不在你的数据范围内（404 + `10404`）
     * @throws \app\common\exception\ConflictException  岗位编码已被其他岗位占用（409 + `20201`）
     */
    public function update(Request $request, int $id): Response
    {
        return Result::ok(PostService::update($id, self::validate($request))->toArray());
    }

    /**
     * 删除岗位
     *
     * `DELETE /admin/posts/{id}` · 权限点 `sys:post:delete` · 自动落操作日志
     *
     * @param Request $request 无请求体
     * @param int     $id      岗位 ID
     *
     * @return Response 204，无响应体
     *
     * @throws \app\common\exception\NotFoundException   岗位不存在，或不在你的数据范围内（404 + `10404`）
     * @throws \app\common\exception\ConflictException  该岗位下还有用户（409 + `20203`）
     */
    public function destroy(Request $request, int $id): Response
    {
        PostService::delete($id);

        return Result::noContent();
    }

    /**
     * 批量删除岗位
     *
     * `POST /admin/posts/batch-delete` · 权限点 `sys:post:delete` · 自动落操作日志
     *
     * **逐条尽力执行，不是一个事务**：某一条因「岗位下还有用户」被拒，
     * 其余仍会删除，失败明细逐条返回（api.md §1.4）。整批回滚在这里是错的——
     * 用户勾了 20 个，其中 1 个删不掉就一个都删不成，只会让人反复试。
     *
     * 用 POST 而不是 DELETE：请求体里要带 id 数组，而 DELETE 带 body
     * 在部分代理与网关上会被丢掉。
     *
     * @param Request $request 请求体：`ids` 岗位 ID 数组；空数组直接返回全零的结果，不报错
     *
     * @return Response 200，`{total, success, failed, failures:[{id, message}]}`
     */
    public function batchDestroy(Request $request): Response
    {
        $ids = array_filter(array_map('intval', (array) $request->post('ids', [])));
        if (!$ids) {
            return Result::ok(BatchResult::make()->toArray());
        }

        // 批量操作的日志对象是整批 id：service 里逐条设置的话，
        // 最后只会留下最后一条的名字，审计时看不出这次动了哪些
        OpLog::target('岗位 ' . implode(',', $ids));

        return Result::ok(
            BatchResult::run($ids, fn (int $id) => PostService::delete($id))->toArray()
        );
    }

    /**
     * 新增与编辑共用的入参校验
     *
     * @param Request $request 请求体：`name` 岗位名称（必填，≤64）、`code` 岗位编码（必填，唯一）、
     *                         `dept_id` 所属部门、`default_role_id` 默认角色（新人入职按岗位带角色）、
     *                         `sort` 排序、`status` 0 停用 1 启用、`remark` 备注
     *
     * @return array 只含白名单内字段的数组
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    private static function validate(Request $request): array
    {
        return Validator::make($request->all(), [
            'name'            => ['required|string|max:64', '岗位名称'],
            'code'            => ['required|code|max:64',   '岗位编码'],
            'dept_id'         => ['int|min:0',              '所属部门'],
            'default_role_id' => ['int|min:0',              '默认角色'],
            'sort'            => ['int|min:0|max:9999',     '排序'],
            'status'          => ['int|in:0,1',             '状态'],
            'remark'          => ['string|max:255',         '备注'],
        ])->validated();
    }
}
