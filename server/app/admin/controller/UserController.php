<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\UserService;
use app\common\support\Paginator;
use app\common\support\Validator;
use support\Response;
use Webman\Http\Request;

/**
 * 用户管理
 *
 * 当前只有查询接口——它是骨架的验证载体：一条链路同时压到了
 * 权限中间件、数据权限 Scope、字段级脱敏、分页与排序白名单。
 * 增删改在 M2 补齐（见 docs/api.md §4）。
 */
class UserController
{
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'keyword' => ['string|max:64', '关键词'],
            'status'  => ['in:0,1,2',      '状态'],
            'dept_id' => ['int|min:1',     '部门'],
        ])->validated();

        return Paginator::response(
            UserService::listQuery($filters),
            $request,
            sortable: UserService::SORTABLE,
            defaultField: 'id',
            defaultOrder: 'asc',
            map: UserService::rowMapper(),
        );
    }
}
