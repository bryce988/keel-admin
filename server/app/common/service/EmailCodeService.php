<?php
/**
 * keel admin
 * 邮箱验证码
 *
 * 生成、限流、校验。发信本身在 MailService，这里不关心信怎么送出去。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\service;

use app\common\exception\RateLimitException;
use app\common\support\Cache;
use app\common\support\Env;

/**
 * 验证码存 Redis，理由与图形验证码一样：webman 多进程，发码和验码
 * 大概率落在不同的 worker 上，进程内变量各存各的（PROJECT.md §14.1）。
 *
 * ## 三道闸各挡各的
 *
 * - **重发间隔**（`EMAIL_CODE_RESEND_SECONDS`，60 秒）挡连点，用户侧的倒计时
 *   只是界面提示，真正的拦截在这里——按钮禁用绕过去太容易了
 * - **每日上限**（`EMAIL_CODE_DAILY_LIMIT`，10 次）挡「拿到一个有效账号后
 *   拿它的邮箱当轰炸目标」。间隔闸只能把频率压到每分钟一次，一天仍是 1440 封
 * - **验证次数**（`EMAIL_CODE_MAX_ATTEMPTS`，5 次）挡爆破。六位数字 100 万种组合，
 *   没有这道闸的话，5 分钟有效期内足够把它试穿
 *
 * 三个计数器都以邮箱为维度，不掺 IP：验证码是发到那个邮箱去的，
 * 换 IP 并不能让攻击者多收到一封信，掺 IP 反而给了绕过每日上限的口子。
 */
class EmailCodeService
{
    /** 场景：登录。将来若加「换绑邮箱」，用新的场景值，两边的码不能互相顶用 */
    public const SCENE_LOGIN = 'login';

    private const PREFIX = 'email:code:';

    /**
     * 生成并存一个码，返回码本身交给调用方去发
     *
     * 先占坑再发信：两道限流闸在生成的这一刻就落下，
     * 否则并发的两个请求会同时通过检查、各发一封。
     */
    public static function start(string $email, string $scene): string
    {
        $base = self::base($email, $scene);

        $cool = Env::int('EMAIL_CODE_RESEND_SECONDS', 60);
        if ($cool > 0 && !Cache::setNx($base . ':cool', $cool)) {
            // SET NX 而不是 exists + set：并发下两个请求都能通过 exists
            throw new RateLimitException('验证码发送过于频繁，请稍后再试', max(Cache::ttl($base . ':cool'), 1));
        }

        $daily = Env::int('EMAIL_CODE_DAILY_LIMIT', 10);
        if ($daily > 0 && Cache::incr($base . ':day', 86400) > $daily) {
            throw new RateLimitException('该邮箱今日验证码次数已达上限，请明天再试', max(Cache::ttl($base . ':day'), 1));
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::set($base, $code, self::ttl());
        // 上一个码的失败次数不能留给新码，否则连发两次之后新码只剩几次机会
        Cache::del($base . ':try');

        return $code;
    }

    /**
     * 发信失败时回滚
     *
     * 清掉码与重发间隔——信没送出去，让用户马上能再试一次。
     * **每日计数不回滚**：SMTP 挂了的时候它是唯一还拦得住反复触发的东西，
     * 而多算几次的代价只是当天少几次配额。
     */
    public static function rollback(string $email, string $scene): void
    {
        $base = self::base($email, $scene);

        Cache::del($base);
        Cache::del($base . ':cool');
    }

    /**
     * 校验，成功即销毁
     *
     * 失败次数超限时连码一起作废：只计数不作废的话，攻击者等计数窗口过去
     * 还能接着试同一个码。
     */
    public static function verify(string $email, string $scene, string $code): bool
    {
        if ($code === '') {
            return false;
        }

        $base   = self::base($email, $scene);
        $cached = Cache::get($base);

        if ($cached === null) {
            return false;
        }

        $max = Env::int('EMAIL_CODE_MAX_ATTEMPTS', 5);
        if ($max > 0 && Cache::incr($base . ':try', self::ttl()) > $max) {
            Cache::del($base);
            Cache::del($base . ':try');

            throw new RateLimitException('验证码错误次数过多，请重新获取', 1);
        }

        // hash_equals：验证码比对是安全比对，别给计时攻击留缝
        if (!hash_equals($cached, $code)) {
            return false;
        }

        Cache::del($base);
        Cache::del($base . ':try');
        // 用掉即解除重发间隔：下一次登录不该被上一次的倒计时拦着
        Cache::del($base . ':cool');

        return true;
    }

    /** 验证码有效期（秒），前端拿它显示「N 分钟内有效」 */
    public static function ttl(): int
    {
        return max(60, Env::int('EMAIL_CODE_TTL', 300));
    }

    /**
     * 键名里的邮箱取小写后哈希
     *
     * 小写：邮箱域名大小写不敏感，`A@x.com` 与 `a@x.com` 必须命中同一组计数器，
     * 否则改个大小写就能把重发间隔和每日上限一起绕过去。
     * 哈希：Redis 的键会出现在 `KEYS`、慢日志、监控面板里，不必把全站邮箱摊在那儿。
     */
    private static function base(string $email, string $scene): string
    {
        return self::PREFIX . $scene . ':' . md5(strtolower(trim($email)));
    }
}
