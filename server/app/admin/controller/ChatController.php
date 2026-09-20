<?php
/**
 * keel admin
 * 即时通讯
 *
 * 业务全在 `app\common\service\ChatService`——后台与员工移动端共用同一份，
 * 这里只做编排（PROJECT.md §8.2）。
 *
 * **可见性不靠权限点靠成员关系**：所有方法的 user_id 都取自令牌，
 * service 的第一行是 `assertMember()`，非成员一律 404（不是 403，
 * 403 等于确认会话存在，配合自增 id 就能探出别人的会话列表）。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\common\exception\BusinessException;
use app\common\service\ChatService;
use app\common\support\Ctx;
use app\common\support\Result;
use support\Request;
use support\Response;

class ChatController
{
    /**
     * 通讯录
     * @url GET /admin/chat/contacts
     * @perm chat:use
     * @description 可发起会话的在职员工。不复用 `/admin/users`——那个挂着
     * `sys:user:list`，而聊天是全员功能，普通员工没有那个权限点却必须能选人。
     * 只返回「是谁」，不带手机号邮箱：那些受字段级权限管，全员可调的接口带出来等于绕过。
     */
    public function contacts(Request $request): Response
    {
        return Result::ok(ChatService::contacts(
            self::uid(),
            trim((string) $request->get('keyword', '')),
            (int) $request->get('limit', 50),
        ));
    }

    /**
     * 打开单聊
     * @url POST /admin/chat/conversations
     * @perm chat:use
     * @description `{user_id}`。**已存在就返回已有的，不新建**，所以是 200 不是 201——
     * 「一对人只有一个会话」由 uk_peer 唯一索引保证，不是靠先查后插。
     * @error 400 和自己发起会话、对方已停用
     * @error 404 对方不存在
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
     * 会话详情
     * @url GET /admin/chat/conversations/{id}
     * @perm chat:use
     * @error 404 会话不存在，或你不是它的成员
     */
    public function detail(Request $request, int $id): Response
    {
        return Result::ok(ChatService::detail($id, self::uid()));
    }

    /**
     * 历史消息
     * @url GET /admin/chat/conversations/{id}/messages
     * @perm chat:use
     * @description 游标分页：`before_seq` 向上翻历史，`after_seq` 断线重连后补空洞。
     * 不用 offset——消息表会很大，深页要扫过前面所有行。
     * 返回**永远按 seq 正序**（从旧到新），前端不用再排一次。
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
     * @url POST /admin/chat/conversations/{id}/messages
     * @perm chat:use
     * @description `{client_msg_id, type, content, extra?}`。
     * `client_msg_id` 是客户端生成的 UUID，用于幂等——「点了发送但响应超时，
     * 用户又点一次」是最常见的重复来源，撞了唯一索引直接返回已存在的那条（200 语义，
     * 这里仍是 201，因为对调用方而言这次请求确实产出了那条消息）。
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
     * @url POST /admin/chat/conversations/{id}/read
     * @perm chat:use
     * @description `{last_read_seq}`。水位只增不减（服务端用 GREATEST）——
     * 两个标签页乱序提交时，后到的小值会让水位倒退，表现为「明明读过了，红点又冒出来」。
     * @error 404 会话不存在，或你不是它的成员
     */
    public function read(Request $request, int $id): Response
    {
        return Result::ok(ChatService::markRead($id, self::uid(), (int) $request->post('last_read_seq', 0)));
    }

    /** 用户 id 只从令牌取。一旦改成从请求读，聊天立刻变成「以任意身份发消息」 */
    private static function uid(): int
    {
        return (int) (Ctx::user()['id'] ?? 0);
    }
}
