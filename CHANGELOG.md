# 更新日志

本项目遵循[语义化版本](https://semver.org/lang/zh-CN/)，格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)。

`web/` 与 `server/` 共用同一个版本号与 tag。

## [未发布]

### 新增
- **CI**：`.github/workflows/ci.yml` 三个 job —— 前端类型检查与构建、后端 `php -l` 全量与
  `composer validate`、以及起 docker compose 跑 `scripts/acceptance.sh` 的验收。
  与 CONTRIBUTING 里「提交前跑一遍检查」是同一组命令，本地过了 CI 就会过
- **`composer lint` / `composer check`**：`server/scripts/lint.php` 对 vendor 之外的全部 PHP 跑语法检查。
  CONTRIBUTING 里写了很久的 `composer lint`、`composer test` 此前并不存在，跑起来直接报错
- `docker compose exec web npm run check`：一条命令跑完 `vue-tsc` + `vite build`
  （只跑 type-check 验不到模板结构，两步必须都跑）

### 变更
- **生产改为不可变镜像**（`docker/php/Dockerfile.prod`）：后端代码与 composer 依赖在**构建期**
  烤进镜像，容器以只读根文件系统运行。此前生产把整个 `server/` 挂载进容器、并在每次启动时
  执行 composer 安装，等于「线上跑的是 git 工作副本的当前状态」——不是任何一个可复现的产物，
  也无法回滚到上一个镜像
- **建表与播种改为一次性 job**：`docker-compose.prod.yml` 新增 `migrate` 服务
  （migrate → install → seed），`server` 声明 `service_completed_successfully` 依赖它。
  初始化失败会直接拦住发布，而不是像原来那样被 `|| echo` 吞掉、留下一个权限树残缺的线上环境
  （表现是登录进去到处 403）
- **生产不再启用文件监听**：`config/process.php` 的 `enable_file_monitor` 增加 `APP_ENV` 判断。
  上游只按 `-d`（守护进程）判断，而容器里不能用 `-d`，于是生产一直开着文件监听——
  每两秒扫一遍 `app/`，且**任何文件改动都会自动 reload 线上进程**
- **前端 Element Plus 改为按需导入**（`unplugin-vue-components` + `unplugin-auto-import`）：
  主 JS 从 1275 KB / gzip 414 KB 降到 816 KB / gzip 269 KB（-36%），
  主 CSS 从 367 KB / gzip 50 KB 降到 164 KB / gzip 23 KB（-54%）
- **`ProTable` 与表单壳改为泛型**：`ProTable<T>`、`useFormShell<T>`、`FormDrawer<T>` / `FormDialog<T>`，
  行类型与表单类型从 `api/*.ts` 一路推到模板。全仓 28 处 `Record<string, any>` 清零。
  此前表格列、表单字段与接口字段对不上要等用户点开那一页才发现
- 写接口的请求体类型收紧：`api/system.ts` 的 16 个 create/update 由 `Record<string, unknown>`
  改为 `UserPayload` / `DeptPayload` 等（`Partial<行类型>`），字段名拼错在编译期就报错
- `Cache` 不再在每次访问前 `PING`，改为失败后重连并重试一次。原写法给每一次缓存读写
  固定加一个往返，而断连是罕见事件；Redis 若部署在网络对端（托管实例），这笔开销会显著放大
- 前端生产镜像改用 `npm ci` 并拷入 `package-lock.json`：此前只拷 `package.json` 跑 `npm install`，
  每次构建都重新解析版本范围，线上装到的依赖树可能与锁文件不是同一套
- 菜单的 `visible` / `keep_alive` 前后端统一用布尔：接口返回的本就是布尔，
  而表单打开时转成 1/0、提交时又送回数字，靠 PHP 的隐式转换兜住
- **全站字体改为系统 UI 字体栈**（`-apple-system` / `Segoe UI` / `Roboto` …，
  中文依次回退 苹方 / 冬青黑 / 微软雅黑 / 思源黑）：各平台显示自己的原生界面字体，
  不再统一落到 Helvetica Neue。覆盖的是 `--el-font-family` 令牌而非只给 `body` 设
  `font-family`，Element Plus 组件内部（表格、下拉、消息框）也一起跟着变

### 性能
- **概览页 SQL 从 23 条降到 14 条**：`stats()` 与 `moduleSummary()` 此前各查一遍用户、部门、
  岗位、角色；「总数」与「其中多少条满足某条件」也拆成了两条 `count()`。
  后者不只是多一次往返——两次查询之间有人登录，就会出现「今日登录 7 次、失败 8 次」这种对不上的数
- **字典列表的 N+1 收敛**：字典类型列表 14 → 5 条 SQL；字典项列表的引用数改为整页批量聚合，
  查询条数与页大小无关（`page_size=100` 时从约 400 条降到 8 条）
- **日志表补索引**：`sys_operation_logs` 增加 `(dept_id, created_at)`；
  `sys_login_logs` 增加 `(created_at)` 与 `(dept_id, created_at)`，并删掉被前缀覆盖的单列 `idx_dept`。
  登录日志此前按时间范围查询时 `possible_keys` 为 `NULL`（一个候选索引都没有）

### 修复
- **不建 `.env` 直接 `docker compose up -d` 时登录会 500**：`JwtService` 的密钥长度
  下限写死为 16 字节，那是 `firebase/php-jwt` ^6 时代的判断；^7 自己会校验 HMAC
  密钥长度，HS256 要求至少 32 字节。开发编排的默认值 `change-me-in-production`
  只有 23 字节，正好落在两道检查中间，于是穿过我们的校验、栽在库里，
  抛出指向第三方库行号的 `Provided key is too short @ JWT.php:701`。
  现在下限按算法推导，报错也改成能照着做的话；开发默认值加长到 44 字节，
  README 说的「clone 完 up -d 就能跑」这才成立
- **验收脚本的期望值不再写死**：`scripts/acceptance.sh` 第 4、5 节把「总计 5 人」
  「营销部(7)」「角色 4 是审计员」当成既定事实，而那是作者本机累积出来的状态——
  种子数据里只有 4 个用户、3 个部门、3 个内置角色。本机能过是因为库里恰好有那些数据，
  CI 每次都是空库，必挂。现在人数与部门 id 一律从库里现算，
  「自定义部门范围」所需的角色就地创建、跑完删除；`down -v` 后连跑两遍均 43/43
- **`scripts/migrate.php` 现在能补索引**：此前只能补列，往 `schema.sql` 的 `CREATE TABLE` 里
  新加的 `KEY` 对存量库完全无效——开发机 `down -v` 重建后一切正常，线上一个索引都没加。
  索引缺失不报错、不返回错数据，只是慢，等发现时已经是线上慢查询
- **新增 `.dockerignore`**：此前构建上下文没有过滤，`COPY server/ ./` 会把宿主机的 `vendor/`
  覆盖到构建期装好的那份上（可复现性归零），并把运行期日志、历史导出文件、陈旧的 `webman.pid`
  一起打进镜像；宿主机存在 `server/.env` 时还会把生产密钥烤进镜像层
- **生产的用户上传目录改为挂卷**：`public/uploads` 落到 `server-uploads` 卷。
  改成不可变镜像后若不挂卷，每次发版重建镜像会把用户头像全部清掉
- `ProTable` 的 `sort-change` 签名由 `prop: string` 改为 `prop: string | null`：
  el-table 在「升序 → 降序 → 取消」的第三下会把它清空
- 字典项的 `tag_type` 由 `string` 收紧为 Element Plus 的 tag type 联合，
  后端存进拼错的值时不再只是静默显示成默认灰

### 文档
- `CONTRIBUTING.md` 的检查命令改成实际能跑的：此前四条命令有三条不存在
  （`composer lint`、`composer test` 未定义，`pnpm lint` 既无脚本、包管理器也早已换成 npm）；
  本地开发一节的 PHP 版本下限由 8.1 更正为 8.4，pnpm 改为 npm
- `PROJECT.md` 的「没有 CI」一节改为 CI 说明，并明确列出 CI 里**仍然没有**的东西
  （单元测试、ESLint / Prettier、依赖漏洞扫描）与暂不引入的理由

### 计划中
- 进程数定值 —— 需要真实业务的流量画像才做得准
- 单元测试、ESLint / Prettier、依赖漏洞扫描
- 图标包（293 个，144 KB / gzip 38 KB）目前仍在主 chunk：布局外壳静态引用了其中约 25 个，
  只要有一处静态 import，整包就会被钉在主 chunk 上，动态 import 会被 Rollup 并回去。
  真要拆出去得把那 25 个图标抄成本地组件，与 EP 版本漂移的代价换 gzip 38 KB，暂不做

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
- `scripts/acceptance.sh` 的仓库根写死成了作者本机的绝对路径，别人 clone 下来跑不了；
  改为从脚本自身位置推导，数据库账号密码也支持用 `DB_USERNAME` / `DB_PASSWORD` / `DB_DATABASE` 覆盖

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
