<?php
/**
 * keel admin
 * 邮件发送
 *
 * 只做一件事：把一封信交给 SMTP 服务器。收件人是谁、内容写什么由调用方决定。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\service;

use app\common\constant\BizCode;
use app\common\exception\BusinessException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use support\Log;
use Throwable;

/**
 * ## 配置来源：参数表优先，`.env` 兜底
 *
 * 「系统管理 / 参数配置 / 系统配置」这一组（`sys.mail.*`）填了就用它，
 * 留空则回落到 `.env` 的 `MAIL_*`。两条路并存是有原因的：
 * 容器化部署习惯把配置注进环境变量、一次配好不进库；而没有部署权限的人
 * 要能在界面上改。回落的粒度是**单个键**，所以「host 走 .env、口令在界面上换」
 * 这种混着来的用法也是成立的。
 *
 * ⚠️ 口令进了参数表就等于进了数据库。`is_secret` 让它在列表接口里只回掩码
 * （`ParamService::MASK`），操作日志里也只记「已更新」不记明文——但挡不住的是：
 * 有 `sys:param:update` 的人虽然看不到旧口令，却能把 `sys.mail.host` 改到自己的
 * 服务器上，此后全站的邮箱验证码都投到那里去。**这个权限点只该给运维**。
 * 要彻底避开这条，就别在界面上填，只用 `.env`。
 *
 * ## 为什么同步发，不走队列
 *
 * 项目里已经有队列（导出走的就是它），但那是因为导出要几十秒、必然超时。
 * 发一封信是秒级的，而调用方（登录页点「发送验证码」）**必须当场知道成没成**：
 * 异步投递之后接口只能回「已发送」，SMTP 认证配错了用户也只会盯着空邮箱等，
 * 前端没有任何可显示的错误。
 *
 * 代价是这段时间 worker 被占住（webman 是阻塞模型，见 PROJECT.md §14）。
 * 所以超时必须显式设死（`MAIL_TIMEOUT`，默认 10 秒）——PHP 的默认
 * socket 超时是 `default_socket_timeout`（通常 60 秒），一台不响应的 SMTP
 * 能把发信接口的每个请求各占住一分钟。而这条路径本身要先过图形验证码、
 * 邮箱+密码校验与 60 秒重发间隔，攻击者拿不到批量触发的机会。
 *
 * ## 每次发信新建连接
 *
 * symfony/mailer 的 transport 可以复用连接，但常驻进程里持有一条 SMTP 长连接
 * 就是又一个「连接被对端悄悄掐掉、下一条命令才发现」的问题（同 Cache 的注释）。
 * 发信是低频操作，一次握手的开销远比一套重连逻辑便宜。
 */
class MailService
{
    /**
     * 是否配好了发信
     *
     * 前端据此决定要不要显示「邮箱登录」入口——没配 SMTP 还把入口摆出来，
     * 用户点了发码只会拿到一个错误。
     */
    public static function configured(): bool
    {
        return self::conf('sys.mail.host', 'MAIL_HOST') !== ''
            && self::conf('sys.mail.from', 'MAIL_FROM') !== '';
    }

    /**
     * 发一封信（同步，失败抛异常）
     *
     * @param  string  $html  正文，纯文本 fallback 由调用方给，别指望收信端会渲染 HTML
     */
    public static function send(string $to, string $subject, string $html, string $text): void
    {
        if (!self::configured()) {
            throw new BusinessException('系统未配置邮件服务，请联系管理员', BizCode::MAIL_NOT_CONFIGURED);
        }

        try {
            $transport = self::transport();

            try {
                $transport->send(self::message($to, $subject, $html, $text));
            } finally {
                // 不 stop 的话连接会挂到 transport 被回收为止，异常路径上尤其明显
                $transport->stop();
            }
        } catch (Throwable $e) {
            // 原始错误只进日志：SMTP 的报错常带主机名与账号，回给未登录的调用方
            // 等于把发信配置抖给了公网
            Log::error('邮件发送失败', [
                'to'    => $to,
                'host'  => self::conf('sys.mail.host', 'MAIL_HOST'),
                'error' => $e->getMessage(),
            ]);

            throw new BusinessException('邮件发送失败，请稍后重试', BizCode::MAIL_SEND_FAILED);
        }
    }

    private static function transport(): EsmtpTransport
    {
        // ssl = 建连即 TLS（465）；tls = 明文建连后 STARTTLS 升级（587）；none = 不加密
        $encryption = strtolower(self::conf('sys.mail.encryption', 'MAIL_ENCRYPTION', 'ssl'));
        $port       = (int) self::conf('sys.mail.port', 'MAIL_PORT') ?: ($encryption === 'ssl' ? 465 : 587);

        $transport = new EsmtpTransport(self::conf('sys.mail.host', 'MAIL_HOST'), $port, $encryption === 'ssl');

        if ($encryption === 'none') {
            // 默认会在服务端声明 STARTTLS 时自动升级。显式配 none 的场景
            // （容器内的本地中继）通常压根不支持，让它别试
            $transport->setAutoTls(false);
        }

        $username = self::conf('sys.mail.username', 'MAIL_USERNAME');
        if ($username !== '') {
            $transport->setUsername($username);
            $transport->setPassword(self::conf('sys.mail.password', 'MAIL_PASSWORD'));
        }

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout((float) (self::env('MAIL_TIMEOUT', '10')));
        }

        return $transport;
    }

    private static function message(string $to, string $subject, string $html, string $text): Email
    {
        $from = self::conf('sys.mail.from', 'MAIL_FROM');
        $name = self::conf('sys.mail.fromName', 'MAIL_FROM_NAME')
            ?: (string) ParamService::value('sys.name', 'Keel');

        return (new Email())
            ->from(new Address($from, $name))
            ->to($to)
            ->subject($subject)
            ->text($text)
            ->html($html);
    }

    /**
     * 取一项配置：参数表优先，`.env` 兜底
     *
     * 参数表里存的是**空串**而不是 NULL（`sys_params.param_value` 非空），
     * 所以「没配」与「配了空」在这里是同一件事——都回落到 `.env`。
     * 这正是想要的语义：界面上把某项清空 = 「这项我不管，按部署时配的来」。
     *
     * 参数读一次走 Redis 缓存（`ParamService::value`，300 秒），
     * 保存时按键清除，所以界面上改完立刻生效，不用等缓存过期。
     */
    private static function conf(string $paramKey, string $envKey, string $default = ''): string
    {
        $value = trim((string) (ParamService::value($paramKey) ?? ''));

        return $value !== '' ? $value : self::env($envKey, $default);
    }

    /**
     * 读环境变量，不做类型魔法
     *
     * `Env::get()` 会把 `"false"` / `"null"` 这类字符串转成对应的 PHP 值——
     * 对开关是便利，对**口令**是错的：密码恰好是 `null` 时会读成 null，
     * 而且是静默的（认证失败，看不出原因）。这里的值全是要原样送给 SMTP 的字符串。
     */
    private static function env(string $key, string $default = ''): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }
}
