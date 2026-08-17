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
| 追踪 | 每个响应含 `traceId`，报障时提供它即可定位日志 |

### 1.2 响应体

**HTTP 状态码表达结果，成功只有 2xx。** 成功响应直接返回数据本体，不包 `code` 信封；错误响应才有统一的错误结构。

**成功**

```http
HTTP/1.1 200 OK
X-Trace-Id: TRC-8f21c4d9
```
```json
{ "id": 12, "username": "zhangming", "realName": "张明" }
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
  "traceId": "TRC-8f21c4d9"
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
  "traceId": "TRC-...",
  "details": { "username": ["账号已存在"], "phone": ["格式不正确"] }
}
```

**两层结构**：HTTP 状态码表达**大类**（该重试还是该改参数、该跳登录还是该提权限），业务码表达**具体原因**（同是 409，是账号重复还是部门下有用户）。前端先按状态码分派，再按 `code` 细化提示。

**`traceId` 走响应头 `X-Trace-Id`**，成功与失败都有；错误响应体里再带一份，方便用户直接截图报障。

**为什么不用「全 200 + code」**

- 网关、负载均衡、APM 天然按状态码统计错误率与告警，不必额外接业务码
- 浏览器 DevTools、日志、链路追踪的过滤器都基于状态码，排查时省事
- 与开放平台、第三方回调、各类 HTTP 客户端库的默认预期一致，不需要为外部单独做一套
- 语义自解释：新人看 `403` 就知道是权限问题，不必翻码表

**注意**：这样一来 4xx 里会有大量正常的业务错误（账号重复、参数不合法），**告警阈值应以 5xx 为主**，4xx 只看趋势与突增，否则告警会被日常业务噪音淹没。

### 1.3 分页

请求（query）：

```
?pageNum=1&pageSize=20&keyword=张&status=1&sortField=created_at&sortOrder=desc
```

响应（`200 OK`，直接返回列表结构，不包信封）：

```json
{
  "list": [],
  "total": 248,
  "pageNum": 1,
  "pageSize": 20
}
```

- `pageSize` 默认 20，最大 100，超出按 100 处理
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
  const { code, message, traceId, details } = data ?? {}

  if (!err.response)  return toast('网络异常，请稍后重试')        // 断网、超时
  switch (status) {
    case 401: clearToken(); redirectToLogin(); break             // 含 token 过期、账号停用
    case 403: router.push('/403'); break                         // 无权限 / 数据权限不足
    case 404: toast('数据不存在或已被删除'); break
    case 422: showFieldErrors(details); break                    // 表单字段级回填
    case 429: toast(`操作过于频繁，请 ${err.response.headers['retry-after']} 秒后重试`); break
    default:  toast(status >= 500 ? '服务暂时不可用' : message, traceId)
  }
  return Promise.reject(err)
})
```

- 业务错误的文案直接用后端返回的 `message`，前端不维护第二份文案
- 需要针对具体原因做特殊交互时（如 409 的"账号已存在"要聚焦到用户名输入框），才判断 `code`

### 1.4 其他约定

- 时间统一 `YYYY-MM-DD HH:mm:ss` 字符串，不传时间戳
- 金额传**分**（整数），前端负责换算展示
- 布尔用 `true/false`，不用 `0/1`
- 树形数据统一 `children` 字段，空树返回 `[]` 而非 `null`
- 批量操作传数组 `{"ids": [1,2,3]}`，返回成功与失败明细
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
| `500 Internal Server Error` | 未捕获异常 | 代码 bug、依赖故障 | 提示"服务暂时不可用" + traceId |
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
| 401 | `10103` | 账号已在其他设备登录 | 开启单点登录时 |
| 403 | `10301` | 无权限访问 | 缺少功能权限点 |
| 403 | `10302` | 数据权限不足 | 数据在可见范围外 |
| 403 | `10303` | 字段权限不足 | 修改无权编辑的字段 |
| 404 | `10404` | 数据不存在或已被删除 | 含无权见的伪装 |
| 409 | `10409` | 数据已被他人修改，请刷新后重试 | 乐观锁冲突 |
| 422 | `10422` | 参数校验失败 | `details` 含字段级错误 |
| 429 | `10429` | 操作过于频繁 | 响应头带 `Retry-After` |
| 500 | `10500` | 服务暂时不可用 | 仅返回 traceId，不返回堆栈 |

**管理后台**

| HTTP | code | message | 触发场景 |
|---|---|---|---|
| 401 | `20001` | 账号或密码错误 | **不区分**账号不存在与密码错误 |
| 401 | `20002` | 账号已被停用 | |
| 401 | `20003` | 账号已锁定，请 N 分钟后重试 | 连续失败超限 |
| 401 | `20007` | 密码已过期，请修改后登录 | |
| 400 | `20004` | 验证码错误或已过期 | |
| 400 | `20005` | 原密码错误 | 修改密码 |
| 422 | `20006` | 新密码不符合安全策略 | 长度、复杂度 |
| 409 | `20101` | 账号已存在 | 新建用户 |
| 400 | `20104` | 请先完成数据交接 | 停用有归属数据的账号 |
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
| 409 | `20501` | 字典编码已存在 | |
| 409 | `20502` | 字典项已被引用，不可修改其值 | |
| 403 | `20601` | 内置参数不可删除 | |
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
{ "username": "admin", "password": "******", "captchaKey": "xxx", "captchaCode": "1234" }
```

