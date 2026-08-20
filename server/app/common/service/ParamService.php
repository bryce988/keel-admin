<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\constant\BizCode;
use app\common\model\SysParamModel;
use app\common\support\Cache;
use app\common\support\Db;
use app\common\support\Guard;
use app\common\support\OpLog;

/**
 * 系统参数
 *
 * ⚠️ 参数只落库 + 走缓存，**绝不在运行期改 webman 配置**：
 * 常驻内存下 `config()` 改了只影响当前 worker，多进程之间立刻不一致，
 * 表现为「同一个操作有时按新值有时按旧值」，极难排查（PROJECT.md §14）。
 */
class ParamService
{
    private const TTL = 300;
    private const KEY = 'param:';

    /** 界面 tab 的顺序与文案，也用来校验分组取值 */
    public const GROUPS = [
        'basic'       => '基础设置',
        'security'    => '安全策略',
        'integration' => '第三方集成',
        'advanced'    => '高级选项',
    ];

    /**
     * 密钥掩码
     *
     * `is_secret` 的参数**只写不读**：读接口一律返回这个常量，
     * 保存时值等于它就跳过更新。所以「不改密钥」与「把密钥改成 ******」
     * 在协议上是同一件事——后者本来也不该是一个合法密钥。
     */
    public const MASK = '******';

    /** 登录页等未登录场景可见的参数，白名单外一律不下发 */
    private const PUBLIC_KEYS = ['sys.name', 'sys.logo', 'sys.footer'];

    // ------------------------------------------------------------ 读取

    public static function value(string $key, mixed $default = null): mixed
    {
        $cached = Cache::get(self::KEY . $key);
        if ($cached !== null) {
            return $cached === '' ? $default : $cached;
        }

        $param = SysParamModel::query()->where('param_key', $key)->first();
        $value = $param?->typedValue();

        // 不存在也缓存空串，避免每次请求都回表找一个不存在的键
        Cache::set(self::KEY . $key, $value === null ? '' : (string) $value, self::TTL);

        return $value ?? $default;
    }

    public static function forget(string $key): void
    {
        Cache::del(self::KEY . $key);
    }

