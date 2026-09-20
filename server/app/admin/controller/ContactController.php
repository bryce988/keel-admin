<?php
/**
 * keel admin
 * 通讯录
 *
 * 全员可见的组织架构只读视图。与「用户管理」的区别见 {@see ContactService} 的类注释——
 * 一句话：那边是管理能力（`sys:user:*`，受数据权限约束，能改），
 * 这边是找人（`contact:view`，全公司可见，只读）。
 *
 * 业务放 `common/service` 而不是 `admin/service`：员工移动端迟早要同一份，
 * 那时只加一个薄 controller 就行（PROJECT.md §8.2）。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\ContactService;
use app\common\support\Result;
use support\Request;
use support\Response;

class ContactController
{
    /**
     * 部门树
     * @url GET /admin/contacts/depts
     * @perm contact:view
     * @description **全公司的树，不受数据权限约束**——通讯录存在的意义恰恰是
     * 让人找到别的部门的同事。只返回启用的部门。
     */
    public function depts(Request $request): Response
    {
        return Result::ok(ContactService::deptTree());
    }

    /**
     * 人员列表
     * @url GET /admin/contacts
     * @perm contact:view
     * @description `?dept_id=&keyword=&page_num=&page_size=`。
     * `dept_id` 默认**含子部门**（平铺列表用）；`include_children=0` 只取本级，
     * 给树形展示用——树里子部门是独立节点，父节点再算进来同一个人会出现两次。
     * 只返回在职员工。手机号与邮箱受字段级权限管，没授权的拿到掩码——
     * 数据范围放开不等于字段放开。
     */
    public function index(Request $request): Response
    {
        $data = ContactService::list([
            'dept_id'   => (int) $request->get('dept_id', 0),
            // 树形展示传 0：树里子部门已经是独立节点，父节点再算进来会让人重复出现
            'include_children' => filter_var($request->get('include_children', '1'), FILTER_VALIDATE_BOOLEAN),
            'keyword'   => (string) $request->get('keyword', ''),
            'page_num'  => (int) $request->get('page_num', 1),
            'page_size' => (int) $request->get('page_size', 20),
        ]);

        return Result::page($data['list'], $data['total'], $data['page_num'], $data['page_size']);
    }

    /**
     * 人员详情
     * @url GET /admin/contacts/{id}
     * @perm contact:view
     * @error 404 人不存在或已停用（两种情况不区分——对调用方都是「通讯录里没这个人」）
     */
    public function show(Request $request, int $id): Response
    {
        return Result::ok(ContactService::detail($id));
    }
}
