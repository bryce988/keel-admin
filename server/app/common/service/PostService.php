<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\model\SysPostModel;
use app\common\model\SysUserModel;
use app\common\support\Db;
use app\common\support\Guard;
use app\common\support\OpLog;
use Illuminate\Database\Eloquent\Builder;

/**
 * 岗位
 *
 * 岗位是 HR 概念，**不是角色**：它只在新建用户时带出 `default_role_id`
 * 作为初始值，之后改岗位不会动已有账号的授权（docs/database.md §3.3）。
 * 这个边界一旦被打破，「改一下岗位结果一批人权限变了」就会变成线上事故。
 */
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

    public static function create(array $data): SysPostModel
    {
        Guard::unique(SysPostModel::class, 'code', $data['code'], null, '岗位编码已存在', 20201);

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

        Guard::unique(SysPostModel::class, 'code', $data['code'], $id, '岗位编码已存在', 20201);

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
            20203
        );

        OpLog::target("岗位 {$post->name}({$post->id})");

        $post->delete();
    }
}