```json
{
  "accessToken": "eyJ...",
  "refreshToken": "eyJ...",
  "expiresIn": 7200,
  "mustChangePassword": true
}
```

- `accessToken` 有效期 2 小时，`refreshToken` 7 天
- token 内**只放 `uid`、`type`、签发时间**，权限从 Redis 读取，不塞进 token
- `mustChangePassword` 为 true 时前端强制跳转改密页，不允许进入系统

### 当前用户信息

登录后第一个请求，前端据此渲染菜单与按钮权限。

```json
{
  "user": {
    "id": 1, "username": "admin", "realName": "系统管理员",
    "avatar": "", "deptId": 1, "deptName": "总公司", "isSuper": true
  },
  "roles": ["ROLE_SUPER"],
  "permissions": ["sys:user:list", "sys:user:edit", "biz:item:list"],
  "dataScope": 1,
  "menus": [
    {
      "id": 1, "name": "概览", "path": "/", "component": "Layout",
      "icon": "Odometer", "visible": true, "children": [
        {
          "id": 2, "name": "系统概览", "path": "/dashboard",
          "component": "views/dashboard/index.vue",
          "permCode": "sys:dashboard:view", "visible": true, "keepAlive": true
        }
      ]
    }
  ]
}
```

- `menus` 只返回 `type IN (1,2)` 且当前用户有权的节点，按钮权限在 `permissions` 数组里
- 超级管理员的 `permissions` 返回 `["*"]`，前端 `v-permission` 见到 `*` 直接放行

---

## 4. 用户管理

| 方法 | 路径 | 权限标识 | 说明 |
|---|---|---|---|
| GET | `/admin/users` | `sys:user:list` | 列表，支持 `deptId` `includeChildDept` `status` `roleId` `keyword` |
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
GET /admin/users?deptId=3&includeChildDept=true&status=1&pageNum=1&pageSize=20
```

`includeChildDept` 默认 `true`：选中部门时包含其所有子部门的用户（对应原型上的"包含子部门"开关）。

**分配角色**

```http
PUT /admin/users/12/roles
{ "roleIds": [3, 5] }
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
{ "permissionIds": [1,2,5,8,12] }
```

保存后递增所有持有该角色用户的 `perm_version`。

**数据权限**

```http
PUT /admin/roles/3/data-scope
{ "dataScope": 5, "deptIds": [3,4,7] }   // deptIds 仅 dataScope=5 时必填
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
| GET | `/admin/menus/matrix` | `sys:menu:list` | 角色 × 权限矩阵（只读审计视图） |

**本模块只定义权限点，不做授权**。`GET /admin/menus/tree` 返回的每个节点带 `grantedRoleCount`，仅供展示。

---

## 8. 数据字典

