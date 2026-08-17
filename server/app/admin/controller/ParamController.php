<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\ParamService;
use app\common\support\Result;
use app\common\support\Validator;
use support\Response;
use Webman\Http\Request;

/**
 * 参数配置
 *
 * 一个分组就是一张表单，整组提交（PUT /admin/params），不做逐条保存——
 * 同组参数彼此相关（失败次数与锁定时长），逐条保存会留下半新半旧的中间态。
 */
class ParamController
{
    /** 按分组查询；不传 group 返回全部 */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'group' => ['string|in:' . implode(',', array_keys(ParamService::GROUPS)), '分组'],
        ])->validated();

        return Result::ok(ParamService::listByGroup((string) ($filters['group'] ?? '')));
    }

    /** 分组元信息，前端拿来渲染 tab，避免把中文标题写死在页面里 */
    public function groups(Request $request): Response
    {
        $out = [];
        foreach (ParamService::GROUPS as $code => $name) {
            $out[] = ['code' => $code, 'name' => $name];
        }

        return Result::ok($out);
    }

    /** 登录页要的少量参数，不需要登录态 */
    public function publicParams(Request $request): Response
    {
        return Result::ok(ParamService::publicParams());
    }

    public function show(Request $request, int $id): Response
    {
        return Result::ok(ParamService::detail($id));
    }

    /** 批量保存：[{param_key, param_value}, ...] */
    public function batchUpdate(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'items' => ['required|array', '参数列表'],
        ])->validated();

        $items = [];
        foreach ((array) $data['items'] as $item) {
            if (!is_array($item) || !isset($item['param_key'])) {
                continue;
            }

            $items[] = [
                'param_key'   => (string) $item['param_key'],
                'param_value' => (string) ($item['param_value'] ?? ''),
            ];
        }

        return Result::ok(['saved_count' => ParamService::saveMany($items)]);
    }

    public function store(Request $request): Response
    {
        return Result::created(ParamService::create(self::validate($request, true)));
    }

    public function update(Request $request, int $id): Response
    {
        return Result::ok(ParamService::update($id, self::validate($request, false)));
    }

    public function destroy(Request $request, int $id): Response
    {
        ParamService::delete($id);

        return Result::noContent();
    }

    /**
     * @param  bool  $creating  新增时参数键必填；编辑内置参数时 service 会忽略键与类型
     */
    private static function validate(Request $request, bool $creating): array
    {
        return Validator::make($request->all(), [
            'group'       => [($creating ? 'required|' : '') . 'string|in:' . implode(',', array_keys(ParamService::GROUPS)), '分组'],
            'name'        => [($creating ? 'required|' : '') . 'string|max:64', '参数名称'],
            'param_key'   => [($creating ? 'required|' : '') . 'code|max:128',  '参数键'],
            // 参数值不限内容：json 类型的参数整段存在这里
            'param_value' => ['string',                                          '参数值'],
            'value_type'  => ['string|in:string,int,bool,json',                   '值类型'],
            'is_secret'   => ['bool',                                             '密钥'],
            'remark'      => ['string|max:255',                                   '备注'],
        ])->validated();
    }
}
