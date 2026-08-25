#!/bin/sh
# 一次性初始化：对齐表结构 → 建管理员 → 播种权限点/字典/参数
#
# 由 docker-compose.prod.yml 里的 `migrate` 服务调用，跑完即退出，
# server 服务等它成功后才启动。三步都是幂等的，每次发版跑一遍即可。
#
# ⚠️ 三步**都会致命失败**，与开发 entrypoint 的 `|| echo 提示` 刻意不同：
# 一次性 job 里失败就该拦住发布。seed 半途失败留下的是残缺的权限树，
# 而权限点缺失的表现是「登录进去什么都点不了、到处 403」——
# 比服务起不来难排查得多。
set -e
cd /app

echo "▸ 对齐表结构..."
php scripts/migrate.php

echo "▸ 检查初始化数据..."
php scripts/install.php

echo "▸ 播种权限点、字典、参数..."
php scripts/seed.php ${SEED_DEMO:+--demo}

echo "▸ 初始化完成"
