<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\constant\BizCode;
use app\common\exception\BusinessException;
use app\common\support\Ctx;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * C 端渠道识别
 *
 * PROJECT.md §8.5 规定 C 端请求必带 X-Channel / X-App-Version / X-Device-Id。
 * 这三个头是后面所有事情的前提——灰度、强制更新、风控、埋点都要用，
 * 缺了以后再补会发现历史数据全是空的，所以在入口就拦。
 *
 * 渠道解析出来放进 Ctx，业务代码用 Ctx::get('channel') 取，
 * 不要在 service 里再去读 Request：那样 common/service 就绑死在 HTTP 上，
 * 队列和定时任务里再想复用就得造假请求对象。
 */
class ChannelMiddleware implements MiddlewareInterface
{
    /** 允许的渠道，新增渠道要在这里登记，避免客户端随便传 */
    private const CHANNELS = ['app-ios', 'app-android', 'mp-weixin', 'h5'];

    public function process(Request $request, callable $handler): Response
    {
        $channel = (string) $request->header('x-channel', '');

        if ($channel === '') {
            throw new BusinessException('缺少 X-Channel 请求头', BizCode::CHANNEL_HEADER_MISSING);
        }
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new BusinessException('不支持的渠道标识', BizCode::CHANNEL_UNSUPPORTED);
        }

        Ctx::set('channel', $channel);
        Ctx::set('app_version', (string) $request->header('x-app-version', ''));
        Ctx::set('device_id', (string) $request->header('x-device-id', ''));

        return $handler($request);
    }
}
