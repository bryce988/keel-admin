<?php

declare(strict_types=1);

namespace app\staff\validation\Notice;

use app\common\validation\FormRequest;

/**
 * 消息列表（`GET /staff/v1/notices`）
 *
 * 只有分页参数——手机上的消息页不做筛选：屏幕就这么大，
 * 加一排筛选条件挤掉的是消息本身，而公告总量本来就不大，往下翻更快。
 */
class ListRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'page_num'  => ['integer|min:1',          '页码'],
            'page_size' => ['integer|min:1|max:100',  '每页条数'],
        ];
    }
}
