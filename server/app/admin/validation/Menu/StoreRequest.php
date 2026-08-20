<?php

declare(strict_types=1);

namespace app\admin\validation\Menu;

use app\admin\validation\FormRequest;

/**
 * 新增菜单 / 权限点（`POST /admin/menus`）
 *
 * 五种类型（目录/菜单/按钮/接口/字段）的字段要求不同，但规则只写这一份。
 * 不适用的字段由 {@see \app\common\service\MenuService} 的 `normalize()` 清空，
 * 这里只保证格式合法。分开写五套规则的话，「按钮不该有 component」这种约束
 * 会散落在五个地方，改一处漏四处。
 *
 * `perm_code` 全类型必填：权限点是 fail-closed 的（`config/route.php` 里
 * 声明了才有人能授权），留空的节点等于建了一个谁也授不了的死条目。
 *
 * 与编辑共用一份规则，见 {@see UpdateRequest}。
 */
class StoreRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'parent_id'  => ['integer|min:0',                '上级节点'],
            'name'       => ['required|string|max:64',       '名称'],
            'type'       => ['required|integer|in:1,2,3,4,5', '类型'],
            'perm_code'  => ['required|code|max:128',        '权限标识'],   // 模块:资源:操作
            'path'       => ['string|max:255',               '路由路径'],
            'component'  => ['string|max:255',               '组件路径'],   // 目录填 Layout
            'icon'       => ['string|max:64',                '图标'],       // EP 图标名
            'api_method' => ['in:GET,POST,PUT,DELETE,PATCH', '接口方法'],   // 仅 type=4 用
            'api_path'   => ['string|max:255',               '接口路径'],   // 仅 type=4 用
            'visible'    => ['integer|in:0,1',               '是否显示'],   // 是否进侧边栏
            'keep_alive' => ['integer|in:0,1',               '是否缓存'],   // 是否缓存页面
            'sort'       => ['integer|min:0|max:9999',       '排序'],
            'status'     => ['integer|in:0,1',               '状态'],
        ];
    }
}
