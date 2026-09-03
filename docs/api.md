# Keel · 接口契约

> 版本 v1.0 · 对应 Keel v1.0.0
> 配套文档：[数据库设计](database.md) · [项目文档](../PROJECT.md)
>
> 本文是**前后端并行开发的依据**。正式接口文档由代码注解生成 OpenAPI，本文只定义契约与约定，不重复描述每个字段。

---

## 1. 通用约定

### 1.1 基础信息

| 项目 | 值 |
|---|---|
| 管理后台前缀 | `/admin` |
| C 端前缀（二期） | `/client/v1` |
| 开放平台（二期） | `/open` |
| 内容类型 | `application/json; charset=utf-8` |
| 鉴权头 | `Authorization: Bearer <access_token>` |
| 追踪 | 每个响应含 `trace_id`，报障时提供它即可定位日志 |

### 1.2 响应体

**HTTP 状态码表达结果，成功只有 2xx。** 成功响应直接返回数据本体，不包 `code` 信封；错误响应才有统一的错误结构。

**成功**

```http
HTTP/1.1 200 OK
X-Trace-Id: TRC-8f21c4d9
```
```json
{ "id": 12, "username": "zhangming", "real_name": "张明" }
```

| 场景 | 状态码 | 响应体 |
|---|---|---|
| 查询、更新成功 | `200 OK` | 资源对象或列表结构 |
| 创建成功 | `201 Created` | 新建的资源对象（含 id） |
| 删除、无返回内容 | `204 No Content` | 空 |

**错误**

```http
HTTP/1.1 409 Conflict
X-Trace-Id: TRC-8f21c4d9
```
```json
{
  "code": 20101,
  "message": "账号已存在",
  "trace_id": "TRC-8f21c4d9"
}
```

参数校验失败时附带字段级明细：

```http
HTTP/1.1 422 Unprocessable Entity
```
```json
{
  "code": 10422,
  "message": "参数校验失败",
  "trace_id": "TRC-...",
  "details": { "username": ["账号已存在"], "phone": ["格式不正确"] }
}
```

**两层结构**：HTTP 状态码表达**大类**（该重试还是该改参数、该跳登录还是该提权限），业务码表达**具体原因**（同是 409，是账号重复还是部门下有用户）。前端先按状态码分派，再按 `code` 细化提示。

**`trace_id` 走响应头 `X-Trace-Id`**，成功与失败都有；错误响应体里再带一份，方便用户直接截图报障。

**为什么不用「全 200 + code」**

- 网关、负载均衡、APM 天然按状态码统计错误率与告警，不必额外接业务码
- 浏览器 DevTools、日志、链路追踪的过滤器都基于状态码，排查时省事
- 与开放平台、第三方回调、各类 HTTP 客户端库的默认预期一致，不需要为外部单独做一套
- 语义自解释：新人看 `403` 就知道是权限问题，不必翻码表

**注意**：这样一来 4xx 里会有大量正常的业务错误（账号重复、参数不合法），**告警阈值应以 5xx 为主**，4xx 只看趋势与突增，否则告警会被日常业务噪音淹没。

### 1.3 分页

请求（query）：

```
?page_num=1&page_size=20&keyword=张&status=1&sort_field=created_at&sort_order=desc
```

响应（`200 OK`，直接返回列表结构，不包信封）：

```json
{
  "list": [],
  "total": 248,
  "page_num": 1,
  "page_size": 20
}
```

- `page_size` 默认 20，最大 100，超出按 100 处理
- 排序字段必须在后端白名单内，不接受任意字段（防注入与全表扫描）
- **C 端列表用游标分页**（`cursor` + `limit`），不用页码

### 1.3.1 前端拦截器约定

4xx / 5xx 会走 axios 的 reject 分支，统一在这里分派：

```ts
// 成功：2xx 直接拿数据
instance.interceptors.response.use(res => res.data)

// 失败：先按状态码分大类，再按业务码细化
instance.interceptors.response.use(undefined, (err) => {
  const { status, data } = err.response ?? {}
  const { code, message, trace_id, details } = data ?? {}

  if (!err.response)  return toast('网络异常，请稍后重试')        // 断网、超时
  switch (status) {
    case 401: clearToken(); redirectToLogin(); break             // 含 token 过期、账号停用
    case 403: router.push('/403'); break                         // 无权限 / 数据权限不足
    case 404: toast('数据不存在或已被删除'); break
    case 422: showFieldErrors(details); break                    // 表单字段级回填
    case 429: toast(`操作过于频繁，请 ${err.response.headers['retry-after']} 秒后重试`); break
    default:  toast(status >= 500 ? '服务暂时不可用' : message, trace_id)
  }
  return Promise.reject(err)
})
```

- 业务错误的文案直接用后端返回的 `message`，前端不维护第二份文案
- 需要针对具体原因做特殊交互时（如 409 的"账号已存在"要聚焦到用户名输入框），才判断 `code`

### 1.4 其他约定

**字段命名一律 `snake_case`**，请求与响应都是，与数据库字段名逐字一致：`is_super` 不写 `isSuper`。

这样全链路只有一个名字——数据库、日志、接口文档、前端类型定义、浏览器 Network 面板里看到的都是同一个词，
排查问题时不用在两种写法之间换算，也不需要在任何一层做键名转换。

例外只有两类，它们不是接口字段：

- HTTP 头沿用惯用写法：`X-Trace-Id`、`Idempotency-Key`
- 前端自己的标识符（TS 变量、组件 props、store getter、路由 meta）仍用小驼峰，
  它们不出现在网络请求里

其余约定：

- 时间统一 `YYYY-MM-DD HH:mm:ss` 字符串，不传时间戳
- 金额传**分**（整数），前端负责换算展示
- 布尔用 `true/false`，不用 `0/1`
- 树形数据统一 `children` 字段，空树返回 `[]` 而非 `null`
- 批量操作传数组 `{"ids": [1,2,3]}`，**逐条尽力执行**，返回成功与失败明细：

  ```json
  {
    "success_count": 2,
    "fail_count": 1,
    "succeeded": [1, 2],
    "failed": [{ "id": 3, "reason": "部门下存在用户或子部门，无法删除" }]
  }
  ```

  整批回滚太粗暴（能删的那几条也白删了），只回一句「操作失败」又让用户不知道该改哪条。
  注意状态码仍是 `200`——**部分失败不是请求失败**，请求本身被正确处理了。
  服务端只在遇到非业务异常（数据库不可用等）时才返回 5xx。
- 写接口支持 `Idempotency-Key` 头做幂等（Redis SETNX，10 分钟窗口）

---

## 2. 状态码与错误码

### 2.1 HTTP 状态码（大类）

