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
    public function tree(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'keyword' => ['string|max:64', '关键词'],
            'status'  => ['in:0,1',        '状态'],
        ])->validated();

        return Result::ok(DeptService::tree($filters));
    }

    public function show(Request $request, int $id): Response
    {
        return Result::ok(DeptService::detail($id));
    }

    public function store(Request $request): Response
    {
        $data = self::validate($request);

        return Result::created(DeptService::create($data)->toArray());
    }

    public function update(Request $request, int $id): Response
    {
        $data = self::validate($request);

        return Result::ok(DeptService::update($id, $data)->toArray());
    }

    public function destroy(Request $request, int $id): Response
    {
        DeptService::delete($id);

        return Result::noContent();
    }

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
