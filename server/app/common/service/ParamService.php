<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\model\SysParamModel;
use app\common\support\Cache;

/**
 * 系统参数读取
 *
 * ⚠️ 参数只落库 + 走缓存，**绝不在运行期改 webman 配置**：
 * 常驻内存下 `config()` 改了只影响当前 worker，多进程之间立刻不一致，
 * 表现为「同一个操作有时按新值有时按旧值」，极难排查（PROJECT.md §14）。
 *
 * 参数的增删改在 M2.5，这里先提供读取——角色数上限等校验现在就要用。
 */
class ParamService
{
    private const TTL = 300;
    private const KEY = 'param:';

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
}
