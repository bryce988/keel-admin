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
| `422 Unprocessable Entity` | 参数校验失败 | 字段格式、必填缺失 | `details` 回填表单 |
| `429 Too Many Requests` | 触发限流 | 登录、短信、导出 | 提示 `Retry-After` 秒后重试 |
| `500 Internal Server Error` | 未捕获异常 | 代码 bug、依赖故障 | 提示"服务暂时不可用" + trace_id |
| `503 Service Unavailable` | 服务不可用 | 维护中、依赖不可用 | 提示维护中 |

**关于 404**：查询不存在的资源、和查询存在但超出数据权限的资源，**返回同一个 404**。若前者 404、后者 403，攻击者就能通过状态码差异枚举出哪些 ID 是存在的。

**关于 401 与 403**：401 是"你是谁我不认"，403 是"我认得你但不让你干"。登录失败一律 401，不因账号不存在/密码错/已停用而给不同状态码。

### 2.2 业务码（细分原因）

业务码只在错误响应里出现，用于区分同一状态码下的不同原因。分段：

| 区间 | 归属 |
|---|---|
| `10000-19999` | 通用（各端共用） |
| `20000-29999` | 管理后台 |
| `30000-39999` | C 端（二期） |
| `40000-49999` | 开放平台（二期） |

**通用**

| HTTP | code | message | 说明 |
|---|---|---|---|
| 401 | `10101` | 登录已过期，请重新登录 | token 缺失、无效或过期 |
| 401 | `10102` | 登录凭证类型不匹配 | 员工 token 调 C 端接口，或反之 |
| 401 | `10103` | 密码已变更，请重新登录 | 本人改密或管理员重置密码后，该用户**全部**令牌立即失效 |
| 403 | `10301` | 无权限访问 | 缺少功能权限点 |
| 403 | `10302` | 数据权限不足 | 数据在可见范围外 |
| 403 | `10303` | 字段权限不足 | 修改无权编辑的字段 |
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
| 401 | `20007` | 密码已过期，请修改后登录 | |
| 400 | `20004` | 验证码错误或已过期 | |
| 400 | `20005` | 原密码错误 | 修改密码 |
| 422 | `20006` | 新密码不符合安全策略 | 长度、复杂度 |
| 409 | `20101` | 账号已存在 | 新建用户 |
| 400 | `20104` | 请先完成数据交接 | 停用有归属数据的账号 |
| 400 | `20105` | 不能删除/停用自己的账号 | 用户管理里对自己动手 |
| 409 | `20106` | 手机号已被其他账号使用 | 个人中心换绑手机 |
| 403 | `20103` | 不允许操作超级管理员 | 改角色、停用、删除 |
| 409 | `20201` | 部门编码已存在 | |
| 400 | `20202` | 上级部门不能是自己或其子部门 | 移动部门 |
| 409 | `20203` | 部门下存在用户或子部门，无法删除 | |
| 409 | `20301` | 角色编码已存在 | |
| 403 | `20302` | 内置角色不允许修改或删除 | |
| 409 | `20303` | 角色下存在用户，无法删除 | |
| 400 | `20304` | 与角色「X」互斥，不可同时授予 | 职责分离约束 |
| 400 | `20305` | 超出单账号角色数量上限 | |
| 400 | `20306` | 继承角色不可形成环 | |
| 409 | `20401` | 权限标识已存在 | |
| 409 | `20402` | 权限点被角色引用，请改为停用 | |
| 400 | `20403` | 上级菜单不能是自己或其子节点 | |
| 409 | `20501` | 字典编码已存在 | 同字典内字典项的值重复也用这个码 |
| 409 | `20502` | 字典项已被引用，不可修改其值 | 也用于「类型下有项时不许改编码 / 不许删类型」 |
| 403 | `20601` | 内置参数不可删除 | |
| 409 | `20602` | 参数键已存在 | |
| 400 | `20701` | 导出数据量超过上限 | 提示缩小筛选范围 |

前端针对大类的处理看状态码即可；**只有需要特殊交互时才判断 `code`**（如 409 + `20101` 时聚焦到用户名输入框）。文案一律用后端返回的 `message`，前端不维护第二份。

---

## 3. 认证

| 方法 | 路径 | 权限 | 说明 |
|---|---|---|---|
| GET | `/admin/auth/captcha` | 公开 | 获取图形验证码 |
| POST | `/admin/auth/login` | 公开 | 登录 |
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

### 当前用户信息

