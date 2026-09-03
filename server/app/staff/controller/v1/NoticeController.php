<?php
/**
 * keel admin
 * 员工移动端 · 消息（系统公告）
 *
 * 接收端逻辑全部复用 {@see NoticeService}——与后台铃铛是同一份已读判定、
 * 同一份摘要规则。这里只决定「手机上要什么形状」：
 * 列表分页而不是像铃铛那样只给最近 10 条，且顺带把未读数一起返回。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\staff\controller\v1;

use app\common\service\NoticeService;
use app\common\support\Ctx;
use app\common\support\Paginator;
use app\common\support\Result;
use app\staff\validation\Notice\ListRequest;
use support\Response;
use Webman\Http\Request;

class NoticeController
{
    private static function uid(): int
    {
        return (int) ((Ctx::user() ?? [])['id'] ?? 0);
    }

    /**
     * 消息列表
     * @url GET /staff/v1/notices
     * @perm 登录即可
     * @description 已发布的公告，按发布时间倒序，每条带 `is_read`。
     * 分页元信息之外**额外返回 `unread_count`**——列表和角标在界面上是同一件事的两面，
     * 分成两个接口会出现「角标 3、点进去只有 2 条未读」的错位，轮询请求数也翻倍。
     */
    public function index(ListRequest $request): Response
    {
        $userId = self::uid();

        $page = Paginator::make(
            NoticeService::inboxQuery(),
            $request->request(),
            sortable: ['published_at', 'id'],
            defaultField: 'published_at',
            defaultOrder: 'desc',
            map: NoticeService::inboxMapper($userId),
        );

        return Result::ok($page + ['unread_count' => NoticeService::unreadCount($userId)]);
    }

    /**
     * 读一条
     * @url GET /staff/v1/notices/{id}
     * @perm 登录即可
     * @description 返回正文（HTML）**并顺带落已读回执**——读和标已读是同一个动作，
     * 拆成两个接口的话每打开一条要发两个请求，而且第二个失败时界面显示已读、库里还是未读。
     * 草稿与已撤回的一律 404：它们对接收端不存在，链接还在手上也打不开。
     * @error 404 `10404` 公告不存在或已撤回
     */
    public function show(Request $request, int $id): Response
    {
        return Result::ok(NoticeService::read(self::uid(), $id));
    }

    /**
     * 全部标为已读
     * @url POST /staff/v1/notices/read-all
     * @perm 登录即可
     * @description 一次把缺的回执补齐，返回实际新增的条数。
     */
    public function readAll(Request $request): Response
    {
        return Result::ok(['marked' => NoticeService::readAll(self::uid())]);
    }
}
