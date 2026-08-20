<?php

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

/**
 * 参数配置
 *
 * 一个分组就是一张表单，整组提交（PUT /admin/params），不做逐条保存——
 * 同组参数彼此相关（失败次数与锁定时长），逐条保存会留下半新半旧的中间态。
 */
class ParamController
{
    /**
     * 参数列表（按分组）
     *
     * `GET /admin/params` · 权限点 `sys:param:list`
     *
     * 不分页——参数总量是几十条量级，而且要整组渲染成一张表单，分页反而碍事。
     *
     * ⚠️ `is_secret` 的参数**只写不读**，这里返回的是固定掩码而不是真值。
     * 掩码统一在 service 的出参函数里加，保证任何返回路径上都不会漏。
     *
     * @param ListRequest $request 查询参数：`group` 分组编码；不传返回全部
     *
     * @return Response 200，参数数组
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    public function index(ListRequest $request): Response
    {
        $filters = $request->validated();

        return Result::ok(ParamService::listByGroup((string) ($filters['group'] ?? '')));
    }

    /**
     * 分组元信息
     *
     * `GET /admin/params/groups` · 权限点 `sys:param:list`
     *
     * 前端拿它渲染 tab，避免把中文标题写死在页面里——加一个分组只改后端常量。
     *
     * @param Request $request 无参数
     *
     * @return Response 200，`[{code, name}]`
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
     *
     * `GET /admin/params/public` · **免登录**（注册在鉴权中间件之外）
     *
     * 登录页还没有令牌，却要拿到系统名称、Logo 这类东西。
     * 这里**只返回明确公开的白名单**，且 service 里硬过滤掉 `is_secret`——
     * 免登录接口一旦被写成「按分组返回」，加个参数就可能把密钥暴露到公网。
     *
     * @param Request $request 无参数
     *
     * @return Response 200，`{参数键: 参数值}`
     */
    public function publicParams(Request $request): Response
    {
        return Result::ok(ParamService::publicParams());
    }

    /**
     * 参数详情
     *
     * `GET /admin/params/{id}` · 权限点 `sys:param:list`
     *
     * @param Request $request 无查询参数
     * @param int     $id      参数 ID
     *
     * @return Response 200，参数对象；密钥类参数的值仍是掩码
     *
     * @throws \app\common\exception\NotFoundException   参数不存在（404 + `10404`）
     */
    public function show(Request $request, int $id): Response
    {
        return Result::ok(ParamService::detail($id));
    }

    /**
     * 整组保存参数
     *
     * `PUT /admin/params` · 权限点 `sys:param:update` · 自动落操作日志
     *
     * 一个分组就是一张表单，整组提交而不是逐条保存——同组参数彼此相关
     * （比如失败次数与锁定时长），逐条保存会留下半新半旧的中间态。
     *
     * 密钥类参数如果提交上来的还是掩码，service 会**跳过不写**，
     * 否则用户只改了同组的另一个字段就会把密钥覆盖成一串星号。
     *
     * 结构不合法的条目（不是数组、缺 `param_key`）直接跳过，不让整批失败。
     *
     * @param BatchUpdateRequest $request 请求体：`items` 数组，每项 `{param_key, param_value}`
     *
     * @return Response 200，`{saved_count}` 实际写入的条数（未变化的不计）
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
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
     *
     * `POST /admin/params` · 权限点 `sys:param:create` · 自动落操作日志
     *
     * 界面新增的一律是自定义参数，`is_builtin` 强制为 false——
     * 内置标记只由 `scripts/seed.php` 写，它决定了这条参数能不能被删。
     *
     * @param StoreRequest $request 请求体见 {@see StoreRequest}，`group` / `name` / `param_key` 必填
     *
     * @return Response 201，返回新建的参数
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\ConflictException  参数键已存在（409 + `20602`）
     */
    public function store(StoreRequest $request): Response
    {
        return Result::created(ParamService::create($request->validated()));
    }

    /**
     * 编辑参数
     *
     * `PUT /admin/params/{id}` · 权限点 `sys:param:update` · 自动落操作日志
     *
     * **内置参数只能改值**，键与类型会被 service 忽略：内置参数的键被代码直接引用
     * （如 `sys.log.retainDays`），改掉之后读取方拿到的是默认值，而且不报错。
     *
     * @param UpdateRequest $request 请求体见 {@see UpdateRequest}
     * @param int           $id      参数 ID
     *
     * @return Response 200，返回更新后的参数
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\NotFoundException   参数不存在（404 + `10404`）
     * @throws \app\common\exception\ConflictException  参数键已被占用（409 + `20602`）
     */
    public function update(UpdateRequest $request, int $id): Response
    {
        return Result::ok(ParamService::update($id, $request->validated()));
    }

    /**
     * 删除参数
     *
     * `DELETE /admin/params/{id}` · 权限点 `sys:param:delete` · 自动落操作日志
     *
     * 内置参数不可删——删掉之后引用它的代码会静默走默认值，
     * 表现是「某个开关莫名其妙失效了」，而日志里只有一条删除记录。
     *
     * @param Request $request 无请求体
     * @param int     $id      参数 ID
     *
     * @return Response 204，无响应体
     *
     * @throws \app\common\exception\NotFoundException   参数不存在（404 + `10404`）
     * @throws \app\common\exception\ForbiddenException  内置参数不可删除（403 + `20601`）
     */
    public function destroy(Request $request, int $id): Response
    {
        ParamService::delete($id);

        return Result::noContent();
    }
}
