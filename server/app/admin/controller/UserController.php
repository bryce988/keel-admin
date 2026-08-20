<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\exception\BusinessException;
use app\common\service\UserService;
use app\common\support\OpLog;
use app\common\support\Paginator;
use app\common\support\Result;
use app\common\support\Validator;
use support\Response;
use Webman\Http\Request;

/**
 * 用户管理（RBAC 的**分配**层）
 *
 * 三层职责分离：定义（菜单权限）→ 授权（角色）→ **分配（本模块）**。
 * 这里只把已有的角色分给人，不在用户身上单独授权——
 * 用户身上一旦能独立加权限，「这个人为什么能看到这个」就再也说不清了。
 */
class UserController
{
    /**
     * 用户列表（分页）
     *
     * `GET /admin/users` · 权限点 `sys:user:list`
     *
     * 两层过滤都不在这里写：**数据权限**（能看到谁）由模型全局 Scope 注入，
     * **字段权限**（手机号/邮箱是明文还是掩码）在 `rowMapper()` 里按当前用户
     * 持有的 `sys:field:*` 决定。控制器只管把筛选条件递下去。
     *
     * @param Request $request 查询参数见 {@see self::filters()}
     *
     * @return Response 200，`{list, total, page, page_size}`
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    public function index(Request $request): Response
    {
        return Paginator::response(
            UserService::listQuery(self::filters($request)),
            $request,
            sortable: UserService::SORTABLE,
            defaultField: 'id',
            defaultOrder: 'asc',
            map: UserService::rowMapper(),
        );
    }

    /**
     * 用户详情
     *
     * `GET /admin/users/{id}` · 权限点 `sys:user:list`
     *
     * 不在数据范围内的用户返回 **404 而不是 403**：403 等于告诉调用方
     * 「这个 id 存在，只是你看不到」，本身就是一次信息泄露。
     *
     * @param Request $request 无查询参数
     * @param int     $id      用户 ID
     *
     * @return Response 200，用户对象（含部门、岗位、角色），敏感字段按字段级权限脱敏
     *
     * @throws \app\common\exception\NotFoundException   用户不存在，或不在你的数据范围内（404 + `10404`）
     */
    public function show(Request $request, int $id): Response
    {
        return Result::ok(UserService::detail($id));
    }

    /**
     * 新增用户
     *
     * `POST /admin/users` · 权限点 `sys:user:create` · 自动落操作日志
     *
     * 角色在同一个请求里一并分配，走的是与「分配角色」接口同一套校验
     * （互斥、角色数上限），不会因为入口不同而放宽。
     *
     * 不传 `password` 时由 service 按密码策略生成。
     *
     * @param Request $request 请求体见 {@see self::validate()}，外加 `role_ids` 角色 ID 数组
     *
     * @return Response 201，返回新建的用户
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\ConflictException 账号已存在（409 + `20101`）
     * @throws \app\common\exception\BusinessException 角色互斥（400 + `20304`）、超出角色数上限（400 + `20305`）
     */
    public function store(Request $request): Response
    {
        $data = self::validate($request);
        $roleIds = array_map('intval', (array) $request->post('role_ids', []));

        return Result::created(UserService::create($data, $roleIds));
    }

    /**
     * 编辑用户
     *
     * `PUT /admin/users/{id}` · 权限点 `sys:user:update` · 自动落操作日志
     *
     * `role_ids` 的三态是刻意的：**不传**表示不动角色，传**空数组**表示清空。
     * 合并成一种语义的话，「只改个手机号」的请求会把这个人的角色全清掉。
     *
     * @param Request $request 请求体见 {@see self::validate()}；`role_ids` 可选
     * @param int     $id      用户 ID
     *
     * @return Response 200，返回更新后的用户详情
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\NotFoundException   用户不存在，或不在你的数据范围内（404 + `10404`）
     * @throws \app\common\exception\ForbiddenException 不允许操作超级管理员（403 + `20103`）
     * @throws \app\common\exception\ConflictException  账号已被占用（409 + `20101`）
     * @throws \app\common\exception\BusinessException  角色互斥（400 + `20304`）、超出角色数上限（400 + `20305`）
     */
    public function update(Request $request, int $id): Response
    {
        $data = self::validate($request);

        // 没传 role_ids 就不动角色；传了空数组则表示清空
        $roleIds = $request->post('role_ids') === null
            ? null
            : array_map('intval', (array) $request->post('role_ids'));

        UserService::update($id, $data, $roleIds);

        return Result::ok(UserService::detail($id));
    }

