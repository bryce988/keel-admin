<div align="center">

# Keel

多端后台脚手架，不含业务逻辑

[![Stars](https://img.shields.io/github/stars/bryce988/keel-admin?style=flat&logo=github)](https://github.com/bryce988/keel-admin)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.4-777bb3.svg)](https://www.php.net/)
[![webman](https://img.shields.io/badge/webman-2.x-42b983.svg)](https://www.workerman.net/webman)
[![Vue](https://img.shields.io/badge/Vue-3.x-42b883.svg)](https://vuejs.org/)

[在线预览](http://43.143.249.52:8080) · [项目文档](PROJECT.md) · [数据库设计](docs/database.md) · [接口契约](docs/api.md) · [更新日志](CHANGELOG.md)

[GitHub](https://github.com/bryce988/keel-admin) · [Gitee](https://gitee.com/yewang_top/keel-admin)　两边都是主仓库，内容一致，就近选一个

</div>

---

## 这是什么

一套不含业务逻辑的后台管理脚手架。clone 下来 `docker compose up`，得到的是一个能登录、
能管权限、能查日志的后台，剩下的工作是往里加业务模块。

已经就位的：登录鉴权、菜单导航、多页签工作区、RBAC 权限体系（功能 / 数据 / 字段三个维度）、
系统管理八个模块（用户、部门、岗位、角色、菜单权限、字典、参数、日志）、五种页型模板、
操作日志、队列与定时任务进程。

不做的是任何行业逻辑。它不是 CRM 也不是 ERP，那些是在它之上写的东西，演示数据可以一键清空。

### 在线预览

http://43.143.249.52:8080 ，账号 `admin` / `admin123`。

想看权限体系的效果，再用 `manager` / `demo123456`（部门主管）登录一次：菜单少了几项，
用户列表只剩本部门的人。同一个接口，返回的数据自己变少了——过滤是数据库层注入的，
不是前端藏起来的。

### 几个设计取向

后端跑在 webman（Workerman）上，常驻内存，比 PHP-FPM 快数倍，定时任务和长连接不用另外找地方放。

一开始就是多端结构。`admin` / `client` / `open` / `internal` 四个入口按 webman 多应用切好，
各有各的中间件与异常处理器，二期接 App、小程序是加目录而不是改架构。

权限做到了完整而不是演示：功能权限、数据权限、字段级权限三个维度正交，
角色继承（RBAC1）与职责分离（RBAC2）都落到了实现。前端的 `v-permission` 只负责收敛界面，
真正的拦截在后端路由的权限点声明上，没声明就是 403。

页型模板有五种（标准列表、树表联动、主从、表单、详情），新模块从模板复制，
视觉和交互不会各写各的。权限标识、状态色、字典枚举、日志策略都在框架层解决，
业务代码里不用重复实现一遍。

## 技术栈

| | |
|---|---|
| 前端 | Vue 3 + TypeScript + Vite 5 + Element Plus + Pinia |
| 后端 | PHP 8.4+ + webman 2.x（多应用）+ Eloquent + Redis |
| 数据库 | MySQL 8.0+ |

## 快速开始

只需要 Docker，不必在本机装 PHP / Node / MySQL / Redis。

```bash
git clone https://github.com/bryce988/keel-admin.git   # 国内建议换 Gitee 地址，快很多
cd keel-admin

cp .env.example .env      # 按需改端口、密码、JWT 密钥
docker compose up -d      # 首次会拉 webman 骨架并装依赖，约 2-3 分钟
```

起来之后：

| 地址 | 说明 |
|---|---|
| http://localhost:5173 | 管理后台，账号 `admin` / `admin123` |
| http://localhost:8787/admin/ping | 后端存活探测 |

看启动进度和日志：

```bash
docker compose logs -f server     # 后端
docker compose logs -f web        # 前端
docker compose ps                 # 服务状态
```

常用命令：

```bash
docker compose restart server                        # 重启后端
docker compose exec server php start.php reload      # 改完 PHP 平滑重载
docker compose exec server php scripts/install.php   # 重新初始化管理员，幂等
docker compose exec mysql mysql -ukeel -pkeel123456 keel   # 进数据库
docker compose down -v                               # 停止并清空数据，从头再来
```

改 PHP 代码调试模式会自动 reload；改了 `config/` 或自定义进程要 `docker compose restart server`。

### 不用 Docker

需自备 PHP 8.4+（含 ext-zip）、Node 18+、MySQL 8、Redis。

```bash
cd server && composer install && php start.php start
cd web && npm install && VITE_PROXY_TARGET=http://127.0.0.1:8787 npm run dev
```

### 部署到服务器

```bash
# 首次
git clone https://gitee.com/yewang_top/keel-admin.git /opt/keel
cd /opt/keel
cp .env.example .env && vi .env      # 数据库密码、JWT 密钥务必换成随机值
docker compose -f docker-compose.prod.yml up -d --build

# 后续更新：拉代码、重建、健康检查一条命令搞定
./scripts/deploy.sh
```

生产编排跟开发编排的区别：前端构建成静态文件交给 nginx，不跑 vite；MySQL 与 Redis
不对宿主机暴露端口；只开一个 HTTP 端口，默认 8080。网络受限时可以在 `.env` 里设
`APK_MIRROR` 和 `COMPOSER_MIRROR` 加速构建。

## 功能一览

下面是 v1.0 规划的全部内容，也是当前代码的真实进度——**全部已完成**，
没有「菜单在侧边栏、点进去是占位页」的条目。

| 模块 | 内容 |
|---|---|
| 登录与鉴权 | 图形验证码、JWT、失败锁定、权限与菜单下发 |
| 工作区 | 可折叠二级菜单、多页签、面包屑、深浅色主题 |
| 权限体系 | 数据权限五种范围、字段级脱敏、权限点拦截、操作日志 |
| 概览 | 模块规模、登录趋势、最近操作、运行状态，全部真实数据 |
| 用户管理 | 部门树筛选、角色分配、导入导出、停用交接 |
| 数据字典 | 字典类型与字典项，驱动全站枚举与状态色 |
| 部门管理 | 组织树、岗位、默认角色 |
| 角色管理 | 功能权限树、数据范围、字段级权限、成员、继承与互斥约束 |
| 菜单与权限 | 菜单树、权限点定义（五种类型） |
| 参数配置 | 基础设置、安全策略、集成配置、高级参数，密钥只写不读 |
| 操作日志 | 操作 / 登录日志，字段级变更留痕，越权尝试同样入库 |
| 个人中心 | 资料、安全设置（改密 / 换绑手机）、我的登录记录 |
| 页型模板 | 五种页型，只在开发环境注册，不进生产包 |

## 权限模型

```
用户 ──多对多── 角色 ──多对多── 权限点 ──→ 资源（菜单/按钮/接口/数据）
                 └──→ 数据范围（全部/本部门及下属/本部门/仅本人/自定义）
```

三层职责分开，一层只做一件事：定义（菜单与权限）→ 授权（角色管理）→ 分配（用户管理）。

用户身上没有独立授权。所有写接口与敏感读接口都在服务端独立校验，前端隐藏不算权限控制。

## 目录结构

```
keel-admin/
├── web/        前端
├── server/     后端（app/admin · app/client · app/open · app/common）
├── docs/       文档
├── docker/     一键启动
└── PROJECT.md  完整项目文档
```

## 进度

- [x] 交互原型、项目文档、数据库设计、接口契约
- [x] Docker 一键启动环境
- [x] 登录闭环：验证码 → JWT 签发 → 鉴权中间件 → 用户/权限/菜单下发
- [x] M1 框架搭建
  - [x] 多页签工作区（上限、右键菜单、刷新恢复）
  - [x] `v-permission` 权限指令、菜单驱动的动态路由
  - [x] `ProTable` / `SearchForm` / `DictSelect` / `DictTag` 通用组件
  - [x] Eloquent 模型层 + 数据权限全局 Scope（五种范围）
  - [x] 权限中间件（没声明权限点就拒绝）+ 操作日志中间件（越权尝试同样留痕）
  - [x] 四端隔离：`app/{admin,client,open,internal}` 各有中间件与异常处理器
  - [x] 日志三通道：业务 / 未捕获异常 / 慢查询
- [x] M2 系统管理：用户 / 部门 / 岗位 / 角色 / 菜单权限 / 字典 / 参数 / 日志
  - [x] 列表状态同步 URL、表单抽屉规范、写接口通用件（唯一性 / 引用 / 成环 / 内置数据断言）
  - [x] 七个模块的完整增删改查，写接口一律带权限点与操作日志
  - [x] 导入导出走 openspout 流式读写（两万行内存峰值 4MB）
  - [x] 权限矩阵收尾：内置角色授权补齐，`manager` / `dev01` 双账号全量走查
- [x] M3 页型模板、个人中心与通用组件
  - [x] 五种页型模板，开发环境可直接打开预览
  - [x] 个人中心：资料、安全设置（改密 / 换绑手机）、我的登录记录
  - [x] `EmptyState` 空状态（四场景 + 必带动作）与 `PageSkeleton` 首屏骨架屏
  - [x] 队列消费进程 + 定时任务进程（日志按保留天数自动清理）
- [x] M4 联调加固：13 条验收项逐条实测
  - [x] 权限矩阵、数据权限五种范围、字段级脱敏、多端隔离 —— `sh scripts/acceptance.sh`，43 项断言（仅开发环境，脚本会临时改角色与部门再还原）
  - [x] 连续压测 30 分钟：284,920 次请求 / 1 次失败，内存预热后进入平台期
  - [x] MySQL 断开重连（含在死连接上开事务）无需重启进程
  - [x] 部署脚本与守护配置，`reload` 0 秒、`restart` 约 2 秒的停机窗口已实测
- [ ] 二期：App / 小程序端接入，开放平台

M2 各阶段的执行拆分与实测记录见 [docs/roadmap-m2.md](docs/roadmap-m2.md)。

## 参与贡献

GitHub 与 Gitee 都是主仓库，维护者一条 `git push` 同时推两边，内容始终一致，
在哪边方便就用哪边，提 Issue、提 PR 都行。

没有 Issue / PR 模板，把话说清楚就够了：这是什么问题、怎么复现、你期望的行为。
提 PR 时说明改了什么、为什么这么改、怎么验证的。

提交前请读一遍 [CONTRIBUTING.md](CONTRIBUTING.md)，另外注意：

- 提交信息用 `type(scope): subject`
- 新增写接口必须带权限点、操作日志与数据权限约束
- 后端改动前先看项目文档里的「webman 常驻内存注意事项」

## 联系方式

邮箱 1306811834@qq.com，使用问题、功能建议、二次开发咨询都可以发。不过更建议走 Issue：

- Bug 与功能建议：[GitHub Issue](https://github.com/bryce988/keel-admin/issues)，公开讨论对后来遇到同样问题的人有用
- 安全漏洞：不要发 Issue，按 [SECURITY.md](SECURITY.md) 的私密渠道报告；发邮件也行，但请在标题注明「安全」
- 其他：邮件

## 开源协议

[MIT](LICENSE)，可商用，可闭源二次开发。
