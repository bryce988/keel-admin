<?php
/**
 * keel admin
 * 用户管理 —— RBAC 的分配层
 *
 * 三层职责分离：定义（菜单权限）→ 授权（角色）→ 分配（本模块）。
 * 这里只把已有的角色分给人，不在用户身上单独授权——用户身上一旦能独立加权限，
 * 「这个人为什么能看到这个」就再也说不清了。
 *
 * 本模块通用，各方法不再重复：权限点声明在 `config/route.php`，不写即 403（fail-closed）；
 * 入参校验见 `app\admin\validation\User\*`，失败一律 422 + 字段级 `details`；
 * 数据范围外的记录返回 404 而非 403（403 等于承认「这个 id 存在，只是你看不到」）；
 * 超级管理员受保护，任何写操作都是 403 + `20103`。错误码表见 docs/api.md §2.2。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\validation\User\ListRequest;
use app\admin\validation\User\StoreRequest;
use app\admin\validation\User\UpdateRequest;
use app\admin\validation\User\UpdateStatusRequest;
use app\common\exception\BusinessException;
use app\common\service\ExportService;
use app\admin\service\UserService;
use app\common\support\OpLog;
use app\common\support\Paginator;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

class UserController
{
    /**
     * 用户列表
     * @url GET /admin/users
     * @perm sys:user:list
     * @description 两层过滤都不在这里写：数据权限（能看到谁）由模型全局 Scope 注入，
     * 字段权限（手机号/邮箱是否脱敏）在 `rowMapper()` 里按 `sys:field:*` 决定。
     */
    public function index(ListRequest $request): Response
    {
        return Paginator::response(
            UserService::listQuery($request->validated()),
            $request->request(),   // 分页参数走原始 Request，不在 ListRequest 白名单里
            sortable: UserService::SORTABLE,
            defaultField: 'id',
            defaultOrder: 'asc',
            map: UserService::rowMapper(),
        );
    }

    /**
     * 用户详情
     * @url GET /admin/users/{id}
     * @perm sys:user:list
     * @description 返回含部门、岗位、角色，敏感字段按字段级权限脱敏。
     */
    public function show(Request $request, int $id): Response
    {
        return Result::ok(UserService::detail($id));
    }

    /**
     * 新增用户
     * @url POST /admin/users
     * @perm sys:user:create
     * @description 角色在同一个请求里一并分配（`role_ids`），走的是与「分配角色」接口同一套校验，
     * 不会因为入口不同而放宽。不传 `password` 时由 service 按密码策略生成。
     * @error 409 `20101` 账号已存在 · 400 `20304` 角色互斥 · 400 `20305` 超出角色数上限
     */
    public function store(StoreRequest $request): Response
    {
        $data = $request->validated();
        $roleIds = array_map('intval', (array) $request->post('role_ids', []));

        return Result::created(UserService::create($data, $roleIds));
    }

    /**
     * 编辑用户
     * @url PUT /admin/users/{id}
     * @perm sys:user:update
     * @description `role_ids` 的三态是刻意的：不传表示不动角色，传空数组表示清空。
     * 合并成一种语义的话，「只改个手机号」的请求会把这个人的角色全清掉。
     * @error 409 `20101` 账号已被占用 · 400 `20304` 角色互斥 · 400 `20305` 超出角色数上限
     */
    public function update(UpdateRequest $request, int $id): Response
    {
        $data = $request->validated();

        // 没传 role_ids 就不动角色；传了空数组则表示清空
        $roleIds = $request->post('role_ids') === null
            ? null
            : array_map('intval', (array) $request->post('role_ids'));

        UserService::update($id, $data, $roleIds);

        return Result::ok(UserService::detail($id));
    }

    /**
     * 删除用户
     * @url DELETE /admin/users/{id}
     * @perm sys:user:delete
     * @error 400 `20105` 不能删除自己的账号
     */
    public function destroy(Request $request, int $id): Response
    {
        UserService::delete($id);

        return Result::noContent();
    }

    /**
     * 启用 / 停用用户
     * @url PUT /admin/users/{id}/status
     * @perm sys:user:update
     * @description 停用是可逆的下线动作，比删除安全——账号停用后立即无法登录，
     * 但历史数据的归属与日志里的操作人都还在。
     * @error 400 `20105` 不能停用自己 · 400 `20104` 名下还有待交接的数据
     */
    public function setStatus(UpdateStatusRequest $request, int $id): Response
    {
        $data = $request->validated();

        UserService::setStatus($id, $data['status']);

        return Result::noContent();
    }

    /**
     * 分配角色（全量覆盖）
     * @url PUT /admin/users/{id}/roles
     * @perm sys:user:grantRole
     * @description 请求体 `role_ids` 数组，空数组表示清空。与角色页的「添加成员」
     * 共用同一个校验实现，否则会出现「从角色页加人能成功、从用户页加同一个人却被拒」这种漂移。
     * 保存后顶该用户的 `perm_version`，权限即刻生效，不用重新登录。
     * @error 400 `20304` 角色互斥 · 400 `20305` 超出角色数上限
     */
    public function grantRoles(Request $request, int $id): Response
    {
        UserService::grantRoles($id, array_map('intval', (array) $request->post('role_ids', [])));

        return Result::noContent();
    }

    /**
     * 重置密码
     * @url PUT /admin/users/{id}/password/reset
     * @perm sys:user:resetPwd
     * @description ⚠️ 返回的 `{password}` 明文只有这一次，库里存的是哈希，之后再也取不出来，
     * 前端要提示管理员当面转交。请求体 `password` 留空则按密码策略随机生成。
     * @error 422 `20006` 指定的密码不符合安全策略
     */
    public function resetPassword(Request $request, int $id): Response
    {
        $plain = UserService::resetPassword($id, (string) $request->post('password', ''));

        return Result::ok(['password' => $plain]);
    }

    // ---------------------------------------------------------------- 导入导出

    /**
     * 发起导出用户
     * @url GET /admin/users/export
     * @perm sys:user:export
     * @description **不直接返回文件**：建一条导出任务投进队列，返回 `202 Accepted` +
     * `{task_id}`，用户到「数据管理 / 数据导出」下载。几万行的 xlsx 要几十秒，
     * 同步返回会在浏览器或 nginx 任一层超时，而那个 worker 在这段时间里一个请求都接不了。
     *
     * 筛选条件与列表接口共用 {@see ListRequest}，导出的就是界面上筛出来的那批，
     * 不是全表——条件整份存进任务，排队期间界面改了筛选也不影响。
     * 数据权限与字段脱敏同样生效（消费进程会还原发起人身份），
     * 导出不是绕开字段权限的后门：没有手机号权限的人导出来也是掩码。
     *
     * 路由里这条必须排在 `/users/{id}` 之前，否则 `export` 会被当成 id 匹配掉。
     */
    public function export(ListRequest $request): Response
    {
        $task = ExportService::enqueue('user', $request->validated());

        return Result::accepted([
            'task_id' => $task->id,
            'message' => '已加入导出队列，完成后可在「数据管理 / 数据导出」下载',
        ]);
    }

    /**
     * 下载导入模板（xlsx）
     * @url GET /admin/users/import-template
     * @perm sys:user:import
     * @description 表头就是导入时认的列名，由 service 生成而不是放一个静态文件——
     * 静态文件会和代码里的列定义走散，而走散的表现是「照模板填却导不进去」。
     */
    public function importTemplate(Request $request): Response
    {
        return Result::download(UserService::importTemplate(), '用户导入模板.xlsx');
    }

    /**
     * 导入用户
     * @url POST /admin/users/import
     * @perm sys:user:import
     * @description `multipart/form-data`，字段名 `file`，支持 .xlsx 与 .csv；
     * 返回 `{success_count, fail_count, failures:[{row, message}]}`。
     * 逐行尽力执行：某一行账号重复不影响其余行，失败明细带行号返回。
     * @error 400 没选文件、文件损坏，或扩展名不对
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
}
