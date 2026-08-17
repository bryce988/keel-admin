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

    public function show(Request $request, int $id): Response
    {
        return Result::ok(UserService::detail($id));
    }

    public function store(Request $request): Response
    {
        $data = self::validate($request);
        $roleIds = array_map('intval', (array) $request->post('role_ids', []));

        return Result::created(UserService::create($data, $roleIds));
    }

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

    public function destroy(Request $request, int $id): Response
    {
        UserService::delete($id);

        return Result::noContent();
    }

    /** 启用 / 停用 */
    public function setStatus(Request $request, int $id): Response
    {
        $data = Validator::make($request->all(), [
            'status' => ['required|int|in:0,1', '状态'],
        ])->validated();

        UserService::setStatus($id, $data['status']);

        return Result::noContent();
    }

    /** 分配角色 */
    public function grantRoles(Request $request, int $id): Response
    {
        UserService::grantRoles($id, array_map('intval', (array) $request->post('role_ids', [])));

        return Result::noContent();
    }

    /**
     * 重置密码
     *
     * 返回的明文**只有这一次**，前端要提示管理员当面转交或另行传达。
     */
    public function resetPassword(Request $request, int $id): Response
    {
        $plain = UserService::resetPassword($id, (string) $request->post('password', ''));

        return Result::ok(['password' => $plain]);
    }

    // ---------------------------------------------------------------- 导入导出

    public function export(Request $request): Response
    {
        $path = UserService::export(self::filters($request));

        OpLog::target('导出用户 ' . basename($path));

        return Result::download($path, "用户列表_" . date("Ymd_His") . ".xlsx");
    }

    public function importTemplate(Request $request): Response
    {
        return Result::download(UserService::importTemplate(), '用户导入模板.xlsx');
    }

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

    private static function filters(Request $request): array
    {
        return Validator::make($request->all(), [
            'keyword' => ['string|max:64', '关键词'],
            'status'  => ['in:0,1,2',      '状态'],
            'dept_id' => ['int|min:1',     '部门'],
        ])->validated();
    }

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