| 方法 | 路径 | 权限标识 | 说明 |
|---|---|---|---|
| GET | `/admin/dicts/{code}/items` | 登录态 | **某个字典的启用项**，页面按需取 |
| GET | `/admin/dicts/batch?codes=a,b,c` | 登录态 | 批量预热，一个列表页一次拉齐 |
| GET | `/admin/dicts` | `sys:dict:list` | 字典类型列表（维护界面用） |
| POST/PUT/DELETE | `/admin/dicts/{id}` | `sys:dict:create` / `update` / `delete` | 类型增改删 |
| GET | `/admin/dicts/{code}/items/all` | `sys:dict:list` | 字典项（含停用，维护界面用） |
| POST/PUT/DELETE | `/admin/dict-items/{id}` | `sys:dict:create` / `update` / `delete` | 字典项增改删（改已引用的 value 时 409 + `20502`） |

```json
GET /admin/dicts/common_status/items → 200 OK
[
  { "label": "正常", "value": "1", "tagType": "success" },
  { "label": "异常", "value": "3", "tagType": "danger" }
]
```

读取接口只要**登录态**：字典是全站下拉与状态色的基础数据，
要求 `sys:dict:list` 会让没有字典管理权限的账号连状态标签都渲染不出来。

前端存入 Pinia（`stores/dict.ts`，带缓存与并发去重），
`<DictTag code="common_status" :value="row.status" />` 直接渲染，颜色由 `tagType` 驱动。
服务端缓存 5 分钟，字典维护接口写入后主动 `DictService::forget()`。

---

## 9. 参数配置

| 方法 | 路径 | 权限标识 | 说明 |
|---|---|---|---|
| GET | `/admin/params?group=security` | `sys:param:list` | 按分组查询 |
| PUT | `/admin/params` | `sys:param:update` | 批量保存 `[{key, value}]` |
| GET | `/admin/params/public` | 公开 | 登录页需要的少量参数（系统名、Logo、页脚） |

`is_secret = 1` 的参数**只写不读**：查询时返回掩码 `******`，保存时值为掩码则跳过更新。

---

## 10. 日志

| 方法 | 路径 | 权限标识 | 说明 |
|---|---|---|---|
| GET | `/admin/logs/operation` | `sys:log:operation:list` | 操作日志，必带时间范围 |
| GET | `/admin/logs/operation/{id}` | `sys:log:operation:list` | 详情，含字段级变更 |
| GET | `/admin/logs/login` | `sys:log:login:list` | 登录日志 |
| GET | `/admin/logs/operation/export` | `sys:log:operation:export` | 导出（导出行为本身也记日志） |

时间范围为**必填**，未传时后端默认最近 7 天，避免全表扫描。

---

## 11. 个人中心

| 方法 | 路径 | 权限 | 说明 |
|---|---|---|---|
| GET | `/admin/profile` | 登录态 | 个人资料 |
| PUT | `/admin/profile` | 登录态 | 修改姓名、头像、邮箱（部门角色只读） |
| PUT | `/admin/profile/password` | 登录态 | 修改密码，需原密码 |
| PUT | `/admin/profile/phone` | 登录态 | 换绑手机，需短信验证 |
| GET | `/admin/profile/logins` | 登录态 | 我的登录记录 |
| GET/PUT | `/admin/profile/notifications` | 登录态 | 通知偏好 |

---

## 12. 通用能力

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/admin/upload` | 文件上传，返回 `{url, name, size}`；先入临时目录，业务保存后转正 |
| GET | `/admin/common/regions` | 省市区数据（如需要） |
| GET | `/ping` · `/client/ping` · `/open/ping` | 各端存活探测，用于验证分端中间件链路 |

---

## 13. 前后端并行约定

- **契约先行**：本文定义的路径、权限标识、错误码是双方的共同依据，任何一方要改必须先改本文并同步对方
- **Mock 数据**：前端按本文结构自造 mock，不等后端；后端接口就绪后切换 baseURL 即可
- **字段增删**：新增字段不算破坏性变更；删除或改名字段必须提前一个版本标记废弃
- **联调顺序**：`/admin/auth/*` → `/admin/dict/all` → 各业务模块。前两个通了，后面的页面才有基础数据
