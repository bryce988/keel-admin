# 更新日志

本项目遵循[语义化版本](https://semver.org/lang/zh-CN/)，格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)。

`web/` 与 `server/` 共用同一个版本号与 tag。

## [未发布]

### 计划中
- 进程数定值、前端拆包（主 chunk 1.27MB / gzip 412KB）—— 两件都需要真实业务的流量画像与页面数量才做得准

## [1.0.0] - 2026-08-24

首个公开版本，v1.0 规划的全部内容已完成：权限与数据权限、操作日志、四端隔离、
页型模板与通用组件、队列与定时任务，均已实测通过并部署上线。
侧边栏里没有「点进去是占位页」的条目。

### 新增

**项目基建（M1 之前）**
- 交互原型定稿：16 个页面，含五种页型模板与系统管理全套
- 项目文档 `PROJECT.md`：技术选型、多端架构、RBAC 设计、接口约定、部署规范
- 数据库设计 `docs/database.md`、接口契约 `docs/api.md`（通用响应约定、错误码表、各模块接口清单）
- 开源仓库文件：README、CONTRIBUTING、SECURITY、LICENSE、CHANGELOG
- Docker 一键开发环境：mysql 8 + redis 7 + webman + vite，宿主机只需 Docker
- 后端基础设施：webman 多应用目录、`TraceMiddleware`（traceId 与上下文清理）、
  统一异常处理器（HTTP 状态码 + 业务码两层）、`Db` / `Cache` / `Ctx` / `Result`
- 登录闭环：SVG 图形验证码（不依赖 GD）、JWT 签发与校验、登录失败锁定、登录日志、
  权限版本号机制、鉴权中间件、用户与菜单下发
- 前端骨架：登录页、布局、路由守卫、请求封装（先按 HTTP 状态码分派，再按业务码细化）
- 生产编排 `docker-compose.prod.yml`：前端静态化 + nginx 托管，MySQL / Redis 不暴露宿主机端口，
  构建源可配置（`APK_MIRROR` / `COMPOSER_MIRROR`）；部署脚本支持从 Git 拉取更新
- 部署上线：http://43.143.249.52:8080

**M1 框架骨架**
- 后端 14 张表 + Eloquent 模型层（`Sys*Model`），表结构由 `scripts/migrate.php` 在进程启动时幂等对齐，
  权限点 / 字典 / 参数由 `scripts/seed.php` 按唯一键 upsert
- 数据权限：`HasDataScope` trait 以模型全局 Scope 注入五种范围，业务代码不手写归属过滤，
  必须绕过时用 `Model::withoutDataScope()` 让绕过点在 review 中可见
- 权限中间件 fail-closed：接口的权限点在 `config/route.php` 上声明，不声明即 403；
  声明 `log` 的路由自动进操作日志（含越权尝试），service 内用 `OpLog::target()` / `OpLog::diff()` 补对象与字段变更
- 四端隔离：`app/{admin,client,open,internal}` 各有中间件与异常处理器，错误体结构刻意不同；
  员工 token 与 C 端 token 互调一律 401
- 日志三通道：`app`（业务）/ `error`（未捕获异常，留 30 天）/ `sql`（慢查询，阈值 `SLOW_QUERY_MS`）
- 前端：`v-permission` 指令、菜单驱动的动态路由、`ProTable` / `SearchForm` / `DictSelect` / `DictTag`、字典 store

**M2 系统管理**
- 部门与岗位、菜单与权限、角色、用户（含 openspout 导入导出）、数据字典与参数配置、操作日志与登录日志
- 写接口通用件：`Guard` / `BatchResult`；表单统一走抽屉，`ProTable` 支持树形
- 列表页筛选条件与页码同步到 URL，页签记住完整地址
- 登录日志新增登录地址（ip2region，`vectorIndex` 用 `file` 策略）

**M3 页型模板与个人中心**
- 个人中心 `/profile`：`ProfileService` 的 id 只从令牌取，结构上没有「改别人」的路径，因此不需要权限点；
  支持换头像。换绑手机用当前密码验证而非短信（脚手架不绑死短信服务商）
- `views/template/` 五种页型模板 + `_demo.ts` 内存假数据 + README，注册为开发环境专属路由
- `<EmptyState>`（四场景，必带动作）与 `<PageSkeleton>`（list / detail / form），
  收敛全仓 10 处各写各的 `el-empty`；`ProTable` 区分「没数据」与「筛不到」
- 队列消费进程（`webman/redis-queue`，count=2）+ 定时任务进程（`TaskProcess`，count=1，只投递不干活）+
  `LogCleanupService` 按 `sys.log.retainDays` 分批删

**M4 联调加固**
- `scripts/acceptance.sh`：权限矩阵与异常场景 43 项断言，含 client / open 空壳链路
  （渠道头强制、各端错误体结构不同、员工 token 调 C 端 401）
- MySQL 断开重连实测通过，无需改代码，结论写进 `Db.php` 注释
- 30 分钟压测：284,920 请求 / 1 次失败，worker 预热到 26MB 后进入平台期；
  同时量出 reload=0 秒、restart≈2 秒的停机窗口

**其他**
- 顶栏菜单命令面板、图标选择器网格面板、页签取消数量上限并把操作收进统一菜单
- 主题切换改用 View Transitions
- 品牌标记：侧栏与登录页不再是纯文字

### 变更
- **BREAKING** 模型类名统一 `Sys*Model` 后缀；接口字段一律 `snake_case`（请求与响应），
  与数据库字段名逐字一致，全链路不做键名转换。
  迁移：调用方需同步改键名，例外只有 HTTP 头与前端自己的标识符
- 校验器换成 `webman/validation`，规则收敛到 `FormRequest`
- 业务码与 HTTP 状态码常量化，前后端各一份并加一致性校验
- 表单壳拆成 `FormDrawer` 与 `FormDialog`，公共逻辑收进 `useFormShell`
- 能用模型的一律走模型，不再手写 `Db::table`；表的固定字段常量化，开关型 `status` 抽成 `HasStatus`
- 系统概览改成一级菜单并按现有模块重构；seed 补上退役菜单清单
- 全局组件尺寸、表格密度、版式令牌多轮对齐；换掉 Element Plus 默认的五个品牌色
- 升级到 PHP 8.4（随 openspout 引入）
- `tsconfig.json` 去掉已弃用的 `baseUrl`，`paths` 直接按 tsconfig 目录解析
- GitHub 与 Gitee 同为主仓库，移除模板与镜像同步工作流

### 移除
- 角色 × 权限矩阵页（权限在角色编辑内完成，矩阵页是重复入口）
- 无引用的 `ModulePlaceholder`

### 修复
- nginx：`/internal/*` 返回 404 而不是落到前端兜底；请求体上限提到 24m，否则合法头像被 413 挡下
- Docker：`composer.lock` 变化时重新安装依赖（原先只判断 vendor 是否存在会静默跳过）
- 前端：切换菜单后内容区不刷新、新增页面路由注册不上、根路径重定向不生效、
  角色成员抽屉打不开、侧边栏二级菜单没画图标、深色模式首屏闪白、logo 区与顶栏底边没接上
- `v-permission` 拒绝挂到 Fragment 根组件，下拉项的权限收敛改用 `v-if`

### 安全
- 客户端 IP 可伪造，波及白名单 / 限流 / 审计
- 会话吊销与登录锁定两处缺陷
- 登录日志缺 `dept_id`，导致数据权限在非「仅本人」范围下找不到部门列而直接放行，
  部门主管能看到全公司记录
- 数据权限补上写入侧：可写部门集合 = 可读部门集合
- 脱敏缺陷：`Arr::mask('')` 把空手机号打成 `*`；操作日志的 `params` 存明文手机号绕过了字段级权限，
  已加部分脱敏档 `PARTIAL`

---

## 版本记录格式说明

每个版本按以下分类记录，无内容的分类可省略：

- **新增** — 新功能
- **变更** — 已有功能的调整
- **废弃** — 即将移除的功能（需给出替代方案与移除版本）
- **移除** — 已删除的功能
- **修复** — Bug 修复
- **安全** — 安全相关修复（关联安全公告编号）

破坏性变更需在条目前标注 `**BREAKING**` 并给出迁移步骤，例如：

```markdown
### 变更
- **BREAKING** 权限标识由 `sys:user:add` 改为 `sys:user:create`
  迁移：执行 `php start.php migrate:perm-rename`，或手工更新 `sys_permissions` 表中的标识
```

## 示例

<!--
## [1.1.0] - 2026-09-01

### 新增
- 角色管理支持字段级权限配置（#42）
- 列表页列设置支持按用户持久化（#38）

### 修复
- 修复角色回收后旧 token 仍可访问的问题（#51）
- 修复多进程下微信 access_token 互相顶掉的问题（#55）

### 安全
- 修复导出接口未走数据权限过滤导致的越权（GHSA-xxxx）
-->
