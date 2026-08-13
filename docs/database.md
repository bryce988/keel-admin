# Keel · 数据库设计

> 版本 v1.0 · 对应 Keel v1.0.0 · MySQL 8.0+
> 配套文档：[接口契约](api.md) · [项目文档](../PROJECT.md)

---

## 1. 通用约定

这些约定对**所有表**生效，包括后续的业务表。框架的数据权限、审计日志、逻辑删除都依赖它们。

| 项目 | 约定 |
|---|---|
| 引擎 / 字符集 | `InnoDB` / `utf8mb4` / `utf8mb4_0900_ai_ci` |
| 主键 | `id BIGINT UNSIGNED AUTO_INCREMENT`，不用 UUID（索引性能与排序） |
| 表名 | `snake_case` 复数，系统表统一 `sys_` 前缀，业务表用自己的前缀 |
| 时间 | `DATETIME`（不用 TIMESTAMP，避免 2038 与时区隐式转换），统一存 UTC+8 |
| 金额 | `BIGINT` 存**分**，禁止 FLOAT/DOUBLE |
| 布尔 | `TINYINT(1)`，`1` 真 `0` 假 |
| 状态 | `TINYINT`，取值必须在数据字典中有对应项 |
| 逻辑删除 | `deleted_at DATETIME NULL`，为空表示未删除 |
| 审计字段 | `creator_id` `updater_id` `created_at` `updated_at`，由框架自动填充 |
| 索引命名 | 唯一索引 `uk_*`，普通索引 `idx_*`，外键不建物理约束（由应用层保证） |
| **注释** | **每张表、每个字段都必须写 `COMMENT`**，枚举字段要在注释中列全取值（如 `0停用 1在职 2试用期`） |

**数据权限的硬性要求**

凡是需要按部门/个人隔离的表，**必须**包含这两个字段，模型全局 Scope 依赖它们注入过滤条件：

```sql
`dept_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属部门',
`owner_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属人',
```

缺字段的表无法接入数据权限，评审时会被打回。

---

## 2. ER 关系

```
sys_depts ──1:N──> sys_users <──N:M──> sys_roles <──N:M──> sys_permissions
    │                   │                  │
    │                   │                  ├──1:N──> sys_role_depts   (自定义数据范围)
    │                   │                  └──1:N──> sys_role_fields  (字段级权限)
    │                   │
    └──1:N──> sys_posts │
                        ├──1:N──> sys_operation_logs
                        └──1:N──> sys_login_logs

sys_dict_types ──1:N──> sys_dict_items        (驱动全站枚举与状态色)
sys_params                                     (系统参数，无关联)
```

---

## 3. 建表语句

### 3.1 sys_users 用户（员工账号）

