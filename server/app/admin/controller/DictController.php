<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\DictService;
use app\common\support\BatchResult;
use app\common\support\OpLog;
use app\common\support\Paginator;
use app\common\support\Result;
use app\common\support\Validator;
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
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'keyword' => ['string|max:64', '关键词'],
            'status'  => ['in:0,1',        '状态'],
        ])->validated();

        return Paginator::response(
            DictService::typeQuery($filters),
            $request,
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
     * @param Request $request 请求体见 {@see self::validateType()}
     *
     * @return Response 201，返回新建的字典类型（含 id）
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\ConflictException  字典编码已存在（409 + `20501`）
     */
    public function store(Request $request): Response
    {
        return Result::created(DictService::createType(self::validateType($request))->toArray());
    }

    /**
     * 编辑字典类型
     *
     * `PUT /admin/dicts/{id}` · 权限点 `sys:dict:update` · 自动落操作日志
     *
     * **已有字典项时不允许改编码**：编码是字典项的外键，改了等于把下面所有项孤立掉，
     * 而引用它的业务数据仍然存着旧编码。
     *
     * @param Request $request 请求体见 {@see self::validateType()}
     * @param int     $id      字典类型 ID
     *
     * @return Response 200，返回更新后的字典类型
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\NotFoundException 字典不存在（404 + `10404`）
     * @throws \app\common\exception\ConflictException  编码已被占用，或该字典下已有项、编码不可改（409 + `20501` / `20502`）
     */
    public function update(Request $request, int $id): Response
    {
        return Result::ok(DictService::updateType($id, self::validateType($request))->toArray());
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
    public function allItems(Request $request, string $code): Response
    {
        $filters = Validator::make($request->all(), [
            'keyword' => ['string|max:64', '关键词'],
            'status'  => ['in:0,1',        '状态'],
        ])->validated();

        return Paginator::response(
            DictService::itemQuery($code, $filters),
            $request,
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
     * @param Request $request 请求体见 {@see self::validateItem()}
     *
     * @return Response 201，返回新建的字典项（含 id）
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\NotFoundException 所属字典不存在（404 + `10404`）
     */
    public function storeItem(Request $request): Response
    {
        return Result::created(DictService::createItem(self::validateItem($request))->toArray());
    }

    /**
     * 编辑字典项
     *
     * `PUT /admin/dict-items/{id}` · 权限点 `sys:dict:update` · 自动落操作日志
     *
     * **已被引用时不允许改 `value`**：业务表里存的是这个值，改了等于让历史数据
     * 指向一个不存在的选项，而界面上只会显示成空白。改文案（`label`）不受限制。
     *
     * @param Request $request 请求体见 {@see self::validateItem()}
     * @param int     $id      字典项 ID
     *
     * @return Response 200，返回更新后的字典项
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\NotFoundException 字典项不存在（404 + `10404`）
     * @throws \app\common\exception\ConflictException  已被 N 条数据引用，值不可修改（409 + `20502`）
     */
    public function updateItem(Request $request, int $id): Response
    {
        return Result::ok(DictService::updateItem($id, self::validateItem($request))->toArray());
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

    /**
     * 字典类型的入参校验（新增与编辑共用）
     *
     * @param Request $request 请求体：`name` 字典名称（必填，≤64）、`code` 字典编码（必填，唯一）、
     *                         `status` 0 停用 1 启用、`remark` 备注
     *
     * @return array 只含白名单内字段的数组
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    private static function validateType(Request $request): array
    {
        return Validator::make($request->all(), [
            'name'   => ['required|string|max:64', '字典名称'],
            'code'   => ['required|code|max:64',   '字典编码'],
            'status' => ['int|in:0,1',             '状态'],
            'remark' => ['string|max:255',         '备注'],
        ])->validated();
    }

    /**
     * 字典项的入参校验（新增与编辑共用）
     *
     * `tag_type` 允许空串——没有颜色的字典项渲染成默认灰标签，
     * 强制必填会逼着人给「是/否」这种中性选项硬安一个颜色。
     *
     * @param Request $request 请求体：`type_code` 所属字典编码（必填）、`label` 显示文案（必填）、
     *                         `value` 存储值（必填，业务表里存的就是它）、
     *                         `tag_type` 标签颜色（空 / success / warning / danger / primary / info）、
     *                         `sort` 排序、`status` 0 停用 1 启用、`remark` 备注
     *
     * @return array 只含白名单内字段的数组
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    private static function validateItem(Request $request): array
    {
        return Validator::make($request->all(), [
            'type_code' => ['required|code|max:64',   '所属字典'],
            'label'     => ['required|string|max:64', '显示文案'],
            'value'     => ['required|string|max:64', '存储值'],
            // 空串合法：没有 tag_type 的字典项渲染成默认灰标签
            'tag_type'  => ['string|in:,success,warning,danger,primary,info', '标签颜色'],
            'sort'      => ['int|min:0|max:9999',     '排序'],
            'status'    => ['int|in:0,1',             '状态'],
            'remark'    => ['string|max:255',         '备注'],
        ])->validated();
    }
}
