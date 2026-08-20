<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\admin\validation\Dict\ListItemRequest;
use app\admin\validation\Dict\ListTypeRequest;
use app\admin\validation\Dict\StoreItemRequest;
use app\admin\validation\Dict\StoreTypeRequest;
use app\admin\validation\Dict\UpdateItemRequest;
use app\admin\validation\Dict\UpdateTypeRequest;
use app\common\service\DictService;
use app\common\support\BatchResult;
use app\common\support\OpLog;
use app\common\support\Paginator;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

/**
 * 数据字典
 *
 * 读接口（items / batch）只要登录态：字典是全站下拉与状态标签的基础数据，
 * 要求 sys:dict:list 会让没有字典管理权限的账号连状态色都渲染不出来（docs/api.md §8）。
 * 维护接口才走 sys:dict:*。
 */
class DictController
{
    // ------------------------------------------------------------ 读取（登录态）

    /**
     * 单个字典的可选项
     *
     * `GET /admin/dicts/{code}/items` · **登录即可**，不要求 `sys:dict:list`
     *
     * 只返回启用中的项，供 `<DictSelect>` / `<DictTag>` 渲染。
     * 不要权限点是有意的：字典是全站下拉与状态标签的基础数据，
     * 要求 `sys:dict:list` 会让没有字典管理权限的账号连状态色都渲染不出来（api.md §8）。
     *
     * @param Request $request 无查询参数
     * @param string  $code    字典编码，如 `enable_status`
     *
     * @return Response 200，`[{label, value, tag_type}]`
     *
     * @throws \app\common\exception\NotFoundException 字典不存在或已停用（404 + `10404`）
     */
    public function items(Request $request, string $code): Response
    {
        return Result::ok(DictService::items($code));
    }

    /**
     * 批量预热字典
     *
     * `GET /admin/dicts/batch?codes=user_status,enable_status` · **登录即可**
     *
     * 一个页面往往要四五个字典，逐个请求就是四五个往返。前端 store 在页面挂载时
     * 一次把用得到的都取回来，之后同一会话内不再请求。
     *
     * @param Request $request 查询参数：`codes` 逗号分隔的字典编码；空值返回空对象
     *
     * @return Response 200，`{字典编码: [{label, value, tag_type}]}`；不存在的编码直接跳过，不报错
     */
    public function batch(Request $request): Response
    {
        $codes = array_filter(array_map('trim', explode(',', (string) $request->get('codes', ''))));

        return Result::ok(DictService::batch($codes));
    }

    // ------------------------------------------------------------ 字典类型

    /**
     * 字典类型列表（分页）
     *
     * `GET /admin/dicts` · 权限点 `sys:dict:list`
     *
     * @param Request $request 查询参数：`keyword` 名称/编码模糊匹配、`status` 0 停用 1 启用
     *
     * @return Response 200，`{list, total, page, page_size}`
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    public function index(ListTypeRequest $request): Response
    {
        return Paginator::response(
            DictService::typeQuery($request->validated()),
            $request->request(),   // 分页与排序参数不在 ListTypeRequest 白名单里，走原始 Request
            sortable: DictService::TYPE_SORTABLE,
            defaultField: 'id',
            defaultOrder: 'asc',
            map: DictService::typeRowMapper(),
        );
    }

    /**
     * 新增字典类型
     *
     * `POST /admin/dicts` · 权限点 `sys:dict:create` · 自动落操作日志
     *
     * @param StoreTypeRequest $request 请求体见 {@see StoreTypeRequest}
     *
     * @return Response 201，返回新建的字典类型（含 id）
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\ConflictException  字典编码已存在（409 + `20501`）
     */
    public function store(StoreTypeRequest $request): Response
    {
        return Result::created(DictService::createType($request->validated())->toArray());
    }

    /**
     * 编辑字典类型
     *
     * `PUT /admin/dicts/{id}` · 权限点 `sys:dict:update` · 自动落操作日志
     *
     * **已有字典项时不允许改编码**：编码是字典项的外键，改了等于把下面所有项孤立掉，
     * 而引用它的业务数据仍然存着旧编码。
     *
     * @param UpdateTypeRequest $request 请求体见 {@see UpdateTypeRequest}
     * @param int               $id      字典类型 ID
     *
     * @return Response 200，返回更新后的字典类型
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\NotFoundException 字典不存在（404 + `10404`）
     * @throws \app\common\exception\ConflictException  编码已被占用，或该字典下已有项、编码不可改（409 + `20501` / `20502`）
     */
    public function update(UpdateTypeRequest $request, int $id): Response
    {
        return Result::ok(DictService::updateType($id, $request->validated())->toArray());
    }