```sql
CREATE TABLE `sys_users` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `username`       VARCHAR(64)     NOT NULL                COMMENT '登录账号',
  `password`       VARCHAR(255)    NOT NULL                COMMENT 'password_hash 加密',
  `real_name`      VARCHAR(64)     NOT NULL DEFAULT ''     COMMENT '姓名',
  `avatar`         VARCHAR(255)    NOT NULL DEFAULT ''     COMMENT '头像地址',
  `phone`          VARCHAR(20)     NOT NULL DEFAULT ''     COMMENT '手机号，受字段级权限控制',
  `email`          VARCHAR(128)    NOT NULL DEFAULT ''     COMMENT '邮箱',
  `dept_id`        BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '所属部门，0=未分配',
  `post_id`        BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '岗位',
  `status`         TINYINT         NOT NULL DEFAULT 1      COMMENT '0停用 1在职 2试用期',
  `is_super`       TINYINT(1)      NOT NULL DEFAULT 0      COMMENT '超级管理员，跳过权限校验',
  `perm_version`   INT UNSIGNED    NOT NULL DEFAULT 0      COMMENT '权限版本号，授权变更时递增使缓存失效',
  `pwd_updated_at` DATETIME        NULL                    COMMENT '密码最后修改时间，用于有效期校验',
  `last_login_at`  DATETIME        NULL COMMENT '最后登录时间',
  `last_login_ip`  VARCHAR(45)     NOT NULL DEFAULT ''     COMMENT '兼容 IPv6',
  `remark`         VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '备注',
  `creator_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人',
  `updater_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最后修改人',
  `created_at`     DATETIME        NOT NULL COMMENT '创建时间',
  `updated_at`     DATETIME        NOT NULL COMMENT '更新时间',
  `deleted_at`     DATETIME        NULL COMMENT '删除时间，NULL 表示未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_dept` (`dept_id`),
  KEY `idx_phone` (`phone`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户（员工账号）';
```

**说明**

- `perm_version` 是权限即时生效的关键：角色授权变更时递增，Redis 中的权限缓存 key 含该值，旧缓存自然失效，用户无需重新登录
- `is_super = 1` 的账号跳过一切权限校验，**不允许通过界面授予**，只能在数据库或初始化脚本中设置
- 账号只停用（`status = 0`）不物理删除，`deleted_at` 仅用于极端情况的软删

### 3.2 sys_depts 部门

```sql
CREATE TABLE `sys_depts` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `parent_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0   COMMENT '上级部门，0=顶级',
  `ancestors`  VARCHAR(255)    NOT NULL DEFAULT ''  COMMENT '祖级路径，如 0,1,3',
  `name`       VARCHAR(64)     NOT NULL COMMENT '名称',
  `code`       VARCHAR(64)     NOT NULL             COMMENT '部门编码',
  `leader_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0   COMMENT '部门负责人',
  `sort`       INT             NOT NULL DEFAULT 0 COMMENT '排序，值越小越靠前',
  `status`     TINYINT         NOT NULL DEFAULT 1   COMMENT '0停用 1启用',
  `creator_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人',
  `updater_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最后修改人',
  `created_at` DATETIME        NOT NULL COMMENT '创建时间',
  `updated_at` DATETIME        NOT NULL COMMENT '更新时间',
  `deleted_at` DATETIME        NULL COMMENT '删除时间，NULL 表示未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_ancestors` (`ancestors`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='部门';
```

**为什么要 `ancestors`**：数据权限「本部门及下属」需要一次性取出整棵子树。用祖级路径 `LIKE '0,1,%'` 一条 SQL 搞定，比递归查询快得多。移动部门时必须同步更新其所有子孙的 `ancestors`。

### 3.3 sys_posts 岗位

```sql
CREATE TABLE `sys_posts` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name`            VARCHAR(64)     NOT NULL COMMENT '名称',
  `code`            VARCHAR(64)     NOT NULL COMMENT '编码',
  `dept_id`         BIGINT UNSIGNED NOT NULL DEFAULT 0  COMMENT '所属部门，0=全公司通用',
  `default_role_id` BIGINT UNSIGNED NOT NULL DEFAULT 0  COMMENT '入职时带出的默认角色',
  `sort`            INT             NOT NULL DEFAULT 0 COMMENT '排序，值越小越靠前',
  `status`          TINYINT         NOT NULL DEFAULT 1 COMMENT '0停用 1启用',
  `remark`          VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '备注',
  `created_at`      DATETIME        NOT NULL COMMENT '创建时间',
  `updated_at`      DATETIME        NOT NULL COMMENT '更新时间',
  `deleted_at`      DATETIME        NULL COMMENT '删除时间，NULL 表示未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_dept` (`dept_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='岗位';
```

**岗位 ≠ 角色**：岗位是 HR 概念，只在新建用户时带出 `default_role_id` 作为默认值，之后两者不再联动。改岗位不会改已有账号的角色。

### 3.4 sys_roles 角色

```sql
CREATE TABLE `sys_roles` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name`       VARCHAR(64)     NOT NULL COMMENT '名称',
  `code`       VARCHAR(64)     NOT NULL             COMMENT '角色编码，如 ROLE_DEPT_MGR',
  `parent_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0   COMMENT '继承自，0=无；仅支持单继承一层',
  `data_scope` TINYINT         NOT NULL DEFAULT 4   COMMENT '1全部 2本部门及下属 3本部门 4仅本人 5自定义',
  `is_builtin` TINYINT(1)      NOT NULL DEFAULT 0   COMMENT '内置角色不可删除',
  `sort`       INT             NOT NULL DEFAULT 0 COMMENT '排序，值越小越靠前',
  `status`     TINYINT         NOT NULL DEFAULT 1 COMMENT '0停用 1启用',
  `remark`     VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '备注',
  `creator_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人',
  `updater_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最后修改人',
  `created_at` DATETIME        NOT NULL COMMENT '创建时间',
  `updated_at` DATETIME        NOT NULL COMMENT '更新时间',
  `deleted_at` DATETIME        NULL COMMENT '删除时间，NULL 表示未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色';
```

### 3.5 sys_user_roles 用户-角色

```sql
CREATE TABLE `sys_user_roles` (
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户 ID',
  `role_id` BIGINT UNSIGNED NOT NULL COMMENT '角色 ID',
  PRIMARY KEY (`user_id`, `role_id`),
  KEY `idx_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户角色关联';
```

多角色时：功能权限取**并集**，数据范围取**最大者**（`data_scope` 数值越小范围越大）。

### 3.6 sys_permissions 菜单与权限点

菜单与权限点合并为一棵树，`type` 区分节点性质。

```sql
CREATE TABLE `sys_permissions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `parent_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0   COMMENT '上级节点',
  `name`       VARCHAR(64)     NOT NULL             COMMENT '显示名称',
  `type`       TINYINT         NOT NULL             COMMENT '1目录 2菜单 3按钮 4接口 5数据(字段)',
  `perm_code`  VARCHAR(128)    NOT NULL DEFAULT ''  COMMENT '权限标识，目录可为空',
  `path`       VARCHAR(255)    NOT NULL DEFAULT ''  COMMENT '前端路由路径',
  `component`  VARCHAR(255)    NOT NULL DEFAULT ''  COMMENT '前端组件路径',
  `icon`       VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '菜单图标',
  `api_method` VARCHAR(10)     NOT NULL DEFAULT ''  COMMENT '绑定接口方法',
  `api_path`   VARCHAR(255)    NOT NULL DEFAULT ''  COMMENT '绑定接口路径',
  `visible`    TINYINT(1)      NOT NULL DEFAULT 1   COMMENT '是否显示在菜单，详情页设 0',
  `keep_alive` TINYINT(1)      NOT NULL DEFAULT 1   COMMENT '多页签切换时是否缓存页面',
  `sort`       INT             NOT NULL DEFAULT 0 COMMENT '排序，值越小越靠前',
  `status`     TINYINT         NOT NULL DEFAULT 1   COMMENT '0停用 1启用；权限点只停用不删除',
  `created_at` DATETIME        NOT NULL COMMENT '创建时间',
  `updated_at` DATETIME        NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_perm_code` (`perm_code`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='菜单与权限点';
```

**注意**：`uk_perm_code` 对空串会冲突（多个目录都是 `''`）。实现时二选一——目录也给唯一标识（推荐，如 `sys:dashboard`），或把唯一索引改为函数索引跳过空值。推荐前者，保持"每个节点都有标识"的一致性。

### 3.7 sys_role_permissions 角色-权限

```sql
CREATE TABLE `sys_role_permissions` (
  `role_id`       BIGINT UNSIGNED NOT NULL COMMENT '角色 ID',
  `permission_id` BIGINT UNSIGNED NOT NULL COMMENT '权限点 ID',
  PRIMARY KEY (`role_id`, `permission_id`),
  KEY `idx_permission` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色权限关联';
```

### 3.8 sys_role_depts 角色自定义数据范围

```sql
CREATE TABLE `sys_role_depts` (
  `role_id` BIGINT UNSIGNED NOT NULL COMMENT '角色 ID',
  `dept_id` BIGINT UNSIGNED NOT NULL COMMENT '部门 ID',
  PRIMARY KEY (`role_id`, `dept_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色自定义数据范围（data_scope=5 时生效）';
```

### 3.9 sys_role_fields 字段级权限

```sql
CREATE TABLE `sys_role_fields` (
  `id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `role_id`  BIGINT UNSIGNED NOT NULL COMMENT '角色 ID',
  `object`   VARCHAR(64)     NOT NULL COMMENT '对象标识，通常为表名，如 sys_users',
  `field`    VARCHAR(64)     NOT NULL COMMENT '字段名，如 phone',
  `visible`  TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '0=接口返回脱敏值或不返回',
  `editable` TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否可编辑，0=只读',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_field` (`role_id`, `object`, `field`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='字段级权限';
```

**默认策略**：表中无记录 = 按该字段的全局默认（在代码中声明敏感字段清单，默认不可见）。这样新增敏感字段时不会因为忘记配置而泄露。

### 3.10 sys_dict_types / sys_dict_items 数据字典

```sql
CREATE TABLE `sys_dict_types` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name`       VARCHAR(64)     NOT NULL COMMENT '字典名称，如 通用状态',
  `code`       VARCHAR(64)     NOT NULL COMMENT '字典编码，如 common_status',
  `remark`     VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '备注',
  `status`     TINYINT         NOT NULL DEFAULT 1 COMMENT '0停用 1启用',
  `created_at` DATETIME        NOT NULL COMMENT '创建时间',
  `updated_at` DATETIME        NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='字典类型';

CREATE TABLE `sys_dict_items` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `type_code`  VARCHAR(64)     NOT NULL COMMENT '关联 sys_dict_types.code',
  `label`      VARCHAR(64)     NOT NULL COMMENT '显示文案',
  `value`      VARCHAR(64)     NOT NULL COMMENT '存储值，一经使用不可修改',
  `tag_type`   VARCHAR(16)     NOT NULL DEFAULT '' COMMENT 'success/warning/danger/primary/info，驱动标签颜色',
  `sort`       INT             NOT NULL DEFAULT 0 COMMENT '排序，值越小越靠前',
  `status`     TINYINT         NOT NULL DEFAULT 1 COMMENT '0停用 1启用',
  `remark`     VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '备注',
  `created_at` DATETIME        NOT NULL COMMENT '创建时间',
  `updated_at` DATETIME        NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_value` (`type_code`, `value`),
  KEY `idx_type` (`type_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='字典项';
```

`tag_type` 是全站状态色一致性的来源：前端 `<DictTag>` 直接按它渲染，不在页面里各写各的颜色判断。

### 3.11 sys_params 参数配置

```sql
CREATE TABLE `sys_params` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `group`       VARCHAR(32)     NOT NULL DEFAULT 'basic' COMMENT 'basic/security/integration/advanced',
  `name`        VARCHAR(64)     NOT NULL COMMENT '名称',
  `param_key`   VARCHAR(128)    NOT NULL COMMENT '参数键，如 sys.upload.maxSize',
  `param_value` TEXT            NOT NULL COMMENT '参数值',
  `value_type`  VARCHAR(16)     NOT NULL DEFAULT 'string' COMMENT 'string/int/bool/json',
  `is_builtin`  TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '内置参数不可删除，只可改值',
  `is_secret`   TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '密钥类，只写不读，界面显示掩码',
  `remark`      VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '备注',
  `updater_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最后修改人',
  `created_at`  DATETIME        NOT NULL COMMENT '创建时间',
  `updated_at`  DATETIME        NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_param_key` (`param_key`),
  KEY `idx_group` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统参数';
```

### 3.12 sys_operation_logs 操作日志

```sql
CREATE TABLE `sys_operation_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `trace_id`   VARCHAR(64)     NOT NULL DEFAULT ''  COMMENT '链路追踪 ID，与响应体一致',
  `user_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户 ID',
  `username`   VARCHAR(64)     NOT NULL DEFAULT ''  COMMENT '冗余存储，用户改名后日志仍可读',
  `dept_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0   COMMENT '操作人部门，日志本身也受数据权限约束',
  `module`     VARCHAR(64)     NOT NULL DEFAULT ''  COMMENT '模块名，如 系统管理/用户',
  `action`     TINYINT         NOT NULL             COMMENT '1新增 2修改 3删除 4导出 5授权 6其他',
  `title`      VARCHAR(255)    NOT NULL DEFAULT ''  COMMENT '操作描述',
  `target`     VARCHAR(128)    NOT NULL DEFAULT ''  COMMENT '操作对象标识',
  `api_method` VARCHAR(10)     NOT NULL DEFAULT ''  COMMENT '请求方法',
  `api_path`   VARCHAR(255)    NOT NULL DEFAULT ''  COMMENT '请求路径',
  `ip`         VARCHAR(45)     NOT NULL DEFAULT '' COMMENT '来源 IP',
  `user_agent` VARCHAR(255)    NOT NULL DEFAULT ''  COMMENT '客户端标识',
  `params`     JSON            NULL                 COMMENT '请求参数，密码等字段已脱敏',
  `changes`    JSON            NULL                 COMMENT '字段级变更 [{field,old,new}]，只记变化的字段',
  `status`     TINYINT(1)      NOT NULL DEFAULT 1   COMMENT '1成功 0失败',
  `error_msg`  VARCHAR(500)    NOT NULL DEFAULT ''  COMMENT '失败原因',
  `duration`   INT UNSIGNED    NOT NULL DEFAULT 0   COMMENT '耗时毫秒',
  `created_at` DATETIME        NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `created_at`),
  KEY `idx_trace` (`trace_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志';
```

**日志表不可修改、不可删除**，只按 `sys.log.retainDays` 归档。数据量大时按月分表或转 ClickHouse，接口层保持不变。

### 3.13 sys_login_logs 登录日志

```sql
CREATE TABLE `sys_login_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户 ID',
  `username`   VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '登录账号',
  `ip`         VARCHAR(45)     NOT NULL DEFAULT '' COMMENT '来源 IP',
  `location`   VARCHAR(64)     NOT NULL DEFAULT ''  COMMENT 'IP 归属地',
  `browser`    VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '浏览器',
  `os`         VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '操作系统',
  `type`       TINYINT         NOT NULL DEFAULT 1   COMMENT '1登录 2登出',
  `status`     TINYINT(1)      NOT NULL DEFAULT 1   COMMENT '1成功 0失败',
  `msg`        VARCHAR(255)    NOT NULL DEFAULT ''  COMMENT '失败原因',
  `created_at` DATETIME        NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_username_time` (`username`, `created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='登录日志';
```

登录失败也要记录（含失败原因），连续失败锁定的计数以此为依据。

---

## 4. 二期预留

C 端用户**独立建表**，与 `sys_users` 永不混用（见项目文档 §8.4）。二期落地时再建，此处仅锁定结构方向：

```sql
-- app_users        C 端用户：手机号、昵称、头像、渠道、状态
-- app_user_socials C 端三方账号：user_id, platform(wechat/apple), open_id, union_id
-- app_devices      设备与推送 token：user_id, device_id, channel, push_token
```

C 端表**不需要** `dept_id`，其数据隔离规则是"只能操作自己的数据"，由 `user_id` 直接约束。

---

## 5. 初始化数据

初始化脚本应可重复执行（幂等），且提供 `--demo` 开关区分「最小可用数据」与「演示数据」。

### 5.1 超级管理员

```sql
INSERT INTO `sys_users`
  (`username`,`password`,`real_name`,`status`,`is_super`,`created_at`,`updated_at`)
VALUES
  ('admin', '$2y$10$...', '系统管理员', 1, 1, NOW(), NOW());
```

初始密码由安装时随机生成并打印到控制台，**不写死在脚本里**；`pwd_updated_at` 置空，强制首次登录修改。

### 5.2 内置角色

| code | 名称 | data_scope | 说明 |
|---|---|---|---|
| `ROLE_SUPER` | 超级管理员 | 1 全部 | 内置，不可编辑删除 |
| `ROLE_DEPT_MGR` | 部门主管 | 2 本部门及下属 | 继承普通员工 |
| `ROLE_STAFF` | 普通员工 | 4 仅本人 | 大多数账号的基础角色 |
| `ROLE_DATA_MGR` | 数据管理员 | 1 全部 | 可导出、可删除 |
| `ROLE_SUPPORT` | 技术支持 | 4 仅本人 | 只读为主 |
| `ROLE_VIEWER` | 只读访客 | 3 本部门 | 全部只读 |
| `ROLE_AUDITOR` | 系统审计 | 1 全部 | 只读 + 日志查看，与其他角色互斥 |

### 5.3 权限点

按原型的 15 个页面生成，命名遵循 `模块:资源:操作`：

```sql
-- 目录
('概览',        1, 'sys:dashboard',      '', '', 10),
-- 菜单
('系统概览',    2, 'sys:dashboard:view', '/dashboard', 'views/dashboard/index.vue', 10),
-- 按钮
('导出概览',    3, 'sys:dashboard:export', '', '', 10),
-- 接口
('导出接口',    4, 'sys:dashboard:export:api', '', '', 10),  -- api_method=POST api_path=/admin/dashboard/export
-- 数据（字段级）
('查看手机号',  5, 'sys:field:phone',    '', '', 10);
```

完整清单约 96 条，随迁移脚本落库；新增权限点默认不授予任何角色。

### 5.4 数据字典

| 字典编码 | 名称 | 字典项 |
|---|---|---|
| `common_status` | 通用状态 | 正常(success) / 待处理(warning) / 异常(danger) / 进行中(primary) / 已归档(info) |
| `enable_status` | 启用状态 | 启用(success) / 停用(info) |
| `user_status` | 用户状态 | 在职(success) / 试用期(warning) / 停用(info) |
| `data_scope` | 数据范围 | 全部 / 本部门及下属 / 本部门 / 仅本人 / 自定义 |
| `perm_type` | 权限类型 | 目录 / 菜单 / 按钮 / 接口 / 数据 |
| `log_action` | 操作类型 | 新增 / 修改 / 删除 / 导出 / 授权 / 其他 |
| `yes_no` | 是否 | 是 / 否 |
| `gender` | 性别 | 男 / 女 / 未知 |

### 5.5 系统参数

| param_key | 默认值 | 分组 | 说明 |
|---|---|---|---|
| `sys.name` | Keel Admin | basic | 系统名称 |
| `sys.page.size` | 20 | basic | 默认分页条数 |
| `sys.upload.maxSize` | 20971520 | advanced | 单文件上传上限（字节） |
| `sys.export.maxRows` | 50000 | advanced | 单次导出最大行数 |
| `sys.log.retainDays` | 180 | advanced | 日志保留天数 |
| `sys.cache.ttl` | 300 | advanced | 字典缓存秒数 |
| `sys.pwd.minLength` | 8 | security | 密码最小长度 |
| `sys.pwd.expireDays` | 90 | security | 密码有效期 |
| `sys.login.failLimit` | 5 | security | 连续失败锁定次数 |
| `sys.login.lockMinutes` | 30 | security | 锁定时长 |
| `sys.session.timeout` | 1800 | security | 无操作登出秒数 |

---

## 6. 索引与性能约定

- 列表页的默认排序字段必须有索引（通常是 `created_at` 或 `sort`）
- 分页查询禁止 `SELECT *`，只取列表需要的字段
- 日志类表的查询必须带时间范围，接口层强制默认最近 7 天
- 超过 10 万行的表新增索引走 `pt-online-schema-change` 或在低峰期执行
- `JSON` 字段不建索引，需要检索的属性提取为独立列