| 状态码 | 含义 | 典型场景 | 前端动作 |
|---|---|---|---|
| `200 OK` | 成功 | 查询、更新 | 正常渲染 |
| `201 Created` | 创建成功 | 新建资源 | 提示成功，跳详情或列表 |
| `204 No Content` | 成功且无返回 | 删除 | 提示成功，刷新列表 |
| `400 Bad Request` | 业务规则不允许 | 状态冲突、超出限制 | Message 提示 `message` |
| `401 Unauthorized` | 身份未认证 | 未登录、token 失效、登录失败 | 清 token 跳登录页 |
| `403 Forbidden` | 已认证但无权限 | 功能/数据/字段权限不足 | 跳 403 页，给申请入口 |
| `404 Not Found` | 资源不存在 | ID 不存在，或存在但无权见 | 提示并返回列表 |
| `409 Conflict` | 资源冲突 | 唯一性冲突、被引用、乐观锁 | Message 提示，聚焦冲突字段 |
| `410 Gone` | 资源曾经存在，现在没了 | 导出文件过期被回收（任务记录还在） | 提示并给「重新导出」，不是返回列表 |
| `422 Unprocessable Entity` | 参数校验失败 | 字段格式、必填缺失 | `details` 回填表单 |
| `429 Too Many Requests` | 触发限流 | 登录、短信、导出 | 提示 `Retry-After` 秒后重试 |
| `500 Internal Server Error` | 未捕获异常 | 代码 bug、依赖故障 | 提示"服务暂时不可用" + trace_id |
| `503 Service Unavailable` | 服务不可用 | 维护中、依赖不可用 | 提示维护中 |

> `503` 由**网关**（nginx / 负载均衡）在应用不可用时返回，应用自身不主动抛，所以它没有对应的业务码，响应体也不保证是上面的错误结构。

**关于 404**：查询不存在的资源、和查询存在但超出数据权限的资源，**返回同一个 404**。若前者 404、后者 403，攻击者就能通过状态码差异枚举出哪些 ID 是存在的。

写路径不适用这条：新建/编辑时 `dept_id` 超范围直接 403 + `10302`，因为这里必须告诉用户「换一个部门」，伪装成 404 只会让人以为部门被删了。枚举风险同样堵住了——**不存在的部门与范围外的部门抛的是同一个错**，两者都只是「不在可写集合里」。

**关于 401 与 403**：401 是"你是谁我不认"，403 是"我认得你但不让你干"。登录失败一律 401，不因账号不存在/密码错/已停用而给不同状态码。

### 2.2 业务码（细分原因）

业务码只在错误响应里出现，用于区分同一状态码下的不同原因。分段：

| 区间 | 归属 |
|---|---|
| `10000-19999` | 通用（各端共用） |
| `20000-29999` | 管理后台 |
| `30000-39999` | C 端 |
| `40000-49999` | 开放平台 |

> **权威定义**：业务码以 `app/common/constant/BizCode.php` 为准、HTTP 状态码以
> `app/common/constant/HttpStatus.php` 为准，本文档只做说明。改码时两处同步，
> 抛异常一律引用常量，不写裸数字。

**通用**

| HTTP | code | message | 说明 |
|---|---|---|---|
| 401 | `10101` | 登录已过期，请重新登录 | token 缺失、无效或过期 |
| 401 | `10102` | 登录凭证类型不匹配 | 员工 token 调 C 端接口，或反之 |
| 401 | `10103` | 密码已变更，请重新登录 | 本人改密或管理员重置密码后，该用户**全部**令牌立即失效 |
| 403 | `10301` | 无权限访问 | 缺少功能权限点 |
| 403 | `10302` | 数据权限不足 | **只在写路径抛**：新建/编辑/导入时 `dept_id` 超出可写范围（PROJECT.md §6.4）。读路径不抛此码，统一用 404 伪装以防 ID 枚举，见下方「关于 404」|
| 403 | `10303` | 字段权限不足 | 修改无权编辑的字段（预留） |
| 400 | `10400` | 业务规则不允许 | 通用兜底；模块专属 400 用具体码 |
| 404 | `10404` | 数据不存在或已被删除 | 含无权见的伪装 |
| 409 | `10409` | 数据已被他人修改，请刷新后重试 | 乐观锁冲突 |
| 422 | `10422` | 参数校验失败 | `details` 含字段级错误 |
| 429 | `10429` | 操作过于频繁 | 响应头带 `Retry-After`；登录接口另有按 IP 的失败总闸（跨账号）|
| 500 | `10500` | 服务暂时不可用 | 仅返回 trace_id，不返回堆栈 |

**管理后台**

| HTTP | code | message | 触发场景 |
|---|---|---|---|
| 401 | `20001` | 账号或密码错误 | **不区分**账号不存在与密码错误 |
| 401 | `20002` | 账号已被停用 | |
| 401 | `20003` | 账号已锁定，请 N 分钟后重试 | 「账号 + IP」连续失败超限；换个网络仍可登录 |
| 401 | `20007` | 密码已过期，请修改后登录 | **预留**：密码过期功能未实现，`pwd_updated_at` 目前只兼作「必须改密」标志 |
| 400 | `20004` | 验证码错误或已过期 | ⚠️ 当前实现实际返回 422 + `10422`，此码暂未使用，待定 |
| 400 | `20005` | 原密码错误 | 修改密码、换绑手机等一切需要验证当前密码的操作 |
| 422 | `20006` | 新密码不符合安全策略 | 长度、复杂度 |
| 400 | `20008` | 系统未配置邮件服务 | 未配 `MAIL_HOST` / `MAIL_FROM`，邮箱登录整条不可用 |
| 400 | `20009` | 邮件发送失败 | SMTP 连不上或认证失败；真实原因只进后端日志，不回给调用方 |
| 400 | `20010` | 该邮箱绑定了多个账号 | 存量数据里有重复邮箱，无法据此定位身份，改用账号登录 |
| 409 | `20101` | 账号已存在 | 新建用户 |
| 400 | `20104` | 请先完成数据交接 | 停用有归属数据的账号 |
| 400 | `20105` | 不能删除/停用自己的账号 | 用户管理里对自己动手 |
| 409 | `20106` | 手机号已被其他账号使用 | 个人中心换绑手机 |
| 409 | `20107` | 邮箱已被其他账号使用 | 新建/编辑用户、个人中心改邮箱；邮箱是登录凭证，不能重复 |
| 403 | `20103` | 不允许操作超级管理员 | 改角色、停用、删除 |
| 400 | `20202` | 上级部门不能是自己或其子部门 | 移动部门 |
| 409 | `20203` | 部门下存在用户或子部门，无法删除 | |
| 403 | `20302` | 内置角色不允许修改或删除 | |
| 409 | `20303` | 角色下存在用户，无法删除 | |
| 400 | `20304` | 与角色「X」互斥，不可同时授予 | 职责分离约束 |
| 400 | `20305` | 超出单账号角色数量上限 | |
| 400 | `20306` | 继承角色不可形成环 | |
| 400 | `20307` | 自定义数据范围至少要选择一个部门 | |
| 409 | `20308` | 该角色被其他角色继承，无法删除 | 与 `20303` 是两件事：这条要先解除继承，那条要先改人员角色 |
| 409 | `20401` | 权限标识已存在 | |
| 409 | `20402` | 权限点被角色引用，请改为停用 | |
| 400 | `20403` | 上级菜单不能是自己或其子节点 | |
| 409 | `20404` | 该节点下还有子节点，请先删除子节点 | 与 `20402` 是两件事：这条要先删子节点，那条要改为停用 |
| 409 | `20501` | 字典编码已存在 | 同字典内字典项的值重复也用这个码 |
| 409 | `20502` | 字典项已被引用，不可修改其值 | 也用于「类型下有项时不许改编码 / 不许删类型」 |
| 403 | `20601` | 内置参数不可删除 | |
| 409 | `20602` | 参数键已存在 | |
| 400 | `20701` | 导出数据量超过上限 | 提示缩小筛选范围 |
| 409 | `20802` | 该岗位下存在用户，无法删除 | 岗位曾借用部门的 `20203`，已独立 |

