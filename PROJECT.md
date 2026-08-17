# Keel · 项目文档

> **Keel（龙骨）** —— 多端后台系统的底座
> 版本 v1.3 · 2026-08-13 · 状态：原型定稿，待进入开发 · 开源协议 MIT
> 技术栈：Vue 3 + Element Plus / PHP 8.1 + webman 2.x（多应用）
> 仓库：`keel-admin`（monorepo）· Composer `keel/admin` · npm `@keel/ui`
> 在线预览：http://43.143.249.52:8080（演示账号 admin / admin123）
> 交互原型（静态稿）：https://claude.ai/code/artifact/97f2c6d1-9b75-4927-8b38-926d0cb926f2
> webman 官方文档：https://www.workerman.net/doc/webman/install.html · 多应用：https://www.workerman.net/doc/webman/multiapp.html

---

## 1. 项目定位

**Keel** 是一套**不含业务逻辑的开源后台脚手架**：登录鉴权、菜单导航、多页签工作区、RBAC 权限体系、系统管理（用户/部门/角色/权限/字典/参数/日志）与五种通用页型模板全部就位，业务模块在其上增量开发。

名字取自船体最底层的主梁——**所有结构都搭在龙骨上**，这正是脚手架该扮演的角色：它不决定你造什么船，只保证结构立得住。

**目标**

- 新业务模块从建表到可用页面，前端工作量控制在 1–2 人日
- 权限、日志、字典三件事在框架层解决，业务代码不重复实现
- 所有列表/表单/详情页视觉与交互一致，不因人而异

**不做的事（一期）**

工作流引擎、报表设计器、多租户隔离、国际化多语言（预留但不实现）、移动端适配（仅保证窄屏可用）。

**开源定位**

- 协议 MIT，允许商用与闭源二次开发
- 定位是「拿来就能接业务的底座」，不是演示项目：所有示例数据可一键清空
- 版本号遵循语义化版本；`main` 始终可用，功能在 `develop` 合并后统一发版

---

## 2. 技术选型

### 2.1 前端

| 层次 | 选型 | 说明 |
|---|---|---|
| 框架 | Vue 3 + `<script setup>` | Composition API，不使用 Options API |
| 语言 | TypeScript | `strict: true`，禁止 `any`（确需时用 `unknown` + 断言） |
| 构建 | Vite 5 | 开发热更新，生产分包 |
| UI | Element Plus 2.x | 按需引入，主题通过 CSS 变量覆盖 |
| 状态 | Pinia | 仅存全局态：用户信息、权限、字典、页签 |
| 路由 | Vue Router 4 | 静态基础路由 + 动态业务路由 |
| 请求 | Axios | 统一封装，见 §7 |
| 样式 | SCSS + CSS 变量 | 令牌见 §10，不写死颜色 |
| 校验 | Element Plus 内置 + 自定义规则集 | 手机号、身份证等规则统一维护 |
| 代码规范 | ESLint + Prettier + Stylelint | 提交前钩子强制执行 |

### 2.2 后端

| 层次 | 选型 | 说明 |
|---|---|---|
| 运行时 | **PHP >= 8.1** | webman 2.x 要求，低于此版本无法安装 |
| 框架 | **webman 2.x**（Workerman） | 常驻内存的 HTTP 服务，非 PHP-FPM 模型，见 §14 |
| 包管理 | Composer >= 2.0 | `composer create-project workerman/webman:~2.0` |
| ORM | `webman/database`（illuminate/database） | Laravel Eloquent，团队熟悉度优先 |
| 缓存 | `webman/redis` | 会话、字典缓存、接口限流 |
| 日志 | `webman/log`（Monolog） | 按天切分，配置见 `config/log.php` |
| 校验 | `respect/validation` | 统一在 Request 层校验，控制器不写校验逻辑 |
| 鉴权 | `firebase/php-jwt` | 无状态 JWT，见 §7.5 |
| 定时任务 | `workerman/crontab` | 以自定义进程运行，见 §14 |
| 队列 | `webman/redis-queue` | 导出、通知等耗时任务异步化 |
| 接口文档 | OpenAPI 3.0（注解生成） | 与前端联调的唯一依据 |

**为什么选 webman**：常驻内存带来的性能收益（相比 FPM 提升数倍），且天然适合长连接与定时任务。代价是编程模型与 FPM 不同，全局状态会跨请求存活，**所有开发人员上手前必须先读 §14**。

---

## 3. 目录结构

### 3.0 仓库结构（monorepo）

前后端同仓发布，版本号同步，使用者 clone 一次即可跑通全套。

```
keel-admin/
├── web/                  # 前端 · Vue 3 + Element Plus
├── server/               # 后端 · webman 多应用
├── docs/                 # 文档站源文件（本文件亦在此）
├── docker/               # 一键启动：nginx + php + mysql + redis
├── .github/workflows/    # CI：lint、单测、构建、发版
├── README.md             # 英文 + 中文双语说明
├── CONTRIBUTING.md       # 贡献指南与提交规范
├── CHANGELOG.md          # 按语义化版本记录
└── LICENSE               # MIT
```

**发版策略**：`web/` 与 `server/` 共用同一个 tag（如 `v1.2.0`），保证前后端接口对得上；单端修复用 patch 版本。

