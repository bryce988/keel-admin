<?php
/**
 * keel admin
 * 系统公告
 *
 * 一张表，两组接口，权限口径完全不同（这也是本模块最容易接错的地方）：
 *
 * | 分组 | 路径 | 权限 | 看得到什么 |
 * |---|---|---|---|
 * | 管理端 | `/admin/notices*` | `sys:notice:*` | 全部，含草稿 |
 * | 接收端 | `/admin/my/notices*` | 登录即可（`perm => ''`） | 只有已发布的 |
 *
 * 接收端不带权限点是刻意的：公告的受众是每一个登录用户，给它挂权限点等于
 * 「没被授权的人收不到全员通知」。它的越权面由结构挡住——用户 id 只从令牌取，
 * 路径里没有 user_id 这种参数，与 ProfileController 同一套思路。
 *
 * 本模块通用，各方法不再重复：权限点声明在 `config/route.php`，不写即 403（fail-closed）；
 * 入参校验见 `app\admin\validation\Notice\*`，失败一律 422 + 字段级 `details`；
 * 写操作自动落操作日志。错误码表见 docs/api.md §2.2。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\validation\Notice\ListRequest;
use app\admin\validation\Notice\StoreRequest;
use app\admin\validation\Notice\UpdateRequest;
use app\common\service\NoticeService;
use app\common\support\BatchResult;
use app\common\support\Ctx;
use app\common\support\OpLog;
use app\common\support\Paginator;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

class NoticeController
{
    // ---------------------------------------------------------------- 管理端

    /**
     * 公告列表（分页）
     * @url GET /admin/notices
     * @perm sys:notice:list
     * @description 默认按创建时间倒序：公告是时间序的东西，最新的一条永远该在第一屏。
     * 列表只返回 60 字摘要，正文要看详情。
     */
    public function index(ListRequest $request): Response
    {
        return Paginator::response(
            NoticeService::listQuery($request->validated()),
            $request->request(),
            sortable: NoticeService::SORTABLE,
            defaultField: 'created_at',
            defaultOrder: 'desc',
            map: NoticeService::rowMapper(),
        );
    }

    /**
     * 公告详情
     * @url GET /admin/notices/{id}
     * @perm sys:notice:list
     */
    public function show(Request $request, int $id): Response
    {
        return Result::ok(NoticeService::detail($id));
    }

    /**
     * 新增公告
     * @url POST /admin/notices
     * @perm sys:notice:create
     * @description `status=1` 表示存好就发，服务端顺手盖上发布时间与发布人。
     */
    public function store(StoreRequest $request): Response
    {
        return Result::created(NoticeService::create($request->validated())->toArray());
    }

    /**
     * 编辑公告
     * @url PUT /admin/notices/{id}
     * @perm sys:notice:update
     */
    public function update(UpdateRequest $request, int $id): Response
    {
        return Result::ok(NoticeService::update($id, $request->validated())->toArray());
    }

    /**
     * 发布公告
     * @url POST /admin/notices/{id}/publish
     * @perm sys:notice:publish
     * @description 幂等：已发布的再点一次不报错，也不刷新发布时间
     * （刷新会把这条重新顶回所有人的未读里）。
     */
    public function publish(Request $request, int $id): Response
    {
        return Result::ok(NoticeService::publish($id)->toArray());
    }

    /**
     * 撤回公告（回到草稿）
     * @url POST /admin/notices/{id}/revoke
     * @perm sys:notice:publish
     * @description 已读回执保留——撤回的语义是「先别再发给人看」，
     * 不是「大家没看过」。清掉回执会让改完重发时读过的人再被弹一次。
     */
    public function revoke(Request $request, int $id): Response
    {
        return Result::ok(NoticeService::revoke($id)->toArray());
    }

    /**
     * 删除公告
     * @url DELETE /admin/notices/{id}
     * @perm sys:notice:delete
     */
    public function destroy(Request $request, int $id): Response
    {
        NoticeService::delete($id);

        return Result::noContent();
    }

    /**
     * 批量删除公告
     * @url POST /admin/notices/batch-delete
     * @perm sys:notice:delete
     * @description 逐条尽力执行，不是一个事务（api.md §1.4），与岗位、字典项同一套口径。
     */
    public function batchDestroy(Request $request): Response
    {
        $ids = array_filter(array_map('intval', (array) $request->post('ids', [])));
        if (!$ids) {
            return Result::ok(BatchResult::make()->toArray());
        }

        OpLog::target('公告 ' . implode(',', $ids));

        return Result::ok(
            BatchResult::run($ids, fn (int $id) => NoticeService::delete($id))->toArray()
        );
    }

    // ---------------------------------------------------------------- 接收端

    /**
     * 我的消息（顶栏铃铛）
     * @url GET /admin/my/notices
     * @perm 登录即可
     * @description 一次返回未读数、最新一条未读的 id 与标题、最近 10 条列表——
     * 铃铛的角标和下拉是同一个控件的两部分，分成两个接口轮询会出现
     * 「角标 3、点开只有 2 条」的错位，请求数还翻倍。
     *
     * 前端按固定间隔轮询这个接口（没有 WebSocket，脚手架不引长连接），
     * 所以它必须便宜：两条带索引的 count/limit 查询，不 join。
     */
    public function bell(Request $request): Response
    {
        return Result::ok(NoticeService::bell(Ctx::userId()));
    }

    /**
     * 读一条（同时落已读回执）
     * @url GET /admin/my/notices/{id}
     * @perm 登录即可
     * @description 读和标已读是同一个动作。草稿与已撤回的一律 404——
     * 对接收端来说它们不存在，链接还在手上也打不开。
     */
    public function readOne(Request $request, int $id): Response
    {
        return Result::ok(NoticeService::read(Ctx::userId(), $id));
    }

    /**
     * 全部标为已读
     * @url POST /admin/my/notices/read-all
     * @perm 登录即可
     * @description 返回 `{count}`，即本次新增的回执数（本来就没有未读时是 0）。
     */
    public function readAll(Request $request): Response
    {
        return Result::ok(['count' => NoticeService::readAll(Ctx::userId())]);
    }
}
