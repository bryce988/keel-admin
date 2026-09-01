<?php

declare(strict_types=1);

namespace app\common\support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * 富文本净化
 *
 * 公告正文是富文本（前端 tiptap 产出 HTML），而这段 HTML 会被 `v-html`
 * 渲染到**每个**登录用户的屏幕上——不净化就是一个天然的存储型 XSS 放大器。
 * 作者虽然是后台管理员，但「管理员可信」不成立：他的账号可能被盗，
 * 而这条路径的爆炸半径是全体在线用户。
 *
 * ## 净化在写入时做，不在渲染时做
 *
 * 存进去的就是干净的，读的地方（管理端列表、详情、铃铛下拉）一律不必再管。
 * 反过来做要求每个渲染点都记得净化一次，漏一个就是漏一个洞，
 * 而漏没漏没有任何编译期或运行期信号。
 *
 * 前端也不做净化：那只是给自己看的体面，改一行 JS 或直接打接口就绕过去了。
 *
 * ## 白名单，不是黑名单
 *
 * 只放行排版需要的标签与属性。`<script>` / `<iframe>` / `<style>` /
 * `on*` 事件属性 / `javascript:` 协议全部会被剥掉——但这不是靠逐条枚举，
 * 而是「没列进白名单的一律不留」。黑名单永远赶不上新的绕过写法。
 *
 * ## 为什么这个实例可以是 static
 *
 * 常驻内存下 static 只能存进程级基础设施（CLAUDE.md §webman 红线）。
 * HTMLPurifier 实例正是这类：配置在构造时定死，purify() 不持有任何请求态，
 * 与 `Db` 的连接、`Cache` 的客户端同一档。它构造一次要解析整套 HTML 定义，
 * 每个请求新建会明显拖慢写接口。
 */
class Html
{
    private static ?HTMLPurifier $purifier = null;

    /**
     * 允许的标签与属性
     *
     * 与前端 tiptap 的 StarterKit 能产出的东西对齐——编辑器给不出的标签
     * 放行了也只是多一条别人可以从接口塞进来的路。
     * 加编辑器功能（表格、图片）时两边一起改，只改一边的表现是：
     * 界面上排好版、保存后那部分静默消失。
     */
    private const ALLOWED =
        'p,br,strong,b,em,i,u,s,del,code,pre,blockquote,hr,'
        . 'h1,h2,h3,h4,ul,ol,li,'
        . 'a[href|title|target|rel],span';

    public static function purify(string $html): string
    {
        return self::purifier()->purify($html);
    }

    /**
     * 富文本 → 纯文本
     *
     * 列表摘要、铃铛下拉、操作日志里都只要文字。直接 strip_tags 会把
     * `<p>一</p><p>二</p>` 拼成「一二」，所以先把块级标签换成空格再剥。
     * 实体也要解回来，否则摘要里会出现 `&amp;` 这种给机器看的写法。
     */
    public static function toText(string $html): string
    {
        $text = preg_replace('#<(br|/p|/li|/h[1-4]|/blockquote|hr)[^>]*>#i', ' ', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier instanceof HTMLPurifier) {
            return self::$purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', self::ALLOWED);
        $config->set('AutoFormat.RemoveEmpty', true);

        // 外链一律新窗口打开并断开 opener：公告里的链接是别人写的，
        // 让它能通过 window.opener 操作后台页面是没必要给出去的能力
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.TargetNoopener', true);

        /*
         * 定义缓存要有个能写的目录
         *
         * 不设的话 HTMLPurifier 会往 vendor 目录里写——容器里 vendor 可能只读，
         * 且构建产物不该被运行期改动。目录不存在时它会抛异常而不是降级，
         * 所以这里显式建一次（多进程并发建目录用 @ 兜住，判断存在再建仍有竞态）。
         */
        $cacheDir = runtime_path() . '/htmlpurifier';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        $config->set('Cache.SerializerPath', $cacheDir);

        return self::$purifier = new HTMLPurifier($config);
    }
}