### 3.1 前端

```
src/
├── api/                  # 接口定义，按模块分文件，只放请求不放逻辑
│   ├── system/           # user.ts / dept.ts / role.ts / menu.ts / dict.ts
│   └── biz/              # 业务模块接口
├── assets/               # 静态资源
├── components/           # 全局通用组件
│   ├── ProTable/         # 列表页表格（含列设置、URL 同步）
│   ├── SearchForm/       # 可折叠搜索区
│   ├── DictSelect/       # 字典下拉
│   ├── DictTag/          # 字典状态标签
│   ├── PermButton/       # 带权限校验的按钮
│   └── UploadFile/       # 统一上传
├── directives/           # v-permission / v-debounce
├── hooks/                # useTable / useForm / useDict / usePermission
├── layout/               # 布局：侧边菜单、顶栏、页签、主区
├── router/               # 路由定义与守卫
├── stores/               # Pinia：user / permission / dict / tagsView / app
├── styles/               # 变量、令牌、Element Plus 主题覆盖
├── utils/                # request.ts / auth.ts / format.ts / validate.ts
├── views/
│   ├── login/
│   ├── dashboard/
│   ├── system/           # user / dept / role / menu / dict / param / log
│   ├── template/         # 五种页型模板，新模块从这里复制
│   └── error/            # 403 / 404 / 500
└── types/                # 全局类型定义
```

**约定**：`views` 下每个模块一个目录，页面组件 `index.vue`，弹窗/抽屉放同级 `components/`，接口类型放 `types.ts`。

### 3.2 后端（webman）

采用 **webman 多应用模式**，`app/` 下按端拆子应用，共享代码放 `common/`。完整的分端设计见 §8。

```
app/
├── admin/                # 管理后台（一期唯一实现的端）
│   ├── controller/       # 只做参数编排与响应，不写业务
│   ├── service/          # 后台专有逻辑
│   └── validate/         # 请求校验规则
├── client/               # App / 小程序（二期，一期建空壳）
├── open/                 # 开放平台与第三方回调（二期）
├── internal/             # 内部服务调用（预留）
├── common/               # ★ 各端共享
│   ├── model/            # Eloquent 模型，含全局数据权限 Scope
│   ├── service/          # 核心业务逻辑，事务边界在这一层
│   ├── middleware/       # Trace / Auth / Permission / Log
│   ├── exception/        # BusinessException 等
│   ├── enum/             # 状态枚举，与数据字典同名同值
│   └── support/          # 助手与基础设施
├── process/              # 自定义进程：定时任务、队列消费
└── functions.php         # 全局助手函数（谨慎添加）
config/
├── app.php               # 应用配置
├── route.php             # 路由（分组 + 中间件）
├── database.php          # 数据库连接
├── redis.php             # Redis 连接
├── middleware.php        # 中间件管道（可按应用分别配置）
├── exception.php         # 异常处理器（可按应用分别配置）
├── log.php               # 日志通道
├── server.php            # 监听端口、进程数
├── process.php           # 自定义进程注册
└── plugin/               # 插件配置
public/                   # 静态资源（前端构建产物可托管于此，或独立 Nginx）
runtime/                  # 日志、缓存、PID，需可写
start.php                 # 启动入口
```

**分层约定**

- 控制器**不允许**直接写 SQL 或 Eloquent 查询，一律经 `service/`
- 事务边界只在 `service/` 层开启，控制器与模型内禁止 `DB::beginTransaction()`
- 各端只写自己的 controller，业务逻辑一律下沉 `common/service`，**端与端之间不得互相引用**
- 模型只放字段定义、关联、访问器与全局 Scope，不放业务判断
- 枚举值必须在 `app/enum/` 定义，并与数据字典同名同值，不允许两边各写一套

---

## 4. 页面清单与路由

| 路由 | 页面 | 权限标识 | 说明 |
|---|---|---|---|
| `/login` | 登录 | — | 账号密码 + 图形验证码，支持三方登录预留 |
| `/dashboard` | 系统概览 | `sys:dashboard:view` | 指标卡、趋势、系统状态、待办 |
| `/ui/spec` | 组件规范 | `sys:dashboard:view` | 设计令牌与组件用法，仅开发环境显示 |
| `/template/list` | 标准列表页 | `biz:item:list` | 新列表页从此复制 |
| `/template/form` | 表单页 | `biz:item:edit` | 新增/编辑共用 |
| `/template/detail/:id` | 详情页 | `biz:item:view` | 不在菜单，由列表进入 |
| `/template/tree` | 树表页 | `biz:item:list` | 分类型数据 |
| `/template/result` | 结果与异常页 | — | 结果页与错误页样式 |
| `/system/user` | 用户管理 | `sys:user:list` | 账号、角色分配、停用 |
| `/system/dept` | 部门管理 | `sys:dept:list` | 组织树、岗位 |
| `/system/role` | 角色管理 | `sys:role:list` | 功能权限、数据权限、字段权限 |
| `/system/menu` | 菜单与权限 | `sys:menu:list` | 权限点字典、角色权限矩阵 |
| `/system/dict` | 数据字典 | `sys:dict:list` | 字典类型与字典项 |
| `/system/param` | 参数配置 | `sys:param:edit` | 基础、安全、集成、高级参数 |
| `/system/log` | 操作日志 | `sys:log:list` | 操作/登录/接口日志 |
| `/profile` | 个人中心 | — | 资料、安全设置、通知偏好、登录日志 |