**C 端**

| HTTP | code | message | 触发场景 |
|---|---|---|---|
| 400 | `30001` | 缺少 X-Channel 请求头 | |
| 400 | `30002` | 不支持的渠道标识 | |

**开放平台**

| HTTP | code | message | 触发场景 |
|---|---|---|---|
| 401 | `40101` | 缺少签名参数 / 签名校验失败 | |
| 401 | `40102` | 签名已过期 | |
| 401 | `40103` | 请求已被处理，请勿重复提交 | |
| 401 | `40104` | 未知的 app_key | |
| 403 | `40301` | 来源 IP 不在白名单内 | |

> 开放平台对外不直接暴露数字业务码，而用字符串错误码（见 §12.3 与 `OpenHandler::CODE_MAP`）。

前端针对大类的处理看状态码即可；**只有需要特殊交互时才判断 `code`**（如 409 + `20101` 时聚焦到用户名输入框）。文案一律用后端返回的 `message`，前端不维护第二份。

---

## 3. 认证

| 方法 | 路径 | 权限 | 说明 |
|---|---|---|---|
| GET | `/admin/auth/captcha` | 公开 | 获取图形验证码 |
| POST | `/admin/auth/login` | 公开 | 账号密码登录 |
| POST | `/admin/auth/email/code` | 公开 | 发送邮箱登录验证码（先验邮箱 + 密码） |
| POST | `/admin/auth/login/email` | 公开 | 邮箱登录（邮箱 + 密码 + 邮箱验证码） |
| POST | `/admin/auth/refresh` | 公开 | 刷新 token |
| POST | `/admin/auth/logout` | 登录态 | 登出 |
| GET | `/admin/auth/profile` | 登录态 | 当前用户信息 + 权限 + 菜单 |

### 登录

```http
POST /admin/auth/login
{ "username": "admin", "password": "******", "captcha_key": "xxx", "captcha_code": "1234" }
```

```json
{
  "access_token": "eyJ...",
  "refresh_token": "eyJ...",
  "expires_in": 7200,
  "must_change_password": true
}
```

- `access_token` 有效期 2 小时，`refresh_token` 7 天
- token 内**只放 `uid`、`type`、签发时间**，权限从 Redis 读取，不塞进 token
- `must_change_password` 为 true 时前端强制跳转改密页，不允许进入系统

### 邮箱登录

两步。第一步发码，第二步才登录——两步都要密码。

```http
POST /admin/auth/email/code
{ "email": "a@x.com", "password": "******", "captcha_key": "xxx", "captcha_code": "1234" }
```

```json
{ "expires_in": 300, "resend_in": 60 }
```

```http
POST /admin/auth/login/email
{ "email": "a@x.com", "password": "******", "email_code": "123456" }
```

响应与账号密码登录完全一致（`access_token` / `refresh_token` / `must_change_password`）。

**为什么发码之前要先验密码**：否则任何人填个邮箱就能让别人收信，而且能靠
「目标邮箱有没有收到」枚举出谁是系统用户——接口即使恒回 200 也拦不住这条。
先验密码之后，这两件事都要求攻击者已经掌握密码，此时验证码正好发挥它该发挥的作用：
拿到密码也登不进去，第二因子在受害者的邮箱里。

**为什么登录那步还要再验一次密码**：只信「手里有验证码」的话，能读到收件人邮箱的人
（转发规则、共用邮箱、泄露的邮箱口令）就能不带密码登进来。

**几道闸**（`.env` 可调）：

| 闸 | 变量 | 默认 | 挡什么 |
|---|---|---|---|
| 图形验证码 | `CAPTCHA_TTL` | 120s | 脚本批量调发码接口 |
| 重发间隔 | `EMAIL_CODE_RESEND_SECONDS` | 60s | 连点 |
| 每日上限 | `EMAIL_CODE_DAILY_LIMIT` | 10 次/邮箱 | 拿到密码后把对方邮箱当轰炸目标 |
| 验证次数 | `EMAIL_CODE_MAX_ATTEMPTS` | 5 次 | 爆破六位码；超限连码一起作废 |
| 失败锁定 | `LOGIN_FAIL_LIMIT` 等 | 5 次 / 30 分钟 | 与账号密码登录**共用**同一套「凭证 + IP」计数 |

验证码错误**不计入**登录锁定：它自己有 5 次上限，再叠一层的效果是输错两次验证码
就把账号锁 30 分钟，而这一步已经证明请求方掌握正确密码，不是撞库。

发码动作会写一条 `type=3` 的登录日志（成功），所以「有人拿着我的密码在申请验证码」
这件事在后台查得到。

**邮箱必须是绑定的**：登录按 `sys_users.email` 定位账号，未绑定与密码错误返回同一个
`20001`。这一列没有唯一索引（`NOT NULL DEFAULT ''`，空串没法进唯一索引），
查重在应用层做（`20107`）；存量库里若已有重复邮箱，登录时返回 `20010` 让人改用账号登录。

**没配 SMTP 时**：`/admin/params/public` 的 `sys.login.emailEnabled` 为 false，
登录页「其他登录方式」里不出现邮箱入口，接口直接返回 `20008`。
「配没配」看的是 `sys.mail.host` 与 `sys.mail.from`（参数表优先，`.env` 的 `MAIL_*` 兜底）。

### 当前用户信息

登录后第一个请求，前端据此渲染菜单与按钮权限。

```json
{
  "user": {
    "id": 1, "username": "admin", "real_name": "系统管理员",
    "avatar": "", "dept_id": 1, "dept_name": "总公司", "is_super": true
  },
  "roles": ["ROLE-0001"],
  "permissions": ["sys:user:list", "sys:user:update", "biz:item:list"],
  "data_scope": 1,
  "menus": [
    {
      "id": 1, "name": "仪表盘", "path": "/home/dashboard",
      "component": "views/dashboard/index.vue", "icon": "Odometer",
      "perm_code": "sys:dashboard:view", "visible": true, "keep_alive": true
    },
    {
      "id": 3, "name": "系统管理", "path": "/system", "component": "Layout",
      "icon": "Setting", "visible": true, "children": [
        {
          "id": 4, "name": "用户管理", "path": "/system/user",
          "component": "views/system/user/index.vue",
          "perm_code": "sys:user:list", "visible": true, "keep_alive": true
        }
      ]
    }
  ]
}
```

### 会话失效规则

| 动作 | 影响范围 | 机制 |
|---|---|---|
| 登出 | **仅当前设备**的 access + refresh | 两个 jti 一起进黑名单（签发时记了配对） |
| 本人改密 | **该用户全部设备** | `token_version` 递增，鉴权与刷新都比对 |
| 管理员重置密码 | **该用户全部设备** | 同上 |
| 授权变更（改角色/权限） | 不下线 | 只递增 `perm_version` 让权限缓存失效，下个请求即生效 |

`POST /admin/auth/refresh` 会**轮换**刷新令牌：旧的用过即废，第二次使用返回 401。
这既限制了泄露窗口，也提供了重放检测。

