<?php
/**
 * keel admin
 * 员工移动端 · 即时通讯
 *
 * 业务**一行都没有**——全部复用 {@see ChatService}，与后台 `app/admin/controller/ChatController`
 * 调的是同一份实现。这不是巧合而是铁律：一个业务规则只有一份实现（PROJECT.md §8.2）。
 *
 * 「手机发、电脑收」之所以是这一批最有效的验证方式，就是因为它会把
 * 「有人图省事把业务写进了某一端的 controller」这件事立刻暴露出来。
 *
 * 形状上与后台的差异只有一处：通讯录默认给更少的条数（手机屏幕装不下 50 条），
 * 其余完全一致。将来要长出的强制更新、推送注册之类才是这一端真正的分歧点。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\staff\controller\v1;

use app\common\exception\BusinessException;
use app\common\service\ChatService;
use app\common\support\Ctx;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

class ChatController
{
    private static function uid(): int
    {
        return (int) ((Ctx::user() ?? [])['id'] ?? 0);
    }

    /**
     * 通讯录
     * @url GET /staff/v1/chat/contacts
     * @perm 登录即可
     */
    public function contacts(Request $request): Response
    {
        return Result::ok(ChatService::contacts(
            self::uid(),
            trim((string) $request->get('keyword', '')),
            (int) $request->get('limit', 30),
        ));
    }

/**
     * 我的会话列表
     * @url GET /staff/v1/chat/conversations
     * @perm 登录即可
     * @description 置顶在前，其余按最后消息时间倒序。每条带 `unread` / `has_at` /
     * `is_pinned` / `is_muted`。**未读数是算出来的**（会话最大序号 − 我的已读水位），
     * 不存计数字段——存了就要在每次发消息时给每个成员 +1，写入量随群规模线性增长，
     * 而且多设备一旦对不上就再也修不回来。
     */
    public function conversations(Request $request): Response
    {
        return Result::ok(ChatService::conversations(self::uid()));
    }

    /**
     * 全局未读汇总
     * @url GET /staff/v1/chat/unread
     * @perm 登录即可
     * @description 顶栏红点用，刻意做成轻量接口——红点要在每个页面上都对，
     * 而会话列表只有聊天页才拉。**免打扰的会话不计入 `total`**，
     * 计进去的话「免打扰」就只剩个名字。
     */
    public function unread(Request $request): Response
    {
        return Result::ok(ChatService::unreadSummary(self::uid()));
    }

    /**
     * 会话设置
     * @url PUT /staff/v1/chat/conversations/{id}/settings
     * @perm 登录即可
     * @description `{is_pinned?, is_muted?}`。两个都是**每个人自己的**，
     * 存在成员行上——我置顶了不该影响对方。
     * @error 404 会话不存在，或你不是它的成员
     */
    public function settings(Request $request, int $id): Response
    {
        $data = [];
        foreach (['is_pinned', 'is_muted'] as $k) {
            $v = $request->post($k);
            if ($v !== null) {
                $data[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
            }
        }

        return Result::ok(ChatService::updateSettings($id, self::uid(), $data));
    }

    /**
     * 删除会话
     * @url DELETE /staff/v1/chat/conversations/{id}
     * @perm 登录即可
     * @description **只从我的列表移除，不删消息**，对方完全不受影响。
     * 对方再发一条时会话会带着新消息重新出现，但删除之前的历史我看不到了。
     * @error 404 会话不存在，或你不是它的成员
     */
    public function remove(Request $request, int $id): Response
    {
        ChatService::removeConversation($id, self::uid());

        return Result::noContent();
    }

    /**
     * 打开单聊
     * @url POST /staff/v1/chat/conversations
     * @perm 登录即可
     * @description `{user_id}`。已存在就返回已有的，不新建
     */
    public function open(Request $request): Response
    {
        $peerId = (int) $request->post('user_id', 0);
        if ($peerId <= 0) {
            throw new BusinessException('请选择聊天对象');
        }

        $conv = ChatService::openSingle(self::uid(), $peerId);

        return Result::ok(ChatService::detail((int) $conv->id, self::uid()));
    }

    /**
     * 历史消息
     * @url GET /staff/v1/chat/conversations/{id}/messages
     * @perm 登录即可
     * @error 404 会话不存在，或你不是它的成员
     */
    public function messages(Request $request, int $id): Response
    {
        $before = $request->get('before_seq');
        $after  = $request->get('after_seq');

        return Result::ok(ChatService::messages(
            $id,
            self::uid(),
            $before === null || $before === '' ? null : (int) $before,
            $after === null || $after === '' ? null : (int) $after,
            (int) $request->get('limit', 30),
        ));
    }

    /**
     * 发消息
     * @url POST /staff/v1/chat/conversations/{id}/messages
     * @perm 登录即可
     * @error 400 内容为空或超长
     * @error 404 会话不存在，或你不是它的成员
     * @error 429 发送过于频繁
     */
    public function send(Request $request, int $id): Response
    {
        return Result::created(ChatService::send($id, self::uid(), [
            'client_msg_id' => (string) $request->post('client_msg_id', ''),
            'type'          => (string) $request->post('type', 'text'),
            'content'       => (string) $request->post('content', ''),
            'extra'         => $request->post('extra'),
        ]));
    }

/**
     * 撤回消息
     * @url POST /staff/v1/chat/messages/{id}/recall
     * @perm 登录即可
     * @description 只能撤回**自己的**消息，且在 2 分钟内（参数 `chat.message.recallWindow`）。
     * 重复撤回不报错，直接返回已撤回的那条——两个标签页同时点是正常操作。
     * 库里**保留原文**（审计与误撤回追溯），但接口不再下发。
     * @error 400 超过可撤回时间
     * @error 403 不是自己发的消息
     * @error 404 消息不存在，或你不在它所属的会话里
     */
    public function recall(Request $request, int $id): Response
    {
        return Result::ok(ChatService::recall($id, self::uid()));
    }

    /**
     * 标记已读
     * @url POST /staff/v1/chat/conversations/{id}/read
     * @perm 登录即可
     */
    public function read(Request $request, int $id): Response
    {
        return Result::ok(ChatService::markRead($id, self::uid(), (int) $request->post('last_read_seq', 0)));
    }
}
