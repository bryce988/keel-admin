<?php
/**
 * keel admin
 * 小k 工具：查某个员工的详情
 *
 * 走 `UserService::listQuery()`（带数据权限）按 id 取一行，范围外的人与不存在的人
 * 表现完全一样——「没找到」，不确认记录存在。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\ai;

use app\admin\service\UserService;
use app\common\ai\ToolResult;
use app\common\exception\NotFoundException;

class GetUserTool extends QueryTool
{
    public function name(): string
    {
        return 'get_user';
    }

    public function description(): string
    {
        return '按用户 id 查一个员工的详细资料（部门、岗位、角色、状态、最近登录时间、创建时间）。'
            . 'id 通常来自 search_users 的结果。不在可见范围内的人会返回「没有找到」。';
    }

    public function parameters(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => ['id' => ['type' => 'integer', 'description' => '用户 id']],
            'required'             => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return ['id' => ['required|integer|min:1', '用户']];
    }

    public function perm(): string|array
    {
        return 'sys:user:detail';
    }

    public function permLabel(): string
    {
        return '用户详情';
    }

    public function sensitive(): array
    {
        return ['phone' => 'phone', 'email' => 'email'];
    }

    public function run(array $args): ToolResult
    {
        $user = UserService::listQuery([])->whereKey($args['id'])->first();
        if (!$user) {
            throw new NotFoundException();
        }

        $row = (UserService::rowMapper())($user);
        unset($row['avatar']);
        $row['roles'] = $user->roles()->pluck('sys_roles.name')->all();

        $status = $this->dictLabels('user_status');
        $row['status_text'] = $status[(string) $row['status']] ?? (string) $row['status'];

        return new ToolResult(
            rows: [$row],
            total: 1,
            label: '用户详情：' . ($row['real_name'] ?: $row['username']),
            scopeNote: $this->scopeNote(),
            link: [
                'path'  => '/system/user',
                'query' => ['keyword' => (string) $row['username']],
                'label' => '在用户列表中查看',
            ],
        );
    }
}
