#!/bin/sh
set -e
cd /app

# ------------------------------------------------------------------
# 首次启动：从上游拉取 webman 骨架，只补齐仓库里没有的框架文件
# （start.php、support/、public/ 等），仓库中已有的 app/ config/ 优先
# ------------------------------------------------------------------
if [ ! -f start.php ]; then
  echo "▸ 首次启动，拉取 webman 骨架..."
  rm -rf /tmp/webman
  composer create-project workerman/webman:~2.0 /tmp/webman \
      --no-interaction --no-scripts --no-install

  # BusyBox 的 cp 没有 -n，这里显式判断，避免覆盖仓库代码
  cd /tmp/webman
  find . -path ./vendor -prune -o -type f -print | while read -r f; do
    if [ ! -e "/app/$f" ]; then
      mkdir -p "/app/$(dirname "$f")"
      cp "$f" "/app/$f"
    fi
  done
  cd /app
  rm -rf /tmp/webman

  if [ ! -f start.php ]; then
    echo "✗ 骨架拉取失败，请检查网络或 composer 镜像源"
    exit 1
  fi
  echo "▸ 骨架就位"
fi

# ------------------------------------------------------------------
# 依赖：vendor 缺失或 autoload 依赖的文件缺失时重新安装
# ------------------------------------------------------------------
if [ ! -f vendor/autoload.php ]; then
  echo "▸ 安装依赖..."
  composer install --no-interaction
elif [ ! -f vendor/composer/autoload_files.php ] || ! php -r 'require "vendor/autoload.php";' 2>/dev/null; then
  echo "▸ 重建 autoload..."
  composer dump-autoload --no-interaction
fi

# ------------------------------------------------------------------
# 初始化：建管理员账号（幂等，已存在则跳过）
# ------------------------------------------------------------------
echo "▸ 检查初始化数据..."
php scripts/install.php || echo "  初始化脚本执行失败，可稍后手动运行：docker compose exec server php scripts/install.php"

echo "▸ 启动 webman（调试模式，改代码自动 reload）"
exec php start.php start
