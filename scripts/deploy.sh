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
compose() { docker compose -f "$COMPOSE_FILE" "$@"; }

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

# ------------------------------------------------------------------
# 构建镜像
#
# 后端代码与 composer 依赖都烤进镜像（docker/php/Dockerfile.prod），
# 所以「重启服务」= 换一个新镜像的容器，而不是让常驻进程重新读磁盘上的文件。
#
# 以前这里要显式 `restart server`：代码是 bind mount 的，镜像内容没变时
# compose 认为容器是最新的、不会重建，不 restart 就还在跑旧代码。
# 现在代码变了镜像摘要就变，compose 自己会重建容器，那条特判连同它的
# `--force` 开关一起去掉了——少一个「忘了加参数就发了个假版本」的坑。
# ------------------------------------------------------------------
echo "▸ 构建镜像..."
compose build

# ------------------------------------------------------------------
# 起服务
#
# `migrate` 是一次性服务（migrate → install → seed），server 在编排里声明了
# `service_completed_successfully` 依赖它，所以 `up -d` 会先把它跑完再起 server。
# 初始化失败 → migrate 非零退出 → server 根本不会启动，发布就地中止。
# 这正是想要的：残缺的权限树比服务起不来更难排查（登录进去到处 403）。
#
# 实测：**每次 `up -d` 都会重跑 migrate**（compose 会把已退出的一次性容器重新拉起），
# 不是只在镜像变了的时候跑。三步都是幂等的，重跑一遍约 1 秒，
# 换来的是「表结构与种子数据每次发版都对齐」——比省这一秒值。
# ------------------------------------------------------------------
echo "▸ 启动服务（含一次性初始化）..."
if ! compose up -d --remove-orphans; then
  echo "✗ 启动失败，初始化日志："
  compose logs --no-color --tail 80 migrate || true
  exit 1
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

compose ps --format "table {{.Service}}\t{{.Status}}"
echo "▸ 部署完成"
