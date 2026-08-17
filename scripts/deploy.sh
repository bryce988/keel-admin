#!/usr/bin/env bash
#
# Keel 服务器部署脚本
#
# 在服务器上执行，从 Git 拉取最新代码并重建服务：
#   cd /opt/keel && ./scripts/deploy.sh
#
# 首次部署见 README「部署到服务器」。
set -euo pipefail

cd "$(dirname "$0")/.."
COMPOSE_FILE=docker-compose.prod.yml

echo "▸ 当前版本：$(git rev-parse --short HEAD) $(git log -1 --pretty=%s)"

if [ ! -f .env ]; then
  echo "✗ 缺少 .env，请先按 .env.example 创建并填入生产配置"
  exit 1
fi

echo "▸ 拉取最新代码..."
git fetch --all --quiet
BEFORE=$(git rev-parse HEAD)
git reset --hard origin/main --quiet     # 服务器不做本地修改，直接对齐远端
AFTER=$(git rev-parse HEAD)

if [ "$BEFORE" = "$AFTER" ]; then
  echo "  已是最新，无代码变更"
else
  echo "  更新到：$(git rev-parse --short HEAD) $(git log -1 --pretty=%s)"
  git log --oneline "$BEFORE..$AFTER" | sed 's/^/    /'
fi

echo "▸ 重建并启动..."
docker compose -f "$COMPOSE_FILE" up -d --build

# ------------------------------------------------------------------
# 显式重启后端
#
# PHP 代码是 bind mount 进容器的，镜像内容没变时 `up -d --build` 不会重建
# server 容器，而 webman 是常驻内存进程——不重启就还在跑旧代码。
# 之前能生效是因为 Monitor 进程监听到文件变化自己 reload 了，
# 但那是不确定的：监听进程挂了，或改的是 config/ 这类必须 restart 才生效的
# 文件，脚本都会「部署成功」而实际跑的是旧代码。
#
# restart 会重跑 entrypoint（migrate → install → seed 都是幂等的），
# 顺带保证表结构与种子数据也对齐。
# ------------------------------------------------------------------
if [ "$BEFORE" != "$AFTER" ] || [ "${1:-}" = "--force" ]; then
  echo "▸ 重启后端进程..."
  docker compose -f "$COMPOSE_FILE" restart server
else
  echo "▸ 无代码变更，跳过重启（需要强制重启用：./scripts/deploy.sh --force）"
fi

echo "▸ 等待服务就绪..."
for i in $(seq 1 30); do
  if curl -sf -m 3 "http://127.0.0.1:${PORT_HTTP:-8080}/admin/ping" >/dev/null 2>&1; then
    echo "  ✓ 服务已就绪"
    break
  fi
  [ "$i" = 30 ] && { echo "  ✗ 超时，请检查：docker compose -f $COMPOSE_FILE logs server"; exit 1; }
  sleep 5
done

echo "▸ 清理无用镜像..."
docker image prune -f >/dev/null

docker compose -f "$COMPOSE_FILE" ps --format "table {{.Service}}\t{{.Status}}"
echo "▸ 部署完成"
