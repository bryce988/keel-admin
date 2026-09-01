<?php
/**
 * keel admin
 * 系统公告 —— sys_notices
 *
 * 全员可见，**不挂 HasDataScope**：公告的受众就是所有登录用户，
 * 按部门过滤会让「总部发的通知分公司看不到」，而这恰恰是公告最没用的一种失败。
 * 管理端列表同理——公告不属于任何部门，没有归属可过滤。
 *
 * 也不用软删：公告删掉就是不该再出现在任何人的消息里，
 * 已读回执由 NoticeService 一并清掉（BaseModel 顶部那段说明了软删的适用边界）。
 *
 * 字段说明以 database/schema.sql 的列注释为准，改表结构时两边一起改。
 *
 * @property int     $id             主键
 * @property string  $title          标题
 * @property string  $content        正文（富文本 HTML，入库前已净化）
 * @property string  $type           类型，取值来自字典 notice_type
 * @property int     $status         0 草稿 · 1 已发布
 * @property ?Carbon $published_at   发布时间，草稿为 null
 * @property int     $publisher_id   发布人
 * @property string  $publisher_name 发布人姓名（冗余）
 * @property int     $creator_id     创建人
 * @property int     $updater_id     最后修改人
 * @property Carbon  $created_at     创建时间
 * @property Carbon  $updated_at     更新时间
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use Illuminate\Support\Carbon;

class SysNoticeModel extends BaseModel
{
    /**
     * 状态不用 HasStatus
     *
     * 那个 trait 的语义是「启用 / 停用」，这里是「草稿 / 已发布」——
     * 值虽然同为 0/1，但 `enabled()` 这种方法名套在公告上会读成
     * 「启用的公告」，而真正的含义是「已经发出去、别人看得见的公告」。
     * 字典也因此单开一份 `notice_status`，没有复用 `enable_status`。
     */
    public const STATUS_DRAFT     = 0;
    public const STATUS_PUBLISHED = 1;

    protected $table = 'sys_notices';

    protected $casts = [
        'status'       => 'integer',
        'publisher_id' => 'integer',
        'published_at' => 'datetime',
    ];

    /** 已发布且发布时间已到的公告 */
    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED)->whereNotNull('published_at');
    }
}