- `menus` 只返回 `type IN (1,2)` 且当前用户有权的节点，按钮权限在 `permissions` 数组里
- 一级节点**可以直接是页面**（`component` 不是 `Layout`、没有 `children`），
  底下只有一个页面时就该这么挂，不必为它套一层目录
- 超级管理员的 `permissions` 返回 `["*"]`，前端 `v-permission` 见到 `*` 直接放行

---

## 4. 用户管理

| 方法 | 路径 | 权限标识 | 说明 |
|---|---|---|---|
| GET | `/admin/users` | `sys:user:list` | 列表，支持 `dept_id` `include_child_dept` `status` `role_id` `keyword` |
| GET | `/admin/users/{id}` | `sys:user:detail` 或 `sys:user:update` | 详情。编辑表单要靠它回填，所以两个权限点任一命中即可 |
| POST | `/admin/users` | `sys:user:create` | 新建 |
| PUT | `/admin/users/{id}` | `sys:user:update` | 编辑 |
| DELETE | `/admin/users/{id}` | `sys:user:delete` | 删除（软删） |
| PUT | `/admin/users/{id}/status` | `sys:user:update` | 启用 / 停用（`status` 只收 0/1） |
| PUT | `/admin/users/{id}/roles` | `sys:user:grantRole` | 分配角色 |
| PUT | `/admin/users/{id}/password/reset` | `sys:user:resetPwd` | 重置密码 |
| POST | `/admin/users/import` | `sys:user:import` | 批量导入 |
| GET | `/admin/users/export` | `sys:user:export` | 导出 |
| GET | `/admin/users/stats` | `sys:user:list` | 顶部四个指标卡 |

**敏感字段**：`phone` `email` 受字段级权限（`sys:field:user:phone` / `sys:field:user:email`）控制。
无权限时接口返回的**就是脱敏值**（`138****8000`），不是前端拿到明文再打码。

**列表关键参数**

```
GET /admin/users?dept_id=3&include_child_dept=true&status=1&page_num=1&page_size=20
```

`include_child_dept` 默认 `true`：选中部门时包含其所有子部门的用户（对应原型上的"包含子部门"开关）。

**分配角色**

```http
PUT /admin/users/12/roles
{ "role_ids": [3, 5] }
```

服务端校验：互斥角色（400 + `20304`）、角色数上限（400 + `20305`）、超级管理员不可改（403 + `20103`）。成功后递增该用户 `perm_version`，权限**即时生效**，无需重新登录。

---

## 5. 部门与岗位

| 方法 | 路径 | 权限标识 | 说明 |
|---|---|---|---|
| GET | `/admin/depts/tree` | `sys:dept:list` 或 `sys:user:list` | 部门树，含每个节点的用户数 |
| GET | `/admin/depts/{id}` | `sys:dept:detail` | 详情 |
| POST | `/admin/depts` | `sys:dept:create` | 新建 |
| PUT | `/admin/depts/{id}` | `sys:dept:update` | 编辑（移动时同步更新子孙 `ancestors`） |
| DELETE | `/admin/depts/{id}` | `sys:dept:delete` | 删除（有用户或子部门时 409 + `20203`） |
| GET | `/admin/posts` | `sys:post:list` | 岗位列表 |
| GET | `/admin/posts/options` | `sys:post:list` 或 `sys:user:list` | 岗位下拉选项，含 `default_role_id` |
| GET | `/admin/posts/{id}` | `sys:post:detail` | 岗位详情 |
| POST/PUT/DELETE | `/admin/posts/{id}` | `sys:post:create` / `update` / `delete` | 岗位增改删 |

岗位的 `code` **不收请求体里的值**，由服务端按主键生成：`POST-` 加四位左补零的主键
（`1` → `POST-0001`，`100` → `POST-0100`，超过 9999 自然变五位）。它只是主键的可读别名，
用在导入用户的 Excel「岗位编码」列上，让填表的人不必知道数据库主键。
新增时先入库拿到主键再回写，编辑不会改动它——主键不变，编码就不变。

部门树与岗位选项都是用户表单的数据源，因此**任一权限满足即可**读取——
只有用户管理权限、没有部门/岗位管理权限的账号，也要能按部门筛人、给人选岗位。

`/admin/posts/options` 只返回**启用**的岗位，并带上 `default_role_id`：
前端新建用户时选中岗位，据此预填角色（见 §6 末尾的说明）。
已挂在停用岗位上的存量用户不受影响，但编辑他时下拉里选不到当前值、显示为空——
这是有意的，提示操作者该岗位已废弃、需要重新选一个。

---

## 6. 角色管理

| 方法 | 路径 | 权限标识 | 说明 |
|---|---|---|---|
| GET | `/admin/roles` | `sys:role:list` | 列表（内置/自定义分组） |
| GET | `/admin/roles/{id}` | `sys:role:detail` / `update` / `grantPerm` / `grantData` | 详情，含继承与约束。授权抽屉打开时要先取它，所以两个授权权限点也放行 |
| POST | `/admin/roles` | `sys:role:create` | 新建 |
| PUT | `/admin/roles/{id}` | `sys:role:update` | 编辑 |
| DELETE | `/admin/roles/{id}` | `sys:role:delete` | 删除（内置 403 + `20302`，有成员 409 + `20303`） |
| GET | `/admin/roles/{id}/permissions` | `sys:role:list` | 已授权的权限点 ID 列表 + 继承来的 ID 列表 |
| PUT | `/admin/roles/{id}/permissions` | `sys:role:grantPerm` | 保存功能权限 |
| PUT | `/admin/roles/{id}/data-scope` | `sys:role:grantData` | 保存数据权限 |
| PUT | `/admin/roles/{id}/fields` | `sys:role:grantData` | 保存字段级权限 |
| GET | `/admin/roles/{id}/users` | `sys:role:list` | 角色成员 |
| POST | `/admin/roles/{id}/users` | `sys:user:grantRole` | 添加成员 |
| DELETE | `/admin/roles/{id}/users/{userId}` | `sys:user:grantRole` | 移除成员 |

**功能权限**

```http
GET /admin/roles/3/permissions
→ 200 OK  { "granted": [1,2,5,8], "inherited": [1,2] }
```

`inherited` 的节点在前端置灰不可取消（继承自父角色）。

```http
PUT /admin/roles/3/permissions
{ "permission_ids": [1,2,5,8,12] }
```

保存后递增所有持有该角色用户的 `perm_version`。

**数据权限**

```http
PUT /admin/roles/3/data-scope
{ "data_scope": 5, "dept_ids": [3,4,7] }   // dept_ids 仅 data_scope=5 时必填
```

---

## 7. 菜单与权限

| 方法 | 路径 | 权限标识 | 说明 |
|---|---|---|---|
| GET | `/admin/menus/tree` | `sys:menu:list` | 菜单与权限点树（全量，含停用） |
| GET | `/admin/menus/{id}` | `sys:menu:detail` | 节点详情 |
| POST | `/admin/menus` | `sys:menu:create` | 新建节点 |
| PUT | `/admin/menus/{id}` | `sys:menu:update` | 编辑 |
| DELETE | `/admin/menus/{id}` | `sys:menu:delete` | 删除（被引用 409 + `20402`） |

