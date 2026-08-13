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
  `status`         TINYINT         NOT NULL DEFAULT 1      COMMENT '0停用 1在职 2试用期',
  `is_super`       TINYINT(1)      NOT NULL DEFAULT 0      COMMENT '超级管理员，跳过权限校验',
  `perm_version`   INT UNSIGNED    NOT NULL DEFAULT 0      COMMENT '权限版本号，授权变更时递增使缓存失效',
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
  `ip`         VARCHAR(45)     NOT NULL DEFAULT ''     COMMENT '来源 IP',
  `location`   VARCHAR(64)     NOT NULL DEFAULT ''     COMMENT 'IP 归属地',
  `browser`    VARCHAR(64)     NOT NULL DEFAULT ''     COMMENT '浏览器',
  `os`         VARCHAR(64)     NOT NULL DEFAULT ''     COMMENT '操作系统',
  `type`       TINYINT         NOT NULL DEFAULT 1      COMMENT '1登录 2登出',
  `status`     TINYINT(1)      NOT NULL DEFAULT 1      COMMENT '1成功 0失败',
  `msg`        VARCHAR(255)    NOT NULL DEFAULT ''     COMMENT '失败原因',
  `created_at` DATETIME        NOT NULL                COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_username_time` (`username`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='登录日志';

-- ---------------------------------------------------------------- 基础数据
INSERT INTO `sys_depts` (`id`,`parent_id`,`ancestors`,`name`,`code`,`sort`,`created_at`,`updated_at`) VALUES
  (1, 0, '0',   '总公司',   'DEPT-ROOT', 1, NOW(), NOW()),
  (2, 1, '0,1', '技术部',   'DEPT-TECH', 1, NOW(), NOW()),
  (3, 1, '0,1', '运营部',   'DEPT-OPS',  2, NOW(), NOW())
ON DUPLICATE KEY UPDATE `updated_at` = NOW();

INSERT INTO `sys_roles` (`id`,`name`,`code`,`data_scope`,`is_builtin`,`sort`,`remark`,`created_at`,`updated_at`) VALUES
  (1, '超级管理员', 'ROLE_SUPER',    1, 1, 1, '内置角色，拥有全部权限，不可编辑删除', NOW(), NOW()),
  (2, '部门主管',   'ROLE_DEPT_MGR', 2, 0, 2, '可见本部门及下属部门数据',             NOW(), NOW()),
  (3, '普通员工',   'ROLE_STAFF',    4, 0, 3, '仅可见本人数据',                       NOW(), NOW())
ON DUPLICATE KEY UPDATE `updated_at` = NOW();

-- 菜单：登录后前端据此渲染侧边栏
INSERT INTO `sys_permissions`
  (`id`,`parent_id`,`name`,`type`,`perm_code`,`path`,`component`,`icon`,`visible`,`sort`,`created_at`,`updated_at`) VALUES
  (1, 0, '概览',     1, 'sys:dashboard',      '/',          'Layout',                      'Odometer', 1, 10, NOW(), NOW()),
  (2, 1, '系统概览', 2, 'sys:dashboard:view', '/dashboard', 'views/dashboard/index.vue',   'Odometer', 1, 10, NOW(), NOW()),
  (3, 0, '系统管理', 1, 'sys',                '/system',    'Layout',                      'Setting',  1, 90, NOW(), NOW()),
  (4, 3, '用户管理', 2, 'sys:user:list',      '/system/user', 'views/system/user/index.vue', 'User',   1, 10, NOW(), NOW())
ON DUPLICATE KEY UPDATE `updated_at` = NOW();

-- 部门主管、普通员工先给到概览
INSERT INTO `sys_role_permissions` (`role_id`,`permission_id`) VALUES
  (2,1),(2,2),(3,1),(3,2)
ON DUPLICATE KEY UPDATE `role_id` = VALUES(`role_id`);
