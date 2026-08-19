#!/bin/sh
# 验收脚本：权限矩阵 · 数据权限五范围 · 字段级权限 · 多端隔离 · 操作日志
#
#   sh scripts/acceptance.sh          # 对本地 docker compose 环境
#   BASE=http://线上:8080 sh ...      # 也可指向别的环境（需该环境的演示账号）
#
# 设计原则
#   · **全程走 HTTP**，不直接查库断言：直接改库验不到中间件、Scope、脱敏这些应用层逻辑
#   · 只在「造前置条件」时改库（临时调整角色、部门），且**每一处都还原**
#   · 不依赖种子数据之外的任何账号——历史遗留账号随时可能被清掉
#
# ⚠️ 会临时改动角色权限与部门归属，**不要对生产环境跑**。
# 登录要过图形验证码，脚本直接从 Redis 读明文——这也是它只能用于开发/预发的原因。
set -u
BASE=${BASE:-http://localhost:8787}
ROOT=/Users/huohuo/workspace/web/admin
PASS=0; FAIL=0

ok()   { PASS=$((PASS+1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31m✗\033[0m %s\n' "$1"; }
chk()  { [ "$2" = "$3" ] && ok "$1 ($2)" || bad "$1 期望=$3 实际=$2"; }

login() {
  CAP=$(curl -s "$BASE/admin/auth/captcha")
  KEY=$(printf '%s' "$CAP" | python3 -c 'import sys,json;print(json.load(sys.stdin)["captcha_key"])')
  CODE=$(cd $ROOT && docker compose exec -T redis redis-cli --no-raw GET "$KEY" | tr -d '"\r\n')
  curl -s -X POST "$BASE/admin/auth/login" -H 'Content-Type: application/json' \
    -d "{\"username\":\"$1\",\"password\":\"$2\",\"captcha_key\":\"$KEY\",\"captcha_code\":\"$CODE\"}" \
    | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d.get("access_token",""))'
}
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
total() { curl -s "$@" | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d.get("total","ERR"))'; }
sql()  { cd $ROOT && docker compose exec -T mysql mysql -ukeel -pkeel123456 --default-character-set=utf8mb4 -N keel -e "$1" 2>/dev/null; }
# 改完角色权限要顶 perm_version，否则命中的是 Redis 里的旧权限缓存
bump() { sql "UPDATE sys_users SET perm_version=perm_version+1 WHERE id IN (SELECT user_id FROM sys_user_roles WHERE role_id=$1);"; }
pid()  { sql "SELECT id FROM sys_permissions WHERE perm_code='$1' LIMIT 1"; }
grant()  { sql "INSERT IGNORE INTO sys_role_permissions (role_id,permission_id) VALUES ($1,$(echo $2));"; bump $1; }
revoke() { sql "DELETE FROM sys_role_permissions WHERE role_id=$1 AND permission_id=$2;"; bump $1; }

echo "════ 1. 多端隔离 ════"
chk "/admin/ping 可访问"          "$(code $BASE/admin/ping)" 200
chk "/client/ping 缺渠道头被拒"   "$(code $BASE/client/ping)" 400
chk "/client/ping 带渠道头"       "$(code -H 'X-Channel: h5' $BASE/client/ping)" 200
chk "/open/ping 可访问"           "$(code $BASE/open/ping)" 200
ADMIN=$(login admin admin123)
[ -n "$ADMIN" ] && ok "admin 登录" || { bad "admin 登录失败"; exit 1; }
chk "员工 token 调 C 端 → 401"    "$(code -H 'X-Channel: h5' -H "Authorization: Bearer $ADMIN" $BASE/client/v1/profile)" 401
A_BODY=$(curl -s $BASE/admin/profile); C_BODY=$(curl -s -H 'X-Channel: h5' $BASE/client/v1/profile)
echo "$A_BODY" | grep -q trace_id && ok "admin 错误体带 trace_id" || bad "admin 错误体缺 trace_id"
echo "$C_BODY" | grep -q trace_id && bad "client 错误体不该带 trace_id" || ok "client 错误体结构不同（无 trace_id）"

echo "════ 2. 越权拦截（前端隐藏≠安全边界）════"
DEV=$(login dev01 demo123456)
chk "dev01 调日志接口 → 403"      "$(code -H "Authorization: Bearer $DEV" $BASE/admin/logs/login)" 403
chk "dev01 调用户列表 → 403"      "$(code -H "Authorization: Bearer $DEV" $BASE/admin/users)" 403
chk "dev01 个人中心 → 200"        "$(code -H "Authorization: Bearer $DEV" $BASE/admin/profile)" 200
chk "无 token → 401"              "$(code $BASE/admin/users)" 401

echo "════ 3. 字段级权限（服务端脱敏，非前端打码）════"
MGR=$(login manager demo123456)
phone_of() { curl -s "$BASE/admin/users?page_size=50" -H "Authorization: Bearer $1" \
  | python3 -c 'import sys,json;d=json.load(sys.stdin);print([u["phone"] for u in d.get("list",[]) if u["username"]=="dev01"][0] if d.get("list") else "ERR")'; }
FIELD_PID=$(pid 'sys:field:user:phone')
# 部门主管默认**有**这个字段权限，所以先撤销才验得到脱敏
revoke 2 "$FIELD_PID"
P_MASKED=$(phone_of "$MGR")
case "$P_MASKED" in *\**) ok "无字段权限 → 服务端返回脱敏值 ($P_MASKED)";; *) bad "应脱敏却是明文: $P_MASKED";; esac
grant 2 "$FIELD_PID"
P_PLAIN=$(phone_of "$MGR")
case "$P_PLAIN" in *\**) bad "有字段权限却仍脱敏: $P_PLAIN";; *) ok "有字段权限 → 明文 ($P_PLAIN)，且同一 token 立即生效";; esac

