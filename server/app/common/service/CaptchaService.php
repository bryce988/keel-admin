<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\support\Cache;
use app\common\support\Env;

/**
 * 图形验证码
 *
 * 用 SVG 生成，不依赖 GD 扩展，容器镜像更小。
 * 验证码存 Redis 而不是进程内变量——多进程模型下进程内存各自独立，
 * 生成和校验很可能落在不同进程上（PROJECT.md §14.1）。
 */
class CaptchaService
{
    private const CHARS = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public static function generate(): array
    {
        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= self::CHARS[random_int(0, strlen(self::CHARS) - 1)];
        }

        $key = 'captcha:' . bin2hex(random_bytes(8));
        Cache::set($key, strtolower($code), Env::int('CAPTCHA_TTL', 120));

        return [
            'captchaKey'   => $key,
            'captchaImage' => 'data:image/svg+xml;base64,' . base64_encode(self::render($code)),
        ];
    }

    /** 校验后立即删除，防止同一验证码重复使用 */
    public static function verify(string $key, string $code): bool
    {
        if ($key === '' || $code === '') {
            return false;
        }

        $cached = Cache::get($key);
        Cache::del($key);

        return $cached !== null && strtolower($code) === $cached;
    }

    private static function render(string $code): string
    {
        $w = 120;
        $h = 40;
        $colors = ['#409eff', '#67c23a', '#e6a23c', '#f56c6c', '#909399'];

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">',
            $w, $h, $w, $h
        );
        $svg .= '<rect width="100%" height="100%" fill="#f5f7fa"/>';

        // 干扰线
        for ($i = 0; $i < 4; $i++) {
            $svg .= sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1" opacity="0.4"/>',
                random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h),
                $colors[array_rand($colors)]
            );
        }

        // 干扰点
        for ($i = 0; $i < 20; $i++) {
            $svg .= sprintf(
                '<circle cx="%d" cy="%d" r="1" fill="%s" opacity="0.5"/>',
                random_int(0, $w), random_int(0, $h), $colors[array_rand($colors)]
            );
        }

        // 字符：随机旋转与位移
        $len = strlen($code);
        for ($i = 0; $i < $len; $i++) {
            $x = 18 + $i * 26 + random_int(-3, 3);
            $y = 28 + random_int(-3, 3);
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="Arial,Helvetica,sans-serif" font-size="24" font-weight="bold"'
                . ' fill="%s" transform="rotate(%d %d %d)">%s</text>',
                $x, $y, $colors[array_rand($colors)], random_int(-20, 20), $x, $y, $code[$i]
            );
        }

        return $svg . '</svg>';
    }
}
