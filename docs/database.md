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
| **注释** | **每张表、每个字段都必须写 `COMMENT`**，枚举字段要在注释中列全取值（如 `0停用 1启用`） |

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
  `status`         TINYINT         NOT NULL DEFAULT 1      COMMENT '0停用 1启用',
  `is_super`       TINYINT(1)      NOT NULL DEFAULT 0      COMMENT '超级管理员，跳过权限校验',
  `perm_version`   INT UNSIGNED    NOT NULL DEFAULT 0      COMMENT '权限版本号，授权变更时递增使缓存失效',
  `token_version`  INT UNSIGNED    NOT NULL DEFAULT 0      COMMENT '会话版本号，改密/重置密码时递增使该用户全部令牌失效',
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
- `token_version` 是**强制下线**的开关，与 `perm_version` 职责不同：改密与管理员重置密码时递增，
  令牌载荷里带着它，鉴权与刷新都比对，不一致即 401。
  不能拿 `pwd_updated_at` 代替——那一列在管理员重置密码时被置为 `NULL`（兼作「必须改密」标志），
  参与时间比较毫无意义
- `is_super = 1` 的账号跳过一切权限校验，**不允许通过界面授予**，只能在数据库或初始化脚本中设置
- 账号只停用（`status = 0`）不物理删除，`deleted_at` 仅用于极端情况的软删

### 3.2 sys_depts 部门

```sql
CREATE TABLE `sys_depts` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `parent_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0   COMMENT '上级部门，0=顶级',
  `ancestors`  VARCHAR(255)    NOT NULL DEFAULT ''  COMMENT '祖级路径，如 0,1,3',
  `name`       VARCHAR(64)     NOT NULL COMMENT '名称',
  `code`       VARCHAR(64)     NOT NULL             COMMENT '部门编码，DEPT-加四位补零主键，由程序生成',
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
  `code`            VARCHAR(64)     NOT NULL COMMENT '编码，POST-加四位补零主键，由程序生成',
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

**岗位 ≠ 角色**：岗位是 HR 概念，`default_role_id` 只是「新人入职时的角色初始值」，不是这个岗位的权限。

生效范围写死在这三条里，任何一条被打破都会变成「改岗位就改权限」：

| 场景 | 行为 |
|---|---|
| **新建**用户，选中岗位 | 把该岗位的 `default_role_id` 预填进角色框，之后可自由增删 |
| **新建**时换岗位 | 只有当角色框为空、或仍是上一个岗位带出的原样（用户没改过）时才跟着换；用户手动动过就不再覆盖 |
| **编辑**用户，改岗位 | **绝不触碰角色**。调岗是 HR 动作，不该静默改动一个人的权限 |

`default_role_id = 0` 表示这个岗位不带角色，是合法取值。

预填发生在**前端**（`views/system/user/index.vue` 的 `onPostChange`），后端 `UserService`
不读这个字段——服务端只按请求里的 `role_ids` 授权。这样通过接口或导入建号时行为是确定的：
给什么角色就是什么角色，不会有一层看不见的默认值在背后生效。

### 3.4 sys_roles 角色

```sql
CREATE TABLE `sys_roles` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name`       VARCHAR(64)     NOT NULL COMMENT '名称',
  `code`       VARCHAR(64)     NOT NULL             COMMENT '角色编码，ROLE-加四位补零主键，由程序生成',
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

### 3.8.1 sys_role_mutexes 角色互斥

```sql
CREATE TABLE `sys_role_mutexes` (
  `role_id`  BIGINT UNSIGNED NOT NULL COMMENT '角色 ID',
  `mutex_id` BIGINT UNSIGNED NOT NULL COMMENT '与之互斥的角色 ID',
  PRIMARY KEY (`role_id`, `mutex_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色互斥（职责分离）';
```

**职责分离**：审计员不能同时是数据管理员，否则「操作」与「审计操作」落在同一个人身上，
留痕就失去意义。互斥是**对称**的，写入时两个方向都存一条，
查询时就不用写 `where role_id = ? or mutex_id = ?` 这种两边都要考虑的条件——
那种写法总有一处会漏。

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

> **当前实现说明（v1.0）**：字段级权限走的是 `sys_permissions` 里 `type=5` 的权限点
> （如 `sys:field:user:phone`），跟功能权限一起在角色授权时勾选，服务端在接口返回前脱敏。
> **本表暂未使用**，保留结构是为了将来需要「按对象×字段精细配置」时不用改表。
> 两套机制并存只会让人搞不清哪个说了算，所以现阶段只保留权限点这一套。

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
  `dept_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '登录人部门，日志本身也受数据权限约束',
  `ip`         VARCHAR(45)     NOT NULL DEFAULT '' COMMENT '来源 IP',
  `location`   VARCHAR(64)     NOT NULL DEFAULT ''  COMMENT 'IP 归属地（ip2region 离线库）',
  `browser`    VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '浏览器',
  `os`         VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '操作系统',
  `type`       TINYINT         NOT NULL DEFAULT 1   COMMENT '1登录 2登出',
  `status`     TINYINT(1)      NOT NULL DEFAULT 1   COMMENT '1成功 0失败',
  `msg`        VARCHAR(255)    NOT NULL DEFAULT ''  COMMENT '失败原因',
  `created_at` DATETIME        NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_username_time` (`username`, `created_at`),
  KEY `idx_created` (`created_at`),
  KEY `idx_dept` (`dept_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='登录日志';
