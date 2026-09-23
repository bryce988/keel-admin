#!/bin/sh
# AI 助手「小k」验收：权限矩阵 · 身份不串号 · 流式链路 · 停止 · 配额 · 故障 · 审计
#
#   sh scripts/ai-acceptance.sh
#
# 跑的是**模拟的 DeepSeek**（server/scripts/ai-mock-deepseek.php），不花钱、结果可重复。
# 前置（一次性）：
#   1. .env 里设 DEEPSEEK_BASE_URL=http://127.0.0.1:9999，然后 docker compose up -d server
#   2. 脚本会自己在容器里拉起模拟服务，并临时把 ai.enabled / ai.deepseek.apiKey 改成测试值，跑完还原
#
# 设计原则同 scripts/acceptance.sh：全程走 HTTP 断言；只在造前置条件时改库，且每一处都还原。
# ⚠️ 会临时改角色权限与系统参数，**不要对生产环境跑**。
set -u
BASE=${BASE:-http://localhost:8787}
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DB_USER=${DB_USERNAME:-keel}
DB_PASS=${DB_PASSWORD:-keel123456}
DB_NAME=${DB_DATABASE:-keel}
PASS=0; FAIL=0

ok()   { PASS=$((PASS+1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31m✗\033[0m %s\n' "$1"; }
chk()  { [ "$2" = "$3" ] && ok "$1 ($2)" || bad "$1 期望=$3 实际=$2"; }
has()  { case "$2" in *"$3"*) ok "$1";; *) bad "$1（没有「$3」）: $(printf '%s' "$2" | head -c 300)";; esac; }
hasnt(){ case "$2" in *"$3"*) bad "$1（不该有「$3」）: $(printf '%s' "$2" | head -c 300)";; *) ok "$1";; esac; }

dc()   { (cd "$ROOT" && docker compose "$@"); }
sql()  { dc exec -T mysql mysql -u"$DB_USER" -p"$DB_PASS" --default-character-set=utf8mb4 -N "$DB_NAME" -e "$1" 2>/dev/null; }
redis(){ dc exec -T redis redis-cli --no-raw "$@" | tr -d '"\r'; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
json() { python3 -c "import sys,json;d=json.load(sys.stdin);print($1)" 2>/dev/null; }

login() {
  KEY=$(curl -s "$BASE/admin/auth/captcha" | json 'd["captcha_key"]')
  CODE=$(redis GET "$KEY" | tr -d '\n')
  curl -s -X POST "$BASE/admin/auth/login" -H 'Content-Type: application/json' \
    -d "{\"username\":\"$1\",\"password\":\"$2\",\"captcha_key\":\"$KEY\",\"captcha_code\":\"$CODE\"}" | json 'd.get("access_token","")'
}

bump()   { sql "UPDATE sys_users SET perm_version=perm_version+1 WHERE id IN (SELECT user_id FROM sys_user_roles WHERE role_id=$1);"; }
pid()    { sql "SELECT id FROM sys_permissions WHERE perm_code='$1' LIMIT 1"; }
grant()  { sql "INSERT IGNORE INTO sys_role_permissions (role_id,permission_id) VALUES ($1,$2);"; bump $1; }
revoke() { sql "DELETE FROM sys_role_permissions WHERE role_id=$1 AND permission_id=$2;"; bump $1; }
param()  { sql "UPDATE sys_params SET param_value='$2' WHERE param_key='$1';"; redis DEL "param:$1" >/dev/null; }
getparam() { sql "SELECT param_value FROM sys_params WHERE param_key='$1'"; }

# 提问，打印 run_id（失败时打印 HTTP 码与业务码）
ask() {
  curl -s -o /tmp/ai_ask.json -w '%{http_code}' -X POST "$BASE/admin/ai/messages" \
    -H "Authorization: Bearer $1" -H 'Content-Type: application/json' \
    -d "{\"content\":\"$2\"}" > /tmp/ai_ask.code
  if [ "$(cat /tmp/ai_ask.code)" = "202" ]; then json 'd["run_id"]' < /tmp/ai_ask.json
  else printf 'HTTP%s:%s' "$(cat /tmp/ai_ask.code)" "$(json 'd.get("code")' < /tmp/ai_ask.json)"; fi
}
# 等 run 结束，打印最终状态（2 完成 3 停止 4 失败）
# ⚠️ sh 没有局部变量：循环计数不能叫 i，否则会把调用方循环里的 i 重置掉（踩过：死循环跑出两千多次问答）
wait_run() {
  _w=0
  while [ $_w -lt 60 ]; do
    S=$(sql "SELECT status FROM ai_runs WHERE id=$1")
    [ -n "$S" ] && [ "$S" -ge 2 ] && { echo "$S"; return; }
    sleep 0.5; _w=$((_w+1))
  done
  echo "timeout"
}
answer()     { sql "SELECT m.content FROM ai_runs r JOIN im_messages m ON m.id=r.answer_msg_id WHERE r.id=$1"; }
answer_ext() { sql "SELECT m.extra FROM ai_runs r JOIN im_messages m ON m.id=r.answer_msg_id WHERE r.id=$1"; }
users_total(){ curl -s "$BASE/admin/users?status=1&page_size=1" -H "Authorization: Bearer $1" | json 'd["total"]'; }

echo "════ 0. 前置 ════"
URL=$(dc exec -T server sh -c 'echo $DEEPSEEK_BASE_URL' | tr -d '\r')
if [ "$URL" != "http://127.0.0.1:9999" ]; then
  echo "  容器里的 DEEPSEEK_BASE_URL=[$URL]，要指向模拟服务才能跑："
  echo "  在 .env 里设 DEEPSEEK_BASE_URL=http://127.0.0.1:9999 后 docker compose up -d server"
  exit 1
fi
# 用 exec -d 起：在 `sh -c '… &'` 里起的话，exec 会话一结束进程就被带走了
if ! dc exec -T server pgrep -f ai-mock-deepseek >/dev/null 2>&1; then
  dc exec -d server php -S 127.0.0.1:9999 scripts/ai-mock-deepseek.php
  sleep 1
fi
chk "模拟服务在线" "$(dc exec -T server sh -c 'curl -s -o /dev/null -w %{http_code} -H "Authorization: Bearer sk-mock" http://127.0.0.1:9999/user/balance' | tr -d '\r')" 200

OLD_ENABLED=$(getparam ai.enabled); OLD_KEY=$(getparam ai.deepseek.apiKey); OLD_QUOTA=$(getparam ai.quota.daily)
param ai.enabled 1; param ai.deepseek.apiKey sk-mock; param ai.quota.daily 1000
# 清掉测试账号今天的配额计数：脚本一次要问二十来个问题，跑两遍就会撞上默认的每日 50 次
for u in 1 2 3; do redis DEL "ai:quota:$u:$(date +%Y%m%d)" >/dev/null; done
AI_USE=$(pid ai:use)

ADMIN=$(login admin admin123); MGR=$(login manager demo123456); DEV=$(login dev01 demo123456)
[ -n "$ADMIN" ] && [ -n "$MGR" ] && [ -n "$DEV" ] && ok "三个账号登录" || { bad "登录失败"; exit 1; }

echo "════ 1. 入口与功能权限 ════"
chk "普通员工没有 ai:use → 会话接口 403"   "$(code -H "Authorization: Bearer $DEV" $BASE/admin/ai/conversation)" 403
chk "普通员工的未读汇总里没有小k"          "$(curl -s $BASE/admin/chat/unread -H "Authorization: Bearer $DEV" | json 'd["ai"]')" None
chk "超管的未读汇总里有小k"               "$(curl -s $BASE/admin/chat/unread -H "Authorization: Bearer $ADMIN" | json 'd["ai"] is not None')" True
chk "部门主管调审计接口 403"              "$(code -H "Authorization: Bearer $MGR" $BASE/admin/ai/runs)" 403
chk "部门主管调测试连接 403"              "$(code -X POST -H "Authorization: Bearer $MGR" $BASE/admin/ai/provider/test)" 403
chk "超管测试连接 → 可用 + 余额"          "$(curl -s -X POST $BASE/admin/ai/provider/test -H "Authorization: Bearer $ADMIN" | json 'd["balances"][0]["total_balance"]')" 88.80

grant 2 "$AI_USE"; grant 3 "$AI_USE"
chk "授权后普通员工能打开小k（不用重新登录）" "$(code -H "Authorization: Bearer $DEV" $BASE/admin/ai/conversation)" 200

echo "════ 2. 数据权限：同一个问题，不同的人不同的答案 ════"
R_ADMIN=$(ask "$ADMIN" "公司在职的有多少人？"); S=$(wait_run "$R_ADMIN")
chk "超管问答完成" "$S" 2
A_TXT=$(answer "$R_ADMIN")
has   "超管的数字 = 他在用户列表看到的 total" "$A_TXT" "共 $(users_total "$ADMIN") 条"
hasnt "超管不带口径说明" "$A_TXT" "可见范围"

R_MGR=$(ask "$MGR" "公司在职的有多少人？"); S=$(wait_run "$R_MGR")
chk "部门主管问答完成" "$S" 2
M_TXT=$(answer "$R_MGR")
has "部门主管的数字 = 他在用户列表看到的 total" "$M_TXT" "共 $(users_total "$MGR") 条"
has "部门主管的回答带口径" "$M_TXT" "技术部及下属部门"
hasnt "部门主管的分组里没有范围外的部门" "$M_TXT" "运营部"
M_EXT=$(answer_ext "$R_MGR")
has   "链接：有权限的页面保留（/system/role）" "$M_EXT" "/system/role"
hasnt "链接：没权限的页面被服务端剔除（/log/ai）" "$M_EXT" "/log/ai"
has   "链接：工具生成的列表链接带筛选条件" "$M_EXT" '"status": "1"'

R_DEV=$(ask "$DEV" "公司在职的有多少人？"); S=$(wait_run "$R_DEV")
chk "普通员工问答完成（没权限不是失败）" "$S" 2
has "普通员工被告知需要『用户管理』权限" "$(answer "$R_DEV")" "用户管理"
chk "审计里记了一条被拒的工具调用" "$(sql "SELECT COUNT(*) FROM ai_tool_calls WHERE run_id=$R_DEV AND denied=1")" 1

echo "════ 3. 身份不串号（消费进程常驻，前后脚的两个人不能互相顶替）════"
for _round in 1 2 3 4; do
  RA=$(ask "$ADMIN" "最近一周有谁登录失败过"); RD=$(ask "$DEV" "最近一周有谁登录失败过")
  wait_run "$RA" >/dev/null; wait_run "$RD" >/dev/null
done
chk "所有工具调用的执行身份 = 提问人" \
  "$(sql "SELECT COUNT(*) FROM ai_tool_calls c JOIN ai_runs r ON r.id=c.run_id WHERE c.acting_user_id <> r.user_id")" 0
chk "普通员工那几次全部被拒（没拿到超管的日志权限）" \
  "$(sql "SELECT COUNT(*) FROM ai_tool_calls c JOIN ai_runs r ON r.id=c.run_id WHERE r.user_id=3 AND c.tool='search_login_logs' AND c.denied=0")" 0
chk "超管那几次都查到了" \
  "$(sql "SELECT COUNT(*) FROM ai_tool_calls c JOIN ai_runs r ON r.id=c.run_id WHERE r.user_id=1 AND c.tool='search_login_logs' AND c.denied=1")" 0

echo "════ 4. 流式链路与上下文 ════"
has "被切在多字节字符中间的 SSE 事件解析正确" "$A_TXT" "【完】"
R2=$(ask "$ADMIN" "那公告呢"); wait_run "$R2" >/dev/null
N=$(dc exec -T server cat /tmp/mock_n | tr -d '\r')
# 本次问答的第一个请求 = 最后一个请求往前数 1（第二轮是工具结果后的收尾）
FIRST=$(dc exec -T server cat "/tmp/mock_req_$((N-1)).json")
has "追问时带上了折叠的历史" "$FIRST" "此前的对话"
chk "历史折叠进 user 消息，消息数组里没有历史 assistant 回合" \
  "$(printf '%s' "$FIRST" | json 'len(d["messages"])')" 2
chk "带工具的第二轮回传了 reasoning_content（否则模拟服务 400）" "$(wait_run "$R2")" 2
curl -s -o /dev/null -X POST $BASE/admin/ai/reset -H "Authorization: Bearer $ADMIN"
R3=$(ask "$ADMIN" "你好"); wait_run "$R3" >/dev/null
N=$(dc exec -T server cat /tmp/mock_n | tr -d '\r')
hasnt "「新对话」之后不再带历史" "$(dc exec -T server cat "/tmp/mock_req_$N.json")" "此前的对话"
has   "不需要查数据的回答里 [[page:]] 被换成链接" "$(answer_ext "$R3")" "/config/param"

echo "════ 5. 并发、停止 ════"
R_SLOW=$(ask "$ADMIN" "慢慢说"); sleep 1.5
chk "上一个没答完再问 → 409 + 21303" "$(ask "$ADMIN" "插队")" "HTTP409:21303"
chk "别人不能停我的问答 → 404" "$(code -X POST -H "Authorization: Bearer $DEV" $BASE/admin/ai/runs/$R_SLOW/cancel)" 404
chk "停止 → 204" "$(code -X POST -H "Authorization: Bearer $ADMIN" $BASE/admin/ai/runs/$R_SLOW/cancel)" 204
chk "run 状态变成已停止" "$(wait_run "$R_SLOW")" 3
has "已输出的部分保留" "$(answer "$R_SLOW")" "慢慢"
has "消息标注 stopped" "$(answer_ext "$R_SLOW")" '"stopped"'

echo "════ 6. 小k 会话不走聊天接口 ════"
CONV=$(curl -s $BASE/admin/ai/conversation -H "Authorization: Bearer $ADMIN" | json 'd["id"]')
chk "聊天发送接口往小k 会话里发 → 400" "$(code -X POST -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' -d '{"content":"hi"}' $BASE/admin/chat/conversations/$CONV/messages)" 400
chk "小k 会话不能设置免打扰 → 400" "$(code -X PUT -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' -d '{"is_muted":true}' $BASE/admin/chat/conversations/$CONV/settings)" 400
chk "小k 会话不在普通会话列表里" "$(curl -s $BASE/admin/chat/conversations -H "Authorization: Bearer $ADMIN" | json "len([c for c in d if c['type']==3])")" 0
chk "别人读不到我的小k 会话 → 404" "$(code -H "Authorization: Bearer $DEV" $BASE/admin/chat/conversations/$CONV/messages)" 404

echo "════ 7. 模型编造工具名 ════"
R_FAKE=$(ask "$ADMIN" "编造一个工具"); wait_run "$R_FAKE" >/dev/null
has "不存在的工具不会被执行" "$(answer "$R_FAKE")" "没有名为 delete_all_users 的工具"

echo "════ 8. 配额与故障 ════"
Q_KEY="ai:quota:3:$(date +%Y%m%d)"; USED=$(redis GET "$Q_KEY" | tr -d '\n')
param ai.quota.daily "$USED"
chk "配额用完 → 429 + 21304" "$(ask "$DEV" "再问一个")" "HTTP429:21304"
param ai.quota.daily 1000

param ai.deepseek.apiKey sk-wrong
BEFORE=$(redis GET "ai:quota:1:$(date +%Y%m%d)" | tr -d '\n')
R_BAD=$(ask "$ADMIN" "你好"); chk "密钥错 → 问答失败" "$(wait_run "$R_BAD")" 4
chk "记下 HTTP 401" "$(sql "SELECT http_status FROM ai_runs WHERE id=$R_BAD")" 401
has "给用户的话是「密钥无效，请联系管理员」" "$(answer "$R_BAD")" "密钥无效"
chk "服务商故障返还配额" "$(redis GET "ai:quota:1:$(date +%Y%m%d)" | tr -d '\n')" "$BEFORE"
chk "测试连接显示失败原因" "$(curl -s -X POST $BASE/admin/ai/provider/test -H "Authorization: Bearer $ADMIN" | json 'd["ok"]')" False
param ai.deepseek.apiKey sk-mock

param ai.enabled 0
chk "总开关关闭 → 会话接口 400 + 21301" "$(curl -s $BASE/admin/ai/conversation -H "Authorization: Bearer $ADMIN" | json 'd["code"]')" 21301
chk "总开关关闭 → 未读汇总里没有小k" "$(curl -s $BASE/admin/chat/unread -H "Authorization: Bearer $ADMIN" | json 'd["ai"]')" None
param ai.enabled 1

echo "════ 9. 审计 ════"
DETAIL=$(curl -s $BASE/admin/ai/runs/$R_MGR -H "Authorization: Bearer $ADMIN")
chk "审计详情有工具调用明细" "$(printf '%s' "$DETAIL" | json 'len(d["tool_calls"])')" 1
hasnt "审计详情不含对话内容" "$DETAIL" "查询结果"
chk "审计列表能看到全公司的问答" "$(curl -s "$BASE/admin/ai/runs?page_size=1" -H "Authorization: Bearer $ADMIN" | json 'd["total"] >= 10')" True
# 只看代码行：注释里写「禁止 withoutDataScope」是在说规矩，不是违反规矩
chk "工具与 AI 层代码里没有绕过数据权限的写法" \
  "$(grep -rnE 'withoutDataScope|withoutGlobalScope|Db::table|DB::select|Db::conn' "$ROOT/server/app/admin/ai" "$ROOT/server/app/common/ai" \
     | grep -vE '^[^:]+:[0-9]+:\s*(\*|//|/\*)' | wc -l | tr -d ' ')" 0

echo "════ 还原 ════"
revoke 2 "$AI_USE"; revoke 3 "$AI_USE"
param ai.enabled "$OLD_ENABLED"; param ai.deepseek.apiKey "$OLD_KEY"; param ai.quota.daily "$OLD_QUOTA"
echo "  (已还原角色授权与 AI 参数)"

echo
printf '════ 结果：\033[32m通过 %d\033[0m  \033[31m失败 %d\033[0m ════\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
