# 贡献指南

感谢参与 Keel。这份文档说明如何提交问题、如何本地开发、以及代码合并前必须满足的条件。

先读一遍 [PROJECT.md](PROJECT.md)——尤其是**权限设计（§6）**、**多端入口划分（§8）**和 **webman 常驻内存注意事项（§14）**。这三节里的约定是硬性的，PR 会按它们审查。

---

## 提 Issue

| 类型 | 请提供 |
|---|---|
| Bug | 复现步骤、期望结果、实际结果、版本号、`traceId`（如接口报错） |
| 功能建议 | 你的实际场景，而不是「某某框架有这个功能」 |
| 安全问题 | **不要开 Issue**，见 [SECURITY.md](SECURITY.md) |

提 Bug 前请先搜一遍已有 Issue。复现步骤缺失的 Bug 会被要求补充后才处理。

---

## 本地开发

```bash
git clone https://github.com/bryce988/keel-admin.git   # 国内可换成 git@gitee.com:yewang_top/keel-admin.git
cd keel-admin
```

> **GitHub 与 Gitee 都是主仓库**，维护者一条 `git push` 同时推两边，内容始终一致。
> Issue 与 PR 提到哪边都行，就近选一个即可。

**推荐：全部跑在容器里**（本机只需要 Docker）

```bash
cp .env.example .env         # 数据库、Redis、JWT 密钥都在这里
docker compose up -d
```

前端 http://localhost:5173 · 后端 http://localhost:8787 · 默认账号 `admin` / `admin123`。

**不用容器时**，后端要 PHP >= 8.4（`openspout ^5` 的下限）、Composer >= 2.0：

```bash
cd server
composer install
cp ../.env.example .env      # 配置数据库与 Redis
php start.php start          # 调试模式，改代码自动 reload
```

前端要 Node >= 18。**用 npm，不要用 pnpm**——corepack 拉起的 pnpm 11 配 Node 20
会报 `ERR_UNKNOWN_BUILTIN_MODULE`，仓库里也只有 `package-lock.json`：

```bash
cd web
npm ci
npm run dev
```

**改了代码没生效？** webman 是常驻内存的：调试模式下 monitor 进程会自动 reload；生产模式下必须手动 `php start.php reload`。改了 `config/`、自定义进程或 `start.php` 则需要 `restart`。

---

## 分支与提交

- 从 `develop` 切分支：`feature/模块-简述`、`fix/问题简述`、`docs/简述`
- PR 一律合入 `develop`；`main` 只接受发版合并
- 提交信息格式：`type(scope): subject`

```
feat(role): 角色详情增加字段级权限配置
fix(auth): 修复刷新 token 后权限缓存未失效
refactor(table): ProTable 抽出列设置逻辑
docs(readme): 补充 Docker 启动说明
chore(deps): 升级 element-plus 到 2.9.0
```

type 取值：`feat` `fix` `refactor` `perf` `style` `docs` `test` `chore`。
一个提交只做一件事；不要把格式化和逻辑改动混在一个提交里。

---

## 推送到两个仓库（维护者）

两个仓库地位相同，都要保持最新。给同一个 `origin` 配两个 push 地址，
之后 `git push` 一条命令就会依次推到两边——**不要只推一边**，否则两个「主仓库」就名不副实了：

```bash
# 一次性配置
git remote add origin git@github.com:bryce988/keel-admin.git
git remote set-url --add --push origin git@github.com:bryce988/keel-admin.git
git remote set-url --add --push origin git@gitee.com:yewang_top/keel-admin.git

# 确认配置（应看到 1 个 fetch、2 个 push）
git remote -v
# origin  git@github.com:bryce988/keel-admin.git (fetch)
# origin  git@github.com:bryce988/keel-admin.git (push)
# origin  git@gitee.com:yewang_top/keel-admin.git (push)
```

日常使用：

```bash
git push              # 推当前分支到 GitHub + Gitee
git push --tags       # 推标签到两边
git push -u origin main
```

**注意事项**

- **fetch 只走第一个地址**：`git pull` / `git fetch` 只从 GitHub 拉。两边内容一致时这没有影响；
  若在 Gitee 上直接合并了 PR，记得先 `git pull git@gitee.com:yewang_top/keel-admin.git main` 再推
- **两边可以共用同一把 SSH 公钥**：把 `~/.ssh/id_ed25519.pub` 分别加到 GitHub 与 Gitee 账号即可，不必生成两把
- **新建第二个仓库时要建成空仓库**：创建时不要勾选「使用 README 初始化」，否则首次推送会因非快进被拒，需要 `git push -f`
- **推送是顺序执行的**：若 Gitee 那一步失败（网络、仓库未审核通过），GitHub 可能已推成功。看清输出，失败后单独补推：

