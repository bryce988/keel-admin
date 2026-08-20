<?php

/**
 * webman/validation 插件配置
 *
 * **这里只有 app.php，没有 middleware.php，是有意的。**
 *
 * 插件自带的 `middleware.php` 会往 `'@'`（所有应用）挂一个全局中间件，
 * 用来支持 `#[Validate]` / `#[Param]` 注解式校验。我们不用它，因为它的失败路径是
 * `throw new ValidationException($validator->errors()->first(), 400)`——
 * **只带第一条消息、状态码 400、异常基类是 `Webman\Exception\BusinessException`**，
 * 三条都对不上 docs/api.md 约定的 422 + `{code,message,traceId,details}`（details 是字段级的）。
 *
 * 所以项目里只有一条校验路径：`app\common\support\Validator`，它直接用
 * `Webman\Validation\Factory\ValidationFactory` 拿到的 illuminate Factory，
 * 自己把 MessageBag 转成我们的 `ValidationException`。
 *
 * 如果哪天真要用注解，记得先解决错误体的问题，再把 middleware.php 补回来——
 * 光加注解不加中间件是**静默不生效**的。
 */
return [
    'enable' => true,
    // 只在插件自己的 validate() 里用得到；我们不走那条路，保留默认值即可
    'exception' => support\validation\ValidationException::class,
];
