#!/bin/sh
# 验收脚本：权限矩阵 · 数据权限五范围 · 字段级权限 · 多端隔离 · 操作日志
#
#   sh scripts/acceptance.sh          # 对本地 docker compose 环境
#   BASE=http://预发:8080 sh ...      # 也可指向别的开发/预发环境
#
# 可覆盖的环境变量：BASE · DB_USERNAME · DB_PASSWORD · DB_DATABASE
# （默认值与 docker-compose.yml 一致，本地开箱即用）
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
# 从脚本自身位置推出仓库根，别写死绝对路径——写死了别人 clone 下来跑不了
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DB_USER=${DB_USERNAME:-keel}
DB_PASS=${DB_PASSWORD:-keel123456}
DB_NAME=${DB_DATABASE:-keel}
PASS=0; FAIL=0

ok()   { PASS=$((PASS+1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31m✗\033[0m %s\n' "$1"; }
chk()  { [ "$2" = "$3" ] && ok "$1 ($2)" || bad "$1 期望=$3 实际=$2"; }

# 登录要过图形验证码，脚本直接从 Redis 读明文（这也是它只能用于开发/预发的原因）。
#
# 失败时把每一步的中间结果打出来：光一句「登录失败」在 CI 里没法排查——
# 验证码接口挂了、Redis 读不到、密码不对、账号根本没建出来，
# 四种原因的表现完全一样。诊断走 stderr，不污染 $(login ...) 的返回值。
login() {
  CAP_HTTP=$(curl -s -o /tmp/acc_cap.json -w '%{http_code}' "$BASE/admin/auth/captcha")
  KEY=$(python3 -c 'import sys,json;print(json.load(open("/tmp/acc_cap.json")).get("captcha_key",""))' 2>/dev/null)
  CODE=$(cd "$ROOT" && docker compose exec -T redis redis-cli --no-raw GET "$KEY" 2>/dev/null | tr -d '"\r\n')
  LOGIN_HTTP=$(curl -s -o /tmp/acc_login.json -w '%{http_code}' -X POST "$BASE/admin/auth/login" \
    -H 'Content-Type: application/json' \
    -d "{\"username\":\"$1\",\"password\":\"$2\",\"captcha_key\":\"$KEY\",\"captcha_code\":\"$CODE\"}")
  TOKEN=$(python3 -c 'import sys,json;print(json.load(open("/tmp/acc_login.json")).get("access_token",""))' 2>/dev/null)

  if [ -z "$TOKEN" ]; then
    {
      echo "  ── 登录失败诊断（账号 $1）──"
      echo "     验证码接口 HTTP=$CAP_HTTP  body=$(head -c 200 /tmp/acc_cap.json)"
      echo "     captcha_key=[$KEY]  从 Redis 读到的验证码=[$CODE]"
      echo "     登录接口   HTTP=$LOGIN_HTTP  body=$(head -c 300 /tmp/acc_login.json)"
      echo "     库里该账号: $(sql "SELECT CONCAT(id,' status=',status) FROM sys_users WHERE username='$1'" 2>/dev/null)"
    } >&2
  fi
  printf '%s' "$TOKEN"
}
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
total() { curl -s "$@" | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d.get("total","ERR"))'; }
sql()  { cd "$ROOT" && docker compose exec -T mysql mysql -u"$DB_USER" -p"$DB_PASS" --default-character-set=utf8mb4 -N "$DB_NAME" -e "$1" 2>/dev/null; }
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
#
# ⚠️ 期望值一律从库里现算，不要写死。
#
# 这里原来写死的是「总计 5 人」「营销部(7)」「角色 4 是审计员」——那是作者本机
# 累积出来的状态，种子数据里只有 4 个用户、3 个部门、3 个内置角色。
# 本机能过是因为库里恰好有那些数据，CI 每次都是空库，必挂。
#
# 判断标准：任何一个数字，如果 `docker compose down -v` 之后就不成立，就不能写死。

users_all() { sql "SELECT COUNT(*) FROM sys_users WHERE deleted_at IS NULL"; }
users_in()  { sql "SELECT COUNT(*) FROM sys_users WHERE deleted_at IS NULL AND dept_id IN ($1)"; }
# 与 DataScope::deptTree 用同一套 ancestors 前缀匹配，避免两边算法漂移
subtree_of() { sql "SELECT GROUP_CONCAT(d.id) FROM sys_depts d
                    JOIN (SELECT IF(ancestors='','',CONCAT(ancestors,',')) a FROM sys_depts WHERE id=$1) p
                    WHERE d.deleted_at IS NULL
                      AND (d.id=$1 OR d.ancestors=CONCAT(p.a,$1) OR d.ancestors LIKE CONCAT(p.a,$1,',%'))"; }

MGR_DEPT=$(sql "SELECT dept_id FROM sys_users WHERE username='manager'")
MGR_DEPT_NAME=$(sql "SELECT name FROM sys_depts WHERE id=$MGR_DEPT")
MGR_PARENT=$(sql "SELECT parent_id FROM sys_depts WHERE id=$MGR_DEPT")
ROOT_DEPT=$(sql "SELECT id FROM sys_depts WHERE parent_id=0 AND deleted_at IS NULL ORDER BY id LIMIT 1")
MGR_TREE=$(subtree_of "$MGR_DEPT")
ALL_USERS=$(users_all)

echo "  基准（现算）：用户 $ALL_USERS 人；manager 在 $MGR_DEPT_NAME($MGR_DEPT)，子树={$MGR_TREE}；根部门=$ROOT_DEPT"

chk "范围1 全部数据 (admin)"                "$(total -H "Authorization: Bearer $ADMIN" "$BASE/admin/users?page_size=50")" "$ALL_USERS"
chk "范围2 本部门及下级 (manager@$MGR_DEPT_NAME)" "$(total -H "Authorization: Bearer $MGR" "$BASE/admin/users?page_size=50")" "$(users_in "$MGR_TREE")"

# ---- 范围5 自定义部门 ----
# 种子里没有 data_scope=5 的角色，就地造一个，跑完删掉。
# 不复用现有角色：那等于把验收结果绑在「库里恰好有个审计员」上，
# 而这正是这一节原来挂掉的原因。
#
# 自定义集合刻意**排除 ops01 自己所在的部门**：它应当看不到自己，
# 这才证明自定义范围是完全替换，而不是「本部门 + 额外」
OPS_DEPT=$(sql "SELECT dept_id FROM sys_users WHERE username='ops01'")
CUSTOM_DEPTS=$(sql "SELECT GROUP_CONCAT(id) FROM sys_depts WHERE deleted_at IS NULL AND id<>$OPS_DEPT")
OPS_ROLES_ORIG=$(sql "SELECT GROUP_CONCAT(role_id) FROM sys_user_roles WHERE user_id=(SELECT id FROM sys_users WHERE username='ops01')")

sql "INSERT INTO sys_roles (name,code,data_scope,is_builtin,sort,status,remark,created_at,updated_at)
     VALUES ('验收-自定义范围','ROLE_ACCEPT_CUSTOM',5,0,99,1,'验收脚本临时角色，跑完即删',NOW(),NOW());"
CUSTOM_ROLE=$(sql "SELECT id FROM sys_roles WHERE code='ROLE_ACCEPT_CUSTOM'")
USER_LIST_PID=$(pid 'sys:user:list')
sql "INSERT IGNORE INTO sys_role_permissions (role_id,permission_id) VALUES ($CUSTOM_ROLE,$USER_LIST_PID);"
for d in $(echo "$CUSTOM_DEPTS" | tr ',' ' '); do
  sql "INSERT IGNORE INTO sys_role_depts (role_id,dept_id) VALUES ($CUSTOM_ROLE,$d);"
done
sql "DELETE FROM sys_user_roles WHERE user_id=(SELECT id FROM sys_users WHERE username='ops01');"
sql "INSERT INTO sys_user_roles (user_id,role_id) VALUES ((SELECT id FROM sys_users WHERE username='ops01'),$CUSTOM_ROLE);"
sql "UPDATE sys_users SET perm_version=perm_version+1 WHERE username='ops01';"

OPS=$(login ops01 demo123456)
chk "范围5 自定义={$CUSTOM_DEPTS}，且看不到自己所在的部门 $OPS_DEPT" \
    "$(total -H "Authorization: Bearer $OPS" "$BASE/admin/users?page_size=50")" "$(users_in "$CUSTOM_DEPTS")"

# 还原：先解绑用户，再删角色的关联行，最后删角色
sql "DELETE FROM sys_user_roles WHERE user_id=(SELECT id FROM sys_users WHERE username='ops01');"
for r in $(echo "$OPS_ROLES_ORIG" | tr ',' ' '); do
  sql "INSERT IGNORE INTO sys_user_roles (user_id,role_id) VALUES ((SELECT id FROM sys_users WHERE username='ops01'),$r);"
done
sql "UPDATE sys_users SET perm_version=perm_version+1 WHERE username='ops01';"
sql "DELETE FROM sys_role_depts WHERE role_id=$CUSTOM_ROLE;"
sql "DELETE FROM sys_role_permissions WHERE role_id=$CUSTOM_ROLE;"
sql "DELETE FROM sys_roles WHERE id=$CUSTOM_ROLE;"
ok "已删除临时角色并还原 ops01 的角色($OPS_ROLES_ORIG)"

# ---- 范围4 仅本人 ----
echo "  范围4 仅本人：dev01 无用户列表权限，改用「我的登录记录」验证"
DEV_ID=$(sql "SELECT id FROM sys_users WHERE username='dev01'")
MINE=$(total -H "Authorization: Bearer $DEV" "$BASE/admin/profile/logins?page_size=100")
DBMINE=$(sql "SELECT COUNT(*) FROM sys_login_logs WHERE user_id=$DEV_ID")
chk "范围4 仅本人 (dev01 登录记录)" "$MINE" "$DBMINE"

# ---- 范围3 本部门不含下级 ----
echo "  范围3 本部门不含下级：现有账号都在叶子部门，区分不出来 → 临时把主管挪到根部门"
sql "UPDATE sys_users SET dept_id=$ROOT_DEPT WHERE username='manager';"
MGR2=$(login manager demo123456)
chk "范围2 在根部门 → 全树 $(users_in "$(subtree_of "$ROOT_DEPT")") 人" \
    "$(total -H "Authorization: Bearer $MGR2" "$BASE/admin/users?page_size=50")" "$(users_in "$(subtree_of "$ROOT_DEPT")")"
curl -s -X PUT "$BASE/admin/roles/2/data-scope" -H "Authorization: Bearer $ADMIN" \
  -H 'Content-Type: application/json' -d '{"data_scope":3,"dept_ids":[]}' -o /dev/null
# 此刻根部门里有 admin + 临时挪来的 manager；下级部门的人应被排除
chk "范围3 在根部门 → 仅本部门 $(users_in "$ROOT_DEPT") 人（排除下级）" \
    "$(total -H "Authorization: Bearer $MGR2" "$BASE/admin/users?page_size=50")" "$(users_in "$ROOT_DEPT")"
# 还原（用进来时存下的原值，不写死）
curl -s -X PUT "$BASE/admin/roles/2/data-scope" -H "Authorization: Bearer $ADMIN" \
  -H 'Content-Type: application/json' -d '{"data_scope":2,"dept_ids":[]}' -o /dev/null
sql "UPDATE sys_users SET dept_id=$MGR_DEPT WHERE username='manager';"
ok "已还原 manager 部门($MGR_DEPT)与角色数据范围"

echo "════ 5. 写入侧数据权限（可写集合 = 可读集合）════"
echo "  manager 在 $MGR_DEPT_NAME($MGR_DEPT)，范围「本部门及下级」；该部门无子部门 → 可写集合 = {$MGR_DEPT}"
# 这一节全部是 HTTP 断言：写侧的校验点在 service 里，只有真发请求才走得到
mkuser() { curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/users" \
  -H "Authorization: Bearer $1" -H 'Content-Type: application/json' \
  -d "{\"username\":\"$2\",\"real_name\":\"验收探针\",\"dept_id\":$3,\"status\":1}"; }
bizcode() { curl -s "$@" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("code","ERR"))'; }

chk "建用户到上级部门($MGR_PARENT) → 403" "$(mkuser "$MGR" probe_out "$MGR_PARENT")" 403
chk "  且业务码是 10302 而非 10301"   "$(bizcode -X POST "$BASE/admin/users" -H "Authorization: Bearer $MGR" \
  -H 'Content-Type: application/json' -d "{\"username\":\"probe_out\",\"real_name\":\"x\",\"dept_id\":$MGR_PARENT,\"status\":1}")" 10302
# dept_id=0 不用特判，它天然不在任何受限集合里；拦它是因为写完自己就看不见了
chk "建用户到「未分配」(0) → 403"     "$(mkuser "$MGR" probe_zero 0)" 403
chk "建用户到本部门($MGR_DEPT_NAME) → 201" "$(mkuser "$MGR" probe_in "$MGR_DEPT")" 201
PROBE_ID=$(sql "SELECT id FROM sys_users WHERE username='probe_in'")
# 改新部门那一判：拦住「把范围内的人踢到范围外」，一步就失去对这条数据的控制
chk "把范围内的人改去上级部门 → 403"  "$(curl -s -o /dev/null -w '%{http_code}' -X PUT "$BASE/admin/users/$PROBE_ID" \
  -H "Authorization: Bearer $MGR" -H 'Content-Type: application/json' \
  -d "{\"username\":\"probe_in\",\"real_name\":\"验收探针\",\"dept_id\":$MGR_PARENT,\"status\":1}")" 403
chk "  被拒后库里的部门没变"          "$(sql "SELECT dept_id FROM sys_users WHERE id=$PROBE_ID")" "$MGR_DEPT"
# 对照组：范围「全部数据」不受限，否则上面几条可能只是接口本身坏了
chk "对照 admin 建到根部门 → 201"     "$(mkuser "$ADMIN" probe_admin "$ROOT_DEPT")" 201

echo "  部门树的根不能写死 parent_id=0——上级被数据权限滤掉后整棵树会塌成空"
TREE_TOP=$(curl -s "$BASE/admin/depts/tree" -H "Authorization: Bearer $MGR" \
  | python3 -c 'import sys,json;t=json.load(sys.stdin);print(t[0]["name"] if t else "空树")')
chk "manager 的部门树以 $MGR_DEPT_NAME 为根" "$TREE_TOP" "$MGR_DEPT_NAME"

sql "DELETE FROM sys_user_roles WHERE user_id IN (SELECT id FROM sys_users WHERE username LIKE 'probe%');"
sql "DELETE FROM sys_users WHERE username LIKE 'probe%';"
sql "DELETE FROM sys_operation_logs WHERE target LIKE '%probe%';"
ok "已清理验收探针账号"

echo "════ 6. 换头像（上传校验三道关）════"
TMPIMG=$(mktemp -d)
# 造三个样本：正常 png / 扩展名不对 / 叫 png 但内容不是图片
python3 - "$TMPIMG" <<'PY'
import sys, zlib, struct
d = sys.argv[1]
def png(w, h):
    raw = b''.join(b'\x00' + bytes((60, 134, 255)) * w for _ in range(h))
    ch = lambda t, b: struct.pack('>I', len(b)) + t + b + struct.pack('>I', zlib.crc32(t + b))
    return (b'\x89PNG\r\n\x1a\n'
            + ch(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0))
            + ch(b'IDAT', zlib.compress(raw)) + ch(b'IEND', b''))
open(d + '/ok.png', 'wb').write(png(32, 32))
open(d + '/bad.php', 'wb').write(b'<?php echo 1;')
open(d + '/fake.png', 'wb').write(b'not an image at all')
PY
avatar() { curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/profile/avatar" \
  -H "Authorization: Bearer $ADMIN" -F "file=@$1"; }

OLD_AV=$(sql "SELECT IFNULL(avatar,'') FROM sys_users WHERE username='admin'")
chk "上传正常 png → 200"          "$(avatar $TMPIMG/ok.png)" 200
NEW_AV=$(sql "SELECT avatar FROM sys_users WHERE username='admin'")
case "$NEW_AV" in /uploads/avatar/*) ok "库里已写入新头像 ($NEW_AV)";; *) bad "头像没写库: $NEW_AV";; esac
chk "  该文件可被静态访问"        "$(code "$BASE$NEW_AV")" 200
chk "扩展名不在白名单 → 400"      "$(avatar $TMPIMG/bad.php)" 400
chk "叫 png 但不是图片 → 400"     "$(avatar $TMPIMG/fake.png)" 400
chk "不带文件 → 400"              "$(code -X POST "$BASE/admin/profile/avatar" -H "Authorization: Bearer $ADMIN")" 400
# 换一次，确认旧文件被删掉而不是越堆越多
avatar $TMPIMG/ok.png > /dev/null
[ -f "$ROOT/server/public$NEW_AV" ] && bad "换头像后旧文件仍在" || ok "换头像后旧文件已删除"
# 还原：删掉本轮产生的文件与库里的值
LAST_AV=$(sql "SELECT avatar FROM sys_users WHERE username='admin'")
rm -f "$ROOT/server/public$LAST_AV"
sql "UPDATE sys_users SET avatar='$OLD_AV' WHERE username='admin';"
rm -rf "$TMPIMG"
ok "已还原 admin 头像与上传文件"

echo "════ 7. 授权变更即刻生效（无需重新登录）════"
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

echo "════ 8. 操作日志：字段级变更 + traceId ════"
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
