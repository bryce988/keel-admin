<?php
/**
 * keel admin
 * 聊天消息的跨进程投递
 *
 * HTTP 进程把「该推给谁 + 推什么」发布到 Redis 频道，
 * WebSocket 网关进程（`app\process\ChatGateway`）订阅后推给自己持有的连接。
 *
 * ## 为什么必须走 Redis 而不是进程内变量
 *
 * webman 是多进程模型：发消息的 HTTP worker 和持有接收方长连接的网关进程
 * 是**两个操作系统进程**，内存不共享。网关本身也 count>1，
 * 每个进程只持有一部分连接。所以广播只能走进程外的通道（PROJECT.md §14）。
 *
 * ## 这条通道是「不可靠投递」，这是设计而不是缺陷
 *
 * Redis 的 pub/sub 不做持久化：订阅方不在（网关正在重启）就丢了。
 * 可以接受——丢了的后果只是客户端暂时没收到推送，下次对齐时按 seq 补齐。
 * **可靠性建立在数据库上，不在这条通道上**（docs/chat-tech.md §2）。
 *
 * 所以这里的任何失败都只记日志、不抛异常：消息已经落库了，
 * 为一次推送失败让整个发送请求返回 500 是本末倒置。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\support;

use support\Log;

class ChatFanout
{
    /** 网关订阅的频道。单个频道 + 载荷里带收件人，而不是每人一个频道——
     *  后者在几千用户时会让网关持有几千个订阅，而 Redis 的订阅是有成本的 */
    public const CHANNEL = 'im:fanout';

    /** 新消息 */
    public static function messageCreated(int $convId, array $userIds, array $payload): void
    {
        self::publish('message.new', $convId, $userIds, $payload);
    }

    /** 撤回。下发的 payload 里不含原文（presentMessage 已经抹掉） */
    public static function messageRecalled(int $convId, array $userIds, array $payload): void
    {
        self::publish('message.recalled', $convId, $userIds, $payload);
    }

    /** 已读水位变化，用于多端同步「对方已读」 */
    public static function conversationRead(int $convId, array $userIds, array $payload): void
    {
        self::publish('conversation.read', $convId, $userIds, $payload);
    }

    /**
     * 系统公告有变化（发布 / 撤回 / 删除 / 已发布的被编辑）
     *
     * 公告是全员可见的，收件人就是「所有在线的人」，所以走广播而不是列 user_ids：
     * 列出来的话每发一条公告都要先把全公司的 id 查一遍、塞进一条 Redis 消息里。
     * 客户端收到后自己去拉未读数，这里只说「变了」，不带谁该 +1 的判断
     */
    public static function noticeChanged(array $payload): void
    {
        self::send(['ev' => 'notice.changed', 'conv_id' => 0, 'all' => true, 'user_ids' => [], 'data' => $payload]);
    }

    /** 某人读了公告：推给他自己的其他标签页 / 设备，让各处的未读数一起变 */
    public static function noticeRead(int $userId, array $payload): void
    {
        self::publish('notice.read', 0, [$userId], $payload);
    }

    /**
     * 发布
     *
     * `user_ids` 由调用方（HTTP 侧）算好传进来，**不让网关去查库**：
     * 网关进程要保持「无业务逻辑」，这样将来换成 GatewayWorker 或独立网关时，
     * 它才是可替换的——业务逻辑一行都不在里面（docs/chat-tech.md §1.2）。
     */
    private static function publish(string $event, int $convId, array $userIds, array $payload): void
    {
        if (!$userIds) {
            return;
        }

        self::send([
            'ev'       => $event,
            'conv_id'  => $convId,
            'user_ids' => array_values(array_map('intval', $userIds)),
            'data'     => $payload,
        ]);
    }

    private static function send(array $frame): void
    {
        try {
            Cache::conn()->publish(self::CHANNEL, json_encode($frame, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            // 只记不抛：数据已经落库，客户端下次对齐会补上
            Log::warning('[chat] 广播失败 ' . $e->getMessage(), [
                'event'   => $frame['ev'] ?? '',
                'conv_id' => $frame['conv_id'] ?? 0,
            ]);
        }
    }
}
