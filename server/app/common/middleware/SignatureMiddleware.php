<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\exception\UnauthorizedException;
use app\common\support\Cache;
use app\common\support\Env;
use app\common\support\Ctx;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 开放平台 HMAC 验签
 *
 * 请求需带四个头：
 *   X-App-Key    第三方应用标识
 *   X-Timestamp  Unix 秒，与服务端时间差超过窗口即拒绝
 *   X-Nonce      随机串，窗口内不可重复
 *   X-Signature  HMAC-SHA256(app_secret, 待签串) 的十六进制
 *
 * 待签串 = "{METHOD}\n{PATH}\n{按键排序并 urlencode 的参数}\n{timestamp}\n{nonce}"
 *
 * 三道防线缺一不可：
 * - 签名保证请求没被篡改
 * - 时间戳把重放窗口压到 5 分钟
 * - nonce 让窗口内的重放也只能成功一次（Redis SETNX）
 * 只验签名不验时间戳，抓到一个包就能永久重放。
 *
 * ⚠️ app_key/app_secret 现在从 .env 读，只够一个第三方用。
 * 二期接入多个第三方时改为 open_apps 表 + 按 AppID 授权 scope（PROJECT.md §8.1）。
 */
class SignatureMiddleware implements MiddlewareInterface
{
    private const WINDOW = 300;   // 时间戳容差（秒），双向

    public function process(Request $request, callable $handler): Response
    {
        // 探测类接口不验签：第三方要先能确认服务可达、拿到服务端时间来对齐时间戳，
        // 否则第一次对接就会卡在「签名过期」而不知道是自己的时钟不准
        if ($request->route?->param('skip_signature') === true) {
            return $handler($request);
        }

        $appKey    = (string) $request->header('x-app-key', '');
        $timestamp = (int) $request->header('x-timestamp', 0);
        $nonce     = (string) $request->header('x-nonce', '');
        $signature = (string) $request->header('x-signature', '');

        if ($appKey === '' || $nonce === '' || $signature === '' || $timestamp === 0) {
            throw new UnauthorizedException('缺少签名参数', 40101);
        }

        $secret = self::secretOf($appKey);
        if ($secret === null) {
            throw new UnauthorizedException('未知的 app_key', 40104);
        }

        if (abs(time() - $timestamp) > self::WINDOW) {
            throw new UnauthorizedException('签名已过期', 40102);
        }

        // nonce 只在时间窗口内需要保留，过期自动回收
        if (!Cache::setNx("open:nonce:{$appKey}:{$nonce}", self::WINDOW * 2)) {
            throw new UnauthorizedException('请求已被处理，请勿重复提交', 40103);
        }

        $expected = self::sign($request, $secret, $timestamp, $nonce);

        // hash_equals 而非 ===：避免按字符比较的时序侧信道
        if (!hash_equals($expected, $signature)) {
            throw new UnauthorizedException('签名校验失败', 40101);
        }

        Ctx::set('app_key', $appKey);

        return $handler($request);
    }

    /** 生成待签串并计算签名，客户端按同样规则拼即可 */
    public static function sign(Request $request, string $secret, int $timestamp, string $nonce): string
    {
        $params = $request->all();
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE));
        }

        $payload = implode("\n", [
            strtoupper($request->method()),
            $request->path(),
            implode('&', $pairs),
            (string) $timestamp,
            $nonce,
        ]);

        return hash_hmac('sha256', $payload, $secret);
    }

    private static function secretOf(string $appKey): ?string
    {
        $configured = (string) Env::get('OPEN_APP_KEY', '');
        $secret     = (string) Env::get('OPEN_APP_SECRET', '');

        if ($configured === '' || $secret === '' || !hash_equals($configured, $appKey)) {
            return null;
        }

        return $secret;
    }
}
