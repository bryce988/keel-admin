<?php

declare(strict_types=1);

namespace app\common\support;

/**
 * 操作日志的补充信息
 *
 * 中间件负责「什么时候记、记谁、记多久」，
 * service 层负责「记的是哪条数据、改了哪些字段」——后者只有业务代码知道，
 * 通过请求上下文传给中间件，避免把 Request 一路透传到 service。
 */
final class OpLog
{
    /** 操作对象标识，如 "用户 zhangsan(12)" */
    public static function target(string $target): void
    {
        Ctx::set('log.target', $target);
    }

    /** 直接指定字段级变更 */
    public static function changes(array $changes): void
    {
        Ctx::set('log.changes', $changes);
    }

    /**
     * 比对前后快照，只记真正变化的字段
     *
     * @param  array  $before  修改前的快照，用 $model->toArray()
     * @param  array  $after   修改后
     * @param  array  $ignore  不记录的字段，默认排除时间戳与密码
     */
    public static function diff(array $before, array $after, array $ignore = []): void
    {
        $ignore = array_merge(['updated_at', 'created_at', 'password', 'updater_id'], $ignore);
        $changes = [];

        foreach ($after as $field => $newValue) {
            if (in_array($field, $ignore, true)) {
                continue;
            }
            $oldValue = $before[$field] ?? null;
            // 松散比较：数据库取回是字符串 "1"，入参是 int 1，不该记成变更
            if ((string) self::flatten($oldValue) === (string) self::flatten($newValue)) {
                continue;
            }
            $changes[] = [
                'field' => $field,
                'old'   => self::flatten($oldValue),
                'new'   => self::flatten($newValue),
            ];
        }

        if ($changes) {
            self::changes($changes);
        }
    }

    private static function flatten(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return $value;
    }
}
