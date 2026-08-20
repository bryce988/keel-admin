#!/bin/sh
# 业务码一致性校验：server/app/common/constant/BizCode.php ↔ web/src/constants/bizCode.ts
#
#   sh scripts/check-bizcode.sh
#
# 为什么需要它：业务码是**跨语言**的契约，而跨语言的漂移没有任何编译器管得着。
# 后端改一个码，PHP 侧有 grep 和类型兜着；前端那边不报错、不告警，
# 只是「冲突时红框不再标到那个输入框上」——只有用户点到才知道。
#
# 校验的是**严格集合相等**（名字与值都要一一对应），不是「TS 是 PHP 的子集」。
# 子集校验会让「哪些该镜像」变成一个需要判断的问题，而需要判断的约定迟早烂掉。
#
# 退出码：0 一致；1 不一致（差异会逐条列出）。可直接挂进 CI 或 pre-commit。
set -e
ROOT=$(cd "$(dirname "$0")/.." && pwd)

python3 - "$ROOT" <<'PY'
import re, sys, pathlib

root = pathlib.Path(sys.argv[1])
php_file = root / 'server/app/common/constant/BizCode.php'
ts_file  = root / 'web/src/constants/bizCode.ts'

for f in (php_file, ts_file):
    if not f.is_file():
        print(f"✗ 找不到 {f}")
        sys.exit(1)

php = dict((m[0], int(m[1])) for m in
           re.findall(r"public const ([A-Z_][A-Z0-9_]*) = (\d+);", php_file.read_text(encoding='utf-8')))
ts  = dict((m[0], int(m[1])) for m in
           re.findall(r"^\s+([A-Z_][A-Z0-9_]*):\s*(\d+),", ts_file.read_text(encoding='utf-8'), re.M))

problems = []
for name in sorted(set(php) - set(ts)):
    problems.append(f"  PHP 有、TS 缺：{name} = {php[name]}")
for name in sorted(set(ts) - set(php)):
    problems.append(f"  TS 有、PHP 缺：{name} = {ts[name]}（后端删码时忘了同步？）")
for name in sorted(set(php) & set(ts)):
    if php[name] != ts[name]:
        problems.append(f"  值不一致：{name}  PHP={php[name]}  TS={ts[name]}")

dups = [v for v in php.values() if list(php.values()).count(v) > 1]
for v in sorted(set(dups)):
    problems.append(f"  PHP 里码值重复：{v} → {sorted(k for k in php if php[k] == v)}")

if problems:
    print(f"✗ 业务码不一致（PHP {len(php)} 个 / TS {len(ts)} 个）：")
    print('\n'.join(problems))
    print("\n  改完 BizCode.php 记得同步 web/src/constants/bizCode.ts")
    sys.exit(1)

print(f"✓ 业务码一致，两边各 {len(php)} 个")
PY