echo "════ 4. 数据权限五种范围 ════"
echo "  部门树：总公司(1) → 技术部(2,2人) 运营部(3,1人) 营销部(7,1人)；总计 5 人"
chk "范围1 全部数据 (admin)"       "$(total -H "Authorization: Bearer $ADMIN" "$BASE/admin/users?page_size=50")" 5
chk "范围2 本部门及下级 (manager@技术部)" "$(total -H "Authorization: Bearer $MGR" "$BASE/admin/users?page_size=50")" 2
# 范围5「自定义部门」的角色是审计员(role 4, 自定义=总公司+技术部)。
# 不用它现有的成员——那是历史遗留账号，验收不该依赖它。
# 临时把 ops01(运营部) 挪进去：它自己所在的运营部**不在**自定义范围内，
# 所以它应当看不到自己，正好证明自定义范围不是「本部门 + 额外」而是完全替换
USER_LIST_PID=$(pid 'sys:user:list')
grant 4 "$USER_LIST_PID"
sql "UPDATE sys_user_roles SET role_id=4 WHERE user_id=(SELECT id FROM sys_users WHERE username='ops01');"
sql "UPDATE sys_users SET perm_version=perm_version+1 WHERE username='ops01';"
OPS=$(login ops01 demo123456)
chk "范围5 自定义(总公司+技术部)，且看不到自己所在的运营部" \
    "$(total -H "Authorization: Bearer $OPS" "$BASE/admin/users?page_size=50")" 3
revoke 4 "$USER_LIST_PID"
sql "UPDATE sys_user_roles SET role_id=3 WHERE user_id=(SELECT id FROM sys_users WHERE username='ops01');"
sql "UPDATE sys_users SET perm_version=perm_version+1 WHERE username='ops01';"
ok "已还原 ops01 的角色"
echo "  范围4 仅本人：dev01 无用户列表权限，改用「我的登录记录」验证"
MINE=$(total -H "Authorization: Bearer $DEV" "$BASE/admin/profile/logins?page_size=100")
DBMINE=$(sql "SELECT COUNT(*) FROM sys_login_logs WHERE user_id=3")
chk "范围4 仅本人 (dev01 登录记录)" "$MINE" "$DBMINE"

