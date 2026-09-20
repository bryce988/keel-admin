<?php
/**
 * keel admin
 * 会话成员 —— im_conversation_members
 *
 * **这张表是整个聊天模块的可见性边界**。所有读写接口的第一件事都是查它：
 * `(conv_id, user_id, quit_at IS NULL)` 命中才继续，否则 404。
 * 全站其他模块靠数据权限 Scope 收敛可见性，聊天靠这张表——
 * 「在不在这个会话里」和「归属哪个部门」是两回事。
 *
 * 退群用 `quit_at` 而不是删行：删了就查不到「他当时在群里」，
 * 审计和「历史消息里显示的昵称」都会失去依据。
 *
 * @property int         $id            主键
 * @property int         $conv_id       会话 ID
 * @property int         $user_id       成员 ID
 * @property int         $role          0成员 1群主
 * @property int         $last_read_seq 已读水位
 * @property int         $min_seq       可见起始序号
 * @property int         $join_seq      入群时的序号
 * @property bool        $is_pinned     置顶
 * @property bool        $is_muted      免打扰
 * @property bool        $is_visible    是否出现在会话列表
 * @property int         $at_seq        最近一次被 @ 的序号
 * @property Carbon|null $quit_at       退群时间，非空表示已不是成员
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class ImConversationMemberModel extends BaseModel
{
    protected $table = 'im_conversation_members';

    public const ROLE_MEMBER = 0;
    public const ROLE_OWNER  = 1;

    protected $casts = [
        'conv_id'       => 'integer',
        'user_id'       => 'integer',
        'role'          => 'integer',
        'last_read_seq' => 'integer',
        'min_seq'       => 'integer',
        'join_seq'      => 'integer',
        'is_pinned'     => 'boolean',
        'is_muted'      => 'boolean',
        'is_visible'    => 'boolean',
        'at_seq'        => 'integer',
        'quit_at'       => 'datetime',
    ];

    /** 仍在会话里的成员。退群的行要留着（审计与历史昵称），但不该出现在任何业务查询里 */
    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('quit_at');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ImConversationModel::class, 'conv_id');
    }

    public function auditColumns(): array
    {
        // 成员行就是「谁在这个会话里」，再存一遍 creator_id 是重复（与 SysNoticeReadModel 同）
        return [];
    }
}
