<?php
/**
 * keel admin
 * 岗位
 *
 * 岗位是 HR 概念，不是角色：`default_role_id` 只在**新建**用户时被前端读走、
 * 用作角色框的初始值；编辑用户改岗位一律不动角色（docs/database.md §3.3）。
 * 这个边界一旦被打破，「改一下岗位结果一批人权限变了」就会变成线上事故。
 *
 * 本服务只负责把这个字段存好、并通过 options() 下发，不参与授权。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\service;

use app\common\constant\BizCode;
use app\common\model\SysPostModel;
use app\common\model\SysUserModel;
use app\common\support\Db;
use app\common\support\Guard;
use app\common\support\OpLog;
use Illuminate\Database\Eloquent\Builder;

class PostService
{
    public const SORTABLE = ['id', 'sort', 'status', 'created_at'];

    public static function listQuery(array $filters): Builder
    {
        $query = SysPostModel::query()->with('dept:id,name');

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")->orWhere('code', 'like', "%{$keyword}%");
            });
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('status', (int) $filters['status']);
        }

        if (!empty($filters['dept_id'])) {
            $query->whereIn('dept_id', DeptService::subtreeIds((int) $filters['dept_id']));
        }

        return $query;
    }

    public static function rowMapper(): callable
    {
        return fn (SysPostModel $row): array => [
            'id'              => $row->id,
            'name'            => $row->name,
            'code'            => $row->code,
            'dept_id'         => $row->dept_id,
            // dept_id = 0 表示全公司通用，没有关联部门，这里给一个明确的文案而不是空白
            'dept_name'       => $row->dept_id === 0 ? '全公司通用' : ($row->dept?->name ?? ''),
            'default_role_id' => $row->default_role_id,
            'sort'            => $row->sort,
            'status'          => $row->status,
            'remark'          => $row->remark,
            'created_at'      => $row->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    public static function detail(int $id): array
    {
        /** @var SysPostModel $post */
        $post = Guard::found(SysPostModel::find($id));

        return $post->toArray();
    }

    /**
     * 下拉选项：给用户表单选岗位用
     *
     * 带上 `default_role_id`，前端新建用户选中岗位时据此预填角色——
     * 不带的话前端只能再逐个查岗位详情，或者把整个分页列表拉下来筛。
     *
     * 只给启用的：停用岗位不该出现在「给新人选岗位」的下拉里。
     * 但已经挂在停用岗位上的存量用户不受影响，那是历史数据，
     * 编辑他时下拉里选不到当前值，界面会显示成空——这是有意的，
     * 提醒操作者这个人的岗位已经废弃了，该重新选一个。
     */
    public static function options(): array
    {
        return SysPostModel::query()
            ->enabled()
            ->orderBy('sort')
            ->get(['id', 'name', 'code', 'dept_id', 'default_role_id'])
            ->map(fn (SysPostModel $p) => [
                'id'              => $p->id,
                'name'            => $p->name,
                'code'            => $p->code,
                'dept_id'         => $p->dept_id,
                'default_role_id' => $p->default_role_id,
            ])
            ->all();
    }

    public static function create(array $data): SysPostModel
    {
        Guard::unique(SysPostModel::class, 'code', $data['code'], null, '岗位编码已存在', BizCode::POST_CODE_EXISTS);

        // dept_id = 0 是「全公司通用」，天然不在任何受限集合里 → 只有全部数据范围能建
        Guard::inDeptScope((int) ($data['dept_id'] ?? 0));

        return Db::transaction(function () use ($data) {
            $post = new SysPostModel();
            $post->fill($data);
            $post->save();

            OpLog::target("岗位 {$post->name}({$post->id})");

            return $post;
        });
    }

    public static function update(int $id, array $data): SysPostModel
    {
        /** @var SysPostModel $post */
        $post = Guard::found(SysPostModel::find($id));

        Guard::unique(SysPostModel::class, 'code', $data['code'], $id, '岗位编码已存在', BizCode::POST_CODE_EXISTS);
        Guard::inDeptScope((int) ($data['dept_id'] ?? $post->dept_id), (int) $post->dept_id);

        $before = $post->toArray();

        return Db::transaction(function () use ($post, $data, $before) {
            $post->fill($data);
            $post->save();

            OpLog::target("岗位 {$post->name}({$post->id})");
            OpLog::diff($before, $post->toArray());

            return $post;
        });
    }

    public static function delete(int $id): void
    {
        /** @var SysPostModel $post */
        $post = Guard::found(SysPostModel::find($id));

        Guard::notReferenced(
            SysUserModel::class,
            'post_id',
            $id,
            '该岗位下存在用户，无法删除',
            BizCode::POST_HAS_USERS,
        );

        OpLog::target("岗位 {$post->name}({$post->id})");

        $post->delete();
    }
}
