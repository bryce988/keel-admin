<?php
/**
 * keel admin
 * 小k 的查询工具
 *
 * 每个工具是**某个现有 service 的薄封装**：不写 SQL、不 `withoutDataScope()`、不绕过 presenter。
 * 它跑在 `AuthService::actAs()` 里，数据权限与字段脱敏自动按提问人生效——
 * 工具自己一行权限代码都不该写（docs/ai-tech.md §5）。
 *
 * 新增工具：继承本类，登记到 `config/ai.php`。工具类放 `app/admin/ai/`
 * （它们要调 `app/admin/service/*`，而 common 不许 use 任何端的代码）。
 *
 * ⚠️ `app/admin/ai/` 与 `app/common/ai/` 下禁止出现 `withoutDataScope` / `Db::table` / 原生 SQL，
 * `scripts/acceptance.sh` 里有一条 grep 断言。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\ai;

abstract class AiTool
{
    /** 工具名，给模型看的函数名：小写下划线 */
    abstract public function name(): string;

    /**
     * 给模型看的说明：什么时候用、返回什么、口径是什么
     *
     * 这段文字是模型挑工具的唯一依据，写清楚比写得短重要。
     */
    abstract public function description(): string;

    /**
     * 参数的 JSON Schema（作为 function.parameters 发给模型）
     *
     * 不开 DeepSeek 的 strict 模式（要求所有属性 required，而查询条件大多可选），
     * 参数合法性由下面的 rules() 在服务端再判一次。
     */
    abstract public function parameters(): array;

    /**
     * 服务端校验规则，格式同 `Validator::make()`
     *
     * 参数决定查什么，不信任任何上游——包括模型。
     */
    abstract public function rules(): array;

    /** 所需权限点，与路由同一套语义：'' 登录即可；数组任一命中 */
    abstract public function perm(): string|array;

    /** 执行。此时 Ctx 里已经是提问人 */
    abstract public function run(array $args): ToolResult;

    /**
     * 这个权限点在界面上叫什么（没权限时告诉用户「需要『用户管理』权限」，不说权限点代码）
     */
    abstract public function permLabel(): string;

    /**
     * 结果里的敏感键，参数 `ai.field.unmask` 关闭（默认）时一律打码后再给模型，
     * 与提问人有没有明文权限无关（理由见 docs/ai-prd.md §4.3）
     *
     * @return array<string, 'phone'|'email'>
     */
    public function sensitive(): array
    {
        return [];
    }

    /** 发给模型的工具定义 */
    final public function definition(): array
    {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => $this->description(),
                'parameters'  => $this->parameters(),
            ],
        ];
    }
}
