<?php
/**
 * keel admin
 * 会话 —— im_conversations
 *
 * 类名用 `Im*Model` 而不是 `Sys*Model`：`sys_` 前缀留给框架自己的表，
 * 业务表用自己的前缀（database.md §1），模型名跟着表名走。
 *
 * ⚠️ **不挂 `HasDataScope`**，与 `SysNoticeReadModel` 同理：会话不属于任何部门，
 * 也没有部门列可过滤。按部门过滤会让「总部给分公司发消息」直接断掉，
 * 而那恰恰是内部 IM 最基本的用法。可见性由 `ImConversationMemberModel` 决定。
 *
 * 不用软删：解散群是 `status=0`，删会话是成员侧抬 `min_seq`。
 * 真正的物理删除只发生在留存期清理。
 *
 * @property int         $id            主键
 * @property int         $type          1单聊 2群聊
 * @property string|null $peer_key      单聊唯一键 min:max，群聊为 NULL
 * @property string      $name          群名称
 * @property string      $avatar        群头像
 * @property int         $owner_id      群主
 * @property int         $member_count  成员数
 * @property int         $max_seq       会话内最大消息序号
 * @property int         $last_msg_id   最后一条消息 ID
 * @property Carbon|null $last_msg_at   最后一条消息时间
 * @property string      $last_msg_text 最后一条消息摘要
 * @property int         $status        0已解散 1正常
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class ImConversationModel extends BaseModel
{
    protected $table = 'im_conversations';

    public const TYPE_SINGLE = 1;
    public const TYPE_GROUP  = 2;

    public const STATUS_DISBANDED = 0;
    public const STATUS_NORMAL    = 1;

    protected $casts = [
        'type'         => 'integer',
        'owner_id'     => 'integer',
        'member_count' => 'integer',
        'max_seq'      => 'integer',
        'last_msg_id'  => 'integer',
        'status'       => 'integer',
        'last_msg_at'  => 'datetime',
    ];

    /**
     * 单聊的唯一键：小 id 在前，大 id 在后
     *
     * 用一个键而不是两行记录：`12:87` 无论谁发起都是同一个值，
     * 配合 `uk_peer` 唯一索引，从数据库层面保证「一对人只有一个会话」。
     * 先查后插挡不住两个人互相同时点「发消息」——这在配合前端重试时真会发生。
     */
    public static function peerKey(int $a, int $b): string
    {
        return min($a, $b) . ':' . max($a, $b);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ImConversationMemberModel::class, 'conv_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ImMessageModel::class, 'conv_id');
    }
}