**本模块只定义权限点，不做授权**。`GET /admin/menus/tree` 返回的每个节点带 `granted_role_count`，仅供展示。

---

## 8. 数据字典

| 方法 | 路径 | 权限标识 | 说明 |
|---|---|---|---|
| GET | `/admin/dicts/{code}/items` | 登录态 | **某个字典的启用项**，页面按需取 |
| GET | `/admin/dicts/batch?codes=a,b,c` | 登录态 | 批量预热，一个列表页一次拉齐 |
| GET | `/admin/dicts` | `sys:dict:list` | 字典类型列表（维护界面用） |
| POST/PUT/DELETE | `/admin/dicts/{id}` | `sys:dict:create` / `update` / `delete` | 类型增改删 |
| GET | `/admin/dicts/{code}/items/all` | `sys:dict:list` | 字典项（含停用，维护界面用） |
| POST | `/admin/dict-items` | `sys:dict:create` | 新增字典项 |
| PUT/DELETE | `/admin/dict-items/{id}` | `sys:dict:update` / `delete` | 字典项改删（改已引用的 value 时 409 + `20502`） |
| POST | `/admin/dict-items/batch-delete` | `sys:dict:delete` | 批量删除，逐条尽力执行 |

```json
GET /admin/dicts/common_status/items → 200 OK
[
  { "label": "正常", "value": "1", "tag_type": "success" },
  { "label": "异常", "value": "3", "tag_type": "danger" }
]
```

读取接口只要**登录态**：字典是全站下拉与状态色的基础数据，
要求 `sys:dict:list` 会让没有字典管理权限的账号连状态标签都渲染不出来。

前端存入 Pinia（`stores/dict.ts`，带缓存与并发去重），
`<DictTag code="common_status" :value="row.status" />` 直接渲染，颜色由 `tag_type` 驱动。
服务端缓存 5 分钟，字典维护接口写入后主动 `DictService::forget()`。

### 8.1 值不可改的判定

维护界面的 `items/all` 每行多一个 `ref_count`：这个字典值被业务表引用的行数。

```json
{ "id": 8, "type_code": "user_status", "label": "启用", "value": "1",
  "tag_type": "success", "sort": 1, "status": 1, "ref_count": 5 }
```

`ref_count > 0` 时改值或删除都是 409 + `20502`——改了等于把已有数据的含义换掉，
而旧值不会被回溯更新。`label` / `tag_type` / `sort` / `status` 不受限制，那些只影响展示。

引用关系没有外键可查（字典值就是散落在各表的 TINYINT），
所以在 `DictService::USAGE` 里显式登记「哪张表的哪一列消费了哪个字典」。
**没登记的字典默认放行**：宁可漏拦也不能误拦，误拦会让人改不了一个其实没人用的字典项。
新增业务表消费了某个字典时记得补一行。

同理，已经有字典项的**类型**不允许改 `code`（409 + `20502`）：
`code` 是字典项的关联键，改了所有项都会成为孤儿。

---

## 8.2 系统公告

一张表，两组接口，**权限口径完全不同**——这是本模块最容易接错的地方。

**管理端**（`/admin/notices*`，看得到草稿）

| 方法 | 路径 | 权限标识 | 说明 |
|---|---|---|---|
| GET | `/admin/notices` | `sys:notice:list` | 列表，默认按创建时间倒序；只返回 60 字 `summary`，正文要看详情 |
| GET | `/admin/notices/{id}` | `sys:notice:detail` 或 `sys:notice:update` | 详情（含正文）。列表只给摘要，编辑要靠它回填 |
| POST | `/admin/notices` | `sys:notice:create` | 新增；`status=1` 表示存好就发 |
| PUT | `/admin/notices/{id}` | `sys:notice:update` | 编辑 |
| POST | `/admin/notices/{id}/publish` | `sys:notice:publish` | 发布，**幂等** |
| POST | `/admin/notices/{id}/revoke` | `sys:notice:publish` | 撤回到草稿，已读回执保留 |
| DELETE | `/admin/notices/{id}` | `sys:notice:delete` | 删除，回执一并清除 |
| POST | `/admin/notices/batch-delete` | `sys:notice:delete` | 批量删除，逐条尽力执行（§1.4） |

**接收端**（`/admin/my/notices*`，**登录即可**，只看得到已发布的）

| 方法 | 路径 | 权限标识 | 说明 |
|---|---|---|---|
| GET | `/admin/my/notices` | 登录态 | 铃铛用：未读数 + 最新未读 id/标题 + 最近 10 条 |
| GET | `/admin/my/notices/{id}` | 登录态 | 读一条，**同时落已读回执** |
| POST | `/admin/my/notices/read-all` | 登录态 | 全部已读，返回 `{count}`（本次新增的回执数） |

接收端不挂权限点是刻意的：公告的受众是每一个登录用户，挂了等于
「没被授权的人收不到全员通知」。越权面由结构挡住——用户 id 只从令牌取，
路径里没有 `user_id` 这类参数，与 §11 个人中心同一套思路。

```json
GET /admin/my/notices → 200 OK
{
  "unread_count": 2,
  "latest_id": 17,
  "latest_title": "系统维护通知",
  "list": [
    { "id": 17, "title": "系统维护通知", "summary": "本周六 00:00-02:00 例行维护…",
      "type": "maintenance", "published_at": "2026-09-01 14:06:47",
      "publisher_name": "系统管理员", "is_read": false }
  ]
}
```

几条约定，改动前先读：

- **未读是算出来的**：未读 = 已发布公告 ∖ 我的已读回执。反过来做（发布时给每人插一行）
  会把「发一条公告」变成一次全表写入，新入职的人还得补发
- **`latest_id` 而不是 `unread_count` 判断有没有新的**：读掉一条、同时又发来一条时数量不变
- **发布时间只在状态跨过发布线时盖章**：已发布的公告改错别字不刷新 `published_at`，
  否则它会把这条重新顶到所有人消息列表的最上面，而内容其实没变。
  `published_at` / `publisher_id` / `publisher_name` 三个字段前端传了也不生效
- **正文是富文本 HTML，写入时净化**：`POST` / `PUT` 都会过 `support/Html.php` 的
  白名单（`p/br/strong/em/u/s/code/pre/blockquote/hr/h1-h4/ul/ol/li/a/span`），
  `<script>`、`on*` 事件属性、`javascript:` 协议、`<iframe>` 一律剥掉，外链自动补
  `target="_blank" rel="noopener"`。**存进去的就是干净的**，所以读的地方直接 `v-html`。
  反过来（渲染时净化）要求每个渲染点都记得做一次，漏一个就是漏一个洞
- 列表的 `summary` 是正文**剥成纯文字**后的前 60 字，不是截断的 HTML
- **没有推送**：前端每 60 秒轮询一次 `/admin/my/notices`，标签页不可见时不轮询。
  脚手架不引长连接，公告延迟一分钟没有影响
- 状态字典是 `notice_status`（0 草稿 / 1 已发布），**不复用 `enable_status`**——
  公告没有「停用」这回事，共用会让列表里显示成「已停用」

---

## 8.3 数据导出（异步）

