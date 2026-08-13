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
