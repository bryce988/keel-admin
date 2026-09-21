<?php
/**
 * keel admin
 * 员工移动端 · 通用文件上传
 *
 * 与后台 `app/admin/controller/UploadController` 是**两个薄 controller、一份实现**——
 * 落盘逻辑全在 `common/service/UploadService`。
 *
 * 为什么不让移动端直接打 `/admin/upload`：它的令牌确实能过 `AdminAuthMiddleware`
 * （两端同一套身份），但端与端之间的接口是分开治理的——限流、渠道头、
 * 审计口径、网关路由都按前缀划分，混着调就没法分开治理了（PROJECT.md §8.1）。
 * 多一层薄 controller 的代价，换的是这个。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\staff\controller\v1;

use app\common\exception\BusinessException;
use app\common\service\UploadService;
use app\common\support\Ctx;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

class UploadController
{
    /**
     * 上传文件
     * @url POST /staff/v1/upload
     * @perm 登录即可
     * @description `multipart/form-data`，字段名 `file`，另可带 `biz`（业务标识）。
     * 约束与后台完全一致，见 docs/api.md §12.4。
     * @error 400 没选文件、类型不支持、超出大小上限
     * @error 429 上传过于频繁
     */
    public function store(Request $request): Response
    {
        $file = $request->file('file');

        if (!$file || !$file->isValid()) {
            throw new BusinessException('请选择要上传的文件');
        }

        return Result::created(UploadService::store(
            (int) ((Ctx::user() ?? [])['id'] ?? 0),
            $file,
            (string) $request->post('biz', 'common'),
        ));
    }
}
