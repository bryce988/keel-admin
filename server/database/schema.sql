-- Keel 初始化建表脚本（MySQL 容器首次启动时自动执行）
-- 完整设计见 docs/database.md，此处为登录功能所需的核心表

SET NAMES utf8mb4;

-- ---------------------------------------------------------------- 用户
CREATE TABLE IF NOT EXISTS `sys_users` (
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
  `pwd_updated_at` DATETIME        NULL                    COMMENT '密码最后修改时间',
  `last_login_at`  DATETIME        NULL                    COMMENT '最后登录时间',
  `last_login_ip`  VARCHAR(45)     NOT NULL DEFAULT ''     COMMENT '兼容 IPv6',
  `remark`         VARCHAR(255)    NOT NULL DEFAULT ''     COMMENT '备注',
  `creator_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '创建人',
  `updater_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '最后修改人',
  `created_at`     DATETIME        NOT NULL                COMMENT '创建时间',
  `updated_at`     DATETIME        NOT NULL                COMMENT '更新时间',
  `deleted_at`     DATETIME        NULL                    COMMENT '删除时间，NULL 表示未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_dept` (`dept_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户（员工账号）';

-- ---------------------------------------------------------------- 部门
CREATE TABLE IF NOT EXISTS `sys_depts` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `parent_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '上级部门，0=顶级',
  `ancestors`  VARCHAR(255)    NOT NULL DEFAULT ''     COMMENT '祖级路径，如 0,1,3',
  `name`       VARCHAR(64)     NOT NULL                COMMENT '名称',
  `code`       VARCHAR(64)     NOT NULL                COMMENT '部门编码',
  `leader_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '部门负责人',
  `sort`       INT             NOT NULL DEFAULT 0      COMMENT '排序，值越小越靠前',
  `status`     TINYINT         NOT NULL DEFAULT 1      COMMENT '0停用 1启用',
  `creator_id` BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '创建人',
  `updater_id` BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '最后修改人',
  `created_at` DATETIME        NOT NULL                COMMENT '创建时间',
  `updated_at` DATETIME        NOT NULL                COMMENT '更新时间',
  `deleted_at` DATETIME        NULL                    COMMENT '删除时间，NULL 表示未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='部门';

-- ---------------------------------------------------------------- 角色
CREATE TABLE IF NOT EXISTS `sys_roles` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name`       VARCHAR(64)     NOT NULL                COMMENT '名称',
  `code`       VARCHAR(64)     NOT NULL                COMMENT '角色编码，如 ROLE_DEPT_MGR',
  `parent_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '继承自，0=无',
  `data_scope` TINYINT         NOT NULL DEFAULT 4      COMMENT '1全部 2本部门及下属 3本部门 4仅本人 5自定义',
  `is_builtin` TINYINT(1)      NOT NULL DEFAULT 0      COMMENT '内置角色不可删除',
  `sort`       INT             NOT NULL DEFAULT 0      COMMENT '排序，值越小越靠前',
  `status`     TINYINT         NOT NULL DEFAULT 1      COMMENT '0停用 1启用',
  `remark`     VARCHAR(255)    NOT NULL DEFAULT ''     COMMENT '备注',
  `creator_id` BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '创建人',
  `updater_id` BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '最后修改人',
  `created_at` DATETIME        NOT NULL                COMMENT '创建时间',
  `updated_at` DATETIME        NOT NULL                COMMENT '更新时间',
  `deleted_at` DATETIME        NULL                    COMMENT '删除时间，NULL 表示未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色';

CREATE TABLE IF NOT EXISTS `sys_user_roles` (
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户 ID',
  `role_id` BIGINT UNSIGNED NOT NULL COMMENT '角色 ID',
  PRIMARY KEY (`user_id`, `role_id`),
  KEY `idx_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户角色关联';

-- ---------------------------------------------------------------- 菜单与权限点
CREATE TABLE IF NOT EXISTS `sys_permissions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `parent_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '上级节点',
  `name`       VARCHAR(64)     NOT NULL                COMMENT '显示名称',
  `type`       TINYINT         NOT NULL                COMMENT '1目录 2菜单 3按钮 4接口 5数据',
  `perm_code`  VARCHAR(128)    NOT NULL                COMMENT '权限标识，全局唯一',
  `path`       VARCHAR(255)    NOT NULL DEFAULT ''     COMMENT '前端路由路径',
  `component`  VARCHAR(255)    NOT NULL DEFAULT ''     COMMENT '前端组件路径',
  `icon`       VARCHAR(64)     NOT NULL DEFAULT ''     COMMENT '菜单图标',
  `api_method` VARCHAR(10)     NOT NULL DEFAULT ''     COMMENT '绑定接口方法',
  `api_path`   VARCHAR(255)    NOT NULL DEFAULT ''     COMMENT '绑定接口路径',
  `visible`    TINYINT(1)      NOT NULL DEFAULT 1      COMMENT '是否显示在菜单',
  `keep_alive` TINYINT(1)      NOT NULL DEFAULT 1      COMMENT '页面是否缓存',
  `sort`       INT             NOT NULL DEFAULT 0      COMMENT '排序，值越小越靠前',
  `status`     TINYINT         NOT NULL DEFAULT 1      COMMENT '0停用 1启用',
  `created_at` DATETIME        NOT NULL                COMMENT '创建时间',
  `updated_at` DATETIME        NOT NULL                COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_perm_code` (`perm_code`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='菜单与权限点';

CREATE TABLE IF NOT EXISTS `sys_role_permissions` (
  `role_id`       BIGINT UNSIGNED NOT NULL COMMENT '角色 ID',
  `permission_id` BIGINT UNSIGNED NOT NULL COMMENT '权限点 ID',
  PRIMARY KEY (`role_id`, `permission_id`),
  KEY `idx_permission` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色权限关联';

-- ---------------------------------------------------------------- 日志
CREATE TABLE IF NOT EXISTS `sys_login_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '用户 ID',
  `username`   VARCHAR(64)     NOT NULL DEFAULT ''     COMMENT '登录账号',
  `dept_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '登录人部门，日志本身也受数据权限约束',
  `ip`         VARCHAR(45)     NOT NULL DEFAULT ''     COMMENT '来源 IP',
  `location`   VARCHAR(64)     NOT NULL DEFAULT ''     COMMENT 'IP 归属地',
  `browser`    VARCHAR(64)     NOT NULL DEFAULT ''     COMMENT '浏览器',
  `os`         VARCHAR(64)     NOT NULL DEFAULT ''     COMMENT '操作系统',
  `type`       TINYINT         NOT NULL DEFAULT 1      COMMENT '1登录 2登出',
  `status`     TINYINT(1)      NOT NULL DEFAULT 1      COMMENT '1成功 0失败',
  `msg`        VARCHAR(255)    NOT NULL DEFAULT ''     COMMENT '失败原因',
  `created_at` DATETIME        NOT NULL                COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_username_time` (`username`, `created_at`),
  -- 日志查询固定带时间范围；数据权限再注入 dept_id。
  -- 没有 idx_created 时「按时间翻登录日志」的 EXPLAIN 里 possible_keys 是 NULL。
  -- 不加单列 idx_dept：它是 idx_dept_time 的最左前缀，纯属重复（只增表，写入开销要省）
  KEY `idx_created` (`created_at`),
  KEY `idx_dept_time` (`dept_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='登录日志';


-- ---------------------------------------------------------------- 岗位
CREATE TABLE IF NOT EXISTS `sys_posts` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name`            VARCHAR(64)     NOT NULL                COMMENT '名称',
  `code`            VARCHAR(64)     NOT NULL                COMMENT '编码',
  `dept_id`         BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '所属部门，0=全公司通用',
  `default_role_id` BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '入职时带出的默认角色',
  `sort`            INT             NOT NULL DEFAULT 0      COMMENT '排序，值越小越靠前',
  `status`          TINYINT         NOT NULL DEFAULT 1      COMMENT '0停用 1启用',
  `remark`          VARCHAR(255)    NOT NULL DEFAULT ''     COMMENT '备注',
  `created_at`      DATETIME        NOT NULL                COMMENT '创建时间',
  `updated_at`      DATETIME        NOT NULL                COMMENT '更新时间',
  `deleted_at`      DATETIME        NULL                    COMMENT '删除时间，NULL 表示未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_dept` (`dept_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='岗位';

-- ---------------------------------------------------------------- 角色自定义数据范围
CREATE TABLE IF NOT EXISTS `sys_role_depts` (
  `role_id` BIGINT UNSIGNED NOT NULL COMMENT '角色 ID',
  `dept_id` BIGINT UNSIGNED NOT NULL COMMENT '部门 ID',
  PRIMARY KEY (`role_id`, `dept_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色自定义数据范围（data_scope=5 时生效）';

-- ---------------------------------------------------------------- 角色互斥
CREATE TABLE IF NOT EXISTS `sys_role_mutexes` (
  `role_id`  BIGINT UNSIGNED NOT NULL COMMENT '角色 ID',
  `mutex_id` BIGINT UNSIGNED NOT NULL COMMENT '与之互斥的角色 ID',
  PRIMARY KEY (`role_id`, `mutex_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色互斥（职责分离）';

-- ---------------------------------------------------------------- 字段级权限
CREATE TABLE IF NOT EXISTS `sys_role_fields` (
  `id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `role_id`  BIGINT UNSIGNED NOT NULL                COMMENT '角色 ID',
  `object`   VARCHAR(64)     NOT NULL                COMMENT '对象标识，通常为表名，如 sys_users',
  `field`    VARCHAR(64)     NOT NULL                COMMENT '字段名，如 phone',
  `visible`  TINYINT(1)      NOT NULL DEFAULT 1      COMMENT '0=接口返回脱敏值或不返回',
  `editable` TINYINT(1)      NOT NULL DEFAULT 0      COMMENT '是否可编辑，0=只读',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_field` (`role_id`, `object`, `field`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='字段级权限';

-- ---------------------------------------------------------------- 数据字典
CREATE TABLE IF NOT EXISTS `sys_dict_types` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name`       VARCHAR(64)     NOT NULL                COMMENT '字典名称，如 通用状态',
  `code`       VARCHAR(64)     NOT NULL                COMMENT '字典编码，如 common_status',
  `remark`     VARCHAR(255)    NOT NULL DEFAULT ''     COMMENT '备注',
  `status`     TINYINT         NOT NULL DEFAULT 1      COMMENT '0停用 1启用',
  `created_at` DATETIME        NOT NULL                COMMENT '创建时间',
  `updated_at` DATETIME        NOT NULL                COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='字典类型';

CREATE TABLE IF NOT EXISTS `sys_dict_items` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `type_code`  VARCHAR(64)     NOT NULL                COMMENT '关联 sys_dict_types.code',
  `label`      VARCHAR(64)     NOT NULL                COMMENT '显示文案',
  `value`      VARCHAR(64)     NOT NULL                COMMENT '存储值，一经使用不可修改',
  `tag_type`   VARCHAR(16)     NOT NULL DEFAULT ''     COMMENT 'success/warning/danger/primary/info，驱动标签颜色',
  `sort`       INT             NOT NULL DEFAULT 0      COMMENT '排序，值越小越靠前',
  `status`     TINYINT         NOT NULL DEFAULT 1      COMMENT '0停用 1启用',
  `remark`     VARCHAR(255)    NOT NULL DEFAULT ''     COMMENT '备注',
  `created_at` DATETIME        NOT NULL                COMMENT '创建时间',
  `updated_at` DATETIME        NOT NULL                COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_value` (`type_code`, `value`),
  KEY `idx_type` (`type_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='字典项';

-- ---------------------------------------------------------------- 系统参数
CREATE TABLE IF NOT EXISTS `sys_params` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `group`       VARCHAR(32)     NOT NULL DEFAULT 'basic' COMMENT 'basic/security/integration/advanced',
  `name`        VARCHAR(64)     NOT NULL                COMMENT '名称',
  `param_key`   VARCHAR(128)    NOT NULL                COMMENT '参数键，如 sys.upload.maxSize',
  `param_value` TEXT            NOT NULL                COMMENT '参数值',
  `value_type`  VARCHAR(16)     NOT NULL DEFAULT 'string' COMMENT 'string/int/bool/json',
  `is_builtin`  TINYINT(1)      NOT NULL DEFAULT 0      COMMENT '内置参数不可删除，只可改值',
  `is_secret`   TINYINT(1)      NOT NULL DEFAULT 0      COMMENT '密钥类，只写不读，界面显示掩码',
  `remark`      VARCHAR(255)    NOT NULL DEFAULT ''     COMMENT '备注',
  `updater_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '最后修改人',
  `created_at`  DATETIME        NOT NULL                COMMENT '创建时间',
  `updated_at`  DATETIME        NOT NULL                COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_param_key` (`param_key`),
  KEY `idx_group` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统参数';

-- ---------------------------------------------------------------- 操作日志
CREATE TABLE IF NOT EXISTS `sys_operation_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `trace_id`   VARCHAR(64)     NOT NULL DEFAULT ''     COMMENT '链路追踪 ID，与响应体一致',
  `user_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '用户 ID',
  `username`   VARCHAR(64)     NOT NULL DEFAULT ''     COMMENT '冗余存储，用户改名后日志仍可读',
  `dept_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '操作人部门，日志本身也受数据权限约束',
  `module`     VARCHAR(64)     NOT NULL DEFAULT ''     COMMENT '模块名，如 系统管理/用户',
  `action`     TINYINT         NOT NULL                COMMENT '1新增 2修改 3删除 4导出 5授权 6其他',
  `title`      VARCHAR(255)    NOT NULL DEFAULT ''     COMMENT '操作描述',
  `target`     VARCHAR(128)    NOT NULL DEFAULT ''     COMMENT '操作对象标识',
  `api_method` VARCHAR(10)     NOT NULL DEFAULT ''     COMMENT '请求方法',
  `api_path`   VARCHAR(255)    NOT NULL DEFAULT ''     COMMENT '请求路径',
  `ip`         VARCHAR(45)     NOT NULL DEFAULT ''     COMMENT '来源 IP',
  `user_agent` VARCHAR(255)    NOT NULL DEFAULT ''     COMMENT '客户端标识',
  `params`     JSON            NULL                    COMMENT '请求参数，密码等字段已脱敏',
  `changes`    JSON            NULL                    COMMENT '字段级变更 [{field,old,new}]，只记变化的字段',
  `status`     TINYINT(1)      NOT NULL DEFAULT 1      COMMENT '1成功 0失败',
  `error_msg`  VARCHAR(500)    NOT NULL DEFAULT ''     COMMENT '失败原因',
  `duration`   INT UNSIGNED    NOT NULL DEFAULT 0      COMMENT '耗时毫秒',
  `created_at` DATETIME        NOT NULL                COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `created_at`),
  KEY `idx_trace` (`trace_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_module` (`module`),
  -- 数据权限按 dept_id 过滤 + 查询固定带时间范围，两者要在同一个索引里。
  -- 不写 (dept_id, created_at, id)：InnoDB 二级索引隐式包含主键，第三列是多余的
  KEY `idx_dept_time` (`dept_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志';

-- ---------------------------------------------------------------- 基础数据
-- 权限点、字典、参数由 scripts/seed.php 播种（那边能表达父子关系与授权）
INSERT INTO `sys_depts` (`id`,`parent_id`,`ancestors`,`name`,`code`,`sort`,`created_at`,`updated_at`) VALUES
  (1, 0, '0',   '总公司', 'DEPT-ROOT', 1, NOW(), NOW()),
  (2, 1, '0,1', '技术部', 'DEPT-TECH', 1, NOW(), NOW()),
  (3, 1, '0,1', '运营部', 'DEPT-OPS',  2, NOW(), NOW())
ON DUPLICATE KEY UPDATE `updated_at` = NOW();

INSERT INTO `sys_roles` (`id`,`name`,`code`,`data_scope`,`is_builtin`,`sort`,`remark`,`created_at`,`updated_at`) VALUES
  (1, '超级管理员', 'ROLE_SUPER',    1, 1, 1, '内置角色，拥有全部权限，不可编辑删除', NOW(), NOW()),
  (2, '部门主管',   'ROLE_DEPT_MGR', 2, 0, 2, '可见本部门及下属部门数据',             NOW(), NOW()),
  (3, '普通员工',   'ROLE_STAFF',    4, 0, 3, '仅可见本人数据',                       NOW(), NOW())
ON DUPLICATE KEY UPDATE `updated_at` = NOW();