    /**
     * 删除字典类型
     *
     * `DELETE /admin/dicts/{id}` · 权限点 `sys:dict:delete` · 自动落操作日志
     *
     * @param Request $request 无请求体
     * @param int     $id      字典类型 ID
     *
     * @return Response 204，无响应体
     *
     * @throws \app\common\exception\NotFoundException 字典不存在（404 + `10404`）
     * @throws \app\common\exception\ConflictException  该字典下还有字典项（409 + `20502`）
     */
    public function destroy(Request $request, int $id): Response
    {
        DictService::deleteType($id);

        return Result::noContent();
    }

    // ------------------------------------------------------------ 字典项

    /**
     * 字典项列表（分页，维护界面用）
     *
     * `GET /admin/dicts/{code}/items/all` · 权限点 `sys:dict:list`
     *
     * 与 {@see self::items()} 的区别：这里**含停用项**，并且每项带 `ref_count`
     * （被多少条业务数据引用），管理员据此判断能不能删。
     *
     * 路由把 `{code}` 段限定为编码字符，否则 `/dicts/12` 这样的 id 路径会被它一起吃掉。
     *
     * @param Request $request 查询参数：`keyword` 文案/值模糊匹配、`status` 0 停用 1 启用
     * @param string  $code    字典编码
     *
     * @return Response 200，`{list, total, page, page_size}`
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    public function allItems(ListItemRequest $request, string $code): Response
    {
        return Paginator::response(
            DictService::itemQuery($code, $request->validated()),
            $request->request(),   // 分页与排序参数不在 ListItemRequest 白名单里，走原始 Request
            sortable: DictService::ITEM_SORTABLE,
            defaultField: 'sort',
            defaultOrder: 'asc',
            map: DictService::itemRowMapper(),
        );
    }

    /**
     * 新增字典项
     *
     * `POST /admin/dict-items` · 权限点 `sys:dict:create` · 自动落操作日志
     *
     * @param StoreItemRequest $request 请求体见 {@see StoreItemRequest}
     *
     * @return Response 201，返回新建的字典项（含 id）
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\NotFoundException 所属字典不存在（404 + `10404`）
     */
    public function storeItem(StoreItemRequest $request): Response
    {
        return Result::created(DictService::createItem($request->validated())->toArray());
    }

    /**
     * 编辑字典项
     *
     * `PUT /admin/dict-items/{id}` · 权限点 `sys:dict:update` · 自动落操作日志
     *
     * **已被引用时不允许改 `value`**：业务表里存的是这个值，改了等于让历史数据
     * 指向一个不存在的选项，而界面上只会显示成空白。改文案（`label`）不受限制。
     *
     * @param UpdateItemRequest $request 请求体见 {@see UpdateItemRequest}
     * @param int               $id      字典项 ID
     *
     * @return Response 200，返回更新后的字典项
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\NotFoundException 字典项不存在（404 + `10404`）
     * @throws \app\common\exception\ConflictException  已被 N 条数据引用，值不可修改（409 + `20502`）
     */
    public function updateItem(UpdateItemRequest $request, int $id): Response
    {
        return Result::ok(DictService::updateItem($id, $request->validated())->toArray());
    }

    /**
     * 删除字典项
     *
     * `DELETE /admin/dict-items/{id}` · 权限点 `sys:dict:delete` · 自动落操作日志
     *
     * @param Request $request 无请求体
     * @param int     $id      字典项 ID
     *
     * @return Response 204，无响应体
     *
     * @throws \app\common\exception\NotFoundException 字典项不存在（404 + `10404`）
     * @throws \app\common\exception\ConflictException  已被 N 条数据引用，无法删除（409 + `20502`）
     */
    public function destroyItem(Request $request, int $id): Response
    {
        DictService::deleteItem($id);

        return Result::noContent();
    }

    /**
     * 批量删除字典项
     *
     * `POST /admin/dict-items/batch-delete` · 权限点 `sys:dict:delete` · 自动落操作日志
     *
     * 逐条尽力执行，不是一个事务：被引用的那几条会失败，其余照常删除，
     * 失败明细逐条返回。整批回滚会让「勾了 20 个、1 个删不掉就全不删」，只能反复试。
     *
     * @param Request $request 请求体：`ids` 字典项 ID 数组；空数组直接返回全零结果
     *
     * @return Response 200，`{total, success, failed, failures:[{id, message}]}`
     */
    public function batchDestroyItem(Request $request): Response
    {
        $ids = array_filter(array_map('intval', (array) $request->post('ids', [])));
        if (!$ids) {
            return Result::ok(BatchResult::make()->toArray());
        }

        // 批量操作的日志对象是整批 id：service 里逐条设置的话，
        // 最后只会留下最后一条，审计时看不出这次动了哪些
        OpLog::target('字典项 ' . implode(',', $ids));

        return Result::ok(
            BatchResult::run($ids, fn (int $id) => DictService::deleteItem($id))->toArray()
        );
    }
}
