<?php

declare(strict_types=1);

/**
 * 初始化脚本（幂等）
 *
 * 创建初始管理员账号并授予超级管理员角色。
 * 已存在则跳过，可重复执行。
 *
 *   php scripts/install.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use app\common\support\Db;
use app\common\support\Env;

$username = (string) Env::get('ADMIN_USERNAME', 'admin');
$password = (string) Env::get('ADMIN_PASSWORD', 'admin123');

// 等待数据库就绪（容器编排下 MySQL 可能刚起来还没建完表）
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

$exists = Db::table('sys_users')->where('username', $username)->first();

if ($exists) {
    echo "  管理员 {$username} 已存在，跳过初始化\n";
    exit(0);
}

$now = date('Y-m-d H:i:s');

Db::transaction(function () use ($username, $password, $now) {
    $userId = Db::table('sys_users')->insertGetId([
        'username'     => $username,
        'password'     => password_hash($password, PASSWORD_DEFAULT),
        'real_name'    => '系统管理员',
        'dept_id'      => 1,
        'status'       => 1,
        'is_super'     => 1,
        'perm_version' => 0,
        'remark'       => '初始化创建',
        'created_at'   => $now,
        'updated_at'   => $now,
        // pwd_updated_at 留空 → 前端提示首次登录建议修改密码
    ]);

    Db::table('sys_user_roles')->insertOrIgnore([
        'user_id' => $userId,
        'role_id' => 1,   // ROLE_SUPER
    ]);
});

echo "  ✓ 已创建管理员账号\n";
echo "    账号：{$username}\n";
echo "    密码：{$password}\n";
echo "    ⚠ 生产环境请立即修改，并在 .env 中移除 ADMIN_PASSWORD\n";
