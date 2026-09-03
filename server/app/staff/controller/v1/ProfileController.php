<?php
/**
 * keel admin
 * 员工移动端 · 个人资料
 *
 * id 一律从令牌取（`Ctx::user()`），任何方法都不接收「要改谁」的参数——
 * 结构上就没有「改别人」的路径。业务逻辑全在 {@see ProfileService}，
 * 与后台个人中心是同一份实现；这里只负责裁剪响应形状（见 {@see StaffPresenter}）。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\staff\controller\v1;

use app\common\exception\BusinessException;
use app\common\service\ProfileService;
use app\common\support\Ctx;
use app\common\support\Result;
use app\staff\support\StaffPresenter;
use app\staff\validation\Profile\UpdateRequest;
use support\Response;
use Webman\Http\Request;

class ProfileController
{
    private static function uid(): int
    {
        return (int) ((Ctx::user() ?? [])['id'] ?? 0);
    }

    /**
     * 个人资料
     * @url GET /staff/v1/profile
     * @perm 登录即可
     * @description 比登录返回的身份多手机号、邮箱、岗位、上次登录。
     * 手机号按字段级权限脱敏（与后台同一套规则，服务端做，不是前端打码）。
     */
    public function index(Request $request): Response
    {
        $detail = ProfileService::detail(self::uid());
        $detail['avatar'] = StaffPresenter::absoluteUrl((string) ($detail['avatar'] ?? ''));

        return Result::ok($detail);
    }

    /**
     * 改资料
     * @url PUT /staff/v1/profile
     * @perm 登录即可
     * @description 手机上只开放姓名与邮箱。手机号换绑要验当前密码，是独立流程（api.md §11）。
     */
    public function update(UpdateRequest $request): Response
    {
        $detail = ProfileService::update(self::uid(), $request->validated());
        $detail['avatar'] = StaffPresenter::absoluteUrl((string) ($detail['avatar'] ?? ''));

        return Result::ok($detail);
    }

    /**
     * 换头像
     * @url POST /staff/v1/profile/avatar
     * @perm 登录即可
     * @description multipart 上传，字段名 `file`。上传成功即写库，
     * 响应里的 `avatar` 是**绝对地址**，客户端直接拿去显示。
     * @error 400 没选文件、扩展名不在白名单、超出 `sys.upload.avatarMaxSize`，或内容不是真图片
     */
    public function avatar(Request $request): Response
    {
        $file = $request->file('file');

        if (!$file || !$file->isValid()) {
            throw new BusinessException('请选择要上传的图片');
        }

        return Result::ok([
            'avatar' => StaffPresenter::absoluteUrl(ProfileService::changeAvatar(self::uid(), $file)),
        ]);
    }
}
