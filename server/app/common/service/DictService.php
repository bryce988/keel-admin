<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\NotFoundException;
use app\common\model\SysDictItem;
use app\common\model\SysDictType;
use app\common\support\Cache;

/**
 * 数据字典读取
 *
 * 字典是全站高频只读数据，走 Redis 缓存；
 * 字典维护接口（M2）在写入后必须调 forget()，否则改了不生效。
 */
class DictService
{
    private const TTL = 300;
    private const KEY = 'dict:items:';

    /** 某个字典的启用项，按 sort 排序 */
    public static function items(string $code): array
    {
        $cached = Cache::get(self::KEY . $code);
        if ($cached !== null) {
            return json_decode($cached, true) ?: [];
        }

        if (!SysDictType::where('code', $code)->where('status', 1)->exists()) {
            throw new NotFoundException('字典不存在或已停用');
        }

        $items = SysDictItem::where('type_code', $code)
            ->where('status', 1)
            ->orderBy('sort')
            ->get()
            ->map(fn (SysDictItem $item) => [
                'label'   => $item->label,
                'value'   => $item->value,
                'tagType' => $item->tag_type,
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
}
