<?php

declare(strict_types=1);

namespace app\common\support;

use app\common\exception\ValidationException;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Factory;
use Webman\Validation\Factory\ValidationFactory;

/**
 * 参数校验
 *
 * 底层是 illuminate/validation（经 webman/validation 接进来），这一层只做四件
 * 它不做、而我们的接口契约又必须有的事：
 *
 *   1. 错误体：转成 `ValidationException`，details 是 `{字段: [消息]}`，
 *      HTTP 422 + 业务码 10422（docs/api.md §3）。插件自带的 `validate()` 只抛
 *      第一条消息、状态码 400、异常基类还是 BusinessException，全对不上，所以不用它
 *   2. 一个字段只报一条：Laravel 默认把该字段所有没过的规则都列出来，
 *      前端表单项下方一次弹三行很难看
 *   3. 空串视同未填：前端清空输入框传的是 `''`。Laravel 里 `''` 是个实实在在的值，
 *      `in:0,1` 会直接判失败。这里对非 required 字段跳过规则、但把原值带出去——
 *      带出去是必要的：字典项的 `tag_type` 就是靠提交 `''` 来清空标签颜色的，
 *      丢掉这个键等于「清空」变成「不修改」
 *   4. 转型：integer 规则给 int、boolean 给 bool、string 顺手 trim，
 *      免得 service 里到处 `(int)`（GET 查询串过来的全是字符串）
 *
 * 用法：
 *
 *   $data = Validator::make($request->all(), [
 *       'username' => ['required|string|min:2|max:64', '账号'],
 *       'status'   => ['required|integer|in:0,1',      '状态'],
 *       'role_ids' => ['array',                        '角色'],
 *       'role_ids.*' => ['integer|min:1',              '角色'],
 *   ])->validated();
 *
 * 规则名一律用 Laravel 的原名（`integer` / `numeric` / `boolean` / `size`），
 * 只额外注册了 `code` 与 `phone` 两条（见 {@see self::registerRules()}）。
 * 第一个元素也可以直接给数组，这样能用上 `Rule::in()` / `Rule::unique()` 这类规则对象：
 *
 *   'code' => [['required', Rule::unique('sys_dict_type', 'code')], '字典编码'],
 *
 * 只做「格式合法性」。业务规则（账号是否重复、部门有没有下级）留在 service 层——
 * 前者失败是 422 字段级回填，后者是 409/400 的业务提示，交互完全不同。
 * 未声明的键一律丢弃。
 */
final class Validator
{
    /**
     * 自定义规则只需注册一次
     *
     * static 存的是进程级基础设施（规则注册到了共享的 Factory 上），不是请求态，
     * 符合 PROJECT.md §14 的常驻内存约束。ValidationFactory 里的 Factory 本身也是
     * 进程内单例，语言包只在首次用到时读一次盘。
     */
    private static bool $extended = false;

    private function __construct(
        private readonly array $data,
        private readonly array $rules,
    ) {
    }

    /**
     * @param  array  $rules  字段 => [规则, 中文名]；规则可以是 `'a|b'` 或 `['a', 'b']`
     */
    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    /** @throws ValidationException 有任一字段不合法 */
    public function validated(): array
    {
        $rules       = [];
        $labels      = [];
        $passthrough = [];

        foreach ($this->rules as $field => $spec) {
            [$rule, $label] = is_array($spec) ? [$spec[0], $spec[1] ?? $field] : [$spec, $field];

            $list = is_array($rule)
                ? array_values($rule)
                : array_values(array_filter(explode('|', (string) $rule), static fn (string $r): bool => $r !== ''));

            // 空串/未传 且 非必填：不跑规则，原值原样带出（见类注释第 3 点）
            if (self::isBlank($this->data[$field] ?? null) && !in_array('required', $list, true)) {
                if (array_key_exists($field, $this->data)) {
                    $passthrough[$field] = $this->data[$field];
                }

                continue;
            }

            $rules[$field]  = $list;
            $labels[$field] = (string) $label;
        }

        // 列表页所有筛选项都空着时会走到这里。空规则数组喂给插件会抛 InvalidArgumentException
        if ($rules === []) {
            return $passthrough;
        }

        $validator = self::factory()->make($this->data, $rules, [], $labels);
        if ($validator->fails()) {
            throw new ValidationException(self::firstPerField($validator->errors()));
        }

        $clean = [];
        foreach ($validator->validated() as $field => $value) {
            $clean[$field] = self::cast($value, $rules[$field] ?? []);
        }

        return $clean + $passthrough;
    }

    /**
     * 共享的 illuminate Factory，附带项目自定义规则
     *
     * 不走 `support\validation\Validator::make()`：它内部是
     * `support\Container::make(static::class)`，而 `support\Container::instance()`
     * 取的是 `Config::get('container')`——webman 的 Config 没引导时返回 null，
     * 于是 `Call to a member function make() on null` 直接致命。
     * HTTP 请求里当然是引导好的，但 `scripts/` 下的 CLI 脚本不是，
     * 校验器是通用件，不该只能在 HTTP 上下文里活。直接拿 Factory 还少一层反射。
     * （CLI 里唯一的降级是 locale 取不到配置会退回 en，不会崩。）
     *
     * `code` 与 `phone` 原本是手写校验器的内置规则，全仓二十来处在用。
     * 与其把 `code` 全改成 `regex:/^[A-Za-z0-9_:.\-]+$/`（正则里的 `|` 还会跟
     * 规则分隔符打架），不如注册成规则名。消息在
     * `resource/translations/zh_CN/validation.php` 里，改正则记得一起改。
     */
    private static function factory(): Factory
    {
        $factory = ValidationFactory::getFactory();

        if (self::$extended) {
            return $factory;
        }

        // 权限点、字典编码、参数键这类标识符：字母数字加 _ : . -
        $factory->extend('code', static fn (string $attribute, mixed $value): bool =>
            is_scalar($value) && preg_match('/^[A-Za-z0-9_:.\-]+$/', (string) $value) === 1);

        // 中国大陆手机号
        $factory->extend('phone', static fn (string $attribute, mixed $value): bool =>
            is_scalar($value) && preg_match('/^1[3-9]\d{9}$/', (string) $value) === 1);

        self::$extended = true;

        return $factory;
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /** 每个字段只留第一条消息 */
    private static function firstPerField(MessageBag $errors): array
    {
        $details = [];
        foreach ($errors->keys() as $field) {
            $details[$field] = [(string) $errors->first($field)];
        }

        return $details;
    }

    /**
     * 按类型规则转型
     *
     * 优先级固定，不看规则书写顺序——同一个字段不会既是 integer 又是 string。
     * `string` 顺手 trim：Laravel 的自动 trim 是 Laravel 框架的中间件干的，
     * 换到 webman 就没有了，而「用户名前面多个空格」是真会发生的。
     */
    private static function cast(mixed $value, array $rules): mixed
    {
        if (in_array('integer', $rules, true)) {
            return (int) $value;
        }
        if (in_array('numeric', $rules, true)) {
            return $value + 0;
        }
        if (in_array('boolean', $rules, true)) {
            return in_array($value, [true, 1, '1', 'true'], true);
        }
        if (in_array('string', $rules, true)) {
            return trim((string) $value);
        }

        return $value;
    }
}