    /**
     * 删除用户
     *
     * `DELETE /admin/users/{id}` · 权限点 `sys:user:delete` · 自动落操作日志
     *
     * @param Request $request 无请求体
     * @param int     $id      用户 ID
     *
     * @return Response 204，无响应体
     *
     * @throws \app\common\exception\NotFoundException   用户不存在，或不在你的数据范围内（404 + `10404`）
     * @throws \app\common\exception\ForbiddenException 不允许操作超级管理员（403 + `20103`）
     * @throws \app\common\exception\BusinessException  不能删除自己的账号（400 + `20105`）
     */
    public function destroy(Request $request, int $id): Response
    {
        UserService::delete($id);

        return Result::noContent();
    }

    /**
     * 启用 / 停用用户
     *
     * `PUT /admin/users/{id}/status` · 权限点 `sys:user:update` · 自动落操作日志
     *
     * 停用是可逆的下线动作，比删除安全——账号停用后立即无法登录，
     * 但历史数据的归属与日志里的操作人都还在。
     *
     * @param Request $request 请求体：`status` 0 停用 1 启用（必填）
     * @param int     $id      用户 ID
     *
     * @return Response 204，无响应体
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     * @throws \app\common\exception\NotFoundException   用户不存在，或不在你的数据范围内（404 + `10404`）
     * @throws \app\common\exception\ForbiddenException 不允许操作超级管理员（403 + `20103`）
     * @throws \app\common\exception\BusinessException  不能停用自己的账号（400 + `20105`）、
     *                                                      名下还有待交接的数据（400 + `20104`）
     */
    public function setStatus(Request $request, int $id): Response
    {
        $data = Validator::make($request->all(), [
            'status' => ['required|int|in:0,1', '状态'],
        ])->validated();

        UserService::setStatus($id, $data['status']);

        return Result::noContent();
    }

    /**
     * 分配角色
     *
     * `PUT /admin/users/{id}/roles` · 权限点 `sys:user:grantRole` · 自动落操作日志
     *
     * 全量覆盖。与角色页的「添加成员」共用同一个校验实现，
     * 否则会出现「从角色页加人能成功、从用户页加同一个人却被拒」这种漂移。
     *
     * 保存后顶该用户的 `perm_version`，权限即刻生效，不用重新登录。
     *
     * @param Request $request 请求体：`role_ids` 角色 ID 数组；空数组表示清空该用户所有角色
     * @param int     $id      用户 ID
     *
     * @return Response 204，无响应体
     *
     * @throws \app\common\exception\NotFoundException   用户不存在，或不在你的数据范围内（404 + `10404`）
     * @throws \app\common\exception\ForbiddenException 不允许操作超级管理员（403 + `20103`）
     * @throws \app\common\exception\BusinessException  角色互斥（400 + `20304`）、超出角色数上限（400 + `20305`）
     */
    public function grantRoles(Request $request, int $id): Response
    {
        UserService::grantRoles($id, array_map('intval', (array) $request->post('role_ids', [])));

        return Result::noContent();
    }

    /**
     * 重置密码
     *
     * `PUT /admin/users/{id}/password/reset` · 权限点 `sys:user:resetPwd` · 自动落操作日志
     *
     * ⚠️ 返回的明文**只有这一次**，库里存的是哈希，之后再也取不出来。
     * 前端要提示管理员当面转交或另行传达。
     *
     * 不传 `password` 时按密码策略随机生成。
     *
     * @param Request $request 请求体：`password` 指定的新密码；留空则随机生成
     * @param int     $id      用户 ID
     *
     * @return Response 200，`{password}` 新密码明文
     *
     * @throws \app\common\exception\NotFoundException   用户不存在，或不在你的数据范围内（404 + `10404`）
     * @throws \app\common\exception\ForbiddenException  不允许操作超级管理员（403 + `20103`）
     * @throws \app\common\exception\ValidationException 指定的密码不符合安全策略（422 + `20006`）
     */
    public function resetPassword(Request $request, int $id): Response
    {
        $plain = UserService::resetPassword($id, (string) $request->post('password', ''));

        return Result::ok(['password' => $plain]);
    }

    // ---------------------------------------------------------------- 导入导出

