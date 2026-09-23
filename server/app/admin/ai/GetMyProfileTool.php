<?php
/**
 * keel admin
 * 小k 工具：我自己的资料
 *
 * 「我是谁、我在哪个部门、我有什么角色、我能看到哪些数据」。
 * id 只从当前身份取，没有「查别人」的路径（与个人中心同一个设计）。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\ai;

use app\common\ai\ToolResult;
use app\common\model\scope\DataScope;
use app\common\service\ProfileService;
use app\common\support\Ctx;

class GetMyProfileTool extends QueryTool
{
    public function name(): string
    {
        return 'get_my_profile';
    }

    public function description(): string
    {
        return '查询提问人自己的资料：姓名、账号、部门、岗位、角色、数据范围（能看到哪些部门的数据）、最近登录时间与 IP。'
            . '问「我是什么角色」「我能看到哪些数据」「我上次什么时候登录」时用它。';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function rules(): array
    {
        return [];
    }

    public function perm(): string|array
    {
        return '';
    }

    public function permLabel(): string
    {
        return '个人资料';
    }

    public function sensitive(): array
    {
        return ['phone' => 'phone', 'email' => 'email'];
    }

    public function run(array $args): ToolResult
    {
        $p = ProfileService::detail(Ctx::userId());
        unset($p['avatar'], $p['id']);
        $p['data_scope'] = DataScope::describe() ?? '全部数据';

        return new ToolResult(
            rows: [$p],
            total: 1,
            label: '我的资料',
            link: ['path' => '/profile', 'query' => [], 'label' => '打开个人中心'],
        );
    }
}