「点导出 → 立刻下载」在数据量大起来之后必然失败：请求要等文件生成完才有响应，
几万行就是几十秒，浏览器或 nginx 任一层超时就让用户看到失败页，而文件其实已经生成好了；
更要紧的是 webman 是常驻内存的多进程模型，一个 worker 卡在导出上，这段时间它一个请求都接不了。

所以导出分两步：**各业务模块的 export 接口建任务并投队列**（返回 202），
**导出中心负责看进度与下载**。

**发起（在各业务模块，权限也是各模块自己的）**

| 方法 | 路径 | 权限标识 | 返回 |
|---|---|---|---|
| GET | `/admin/users/export` | `sys:user:export` | `202` + `{task_id, message}` |
| GET | `/admin/logs/operation/export` | `sys:log:operation:export` | 同上 |
| GET | `/admin/logs/login/export` | `sys:log:login:export` | 同上 |

**导出中心**

| 方法 | 路径 | 权限标识 | 说明 |
|---|---|---|---|
| GET | `/admin/exports` | `sys:export:list` | 任务列表，默认按创建时间倒序 |
| GET | `/admin/exports/{id}/download` | `sys:export:list` | 下载文件流 |
| DELETE | `/admin/exports/{id}` | `sys:export:delete` | 删记录，文件一并删 |

```json
GET /admin/users/export?status=1 → 202 Accepted
{ "task_id": 12, "message": "已加入导出队列，完成后可在「数据管理 / 数据导出」下载" }

GET /admin/exports → 200 OK
{ "list": [{ "id": 12, "biz": "user", "biz_name": "用户", "status": 2,
             "row_count": 1284, "file_name": "用户列表_20260901_145009.xlsx",
             "file_size": 84992, "error_msg": "", "creator_name": "王强",
             "expired_at": "2026-09-04 14:50:09", "finished_at": "2026-09-01 14:50:12",
             "created_at": "2026-09-01 14:50:09", "downloadable": true }],
  "total": 1, "page_num": 1, "page_size": 20 }
```

约定与坑：

- **消费进程会还原发起人身份**（`Ctx::set('user', …)`，用完 `Ctx::clear()`）。
  这是本模块最要命的一点：数据权限（`DataScope`）在 `Ctx::user() === null` 时
  **不注入任何条件**，字段脱敏也读当前用户——不还原的话，部门主管发起的导出会生成
  一份全公司名单且手机号是明文，一次点击绕过两道权限。
  实测：主管导出得到 2 行（他的部门）而不是 5 行，邮箱仍是掩码
- **`downloadable` 由服务端算**，前端别按 `status` 自己判：文件会被回收而状态仍是
  「已完成」，只看状态会给出一个点了报错的按钮
- 文件过期/被回收后下载返回 **`410` + `20702`**，不是 404：记录还在，用户该做的是
  「重新导出」而不是「回列表」
- 看得到哪些任务由数据权限决定（归属人列是 `creator_id`）：「仅本人」范围只看得到
  自己发起的。**谁有 `xxx:export` 就必须有 `sys:export:list`**，否则他导得出来却看不到任务
- 筛选条件在建任务时整份存下（`params`），排队期间界面改了筛选也不影响
- 文件保留 `sys.export.retainDays` 天（默认 3），过期文件由写新文件时顺手回收，
  过期**记录**由每天 03:40 的定时任务清理
- 单文件行数上限仍是 `sys.export.maxRows`（默认 50000），超了任务标失败，
  失败原因原样给用户看（他自己能处理：缩小筛选范围）

⚠️ 文件写在 `runtime/exports`，生成的是消费进程、下载的是 web 进程——
两者在同一个容器里共享这个目录。**多实例部署时要换成对象存储或共享卷**，
否则会出现「A 实例生成、B 实例说文件不存在」。

---

## 9. 参数配置

| 方法 | 路径 | 权限标识 | 说明 |
|---|---|---|---|
| GET | `/admin/params?group=security` | `sys:param:list` | 按分组查询，不分页 |
| GET | `/admin/params/groups` | `sys:param:list` | 分组元信息，前端据此渲染 tab |
| PUT | `/admin/params` | `sys:param:update` | **批量保存**，见下 |
| GET | `/admin/params/{id}` | `sys:param:list` | 单条详情 |
| POST/PUT/DELETE | `/admin/params/{id}` | `sys:param:create` / `update` / `delete` | 自定义参数增改删（删内置 403 + `20601`，键重复 409 + `20602`） |
| GET | `/admin/params/public` | 公开 | 登录页需要的少量参数（系统名、Logo、页脚） |

分组固定五个：`basic` 基础设置 · `security` 安全策略 · `integration` 第三方集成 · `advanced` 高级选项 · `system` 系统配置。

`system` 组放的是邮件（`sys.mail.host` / `port` / `encryption` / `username` / `password` / `from` / `fromName`），邮箱登录靠它。**参数表优先、`.env` 的 `MAIL_*` 兜底**，两边都空才算没配；口令是 `is_secret`，读接口只回掩码、且未配置时回空串。

批量保存整组提交，一个事务：同组参数彼此相关（失败次数与锁定时长），
逐条保存会留下半新半旧的中间态。

```json
PUT /admin/params
{ "items": [
    { "param_key": "sys.login.failLimit", "param_value": "3" },
    { "param_key": "sys.sms.accessKey",   "param_value": "******" }
] }
→ 200 OK  { "saved_count": 1 }
```

`saved_count` 是**实际写入**的条数：值没变的、以及密钥回填掩码的都不算。
请求里的未知键静默跳过而不是报错——前端提交的是整组表单，
其中某条刚被别人删掉，不该因此把其余改动一起回滚。

`is_secret = 1` 的参数**只写不读**：所有读接口（含 POST/PUT 的响应体）一律返回掩码 `******`，
保存时值等于掩码就跳过更新。操作日志里也只记掩码，不记明文。

内置参数（`is_builtin = 1`）只能改**值与备注**：
键、类型、分组都被后端按名字读取（`ParamService::value('sys.pwd.minLength')`），
改了会让代码读到 null，而调用点都有默认值兜底，故障会以「配置怎么不生效」的形式出现。

⚠️ 参数只落库 + 走缓存，**不热改 webman 配置**：常驻内存多进程下运行期改配置
只影响当前 worker，进程间立刻不一致（PROJECT.md §14）。

---

## 9.1 系统概览

| 方法 | 路径 | 权限标识 | 说明 |
|---|---|---|---|
| GET | `/admin/dashboard/overview` | `sys:dashboard:view` | 指标卡、登录趋势、最近操作、模块规模、运行状态 |

只汇总**系统自身已有的模块**，不含业务指标——脚手架里摆假数字，接业务的人第一件事还是得全删掉。

两层收敛，缺一不可：

- **数据权限**：所有计数走模型而不是 `Db::table()`，全局 Scope 自动生效。
  部门主管的「用户数」就是他管得到的那些人
- **功能权限**：没有 `sys:role:list` 的账号，返回体里**根本不含**角色那张卡与那一行模块。
  菜单都对他收敛了，概览再把规模抖出来，等于开了一条绕过菜单收敛的旁路

接口本身只要 `sys:dashboard:view`（概览是登录后的落地页），
权限不足时返回的是**空列表而不是 403**——整页 403 会让人以为系统坏了。

---

## 10. 日志