    /** 登录页用：不需要登录态，只返回白名单里的键 */
    public static function publicParams(): array
    {
        $rows = SysParamModel::query()
            ->whereIn('param_key', self::PUBLIC_KEYS)
            ->where('is_secret', 0)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->param_key] = $row->typedValue();
        }

        return $out;
    }

    // ------------------------------------------------------------ 维护

    /**
     * 按分组取参数（维护界面用）
     *
     * 不分页：每组十来条，一次给全，前端一个 tab 就是一张表单。
     */
    public static function listByGroup(string $group): array
    {
        $query = SysParamModel::query()->orderBy('param_key');

        if ($group !== '') {
            $query->where('group', $group);
        }

        return $query->get()->map(fn (SysParamModel $row) => self::rowToArray($row))->all();
    }

    public static function detail(int $id): array
    {
        /** @var SysParamModel $param */
        $param = Guard::found(SysParamModel::find($id));

        return self::rowToArray($param);
    }

    /**
     * 批量保存 [{param_key, param_value}, ...]
     *
     * 一个 tab 一次提交，整组落在同一个事务里：改一半失败会让「安全策略」
     * 处在半新半旧的状态，而这组参数彼此相关（锁定次数与锁定时长）。
     *
     * @return int 实际写入的条数（值没变的与掩码占位的都不算）
     */
    public static function saveMany(array $items): int
    {
        $keys = array_values(array_filter(array_map(
            static fn (array $i) => trim((string) ($i['param_key'] ?? '')),
            $items
        )));

        if (!$keys) {
            return 0;
        }

        /** @var array<string, SysParamModel> $params */
        $params = SysParamModel::query()->whereIn('param_key', $keys)->get()->keyBy('param_key')->all();

        $changed = [];

        $saved = Db::transaction(function () use ($items, $params, &$changed) {
            $count = 0;

            foreach ($items as $item) {
                $key = trim((string) ($item['param_key'] ?? ''));
                $param = $params[$key] ?? null;

                // 未知键静默跳过而不是报错：前端可能提交整组表单，
                // 其中某条刚被别人删掉，不该因此把其余的改动一起回滚
                if ($key === '' || !$param) {
                    continue;
                }

                $value = (string) ($item['param_value'] ?? '');

                // 密钥没被真正编辑过（界面回填的就是掩码），保持原值
                if ($param->is_secret && $value === self::MASK) {
                    continue;
                }

                if ($value === $param->param_value) {
                    continue;
                }

                // 变更明细里不能出现密钥的明文——操作日志是给人看的，
                // 一旦写进去就等于把密钥抄到了日志表。
                // 前后值不能都写成掩码：diff 只记真正变化的字段，
                // 那样这次改动会从审计里彻底消失
                $changed[$key] = $param->is_secret
                    ? [self::MASK, self::MASK . '（已更新）']
                    : [$param->param_value, $value];

                $param->param_value = $value;
                $param->save();
                $count++;
            }

            return $count;
        });

        // 缓存在事务提交之后才清：事务里清了但事务回滚，缓存会被回填成旧值，
        // 而那之后没有任何人再去清它
        foreach (array_keys($changed) as $key) {
            self::forget($key);
        }

        OpLog::target('系统参数 ' . ($changed ? implode(',', array_keys($changed)) : '无变更'));
        OpLog::diff(
            array_map(static fn (array $p) => $p[0], $changed),
            array_map(static fn (array $p) => $p[1], $changed),
        );

        return $saved;
    }

    public static function create(array $data): array
    {
        Guard::unique(SysParamModel::class, 'param_key', $data['param_key'], null, '参数键已存在', BizCode::PARAM_KEY_EXISTS);

        return Db::transaction(function () use ($data) {
            $param = new SysParamModel();
            $param->fill($data);
            $param->is_builtin = false;   // 内置标记只由 seed 写，界面新增的一律是自定义参数
            $param->save();

            OpLog::target("参数 {$param->param_key}");
            self::forget($param->param_key);

            // 出参走 rowToArray：直接 toArray() 会把刚写进去的密钥原样回显，
            // 破坏「任何返回路径都是掩码」这条不变量
            return self::rowToArray($param);
        });
    }

    /**
     * 编辑参数
     *
     * 内置参数只让改**值**：键、类型、分组都被代码按名字读取（`ParamService::value('sys.pwd.minLength')`），
     * 改了那些等于让代码读到 null，而调用点全都有默认值兜底，故障会以「配置怎么不生效」的形式出现。
     */
    public static function update(int $id, array $data): array
    {
        /** @var SysParamModel $param */
        $param = Guard::found(SysParamModel::find($id));

        if ($param->is_builtin) {
            $data = ['param_value' => $data['param_value'] ?? $param->param_value, 'remark' => $data['remark'] ?? $param->remark];
        } elseif (isset($data['param_key'])) {
            Guard::unique(SysParamModel::class, 'param_key', $data['param_key'], $id, '参数键已存在', BizCode::PARAM_KEY_EXISTS);
        }

        if ($param->is_secret && ($data['param_value'] ?? null) === self::MASK) {
            unset($data['param_value']);
        }

        $before = self::rowToArray($param);
        $oldKey = $param->param_key;

        return Db::transaction(function () use ($param, $data, $before, $oldKey) {
            $param->fill($data);
            $param->save();

            OpLog::target("参数 {$param->param_key}");
            OpLog::diff($before, self::rowToArray($param));

            self::forget($oldKey);
            self::forget($param->param_key);

            return self::rowToArray($param);
        });
    }

    public static function delete(int $id): void
    {
        /** @var SysParamModel $param */
        $param = Guard::found(SysParamModel::find($id));

        Guard::notBuiltin($param, '内置参数不可删除', BizCode::BUILTIN_PARAM_PROTECTED);

        OpLog::target("参数 {$param->param_key}");

        $key = $param->param_key;
        $param->delete();
        self::forget($key);
    }

    /** 出参统一走这里，保证密钥在**任何**返回路径上都是掩码 */
    private static function rowToArray(SysParamModel $row): array
    {
        return [
            'id'          => $row->id,
            'group'       => $row->group,
            'name'        => $row->name,
            'param_key'   => $row->param_key,
            'param_value' => $row->is_secret ? self::MASK : $row->param_value,
            'value_type'  => $row->value_type,
            'is_builtin'  => $row->is_builtin,
            'is_secret'   => $row->is_secret,
            'remark'      => $row->remark,
            'updated_at'  => $row->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
