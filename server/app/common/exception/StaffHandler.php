<?php

declare(strict_types=1);

namespace app\common\exception;

/**
 * 员工移动端错误结构
 *
 * { code, message, trace_id, details? } —— 与后台**刻意保持一致**：
 * 用的人是同事，字段级明细与 traceId 对他们有用，C 端那种「只给一句人话」
 * 在这里是帮倒忙（一个提交失败，同事说不清是哪个字段，只能截图问后端）。
 *
 * 那为什么不直接在 config/exception.php 里复用 AdminHandler？
 * 因为「现在一样」不等于「以后一样」：移动端迟早要在错误体里带
 * 「是否需要强制更新」「最低可用版本」这类只有它关心的东西。
 * 留一个空壳子类，那天到了改一处；共用的话要么污染后台，要么临时再拆。
 */
class StaffHandler extends AdminHandler
{
}
