<?php
/**
 * keel admin
 * 个人中心
 *
 * 与 UserService 最本质的区别：这里没有「改别人」这条路径。
 * 所有方法的第一个参数都是当前登录用户 id（由控制器从令牌取），
 * 请求体里的 id 一律不看——越权在结构上就不成立，因此这些接口
 * 也不需要任何权限点（route.php 里 `perm => ''`）。
 *
 * 单独开一个 service 而不是往 UserService 里加方法，就是为了让
 * 「能改自己」和「能改别人」在代码上分家：后者每个入口都要过权限点与
 * 数据权限，前者一个都不需要，混在一起迟早有人把两套规则接错。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\service;

use app\common\constant\BizCode;
use app\common\exception\BusinessException;
use app\common\exception\ValidationException;
use app\common\model\SysLoginLogModel;
use app\common\model\SysUserModel;
use app\common\support\Arr;
use app\common\support\Guard;
use app\common\support\OpLog;
use Webman\Http\UploadFile;
use Illuminate\Database\Eloquent\Builder;

class ProfileService
{
    /** 我的登录记录允许排序的字段 */
    public const LOGIN_SORTABLE = ['id', 'created_at'];

    // ---------------------------------------------------------------- 资料

    /**
     * 个人资料
     *
     * 手机号按 §5 的规则脱敏后再返回。看自己的号码也脱敏是刻意的：
     * 界面上本来就不需要看全，而换绑时提交的是新号码，不依赖回显。
     */
    public static function detail(int $userId): array
    {
        /** @var SysUserModel $user */
        $user = Guard::found(SysUserModel::withoutDataScope()->with(['dept', 'post'])->find($userId));

        return [
            'id'             => $user->id,
            'username'       => $user->username,
            'real_name'      => $user->real_name,
            'avatar'         => $user->avatar,
            'phone'          => Arr::mask((string) $user->phone),
            'email'          => $user->email,
            'dept_name'      => $user->dept?->name ?? '',
            'post_name'      => $user->post?->name ?? '',
            'roles'          => $user->roles()->pluck('sys_roles.name')->all(),
            'status'         => $user->status,
            'is_super'       => (bool) $user->is_super,
            'pwd_updated_at' => $user->pwd_updated_at?->format('Y-m-d H:i:s'),
            'last_login_at'  => $user->last_login_at?->format('Y-m-d H:i:s'),
            'last_login_ip'  => $user->last_login_ip,
            'created_at'     => $user->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 修改资料
     *
     * 白名单三个字段。不要写成 `$user->fill($data)`：那样前端多传一个
     * `is_super` 或 `dept_id` 就直接提权/换部门了，而这两个字段恰恰是
     * 数据权限的判定依据。能改什么在这里穷举，是这个方法唯一的安全边界。
     */
    public static function update(int $userId, array $data): array
    {
        /** @var SysUserModel $user */
        $user = Guard::found(SysUserModel::withoutDataScope()->find($userId));

        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['email' => ['邮箱格式不正确']]);
        }

        $realName = trim((string) ($data['real_name'] ?? ''));
        if ($realName === '') {
            throw new ValidationException(['real_name' => ['请输入姓名']]);
        }

        OpLog::target("个人资料 {$user->username}");
        OpLog::diff($user->only(['real_name', 'email', 'avatar']), [
            'real_name' => $realName,
            'email'     => $email,
            'avatar'    => (string) ($data['avatar'] ?? $user->avatar),
        ]);

        $user->real_name = $realName;
        $user->email     = $email;
        $user->avatar    = (string) ($data['avatar'] ?? $user->avatar);
        $user->save();

        return self::detail($userId);
    }

    // ---------------------------------------------------------------- 换绑手机

    /**
     * 换绑手机号
     *
     * 用当前密码验证身份而不是短信验证码：Keel 不含业务逻辑，
     * 绑死某家短信服务商等于替使用者做选型。要接短信的项目把这里的
     * `password_verify` 换成验证码校验即可，调用方与前端都不用动。
     */
    public static function changePhone(int $userId, string $phone, string $password): void
    {
        /** @var SysUserModel $user */
        $user = Guard::found(SysUserModel::withoutDataScope()->find($userId));

        if (!password_verify($password, (string) $user->password)) {
            // 复用「原密码错误」，与修改密码同一个码：对用户来说是同一件事
            throw new BusinessException('密码错误', BizCode::OLD_PASSWORD_ERROR);
        }

        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            throw new ValidationException(['phone' => ['手机号格式不正确']]);
        }

        if ($phone === $user->phone) {
            throw new ValidationException(['phone' => ['新手机号与当前号码相同']]);
        }

        /*
         * 手机号没有唯一索引（允许为空、历史数据允许重复），所以只能查一遍。
         * 走 Guard::unique 而不是手写查询：它内部是 withoutGlobalScopes()，
         * 既绕开数据权限（看不见的人一样占着号码）也算上软删记录——
         * 漏掉任何一种，都会出现两个账号绑同一个号，将来接短信登录就撞了。
         */
        Guard::unique(SysUserModel::class, 'phone', $phone, $userId, '手机号已被其他账号使用', BizCode::PHONE_TAKEN);

        OpLog::target("个人资料 {$user->username}");
        // 新旧号码都脱敏后再进日志：操作日志本身是可导出的，
        // 明文写进去等于开了一个绕过字段级权限的口子
        OpLog::diff(
            ['phone' => Arr::mask((string) $user->phone)],
            ['phone' => Arr::mask($phone)]
        );

        $user->phone = $phone;
        $user->save();
    }

    // ---------------------------------------------------------------- 登录记录

    /**
     * 我的登录记录
     *
     * 刻意绕过数据权限：这是「我自己的」记录，与「我能看多大范围」无关。
     * 不绕的话，数据范围为「仅本人」的账号反而正常，而部门主管会在
     * 个人中心里看到整个部门的登录记录——同一个页面对不同人含义不同，是 bug 不是特性。
     * 绕过之后立刻用 user_id 收紧，范围只会比原来更小。
     */
    public static function loginQuery(int $userId): Builder
    {
        return SysLoginLogModel::withoutDataScope()->where('user_id', $userId);
    }

    public static function loginRowMapper(): callable
    {
        // 个人中心不显示账号列（全是自己），其余沿用日志页的字段
        return fn (SysLoginLogModel $row): array => [
            'id'         => $row->id,
            'ip'         => $row->ip,
            'location'   => $row->location,
            'browser'    => $row->browser,
            'os'         => $row->os,
            'type'       => $row->type,
            'status'     => $row->status,
            'msg'        => $row->msg,
            'created_at' => $row->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    // ================================================================ 头像

    /** 允许的图片扩展名。**写死在代码里**，不做成参数——可配置的白名单等于给了配错的机会 */
    private const AVATAR_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** 上传目录，相对 public/。换头像时只删这个前缀下的旧文件，防止把别处的路径带进来 */
    private const AVATAR_DIR = 'uploads/avatar';

    /**
     * 换头像
     *
     * 一步到位：校验通过就存盘并写库，不做「先传临时目录、保存时再转正」那一套。
     * 两段式要额外维护临时目录与孤儿文件清理，而头像这一个场景撑不起那套开销。
     *
     * 三道校验缺一不可：
     * - 扩展名白名单——挡住 .php/.svg 这类一眼就不该收的
     * - `getimagesize()` 二次确认真是图片——只看扩展名挡不住改个名字的文件
     * - 大小上限走系统参数 `sys.upload.avatarMaxSize`（默认 2MB），
     *   全局的 `sys.upload.maxSize`（20MB）对头像太宽松
     *
     * @param  UploadFile  $file  控制器已确认非空且 isValid()
     * @return string 新头像的相对路径，如 /uploads/avatar/202608/xxxx.png
     */
    public static function changeAvatar(int $userId, UploadFile $file): string
    {
        $ext = strtolower($file->getUploadExtension());
        if (!in_array($ext, self::AVATAR_EXTS, true)) {
            throw new BusinessException('只支持 ' . implode(' / ', self::AVATAR_EXTS) . '，收到的是 .' . $ext);
        }

        $max = (int) ParamService::value('sys.upload.avatarMaxSize', 2 * 1024 * 1024);
        if ($file->getSize() > $max) {
            throw new BusinessException('头像不能超过 ' . round($max / 1024 / 1024, 1) . 'MB');
        }

        // 扩展名可以随便改，图片头改不了。放在大小校验之后：别为一个 20MB 的伪造文件去解析
        if (@getimagesize($file->getPathname()) === false) {
            throw new BusinessException('这不是一张有效的图片');
        }

        /** @var SysUserModel $user */
        $user = Guard::found(SysUserModel::withoutDataScope()->find($userId));

        // 按年月分目录：一个目录堆到几十万个文件之后，连 ls 都要等半天
        $dir  = self::AVATAR_DIR . '/' . date('Ym');
        $name = bin2hex(random_bytes(8)) . '.' . $ext;

        // move() 自己会建目录，失败抛 FileException（500）。这里接一下换成能看懂的话——
        // 现实中这一步失败几乎只有一个原因：public/uploads 没写权限
        try {
            $file->move(public_path($dir . '/' . $name));
        } catch (\Throwable $e) {
            throw new BusinessException('头像保存失败，请检查 public/uploads 的写权限');
        }

        $old = (string) $user->avatar;
        $url = '/' . $dir . '/' . $name;

        OpLog::target("个人资料 {$user->username}");
        OpLog::diff(['avatar' => $old], ['avatar' => $url]);

        $user->avatar = $url;
        $user->save();

        self::removeOldAvatar($old);

        return $url;
    }

    /**
     * 删掉被替换下来的旧头像
     *
     * ⚠️ 只删 `uploads/avatar/` 前缀下的文件。库里的 avatar 理论上都是本服务写的，
     * 但这个值曾经可以由 `PUT /admin/profile` 的请求体直接指定——
     * 不加前缀判断的话，一个精心构造的值就能让这里去删别的文件。
     * 失败不抛异常：头像已经换好了，为一个删不掉的旧文件让整个请求失败不值得。
     */
    private static function removeOldAvatar(string $url): void
    {
        if ($url === '' || !str_starts_with($url, '/' . self::AVATAR_DIR . '/')) {
            return;
        }

        // 走 realpath 再比一次前缀，挡住 ../ 这类穿越
        $base = realpath(public_path(self::AVATAR_DIR));
        $path = realpath(public_path(ltrim($url, '/')));

        if ($base && $path && str_starts_with($path, $base) && is_file($path)) {
            @unlink($path);
        }
    }
}