详情页、个人中心不在菜单中，菜单高亮通过路由 `meta.activeMenu` 指回其列表页。

---

## 5. 布局与导航

**整体结构**：左侧菜单（210px，可折叠至 64px）+ 顶栏（面包屑、全局搜索、主题切换、头像菜单）+ 页签条 + 内容区。

**菜单**

- 两级结构，一级只负责分组展开，不承载页面
- 折叠态显示图标，悬停弹出二级面板
- 折叠状态存 `localStorage`
- 菜单由后端返回的权限树动态生成，前端不硬编码菜单

**多页签（tags-view）**

- 打开过的页面累积为页签，点击切换不重新请求
- 首页签固定不可关闭；上限 8 个，超出淘汰最早打开的（当前页与首页签不淘汰）
- 右键菜单：刷新当前 / 关闭 / 关闭右侧 / 关闭其他 / 全部关闭
- 页签列表与当前页写入 `localStorage`，刷新后恢复；恢复前校验路由是否仍存在

**内容区**：全宽铺满，不设最大宽度；页面内边距 16px，卡片间距 16px。

---

## 6. 权限设计（RBAC）

### 6.1 模型

```
用户 User ──(多对多)── 角色 Role ──(多对多)── 权限点 Permission ──→ 资源 Resource
                          │                                        (菜单/按钮/接口/数据)
                          └──→ 数据范围 DataScope
```

- **用户不直接绑定权限**，只绑定角色；多角色时功能权限取并集
- **角色继承（RBAC1）**：子角色继承父角色全部权限，继承来的权限不可取消，只能追加
- **职责分离（RBAC2）**：互斥角色不可同时授予；单账号角色数上限可配置

### 6.2 权限标识规范

格式 `模块:资源:操作`，全局唯一，一经使用不可修改。

```
biz:item:list        列表访问（菜单级）
biz:item:create      新建
biz:item:edit        编辑
biz:item:delete      删除
biz:item:export      导出
sys:user:list        用户管理
sys:role:grant       角色授权
sys:field:phone      查看手机号明文（字段级）
sys:field:amount     查看金额字段（字段级）
```

### 6.3 四类权限点

| 类型 | 作用 | 前端 | 后端 |
|---|---|---|---|
| 菜单 | 能否进入页面 | 路由守卫 | 接口拦截 |
| 按钮 | 页面内操作 | `v-permission` 指令 | 接口注解校验 |
| 接口 | 服务端强校验 | — | 必须校验 |
| 数据 | 字段级可见/可编辑 | 字段渲染判断 | 出参脱敏 |

**硬性要求**：前端隐藏不等于有权限控制，所有写接口与敏感读接口必须在服务端独立校验，前后端共用同一份权限标识字典。

### 6.4 数据权限

角色上配置数据范围，与功能权限正交：

`全部数据` / `本部门及下属部门` / `本部门` / `仅本人` / `自定义部门`

多角色时取范围最大者。所有带「归属人 / 归属部门」字段的业务表，在 SQL 层统一注入过滤条件，不在业务代码里手写。

### 6.5 内置角色

超级管理员（不可编辑不可删除）、部门主管、普通员工、数据管理员、技术支持、只读访客、系统审计。

### 6.6 服务端实现（webman）

**中间件管道**（`config/middleware.php` 中按序注册）

```
TraceMiddleware      生成 traceId，写入上下文与响应
  → AuthMiddleware     解析 JWT，取出用户 ID，加载用户与角色
    → PermissionMiddleware  校验当前路由所需权限点
      → LogMiddleware       记录写操作与字段级变更
```

**权限点绑定路由**：在 `config/route.php` 分组上声明所需权限，避免散落在控制器里。

```php
Route::group('/api/system', function () {
    Route::get('/users',        [UserController::class, 'index'])->setParams(['perm' => 'sys:user:list']);
    Route::post('/users',       [UserController::class, 'store'])->setParams(['perm' => 'sys:user:edit']);
    Route::delete('/users/{id}',[UserController::class, 'destroy'])->setParams(['perm' => 'sys:user:edit']);
})->middleware([AuthMiddleware::class, PermissionMiddleware::class]);
```

**数据权限统一注入**：所有带归属字段的模型继承 `BaseModel`，在 `booted()` 中挂全局 Scope，按当前用户的数据范围拼接条件。业务代码不得手写归属过滤，也不得随意 `withoutGlobalScope()`（确需时须在 Code Review 中说明理由）。

```php
protected static function booted(): void
{
    static::addGlobalScope('dataScope', function (Builder $q) {
        $user = Context::get('user');           // 请求级上下文，不是静态变量
        match ($user->dataScope) {
            DataScope::ALL     => null,
            DataScope::DEPT_SUB=> $q->whereIn('dept_id', $user->subDeptIds()),
            DataScope::DEPT    => $q->where('dept_id', $user->deptId),
            DataScope::SELF    => $q->where('owner_id', $user->id),
            DataScope::CUSTOM  => $q->whereIn('dept_id', $user->customDeptIds()),
        };
    });
}
```