```

登录失败也要记录（含失败原因），连续失败锁定的计数以此为依据。

⚠️ **`dept_id` 不是可选的**。数据权限全局 Scope 在非「仅本人」的范围下，
找不到部门列就**直接放行、不加任何条件**——这张表早期漏了这一列，
结果部门主管能看到全公司的登录记录。写入时由 `AuthService::writeLoginLog()`
按 `user_id` 反查填入；登录失败且账号不存在时为 0，只有「全部数据」范围看得到。

`location` 用 ip2region 的离线库解析，不调第三方接口——登录是同步路径，
一次外部 HTTP 请求就能让整个登录卡住。查不到统一写「未知」，不留空白。

---

### 3.14 sys_notices / sys_notice_reads 系统公告

```sql
CREATE TABLE `sys_notices` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `title`          VARCHAR(128)    NOT NULL COMMENT '标题',
  `content`        TEXT            NOT NULL COMMENT '正文，富文本 HTML（写入时已净化）',
  `type`           VARCHAR(32)     NOT NULL DEFAULT 'notice' COMMENT '类型，字典 notice_type',
  `status`         TINYINT         NOT NULL DEFAULT 0 COMMENT '0草稿 1已发布',
  `published_at`   DATETIME        NULL     COMMENT '发布时间，草稿为 NULL',
  `publisher_id`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '发布人',
  `publisher_name` VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '冗余，发布人改名/离职后仍可读',
  `creator_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人',
  `updater_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最后修改人',
  `created_at`     DATETIME        NOT NULL COMMENT '创建时间',
  `updated_at`     DATETIME        NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status_published` (`status`, `published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统公告';

CREATE TABLE `sys_notice_reads` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `notice_id`  BIGINT UNSIGNED NOT NULL COMMENT '公告 ID',
  `user_id`    BIGINT UNSIGNED NOT NULL COMMENT '阅读人',
  `created_at` DATETIME        NOT NULL COMMENT '已读时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notice_user` (`notice_id`, `user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公告已读回执';
```

**只记已读，不记未读**。未读 = 已发布公告 ∖ 我的回执，是算出来的。
反过来做（发布时给每个用户插一行未读）会让「发一条公告」变成一次全表写入——
1000 人的系统就是 1000 行，新入职的人还得补发。
只记已读，写入量与实际阅读行为成正比，而不是与人数成正比。

`uk_notice_user` 既防重复回执，也是「我读过哪些」这个查询的索引。
并发插入（同一个人两个标签页同时点开）由它兜住，服务端捕获后当成功处理。

⚠️ **这张表不挂 `HasDataScope`**，与其他业务表相反。公告的受众就是所有登录用户，
按部门过滤会让「总部发的通知分公司看不到」，而这恰恰是公告最没用的一种失败。
它也没有部门列可过滤——公告不属于任何部门。

⚠️ **不用软删**：公告删掉就是不该再出现在任何人的消息里，回执由
`NoticeService::delete()` 一并清掉，否则 `sys_notice_reads` 会积压指向空 id 的行。

`status` 的 0/1 是「草稿 / 已发布」，字典单开一份 `notice_status`，
不复用 `enable_status`——公告没有「停用」这回事。

`content` 存的是**已净化的** HTML（`support/Html.php` 的白名单，写入时过一遍）。
库里不再存一份纯文本副本：管理端按关键词搜正文时理论上会命中标签名（搜 "li" 多出几条），
但那要多一列、多一处同步，而搜公告本身是低频操作。列表摘要是查询时现剥的。

---

### 3.15 sys_export_tasks 数据导出任务

```sql
CREATE TABLE `sys_export_tasks` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `biz`          VARCHAR(32)     NOT NULL COMMENT '业务标识，见 ExportService::BIZ',
  `biz_name`     VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '冗余，业务改名后旧任务仍可读',
  `params`       TEXT            NOT NULL COMMENT '导出时的筛选条件（JSON）',
  `status`       TINYINT         NOT NULL DEFAULT 0 COMMENT '0排队 1处理中 2已完成 3失败',
  `row_count`    INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '导出行数',
  `file_name`    VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '下载文件名',
  `file_path`    VARCHAR(500)    NOT NULL DEFAULT '' COMMENT '服务器绝对路径，不下发给前端',
  `file_size`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '字节数',
  `error_msg`    VARCHAR(500)    NOT NULL DEFAULT '' COMMENT '失败原因，直接给用户看',
  `creator_id`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '发起人，也是数据权限的归属人列',
  `creator_name` VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '冗余存储',
  `dept_id`      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '发起人部门，数据权限要用',
  `expired_at`   DATETIME        NULL COMMENT '文件过期时间',
  `started_at`   DATETIME        NULL COMMENT '开始处理时间',
  `finished_at`  DATETIME        NULL COMMENT '完成/失败时间',
  `created_at`   DATETIME        NOT NULL COMMENT '创建时间',
  `updated_at`   DATETIME        NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_creator_time` (`creator_id`, `created_at`),
  KEY `idx_dept_time` (`dept_id`, `created_at`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数据导出任务';
```

