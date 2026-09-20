<?php
/**
 * keel admin
 * 聊天消息 —— im_messages
 *
 * **没有 `updated_at` / `updater_id`**：消息不可编辑。撤回是状态变更，有自己的字段。
 * 审计字段照抄反而误导——看到 `updated_at` 的人会以为消息能改。
 *
 * **没有 `deleted_at`**：删除是成员侧抬 `min_seq`，撤回是 `status=2`。
 * 物理删除只发生在留存期清理，那是 DELETE 不是软删。
 *
 * 撤回**保留 `content`**，只是不再下发：留给以后的审计，也避免误撤回后无法追溯。
 *
 * @property int         $id            主键
 * @property int         $conv_id       会话 ID
 * @property int         $seq           会话内序号，从 1 连续递增
 * @property int         $sender_id     发送人，系统消息为 0
 * @property string      $sender_name   冗余发送人姓名
 * @property string      $type          text/image/file/system
 * @property string      $content       文本内容
 * @property array|null  $extra         附件与扩展
 * @property string      $client_msg_id 客户端幂等 ID
 * @property int         $status        1正常 2已撤回
 * @property int         $recalled_by   撤回人
 * @property Carbon|null $recalled_at   撤回时间
 * @property Carbon      $created_at    发送时间
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class ImMessageModel extends BaseModel
{
    protected $table = 'im_messages';

    /** 消息不可编辑，表里没有这一列——交给 Eloquent 的话它会写一个不存在的列 */
    public const UPDATED_AT = null;

    public const TYPE_TEXT   = 'text';
    public const TYPE_IMAGE  = 'image';
    public const TYPE_FILE   = 'file';
    public const TYPE_SYSTEM = 'system';

    public const STATUS_NORMAL   = 1;
    public const STATUS_RECALLED = 2;

    protected $casts = [
        'conv_id'     => 'integer',
        'seq'         => 'integer',
        'sender_id'   => 'integer',
        'status'      => 'integer',
        'recalled_by' => 'integer',
        'extra'       => 'array',
        'recalled_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ImConversationModel::class, 'conv_id');
    }

    public function auditColumns(): array
    {
        // sender_id 已经是「谁发的」，creator_id 是同一件事说两遍；
        // 而 updater_id/updated_at 这张表压根没有列
        return [];
    }
}
