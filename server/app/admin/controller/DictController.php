<?php
/**
 * keel admin
 * 数据字典
 *
 * 读接口（items / batch）只要登录态：字典是全站下拉与状态标签的基础数据，
 * 要求 `sys:dict:list` 会让没有字典管理权限的账号连状态色都渲染不出来（api.md §8）。
 * 维护接口才走 `sys:dict:*`。
 *
 * 本模块通用，各方法不再重复：权限点声明在 `config/route.php`，不写即 403（fail-closed）；
 * 入参校验见 `app\admin\validation\Dict\*`，失败一律 422 + 字段级 `details`；
 * 写操作自动落操作日志。错误码表见 docs/api.md §2.2。
 *
 * @author 火火
 */
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

class DictController
{
    // ------------------------------------------------------------ 读取（登录态）

    /**
     * 单个字典的可选项
     * @url GET /admin/dicts/{code}/items
     * @perm -
     * @description 登录即可，不要求 `sys:dict:list`——这是有意的：字典是全站下拉与状态标签的
     * 基础数据，要求管理权限会让普通账号连状态色都渲染不出来（api.md §8）。
     * 只返回启用中的项，供 `<DictSelect>` / `<DictTag>` 渲染。
     * @error 404 `10404` 字典不存在或已停用
     */
    public function items(Request $request, string $code): Response
    {
        return Result::ok(DictService::items($code));
    }

    /**
     * 批量预热字典
     * @url GET /admin/dicts/batch
     * @perm -
     * @description 查询参数 `codes` 逗号分隔，返回 `{字典编码: [...]}`；不存在的编码直接跳过，不报错。
     * 一个页面往往要四五个字典，逐个请求就是四五个往返。前端 store 在页面挂载时
     * 一次把用得到的都取回来，之后同一会话内不再请求。
     */
    public function batch(Request $request): Response
    {
        $codes = array_filter(array_map('trim', explode(',', (string) $request->get('codes', ''))));

        return Result::ok(DictService::batch($codes));
    }

    // ------------------------------------------------------------ 字典类型

    /**
     * 字典类型列表（分页）
     * @url GET /admin/dicts
     * @perm sys:dict:list
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
     * @url POST /admin/dicts
     * @perm sys:dict:create
     * @error 409 `20501` 字典编码已存在
     */
    public function store(StoreTypeRequest $request): Response
    {
        return Result::created(DictService::createType($request->validated())->toArray());
    }

    /**
     * 编辑字典类型
     * @url PUT /admin/dicts/{id}
     * @perm sys:dict:update
     * @description 已有字典项时不允许改编码：编码是字典项的外键，改了等于把下面所有项孤立掉，
     * 而引用它的业务数据仍然存着旧编码。
     * @error 409 `20501` 编码已被占用 · 409 `20502` 该字典下已有项，编码不可改
     */
    public function update(UpdateTypeRequest $request, int $id): Response
    {
        return Result::ok(DictService::updateType($id, $request->validated())->toArray());
    }

    /**
     * 删除字典类型
     * @url DELETE /admin/dicts/{id}
     * @perm sys:dict:delete
     * @error 409 `20502` 该字典下还有字典项
     */
    public function destroy(Request $request, int $id): Response
    {
        DictService::deleteType($id);

        return Result::noContent();
    }

    // ------------------------------------------------------------ 字典项

    /**
     * 字典项列表（分页，维护界面用）
     * @url GET /admin/dicts/{code}/items/all
     * @perm sys:dict:list
     * @description 与 {@see self::items()} 的区别：这里含停用项，并且每项带 `ref_count`
     * （被多少条业务数据引用），管理员据此判断能不能删。
     * 路由把 `{code}` 段限定为编码字符，否则 `/dicts/12` 这样的 id 路径会被它一起吃掉。
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
     * @url POST /admin/dict-items
     * @perm sys:dict:create
     * @error 409 `20501` 同一字典下该值已存在
     */
    public function storeItem(StoreItemRequest $request): Response
    {
        return Result::created(DictService::createItem($request->validated())->toArray());
    }

    /**
     * 编辑字典项
     * @url PUT /admin/dict-items/{id}
     * @perm sys:dict:update
     * @description 已被引用时不允许改 `value`：业务表里存的是这个值，改了等于让历史数据
     * 指向一个不存在的选项，而界面上只会显示成空白。改文案（`label`）不受限制。
     * @error 409 `20501` 同一字典下该值已存在 · 409 `20502` 已被引用，值不可改
     */
    public function updateItem(UpdateItemRequest $request, int $id): Response
    {
        return Result::ok(DictService::updateItem($id, $request->validated())->toArray());
    }

    /**
     * 删除字典项
     * @url DELETE /admin/dict-items/{id}
     * @perm sys:dict:delete
     * @error 409 `20502` 该字典项已被业务数据引用
     */
    public function destroyItem(Request $request, int $id): Response
    {
        DictService::deleteItem($id);

        return Result::noContent();
    }

    /**
     * 批量删除字典项
     * @url POST /admin/dict-items/batch-delete
     * @perm sys:dict:delete
     * @description 请求体 `ids` 数组，返回 `{total, success, failed, failures:[{id, message}]}`。
     * 逐条尽力执行，不是一个事务：被引用的那几条会失败，其余照常删除。
     * 整批回滚会让「勾了 20 个、1 个删不掉就全不删」，只能反复试。
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
