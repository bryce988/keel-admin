<?php
/**
 * keel admin
 * 个人中心
 *
 * 每个方法的用户 id 都取自 `Ctx::user()`（来源是令牌），不从请求里读 id。
 * 这是本控制器唯一需要守住的东西：只要 id 不来自请求，就没有「改别人」的路径可走——
 * 所以这一组接口一个权限点都不需要。
 *
 * 修改密码不在这里而在 AuthController：它改完要吊销当前令牌，
 * 属于会话生命周期的一部分，和资料编辑不是一类事。
 *
 * 错误码表见 docs/api.md §2.2。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\common\exception\BusinessException;
use app\admin\service\ProfileService;
use app\common\support\Ctx;
use app\common\support\Paginator;
use app\common\support\Result;
use support\Request;
use support\Response;

class ProfileController
{
    /**
     * 我的资料
     * @url GET /admin/profile
     * @perm -
     * @description 登录即可。不需要权限点是结构决定的：id 取自令牌，没有「查别人」的入参，
     * 也就没有可越权的路径。加权限点反而会让「所有人都能看自己」变成一个需要授权的动作。
     */
    public function index(Request $request): Response
    {
        return Result::ok(ProfileService::detail(self::uid()));
    }

    /**
     * 修改我的资料
     * @url PUT /admin/profile
     * @perm -
     * @description 登录即可，自动落操作日志。只开放三个字段。
     * 用户名、部门、角色都不在其中——那些是管理员才能改的，放开等于给了自我提权的口子。
     */
    public function update(Request $request): Response
    {
        return Result::ok(ProfileService::update(self::uid(), [
            'real_name' => $request->post('real_name', ''),
            'email'     => $request->post('email', ''),
            'avatar'    => $request->post('avatar'),
        ]));
    }

    /**
     * 更换头像
     * @url POST /admin/profile/avatar
     * @perm -
     * @description 登录即可，自动落操作日志。`multipart/form-data`，字段名 `file`。
     * 一步到位：上传成功即写库，响应里的 `avatar` 就是最终地址，不需要再调 `PUT /admin/profile`
     * （两段式要维护临时目录与孤儿文件清理，头像这一个场景撑不起那套开销，见 api.md §11.1）。
     * @error 400 没选文件、扩展名不在白名单、超出 `sys.upload.avatarMaxSize`，或内容不是真图片
     */
    public function avatar(Request $request): Response
    {
        $file = $request->file('file');

        if (!$file || !$file->isValid()) {
            throw new BusinessException('请选择要上传的图片');
        }

        return Result::ok(['avatar' => ProfileService::changeAvatar(self::uid(), $file)]);
    }

    /**
     * 换绑手机号
     * @url PUT /admin/profile/phone
     * @perm -
     * @description 登录即可，自动落操作日志。用当前密码验证而不是短信验证码：
     * 脚手架不绑死任何短信服务商（M3 定案，见 api.md §11）。
     * 接真实业务时把这里换成验证码校验即可，其余逻辑不用动。
     * @error 400 `20005` 密码错误 · 409 `20106` 手机号已被其他账号使用
     */
    public function changePhone(Request $request): Response
    {
        ProfileService::changePhone(
            self::uid(),
            trim((string) $request->post('phone', '')),
            (string) $request->post('password', '')
        );

        return Result::noContent();
    }

    /**
     * 我的登录记录（分页）
     * @url GET /admin/profile/logins
     * @perm -
     * @description 登录即可。只查自己的，条件写死在 service 里，不受数据权限范围影响——
     * 「仅本人」在这里是硬编码而非配置，配置得越复杂越容易配错成别人的。
     */
    public function logins(Request $request): Response
    {
        return Paginator::response(
            ProfileService::loginQuery(self::uid()),
            $request,
            ProfileService::LOGIN_SORTABLE,
            'id',
            'desc',
            ProfileService::loginRowMapper()
        );
    }

    /**
     * 当前登录用户的 ID
     *
     * 只从令牌取，这是本控制器的安全基线。一旦有人图省事改成
     * `$request->post('id')`，个人中心立刻变成「改任意用户」的接口，
     * 而且因为它不需要权限点，谁都能调。
     *
     * @return int 用户 ID；理论上中间件已保证登录，取不到时返回 0 让后续查询落空
     */
    private static function uid(): int
    {
        return (int) (Ctx::user()['id'] ?? 0);
    }
}