```bash
git push git@gitee.com:yewang_top/keel-admin.git main
```

- 只想推其中一边时，临时指定地址即可：`git push git@github.com:bryce988/keel-admin.git main`

---

## 代码规范

**通用**

- 不写死颜色值（用设计令牌）、不写死枚举（用数据字典）、不写死菜单（由后端权限树生成）
- 新增文案避免技术黑话，按用户认知命名（是「通知设置」不是「webhook 配置」）

**前端**

- Vue 3 `<script setup>` + TypeScript，`strict: true`，不用 `any`
- 页面不直接调 `axios`，统一走 `utils/request`
- 权限控制用 `v-permission` 指令，不要自己写 `if (perms.includes(...))`
- 单文件超过 300 行考虑拆分；弹窗独立成组件

**后端**

- 控制器不写 SQL / Eloquent 查询，一律经 `service/`
- 事务只在 `service/` 层开启
- 各端只写自己的 controller，业务逻辑下沉 `app/common/service`，**端与端之间禁止互相引用**
- 数据权限由模型全局 Scope 统一注入，业务代码不得手写归属过滤，也不要随手 `withoutGlobalScope()`
- **禁止**：静态变量或单例存请求态、`exit`/`die`、运行期改配置、使用 `$_GET`/`$_SESSION` 等超全局

提交前跑一遍检查（在容器里跑，本机不需要装 PHP/Node）：

```bash
docker compose exec web npm run check          # vue-tsc 类型检查 + vite build
docker compose exec server composer check      # composer validate + php -l 全量
sh scripts/check-bizcode.sh                    # 业务码与 api.md 一致性（纯静态）
sh scripts/acceptance.sh                       # 43 项权限与数据隔离断言
```

四条都要跑，各自挡的是不同的东西：

- `vue-tsc` **不解析模板结构**，改了 `.vue` 的 template 必须靠 `vite build` 才能验到，
  所以 `npm run check` 是两步而不是只跑 type-check
- `vite build` 又验不到没被 import 的 `.vue`。`views/template/` 靠开发环境专属路由加载，
  改了那边要 `curl http://localhost:5173/src/views/template/xxx/index.vue`，模板写错会返回 500
- `composer lint` 只是 `php -l` 语法检查，挡不住类型错误——真正的行为验证靠 `acceptance.sh`

目前**没有单元测试**，也没有接 ESLint/Prettier（见 `PROJECT.md` 的技术债一节）。
上面四条就是当前全部的门禁，CI 里跑的也是同样的命令。

---

## PR 检查清单

提 PR 时请在描述中逐条确认，未勾选的项请说明原因。

**通用**

- [ ] 关联了对应 Issue（如有）
- [ ] 自测通过，附上关键截图或接口返回
- [ ] 没有引入新的写死颜色 / 写死枚举 / 写死菜单
- [ ] 文案符合规范，错误提示写清了「哪里错了、怎么改」

**涉及接口时**

- [ ] 新增写接口已定义权限点，并在路由分组上声明
- [ ] 服务端独立校验通过（前端隐藏按钮后用 curl 直接调应被拒绝）
- [ ] 涉及业务数据的查询走了数据权限 Scope，未手写归属过滤
- [ ] 敏感字段（手机号、金额、证件号）在接口层脱敏，不是前端打码
- [ ] 写操作会进入操作日志，含字段级变更

**涉及后端且改动较大时**

- [ ] 没有跨请求残留状态（静态变量、单例、全局数组）
- [ ] 大数据量查询用了分块，没有一次性 `get()` 全表
- [ ] 新增第三方凭据（如微信 access_token）存在 Redis 且加了分布式锁

**涉及多端时**

- [ ] 新增业务逻辑放在 `app/common/service`，不在某个端里私自实现
- [ ] 端与端的 token 类型校验未被绕过

---

## 版本与发版

- 遵循[语义化版本](https://semver.org/lang/zh-CN/)：`主版本.次版本.修订号`
- `web/` 与 `server/` **共用同一个 tag**，保证前后端接口对得上
- 破坏性变更必须在 CHANGELOG 中以 `BREAKING CHANGE` 标注，并给出迁移说明
- C 端接口的破坏性变更不改旧版本，升 `/v2` 并保留旧版 6 个月

---

## 行为准则

对事不对人。技术讨论欢迎激烈，人身攻击、歧视性言论不被接受。维护者有权删除不当内容并限制参与。