登录后第一个请求，前端据此渲染菜单与按钮权限。

```json
{
  "user": {
    "id": 1, "username": "admin", "real_name": "系统管理员",
    "avatar": "", "dept_id": 1, "dept_name": "总公司", "is_super": true
  },
  "roles": ["ROLE_SUPER"],
  "permissions": ["sys:user:list", "sys:user:update", "biz:item:list"],
  "data_scope": 1,
  "menus": [
    {
      "id": 1, "name": "概览", "path": "/dashboard",
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
| GET | `/admin/users/{id}` | `sys:user:list` | 详情 |
| POST | `/admin/users` | `sys:user:create` | 新建 |
| PUT | `/admin/users/{id}` | `sys:user:update` | 编辑 |
| DELETE | `/admin/users/{id}` | `sys:user:delete` | 删除（软删） |
| PUT | `/admin/users/{id}/status` | `sys:user:update` | 启用 / 停用 |
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
| GET | `/admin/depts/{id}` | `sys:dept:list` | 详情 |
| POST | `/admin/depts` | `sys:dept:create` | 新建 |
| PUT | `/admin/depts/{id}` | `sys:dept:update` | 编辑（移动时同步更新子孙 `ancestors`） |
| DELETE | `/admin/depts/{id}` | `sys:dept:delete` | 删除（有用户或子部门时 409 + `20203`） |
| GET | `/admin/posts` | `sys:post:list` | 岗位列表 |
| POST/PUT/DELETE | `/admin/posts/{id}` | `sys:post:create` / `update` / `delete` | 岗位增改删 |

部门树是用户列表筛选面板的数据源，因此**任一权限满足即可**读取——
只有用户管理权限、没有部门管理权限的账号也要能按部门筛人。

---

## 6. 角色管理

| 方法 | 路径 | 权限标识 | 说明 |
|---|---|---|---|
| GET | `/admin/roles` | `sys:role:list` | 列表（内置/自定义分组） |
| GET | `/admin/roles/{id}` | `sys:role:list` | 详情，含继承与约束 |
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
| GET | `/admin/menus/{id}` | `sys:menu:list` | 节点详情 |
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
{ "id": 8, "type_code": "user_status", "label": "在职", "value": "1",
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

## 9. 参数配置

| 方法 | 路径 | 权限标识 | 说明 |
|---|---|---|---|
| GET | `/admin/params?group=security` | `sys:param:list` | 按分组查询，不分页 |
| GET | `/admin/params/groups` | `sys:param:list` | 分组元信息，前端据此渲染 tab |
| PUT | `/admin/params` | `sys:param:update` | **批量保存**，见下 |
| GET | `/admin/params/{id}` | `sys:param:list` | 单条详情 |
| POST/PUT/DELETE | `/admin/params/{id}` | `sys:param:create` / `update` / `delete` | 自定义参数增改删（删内置 403 + `20601`，键重复 409 + `20602`） |
| GET | `/admin/params/public` | 公开 | 登录页需要的少量参数（系统名、Logo、页脚） |

分组固定四个：`basic` 基础设置 · `security` 安全策略 · `integration` 第三方集成 · `advanced` 高级选项。

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
| GET | `/admin/logs/operation/{id}` | `sys:log:operation:list` | 详情，含字段级变更与脱敏后的入参 |
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

**换绑手机为什么不用短信**：Keel 是不含业务逻辑的脚手架，绑死某家短信服务商
等于替使用者做了选型。这里用当前密码验证身份，接短信的项目把
`ProfileService::changePhone()` 里的密码校验换成验证码校验即可，其余不动。

**通知偏好不做**：系统里没有任何通知消费方（无站内信、无邮件模板、无推送通道），
只做一个偏好开关就是没人读的死配置。等通知能力落地时连同消费方一起加。

---

## 12. 通用能力

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/admin/upload` | 文件上传，返回 `{url, name, size}`；先入临时目录，业务保存后转正 |
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

## 13. 前后端并行约定

- **契约先行**：本文定义的路径、权限标识、错误码是双方的共同依据，任何一方要改必须先改本文并同步对方
- **Mock 数据**：前端按本文结构自造 mock，不等后端；后端接口就绪后切换 baseURL 即可
- **字段增删**：新增字段不算破坏性变更；删除或改名字段必须提前一个版本标记废弃
- **联调顺序**：`/admin/auth/*` → `/admin/dict/all` → 各业务模块。前两个通了，后面的页面才有基础数据
