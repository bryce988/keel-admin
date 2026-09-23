<?php
/**
 * keel admin
 * 小k 工具：通讯录
 *
 * 通讯录**本来就是全公司可见**的（`ContactService` 刻意绕开了数据权限，理由见那边的类注释），
 * 所以这个工具的口径是「全公司」，不是提问人的数据范围。手机号邮箱仍受字段级权限管，
 * 且 AI 层默认再打一次码。
 *
 * 与 search_users 的区别：它只能查在职的人、只有找人需要的字段，但普通员工也能用。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\ai;

use app\common\ai\ToolResult;
use app\common\service\ContactService;

class SearchContactsTool extends QueryTool
{
    public function name(): string
    {
        return 'search_contacts';
    }

    public function description(): string
    {
        return '通讯录找人：按姓名/账号关键词或部门（含下级）查在职同事，返回姓名、部门、岗位。通讯录对全公司可见，不受数据范围限制，'
            . '但只有在职人员、不含账号状态与登录信息。问「某某在哪个部门」「某部门有哪些同事」用它。';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'keyword' => ['type' => 'string', 'description' => '姓名或账号片段'],
                'dept_id' => ['type' => 'integer', 'description' => '部门 id（含下级部门）'],
                'limit'   => self::limitProp(),
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'keyword' => ['string|max:50', '关键词'],
            'dept_id' => ['integer|min:1', '部门'],
            'limit'   => ['integer|min:1|max:50', '条数'],
        ];
    }

    public function perm(): string|array
    {
        return 'contact:view';
    }

    public function permLabel(): string
    {
        return '通讯录';
    }

    public function sensitive(): array
    {
        return ['phone' => 'phone', 'email' => 'email'];
    }

    public function run(array $args): ToolResult
    {
        $page = ContactService::list([
            'keyword'   => $args['keyword'] ?? '',
            'dept_id'   => $args['dept_id'] ?? 0,
            'page_size' => $this->limit($args),
        ]);

        $rows = array_map(static function (array $r) {
            unset($r['avatar']);

            return $r;
        }, $page['list']);

        return new ToolResult(
            rows: $rows,
            total: (int) $page['total'],
            label: self::describeFilters('通讯录', [!empty($args['keyword']) ? "关键词「{$args['keyword']}」" : null]),
            scopeNote: '通讯录全公司可见（仅在职人员）',
        );
    }
}
