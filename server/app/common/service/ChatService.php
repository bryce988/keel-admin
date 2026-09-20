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
            $message = Db::transaction(function () use ($convId, $userId, $senderName, $type, $content, $data, $clientMsgId) {
                $seq = self::nextSeq($convId);

                $message = ImMessageModel::create([
                    'conv_id'       => $convId,
                    'seq'           => $seq,
                    'sender_id'     => $userId,
                    'sender_name'   => $senderName,
                    'type'          => $type,
                    'content'       => $content,
                    'extra'         => $data['extra'] ?? null,
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

        return ['conv_id' => $convId, 'last_read_seq' => (int) $fresh->last_read_seq];
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
