#!/bin/sh
# 生产入口：只负责启动，不做任何「顺手初始化」
#
# 开发那份 entrypoint 会拉骨架、装依赖、建表、播种——那是为了「clone 完 up -d 就能跑」。
# 生产上这几件事各自的问题：
#   · composer install  依赖装在容器启动时 → 每次重启都可能装出不同的树
#   · migrate / seed    多副本同时启动会并发改表结构；失败还被 `|| echo` 吞掉，
#                       表现成「服务起来了但权限点是空的」，全站 403 却看不出原因
#
# 现在依赖烤进镜像，建表与播种交给独立的一次性 job（compose 里的 `migrate` 服务，
# 见 docker-compose.prod.yml），server 用 service_completed_successfully 等它跑完。
set -e
cd /app

# 依赖必须在镜像里，缺了说明镜像构建有问题——就地装会掩盖问题，直接退出
if [ ! -f vendor/autoload.php ]; then
  echo "✗ 镜像内没有 vendor/，说明构建阶段失败了。生产不做运行期 composer install。"
  exit 1
fi

echo "▸ 启动 webman（生产模式，文件监听已关闭）"
exec php start.php start