归属人列是 `creator_id` 而不是 trait 默认的 `owner_id`：导出任务的归属就是发起人，
「仅本人」范围的账号只看得到自己发起的那些。

⚠️ **`dept_id` 不是可选的**，理由与 `sys_login_logs` 完全一样：数据权限在非「仅本人」
范围下找不到部门列会**直接放行、不加任何条件**。这张表漏了它的后果比日志表更重——
后面挂着一个可下载的文件，等于把别人导出的名单也给了出去。

`params` 存的是发起那一刻的筛选条件。不存的话只能在消费时按当前界面重新取，
而用户完全可能已经改了筛选、甚至关了页面。

`file_path` 只在服务端用，接口从不下发——它是服务器目录结构，属于不必要的信息暴露。
文件写在 `runtime/exports`：生成的是队列消费进程、下载的是 web 进程，
两者在同一容器里共享该目录。**多实例部署要换成对象存储或共享卷**。

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

随 `schema.sql` 建库时写入，主键写死，因此编码也是定值。
后四个是**规划中的示例**，脚手架里没有播种，编码要建出来才有。

| 名称 | code | data_scope | 说明 |
|---|---|---|---|
| 超级管理员 | `ROLE-0001` | 1 全部 | 内置，不可编辑删除 |
| 部门主管 | `ROLE-0002` | 2 本部门及下属 | 继承普通员工 |
| 普通员工 | `ROLE-0003` | 4 仅本人 | 大多数账号的基础角色 |
| 数据管理员 | — | 1 全部 | 可导出、可删除 |
| 技术支持 | — | 4 仅本人 | 只读为主 |
| 只读访客 | — | 3 本部门 | 全部只读 |
| 系统审计 | — | 1 全部 | 只读 + 日志查看，与其他角色互斥 |

⚠️ **角色编码由程序按主键生成（`ROLE-` 加四位补零），不再是可读标识符。**
表里以「名称」为主键列就是这个原因——写文档、写脚本、跟人沟通都该用名称。
前端的 `v-role` 指令匹配的仍是编码（登录接口下发的就是编码数组），
所以真要按角色分支，建议在业务侧维护一张「用途 → 角色 id」的配置，
而不是把 `ROLE-0007` 这种字符串直接写进模板。

### 5.3 权限点

按原型的 15 个页面生成，命名遵循 `模块:资源:操作`：

```sql
-- 目录（只分组，自己没有页面）
('系统管理',    1, 'sys',                '/system', 'Layout', 90),
-- 菜单
('用户管理',    2, 'sys:user:list',      '/system/user', 'views/system/user/index.vue', 10),
-- 按钮
('导出用户',    3, 'sys:user:export',    '', '', 7),
-- 接口
('导出接口',    4, 'sys:user:export:api', '', '', 90),  -- api_method=POST api_path=/admin/users/export
-- 数据（字段级）
('查看手机号',  5, 'sys:field:user:phone', '', '', 90);
```

**目录不是必须的**：底下只有一个页面时直接挂成一级菜单
（`概览` 就是这样，`type=2`、`parent_id=0`）。为一个页面单独立一层目录，
侧边栏会多一次展开点击，面包屑还会出现「概览 / 系统概览」这种同义重复。

完整清单约 96 条，随迁移脚本落库；新增权限点默认不授予任何角色。

### 5.4 数据字典

| 字典编码 | 名称 | 字典项 |
|---|---|---|
| `common_status` | 通用状态 | 正常(success) / 待处理(warning) / 异常(danger) / 进行中(primary) / 已归档(info) |
| `enable_status` | 启用状态 | 启用(success) / 停用(info) |
| `user_status` | 用户状态 | 启用(success) / 停用(info) |
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
