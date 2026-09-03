<?php
/**
 * keel admin
 * 岗位管理
 *
 * 岗位挂在部门下，同时承载「默认角色」——新人入职按岗位带角色，
 * 不用每次手工勾一遍。
 *
 * 本模块通用，各方法不再重复：权限点声明在 `config/route.php`，不写即 403（fail-closed）；
 * 入参校验见 `app\admin\validation\Post\*`，失败一律 422 + 字段级 `details`；
 * 数据范围外的记录返回 404 而非 403（403 等于承认「这个 id 存在，只是你看不到」）；
 * 写操作自动落操作日志。错误码表见 docs/api.md §2.2。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\validation\Post\ListRequest;
use app\admin\validation\Post\StoreRequest;
use app\admin\validation\Post\UpdateRequest;
use app\admin\service\PostService;
use app\common\support\BatchResult;
use app\common\support\OpLog;
use app\common\support\Paginator;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

class PostController
{
    /**
     * 岗位列表（分页）
     * @url GET /admin/posts
     * @perm sys:post:list
     * @description 排序字段走白名单 `PostService::SORTABLE`，默认按 `sort` 升序；
     * 白名单之外的 `sort_by` 会被忽略而不是报错——排序参数来自用户点表头，
     * 为它返回 422 只会让界面卡住。
     */
    public function index(ListRequest $request): Response
    {
        return Paginator::response(
            PostService::listQuery($request->validated()),
            $request->request(),   // 分页与排序参数不在 ListRequest 白名单里，走原始 Request
            sortable: PostService::SORTABLE,
            defaultField: 'sort',
            defaultOrder: 'asc',
            map: PostService::rowMapper(),
        );
    }

    /**
     * 岗位下拉选项
     *
     * 用户表单要选岗位，所以 `sys:user:list` 也放行——与角色的
     * `/roles/options` 同一套口径。不这么做的话，有权管用户但无权管岗位的人
     * 打开新增用户抽屉，岗位下拉会是空的且控制台一个 403。
     *
     * @url GET /admin/posts/options
     * @perm sys:post:list | sys:user:list
     */
    public function options(Request $request): Response
    {
        return Result::ok(PostService::options());
    }

    /**
     * 岗位详情
     * @url GET /admin/posts/{id}
     * @perm sys:post:list
     */
    public function show(Request $request, int $id): Response
    {
        return Result::ok(PostService::detail($id));
    }

    /**
     * 新增岗位
     * @url POST /admin/posts
     * @perm sys:post:create
     */
    public function store(StoreRequest $request): Response
    {
        return Result::created(PostService::create($request->validated())->toArray());
    }

    /**
     * 编辑岗位
     * @url PUT /admin/posts/{id}
     * @perm sys:post:update
     */
    public function update(UpdateRequest $request, int $id): Response
    {
        return Result::ok(PostService::update($id, $request->validated())->toArray());
    }

    /**
     * 删除岗位
     * @url DELETE /admin/posts/{id}
     * @perm sys:post:delete
     * @error 409 `20802` 该岗位下还有用户
     */
    public function destroy(Request $request, int $id): Response
    {
        PostService::delete($id);

        return Result::noContent();
    }

    /**
     * 批量删除岗位
     * @url POST /admin/posts/batch-delete
     * @perm sys:post:delete
     * @description 请求体 `ids` 数组，返回 `{total, success, failed, failures:[{id, message}]}`。
     * 逐条尽力执行，不是一个事务：某一条因「岗位下还有用户」被拒，其余仍会删除（api.md §1.4）。
     * 整批回滚在这里是错的——用户勾了 20 个，其中 1 个删不掉就一个都删不成，只会让人反复试。
     * 用 POST 而不是 DELETE：请求体里要带 id 数组，而 DELETE 带 body 在部分代理与网关上会被丢掉。
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
}
