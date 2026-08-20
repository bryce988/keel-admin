<?php
/**
 * keel admin
 * 开放平台验签示例（空壳）
 *
 * 第三方对接时先调这个接口把签名算法调通，再接真实业务，
 * 省得在真实接口上一边调签名一边调业务。
 *
 * ⚠️ 支付回调、微信推送这类真实回调接口也放在 app/open，
 * 它们除了验签还必须幂等——同一笔回调会被重复推送（PROJECT.md §8.6）。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\open\controller;

use app\common\support\Ctx;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

class EchoController
{
    /**
     * 验签示例
     * @url ANY /open/echo
     * @perm -
     * @description 需要验签（SignatureMiddleware），返回 `{app_key, method, params, signed_ok}`。
     * 原样回显收到的参数与解析出的 `app_key`：签名验过了才进得来，
     * 所以拿到 200 就说明对方的签名算法与时间戳都对上了。
     * 路由用的是 `Route::any`，任何方法都能打——方便第三方用 GET 先试通再换成实际方法。
     * @error 401 `40101` 缺少签名参数或校验失败 · 401 `40102` 签名已过期
     * · 401 `40103` 重复提交 · 401 `40104` 未知的 app_key · 403 `40301` 来源 IP 不在白名单
     */
    public function index(Request $request): Response
    {
        return Result::ok([
            'app_key'   => Ctx::get('app_key'),
            'method'    => $request->method(),
            'params'    => $request->all(),
            'signed_ok' => true,
        ]);
    }
}