echo "  范围3 本部门不含下级：现有账号都在叶子部门，区分不出来 → 临时把主管角色挪到总公司"
sql "UPDATE sys_users SET dept_id=1 WHERE username='manager';"
MGR2=$(login manager demo123456)
T2=$(total -H "Authorization: Bearer $MGR2" "$BASE/admin/users?page_size=50")
chk "范围2 在总公司 → 全树 5 人"   "$T2" 5
curl -s -X PUT "$BASE/admin/roles/2/data-scope" -H "Authorization: Bearer $ADMIN" \
  -H 'Content-Type: application/json' -d '{"data_scope":3,"dept_ids":[]}' -o /dev/null
T3=$(total -H "Authorization: Bearer $MGR2" "$BASE/admin/users?page_size=50")
# 此刻总公司里有 admin + 临时挪来的 manager = 2 人；下级部门的 3 人应被排除
chk "范围3 在总公司 → 仅本部门 2 人（排除下级 3 人）" "$T3" 2
# 还原
curl -s -X PUT "$BASE/admin/roles/2/data-scope" -H "Authorization: Bearer $ADMIN" \
  -H 'Content-Type: application/json' -d '{"data_scope":2,"dept_ids":[]}' -o /dev/null
sql "UPDATE sys_users SET dept_id=2 WHERE username='manager';"
ok "已还原 manager 部门与角色数据范围"

echo "════ 5. 授权变更即刻生效（无需重新登录）════"
BEFORE=$(code -H "Authorization: Bearer $DEV" $BASE/admin/logs/login)
PERM_ID=$(sql "SELECT id FROM sys_permissions WHERE perm_code='sys:log:login:list' LIMIT 1")
CUR=$(sql "SELECT GROUP_CONCAT(permission_id) FROM sys_role_permissions WHERE role_id=3")
sql "INSERT IGNORE INTO sys_role_permissions (role_id, permission_id) VALUES (3, $PERM_ID);"
sql "UPDATE sys_users SET perm_version = perm_version + 1 WHERE id IN (SELECT user_id FROM sys_user_roles WHERE role_id=3);"
AFTER=$(code -H "Authorization: Bearer $DEV" $BASE/admin/logs/login)
[ "$BEFORE" = "403" ] && [ "$AFTER" = "200" ] && ok "同一 token：授权前 403 → 授权后 200" \
  || bad "授权即刻生效失败（前=$BEFORE 后=$AFTER）"
sql "DELETE FROM sys_role_permissions WHERE role_id=3 AND permission_id=$PERM_ID;"
sql "UPDATE sys_users SET perm_version = perm_version + 1 WHERE id IN (SELECT user_id FROM sys_user_roles WHERE role_id=3);"
chk "撤销后立刻恢复 403"           "$(code -H "Authorization: Bearer $DEV" $BASE/admin/logs/login)" 403

echo "════ 6. 操作日志：字段级变更 + traceId ════"
curl -s -X PUT "$BASE/admin/profile" -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' \
  -d '{"real_name":"验收测试","email":""}' -o /dev/null
LOG=$(sql "SELECT CONCAT(IFNULL(trace_id,''),'|',IFNULL(changes,'')) FROM sys_operation_logs ORDER BY id DESC LIMIT 1")
case "$LOG" in TRC-*) ok "写操作留痕且带 traceId";; *) bad "日志缺 traceId: $LOG";; esac
case "$LOG" in *real_name*) ok "含字段级变更 (real_name)";; *) bad "缺字段级变更: $LOG";; esac
curl -s -X PUT "$BASE/admin/profile" -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' \
  -d '{"real_name":"系统管理员","email":""}' -o /dev/null
echo "  (已还原 admin 姓名)"

echo
printf '════ 结果：\033[32m通过 %d\033[0m  \033[31m失败 %d\033[0m ════\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
