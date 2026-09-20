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
     * 标记已读
     * @url POST /staff/v1/chat/conversations/{id}/read
     * @perm 登录即可
     */
    public function read(Request $request, int $id): Response
    {
        return Result::ok(ChatService::markRead($id, self::uid(), (int) $request->post('last_read_seq', 0)));
    }
}
