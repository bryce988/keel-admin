#!/bin/bash
#
# 进程数逐档压测：回答「worker 数加到多少就不涨了」
#
# 用法：  ./scripts/bench-workers.sh [并发数] [每档重复次数]
#         ./scripts/bench-workers.sh 100 3      # 默认值
#
# 依赖开发环境已 up（docker compose up -d），会反复重启 server 容器。
# 结论与依据记在 server/config/process.php 的 count 注释里。
#
# ⚠️ 压测客户端跑在**容器网络内部**，不经宿主的 localhost:8787。
#    这不是讲究：在 macOS 上经端口转发打，Docker Desktop 会先于应用饱和
#    （实测 1874 vs 3406 RPS），各档 worker 数会压出一模一样的数字，
#    看着像「加进程没有收益」，其实量的是 Docker 而不是 webman。
#
set -u
ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

CONC=${1:-100}
REPEAT=${2:-3}
QUERY='/admin/users?page=1&page_size=20'   # 只读、无操作日志，是干净的读链路
BASE=http://localhost:8787
NET=$(docker inspect keel-server --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}}{{end}}' 2>/dev/null)

[ -z "$NET" ] && { echo "keel-server 没在跑，先 docker compose up -d"; exit 1; }

docker image inspect keel-bench >/dev/null 2>&1 || {
  echo "构建压测镜像 keel-bench…"
  printf 'FROM alpine:3.20\nRUN apk add --no-cache apache2-utils\n' | docker build -q -t keel-bench - >/dev/null
}

wait_ready() {
  for _ in $(seq 1 60); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/ping")" = "200" ] && return 0
    sleep 0.5
  done
  echo "服务未就绪"; return 1
}

# 登录取 token：验证码从 Redis 读明文，所以这脚本只能用于开发/预发
login() {
  curl -s -o /tmp/bench_cap.json "$BASE/admin/auth/captcha"
  local key code
  key=$(python3 -c 'import json;print(json.load(open("/tmp/bench_cap.json")).get("captcha_key",""))' 2>/dev/null)
  code=$(docker compose exec -T redis redis-cli --no-raw GET "$key" 2>/dev/null | tr -d '"\r\n')
  curl -s -o /tmp/bench_login.json -X POST "$BASE/admin/auth/login" -H 'Content-Type: application/json' \
    -d "{\"username\":\"${ADMIN_USERNAME:-admin}\",\"password\":\"${ADMIN_PASSWORD:-admin123}\",\"captcha_key\":\"$key\",\"captcha_code\":\"$code\"}"
  python3 -c 'import json;print(json.load(open("/tmp/bench_login.json")).get("access_token",""))' 2>/dev/null
}

ab_run() {  # $1=并发 $2=总请求 $3=token
  docker run --rm --network "$NET" keel-bench \
    ab -n "$2" -c "$1" -s 60 -H "Authorization: Bearer $3" "http://server:8787$QUERY" 2>/dev/null
}

# busybox 的 ps 把 RSS 打成 27m / 512k 这种带单位的值，
# 直接 awk '{s+=$1}' 会把 27m 当 27，算出来的内存差三个数量级
worker_mem() {
  docker compose exec -T server sh -c 'ps -o rss,args ax' 2>/dev/null | awk '
    /webman http/ && !/grep/ {
      v=$1; u=substr(v,length(v)); n=substr(v,1,length(v)-1)+0
      if (u=="g") k=n*1048576; else if (u=="m") k=n*1024; else if (u=="k") k=n; else k=$1+0
      c++; s+=k }
    END { if (c) printf "%d个 %.0fMB 均%.1fMB", c, s/1024, s/1024/c }'
}

CORES=$(docker compose exec -T server sh -c 'nproc' 2>/dev/null | tr -d '\r')
echo "核数=$CORES  并发=$CONC  每档重复=$REPEAT 次（取中位）"
printf '%-12s %-22s %-8s %s\n' 倍数 "RPS 各次" 中位 内存

for MULT in 1 2 4 8; do
  WC=$((CORES * MULT))
  WORKER_COUNT=$WC docker compose up -d --no-deps server >/dev/null 2>&1
  wait_ready || exit 1
  TOKEN=$(login)
  [ -z "$TOKEN" ] && { echo "登录失败，拿不到 token"; exit 1; }

  # 预热：worker 冷启动 17MB，跑一会儿才到稳态，不预热会把预热期算进结果
  ab_run 20 1000 "$TOKEN" >/dev/null

  RESULTS=()
  for _ in $(seq 1 "$REPEAT"); do
    RESULTS+=( "$(ab_run "$CONC" $((CONC * 80)) "$TOKEN" | awk '/Requests per second/{printf "%.0f", $4}')" )
  done
  MEDIAN=$(printf '%s\n' "${RESULTS[@]}" | sort -n | awk '{a[NR]=$1} END {print a[int((NR+1)/2)]}')
  printf '%-12s %-22s %-8s %s\n' "×$MULT ($WC)" "${RESULTS[*]}" "$MEDIAN" "$(worker_mem)"
done

# 复原成配置里的默认值
docker compose up -d --no-deps server >/dev/null 2>&1
wait_ready && echo "已复原为默认进程数（$(docker compose exec -T server sh -c 'ps ax' | grep -c "webman http") 个 worker）"
