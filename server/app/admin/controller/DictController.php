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

    /** 单个字典的选项 */
    public function items(Request $request, string $code): Response
    {
        return Result::ok(DictService::items($code));
    }

    /** 批量预热：/admin/dicts/batch?codes=user_status,enable_status */
    public function batch(Request $request): Response
    {
        $codes = array_filter(array_map('trim', explode(',', (string) $request->get('codes', ''))));

        return Result::ok(DictService::batch($codes));
    }

    // ------------------------------------------------------------ 字典类型

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

    public function store(Request $request): Response
    {
        return Result::created(DictService::createType(self::validateType($request))->toArray());
    }

    public function update(Request $request, int $id): Response
    {
        return Result::ok(DictService::updateType($id, self::validateType($request))->toArray());
    }

    public function destroy(Request $request, int $id): Response
    {
        DictService::deleteType($id);

        return Result::noContent();
    }

    // ------------------------------------------------------------ 字典项

    /** 维护界面用：含停用项，带 ref_count */
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

    public function storeItem(Request $request): Response
    {
        return Result::created(DictService::createItem(self::validateItem($request))->toArray());
    }

    public function updateItem(Request $request, int $id): Response
    {
        return Result::ok(DictService::updateItem($id, self::validateItem($request))->toArray());
    }

    public function destroyItem(Request $request, int $id): Response
    {
        DictService::deleteItem($id);

        return Result::noContent();
    }

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

    private static function validateType(Request $request): array
    {
        return Validator::make($request->all(), [
            'name'   => ['required|string|max:64', '字典名称'],
            'code'   => ['required|code|max:64',   '字典编码'],
            'status' => ['int|in:0,1',             '状态'],
            'remark' => ['string|max:255',         '备注'],
        ])->validated();
    }

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
