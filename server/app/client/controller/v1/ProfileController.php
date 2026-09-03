<?php
/**
 * keel admin
 * C 端个人资料
 *
 * 这里也是「员工 token 调 C 端接口返回 401」那条验收断言的载体（PROJECT.md §15）——
 * 需要一个挂了 ClientAuthMiddleware 的真实接口才验得了。
 *
 * C 端响应不得返回 dept_id、成本、内部备注这类字段（§8.5 响应裁剪），
 * 所以下发字段由 {@see \app\client\service\AuthService::publicUser()} 白名单决定。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\client\controller\v1;

use app\client\service\ProfileService;
use app\client\validation\Profile\UpdateRequest;
use app\common\exception\BusinessException;
use app\common\support\Ctx;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

class ProfileController
{
    /** 当前登录的 C 端用户 id，只从令牌取 */
    private static function uid(): int
    {
        return (int) (((array) Ctx::get('client_user', []))['id'] ?? 0);
    }

    /**
     * 个人资料
     * @url GET /client/v1/profile
     * @perm 登录即可
     * @description 需要 C 端令牌与 `X-Channel` 渠道头。手机号中间四位打码，
     * 头像是绝对地址（App 没有「当前域名」可用来补全相对路径）。
     * @error 401 `10102` 登录凭证类型不匹配（后台令牌调这里）
     */
    public function index(Request $request): Response
    {
        return Result::ok(ProfileService::detail(self::uid()));
    }

    /**
     * 修改资料
     * @url PUT /client/v1/profile
     * @perm 登录即可
     * @description 目前只有昵称。手机号是登录账号，改它要走换绑流程（未实现）。
     */
    public function update(UpdateRequest $request): Response
    {
        return Result::ok(ProfileService::update(self::uid(), $request->validated()));
    }

    /**
     * 换头像
     * @url POST /client/v1/profile/avatar
     * @perm 登录即可
     * @description multipart 上传，字段名 `file`。一步到位：上传成功即写库，
     * 响应就是更新后的资料，不需要再调一次 `PUT /client/v1/profile`。
     * @error 400 没选文件、扩展名不在白名单、超过 2MB，或内容不是真图片
     */
    public function avatar(Request $request): Response
    {
        $file = $request->file('file');

        if (!$file || !$file->isValid()) {
            throw new BusinessException('请选择要上传的图片');
        }

        return Result::ok(ProfileService::changeAvatar(self::uid(), $file));
    }
}
