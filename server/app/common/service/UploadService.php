<?php
/**
 * keel admin
 * 通用文件上传
 *
 * 契约见 docs/api.md §12.4。与 ProfileService::changeAvatar() 的关系：
 * 那个是**头像专用**的一步到位接口（上传即写库、顺带删旧图），这个是**不写任何业务表**的
 * 纯落盘服务，返回地址由调用方自己存进各自的业务字段。两者共用同一套校验思路，
 * 但不合并——头像有「替换旧文件」这一步，通用上传没有，硬合并会让两边都长出 if。
 *
 * ## 为什么不做「临时目录 + 业务保存后转正」
 *
 * 两段式要求每个调用方在业务保存成功后记得再调一次转正。漏一个，文件就被临时目录的
 * 清理任务删掉，而业务记录还指着那个地址——表现是「过几天图片打不开了」，
 * 且要等清理周期之后才暴露，排查时很难联想到是上传漏了一步。
 *
 * 一步到位的代价是用户选了文件却没提交时留下孤儿文件，成本是磁盘；
 * 两段式漏转正的代价是数据损坏。两害相权选磁盘。
 *
 * 孤儿文件由**各业务自己的留存清理**带走：哪个业务用了这里返回的地址，
 * 哪个业务就在自己的清理任务里把过期记录和对应文件一起删掉
 * （参照 LogCleanupService 的做法）。不为上传单独建一套清理机制——
 * 它不知道文件被谁用着，独立清理只能靠「多久没人引用」猜，猜错就是删了在用的文件。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use app\common\exception\RateLimitException;
use app\common\support\Cache;
use Webman\Http\UploadFile;

class UploadService
{
    /**
     * 允许的扩展名，按用途分组。**写死在代码里**，不做成系统参数——
     * 可配置的白名单等于给了配错的机会，而配错一次就是一个上传型漏洞。
     *
     * 没有 `svg` 和 `html`，这是有意的：两者都能内嵌 <script>，
     * 而 /uploads/ 是同域直出的，浏览器打开就是一次 XSS。
     * 要支持矢量图得先解决直出问题（强制下载或换独立域名），不是加一行的事。
     * 同理没有 php/phtml/htaccess——那一类连想都不该想。
     */
    private const EXT_IMAGE   = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const EXT_DOC     = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv'];
    private const EXT_ARCHIVE = ['zip', 'rar', '7z'];

    /**
     * 业务标识白名单，决定落盘的子目录。
     *
     * 不在白名单里的值**按 common 处理而不是报错**：这个参数只影响文件归档在哪个目录，
     * 传错了不构成安全问题，为它返回 400 只会让调用方在联调时多卡一次。
     * 真正要防的是 `biz` 被拿来做路径穿越，而白名单从根上杜绝了这件事——
     * 落盘目录永远只能是这几个常量之一，不拼接任何外部输入。
     */
    private const BIZ_DIRS = ['common', 'chat', 'notice'];

    /** 每人每分钟的上传次数上限。admin 端没有限流中间件（那是 C 端才挂的），所以在这里自己兜一道 */
    private const RATE_LIMIT  = 60;
    private const RATE_WINDOW = 60;

    /**
     * 存盘并返回可访问地址
     *
     * 校验顺序是设计过的，不要调换：
     * 1. 限流放最前——被限的请求不该消耗任何解析成本
     * 2. 扩展名白名单——挡住一眼就不该收的
     * 3. 大小上限——放在内容校验之前，别为一个 20MB 的伪造文件去解析图片头
     * 4. 图片内容二次确认——扩展名能随便改，图片头改不了
     *
     * @param  int         $userId  上传人，只用于限流计数
     * @param  UploadFile  $file    控制器已确认非空且 isValid()
     * @param  string      $biz     业务标识，见 BIZ_DIRS
     * @return array{url: string, name: string, size: int, ext: string}
     */
    public static function store(int $userId, UploadFile $file, string $biz = 'common'): array
    {
        self::guardRate($userId);

        $ext = strtolower($file->getUploadExtension());
        if (!in_array($ext, self::allowedExts(), true)) {
            throw new BusinessException('不支持的文件类型 .' . $ext);
        }

        /*
         * ⚠️ 大小和原始文件名必须在 move() **之前**取。
         *
         * getSize() 走的是 SplFileInfo::stat()，读的是 /tmp 下那个临时文件；
         * move() 之后临时文件已经没了，再调就是
         * 「stat failed for /tmp/workerman.upload.xxx」的 500。
         * 这个坑只有返回 size 的接口会踩——换头像不返回 size，所以一直没暴露。
         */
        $size = $file->getSize();
        $originalName = self::safeName($file->getUploadName());

        $max = (int) ParamService::value('sys.upload.maxSize', 20 * 1024 * 1024);
        if ($size > $max) {
            throw new BusinessException('文件不能超过 ' . self::humanSize($max));
        }

        if (in_array($ext, self::EXT_IMAGE, true) && @getimagesize($file->getPathname()) === false) {
            throw new BusinessException('这不是一张有效的图片');
        }

        // 按年月分目录：一个目录堆到几十万个文件之后，连 ls 都要等半天
        $dir = 'uploads/' . (in_array($biz, self::BIZ_DIRS, true) ? $biz : 'common') . '/' . date('Ym');

        // 落盘名一律随机，**绝不使用原始文件名**：原始名里可能有 ../、控制字符、
        // 超长串，甚至双扩展名（a.php.png）。随机名从根上让这些都进不了文件系统
        $name = bin2hex(random_bytes(8)) . '.' . $ext;

        // move() 自己会建目录，失败抛 FileException（500）。这里接一下换成能看懂的话——
        // 现实中这一步失败几乎只有一个原因：public/uploads 没写权限
        try {
            $file->move(public_path($dir . '/' . $name));
        } catch (\Throwable $e) {
            throw new BusinessException('文件保存失败，请检查 public/uploads 的写权限');
        }

        return [
            // 相对路径不带域名：换域名、上 CDN 时库里存的数据不用动
            'url'  => '/' . $dir . '/' . $name,
            // 原始文件名只回给前端做展示，不参与任何路径。净化一遍是因为它会被
            // 存进业务表（聊天消息的 extra.name）再渲染出来，脏数据别进库
            'name' => $originalName,
            'size' => $size,
            'ext'  => $ext,
        ];
    }

    /**
     * 把字节数说成人话
     *
     * 别直接 `round($n / 1024 / 1024, 1) . 'MB'`：上限被调成 1KB 时，
     * 它会算出「文件不能超过 0MB」——技术上没错，但用户完全不知道该怎么办。
     * 小于 1MB 就用 KB 说，至少是个能照着做的数字。
     */
    private static function humanSize(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? round($bytes / 1024 / 1024, 1) . 'MB'
            : max(1, (int) round($bytes / 1024)) . 'KB';
    }

    /** @return list<string> */
    private static function allowedExts(): array
    {
        return [...self::EXT_IMAGE, ...self::EXT_DOC, ...self::EXT_ARCHIVE];
    }

    /**
     * 上传频率限制
     *
     * 计数必须放 Redis 而不是进程内变量：webman 是多进程模型，同一个人的连续请求会落到
     * 不同 worker 上，进程内计数各数各的，实际阈值会被放大到 worker 数量倍（PROJECT.md §14）。
     *
     * 按 user_id 而不是按 IP：内网里整个办公室共用一个出口 IP 是常态，按 IP 会误伤。
     * 而能走到这里的请求都已经过了鉴权，user_id 是可信的。
     */
    private static function guardRate(int $userId): void
    {
        $key   = 'rl:upload:' . $userId;
        $count = Cache::incr($key, self::RATE_WINDOW);

        if ($count > self::RATE_LIMIT) {
            throw new RateLimitException('上传过于频繁，请稍后再试', max(Cache::ttl($key), 1));
        }
    }

    /**
     * 净化原始文件名
     *
     * 只做展示用，所以目标不是「还原成合法文件名」而是「不带任何能被利用的东西」：
     * 去掉路径分隔符与控制字符，压掉长度。为空则给个兜底名，
     * 免得前端显示一个空白的可点击项。
     */
    private static function safeName(string $name): string
    {
        $name = str_replace(['/', '\\', "\0"], '', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '') {
            return '未命名文件';
        }

        return mb_substr($name, 0, 120);
    }
}
