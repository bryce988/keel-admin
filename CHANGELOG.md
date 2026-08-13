# 更新日志

本项目遵循[语义化版本](https://semver.org/lang/zh-CN/)，格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)。

`web/` 与 `server/` 共用同一个版本号与 tag。

## [未发布]

### 新增
- 交互原型定稿：16 个页面，含五种页型模板与系统管理全套
- 项目文档 `PROJECT.md`：技术选型、多端架构、RBAC 设计、接口约定、部署规范
- 开源仓库文件：README、CONTRIBUTING、SECURITY、LICENSE、CHANGELOG
- 数据库设计 `docs/database.md`：13 张系统表 DDL、索引约定、初始化数据
- 接口契约 `docs/api.md`：通用响应约定、错误码表、系统管理各模块接口清单

### 已完成（开发中）
- Docker 一键开发环境：mysql 8 + redis 7 + webman + vite，宿主机只需 Docker
- 后端骨架：webman 多应用目录、TraceMiddleware（traceId 与上下文清理）、
  统一异常处理器（HTTP 状态码 + 业务码两层）、Db / Cache / Ctx / Result 基础设施
- 登录闭环：SVG 图形验证码（不依赖 GD）、JWT 签发与校验、登录失败锁定、
  登录日志、权限版本号机制、鉴权中间件、用户与菜单下发
- 前端骨架：Vue 3 + Vite + Element Plus + Pinia，登录页、布局、路由守卫、
  请求封装（按 HTTP 状态码分派 + 业务码细化）

- 生产编排 `docker-compose.prod.yml`：前端静态化 + nginx 托管，
  MySQL/Redis 不暴露宿主机端口，构建源可配置（APK_MIRROR / COMPOSER_MIRROR）
- 部署上线：http://43.143.249.52:8080

### 计划中
- M1 框架搭建：webman 多应用骨架、JWT 鉴权、四个中间件、前端布局与多页签
- M2 系统管理：用户、部门、角色、菜单与权限、数据字典、参数配置、操作日志
- M3 页型模板与通用组件：ProTable、SearchForm、DictSelect、PermButton
- M4 压测加固：进程数定值、内存观察、部署脚本与守护配置

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
