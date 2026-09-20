<?php
/**
 * keel admin
 * 通用文件上传
 *
 * 契约见 docs/api.md §12.4。落盘逻辑全在 `UploadService`，这里只做三件事：
 * 取文件、判空、把上传人从令牌里取出来交给 service。
 *
 * **不写任何业务表**——返回的地址由调用方自己存进各自的字段（聊天消息的 extra、
 * 公告正文里的图片地址）。换头像是另一条路（`POST /admin/profile/avatar`）：
 * 它上传即写库、还要删旧图，与这里的「纯落盘」不是一类事。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\common\exception\BusinessException;
use app\common\service\UploadService;
use app\common\support\Ctx;
use app\common\support\Result;
use support\Request;
use support\Response;

class UploadController
{
    /**
     * 上传文件
     * @url POST /admin/upload
     * @perm -
     * @description 登录即可。`multipart/form-data`，字段名 `file`，另可带 `biz`
     * （业务标识，决定归档目录，白名单 common/chat/notice，传错按 common 处理）。
     * 返回 `{url, name, size, ext}`，`url` 是不带域名的相对路径。
     *
     * **不挂权限点**是想清楚的：上传本身不是敏感动作，真正的边界在「传上来的文件被用在哪」
     * ——发消息要过会话成员校验、换头像只能改自己。挂一个 `sys:upload` 只会变成
     * 人人都授予的空权限点，既不增加安全性，又多一个会忘记配进权限树的东西
     * （那种漏配的表现是永久 403，只有用户点到才会发现）。
     *
     * 也**不记操作日志**：一次聊天发九张图就是九条日志，会把真正要审计的动作淹掉。
     * 文件用在哪里，哪里自己记。
     *
     * @error 400 没选文件、扩展名不在白名单、超出 `sys.upload.maxSize`，或图片内容不是真图片
     * @error 429 上传过于频繁（每人 60 次/分钟），响应头带 Retry-After
     */
    public function store(Request $request): Response
    {
        $file = $request->file('file');

        if (!$file || !$file->isValid()) {
            throw new BusinessException('请选择要上传的文件');
        }

        $biz = (string) $request->post('biz', 'common');

        // 201：这是一次资源创建，且响应体是新建资源本身（api.md §1.2）
        return Result::created(UploadService::store(self::uid(), $file, $biz));
    }

    /** 上传人只从令牌取，不从请求读——它是限流的计数键，可被伪造就等于没有限流 */
    private static function uid(): int
    {
        return (int) (Ctx::user()['id'] ?? 0);
    }
}
