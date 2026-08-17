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

    public function show(Request $request, int $id): Response
    {
        return Result::ok(PostService::detail($id));
    }

    public function store(Request $request): Response
    {
        return Result::created(PostService::create(self::validate($request))->toArray());
    }

    public function update(Request $request, int $id): Response
    {
        return Result::ok(PostService::update($id, self::validate($request))->toArray());
    }

    public function destroy(Request $request, int $id): Response
    {
        PostService::delete($id);

        return Result::noContent();
    }

    /** 批量删除：逐条尽力执行，返回成功与失败明细（api.md §1.4） */
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