| 方法 | 路径 | 权限标识 | 说明 |
|---|---|---|---|
| GET | `/admin/logs/operation` | `sys:log:operation:list` | 操作日志，必带时间范围 |
| GET | `/admin/logs/operation/{id}` | `sys:log:operation:detail` | 详情，含字段级变更与脱敏后的入参 |
| GET | `/admin/logs/operation/export` | `sys:log:operation:export` | 导出（导出行为本身也记日志） |
| GET | `/admin/logs/login` | `sys:log:login:list` | 登录日志 |
| GET | `/admin/logs/login/export` | `sys:log:login:export` | 导出 |

**只读**：日志没有写接口。操作日志由 `OperationLogMiddleware` 落库，
登录日志由登录流程落库；能从界面改删日志，审计就失去意义了。

时间范围为**必填**，未传时后端默认最近 7 天，避免全表扫描。
`start_time` / `end_time` 只给到日期（`2026-08-17`）时后端自动补成
`00:00:00` / `23:59:59`——否则「查今天」会一条都查不到。

筛选：`keyword`（操作人/描述/对象）· `module`（**前缀匹配**，传「系统管理」能查到整个大类）·
`action` · `status` · `trace_id`（排障入口：从报错弹窗里的那串直接定位到那次请求）。

```json
GET /admin/logs/operation/32 → 200 OK
{
  "id": 32, "trace_id": "TRC-4e73d1f1ea19", "username": "admin",
  "module": "系统管理/参数", "action": 2, "title": "保存参数",
  "target": "系统参数 sys.login.failLimit,sys.login.lockMinutes",
  "api_method": "PUT", "api_path": "/admin/params", "status": 1, "duration": 18,
  "params": { "items": [ { "param_key": "sys.login.failLimit", "param_value": "5" } ] },
  "changes": [
    { "field": "sys.login.failLimit",   "old": "3",  "new": "5"  },
    { "field": "sys.login.lockMinutes", "old": "15", "new": "30" }
  ]
}
```

列表**不返回** `params` 与 `changes`：两个 JSON 字段可能很大，一页 20 行光这两列就是几百 KB，
而列表页一列都不显示。列表只给 `change_count`，用户据此知道哪几行值得点开。

两张表都带数据权限全局 Scope：部门主管只看得到本部门的记录。
这一点不能靠界面收敛——「看不到但能查」的日志等于没有隔离。

---

## 11. 个人中心

| 方法 | 路径 | 权限 | 说明 |
|---|---|---|---|
| GET | `/admin/profile` | 登录态 | 个人资料 |
| PUT | `/admin/profile` | 登录态 | 修改姓名、头像、邮箱（账号、部门、岗位、角色只读） |
| POST | `/admin/profile/avatar` | 登录态 | 上传并更换头像，`multipart/form-data`，字段名 `file` |
| PUT | `/admin/profile/password` | 登录态 | 修改密码，需原密码 |
| PUT | `/admin/profile/phone` | 登录态 | 换绑手机，需当前密码 |
| GET | `/admin/profile/logins` | 登录态 | 我的登录记录，分页 |

全部只作用于**当前登录用户**：用户 id 一律从令牌取，**不接受请求体里的 id**。
这是与 §5 用户管理最本质的区别——个人中心没有「改别人」这条路径，
因此也不需要任何权限点（`perm => ''`），越权在结构上就不成立。

```json
GET /admin/profile → 200 OK
{
  "id": 1, "username": "admin", "real_name": "超级管理员",
  "avatar": "", "phone": "138****8000", "email": "admin@example.com",
  "dept_name": "研发中心", "post_name": "技术负责人",
  "roles": ["超级管理员"],
  "status": 1, "is_super": true,
  "pwd_updated_at": "2026-08-01 10:22:31",
  "last_login_at": "2026-08-18 09:14:07", "last_login_ip": "1.2.3.4",
  "created_at": "2026-05-01 00:00:00"
}
```

`phone` 沿用 §5 的字段级脱敏规则；换绑时提交的是完整号码。

### 11.1 换头像

```jsonc
POST /admin/profile/avatar        // multipart/form-data，字段名 file
→ 200 OK
{ "avatar": "/uploads/avatar/202608/3f7a9c21d84b6e05.png" }
```

**一步到位，没有「先上传后保存」两段式**：上传成功即写库，响应里的 `avatar`
就是最终地址，前端拿到直接换图，不用再调 `PUT /admin/profile`。
两段式要额外维护临时目录与孤儿文件清理，而头像这一个场景撑不起那套开销。

约束：

| 项 | 值 | 说明 |
|---|---|---|
| 字段名 | `file` | 与 §5 的用户导入保持一致 |
| 扩展名 | `jpg` `jpeg` `png` `gif` `webp` | **写死在代码里**，不做成参数——可配置的白名单等于给了配错的机会 |
| 真实类型 | 必须是真图片 | 用 `getimagesize()` 二次确认，只看扩展名挡不住改名的文件 |
| 大小上限 | 系统参数 `sys.upload.avatarMaxSize`，默认 2MB | 全局上限 `sys.upload.maxSize`（20MB）对头像太宽松，单开一个 |

返回的是**相对路径**，不带域名——换域名、上 CDN 时库里的数据不用动。

文件落在 `server/public/uploads/avatar/年月/`，由 webman 的 StaticFile 提供访问。
按年月分目录是为了不让一个目录堆到几十万个文件。
换头像时会删掉旧文件（只删 `uploads/avatar/` 下的，防止把别处的路径带进来）。

> **部署注意**：nginx 需要把 `/uploads/` 转发到后端（`docker/nginx/default.conf` 已加）。
> 上传目录不进 Git（`.gitignore` 已排除），生产用 bind mount 挂在宿主机上，
> `deploy.sh` 只做 `git reset --hard` 不做 `git clean`，重新部署不会丢文件。

**换绑手机为什么不用短信**：Keel 是不含业务逻辑的脚手架，绑死某家短信服务商
等于替使用者做了选型。这里用当前密码验证身份，接短信的项目把
`ProfileService::changePhone()` 里的密码校验换成验证码校验即可，其余不动。

**通知偏好不做**：系统里没有任何通知消费方（无站内信、无邮件模板、无推送通道），
只做一个偏好开关就是没人读的死配置。等通知能力落地时连同消费方一起加。

---

## 12. 通用能力

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/admin/upload` | 通用文件上传，返回 `{url, name, size}`；先入临时目录，业务保存后转正。**尚未实现**——目前只有 §11.1 的头像上传，它是一步到位的专用接口 |
| GET | `/admin/common/regions` | 省市区数据（如需要） |
| GET | `/ping` | 根探测（不属于任何应用），负载均衡健康检查用 |
| GET | `/admin/ping` · `/client/ping` · `/open/ping` · `/internal/ping` | 各端存活探测，用于验证分端中间件与异常处理链路 |

### 12.1 各端错误结构不同

同一件事（比如未授权）在四个端返回的结构是**刻意不同**的，受众不一样（PROJECT.md §8.3）：

```jsonc
// admin —— 同事在用，要字段级明细与 traceId
{ "code": 10101, "message": "未登录，请先登录", "trace_id": "TRC-…", "details": {…} }

// client —— 终端用户在用，只给一句人话，内部标识不外露（仍有 X-Trace-Id 响应头）
{ "code": 10102, "message": "登录凭证类型不匹配" }