**字段级权限**：在响应序列化层统一处理，无 `sys:field:phone` 的用户拿到的手机号即为脱敏值，**不是前端打码**——接口原始返回就是脱敏的。

**权限缓存**：用户权限点集合缓存进 Redis（key 含用户 ID + 权限版本号），角色授权变更时递增版本号使其失效，不依赖 TTL 过期。

---

## 7. 接口约定

### 7.1 统一响应体

**HTTP 状态码表达结果，成功只有 2xx**（200 查询更新 / 201 创建 / 204 删除）。成功直接返回数据本体，不包 `code` 信封：

```json
{ "id": 12, "username": "zhangming", "realName": "张明" }
```

错误才有统一结构，HTTP 状态码表大类、业务码表具体原因：

```json
{ "code": 20101, "message": "账号已存在", "traceId": "TRC-8f21c4d9" }
```

- 状态码语义：400 业务规则不允许 · 401 未认证 · 403 无权限 · 404 不存在 · 409 冲突 · 422 校验失败 · 429 限流 · 5xx 服务异常
- `traceId` 走响应头 `X-Trace-Id`，成功失败都有；错误响应体再带一份便于截图报障
- 4xx 中大量是正常业务错误，**告警以 5xx 为主**，4xx 只看趋势与突增
- 完整状态码与业务码表见 [接口契约](docs/api.md#2-状态码与错误码)

### 7.2 分页

请求 `{ pageNum, pageSize, ...filters }`，响应 `{ list, total, pageNum, pageSize }`。默认每页 20 条，最大 100。

### 7.3 前端统一处理

| 情况 | 处理 |
|---|---|
| 无响应（断网、超时） | 提示「网络异常，请稍后重试」 |
| 401 | 清除 token，跳转登录页，记录来源路由 |
| 403 | 跳转 403 页面，提供申请权限入口 |
| 404 | 提示数据不存在，返回列表 |
| 422 | 表单字段级错误回填，聚焦第一个错误项 |
| 429 | 按响应头 `Retry-After` 提示重试时间 |
| 其他 4xx | Message 提示后端返回的 `message` |
| 5xx | 提示「服务暂时不可用」，附 traceId |
| 超时/断网 | 提示「网络异常，请重试」，提供重试按钮 |
| 并发重复请求 | 相同 URL + 参数在 pending 中时取消后发起的请求 |

### 7.4 其他

- 时间统一 `YYYY-MM-DD HH:mm:ss`，后端返回时间戳时前端格式化
- 金额传分（整数），前端展示时除以 100
- 鉴权用 `Authorization: Bearer <token>`，token 存 `localStorage`，30 分钟无操作自动登出

### 7.5 服务端实现（webman）

**统一响应**：不在控制器里手拼数组，统一走助手函数。

```php
return success($data);                    // {code:0, message:"success", data:…, traceId:…}
return fail(40001, '名称已存在');          // 业务错误码，HTTP 仍为 200
throw new BusinessException('名称已存在', 40001);   // 推荐：由异常处理器统一转换
```

**异常处理**：在 `config/exception.php` 注册自定义处理器，统一把异常转成上面的响应体。

| 异常类型 | HTTP | code | 说明 |
|---|---|---|---|
| `BusinessException` | 200 | 业务码 | 可预期的业务错误，前端提示即可 |
| `ValidationException` | 200 | 42200 | 参数校验失败，返回字段级错误 |
| `UnauthorizedException` | 401 | 40100 | 未登录或 token 失效 |
| `ForbiddenException` | 403 | 40300 | 已登录但无权限 |
| 其他未捕获异常 | 500 | 50000 | **生产环境不返回堆栈**，只返回 traceId，详情进日志 |

**JWT**：access token 有效期 2 小时，refresh token 7 天；token 内只放 `uid` 与签发时间，权限从 Redis 缓存读取，不塞进 token（避免授权变更后 token 内权限过期）。用户停用或角色变更时递增权限版本号，使旧 token 立即失效。

**幂等与限流**：写接口支持 `Idempotency-Key` 头（Redis SETNX，10 分钟窗口）；登录、短信等接口按 IP + 账号双维度限流。

**接口文档**：注解生成 OpenAPI，CI 中校验注解与实际路由一致，不一致则构建失败。

---

## 8. 多端支持与入口划分

一期只交付管理后台，但后端从**第一天就按多应用结构搭建**，App 与小程序接入时只加目录、不改架构。webman 原生支持多应用（`app/` 下建子目录，URL 前缀与目录名对应，中间件与异常处理器可按应用单独配置）。

### 8.1 端划分

| 端 | URL 前缀 | 应用目录 | 身份体系 | 鉴权方式 | 权限模型 |
|---|---|---|---|---|---|
| 管理后台 | `/admin/*` | `app/admin` | 员工 `sys_users` | JWT（2h）+ 权限点 | 完整 RBAC |
| App / 小程序 | `/client/v1/*` | `app/client` | C 端用户 `app_users` | JWT + 渠道标识 | 无 RBAC，只做归属校验 + 功能开关 |
| 开放 / 回调 | `/open/*` | `app/open` | 第三方应用 AppID | HMAC 签名 + IP 白名单 | 按 AppID 授权 scope |
| 内部服务 | `/internal/*` | `app/internal` | 服务令牌 | 内网限制 + Token | 不对外暴露 |

App 与小程序**共用 `app/client`**：两者业务接口几乎相同，差异只在登录方式与推送，因此按渠道拆登录入口、共用业务接口，不做两套。

### 8.2 目录结构

```
app/
├── admin/                 # 管理后台
│   ├── controller/
│   ├── service/
│   └── validate/
├── client/                # App + 小程序
│   ├── controller/
│   │   ├── auth/          # WechatController / SmsController / AppleController
│   │   └── v1/            # 业务接口，按版本分目录
│   ├── service/
│   └── validate/
├── open/                  # 开放平台与第三方回调（支付、微信推送）
│   └── controller/
├── internal/              # 内部服务间调用
└── common/                # ★ 各端共享
    ├── model/             # Eloquent 模型（含数据权限 Scope）
    ├── service/           # 核心业务逻辑，各端 controller 复用
    ├── enum/
    ├── exception/
    └── support/
```

命名空间按 PSR-4 对应目录：`app/client/controller/v1/OrderController.php` → `namespace app\client\controller\v1;`

**铁律**

- 模型与核心业务逻辑一律放 `common/`，各端只写 controller 与该端特有的 service
- **端与端之间禁止互相引用 controller**，要复用就下沉到 `common/service`
- 一个业务规则只有一份实现：不允许后台一套下单逻辑、小程序另写一套

### 8.3 分端中间件与异常处理

`config/middleware.php` 按应用配置，全局中间件先执行，再执行应用中间件：

```php
return [
    ''        => [app\common\middleware\TraceMiddleware::class],
    'admin'   => [AdminAuthMiddleware::class, PermissionMiddleware::class, OperationLogMiddleware::class],
    'client'  => [ChannelMiddleware::class, ClientAuthMiddleware::class, RateLimitMiddleware::class],
    'open'    => [SignatureMiddleware::class, IpWhitelistMiddleware::class],
    'internal'=> [InternalTokenMiddleware::class],
];
```

`config/exception.php` 每个应用一个处理器：后台返回字段级校验详情便于联调，C 端只返回精简提示，开放平台返回标准错误结构。

```php
return [
    'admin'  => app\common\exception\AdminHandler::class,
    'client' => app\common\exception\ClientHandler::class,
    'open'   => app\common\exception\OpenHandler::class,
];
```

### 8.4 两套身份体系（关键设计）

**员工与 C 端用户是两套完全独立的体系，永不混用。**

| | 员工 | C 端用户 |
|---|---|---|
| 表 | `sys_users` | `app_users` |
| 登录 | 账号密码 + 验证码 | 手机号验证码 / 微信授权 / Apple ID |
| Token | `type=admin`，2 小时 | `type=client`，7 天 + 刷新 |
| 权限 | RBAC 三层 | 只能操作自己的数据 + 功能开关 |
| 数据范围 | 部门维度 | 仅本人，无部门概念 |

Token 的 `type` claim 由中间件强校验：**员工 token 调 C 端接口、C 端 token 调后台接口，一律 401**。员工若需在手机上办公，走管理端 token 的移动端页面，不下沉为 C 端用户。

### 8.5 C 端接口约定

- **版本化**：`/client/v1/...`，破坏性变更升版本，旧版本保留 6 个月后下线；App 无法强制用户升级，版本必须共存
- **必带请求头**：`X-Channel`（`app-ios` / `app-android` / `mp-weixin` / `h5`）、`X-App-Version`、`X-Device-Id`
- **强制更新**：`GET /client/v1/app/version` 返回最低可用版本与更新说明，低于最低版本时客户端拦截
- **分页用游标**：C 端列表用 `cursor` 而非 `pageNum`，避免深翻页性能问题与数据重复
- **响应裁剪**：C 端接口不返回内部字段（`dept_id`、成本、内部备注），在 `common/service` 之上加 Resource 层裁剪
- **限流与风控**：登录、短信、下单接口按 IP + 设备 + 账号三维度限流；敏感接口加 HMAC 签名
- **错误码分段**：`10000-19999` 通用 · `20000-29999` 管理端 · `30000-39999` C 端 · `40000+` 开放平台

### 8.6 小程序 / App 接入要点

- **微信 access_token 全局唯一且 2 小时过期**：必须存 Redis 并加分布式锁刷新。webman 是多进程常驻，**绝不能放进程内静态变量**——各进程各刷各的会互相顶掉，导致线上随机失效（详见 §14）
- `code2session` 换取 `openid` / `unionid`，与 `app_users` 建立映射，同一用户多端登录合并到同一账号
- 支付回调、订阅消息回调走 `app/open`，回调接口必须验签 + 幂等（同一笔回调可能重复推送）
- App 推送 token 随登录上报，登出时解绑，避免推给已登出设备

### 8.7 部署隔离

一期后台与 C 端同端口（8787）不同应用前缀即可。**C 端上线后按端拆进程组**，避免 C 端流量把后台打满：

```php
// config/process.php —— 为 C 端单独起一组进程监听 8788
return [
    'client-http' => [
        'handler'     => Webman\App::class,
        'listen'      => 'http://0.0.0.0:8788',
        'count'       => cpu_count() * 2,
        'constructor' => [/* 参照官方「慢业务处理」章节的 process 示例填写 */],
    ],
];
```

Nginx 按前缀分流：`/admin/` → 8787，`/client/` → 8788。导出、报表等慢接口再单独走一组进程或队列，不与实时接口抢资源。

### 8.8 一期落地范围

- ✅ 目录、中间件、异常处理器按四端切好
- ✅ `app/admin` 完整实现
- ✅ `app/client`、`app/open` 建空壳 + 一个 `ping` 接口，验证分端中间件与异常处理链路通
- ⛔ C 端业务接口、小程序登录、支付回调不在一期范围

**落地情况（M1 已完成）**

| 端 | 目录 | 应用中间件 | 异常处理器 | 空壳接口 |
|---|---|---|---|---|
| admin | `app/admin` | 挂在路由分组上（见下） | `AdminHandler` | 完整实现 |
| client | `app/client` | `ChannelMiddleware` `RateLimitMiddleware` | `ClientHandler` | `/client/ping`、`/client/v1/profile` |
| open | `app/open` | `IpWhitelistMiddleware` `SignatureMiddleware` | `OpenHandler` | `/open/ping`、`/open/echo` |
| internal | `app/internal` | `InternalTokenMiddleware` | `InternalHandler` | `/internal/ping` |

**与 §8.3 示例的一处偏差**：后台的 `AdminAuth` / `Permission` / `OperationLog` 挂在
`config/route.php` 的分组上，而不是 `config/middleware.php` 的 `'admin'` 键下。
原因是每个端都有公开接口（后台的登录与验证码、C 端的短信登录），
应用级中间件会把登录接口自己也挡住。同理 `ClientAuthMiddleware` 也挂在路由分组上。

**验证方式**：员工 token 调 `/client/v1/profile` 返回 401 + `10102`，
C 端 token 调 `/admin/users` 同样 401 + `10102`，两个错误体结构不同即说明分端链路生效。

这样二期接入 App 或小程序时，只需在 `app/client` 下加控制器，不动架构、不改后台。

---

## 9. 页型规范

### 9.1 列表页

- 结构：搜索区（可折叠）→ 工具栏 → 表格 → 分页
- 第一列为主标识列，点击进详情；副信息用第二行小字，不新增列
- 默认列不超过 8 列，超出放详情页；列设置支持显示/隐藏与排序，按用户持久化
- 操作列固定最右，常用操作不超过 3 个，其余收进「更多」
- **无权限时按钮置灰而非隐藏**，让用户知道功能存在
- 删除等危险操作二次确认，弹窗写明影响范围（如「将同时删除 12 条关联数据」）
- 搜索条件、页码、排序写入 URL query，刷新与分享链接后状态不丢

### 9.2 表单页

- 标签置于控件上方左对齐；长表单按语义分卡片，单卡片字段不超过 8 个
- 失焦校验单字段，提交校验全表；失败后滚动并聚焦第一个错误字段
- 提交按钮点击后进入 loading 并禁用，防重复提交
- 有未保存修改时离开页面二次确认；草稿每 30 秒自动暂存
- 附件上传到临时目录，表单提交后才正式关联，取消时清理

### 9.3 详情页

- 左栏静态属性（只读），右栏动态数据与关联对象
- 关联区块数量为 0 时显示空状态与新建入口，不隐藏区块
- 变更记录展示字段级差异（旧值 → 新值），不可删除

### 9.4 空状态与异常

| 场景 | 文案方向 | 必带动作 |
|---|---|---|
| 无数据 | 暂无数据 | 新建入口 |
| 无搜索结果 | 未找到匹配「关键词」的结果 | 清空筛选 |
| 无权限 | 你没有访问该页面的权限 | 申请权限 |
| 服务异常 | 服务暂时不可用 + 错误码 | 重新加载 |

加载策略：首屏骨架屏，翻页局部遮罩，按钮操作用按钮内 loading，避免整页闪烁。

---

## 10. 设计规范

### 10.1 颜色令牌

| 用途 | 令牌 | 值 |
|---|---|---|
| 主色 | `--el-color-primary` | `#409EFF` |
| 成功 | `--el-color-success` | `#67C23A` |
| 警告 | `--el-color-warning` | `#E6A23C` |
| 危险 | `--el-color-danger` | `#F56C6C` |
| 信息 | `--el-color-info` | `#909399` |
| 主要文字 | `--el-text-color-primary` | `#303133` |
| 常规文字 | `--el-text-color-regular` | `#606266` |
| 次要文字 | `--el-text-color-secondary` | `#909399` |
| 边框 | `--el-border-color` | `#DCDFE6` |
| 分割线 | `--el-border-color-lighter` | `#EBEEF5` |
| 页面底色 | — | `#F2F3F5` |

**状态色语义全站唯一**：同一状态在任何页面必须同色，不允许按页面调整。禁止在业务代码中写死十六进制色值（暗色模式依赖令牌切换）。

### 10.2 尺寸

基准字号 14px（辅助 12px）；页面标题 20px / 卡片标题 15px；控件高度 32px（表格内 24px）；圆角统一 4px；卡片内边距 16px；区块间距 16px。

### 10.3 按钮

每屏只有一个主按钮（蓝色实心），其余为次要按钮；危险操作用红色文字 + 描边，不用红色实心；表格行内一律小号。

### 10.4 反馈层级

Message（轻提示，2 秒）→ Notification（需留存）→ Dialog（需决策）→ 结果页（流程终点）。

---

## 11. 框架能力清单

| 能力 | 实现方式 | 业务方用法 |
|---|---|---|
| 权限按钮 | `v-permission="'biz:item:edit'"` | 无权限自动置灰 |
| 字典 | `useDict('common_status')` | 启动加载进 Pinia，5 分钟过期 |
| 字典标签 | `<DictTag type="common_status" :value="row.status" />` | 颜色由字典配置驱动 |
| 列表页 | `useTable(api.list)` | 自带分页、URL 同步、列设置 |
| 表单 | `useForm(rules)` | 自带校验、防重提交、离开确认 |
| 上传 | `<UploadFile />` | 临时目录 + 提交后关联 |
| 操作日志 | 后端注解自动记录 | 前端无需处理 |

---

## 12. 环境与部署

| 环境 | 分支 | 域名 | 说明 |
|---|---|---|---|
| 开发 | `develop` | localhost:5173 | 代理到测试后端 |
| 测试 | `develop` | test-admin.example.com | 自动部署 |
| 预发 | `release/*` | pre-admin.example.com | 数据与生产隔离 |
| 生产 | `main` | admin.example.com | 手动发布 |

### 12.1 前端

构建产物为纯静态文件，Nginx 托管，`try_files $uri $uri/ /index.html` 支持 history 路由。环境变量通过 `.env.[mode]` 注入，接口地址不硬编码。

### 12.2 后端（webman）

**启动与停止**

```bash
composer create-project workerman/webman:~2.0   # 初始化项目（仅一次）

php start.php start        # 调试模式：前台运行，输出到终端，关闭终端即停止
php start.php start -d     # 生产模式：以 daemon 方式常驻后台
php start.php stop
php start.php restart -d   # 重启：断开所有连接，有瞬时不可用
php start.php reload       # 平滑重载：仅重载业务代码，不断开连接 ← 日常发布用这个
php start.php status       # 查看进程与连接状态
```

**发布流程**：拉取代码 → `composer install --no-dev -o` → 执行数据库迁移 → `php start.php reload`。
只有修改了 `config/`、`process/` 或启动文件时才需要 `restart`，其余情况一律 `reload`，避免请求中断。

**进程与端口**：`config/server.php` 中 `count` 建议设为 CPU 核数 × 2~4，压测后定值；webman 监听 8787，不直接对外，由 Nginx 反向代理。

```nginx
upstream webman {
    server 127.0.0.1:8787 max_fails=2 fail_timeout=10s;
    keepalive 32;
}
server {
    listen 443 ssl;
    server_name admin.example.com;

    location / {                       # 前端静态资源
        root /var/www/admin-web/dist;
        try_files $uri $uri/ /index.html;
    }
    location /api/ {                   # 后端接口
        proxy_pass http://webman;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

**进程守护**：生产用 systemd 或 supervisor 拉起，避免机器重启后服务不起。

```ini
[program:webman-admin]
command=php /var/www/admin-api/start.php start
directory=/var/www/admin-api
autostart=true
autorestart=true
user=www
redirect_stderr=true
stdout_logfile=/var/log/webman-admin.log
```

**运行目录权限**：`runtime/` 需对运行用户可写（日志、PID、缓存）。部署脚本中不要用 root 启动。

**上传文件**：不落本地磁盘（多机部署会不一致），统一走对象存储；`public/` 只放静态资源。

---

## 13. 开发规范

- **分支**：`feature/模块-简述`、`fix/问题简述`，合并到 `develop` 需 Code Review
- **提交信息**：`type(scope): subject`，type 取 `feat|fix|refactor|style|docs|chore`
- **命名**：组件 PascalCase，文件 kebab-case，常量 UPPER_SNAKE，接口方法 `getXxx / createXxx / updateXxx / deleteXxx`
- **组件拆分**：单文件超过 300 行考虑拆分；弹窗独立成组件，不堆在页面里
- **禁止（前端）**：在页面里直接写 `axios`、写死颜色值、写死枚举、绕过权限指令
- **命名（后端）**：控制器 `XxxController`，服务 `XxxService`，模型单数 `User`，表名复数下划线 `sys_users`；接口路由用复数名词 + HTTP 动词语义，不用 `/getUserList` 这类动词路径
- **禁止（后端）**：控制器直接查库、模型里写业务判断、在业务代码里手写数据权限过滤、用 `exit`/`die` 结束请求、把请求态存进静态变量或单例（详见 §14）
- **数据库**：所有变更走迁移脚本，禁止手工改生产库；表必须有 `created_at`/`updated_at`，逻辑删除用 `deleted_at`
- **Code Review 必查**：新增写接口是否有权限点、是否记录操作日志、是否有数据权限约束、是否存在跨请求状态污染

---

## 14. webman 常驻内存注意事项（后端必读）

webman 是**进程常驻内存**的模型，与 PHP-FPM「每个请求初始化一次、结束即销毁」完全不同。同一个进程会连续处理成千上万个请求，任何跨请求残留的状态都会变成线上事故。以下是硬性红线。

### 14.1 禁止跨请求持有状态

```php
// ✗ 错误：静态变量在进程内一直存活，第二个请求会读到上一个用户的数据
class Auth {
    public static ?User $user = null;
}

// ✓ 正确：使用请求级上下文，请求结束自动回收
Context::set('user', $user);
$user = Context::get('user');
```

同理禁止：单例里缓存用户/租户信息、用全局变量传参、在类属性上挂请求数据。

### 14.2 禁止修改全局配置与超全局

- 运行期不要 `config()` 写回或修改配置数组，改动会影响后续所有请求
- 不使用 `$_GET` / `$_POST` / `$_SESSION` / `$_SERVER`，一律从 `$request` 取
- 不使用 `exit` / `die`，会导致整个进程退出；控制器用 `return` 返回响应

### 14.3 内存泄漏

- 无限增长的数组、静态缓存、事件监听器重复注册，都会让进程内存持续上涨
- 大数据量查询用分块处理（`chunk()` / 游标），禁止一次性 `get()` 全表
- `config/server.php` 可设 `max_request`，进程处理一定请求数后自动重启，兜底泄漏

### 14.4 数据库与 Redis 连接

- 连接在进程内复用，MySQL `wait_timeout` 到期会导致「MySQL server has gone away」，需确认连接池/重连配置生效
- 事务必须在同一请求内闭合，禁止跨请求持有事务
- 长事务会占住连接，拖垮整个进程池

### 14.5 代码更新

- 修改业务代码后**必须 `reload`** 才生效（调试模式下 monitor 进程会自动 reload）
- 修改 `config/`、自定义进程、`start.php` 需要 `restart`
- 忘记 reload 导致「改了没生效」是新人最常见的困惑，写进上手手册

### 14.6 第三方 token 的进程安全

微信 `access_token`、支付平台令牌这类**全局唯一且有过期时间**的凭据，必须存 Redis 并加分布式锁刷新。放进程内静态变量会导致每个进程各刷各的，后刷的顶掉先刷的，线上表现为「随机失效」，且极难复现。这条在多端接入后尤其致命，详见 §8.6。

### 14.7 定时任务与队列

- 定时任务用 `workerman/crontab` 注册为自定义进程（`config/process.php`），**不要用系统 crontab 调 PHP 脚本**，那样等于回到 FPM 模型
- 多进程下定时任务只应在一个进程中执行，用 `count => 1` 的独立进程承载，避免重复触发
- 导出、批量通知等耗时操作走队列消费进程，不占用 HTTP 进程

---

## 15. 里程碑

| 阶段 | 前端 | 后端 |
|---|---|---|
| M1 框架搭建 | 登录、布局、菜单、页签、请求封装、权限指令 | webman **多应用骨架（admin/client/open/common）**、分端中间件与异常处理器、统一响应、JWT 鉴权、日志通道 |
| M2 系统管理 | 用户、部门、角色、菜单权限、字典、参数、日志七个页面 | 对应七组接口 + RBAC 落库 + 数据权限全局 Scope |
| M3 页型模板 | 五种页型 + 通用组件（ProTable / SearchForm / DictSelect） | 分页/导出/导入通用能力、队列进程、定时任务进程 |
| M4 联调加固 | 权限矩阵验证、异常场景、性能优化 | 压测与进程数定值、内存泄漏观察、部署脚本与守护配置、client/open 空壳链路联通验证 |

**验收清单**

前端

- [ ] 无权限账号登录后看不到越权菜单，直接访问 URL 返回 403
- [ ] 列表页刷新后筛选条件与页码保持
- [ ] 页签超上限、右键菜单、刷新恢复三项行为符合预期
- [ ] 暗色模式下无不可读文字

后端

- [ ] 前端隐藏按钮后用 curl/Postman 直接调接口，被服务端拒绝
- [ ] 数据权限五种范围各验证一遍，越权数据不可见、不可导出
- [ ] 无字段权限的账号，接口返回的手机号等敏感字段本身即为脱敏值
- [ ] 所有写操作在操作日志中可查，含字段级变更与 traceId
- [ ] 角色授权变更后，目标用户无需重新登录即刻生效（权限版本号机制）
- [ ] 连续压测 30 分钟，进程内存无持续上涨；`reload` 期间无请求失败
- [ ] 断开 MySQL 后恢复，服务能自动重连，无需重启进程
- [ ] 员工 token 调 `/client/*` 接口返回 401，C 端 token 调 `/admin/*` 接口返回 401
- [ ] `/client/ping` 与 `/open/ping` 走的是各自的中间件与异常处理器（返回结构不同即为通过）

---

## 16. 附录：后续可选模块

消息通知中心、定时任务管理、文件管理、数据导入向导、报表中心、多租户、国际化。均在框架层预留扩展点，不影响一期交付。
