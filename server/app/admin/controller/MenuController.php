<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\MenuService;
use app\common\support\Result;
use app\common\support\Validator;
use support\Response;
use Webman\Http\Request;

/**
 * 菜单与权限点
 *
 * **本模块只定义权限点，不做授权**。把权限给谁在角色管理里做。
 */
class MenuController
{
    public function tree(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'keyword' => ['string|max:64',   '关键词'],
            'type'    => ['in:1,2,3,4,5',    '类型'],
            'status'  => ['in:0,1',          '状态'],
        ])->validated();

        return Result::ok(MenuService::tree($filters));
    }

    public function show(Request $request, int $id): Response
    {
        return Result::ok(MenuService::detail($id));
    }

    /** 角色 × 权限矩阵，只读审计视图 */
    public function matrix(Request $request): Response
    {
        return Result::ok(MenuService::matrix());
    }

    public function store(Request $request): Response
    {
        return Result::created(MenuService::create(self::validate($request))->toArray());
    }

    public function update(Request $request, int $id): Response
    {
        return Result::ok(MenuService::update($id, self::validate($request))->toArray());
    }

    public function destroy(Request $request, int $id): Response
    {
        MenuService::delete($id);

        return Result::noContent();
    }

    /**
     * 五种类型的字段要求不同，但校验规则写在一处：
     * 不适用的字段由 service 的 normalize() 清空，这里只保证格式合法。
     */
    private static function validate(Request $request): array
    {
        return Validator::make($request->all(), [
            'parent_id'  => ['int|min:0',                '上级节点'],
            'name'       => ['required|string|max:64',   '名称'],
            'type'       => ['required|int|in:1,2,3,4,5', '类型'],
            'perm_code'  => ['required|code|max:128',    '权限标识'],
            'path'       => ['string|max:255',           '路由路径'],
            'component'  => ['string|max:255',           '组件路径'],
            'icon'       => ['string|max:64',            '图标'],
            'api_method' => ['in:GET,POST,PUT,DELETE,PATCH', '接口方法'],
            'api_path'   => ['string|max:255',           '接口路径'],
            'visible'    => ['int|in:0,1',               '是否显示'],
            'keep_alive' => ['int|in:0,1',               '是否缓存'],
            'sort'       => ['int|min:0|max:9999',       '排序'],
            'status'     => ['int|in:0,1',               '状态'],
        ])->validated();
    }
}
