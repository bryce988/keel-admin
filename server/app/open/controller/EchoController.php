<?php

declare(strict_types=1);

namespace app\open\controller;

use app\common\support\Ctx;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

/**
 * 验签示例（空壳）
 *
 * 第三方对接时先调这个接口把签名算法调通，再接真实业务，
 * 省得在真实接口上一边调签名一边调业务。
 *
 * ⚠️ 支付回调、微信推送这类真实回调接口也放在 app/open，
 * 它们除了验签还必须**幂等**——同一笔回调会被重复推送（PROJECT.md §8.6）。
 */
class EchoController
{
    /**
     * 验签示例（空壳）
     *
     * `POST /open/echo` · **需要验签**
     *
     * 原样回显收到的参数与解析出的 `app_key`：签名验过了才进得来，
     * 所以拿到 200 就说明对方的签名算法与时间戳都对上了。
     *
     * @param Request $request 任意参数，外加签名相关的头/字段（由 SignMiddleware 校验）
     *
     * @return Response 200，`{app_key, method, params, signed_ok}`
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
