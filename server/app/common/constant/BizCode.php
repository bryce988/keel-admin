<?php

declare(strict_types=1);

namespace app\common\constant;

/**
 * 业务码（细分原因）
 *
 * 只在错误响应里出现，用于区分同一 HTTP 状态码下的不同原因（docs/api.md §2.2）。
 * 这里是唯一权威来源——异常类默认值、service/middleware 抛异常时一律引用本类的
 * 常量，不再写裸数字，docs/api.md 的码表也以本文件为准。
 *
 * 分段：10000-19999 通用（各端共用）；20000-29999 管理后台；
 *       30000-39999 C 端；40000-49999 开放平台。
 *
 * 命名约定：`模块_含义`，常量名自解释，值只在这里出现一次。
 *
 * 码是只增不改的：值一旦发出去就是对外契约，新增只能往段尾追加，
 * 不要为了让文件里的分组挨在一起而重排（岗位段排在导入导出后面就是这个原因）。
 *
 * 一个码只对应一个「原因」，不对应一句话。措辞可以随场景变
 * （「内置角色不允许修改」/「不允许删除」共用一个码没问题），
 * 但原因不同就必须给新码——原因决定前端怎么处理：
 * 「岗位下有用户」要提示去改人员归属，「岗位编码重复」要把红框标在编码输入框上。
 * 借用别的模块的码，前端 `errorFields` 的映射就只能靠碰运气。
 *
 * 前端镜像在 `web/src/constants/bizCode.ts`，改这里要同步改那里，
 * `scripts/check-bizcode.sh` 会把两边对一遍。
 */
final class BizCode
{
    // ---------------------------------------------------------------- 通用（10000-19999）
    public const UNAUTHORIZED = 10101;          // 登录已过期（token 缺失/无效/过期）
    public const TOKEN_TYPE_MISMATCH = 10102;   // 员工 token 调 C 端，或反之
    public const PASSWORD_CHANGED = 10103;      // 密码已变更，全部令牌失效
    public const FORBIDDEN = 10301;             // 缺少功能权限点
    public const DATA_SCOPE_DENIED = 10302;     // 数据权限不足（只在写路径抛：dept_id 超出可写范围；读路径走 404 伪装）
    public const FIELD_SCOPE_DENIED = 10303;    // 字段权限不足（预留，暂无抛出点）
    public const GENERAL_BAD_REQUEST = 10400;   // 通用业务错误（400 兜底，BusinessException 默认值）
    public const NOT_FOUND = 10404;             // 数据不存在或已被删除（含无权见的伪装）
    public const CONFLICT = 10409;              // 乐观锁冲突 / 唯一性默认值
    public const VALIDATION_FAILED = 10422;     // 参数校验失败（details 含字段级错误）
    public const RATE_LIMITED = 10429;          // 操作过于频繁（响应头带 Retry-After）
    public const INTERNAL_ERROR = 10500;        // 服务暂时不可用（仅返回 trace_id）

    // ---------------------------------------------------------------- 管理后台 · 认证（20000-20007）
    public const ACCOUNT_OR_PASSWORD_ERROR = 20001;  // 不区分账号不存在与密码错误
    public const ACCOUNT_DISABLED = 20002;           // 账号已被停用
    public const ACCOUNT_LOCKED = 20003;             // 账号已锁定，请 N 分钟后重试
    public const CAPTCHA_ERROR = 20004;              // 验证码错误/过期（⚠️ 当前实现实际走 VALIDATION_FAILED，待定）
    public const OLD_PASSWORD_ERROR = 20005;         // 原密码错误（改密码）
    public const PASSWORD_POLICY_VIOLATION = 20006;  // 新密码不符合安全策略
    // ⚠️ 预留，暂无抛出点：密码过期功能没做。pwd_updated_at 目前只兼作「必须改密」标志
    // （置 null = 强制下次登录改密），既没有过期天数参数也没有判定点
    public const PASSWORD_EXPIRED = 20007;           // 密码已过期，请修改后登录

