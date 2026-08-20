<?php

declare(strict_types=1);

namespace app\common\support;

use app\common\exception\ApiException;

/**
 * 批量操作的结果收集
 *
 * 批量删除十条，其中三条因为「部门下还有用户」删不掉——
 * 整批回滚太粗暴（另外七条本来能删），只报一句「操作失败」又让用户不知道该改哪条。
 * 所以按 api.md §1.4 的约定：逐条尽力执行，返回成功与失败明细。
 *
 *   return Result::ok(BatchResult::run($ids, fn (int $id) => self::deleteOne($id))->toArray());
 *
 * 响应形如：
 *   {
 *     "success_count": 7,
 *     "fail_count": 3,
 *     "succeeded": [1,2,5,...],
 *     "failed": [{ "id": 3, "reason": "部门下存在用户或子部门，无法删除" }]
 *   }
 */
final class BatchResult
{
    /** @var array<int|string> */
    private array $succeeded = [];

    /** @var array<array{id: int|string, reason: string}> */
    private array $failed = [];

    public static function make(): self
    {
        return new self();
    }

    /**
     * 逐条执行，把可预期的业务异常收进失败明细
     *
     * ⚠️ 只吞 ApiException。数据库连不上、代码写错这类 Throwable 必须继续往上抛——
     * 把真正的 bug 伪装成「这条数据删不掉」，是排查时最耗时间的一类误导。
     */
    public static function run(array $ids, callable $handler): self
    {
        $result = self::make();

        foreach ($ids as $id) {
            try {
                $handler($id);
                $result->ok($id);
            } catch (ApiException $e) {
                $result->fail($id, $e->getMessage());
            }
        }

        return $result;
    }

    public function ok(int|string $id): void
    {
        $this->succeeded[] = $id;
    }

    public function fail(int|string $id, string $reason): void
    {
        $this->failed[] = ['id' => $id, 'reason' => $reason];
    }

    public function allFailed(): bool
    {
        return $this->succeeded === [] && $this->failed !== [];
    }

    public function toArray(): array
    {
        return [
            'success_count' => count($this->succeeded),
            'fail_count'    => count($this->failed),
            'succeeded'     => $this->succeeded,
            'failed'        => $this->failed,
        ];
    }
}
