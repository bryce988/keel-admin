<?php

declare(strict_types=1);

namespace app\admin\validation\Param;

use app\common\validation\FormRequest;

/**
 * 按组批量保存参数（`PUT /admin/params`）
 *
 * 只校验「有 items 且是数组」。条目本身故意不逐条校验：
 * 结构不合法的条目由控制器跳过，不让一整组保存因为某一条而全失败——
 * 同一组参数（比如登录失败次数与锁定时长）逐条保存会留下半新半旧的中间态。
 */
final class BatchUpdateRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'items' => ['required|array', '参数列表'],
        ];
    }
}
