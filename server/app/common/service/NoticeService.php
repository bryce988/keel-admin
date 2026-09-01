<?php
/**
 * keel admin
 * 系统公告
 *
 * 两类调用方，规则完全不同，所以方法也分成两组：
 *
 * - **管理端**（`sys:notice:*`）：草稿、发布、撤回、改、删。看得到所有公告，含草稿。
 * - **接收端**（登录即可，无权限点）：只看得到已发布的，且只能操作**自己**的已读回执。
 *   与 ProfileService 同样的思路——用户 id 只从令牌取，请求体里的 id 一律不看，
 *   越权在结构上就不成立。
 *
 * ## 未读是算出来的，不是存出来的
 *
 * 未读 = 已发布公告 ∖ 我的已读回执。反过来做（发布时给每个用户插一行未读）
 * 会让「发一条公告」变成一次全表写入，1000 人的系统就是 1000 行，
 * 而且新入职的人还得补发。只记已读，写入量与实际阅读行为成正比。
 *
 * ## 正文是富文本，**写入时净化**
 *
 * 前端 tiptap 产出 HTML，读的地方用 `v-html` 渲染。公告的作者是后台管理员，
 * 但「管理员是可信的」不成立——他的账号可能被盗，而这段内容会出现在**每个**
 * 登录用户的屏幕上，是天然的存储型 XSS 放大器。所以每一条写路径都要过
 * {@see \app\common\support\Html::purify()}，白名单之外的标签与属性一律剥掉。
 *
 * 净化在**写入时**做：存进去的就是干净的，读的地方不必再管。反过来（渲染时净化）
 * 要求每个渲染点都记得做一次，漏一个就是漏一个洞，而漏没漏没有任何信号。
 *
 * 摘要与铃铛下拉要的是纯文字，走 `Html::toText()`——直接截 HTML 会截在标签中间，
 * 把半个 `<stro` 发给前端。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\service;

use app\common\model\SysNoticeModel;
use app\common\model\SysNoticeReadModel;
use app\common\support\Ctx;
use app\common\support\Db;
use app\common\support\Html;
use app\common\support\Guard;
use app\common\support\OpLog;
use Illuminate\Database\Eloquent\Builder;

class NoticeService
{
    public const SORTABLE = ['id', 'status', 'published_at', 'created_at'];

    /** 铃铛下拉里最多列几条。再多就该去列表页翻，下拉不是列表页 */
    private const BELL_LIMIT = 10;

    // ---------------------------------------------------------------- 管理端

    public static function listQuery(array $filters): Builder
    {
        $query = SysNoticeModel::query();

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            /*
             * 正文也一起搜：公告的标题往往是「关于XX的通知」，靠标题搜不出内容。
             *
             * 存的是 HTML，所以关键词理论上能命中标签名（搜 "li" 会多出几条）。
             * 没有为此再存一份纯文本副本：那要多一列、多一处同步，
             * 而管理端搜公告本来就是低频操作，多命中几条不影响用。
             */
            $query->where(function ($q) use ($keyword) {
                $q->where('title', 'like', "%{$keyword}%")->orWhere('content', 'like', "%{$keyword}%");
            });
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('status', (int) $filters['status']);
        }

        if (($filters['type'] ?? '') !== '') {
            $query->where('type', (string) $filters['type']);
        }

        return $query;
    }

    public static function rowMapper(): callable
    {
        return fn (SysNoticeModel $row): array => [
            'id'             => $row->id,
            'title'          => $row->title,
            // 列表里只给摘要：正文可能是几千字，一页 20 条全量返回是把带宽花在没人看的地方
            'summary'        => self::summarize((string) $row->content),
            'type'           => $row->type,
            'status'         => $row->status,
            'published_at'   => $row->published_at?->format('Y-m-d H:i:s'),
            'publisher_name' => $row->publisher_name,
            'read_count'     => SysNoticeReadModel::query()->where('notice_id', $row->id)->count(),
            'created_at'     => $row->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /** 摘要：正文剥成纯文字后取 60 字，够在列表里认出是哪一条 */
    public static function summarize(string $content): string
    {
        $flat = Html::toText($content);

        return mb_strlen($flat) > 60 ? mb_substr($flat, 0, 60) . '…' : $flat;
    }

    public static function detail(int $id): array
    {
        /** @var SysNoticeModel $notice */
        $notice = Guard::found(SysNoticeModel::find($id));

        return $notice->toArray();
    }

    public static function create(array $data): SysNoticeModel
    {
        return Db::transaction(function () use ($data) {
            $notice = new SysNoticeModel();
            $notice->fill($data);
            // 净化放在 fill 之后：fill 进来的是前端原样提交的 HTML
            $notice->content = Html::purify((string) $notice->content);

            // 建的时候就选了「发布」，等价于建完立刻发一次，不必让人再点一下
            if ((int) ($data['status'] ?? 0) === SysNoticeModel::STATUS_PUBLISHED) {
                self::stampPublish($notice);
            }

            $notice->save();

            OpLog::target("公告 {$notice->title}({$notice->id})");

            return $notice;
        });
    }

    public static function update(int $id, array $data): SysNoticeModel
    {
        /** @var SysNoticeModel $notice */
        $notice = Guard::found(SysNoticeModel::find($id));

        $before = $notice->toArray();

        return Db::transaction(function () use ($notice, $data, $before) {
            $wasPublished = (int) $notice->status === SysNoticeModel::STATUS_PUBLISHED;
            $notice->fill($data);
            $notice->content = Html::purify((string) $notice->content);
            $nowPublished = (int) $notice->status === SysNoticeModel::STATUS_PUBLISHED;

            /*
             * 状态在这一次编辑里跨过了发布线，才动 published_at 与发布人
             *
             * 已发布的公告改错别字不该刷新发布时间：它一刷新，所有人的消息列表
             * 就会把这条重新顶到最上面，而内容其实没变。撤回同理——把时间清掉
             * 而不是留着，否则再次发布时无法区分「首次发布」和「改回来」。
             */
            if (!$wasPublished && $nowPublished) {
                self::stampPublish($notice);
            } elseif ($wasPublished && !$nowPublished) {
                $notice->published_at   = null;
                $notice->publisher_id   = 0;
                $notice->publisher_name = '';
            }

            $notice->save();

            OpLog::target("公告 {$notice->title}({$notice->id})");
            OpLog::diff($before, $notice->toArray());

            return $notice;
        });
    }

    /**
     * 发布（草稿 → 已发布）
     *
     * 已经是已发布的重复调用**不报错也不改时间**：这个接口的语义是
     * 「让它处于已发布状态」，重复点一下不该把公告顶回所有人的未读里。
     */
    public static function publish(int $id): SysNoticeModel
    {
        /** @var SysNoticeModel $notice */
        $notice = Guard::found(SysNoticeModel::find($id));

        OpLog::target("公告 {$notice->title}({$notice->id})");

        if ((int) $notice->status === SysNoticeModel::STATUS_PUBLISHED) {
            return $notice;
        }

        self::stampPublish($notice);
        $notice->status = SysNoticeModel::STATUS_PUBLISHED;
        $notice->save();

        return $notice;
    }

    /**
     * 撤回（已发布 → 草稿）
     *
     * 已读回执**保留**：撤回不是「大家没看过」，而是「先别再发给人看」。
     * 删掉回执的话，改完重新发布时读过的人会被当成新未读再弹一次。
     */
    public static function revoke(int $id): SysNoticeModel
    {
        /** @var SysNoticeModel $notice */
        $notice = Guard::found(SysNoticeModel::find($id));

        OpLog::target("公告 {$notice->title}({$notice->id})");

        $notice->status         = SysNoticeModel::STATUS_DRAFT;
        $notice->published_at   = null;
        $notice->publisher_id   = 0;
        $notice->publisher_name = '';
        $notice->save();

        return $notice;
    }

    public static function delete(int $id): void
    {
        /** @var SysNoticeModel $notice */
        $notice = Guard::found(SysNoticeModel::find($id));

        OpLog::target("公告 {$notice->title}({$notice->id})");

        // 公告是硬删，回执要一并清掉，否则 sys_notice_reads 会积压指向空 id 的行
        Db::transaction(function () use ($notice) {
            SysNoticeReadModel::query()->where('notice_id', $notice->id)->delete();
            $notice->delete();
        });
    }

    private static function stampPublish(SysNoticeModel $notice): void
    {
        $user = Ctx::user() ?? [];

        $notice->published_at   = date('Y-m-d H:i:s');
        $notice->publisher_id   = (int) ($user['id'] ?? 0);
        // 姓名冗余存一份：发布人改名或离职销号后，公告上仍要显示当时是谁发的
        $notice->publisher_name = (string) ($user['real_name'] ?? ($user['username'] ?? ''));
    }

    // ---------------------------------------------------------------- 接收端

    /**
     * 铃铛要的全部数据：未读数 + 最近若干条
     *
     * 合成一个接口而不是「一个查数量、一个查列表」：这两件事在界面上是同一个
     * 控件的两个部分，分开轮询会出现「角标 3、点开只有 2 条」的错位，
     * 而且轮询请求数直接翻倍。
     *
     * `latest_id` 给前端判断「有没有新的」用——它比数量可靠：
     * 读掉一条、又发来一条时数量不变，只看数量就不会弹提示。
     */
    public static function bell(int $userId): array
    {
        $readIds = SysNoticeReadModel::query()->where('user_id', $userId)->pluck('notice_id')->all();

        $unreadQuery = SysNoticeModel::query()->published();
        if ($readIds) {
            $unreadQuery->whereNotIn('id', $readIds);
        }

        $unreadCount = (clone $unreadQuery)->count();

        // 下拉里未读读过的都要出现（只列未读的话，读完最后一条下拉就空了，
        // 用户会以为消息丢了），但排序让未读靠前
        $rows = SysNoticeModel::query()
            ->published()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::BELL_LIMIT)
            ->get();

        $readSet = array_flip(array_map('intval', $readIds));

        $list = $rows->map(fn (SysNoticeModel $n) => [
            'id'             => $n->id,
            'title'          => $n->title,
            'summary'        => self::summarize((string) $n->content),
            'type'           => $n->type,
            'published_at'   => $n->published_at?->format('Y-m-d H:i:s'),
            'publisher_name' => $n->publisher_name,
            'is_read'        => isset($readSet[(int) $n->id]),
        ])->all();

        $latestUnread = (clone $unreadQuery)->orderByDesc('id')->first();

        return [
            'unread_count'  => $unreadCount,
            'latest_id'     => (int) ($latestUnread->id ?? 0),
            'latest_title'  => (string) ($latestUnread->title ?? ''),
            'list'          => $list,
        ];
    }

    /**
     * 读一条：返回正文，顺带落已读回执
     *
     * 读和标已读是同一个动作，不拆成两个接口——拆了之后前端每打开一条要发两个
     * 请求，而且第二个失败时界面显示的是「已读」、库里还是未读。
     *
     * 草稿一律 404：它对接收端不存在。撤回后同理，链接还在手上也打不开。
     */
    public static function read(int $userId, int $id): array
    {
        /** @var SysNoticeModel $notice */
        $notice = Guard::found(SysNoticeModel::query()->published()->find($id));

        // 回执用 firstOrCreate 而不是先查后插：同一个人两个标签页同时点开会并发插入，
        // 唯一键会让后一条 500。firstOrCreate 在这里仍可能撞，所以外面还包了一层
        try {
            SysNoticeReadModel::query()->firstOrCreate(
                ['notice_id' => $notice->id, 'user_id' => $userId],
                ['created_at' => date('Y-m-d H:i:s')],
            );
        } catch (\Throwable) {
            // 撞唯一键说明回执已经在了，这正是我们要的结果，不必打扰调用方
        }

        return [
            'id'             => $notice->id,
            'title'          => $notice->title,
            'content'        => $notice->content,
            'type'           => $notice->type,
            'published_at'   => $notice->published_at?->format('Y-m-d H:i:s'),
            'publisher_name' => $notice->publisher_name,
        ];
    }

    /**
     * 全部标为已读
     *
     * 一次性把缺的回执补齐。用 insert 批量写而不是逐条 save()：
     * 积压几百条未读时逐条写就是几百次往返。
     *
     * @return int 实际新增的回执数
     */
    public static function readAll(int $userId): int
    {
        $readIds = SysNoticeReadModel::query()->where('user_id', $userId)->pluck('notice_id')->all();

        $query = SysNoticeModel::query()->published();
        if ($readIds) {
            $query->whereNotIn('id', $readIds);
        }

        $ids = $query->pluck('id')->all();
        if (!$ids) {
            return 0;
        }

        $now  = date('Y-m-d H:i:s');
        $rows = array_map(
            fn ($id) => ['notice_id' => (int) $id, 'user_id' => $userId, 'created_at' => $now],
            $ids,
        );

        // 分批插入：一次 insert 几千行会撞 max_allowed_packet
        foreach (array_chunk($rows, 500) as $chunk) {
            SysNoticeReadModel::query()->insert($chunk);
        }

        return count($ids);
    }
}
