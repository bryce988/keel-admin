<div align="center">

# Keel · 龙骨

**多端后台系统的底座** — 开箱即用的后台脚手架，不含业务，接上就能盖楼

[![Stars](https://img.shields.io/github/stars/bryce988/keel-admin?style=flat&logo=github)](https://github.com/bryce988/keel-admin)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-777bb3.svg)](https://www.php.net/)
[![webman](https://img.shields.io/badge/webman-2.x-42b983.svg)](https://www.workerman.net/webman)
[![Vue](https://img.shields.io/badge/Vue-3.x-42b883.svg)](https://vuejs.org/)

[在线预览](http://43.143.249.52:8080) · [项目文档](PROJECT.md) · [数据库设计](docs/database.md) · [接口契约](docs/api.md) · [更新日志](CHANGELOG.md)

[**GitHub 主仓库**](https://github.com/bryce988/keel-admin)（Issue / PR 请到这里） · [**Gitee 镜像**](https://gitee.com/yewang_top/keel-admin)（国内克隆更快）

> **在线预览**：http://43.143.249.52:8080 演示账号 `admin` / `4IWvhcE9gKLL`
>
> 想看权限体系的效果，再用 `manager` / `demo123456`（部门主管）登录一次：
> 菜单少了几项，用户列表只剩本部门的人——同一个接口，返回的数据自己变少了。

</div>

---

## 这是什么

龙骨是船体最底层的主梁，整艘船的结构都搭在它上面。**Keel 之于后台系统，就是这根梁**——它不决定你造什么，只保证结构立得住。

登录鉴权、菜单导航、多页签工作区、RBAC 权限体系、系统管理、五种页型模板全部就位，接业务时只写业务。

## 为什么是它

- **常驻内存的 PHP 后端** — 基于 webman（Workerman），相比 PHP-FPM 有数倍性能提升，天然适合长连接与定时任务
- **一开始就是多端架构** — `admin` / `client` / `open` / `internal` 四个入口按 webman 多应用切好，二期接 App、小程序只加目录不改架构
- **权限体系是完整的，不是演示** — 功能权限、数据权限、字段级权限三个维度正交；角色继承（RBAC1）与职责分离（RBAC2）都落到实现
- **页型模板可复制** — 列表、表单、详情、树表、结果页五种模板，新模块从模板复制，视觉与交互天然一致
- **规范写进代码** — 权限标识、状态色、字典枚举、日志策略全部框架层解决，业务代码不重复实现

## 技术栈

| | |
|---|---|
| 前端 | Vue 3 + TypeScript + Vite 5 + Element Plus + Pinia |
| 后端 | PHP 8.1+ + webman 2.x（多应用）+ Eloquent + Redis |
| 数据库 | MySQL 8.0+ |

## 快速开始

**只需要 Docker**，不必在本机安装 PHP / Node / MySQL / Redis。

```bash
git clone https://github.com/bryce988/keel-admin.git   # 国内可用 Gitee 镜像
cd keel-admin

cp .env.example .env      # 按需修改端口、密码、JWT 密钥
docker compose up -d      # 首次启动会拉取 webman 骨架并装依赖，约 2-3 分钟
```

启动完成后：

| 地址 | 说明 |
|---|---|
| http://localhost:5173 | 管理后台（默认账号 `admin` / `admin123`） |
| http://localhost:8787/admin/ping | 后端存活探测 |

查看启动进度与日志：

```bash
docker compose logs -f server     # 后端
docker compose logs -f web        # 前端
docker compose ps                 # 服务状态
```

**常用命令**

```bash
docker compose restart server                        # 重启后端
docker compose exec server php start.php reload      # 平滑重载业务代码（改 PHP 后）
docker compose exec server php scripts/install.php   # 重新初始化管理员（幂等）
docker compose exec mysql mysql -ukeel -pkeel123456 keel   # 进数据库
docker compose down -v                               # 停止并清空数据，从头再来
```

> 改 PHP 代码后调试模式会自动 reload；改了 `config/` 或自定义进程需要 `docker compose restart server`。

**不用 Docker 本地跑**（需自备 PHP 8.1+ / Node 18+ / MySQL 8 / Redis）

```bash
cd server && composer install && php start.php start
cd web && npm install && VITE_PROXY_TARGET=http://127.0.0.1:8787 npm run dev
```

**部署到服务器**

```bash
# 首次部署
git clone https://gitee.com/yewang_top/keel-admin.git /opt/keel
cd /opt/keel
cp .env.example .env && vi .env      # 数据库密码、JWT 密钥务必用随机值
docker compose -f docker-compose.prod.yml up -d --build

# 后续更新：拉代码 + 重建 + 健康检查，一条命令
./scripts/deploy.sh
```

生产编排与开发编排的区别：前端构建成静态文件由 nginx 托管（不跑 vite）、
MySQL 与 Redis 不对宿主机暴露端口、只开放一个 HTTP 端口（默认 8080）。
网络受限时可在 `.env` 中设置 `APK_MIRROR` 与 `COMPOSER_MIRROR` 加速构建。

## 功能一览

| 模块 | 内容 |
|---|---|
| 概览 | 指标卡、趋势图、系统状态、待办聚合 |
| 页型模板 | 标准列表页、表单页、详情页、树表页、结果与异常页 |
| 用户管理 | 部门树筛选、角色分配、账号健康度指标 |
| 部门管理 | 组织树、岗位、默认角色 |
| 角色管理 | 功能权限树、数据范围、字段级权限、成员、继承与互斥约束 |
| 菜单与权限 | 菜单树、权限点定义、角色×权限审计矩阵 |
| 数据字典 | 字典类型与字典项，驱动全站枚举与状态色 |
| 参数配置 | 基础设置、安全策略、集成配置、高级参数 |
| 操作日志 | 操作/登录/接口日志，字段级变更留痕 |
| 个人中心 | 资料、安全设置、通知偏好、登录记录 |

## 权限模型

```
用户 ──多对多── 角色 ──多对多── 权限点 ──→ 资源（菜单/按钮/接口/数据）
                 └──→ 数据范围（全部/本部门及下属/本部门/仅本人/自定义）
```

三层职责严格分离，一层只做一件事：

**定义**（菜单与权限）→ **授权**（角色管理）→ **分配**（用户管理）

用户身上不存在独立授权；前端隐藏不等于权限控制，所有写接口与敏感读接口在服务端独立校验。

## 目录结构

```
keel-admin/
├── web/        前端
├── server/     后端（app/admin · app/client · app/open · app/common）
├── docs/       文档
├── docker/     一键启动
└── PROJECT.md  完整项目文档
```

## 路线图

- [x] 交互原型定稿
- [x] 项目文档、数据库设计、接口契约
- [x] Docker 一键启动环境
- [x] 登录闭环：验证码 → JWT 签发 → 鉴权中间件 → 用户/权限/菜单下发
- [ ] M1 其余：多页签工作区、权限指令、菜单动态路由
- [ ] M2 系统管理：用户/部门/角色/权限/字典/参数/日志
- [ ] M3 页型模板与通用组件
- [ ] M4 压测加固与部署脚本
- [ ] 二期：App / 小程序端接入，开放平台

## 参与贡献

**GitHub 是唯一的开发仓库**，Gitee 为镜像（由维护者手动同步）——在 Gitee 提交的 PR 会在下次同步时被覆盖，请到 GitHub 提交。若你无法访问 GitHub，可在 Gitee 提 Issue 说明，我们协助搬运补丁。

提交前请阅读 [CONTRIBUTING.md](CONTRIBUTING.md)，并注意：

- 提交信息遵循 `type(scope): subject`
- 新增写接口必须带权限点、操作日志与数据权限约束
- 后端改动请先读项目文档中的「webman 常驻内存注意事项」

## 开源协议

[MIT](LICENSE) — 可商用，可闭源二次开发。
