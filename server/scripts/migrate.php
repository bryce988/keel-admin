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
 * ⚠️ 这仍然是脚手架阶段的权宜之计：这一段只处理「加列」，改类型与删列没有。
 * 下面另有索引补丁和数据补丁两段，同样是点名式的、不带回滚。
 * 表结构一旦开始频繁演进，就该换成 phinx 这类正式迁移工具。
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

/**
 * 存量表的索引补丁
 *
 * 与补列同一个道理，而且更容易被忽略：往 schema.sql 的 `CREATE TABLE` 里加一行 `KEY`，
 * 在开发机上 `down -v` 重建后一切正常，线上却因为表已存在而**一个索引都没加**。
 * 索引缺失不报错、不返回错数据，只是慢——数据量小的时候完全看不出来，
 * 等到看出来的时候已经是线上慢查询了。
 *
 * MySQL 8 没有 `CREATE INDEX IF NOT EXISTS`，照旧查 information_schema。
 *
 * 每项：[表, 索引名, 列定义或 null（null = 删除这个索引）]
 */
$indexPatches = [
    // 操作日志：日志查询固定带时间范围，数据权限再注入 dept_id。
    // 原有索引里 dept_id 一个都没有 —— 部门主管翻日志时只能靠主键倒扫全表过滤。
    //
    // ⚠️ 不写成 (dept_id, created_at, id)：InnoDB 的二级索引**隐式包含主键**，
    // 显式再加一列 id 是重复的，只会让索引定义看起来比实际复杂。
    //
    // 排序说明：列表默认 `ORDER BY id DESC`，这个索引服务不了排序，仍会 filesort。
    // 它挡的是「先把几百万行缩到本部门最近 7 天」这一步，以及分页的 COUNT(*)——
    // 那两步才是数据量上来后的主要成本。真要连排序一起吃掉，得把默认排序
    // 改成 created_at DESC，那是接口行为变更，要先改 docs/api.md 再动代码。
    ['sys_operation_logs', 'idx_dept_time', '(`dept_id`, `created_at`)'],

    // 登录日志：连一个能服务纯时间范围的索引都没有（idx_username_time 的最左列是
    // username，不带账号筛选时用不上）。EXPLAIN 里 possible_keys 直接是 NULL。
    ['sys_login_logs', 'idx_created',   '(`created_at`)'],
    ['sys_login_logs', 'idx_dept_time', '(`dept_id`, `created_at`)'],

    // 上一条加完之后，原来的单列 idx_dept 就是它的最左前缀，纯属重复。
    // 登录日志是只增表，每多一个索引就多一份写入开销，该删。
    ['sys_login_logs', 'idx_dept', null],
];

$indexed = 0;

foreach ($indexPatches as [$table, $index, $columns]) {
    $exists = Db::conn()->selectOne(
        'SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
        [$database, $table, $index]
    );

    // 要加的已经在 / 要删的已经不在，两种情况都无事可做
    if (($columns !== null) === (bool) $exists) {
        continue;
    }

    try {
        Db::conn()->unprepared($columns === null
            ? "ALTER TABLE `{$table}` DROP INDEX `{$index}`"
            : "ALTER TABLE `{$table}` ADD INDEX `{$index}` {$columns}");
        $indexed++;
        echo '  ✓ ' . ($columns === null ? '删除' : '补') . "索引 {$table}.{$index}\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "✗ 索引调整失败 {$table}.{$index}：{$e->getMessage()}\n");
        exit(1);
    }
}

/**
 * 存量数据的补丁
 *
 * 与补列、补索引同源的问题，但更隐蔽：枚举的**取值范围**变窄时，`CREATE TABLE`
 * 和 `ALTER TABLE` 都管不着已经写进去的行。开发机 `down -v` 重来永远看不到，
 * 线上则是「有几个人的状态列渲染成空白」——前端拿 value 去字典里查不到 label。
 *
 * 每项：[说明, 检测 SQL（有结果才执行）, 检测参数, 要执行的语句数组]
 * 检测条件必须写成「打完补丁后就不再成立」，重复执行才是安全的。
 */
$dataPatches = [
    [
        'sys_users.status 归并为两档',
        // 判据是「列注释还不等于目标那句」，不是「注释里含试用期」。
        //
        // 两个理由。其一，一行 status=2 都没有的库，注释同样要更新，
        // 否则 schema.sql 与线上表的字段注释会长期对不上（database.md 要求枚举列注释列全取值）。
        // 其二是开发时现踩的：按**旧值**匹配的判据，只要目标那句本身再改一次，
        // 已经打过补丁的库就再也追不上——旧值不在了，判据永远不成立。
        // 能自愈的判据必须认「要到哪去」，不是「从哪来」。
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'sys_users'
            AND COLUMN_NAME = 'status' AND COLUMN_COMMENT <> '0停用 1启用' LIMIT 1",
        null,
        [
            // 试用期在鉴权上一直等同于在职（AuthService 只判 status === 0），
            // 所以并到 1 不改变任何人的登录与权限，只是把标记去掉
            'UPDATE `sys_users` SET `status` = 1 WHERE `status` NOT IN (0, 1)',
            "ALTER TABLE `sys_users`
                MODIFY COLUMN `status` TINYINT NOT NULL DEFAULT 1 COMMENT '0停用 1启用'",
        ],
    ],
];

$dataPatched = 0;

foreach ($dataPatches as [$label, $detect, $detectParams, $statements]) {
    $hit = Db::conn()->selectOne($detect, $detectParams ?? [$database]);

    if (!$hit) {
        continue;
    }

    try {
        foreach ($statements as $statement) {
            Db::conn()->unprepared($statement);
        }
        $dataPatched++;
        echo "  ✓ 数据补丁 {$label}\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "✗ 数据补丁失败 {$label}：{$e->getMessage()}\n");
        exit(1);
    }
}

$tables = count(Db::conn()->select('SHOW TABLES'));
echo "  ✓ 表结构已对齐（{$created} 条建表语句"
    . ($patched > 0 ? "，{$patched} 处补列" : '')
    . ($indexed > 0 ? "，{$indexed} 处索引调整" : '')
    . ($dataPatched > 0 ? "，{$dataPatched} 处数据补丁" : '')
    . "，当前 {$tables} 张表）\n";