    // ---------------------------------------------------------------- 管理后台 · 用户（201xx）
    public const ACCOUNT_EXISTS = 20101;          // 账号已存在
    public const SUPER_ADMIN_PROTECTED = 20103;   // 不允许操作超级管理员
    public const DATA_HANDOVER_REQUIRED = 20104;  // 停用前先完成数据交接
    public const CANNOT_OPERATE_SELF = 20105;     // 不能删除/停用自己的账号
    public const PHONE_TAKEN = 20106;             // 手机号已被其他账号使用

    // ---------------------------------------------------------------- 管理后台 · 部门（202xx）
    public const DEPT_CODE_EXISTS = 20201;   // 部门编码已存在
    public const DEPT_CYCLE = 20202;         // 上级部门不能是自己或其子部门
    public const DEPT_HAS_CHILDREN = 20203;  // 部门下存在用户或子部门，无法删除

    // ---------------------------------------------------------------- 管理后台 · 角色（203xx）
    public const ROLE_CODE_EXISTS = 20301;         // 角色编码已存在
    public const BUILTIN_ROLE_PROTECTED = 20302;   // 内置角色不允许修改或删除
    public const ROLE_HAS_USERS = 20303;           // 角色下存在用户，无法删除
    public const ROLE_MUTUAL_EXCLUSION = 20304;    // 角色互斥，不可同时授予
    public const ROLE_LIMIT_EXCEEDED = 20305;      // 超出单账号角色数量上限
    public const ROLE_INHERIT_CYCLE = 20306;       // 继承角色不可形成环
    public const DATA_SCOPE_REQUIRES_DEPT = 20307; // 自定义数据范围至少要选择一个部门
    public const ROLE_INHERITED = 20308;           // 角色被其他角色继承，无法删除（≠ 20303 角色下有用户）

    // ---------------------------------------------------------------- 管理后台 · 菜单/权限（204xx）
    public const PERM_CODE_EXISTS = 20401;  // 权限标识已存在
    public const PERM_IN_USE = 20402;       // 权限点被角色引用，请改为停用
    public const MENU_CYCLE = 20403;        // 上级菜单不能是自己或其子节点
    public const MENU_HAS_CHILDREN = 20404; // 节点下还有子节点，请先删除子节点（≠ 20402 被角色引用）

    // ---------------------------------------------------------------- 管理后台 · 字典（205xx）
    public const DICT_CODE_EXISTS = 20501;  // 字典编码已存在（同字典内字典项的值重复也用这个）
    public const DICT_ITEM_IN_USE = 20502;  // 字典项已被引用，不可修改其值

    // ---------------------------------------------------------------- 管理后台 · 参数（206xx）
    public const BUILTIN_PARAM_PROTECTED = 20601;  // 内置参数不可删除
    public const PARAM_KEY_EXISTS = 20602;         // 参数键已存在

    // ---------------------------------------------------------------- 管理后台 · 导入导出（207xx）
    public const EXPORT_LIMIT_EXCEEDED = 20701;  // 导出数据量超过上限

    // ---------------------------------------------------------------- 管理后台 · 岗位（208xx）
    // 排在导入导出后面而不是紧跟部门段：岗位原先一直借用部门的 20201/20203，
    // 补段时只能往后追加——已发出去的码不重排（见类注释）
    public const POST_CODE_EXISTS = 20801;  // 岗位编码已存在
    public const POST_HAS_USERS = 20802;    // 岗位下存在用户，无法删除

    // ---------------------------------------------------------------- C 端（30000-39999）
    public const CHANNEL_HEADER_MISSING = 30001;  // 缺少 X-Channel 请求头
    public const CHANNEL_UNSUPPORTED = 30002;     // 不支持的渠道标识

    // ---------------------------------------------------------------- 开放平台（40000-49999）
    public const INVALID_SIGNATURE = 40101;   // 签名校验失败 / 缺少签名参数
    public const SIGNATURE_EXPIRED = 40102;   // 签名已过期
    public const DUPLICATE_NONCE = 40103;     // 请求已被处理，请勿重复提交
    public const UNKNOWN_APP_KEY = 40104;     // 未知的 app_key
    public const IP_NOT_ALLOWED = 40301;      // 来源 IP 不在白名单内
}