// open —— 第三方在用，字符串错误码比数字稳定，也不受我们内部码段重排影响
{ "error_code": "INVALID_SIGNATURE", "error_message": "签名校验失败", "request_id": "TRC-…" }

// internal —— 自己的服务在用，信息给足
{ "code": 10500, "message": "…", "trace_id": "TRC-…", "exception": true }
```

### 12.2 C 端请求头

`/client/*` 一律要带（缺失直接 400，见 PROJECT.md §8.5）：

| 头 | 取值 |
|---|---|
| `X-Channel` | `app-ios` / `app-android` / `mp-weixin` / `h5` |
| `X-App-Version` | 客户端版本号，用于灰度与强制更新 |
| `X-Device-Id` | 设备标识，限流按它而不是 IP（移动网络大量共用出口 IP） |

### 12.3 开放平台验签

四个头：`X-App-Key` `X-Timestamp` `X-Nonce` `X-Signature`。

```
待签串 = "{METHOD}\n{PATH}\n{按键排序并 urlencode 的参数}\n{timestamp}\n{nonce}"
签名   = hash_hmac('sha256', 待签串, app_secret)
```

三道防线缺一不可：签名防篡改、时间戳把重放窗口压到 5 分钟、nonce 让窗口内也只能成功一次。
只验签名不验时间戳的话，抓到一个包就能永久重放。`/open/ping` 免签，供第三方校准时间戳与确认出口 IP。

---

## 13. 员工移动端（`/staff/v1/*`）

给**系统人员**用的 App。身份与授权和管理后台**完全同一套**：同一张 `sys_users`、
同一个令牌（`type=admin`）、同一份权限点与数据权限——手机上换的只是界面。
但**接口是另一套**，理由见 PROJECT.md §8.1：后台接口是给宽屏与完整表单设计的，
移动端要聚合与瘦身，且迟早要长出强制更新、推送注册这类后台没有的东西。

请求头：与 C 端一样要带 `X-Channel` / `X-App-Version` / `X-Device-Id`（§12.2），
渠道取 `app-android` / `app-ios` / `h5`。错误体与后台一致：`{code, message, trace_id, details?}`。

| 方法 | 路径 | 权限 | 说明 |
|---|---|---|---|
| GET | `/staff/v1/auth/captcha` | 免登录 | 图形验证码，与后台同一套（验过即焚） |
| POST | `/staff/v1/auth/login` | 免登录 | 账号 + 密码 + 验证码，**一次返回令牌与身份** |
| POST | `/staff/v1/auth/refresh` | 免登录 | 用 refresh 换一对新令牌（轮换，旧的用过即废） |
| POST | `/staff/v1/auth/logout` | 登录即可 | 吊销当前令牌及配对的 refresh |
| GET | `/staff/v1/workbench` | 登录即可 | 工作台聚合：身份 + 权限点 + 概览 |
| GET | `/staff/v1/notices` | 登录即可 | 消息列表（分页 + `unread_count`） |
| GET | `/staff/v1/notices/{id}` | 登录即可 | 读一条，返回正文并落已读回执 |
| POST | `/staff/v1/notices/read-all` | 登录即可 | 全部标为已读 |
| GET | `/staff/v1/profile` | 登录即可 | 个人资料 |
| PUT | `/staff/v1/profile` | 登录即可 | 改姓名 / 邮箱 |
| POST | `/staff/v1/profile/avatar` | 登录即可 | 换头像（multipart，字段名 `file`） |

**登录一次返回身份**（后台是 `login` 与 `auth/profile` 两个接口）：

```jsonc
// POST /staff/v1/auth/login
{ "username": "admin", "password": "admin123", "captcha_key": "captcha:…", "captcha_code": "a1b2" }

// 200
{
  "access_token": "eyJ…", "refresh_token": "eyJ…", "expires_in": 7200,
  "user": { "id": 1, "username": "admin", "real_name": "系统管理员",
            "avatar": "http://…/uploads/avatar/…png", "dept_name": "总公司", "is_super": true },
  "permissions": ["*"]
}
```

移动端合并请求不是图省事：App 启动在弱网下每多一次往返就多一次转圈，
而这两个接口的结果对客户端来说是**同一件事的两半**——没有身份的令牌它也用不了。

**access 2 小时、refresh 7 天**（`JWT_ACCESS_TTL` / `JWT_REFRESH_TTL`）。客户端务必存下
`refresh_token`：手机上没人愿意每两小时重输一次账号密码加验证码，收到 401 应当先用
`/staff/v1/auth/refresh` 自动换一次再重试原请求，换不动才回登录页。

刷新接口**免登录**——access 都过期了，刷新接口自己再要求登录就成了死锁。
旧 refresh 用过即废（轮换），所以客户端要做**单飞**：多个请求同时 401 时只发一次刷新，
否则后到的那个拿着已作废的 refresh 去换，用户照样被踢出去。

**工作台聚合**：

```jsonc
// GET /staff/v1/workbench → 200
{
  "user": { … },
  "permissions": ["*"],
  "dashboard": {
    "visible": true,              // 没有 sys:dashboard:view 时为 false，stats 为空数组
    "stats": [ { "key": "user", "label": "用户", "value": 5, "unit": "人",
                 "hint": "启用 5 · 停用 0", "tone": "primary", "perm": "sys:user:list" } ]
  }
}
```

`visible` 是服务端算的，不让客户端拿权限点自己判断：权限是登录那一刻的快照，
撤权之后客户端缓存还是旧的，界面会显示一块永远加载失败的区域。

**消息（系统公告）**：接收端与后台铃铛是同一份数据、同一份已读判定
（`NoticeService` 在 `common/service`）。三点值得注意：

- 列表的分页体里**额外带 `unread_count`**：列表与角标在界面上是同一件事的两面，
  拆成两个接口会出现「角标 3、点进去只有 2 条未读」的错位，轮询请求数也翻倍
- 工作台聚合里也带 `unread_notice`，App 回到首页刷角标不用再单发一次请求
- **读一条 = 标已读**：`GET /staff/v1/notices/{id}` 返回正文的同时落回执。
  拆成两个接口的话，第二个失败时界面显示已读、库里还是未读
- 草稿与已撤回的公告一律 404：它们对接收端不存在，链接还在手上也打不开

正文是后台富文本编辑器存的 HTML，客户端用 `rich-text` 渲染（小程序端没有 `v-html`，
而 `rich-text` 只认白名单标签，顺带把 XSS 面收窄了）。

**头像是绝对地址**：管理后台下发相对路径（走 proxy 与同域 nginx 能直接用），
移动端没有「当前域名」，所以这一端由服务端用 `APP_URL` 拼好再下发。同一份数据、
两种形状——这正是接口分端的意义。

---

## 14. 前后端并行约定

- **契约先行**：本文定义的路径、权限标识、错误码是双方的共同依据，任何一方要改必须先改本文并同步对方
- **Mock 数据**：前端按本文结构自造 mock，不等后端；后端接口就绪后切换 baseURL 即可
- **字段增删**：新增字段不算破坏性变更；删除或改名字段必须提前一个版本标记废弃
- **联调顺序**：`/admin/auth/*` → `/admin/dict/all` → 各业务模块。前两个通了，后面的页面才有基础数据
