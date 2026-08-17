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
