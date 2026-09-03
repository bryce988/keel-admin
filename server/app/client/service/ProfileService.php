<?php
/**
 * keel admin
 * C 端个人资料
 *
 * id 一律从令牌取（`Ctx::get('client_user')`），任何方法都不接收「要改谁」的参数——
 * 结构上就没有「改别人」的路径，所以这里一个权限点都不需要。
 * 同样的取法见后台的 {@see \app\admin\service\ProfileService}。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\client\service;

use app\common\exception\BusinessException;
use app\common\model\AppUserModel;
use app\common\support\Guard;
use Webman\Http\UploadFile;

class ProfileService
{
    /** 允许的图片扩展名，与后台头像同一套。写死不做成参数——可配置的白名单等于给了配错的机会 */
    private const AVATAR_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** 上传目录，相对 public/。与后台头像分开放，便于按端做清理与配额 */
    private const AVATAR_DIR = 'uploads/app-avatar';

    /** 头像大小上限（字节）。C 端不读 sys.upload.* 参数：那是后台的运营配置，两端的取值场景不同 */
    private const AVATAR_MAX = 2 * 1024 * 1024;

    public static function detail(int $userId): array
    {
        /** @var AppUserModel $user */
        $user = Guard::found(AppUserModel::query()->find($userId), '账号不存在');

        // created_at 是 Eloquent 自动转成的 Carbon，直接塞进数组会被 json_encode
        // 序列化成 ISO8601（2026-09-02T18:57:24.000000Z）——契约要求 'Y-m-d H:i:s'。
        // BaseModel 的 serializeDate() 只在 toArray() 路径上生效，这里是手拼数组
        return AuthService::publicUser($user) + [
            'last_login_at' => (string) $user->last_login_at,
            'created_at'    => $user->created_at?->format('Y-m-d H:i:s') ?? '',
        ];
    }

    public static function update(int $userId, array $data): array
    {
        /** @var AppUserModel $user */
        $user = Guard::found(AppUserModel::query()->find($userId), '账号不存在');

        $user->nickname = trim((string) ($data['nickname'] ?? $user->nickname));
        $user->save();

        return AuthService::publicUser($user);
    }

    /**
     * 换头像
     *
     * 三道校验与后台那份一致，缺一不可：扩展名白名单挡住 .php/.svg，
     * `getimagesize()` 二次确认真是图片（扩展名可以随便改，图片头改不了），
     * 大小上限挡住把服务器磁盘当网盘用。
     *
     * 没有抽成公共方法：两端的目录、上限来源、失败文案都不同，
     * 强行合并会得到一个满是 if 的「通用上传」，那比两份各自清楚的代码更难改。
     *
     * @param  UploadFile $file 控制器已确认非空且 isValid()
     * @return array 更新后的用户资料（含绝对地址的头像），前端直接拿来刷新界面
     */
    public static function changeAvatar(int $userId, UploadFile $file): array
    {
        $ext = strtolower($file->getUploadExtension());
        if (!in_array($ext, self::AVATAR_EXTS, true)) {
            throw new BusinessException('只支持 ' . implode(' / ', self::AVATAR_EXTS) . '，收到的是 .' . $ext);
        }

        if ($file->getSize() > self::AVATAR_MAX) {
            throw new BusinessException('头像不能超过 ' . round(self::AVATAR_MAX / 1024 / 1024, 1) . 'MB');
        }

        // 放在大小校验之后：别为一个 20MB 的伪造文件去解析
        if (@getimagesize($file->getPathname()) === false) {
            throw new BusinessException('这不是一张有效的图片');
        }

        /** @var AppUserModel $user */
        $user = Guard::found(AppUserModel::query()->find($userId), '账号不存在');

        // 按年月分目录：一个目录堆到几十万个文件之后，连 ls 都要等半天
        $dir  = self::AVATAR_DIR . '/' . date('Ym');
        $name = bin2hex(random_bytes(8)) . '.' . $ext;

        try {
            $file->move(public_path($dir . '/' . $name));
        } catch (\Throwable $e) {
            throw new BusinessException('头像保存失败，请稍后重试');
        }

        $old = (string) $user->avatar;

        $user->avatar = '/' . $dir . '/' . $name;
        $user->save();

        self::removeOldAvatar($old);

        return AuthService::publicUser($user);
    }

    /**
     * 删掉被替换下来的旧头像
     *
     * 只删本目录前缀下的文件，并用 realpath 再比一次前缀挡住 ../ 穿越。
     * 失败不抛异常：头像已经换好了，为一个删不掉的旧文件让整个请求失败不值得。
     */
    private static function removeOldAvatar(string $url): void
    {
        if ($url === '' || !str_starts_with($url, '/' . self::AVATAR_DIR . '/')) {
            return;
        }

        $base = realpath(public_path(self::AVATAR_DIR));
        $path = realpath(public_path(ltrim($url, '/')));

        if ($base && $path && str_starts_with($path, $base) && is_file($path)) {
            @unlink($path);
        }
    }
}
