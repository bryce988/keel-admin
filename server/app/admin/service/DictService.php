<?php
/**
 * keel admin
 * 数据字典
 *
 * 字典是全站高频只读数据，走 Redis 缓存；
 * 所有写入路径都必须 forget()，否则改了要等 5 分钟才生效——
 * 表现为「明明改了标签颜色，界面就是老样子」，而且过一会儿又好了。
 * 为了不漏，缓存失效统一收口在本类的 create/update/delete 里，
 * 控制器与前端都不需要知道缓存的存在。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\service;

use app\common\constant\BizCode;
use app\common\exception\ConflictException;
use app\common\exception\NotFoundException;
use app\common\model\SysDeptModel;
use app\common\model\SysDictItemModel;
use app\common\model\SysDictTypeModel;
use app\common\model\SysLoginLogModel;
use app\common\model\SysOperationLogModel;
use app\common\model\SysPermissionModel;
use app\common\model\SysPostModel;
use app\common\model\SysRoleModel;
use app\common\model\SysUserModel;
use app\common\support\Cache;
use app\common\support\Db;
use app\common\support\Guard;
use app\common\support\OpLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class DictService
{
    private const TTL = 300;
    private const KEY = 'dict:items:';

    public const TYPE_SORTABLE = ['id', 'code', 'status', 'created_at'];
    public const ITEM_SORTABLE = ['id', 'sort', 'value', 'status', 'created_at'];

    /**
     * 字典项被哪些业务列引用
     *
     * 判断「这个 value 还能不能改」需要知道谁在用它。数据库里没有外键——
     * 字典值是散落在各表的 TINYINT，靠约定关联，查不出来。所以这里做一份显式登记：
     * 没登记的字典（如 common_status、yes_no 这类给业务表自由取用的）默认放行，
     * 登记了的就按行数拦。
     *
     * 新增业务表若消费了某个字典，记得往这里补一行，否则改值不会被拦住。
     *
     * @var array<string, list<array{0: class-string, 1: string}>>
     */
    private const USAGE = [
        'enable_status' => [
            [SysDeptModel::class, 'status'],
            [SysPostModel::class, 'status'],
            [SysRoleModel::class, 'status'],
            [SysPermissionModel::class, 'status'],
        ],
        'user_status'   => [[SysUserModel::class, 'status']],
        'data_scope'    => [[SysRoleModel::class, 'data_scope']],
        'perm_type'     => [[SysPermissionModel::class, 'type']],
        'log_action'    => [[SysOperationLogModel::class, 'action']],
        'log_status'    => [[SysOperationLogModel::class, 'status']],
        'login_type'    => [[SysLoginLogModel::class, 'type']],
    ];

    // ------------------------------------------------------------ 读取（全站）

    /** 某个字典的启用项，按 sort 排序 */
    public static function items(string $code): array
    {
        $cached = Cache::get(self::KEY . $code);
        if ($cached !== null) {
            return json_decode($cached, true) ?: [];
        }

        if (!SysDictTypeModel::query()->where('code', $code)->enabled()->exists()) {
            throw new NotFoundException('字典不存在或已停用');
        }

        $items = SysDictItemModel::where('type_code', $code)
            ->enabled()
            ->orderBy('sort')
            ->get()
            ->map(fn (SysDictItemModel $item) => [
                'label'    => $item->label,
                'value'    => $item->value,
                'tag_type' => $item->tag_type,
            ])
            ->all();

        Cache::set(self::KEY . $code, json_encode($items, JSON_UNESCAPED_UNICODE), self::TTL);

        return $items;
    }

    /** 一次取多个，前端首屏预热用，避免十几个并发小请求 */
    public static function batch(array $codes): array
    {
        $out = [];
        foreach (array_unique($codes) as $code) {
            try {
                $out[$code] = self::items((string) $code);
            } catch (NotFoundException) {
                $out[$code] = [];   // 不存在的字典返回空数组，不让整批失败
            }
        }

        return $out;
    }

    public static function forget(string $code): void
    {
        Cache::del(self::KEY . $code);
    }

    // ------------------------------------------------------------ 类型维护

    public static function typeQuery(array $filters): Builder
    {
        $query = SysDictTypeModel::query();

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")->orWhere('code', 'like', "%{$keyword}%");
            });
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('status', (int) $filters['status']);
        }

        return $query;
    }

    /**
     * 字典类型列表的整页映射
     *
     * 原先是逐行 `SysDictItemModel::where('type_code', $row->code)->count()`，
     * 注释里写着「字典类型不多，逐行 count 更直白」——实测一页 10 个类型就是
     * 14 条 SQL，其中 10 条是这个 count。分页把每行一次查询伪装成了常数开销，
     * 但它是 O(页大小)，`page_size=100` 时就是 100 条。
     *
     * 现在整页一条 GROUP BY 拿全部计数。
     */
    public static function typeRowsMapper(): callable
    {
        return function (Collection $rows): array {
            $codes = $rows->pluck('code')->all();

            $counts = $codes === [] ? [] : SysDictItemModel::query()
                ->whereIn('type_code', $codes)
                ->groupBy('type_code')
                ->selectRaw('COUNT(*) AS c')
                ->addSelect('type_code')
                ->pluck('c', 'type_code')
                ->all();

            return $rows->map(fn (SysDictTypeModel $row): array => [
                'id'         => $row->id,
                'name'       => $row->name,
                'code'       => $row->code,
                'status'     => $row->status,
                'remark'     => $row->remark,
                // 没有任何字典项的类型不会出现在 GROUP BY 结果里，兜底 0
                'item_count' => (int) ($counts[$row->code] ?? 0),
                'created_at' => $row->created_at?->format('Y-m-d H:i:s'),
            ])->all();
        };
    }

    public static function createType(array $data): SysDictTypeModel
    {
        Guard::unique(SysDictTypeModel::class, 'code', $data['code'], null, '字典编码已存在', BizCode::DICT_CODE_EXISTS);

        return Db::transaction(function () use ($data) {
            $type = new SysDictTypeModel();
            $type->fill($data);
            $type->save();

            OpLog::target("字典 {$type->name}({$type->code})");
            self::forget($type->code);

            return $type;
        });
    }

    /**
     * 编辑字典类型
     *
     * `code` 是字典项的关联键（sys_dict_items.type_code），改了就等于把所有项孤儿化，
     * 所以有项的类型不许改编码——这跟字典项 value 不可改是同一类约束。
     */
    public static function updateType(int $id, array $data): SysDictTypeModel
    {
        /** @var SysDictTypeModel $type */
        $type = Guard::found(SysDictTypeModel::find($id));

        Guard::unique(SysDictTypeModel::class, 'code', $data['code'], $id, '字典编码已存在', BizCode::DICT_CODE_EXISTS);

        $oldCode = $type->code;
        if ($data['code'] !== $oldCode && SysDictItemModel::where('type_code', $oldCode)->exists()) {
            throw new ConflictException('该字典下已有字典项，编码不可修改', BizCode::DICT_ITEM_IN_USE);
        }

        $before = $type->toArray();

        return Db::transaction(function () use ($type, $data, $before, $oldCode) {
            $type->fill($data);
            $type->save();

            OpLog::target("字典 {$type->name}({$type->code})");
            OpLog::diff($before, $type->toArray());

            // 停用类型会让 items() 抛 404，缓存里的旧数据必须一起清掉；
            // 改了编码时新旧两个键都清，否则旧键会一直命中到过期
            self::forget($oldCode);
            self::forget($type->code);

            return $type;
        });
    }

    public static function deleteType(int $id): void
    {
        /** @var SysDictTypeModel $type */
        $type = Guard::found(SysDictTypeModel::find($id));

        Guard::notReferenced(
            SysDictItemModel::class,
            'type_code',
            $type->code,
            '该字典下还有字典项，请先删除字典项', BizCode::DICT_ITEM_IN_USE);

        OpLog::target("字典 {$type->name}({$type->code})");

        $type->delete();
        self::forget($type->code);
    }

    // ------------------------------------------------------------ 字典项维护

    /** 维护界面用：含停用项，且带上被引用次数 */
    public static function itemQuery(string $typeCode, array $filters): Builder
    {
        $query = SysDictItemModel::query()->where('type_code', $typeCode);

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('label', 'like', "%{$keyword}%")->orWhere('value', 'like', "%{$keyword}%");
            });
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('status', (int) $filters['status']);
        }

        return $query;
    }

    /**
     * 字典项列表的整页映射
     *
     * 这里是本类里 N+1 最严重的一处：`refCount()` 会遍历 `USAGE[type_code]`，
     * 逐行调用意味着 **每行 × 登记的模型数** 条查询。`enable_status` 登记了 4 个模型，
     * 一页 20 行就是 80 条 SQL——而列表本身只有 2 条。
     *
     * 改成整页批量：每个登记的模型一条 GROUP BY，与页大小无关。
     */
    public static function itemRowsMapper(): callable
    {
        return function (Collection $rows): array {
            $typeCode = (string) ($rows->first()?->type_code ?? '');
            $refs     = self::refCounts($typeCode, $rows->pluck('value')->all());

            return $rows->map(fn (SysDictItemModel $row): array => [
                'id'        => $row->id,
                'type_code' => $row->type_code,
                'label'     => $row->label,
                'value'     => $row->value,
                'tag_type'  => $row->tag_type,
                'sort'      => $row->sort,
                'status'    => $row->status,
                'remark'    => $row->remark,
                // 界面据此把「值」输入框置灰，用户在提交前就知道改不了，
                // 而不是填完点保存才收到 409
                'ref_count' => $refs[$row->value] ?? 0,
                'created_at' => $row->created_at?->format('Y-m-d H:i:s'),
            ])->all();
        };
    }

    public static function createItem(array $data): SysDictItemModel
    {
        $typeCode = (string) $data['type_code'];
        Guard::found(
            SysDictTypeModel::where('code', $typeCode)->first(),
            '字典不存在或已被删除'
        );

        self::assertValueUnique($typeCode, (string) $data['value'], null);

        return Db::transaction(function () use ($data, $typeCode) {
            $item = new SysDictItemModel();
            $item->fill($data);
            $item->save();

            OpLog::target("字典项 {$typeCode}.{$item->value}({$item->label})");
            self::forget($typeCode);

            return $item;
        });
    }

    /**
     * 编辑字典项
     *
     * `value` 是落在业务表里的那个值，一旦有数据用了它就不能再改——
     * 改了等于把已有数据的含义偷偷换掉，而且旧值不会被回溯更新。
     * label / tag_type / sort / status 随便改，那些只影响展示。
     */
    public static function updateItem(int $id, array $data): SysDictItemModel
    {
        /** @var SysDictItemModel $item */
        $item = Guard::found(SysDictItemModel::find($id));

        $newValue = (string) $data['value'];
        if ($newValue !== $item->value) {
            $refs = self::refCount($item->type_code, $item->value);
            if ($refs > 0) {
                throw new ConflictException("该字典项已被 {$refs} 条数据引用，值不可修改", BizCode::DICT_ITEM_IN_USE);
            }

            self::assertValueUnique($item->type_code, $newValue, $id);
        }

        $before = $item->toArray();

        return Db::transaction(function () use ($item, $data, $before) {
            // type_code 不跟着表单走：字典项属于哪个字典由列表的上下文决定，
            // 允许改等于开了一条「把项挪到别的字典」的暗路
            unset($data['type_code']);

            $item->fill($data);
            $item->save();

            OpLog::target("字典项 {$item->type_code}.{$item->value}({$item->label})");
            OpLog::diff($before, $item->toArray());

            self::forget($item->type_code);

            return $item;
        });
    }

    public static function deleteItem(int $id): void
    {
        /** @var SysDictItemModel $item */
        $item = Guard::found(SysDictItemModel::find($id));

        $refs = self::refCount($item->type_code, $item->value);
        if ($refs > 0) {
            throw new ConflictException("该字典项已被 {$refs} 条数据引用，无法删除", BizCode::DICT_ITEM_IN_USE);
        }

        OpLog::target("字典项 {$item->type_code}.{$item->value}({$item->label})");

        $typeCode = $item->type_code;
        $item->delete();
        self::forget($typeCode);
    }

    /**
     * 某个字典值被业务表引用了多少行
     *
     * 未登记的字典返回 0（放行）：登记表是人工维护的，宁可漏拦也不能误拦——
     * 误拦会让用户改不了一个其实没人用的字典项，且无从自证。
     */
    public static function refCount(string $typeCode, string $value): int
    {
        return self::refCounts($typeCode, [$value])[$value] ?? 0;
    }

    /**
     * 一批字典值各自被引用了多少行
     *
     * 每个登记的模型只发一条 `WHERE value IN (...) GROUP BY value`，
     * 查询条数只跟登记表的长度有关，与传入多少个值无关。
     *
     * @param  list<string>  $values
     * @return array<string, int>  值 => 引用行数，传入的每个值都有键（没被引用的是 0）
     */
    public static function refCounts(string $typeCode, array $values): array
    {
        $values = array_values(array_unique($values));
        $out    = array_fill_keys($values, 0);

        if ($values === [] || !isset(self::USAGE[$typeCode])) {
            return $out;
        }

        foreach (self::USAGE[$typeCode] as [$modelClass, $column]) {
            // 数据权限与软删除都要绕过：这里问的是「库里还有没有数据在用」，
            // 跟当前登录人看得见什么无关，软删的行也仍然存着这个值
            //
            // $column 来自上面的 USAGE 常量（开发者写死的），不是请求参数，
            // 但仍然用 addSelect 而不是拼进 selectRaw——少一处将来会被复制走的字符串拼接
            $rows = $modelClass::query()->withoutGlobalScopes()
                ->whereIn($column, $values)
                ->groupBy($column)
                ->selectRaw('COUNT(*) AS c')
                ->addSelect($column)
                ->pluck('c', $column);

            foreach ($rows as $value => $count) {
                // 字典值是 varchar，业务列常是 TINYINT，回来的键可能是 int。
                // PHP 会把数字字符串键统一转成 int，两边因此仍然对得上
                $out[$value] = ($out[$value] ?? 0) + (int) $count;
            }
        }

        return $out;
    }

    /**
     * 字典项的值在同一字典内唯一
     *
     * 不能用 Guard::unique：那是单列唯一，这里的唯一键是 (type_code, value) 复合。
     */
    private static function assertValueUnique(string $typeCode, string $value, ?int $exceptId): void
    {
        $query = SysDictItemModel::query()
            ->withoutGlobalScopes()
            ->where('type_code', $typeCode)
            ->where('value', $value);

        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }

        if ($query->exists()) {
            throw new ConflictException('该字典下已存在相同的值', BizCode::DICT_CODE_EXISTS);
        }
    }
}
