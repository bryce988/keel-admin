<?php
/**
 * keel admin
 * 参数配置
 *
 * 一个分组就是一张表单，整组提交（`PUT /admin/params`），不做逐条保存——
 * 同组参数彼此相关（失败次数与锁定时长），逐条保存会留下半新半旧的中间态。
 *
 * `is_secret` 的参数只写不读：所有读接口返回的都是固定掩码，掩码统一在 service 的
 * 出参函数里加，保证任何返回路径上都不会漏。
 *
 * 本模块通用，各方法不再重复：权限点声明在 `config/route.php`，不写即 403（fail-closed）；
 * 入参校验见 `app\admin\validation\Param\*`，失败一律 422 + 字段级 `details`；
 * 写操作自动落操作日志。错误码表见 docs/api.md §2.2。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\validation\Param\BatchUpdateRequest;
use app\admin\validation\Param\ListRequest;
use app\admin\validation\Param\StoreRequest;
use app\admin\validation\Param\UpdateRequest;
use app\common\service\ParamService;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

class ParamController
{
    /**
     * 参数列表（按分组）
     * @url GET /admin/params
     * @perm sys:param:list
     * @description 查询参数 `group` 分组编码，不传返回全部。不分页——参数总量是几十条量级，
     * 而且要整组渲染成一张表单，分页反而碍事。
     */
    public function index(ListRequest $request): Response
    {
        $filters = $request->validated();

        return Result::ok(ParamService::listByGroup((string) ($filters['group'] ?? '')));
    }

    /**
     * 分组元信息
     * @url GET /admin/params/groups
     * @perm sys:param:list
     * @description 前端拿它渲染 tab，避免把中文标题写死在页面里——加一个分组只改后端常量。
     */
    public function groups(Request $request): Response
    {
        $out = [];
        foreach (ParamService::GROUPS as $code => $name) {
            $out[] = ['code' => $code, 'name' => $name];
        }

        return Result::ok($out);
    }

    /**
     * 登录页要用的少量参数
     * @url GET /admin/params/public
     * @perm -
     * @description 免登录（注册在鉴权中间件之外）。登录页还没有令牌，却要拿到系统名称、Logo 这类东西。
     * 这里只返回明确公开的白名单，且 service 里硬过滤掉 `is_secret`——
     * 免登录接口一旦被写成「按分组返回」，加个参数就可能把密钥暴露到公网。
     */
    public function publicParams(Request $request): Response
    {
        return Result::ok(ParamService::publicParams());
    }

    /**
     * 参数详情
     * @url GET /admin/params/{id}
     * @perm sys:param:list
     */
    public function show(Request $request, int $id): Response
    {
        return Result::ok(ParamService::detail($id));
    }

    /**
     * 整组保存参数
     * @url PUT /admin/params
     * @perm sys:param:update
     * @description 请求体 `items` 数组，每项 `{param_key, param_value}`，返回 `{saved_count}`。
     * 密钥类参数如果提交上来的还是掩码，service 会跳过不写，
     * 否则用户只改了同组的另一个字段就会把密钥覆盖成一串星号。
     * 结构不合法的条目（不是数组、缺 `param_key`）直接跳过，不让整批失败。
     */
    public function batchUpdate(BatchUpdateRequest $request): Response
    {
        $data = $request->validated();

        $items = [];
        foreach ((array) $data['items'] as $item) {
            if (!is_array($item) || !isset($item['param_key'])) {
                continue;
            }

            $items[] = [
                'param_key'   => (string) $item['param_key'],
                'param_value' => (string) ($item['param_value'] ?? ''),
            ];
        }

        return Result::ok(['saved_count' => ParamService::saveMany($items)]);
    }

    /**
     * 新增自定义参数
     * @url POST /admin/params
     * @perm sys:param:create
     * @description 界面新增的一律是自定义参数，`is_builtin` 强制为 false——
     * 内置标记只由 `scripts/seed.php` 写，它决定了这条参数能不能被删。
     * @error 409 `20602` 参数键已存在
     */
    public function store(StoreRequest $request): Response
    {
        return Result::created(ParamService::create($request->validated()));
    }

    /**
     * 编辑参数
     * @url PUT /admin/params/{id}
     * @perm sys:param:update
     * @description 内置参数只能改值，键与类型会被 service 忽略：内置参数的键被代码直接引用
     * （如 `sys.log.retainDays`），改掉之后读取方拿到的是默认值，而且不报错。
     * @error 409 `20602` 参数键已被占用
     */
    public function update(UpdateRequest $request, int $id): Response
    {
        return Result::ok(ParamService::update($id, $request->validated()));
    }

    /**
     * 删除参数
     * @url DELETE /admin/params/{id}
     * @perm sys:param:delete
     * @description 内置参数不可删——删掉之后引用它的代码会静默走默认值，
     * 表现是「某个开关莫名其妙失效了」，而日志里只有一条删除记录。
     * @error 403 `20601` 内置参数不可删除
     */
    public function destroy(Request $request, int $id): Response
    {
        ParamService::delete($id);

        return Result::noContent();
    }
}
