<?php
/**
 * keel admin
 * 即时通讯 —— 会话与消息
 *
 * 设计见 docs/chat-tech.md。后台与员工移动端**共用这一份**，
 * 两端的 controller 只做编排（PROJECT.md §8.2「一个业务规则只有一份实现」）。
 *
 * ## 三条贯穿全文的设计
 *
 * 1. **可见性只有一个入口**：`assertMember()`。每个公开方法的第一行都调它，
 *    没有例外，包括超级管理员。聊天的边界是「在不在这个会话里」，
 *    不是「有没有权限点」，也不是数据权限的部门范围
 * 2. **会话内序号 seq** 是可靠性的地基：客户端发现空洞就补拉，未读数靠它算，
 *    历史翻页靠它做游标。生成方式是事务内行锁自增，理由见 nextSeq()
 * 3. **发消息走 HTTP，WebSocket 只做下行推送**。所以这里不管推送成败——
 *    推丢了客户端下次对齐会补上，可靠性在数据库不在长连接
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use app\common\exception\ForbiddenException;
use app\common\exception\NotFoundException;
use app\common\exception\RateLimitException;
use app\common\constant\BizCode;
use app\common\model\ImConversationMemberModel;
use app\common\model\ImConversationModel;
use app\common\model\ImMessageModel;
use app\common\model\SysUserModel;
use app\common\support\Cache;
use app\common\support\ChatFanout;
use app\common\support\Db;

class ChatService
{
    /** 每人每分钟发送上限。防误操作与防脚本刷，不是反垃圾——内部系统没有陌生人 */
    private const RATE_LIMIT  = 20;
    private const RATE_WINDOW = 60;

    /** 历史消息每页条数，与前端的翻页粒度一致 */
    private const PAGE_SIZE = 30;

    /** 撤回时限（秒）。参数 `chat.message.recallWindow` 可覆盖 */
    private const RECALL_WINDOW = 120;

    /**
     * 附件必须落在这个前缀下
     *
     * 与上传接口 `biz=chat` 的落盘目录一致。写死不做成参数——
     * 可配置的路径白名单等于给了配错的机会，而配错一次就能把
     * 「发消息」变成「让别人的浏览器去下载任意文件」
     */
    private const ATTACHMENT_PREFIX = '/uploads/chat/';

    // ================================================================ 可见性

    /**
     * 可见性校验 —— **整个模块唯一的入口**
     *
     * 每个公开方法的第一行都调它。返回成员行而不是 bool，
     * 因为调用方紧接着几乎总要用到 min_seq / last_read_seq / role。
     *
     * 抛 404 而不是 403：403 等于确认「这个会话存在，只是你进不去」，
     * 配合可枚举的自增 id 就能探出整个会话列表。全站的「用 404 掩盖无权访问」
     * 是同一条约定（docs/api.md §2.1）。
     *
     * ⚠️ **超级管理员也走这里**。`is_super` 在全站是「跳过权限点校验」，
     * 但聊天的边界不是权限点。管理员要看他人聊天记录必须走审计流程，
     * 不能靠身份绕过——这一点在 chat-prd.md §4 里是写死的。
     */
    public static function assertMember(int $convId, int $userId): ImConversationMemberModel
    {
        $member = ImConversationMemberModel::query()
            ->where('conv_id', $convId)
            ->where('user_id', $userId)
            ->whereNull('quit_at')
            ->first();

        if (!$member) {
            throw new NotFoundException();
        }

        return $member;
    }

    // ================================================================ 会话

    /**
     * 打开与某人的单聊：已存在就返回，不存在才建
     *
     * **并发安全靠 uk_peer 唯一索引，不是靠先查后插**：两个人互相同时点
     * 「发消息」真会发生（尤其配合前端重试）。所以插入撞了唯一索引时，
     * 回头把已存在的那条查出来返回——对调用方来说结果完全一样。
     */
    public static function openSingle(int $me, int $peerId): ImConversationModel
    {
        if ($me === $peerId) {
            throw new BusinessException('不能和自己发起会话', BizCode::CHAT_SELF_CONVERSATION);
        }

        /** @var SysUserModel|null $peer */
        $peer = SysUserModel::withoutDataScope()->find($peerId);
        if (!$peer) {
            throw new NotFoundException();
        }
        if ((int) $peer->status !== 1) {
            throw new BusinessException('不能与已停用的员工发起会话', BizCode::CHAT_PEER_DISABLED);
        }

        $key = ImConversationModel::peerKey($me, $peerId);

        $exists = ImConversationModel::query()->where('peer_key', $key)->first();
        if ($exists) {
            // 会话在但成员行可能被「删除会话」置成了不可见，重新打开要让它回到列表
            self::reviveMembership((int) $exists->id, [$me, $peerId]);
            return $exists;
        }

        try {
            return Db::transaction(function () use ($key, $me, $peerId) {
                $conv = ImConversationModel::create([
                    'type'         => ImConversationModel::TYPE_SINGLE,
                    'peer_key'     => $key,
                    'member_count' => 2,
                    'status'       => ImConversationModel::STATUS_NORMAL,
                ]);

                foreach ([$me, $peerId] as $uid) {
                    ImConversationMemberModel::create([
                        'conv_id' => $conv->id,
                        'user_id' => $uid,
                        'role'    => ImConversationMemberModel::ROLE_MEMBER,
                    ]);
                }

                return $conv;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // 1062 = 撞了 uk_peer，说明对方在这一瞬间先建好了。这不是错误
            if (!str_contains($e->getMessage(), '1062')) {
                throw $e;
            }

            $conv = ImConversationModel::query()->where('peer_key', $key)->first();
            if (!$conv) {
                throw $e;   // 撞了唯一索引却查不到，那是真出问题了，别吞
            }

            self::reviveMembership((int) $conv->id, [$me, $peerId]);
            return $conv;
        }
    }

    /**
     * 让会话重新出现在成员的列表里
     *
     * 「删除会话」只是 `is_visible=0`（对方不受影响）。重新打开或收到新消息时
     * 要把它拉回来，否则用户会觉得「我明明点了发消息，列表里却没有」。
     */
    private static function reviveMembership(int $convId, array $userIds): void
    {
        ImConversationMemberModel::query()
            ->where('conv_id', $convId)
            ->whereIn('user_id', $userIds)
            ->where('is_visible', 0)
            ->update(['is_visible' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * 我的会话列表
     *
     * 一次查询拿全：会话 + 我的成员行，`unread` 与 `has_at` 在内存里算。
     * **未读数不存字段，永远算出来**（`conv.max_seq - member.last_read_seq`）——
     * 与 M2 系统公告「只记已读不记未读」是同一套思路：
     * 存计数就要在每次发消息时给每个成员 +1，写入量随群规模线性增长，
     * 而且多设备之间一旦对不上就再也修不回来。算出来的天然一致。
     *
     * 排序：置顶在前，其余按最后一条消息时间倒序。
     * 排序放数据库做而不是取回来再排——会话数会随工龄增长，
     * 而「最近聊过的那几个」是唯一高频访问的部分。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function conversations(int $userId): array
    {
        $members = ImConversationMemberModel::query()
            ->where('user_id', $userId)
            ->whereNull('quit_at')
            ->where('is_visible', 1)
            ->get()
            ->keyBy('conv_id');

        if ($members->isEmpty()) {
            return [];
        }

        $convs = ImConversationModel::query()
            ->whereIn('id', $members->keys()->all())
            ->where('status', ImConversationModel::STATUS_NORMAL)
            ->orderByDesc('last_msg_at')
            ->orderByDesc('id')
            ->get();

        $rows = [];

        foreach ($convs as $conv) {
            /** @var ImConversationMemberModel $m */
            $m = $members[$conv->id];

            $rows[] = self::presentConversation($conv, $userId) + [
                // 从来没发过消息的会话（刚建就没说话）不该显示未读
                'unread'    => max(0, (int) $conv->max_seq - (int) $m->last_read_seq),
                // 被 @ 的序号比已读水位高 = 有没读到的 @
                'has_at'    => (int) $m->at_seq > (int) $m->last_read_seq,
                'is_pinned' => (bool) $m->is_pinned,
                'is_muted'  => (bool) $m->is_muted,
                'last_read_seq' => (int) $m->last_read_seq,
            ];
        }

        // 置顶排到最前。放内存里做是因为它依赖成员行（每个人的置顶不一样），
        // 而上面那次查询是按会话表排的——要在 SQL 里做就得 JOIN，得不偿失
        usort($rows, static fn (array $a, array $b) => ($b['is_pinned'] <=> $a['is_pinned']));

        return $rows;
    }

    /**
     * 全局未读汇总（顶栏红点用）
     *
     * 刻意做成一个轻量接口而不是让前端拿会话列表去加总：
     * 红点要在**每个页面**上都对，而会话列表只有聊天页才拉。
     *
     * ⚠️ 免打扰的会话**不计入数字**，只在列表里显示小圆点。
     * 计进去的话「免打扰」就只剩个名字——用户设它就是为了红点别跳。
     */
    public static function unreadSummary(int $userId): array
    {
        $rows = Db::table('im_conversation_members as m')
            ->join('im_conversations as c', 'c.id', '=', 'm.conv_id')
            ->where('m.user_id', $userId)
            ->whereNull('m.quit_at')
            ->where('m.is_visible', 1)
            ->where('c.status', ImConversationModel::STATUS_NORMAL)
            ->whereColumn('c.max_seq', '>', 'm.last_read_seq')
            ->get(['c.max_seq', 'm.last_read_seq', 'm.is_muted', 'm.at_seq']);

        $total = 0;
        $convs = 0;
        $hasAt = false;

        foreach ($rows as $r) {
            $diff = (int) $r->max_seq - (int) $r->last_read_seq;
            if ($diff <= 0) {
                continue;
            }

            $convs++;
            if (!$r->is_muted) {
                $total += $diff;
            }
            if ((int) $r->at_seq > (int) $r->last_read_seq) {
                $hasAt = true;
            }
        }

        return [
            // 红点上的数字：不含免打扰
            'total' => $total,
            // 有未读的会话数：含免打扰，用于「有消息但不弹数字」的小圆点
            'conversations' => $convs,
            'has_at' => $hasAt,
        ];
    }

    /**
     * 会话设置：置顶 / 免打扰
     *
     * 这两个是**每个人自己的**，存在成员行上而不是会话上——
     * 我置顶了不该影响对方。
     */
    public static function updateSettings(int $convId, int $userId, array $data): array
    {
        $member = self::assertMember($convId, $userId);

        $patch = [];
        if (array_key_exists('is_pinned', $data)) {
            $patch['is_pinned'] = (int) (bool) $data['is_pinned'];
        }
        if (array_key_exists('is_muted', $data)) {
            $patch['is_muted'] = (int) (bool) $data['is_muted'];
        }

        if ($patch) {
            $patch['updated_at'] = date('Y-m-d H:i:s');
            ImConversationMemberModel::query()->where('id', $member->id)->update($patch);
        }

        $fresh = ImConversationMemberModel::query()->find($member->id);

        return [
            'conv_id'   => $convId,
            'is_pinned' => (bool) $fresh->is_pinned,
            'is_muted'  => (bool) $fresh->is_muted,
        ];
    }

    /**
     * 删除会话 —— **只从我的列表移除，不删消息**
     *
     * 对方那边完全不受影响，这是钉钉、微信的一致行为，用户预期如此。
     * 做法是两步：`is_visible=0` 让它从列表消失，`min_seq` 抬到当前最大序号
     * 让之前的消息对我不可见。对方再发一条时会话会带着新消息重新出现
     * （send() 里会把 is_visible 拉回 1），但删除之前的历史我看不到了。
     *
     * 不动消息表是关键：删会话是个高频误操作，真删了就找不回来，
     * 而抬水位只是一次 UPDATE，代价是查询多带一个 `seq > min_seq` 条件。
     */
    public static function removeConversation(int $convId, int $userId): void
    {
        $member = self::assertMember($convId, $userId);

        /** @var ImConversationModel|null $conv */
        $conv = ImConversationModel::query()->find($convId);

        ImConversationMemberModel::query()->where('id', $member->id)->update([
            'is_visible'    => 0,
            'min_seq'       => (int) ($conv->max_seq ?? 0),
            // 一起把已读水位推到底：否则下次会话重新出现时，
            // 那些已经被 min_seq 隐藏掉的消息还算在未读里，红点显示一个看不到的数字
            'last_read_seq' => (int) ($conv->max_seq ?? 0),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    /** 会话详情，带上「对方是谁」——单聊的标题和头像都来自这里 */
    public static function detail(int $convId, int $userId): array
    {
        self::assertMember($convId, $userId);

        /** @var ImConversationModel $conv */
        $conv = ImConversationModel::query()->find($convId);
        if (!$conv) {
            throw new NotFoundException();
        }

        return self::presentConversation($conv, $userId);
    }

    /**
     * 会话展示形态
     *
     * 单聊没有自己的名字和头像，用对方的。这一步放在服务端而不是前端：
     * 两个端都要显示同一个标题，放前端就要写两遍，而且移动端还得自己再查一次人。
     */
    private static function presentConversation(ImConversationModel $conv, int $userId): array
    {
        $data = [
            'id'            => (int) $conv->id,
            'type'          => (int) $conv->type,
            'name'          => (string) $conv->name,
            'avatar'        => (string) $conv->avatar,
            'max_seq'       => (int) $conv->max_seq,
            'last_msg_at'   => $conv->last_msg_at?->format('Y-m-d H:i:s'),
            'last_msg_text' => (string) $conv->last_msg_text,
            'peer_id'       => 0,
        ];

        if ((int) $conv->type === ImConversationModel::TYPE_SINGLE) {
            $peerId = self::peerIdOf($conv, $userId);
            /** @var SysUserModel|null $peer */
            $peer = $peerId ? SysUserModel::withoutDataScope()->find($peerId) : null;

            $data['peer_id'] = $peerId;
            $data['name']    = $peer?->real_name ?: ($peer?->username ?? '已注销用户');
            $data['avatar']  = (string) ($peer?->avatar ?? '');
        }

        return $data;
    }

    /** 从 peer_key 里解出「对方是谁」，比再查一次成员表便宜 */
    private static function peerIdOf(ImConversationModel $conv, int $userId): int
    {
        $parts = explode(':', (string) $conv->peer_key);
        if (count($parts) !== 2) {
            return 0;
        }

        return (int) $parts[0] === $userId ? (int) $parts[1] : (int) $parts[0];
    }

    // ================================================================ 消息

    /**
     * 历史消息
     *
     * 游标分页而不是页码：消息表会长得很大，`OFFSET` 在深页上要扫过前面所有行。
     * 两个方向：
     * - `before_seq` 向上翻历史（倒序取，返回前翻正）
     * - `after_seq`  断线重连后补齐空洞（正序取）
     *
     * `min_seq` 是成员自己的可见起点：「删除会话」「清空记录」把它抬上去之后，
     * 之前的消息对这个人不再可见，但对方那边完全不受影响。
     */
    public static function messages(int $convId, int $userId, ?int $beforeSeq, ?int $afterSeq, int $limit): array
    {
        $member = self::assertMember($convId, $userId);
        $limit  = max(1, min($limit ?: self::PAGE_SIZE, 100));

        $q = ImMessageModel::query()
            ->where('conv_id', $convId)
            ->where('seq', '>', (int) $member->min_seq);

        if ($afterSeq !== null) {
            $rows = $q->where('seq', '>', $afterSeq)->orderBy('seq')->limit($limit)->get();
        } else {
            if ($beforeSeq !== null) {
                $q->where('seq', '<', $beforeSeq);
            }
            // 取最新的 N 条要倒序查，再翻回正序给前端——前端拿到的永远是从旧到新
            $rows = $q->orderByDesc('seq')->limit($limit)->get()->reverse()->values();
        }

        return $rows->map(fn (ImMessageModel $m) => self::presentMessage($m))->all();
    }

    /**
     * 消息展示形态
     *
     * 撤回的消息**不下发原文**。库里留着是为了审计与「误撤回后仍可追溯」，
     * 但接口层面它就该是一条「谁撤回了一条消息」。
     */
    public static function presentMessage(ImMessageModel $m): array
    {
        $recalled = (int) $m->status === ImMessageModel::STATUS_RECALLED;

        return [
            'id'            => (int) $m->id,
            'conv_id'       => (int) $m->conv_id,
            'seq'           => (int) $m->seq,
            'sender_id'     => (int) $m->sender_id,
            'sender_name'   => (string) $m->sender_name,
            'type'          => (string) $m->type,
            'content'       => $recalled ? '' : (string) $m->content,
            'extra'         => $recalled ? null : $m->extra,
            'client_msg_id' => (string) $m->client_msg_id,
            'status'        => (int) $m->status,
            'recalled_by'   => (int) $m->recalled_by,
            'created_at'    => $m->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 发消息
     *
     * 处理顺序是设计过的，不要调换（docs/chat-tech.md §4.2）：
     *
     *   1. 成员校验        不是成员 → 404
     *   2. 长度校验        超长 → 400
     *   3. 频率限制        超限 → 429
     *   4. 事务：行锁取 seq → 插消息 → 更新会话冗余字段
     *   5. **事务提交之后**才发广播
     *
     * ⚠️ 第 5 步的位置是关键。在事务里发广播的话，接收端可能在事务还没提交时
     * 就来拉这条消息，拉不到——表现是「偶尔收到通知但没有内容」，
     * 只在高并发下出现，本地永远复现不了。
     */
    public static function send(int $convId, int $userId, array $data): array
    {
        self::assertMember($convId, $userId);

        $type    = (string) ($data['type'] ?? ImMessageModel::TYPE_TEXT);
        $content = (string) ($data['content'] ?? '');
        $maxLen  = (int) ParamService::value('chat.message.maxLength', 5000);

        $extra = is_array($data['extra'] ?? null) ? $data['extra'] : null;

        if ($type === ImMessageModel::TYPE_TEXT) {
            if (trim($content) === '') {
                throw new BusinessException('消息内容不能为空');
            }
            if (mb_strlen($content) > $maxLen) {
                throw new BusinessException(
                    "消息不能超过 {$maxLen} 字",
                    BizCode::CHAT_CONTENT_TOO_LONG
                );
            }
        } elseif (in_array($type, [ImMessageModel::TYPE_IMAGE, ImMessageModel::TYPE_FILE], true)) {
            $extra = self::guardAttachment($extra);
            // 附件消息的 content 存展示用的文件名，列表摘要与搜索都靠它
            $content = (string) ($extra['name'] ?? '');
        } else {
            // system 类型只能由服务端自己发（入群、改群名这类），不接受客户端指定
            throw new BusinessException('不支持的消息类型');
        }

        self::guardRate($userId);

        $clientMsgId = (string) ($data['client_msg_id'] ?? '');
        if ($clientMsgId === '') {
            $clientMsgId = self::uuid();
        }

        // 幂等：同一条消息重发多少次库里都只有一条。撞了就把已存在的那条返回，
        // 而不是报 409——对用户来说「重发成功了」才是正确行为
        $dup = ImMessageModel::query()
            ->where('sender_id', $userId)
            ->where('client_msg_id', $clientMsgId)
            ->first();
        if ($dup) {
            return self::presentMessage($dup);
        }

        $senderName = self::displayName($userId);

        try {
            $message = Db::transaction(function () use ($convId, $userId, $senderName, $type, $content, $extra, $clientMsgId) {
                $seq = self::nextSeq($convId);

                $message = ImMessageModel::create([
                    'conv_id'       => $convId,
                    'seq'           => $seq,
                    'sender_id'     => $userId,
                    'sender_name'   => $senderName,
                    'type'          => $type,
                    'content'       => $content,
                    'extra'         => $extra,
                    'client_msg_id' => $clientMsgId,
                    'status'        => ImMessageModel::STATUS_NORMAL,
                ]);

                ImConversationModel::query()->where('id', $convId)->update([
                    'last_msg_id'   => $message->id,
                    'last_msg_at'   => $message->created_at,
                    'last_msg_text' => self::summarize($type, $content),
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);

                // 收到新消息的会话要重新出现在所有成员的列表里（有人删过会话的话）
                ImConversationMemberModel::query()
                    ->where('conv_id', $convId)
                    ->where('is_visible', 0)
                    ->update(['is_visible' => 1, 'updated_at' => date('Y-m-d H:i:s')]);

                // 自己发的消息对自己天然是已读的，否则发完立刻看到自己的红点
                ImConversationMemberModel::query()
                    ->where('conv_id', $convId)
                    ->where('user_id', $userId)
                    ->update(['last_read_seq' => $seq, 'updated_at' => date('Y-m-d H:i:s')]);

                return $message;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // 幂等的第二道：两个请求同时过了上面的查重，后一个会撞 uk_client_msg
            if (str_contains($e->getMessage(), '1062') && str_contains($e->getMessage(), 'uk_client_msg')) {
                $dup = ImMessageModel::query()
                    ->where('sender_id', $userId)
                    ->where('client_msg_id', $clientMsgId)
                    ->first();
                if ($dup) {
                    return self::presentMessage($dup);
                }
            }
            throw $e;
        }

        $payload = self::presentMessage($message);

        // ⚠️ 事务已提交，这里才广播。推送失败不影响发送成功——
        // 收不到的客户端下次对齐时会把这条补上
        ChatFanout::messageCreated($convId, self::memberIds($convId), $payload);

        return $payload;
    }

    /**
     * 会话内序号自增
     *
     * **行锁自增，不是 Redis INCR。** Redis 快，但它与 MySQL 之间没有事务：
     * 发了号而落库失败，这个号就永远空了，客户端会一直以为少了一条消息、反复补拉。
     * 要修就得再写一套对账，比行锁贵得多。
     *
     * 单会话的写入本来就是低频的（人打字能有多快），锁竞争只发生在同一个会话内，
     * 不影响全局。而「同一会话的并发发送被串行化」正是我们想要的。
     */
    private static function nextSeq(int $convId): int
    {
        $conv = ImConversationModel::query()->lockForUpdate()->find($convId);
        if (!$conv) {
            throw new NotFoundException();
        }

        $seq = (int) $conv->max_seq + 1;
        ImConversationModel::query()->where('id', $convId)->update(['max_seq' => $seq]);

        return $seq;
    }

    /** 会话成员 id，广播时用。由 HTTP 侧算好传给网关——网关要保持无业务逻辑 */
    public static function memberIds(int $convId): array
    {
        return ImConversationMemberModel::query()
            ->where('conv_id', $convId)
            ->whereNull('quit_at')
            ->pluck('user_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * 附件地址校验
     *
     * ⚠️ **不能只信前端传的 url**。`extra` 是客户端给的一个 JSON，
     * 不校验的话任何人都能往里塞 `/uploads/avatar/xxx.png`（别人的头像）、
     * `../../server/.env`、甚至一个外站地址——而这些会被原样渲染成
     * `<img src>` 或下载链接发给会话里的其他人。
     *
     * 三道：
     * 1. 必须以 `/uploads/chat/` 开头——上传接口的 `biz=chat` 落盘就在那儿，
     *    别的目录一律不认（头像、公告图片都不该出现在聊天里）
     * 2. 不许有 `..`——即便前缀对了，`/uploads/chat/../avatar/x.png` 仍能穿越
     * 3. 文件名、大小这些展示字段做长度与类型收敛，脏数据别进库
     *
     * @return array<string, mixed> 洗过的 extra，只保留白名单字段
     */
    private static function guardAttachment(?array $extra): array
    {
        $url = (string) ($extra['url'] ?? '');

        if ($url === '' || !str_starts_with($url, self::ATTACHMENT_PREFIX) || str_contains($url, '..')) {
            throw new BusinessException('附件地址不合法', BizCode::CHAT_ATTACHMENT_INVALID);
        }

        // 白名单取字段，不把客户端传的整个对象存进库——多余的键既占空间又可能被后续代码误用
        $clean = [
            'url'  => $url,
            'name' => mb_substr((string) ($extra['name'] ?? '未命名文件'), 0, 120),
            'size' => max(0, (int) ($extra['size'] ?? 0)),
            'ext'  => mb_substr(strtolower((string) ($extra['ext'] ?? '')), 0, 10),
        ];

        // 图片的宽高用于前端占位，避免加载完成时整段消息跳动。缺了不报错——
        // 老客户端可能不带，而这只是体验问题不是正确性问题
        foreach (['width', 'height'] as $k) {
            if (isset($extra[$k])) {
                $clean[$k] = max(0, (int) $extra[$k]);
            }
        }

        return $clean;
    }

    /**
     * 撤回
     *
     * 只允许撤回**自己的**消息，且在 `CHAT_RECALL_WINDOW` 秒内。
     * 群主可撤回群内任意消息是第 ⑤ 批的事（群聊还没做，那条分支先不写——
     * 提前写一个没有调用方的分支，等真做群聊时多半已经和实际需求对不上了）。
     *
     * **不删 content**，只改状态：留给以后的审计，也避免误撤回后无法追溯。
     * 接口层不下发原文（见 presentMessage），所以对用户表现为「撤回了」。
     */
    public static function recall(int $messageId, int $userId): array
    {
        /** @var ImMessageModel|null $msg */
        $msg = ImMessageModel::query()->find($messageId);
        if (!$msg) {
            throw new NotFoundException();
        }

        // 先确认他在这个会话里——不是成员的话连「这条消息存在」都不该知道
        self::assertMember((int) $msg->conv_id, $userId);

        if ((int) $msg->sender_id !== $userId) {
            throw new ForbiddenException('只能撤回自己发送的消息', BizCode::CHAT_RECALL_FORBIDDEN);
        }

        // 已经撤回过的直接返回，不报错：两个标签页同时点撤回是正常操作
        if ((int) $msg->status === ImMessageModel::STATUS_RECALLED) {
            return self::presentMessage($msg);
        }

        $window = (int) ParamService::value('chat.message.recallWindow', self::RECALL_WINDOW);
        $age    = time() - ($msg->created_at?->getTimestamp() ?? 0);

        if ($age > $window) {
            // 别直接 intdiv($window, 60) . '分钟'：时限调成 90 秒时会说成「超过 1 分钟」，
            // 调成 30 秒更会说成「超过 0 分钟」——技术上没错，但用户不知道该怎么办。
            // 与上传接口的 humanSize 是同一类问题
            $human = $window >= 60 && $window % 60 === 0
                ? intdiv($window, 60) . ' 分钟'
                : $window . ' 秒';

            throw new BusinessException(
                "消息发出超过 {$human}，无法撤回",
                BizCode::CHAT_RECALL_EXPIRED
            );
        }

        $msg->status      = ImMessageModel::STATUS_RECALLED;
        $msg->recalled_by = $userId;
        $msg->recalled_at = date('Y-m-d H:i:s');
        $msg->save();

        // 撤回的如果是最后一条，会话列表的摘要还停在原文上——不改的话
        // 消息已经撤回了，左栏还明晃晃写着那句话
        ImConversationModel::query()
            ->where('id', $msg->conv_id)
            ->where('last_msg_id', $msg->id)
            ->update(['last_msg_text' => '撤回了一条消息', 'updated_at' => date('Y-m-d H:i:s')]);

        $payload = self::presentMessage($msg);

        ChatFanout::messageRecalled((int) $msg->conv_id, self::memberIds((int) $msg->conv_id), $payload);

        return $payload;
    }

    /**
     * 标记已读
     *
     * `GREATEST` 而不是直接赋值：两个标签页乱序提交时，后到的小值会让已读水位
     * **倒退**，表现为「明明读过了，红点又冒出来」。只增不减。
     */
    public static function markRead(int $convId, int $userId, int $lastReadSeq): array
    {
        $member = self::assertMember($convId, $userId);

        ImConversationMemberModel::query()
            ->where('id', $member->id)
            ->update([
                'last_read_seq' => Db::conn()->raw("GREATEST(`last_read_seq`, {$lastReadSeq})"),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

        $fresh = ImConversationMemberModel::query()->find($member->id);
        $seq   = (int) $fresh->last_read_seq;

        $payload = ['conv_id' => $convId, 'user_id' => $userId, 'last_read_seq' => $seq];

        /*
         * 水位真变了才广播
         *
         * 前端每收一条消息、每次窗口回到前台都会调一次标已读，其中大部分是重复的
         * （水位没动）。不加这个判断的话，一次对话会产生几十条无用广播，
         * 而每条都要扇出给全部成员。
         *
         * 广播给**所有成员**而不只是对方：我自己的其他设备也要跟着把红点清掉。
         */
        if ($seq > (int) $member->last_read_seq) {
            ChatFanout::conversationRead($convId, self::memberIds($convId), $payload);
        }

        return ['conv_id' => $convId, 'last_read_seq' => $seq];
    }

    // ================================================================ 通讯录

    /**
     * 可发起会话的人
     *
     * V1 全员可达：能搜到任何在职员工。这是**产品决策不是技术限制**——
     * 将来要限制（比如只能联系本部门）就在这里收窄，服务端其他地方不用动。
     *
     * 不复用 `/admin/users`：那个挂着 `sys:user:list` 权限点，而聊天是全员功能，
     * 普通员工没有那个权限点却必须能选人。两者的授权面本来就不同。
     */
    public static function contacts(int $userId, string $keyword = '', int $limit = 50): array
    {
        $q = SysUserModel::withoutDataScope()
            ->where('status', 1)
            ->where('id', '<>', $userId);

        if ($keyword !== '') {
            $q->where(function ($w) use ($keyword) {
                $w->where('real_name', 'like', "%{$keyword}%")
                  ->orWhere('username', 'like', "%{$keyword}%");
            });
        }

        return $q->orderBy('id')->limit(max(1, min($limit, 200)))->get()
            ->map(fn (SysUserModel $u) => [
                'id'        => (int) $u->id,
                // 通讯录只给「是谁」，不带手机号邮箱——那些受字段级权限管，
                // 而这个接口是全员可调的，把它们带出来等于绕过字段权限
                'real_name' => (string) ($u->real_name ?: $u->username),
                'username'  => (string) $u->username,
                'avatar'    => (string) $u->avatar,
                'dept_id'   => (int) $u->dept_id,
            ])->all();
    }

    // ================================================================ 内部

    /** 发送频率限制。计数放 Redis：webman 多进程模型下进程内计数会被放大 N 倍 */
    private static function guardRate(int $userId): void
    {
        $limit = (int) ParamService::value('chat.rateLimit.perMinute', self::RATE_LIMIT);
        if ($limit <= 0) {
            return;
        }

        $key   = 'rl:chat:' . $userId;
        $count = Cache::incr($key, self::RATE_WINDOW);

        if ($count > $limit) {
            throw new RateLimitException('发送过于频繁，请稍后再试', max(Cache::ttl($key), 1));
        }
    }

    /** 发送人姓名冗余进消息行：改名、离职之后历史消息仍然读得出是谁发的 */
    private static function displayName(int $userId): string
    {
        /** @var SysUserModel|null $u */
        $u = SysUserModel::withoutDataScope()->find($userId);

        return (string) ($u?->real_name ?: $u?->username ?: '未知用户');
    }

    /** 会话列表的摘要。图片文件不显示内容，显示类型——列表里放文件名没有意义 */
    private static function summarize(string $type, string $content): string
    {
        return match ($type) {
            ImMessageModel::TYPE_IMAGE => '[图片]',
            ImMessageModel::TYPE_FILE  => '[文件]',
            default                    => mb_substr($content, 0, 40),
        };
    }

    /** 服务端生成的 UUID v4。系统消息与没带 client_msg_id 的请求都要用它填上那一列 */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