    /**
     * 导出用户
     *
     * `GET /admin/users/export` · 权限点 `sys:user:export` · 自动落操作日志
     *
     * 筛选条件与列表接口共用 {@see self::filters()}，导出的就是界面上筛出来的那批，不是全表。
     * 数据权限与字段脱敏同样生效——**导出不是绕开字段权限的后门**，
     * 没有手机号权限的人导出来也是掩码。
     *
     * 路由里这条必须排在 `/users/{id}` 之前，否则 `export` 会被当成 id 匹配掉。
     *
     * @param Request $request 查询参数见 {@see self::filters()}
     *
     * @return Response 200，xlsx 文件流，文件名 `用户列表_YYYYmmdd_HHiiss.xlsx`
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    public function export(Request $request): Response
    {
        $path = UserService::export(self::filters($request));

        OpLog::target('导出用户 ' . basename($path));

        return Result::download($path, "用户列表_" . date("Ymd_His") . ".xlsx");
    }

    /**
     * 下载导入模板
     *
     * `GET /admin/users/import-template` · 权限点 `sys:user:import`
     *
     * 模板的表头就是导入时认的列名，由 service 生成而不是放一个静态文件——
     * 静态文件会和代码里的列定义走散，而走散的表现是「照模板填却导不进去」。
     *
     * @param Request $request 无参数
     *
     * @return Response 200，xlsx 文件流，文件名 `用户导入模板.xlsx`
     */
    public function importTemplate(Request $request): Response
    {
        return Result::download(UserService::importTemplate(), '用户导入模板.xlsx');
    }

    /**
     * 导入用户
     *
     * `POST /admin/users/import` · 权限点 `sys:user:import` · 自动落操作日志
     *
     * 逐行尽力执行：某一行账号重复不影响其余行，失败明细带行号返回。
     *
     * 上传的临时文件会先挪到 `runtime/imports/` 再解析——webman 的上传临时文件
     * 在请求结束时就被清掉，直接解析会读到空文件。解析完**立即删除**，
     * 因为里面通常是真实姓名与手机号。
     *
     * @param Request $request `multipart/form-data`，字段名 `file`，支持 .xlsx 与 .csv
     *
     * @return Response 200，`{success_count, fail_count, failures:[{row, message}]}`
     *
     * @throws \app\common\exception\BusinessException 没选文件、文件损坏，或扩展名不是 xlsx/csv（400）
     */
    public function import(Request $request): Response
    {
        $file = $request->file('file');

        if (!$file || !$file->isValid()) {
            throw new BusinessException('请选择要导入的文件');
        }

        $ext = strtolower($file->getUploadExtension());
        if (!in_array($ext, ['xlsx', 'csv'], true)) {
            throw new BusinessException('只支持 xlsx 与 csv，收到的是 .' . $ext);
        }

        // 挪到 runtime 下再解析：上传的临时文件在请求结束时会被清掉
        $path = runtime_path() . '/imports/' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $file->move($path);

        try {
            $result = UserService::import($path);
        } finally {
            @unlink($path);   // 导入完就删，里面通常有真实姓名与手机号
        }

        OpLog::target(sprintf('导入用户 成功%d 失败%d', $result['success_count'], $result['fail_count']));

        return Result::ok($result);
    }

    // ---------------------------------------------------------------- 内部

    /**
     * 列表与导出共用的查询条件
     *
     * 共用一份是必须的：分开写迟早出现「界面上筛出 20 条，导出来 2000 条」。
     *
     * @param Request $request 查询参数：`keyword` 账号/姓名/手机号模糊匹配、
     *                         `status` 0 停用 1 启用 2 锁定、`dept_id` 所属部门（含下级）
     *
     * @return array 只含白名单内字段的数组
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    private static function filters(Request $request): array
    {
        return Validator::make($request->all(), [
            'keyword' => ['string|max:64', '关键词'],
            'status'  => ['in:0,1,2',      '状态'],
            'dept_id' => ['int|min:1',     '部门'],
        ])->validated();
    }

    /**
     * 新增与编辑共用的入参校验
     *
     * @param Request $request 请求体：`username` 账号（必填，2-64，唯一）、`real_name` 姓名（必填）、
     *                         `phone` 手机号、`email` 邮箱、`dept_id` 部门、`post_id` 岗位、
     *                         `status` 0 停用 1 启用 2 锁定、`remark` 备注、
     *                         `password` 初始密码（留空则由 service 按策略生成）
     *
     * @return array 只含白名单内字段的数组
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    private static function validate(Request $request): array
    {
        return Validator::make($request->all(), [
            'username'  => ['required|string|min:2|max:64', '账号'],
            'real_name' => ['required|string|max:64',       '姓名'],
            'phone'     => ['phone',                        '手机号'],
            'email'     => ['email|max:128',                '邮箱'],
            'dept_id'   => ['int|min:0',                    '部门'],
            'post_id'   => ['int|min:0',                    '岗位'],
            'status'    => ['int|in:0,1,2',                 '状态'],
            'remark'    => ['string|max:255',               '备注'],
            'password'  => ['string|max:64',                '密码'],
        ])->validated();
    }
}
