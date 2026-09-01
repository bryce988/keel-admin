<?php
/**
 * keel admin
 * 公告已读回执 —— sys_notice_reads
 *
 * 一条公告 + 一个人 = 至多一行（uk_notice_user）。没有 updated_at：
 * 回执只会被创建和删除，「又读了一遍」不是需要记录的事实。
 *
 * @property int    $id         主键
 * @property int    $notice_id  公告 ID
 * @property int    $user_id    阅读人
 * @property Carbon $created_at 已读时间
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use Illuminate\Support\Carbon;

class SysNoticeReadModel extends BaseModel
{
    protected $table = 'sys_notice_reads';

    /** 只有 created_at，没有 updated_at —— 交给 Eloquent 的话它会写一个不存在的列 */
    public const UPDATED_AT = null;

    protected $casts = [
        'notice_id' => 'integer',
        'user_id'   => 'integer',
    ];

    public function auditColumns(): array
    {
        return [];   // 回执本身就是「谁读的」，再存一遍 creator_id 是重复
    }
}
