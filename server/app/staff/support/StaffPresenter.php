<?php
/**
 * keel admin
 * 员工移动端的响应形状
 *
 * 同一份数据，两端两种形状——这正是接口分端的意义所在：
 *
 * - **字段裁剪**：手机上不需要 perm_version、token_version、creator_id 这些内部字段，
 *   它们对客户端毫无用处，却让每次响应都胖一圈（弱网下这是真实成本）
 * - **头像补成绝对地址**：后台走 vite proxy 与同域 nginx，相对路径直接可用；
 *   App 的「当前域名」是本地文件系统，补出来是个打不开的路径。
 *   在服务端拼而不是让客户端拼，是因为「用哪个域名」是部署方的知识（`APP_URL`），
 *   不该编译进别人手机里的安装包
 *
 * 放在 app/staff 而不是 common：这是**这一端**怎么呈现的决定，
 * 别的端不该被它约束。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\staff\support;

use app\common\model\SysDeptModel;
use app\common\service\PermissionService;
use app\common\support\Env;

class StaffPresenter
{
    /** 登录与工作台共用的「我是谁 + 我能干什么」 */
    public static function identity(array $user): array
    {
        return [
            'user'        => self::user($user),
            'permissions' => PermissionService::codesOf($user),
        ];
    }

    public static function user(array $user): array
    {
        // 部门名要单查：Ctx 里的用户是 sys_users 一行，只有 dept_id。
        // withoutDataScope()：这是「我自己的部门」，不该被数据权限过滤掉——
        // 「仅本人」范围的员工连自己部门的名字都查不出来，界面上就成了空白
        $dept = ($user['dept_id'] ?? 0)
            ? SysDeptModel::withoutDataScope()->find((int) $user['dept_id'])
            : null;

        return [
            'id'        => (int) ($user['id'] ?? 0),
            'username'  => (string) ($user['username'] ?? ''),
            'real_name' => (string) ($user['real_name'] ?? ''),
            'avatar'    => self::absoluteUrl((string) ($user['avatar'] ?? '')),
            'dept_name' => $dept->name ?? '',
            'is_super'  => (bool) ($user['is_super'] ?? false),
        ];
    }

    /**
     * 相对路径补成绝对地址
     *
     * `APP_URL` 没配就原样返回相对路径——降级成「头像显示不出来」，
     * 而不是拼出一个 `http:///uploads/...` 这样的坏地址。
     */
    public static function absoluteUrl(string $path): string
    {
        if ($path === '' || str_starts_with($path, 'http')) {
            return $path;
        }

        $base = rtrim((string) Env::get('APP_URL', ''), '/');

        return $base === '' ? $path : $base . $path;
    }
}
