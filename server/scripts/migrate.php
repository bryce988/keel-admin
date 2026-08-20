<?php

declare(strict_types=1);

/**
 * 建表（幂等）
 *
 *   php scripts/migrate.php
 *
 * 执行 database/schema.sql，里面全部是 CREATE TABLE IF NOT EXISTS 与
 * INSERT ... ON DUPLICATE KEY，重复执行安全。
 *
 * 为什么不只依赖 MySQL 容器的 /docker-entrypoint-initdb.d：
 * 那个目录只在数据卷为空时执行一次，之后新增的表在已有环境上永远不会被创建，
 * 线上尤其致命。所以每次进程启动都对齐一次表结构。
 *
 * 注意这只是脚手架阶段的做法——表一旦有存量数据、需要改列或加索引时，
 * 应该换成正式的迁移工具（phinx / doctrine-migrations），保留可回滚的版本历史。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use app\common\support\Db;

$file = __DIR__ . '/../database/schema.sql';

if (!is_file($file)) {
    fwrite(STDERR, "✗ 找不到建表脚本：{$file}\n");
    exit(1);
}

// 等数据库就绪
$retry = 0;
while (true) {
    try {
        Db::conn()->getPdo();
        break;
    } catch (Throwable $e) {
        if (++$retry > 30) {
            fwrite(STDERR, "✗ 数据库连接失败：{$e->getMessage()}\n");
            exit(1);
        }
        sleep(1);
    }
}

$sql = (string) file_get_contents($file);

// 去掉整行注释后按分号切分；schema.sql 里没有存储过程，不存在语句内分号
$sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
$statements = array_filter(array_map('trim', explode(';', $sql)));

$created = 0;
foreach ($statements as $statement) {
    try {
        Db::conn()->unprepared($statement);
        if (stripos($statement, 'CREATE TABLE') !== false) {
            $created++;
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "✗ 执行失败：{$e->getMessage()}\n");
        fwrite(STDERR, '  语句：' . mb_substr($statement, 0, 120) . "…\n");
        exit(1);
    }
}

/**
 * 存量表的列补丁
 *
 * `CREATE TABLE IF NOT EXISTS` 只管建表：表已经存在时，schema.sql 里新加的列
 * 一个字都不会生效。开发机上 `down -v` 重来看不出问题，线上却是静默漏列，
 * 直到某个字段一直是默认值才被发现。
 *
 * MySQL 8 没有 `ADD COLUMN IF NOT EXISTS`（那是 MariaDB 的扩展），
 * 所以先查 information_schema 再决定加不加。
 *
 * ⚠️ 这仍然是脚手架阶段的权宜之计：只处理「加列」，不处理改类型、删列、
 * 数据迁移与回滚。表结构一旦开始频繁演进，就该换成 phinx 这类正式迁移工具。
 *
 * 每项：[表, 列, 列定义, 加完之后跑一次的回填 SQL（可为 null）]
 */
$columnPatches = [
    [
        'sys_login_logs',
        'dept_id',
        "BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '登录人部门，日志本身也受数据权限约束' AFTER `username`",
        // 没有这一列时，数据权限对登录日志整个失效（DataScope 找不到部门列就直接放行），
        // 部门主管能看到全公司的登录记录。所以补列之后必须立刻回填历史数据
        'UPDATE `sys_login_logs` l JOIN `sys_users` u ON u.id = l.user_id
            SET l.dept_id = u.dept_id WHERE l.user_id > 0 AND l.dept_id = 0',
    ],
    [
        'sys_users',
        'token_version',
        "INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会话版本号，改密/重置密码时递增使该用户全部令牌失效' AFTER `perm_version`",
        // 不需要回填：默认 0 与令牌里缺省的 tv=0 相等，存量会话不受影响。
        // 反过来说，补列不会把线上在线用户踢下去
        null,
    ],
];

$database = Db::conn()->getDatabaseName();
$patched  = 0;

foreach ($columnPatches as [$table, $column, $definition, $backfill]) {
    $exists = Db::conn()->selectOne(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        [$database, $table, $column]
    );

    if ($exists) {
        continue;
    }

    try {
        Db::conn()->unprepared("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        if ($backfill !== null) {
            Db::conn()->unprepared($backfill);
        }
        $patched++;
        echo "  ✓ 补列 {$table}.{$column}\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "✗ 补列失败 {$table}.{$column}：{$e->getMessage()}\n");
        exit(1);
    }
}

$tables = count(Db::conn()->select('SHOW TABLES'));
echo "  ✓ 表结构已对齐（{$created} 条建表语句"
    . ($patched > 0 ? "，{$patched} 处补列" : '')
    . "，当前 {$tables} 张表）\n";
