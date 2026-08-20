<?php

declare(strict_types=1);

namespace app\admin\validation;

use app\common\support\Validator;
use Webman\Http\Request;

/**
 * 表单请求（Laravel FormRequest 风格）
 *
 * 每个写/查动作建一个 Request 类，按业务模块分子目录（如 `User/StoreRequest`），
 * 控制器方法直接把 `Request $request` 换成它，参数注入阶段就完成校验，
 * 失败即 422、控制器方法不会执行：
 *
 *   // app/admin/validation/User/StoreRequest.php
 *   final class StoreRequest extends FormRequest
 *   {
 *       protected function rules(): array
 *       {
 *           return [
 *               'username' => ['required|string|min:2|max:64', '账号'],
 *           ];
 *       }
 *   }
 *
 *   // 控制器
 *   public function store(StoreRequest $request): Response
 *   {
 *       $data = $request->validated();   // 只含规则声明过的键，已按类型转型
 *   }
 *
 * 之所以能「替换 Request」：webman 的参数注入会把控制器方法里不在内置类型
 * 白名单里的类参数交给容器构造（vendor/workerman/webman-framework/src/App.php
 * 的 resolveMethodDependencies），构造函数里声明的 {@see Request} 会被注入
 * 当前请求对象。
 *
 * 校验仍走 {@see Validator}——不继承 webman/validation 的 Validator 基类，
 * 所以 422 + 字段级 details、空串视同未填、类型转型这些增强一条不丢
 * （原因详见 config/plugin/webman/validation/app.php）。
 */
abstract class FormRequest
{
    private array $data;
    private array $validated;
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->data = $request->all();
        // 构造即校验：失败在参数注入阶段就抛出，控制器方法根本不会执行
        $this->validated = Validator::make($this->data, $this->rules())->validated();
    }

    /**
     * 字段 => [规则, 中文名]，格式与 {@see Validator::make()} 第二个参数一致
     */
    abstract protected function rules(): array;

    /**
     * 校验通过后的干净数据（只含规则声明过的键，已按类型转型）
     */
    public function validated(): array
    {
        return $this->validated;
    }

    /**
     * 原始输入，含未声明校验的键（如 role_ids 这种控制器自己处理的字段）
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * 取单个原始输入（查询串 + 请求体），用于没进校验白名单、但控制器要用的字段
     *
     * ⚠️ 写接口的可选字段不要用它，用 {@see self::post()}。
     * 底层是 `$request->all()`，而 webman 的 `all()` 是 `get() + post()`——
     * `+` 号意味着同名键查询串盖过请求体。用户接口踩过这个坑：
     * `role_ids` 的三态语义是「不传=不动角色、空数组=清空」，
     * 改用 `input()` 之后一个 `PUT /admin/users/3?role_ids=` 就能绕过请求体
     * 把这个人的角色清空（实测 `[3]` → `[]`）。
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * 只取请求体里的单个原始输入
     *
     * 写接口（POST/PUT）的可选字段一律用这个：请求体是客户端明确要写的东西，
     * 查询串不是。见 {@see self::input()} 里那个把角色清空的例子。
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->request->post($key, $default);
    }

    /**
     * 原始 webman 请求，需要上传文件（如导入接口）时用
     */
    public function request(): Request
    {
        return $this->request;
    }
}
