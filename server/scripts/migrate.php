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
 * 那个目录只在**数据卷为空**时执行一次，之后新增的表在已有环境上永远不会被创建，
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

$tables = count(Db::conn()->select('SHOW TABLES'));
echo "  ✓ 表结构已对齐（{$created} 条建表语句，当前 {$tables} 张表）\n";
