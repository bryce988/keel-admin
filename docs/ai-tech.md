# Keel · AI 助手「小k」技术方案

> 版本 v0.1（草案）· 2026-09-23 · 目标里程碑 **M6**
> **状态：后台已实现（2026-09-23），员工移动端不做。** 模型服务 **DeepSeek**，密钥在「系统配置 / 参数配置 / 第三方集成」里配（§3.2）。
> 验证：`scripts/ai-acceptance.sh` 接模拟 DeepSeek（`server/scripts/ai-mock-deepseek.php`）54 项全部通过；**真实 DeepSeek 还没接过**。
> 实现与本文的偏差都已回填进对应小节，汇总见 §14。
> 配套文档：[产品文档](ai-prd.md) · [IM 技术方案](chat-tech.md) · [接口契约](api.md) · [数据库设计](database.md)
>
> 本文定义**怎么实现**。做到哪儿为止看 [ai-prd.md](ai-prd.md)。
> 设计落地后，接口写进 `api.md`、建表写进 `database.md`，本文退回成「为什么这么做」的存档。

---

## 1. 总体架构

```
 浏览器 / App
   │ ① POST /admin/ai/messages  （提问，立刻返回 202 + run_id）
   ▼
┌──────────────────┐   ② 落库提问消息 + 建 ai_runs + 投递队列
│ webman HTTP 进程  │ ─────────────────────────────────────┐
│ AiService::ask() │                                      │ redis-queue: keel:ai
└──────────────────┘                                      ▼
                                          ┌─────────────────────────────┐
                                          │ AI 消费进程（独立进程组）       │
                                          │  actAs(提问人) {               │
                                          │    AgentLoop: 模型 ⇄ 工具       │ ③ 工具 = 现有 service
                                          │  }                            │   （DataScope/字段脱敏照常生效）
                                          └──────┬───────────────┬────────┘
                                                 │ ④ 增量帧        │ ⑤ 最终回答落 im_messages
                                                 ▼                ▼
                                   Redis pub/sub im:fanout     MySQL
                                                 │
                                                 ▼
                                          ChatGateway（WS）──▶ 提问人的所有在线连接
                                                 │
                                                 └──── DeepSeek API（HTTPS，SSE 流式）
```

**四条主线**

1. **提问走 HTTP，回答走队列。** 一次问答 5~90 秒，放在 HTTP worker 里等于每个提问的人占住一个 worker——
   生产机只有 8 个（`config/process.php` 的注释），8 个人同时问，后台就冻住了。这与导出走队列是同一个理由
2. **以提问人身份执行。** 消费进程里用 `AuthService::actAs()` 还原提问人，**所有工具都在这个身份下跑**，
   数据权限与字段脱敏不需要为 AI 重写一行（§4）
3. **工具只是现有 service 的薄封装。** 不写 SQL、不 `withoutDataScope()`、不绕过 presenter（§5）
4. **流式增量走现有的 WS 下行通道，最终回答走现有的消息表。** 增量帧丢了无所谓（与 chat-tech §1 一样，
   长连接不可靠、数据库可靠），客户端最终以 `message.new` 为准

---

## 2. 会话与消息建模

### 2.1 复用 IM 的表，而不是另起一套

小k 会话就是 `im_conversations` 里的一行，`type = 3`（新增）：

| 字段 | 小k 会话的取值 |
|---|---|
| `type` | `3` AI 会话（字典 `im_conv_type` 追加） |
| `peer_key` | `ai:<user_id>` —— 复用 `uk_peer` 保证每人只有一个，并发首次打开不会建出两个 |
| `name` / `avatar` | 空，前端固定展示「小k」 |
| 成员表 | 只有提问人一行 |

**为什么不给小k 建一个 `sys_users` 账号**：那样它会出现在通讯录、用户列表、在职人数统计、角色成员里，
每一处都要加排除条件，漏一处就是一个 bug；更要命的是它会有「自己的角色和数据范围」，
这恰恰是本方案要避免的（ai-prd §4.1：小k 没有自己的身份）。

**为什么不另建 `ai_messages` 表**：会话列表、未读数、`seq` 对齐、翻页、多端同步、留存清理，IM 全都做好了。
另起一套就要把这些再做一遍，而且消息列表要合并两个来源排序。

### 2.2 消息

| 谁发的 | `sender_id` | `type` | `extra` |
|---|---|---|---|
| 提问人 | 提问人 id | `text` | `{run_id}` |
| 小k 的回答 | `0` | `ai`（字典 `im_msg_type` 追加） | `{run_id, steps:[{label,rows}], links:[…], status: done/stopped/failed}` |
| 新对话分隔 | `0` | `system` | `{kind: 'ai_reset'}` |
| 欢迎语 | `0` | `ai` | `{kind: 'welcome', suggestions:[…]}` |

- `sender_id = 0` 与系统消息相同，**`client_msg_id` 必须由服务端生成 UUID**——chat-tech §3.3 那条坑（`(0,'')` 撞 `uk_client_msg`）在这里同样成立
- 回答**只在完成（或停止、失败）时落一条**，流式期间不写库。写库是为了「换设备还在」，而半截的回答不需要漫游
- `extra.steps` 是给「查询了 N 项」折叠区用的**人话描述与行数**，不存原始参数和结果（那些在 `ai_tool_calls` 里，只给审计看）

### 2.3 对 ChatService 的改动（都是防御性的）

| 位置 | 改动 | 不改的后果 |
|---|---|---|
| 发消息 `send()` | `type = 3` 的会话拒绝走聊天发送接口（400） | 消息进了小k 会话却不会触发回答，用户以为小k 挂了 |
| 群操作（加人、改名、解散……） | `type != 2` 一律 404，原本就有，确认覆盖到 3 | — |
| 单聊「对方已离职」判断 | 跳过 `type = 3` | 没有「对方」，判断会取空 |
| 会话列表 | `type = 3` 带上 `is_ai: true`，前端据此固定到公告下方 | 它会和普通会话混排 |
| 撤回 | 小k 会话里只允许撤回自己的提问，不能撤回小k 的回答 | — |

---

## 3. 模型调用层（DeepSeek）

> 以下 DeepSeek 接口细节来自官方文档 api-docs.deepseek.com，**2026-09-23 核对**。
> 这家的模型名和参数变得很快（`deepseek-v4-flash` 已下线、请求被转到 V4.1-Flash），落地时再核一遍。

### 3.1 选型结论

| 项 | 取值 | 理由 |
|---|---|---|
| 接口格式 | **OpenAI 兼容的 Chat Completions**（`POST {base}/chat/completions`） | DeepSeek 的原生格式，文档与示例都以它为准 |
| 默认模型 | `deepseek-flash`（DeepSeek-V4.1-Flash） | 支持工具调用 + 思考模式，1M 上下文；价格约为 `deepseek-v4-pro` 的 1/4。问答类负载先用它，实测准确率不够再换 |
| 思考模式 | 开（DeepSeek 默认开，默认强度 `high`） | 挑工具、拆条件这一步明显受益于思考。强度做成参数，实测后调 |
| HTTP 客户端 | **ext-curl 直接调**，不引 SDK | 容器里已有 curl 扩展；vendor 里没有 guzzle，为一个接口引入 `openai-php/client` + PSR-18 全家桶不划算。SSE 解析二十行代码 |
| 严格模式（strict） | **不用** | 要走 `/beta` 地址，且要求**所有属性都 required**——我们的查询工具绝大多数参数是可选筛选条件，改成全 required + 可空会让 schema 难读，模型也更容易填错。参数合法性交给服务端 `Validator` |
| 网络 | 服务在国内，生产机（腾讯云国内机房）可直连 | 原 ai-prd §11 卡开工的那条因此解除，但**开工前仍要在生产机上 curl 一次** `/user/balance` 确认 |

仍然保留一层 `LlmProvider` 接口，实现只有 `DeepSeekProvider` 一个：接口的成本是一个文件，
而它让「模型调用」与「工具循环、身份、落库」彻底分开，测试时也能换成一个假的 provider 跑权限矩阵，
不花钱、结果可重复。

```php
namespace app\common\ai;

interface LlmProvider
{
    /** 一轮模型调用（可能以 tool_calls 结束）。$onText 收到正文增量，$onThinking 在思考阶段被调用（只用来推「思考中」状态） */
    public function turn(array $messages, array $tools, RunGuard $guard, callable $onText, callable $onThinking): LlmTurn;
}
```

`LlmTurn`：`content`、`reasoning`（原始 `reasoning_content`，**只在本次 run 的内存里回传，不落库**）、
`toolCalls[]`、`finish`（`stop`/`tool_calls`/`length`/`content_filter`/`insufficient_system_resource`/`aborted`）、
`usage{prompt, completion, reasoning, cache_hit, cache_miss}`。

### 3.2 配置：密钥在「系统配置」里

沿用邮件服务已经在用的模式（`MailService::conf()`）：**参数表里填了就用，留空回落 `.env`，粒度是单个键**。

| 参数键 | 分组 | 类型 | `is_secret` | 默认 | 说明 |
|---|---|---|---|---|---|
| `ai.deepseek.apiKey` | `integration` | string | **1** | `''` | 回落 `DEEPSEEK_API_KEY` |
| `ai.deepseek.model` | `integration` | string | 0 | `deepseek-flash` | 回落 `DEEPSEEK_MODEL` |
| `ai.deepseek.reasoningEffort` | `integration` | string | 0 | `high` | `none` 关闭思考 / `low` / `high` / `max` |
| —（不进参数表） | — | — | — | `https://api.deepseek.com` | **只认 `.env` 的 `DEEPSEEK_BASE_URL`**，见下 |

`is_secret = 1` 已有的保护（`ParamService`，M2.5 做的）全部自动生效：列表与详情只回掩码 `ParamService::MASK`、
提交掩码原样等于「不改」、操作日志只记「已更新」、登录页公开参数接口排除密钥类。

⚠️ **Base URL 故意不放进参数表。** 这是 `MailService` 注释里那条风险的翻版，而且更严重：
有 `sys:param:update` 的人看不到密钥，但如果能改 base URL，只要改成自己的服务器，
下一次提问时**密钥本身就会被放在 `Authorization` 头里发过去**——邮件那边泄露的是验证码，这里泄露的是能花钱的凭证，
外加此后所有人的提问与查询结果。改地址这件事必须是有服务器权限的人在 `.env` 里做。

同理，`sys:param:update` 这个权限点**只该给运维**，这一条要写进帮助文档的「参数配置」一篇。

「参数配置」页在 `integration` 分组下为 AI 加一个**「测试连接」**按钮（权限 `sys:param:update`）：
调 DeepSeek 的 `GET /user/balance`，显示「可用 / 余额 xx CNY」或具体错误（401 密钥错、网络不通）。
不做这个按钮的话，配错密钥的唯一表现是员工那边的小k 全部回答失败。

### 3.3 一次调用长什么样

```php
// 示意。请求体（OpenAI 格式，字段名以官方文档为准）
$body = [
    'model'            => $model,                 // deepseek-flash
    'messages'         => $messages,              // system + user + 本次 run 内的 assistant/tool 往返
    'tools'            => $tools,                 // [{type:'function', function:{name, description, parameters}}]，按 name 排序
    'tool_choice'      => 'auto',
    'reasoning_effort' => $effort,                // none 时改为 thinking: {type: 'disabled'}
    'max_tokens'       => 32768,                  // 思考 token 也算在里面；给小了会在思考阶段就被截断。关闭思考时是 4096
    'stream'           => true,
    'stream_options'   => ['include_usage' => true],   // usage 只在最后一个 chunk 里
];

curl_setopt_array($ch, [
    CURLOPT_URL            => rtrim($baseUrl, '/') . '/chat/completions',
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 0,                  // 总时长由 RunGuard 管，不交给 curl
    CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use ($parser, $guard) {
        if ($guard->shouldStop()) {
            return -1;                            // 返回值 ≠ 写入长度 → curl 立即中断连接，这就是「停止」
        }
        $parser->feed($chunk);                    // 按 "\n\n" 切 SSE 事件，"data: [DONE]" 结束
        return strlen($chunk);
    },
]);
```

SSE 解析要点：

- `delta.reasoning_content` → 累积到 `reasoning`，首次出现时回调 `$onThinking`（推 `ai.thinking` 帧，界面显示「思考中…」）
- `delta.content` → 累积并回调 `$onText`
- `delta.tool_calls[]` → **按 `index` 累积**：`id`、`function.name` 只在第一片出现，`function.arguments` 是分片的 JSON 字符串，拼完再 `json_decode`
- 最后一个 chunk 的 `usage` 取 token 数；`finish_reason` 取结束原因
- 一个 chunk 可能被 curl 切在任意字节处（包括 UTF-8 多字节字符中间），解析器要自己留尾巴，不能按回调边界切

### 3.4 思考模式与 `reasoning_content`（最容易 400 的地方）

官方文档原话：**请求带 `tools` 时，之前每一个 assistant 回合的 `reasoning_content` 都必须完整回传，
哪怕那一回合没有调用工具；没有正确回传会返回 400。**而且跨用户回合也要带（「Turn 2 仍要传 Turn 1 的 reasoning_content」）。

这与本方案的两个决定直接冲突：「思考内容不落库」和「上下文只带最近 10 轮的问答文本」（ai-prd §5.3）。处理方式：

| 范围 | 做法 |
|---|---|
| **一次 run 之内**（模型 ⇄ 工具多轮） | assistant 消息原样保留 `content` + `reasoning_content` + `tool_calls`，工具结果以 `role: tool` + `tool_call_id` 回传。这些只活在消费进程的内存里，run 结束即丢 |
| **跨 run 的历史**（「那测试部呢？」） | **不作为 assistant 消息回传**，而是把最近 10 轮问答**折叠成文本，放进本次 user 消息里**：「以下是你与该用户之前的对话，仅供理解上下文：……」。消息数组里因此**没有任何历史 assistant 回合**，也就不存在要回传的 `reasoning_content` |

折叠的代价是模型把历史当「引用材料」而不是「自己说过的话」，追问的理解略弱。
好处是三个：不 400、不用存思考内容（思考里会复述查询结果，存下来等于在 AI 表里又存了一份业务数据）、
历史也不会带上旧的工具结果（本来就不想带，ai-prd §5.3）。第 ② 批用 20 组追问实测，理解明显变差再议。

**不向用户展示原始思考内容**：DeepSeek 返回的是完整的推理文本，冗长且会反复复述查询结果。
界面只显示「思考中…」与「查询了 N 项」的步骤（ai-prd §5.2），思考 token 计入成本统计。

### 3.5 结束原因与错误

| 情况 | 处理 |
|---|---|
| `finish_reason = tool_calls` | 执行工具，进入下一轮 |
| `stop` | 正常结束 |
| `length` | 截断。落库已输出部分，末尾追加「（回答过长被截断，请缩小问题范围）」，`status=done` |
| `content_filter` | 服务商内容审核拦截。回答「这个问题我没法回答」，`status=failed`，**不返还配额**（重试大概率还是被拦） |
| `insufficient_system_resource` / HTTP 503 / 500 | 服务商过载。退避重试 2 次（1s、3s），仍失败则 `failed`，返还配额 |
| HTTP 429 | 同上；连续出现说明 `AI_WORKERS` 开大了 |
| HTTP 401 | 密钥错。`failed`，回答「AI 服务配置有误，请联系管理员」，并给超管发一条系统公告级提醒（同一小时只发一次） |
| HTTP 402 | 余额不足。同 401，提示语换成余额不足。**这是生产上最可能出现的故障**，所以「测试连接」要显示余额 |
| HTTP 400 / 422 | 我们的请求拼错了（最可能就是 §3.4）。`failed`，完整错误体进 `error` 日志，用户侧只给笼统提示 |
| 连接超时 5s | 网络问题，按过载处理 |

### 3.6 缓存

DeepSeek 的上下文缓存是**自动的**（硬盘缓存，默认开，不需要 `cache_control` 之类的标记），
按**前缀单元**完整匹配才命中。命中的输入价格约为未命中的 1/50，所以前缀稳定仍然是成本上最大的杠杆：

| 放在前面（稳定） | 放在后面（每次不同） |
|---|---|
| system：角色、规则、输出格式、帮助文档全文 | user：提问人姓名、部门、数据范围描述、今天日期 |
| tools：按 name 排序 | user：折叠的历史 + 本次提问 |

- 提问人信息与日期**写进 user 消息开头，不写进 system**——写进 system 等于每个人、每天一份缓存
- 工具集合随权限变化，缓存天然按「权限组合」分桶。内置角色就那么几种，桶数可控
- 验证：响应 `usage.prompt_cache_hit_tokens` 落进 `ai_runs.cache_hit_tokens`，连续为 0 说明前缀里混进了变量
- 缓存「通常几小时到几天」清理，没有保证，所以**成本估算按未命中算上限**

### 3.7 计费

DeepSeek 按美元计价，并且**分高峰与非高峰**（高峰：UTC 周一至周五 01:00–04:00、06:00–10:00，即北京时间 9–12 点、14–18 点，
中国法定节假日除外）。`ai_runs.cost` 用 `config/ai.php` 里手写的单价表按**请求开始时刻**折算：

```php
// config/ai.php（单价 USD / 1M tokens，2026-09-23 抄自官方价格页，换模型或调价时手改）
'prices' => [
    'deepseek-flash'  => ['hit' => [0.003, 0.006], 'miss' => [0.15, 0.3],  'out' => [0.6, 1.2]],   // [非高峰, 高峰]
    'deepseek-v4-pro' => ['hit' => [0.022, 0.044], 'miss' => [0.66, 1.32], 'out' => [1.98, 3.96]],
],
```

节假日不单独判（算成高峰，宁可高估）。**这是估算，对账以 DeepSeek 控制台为准**，审计页上要写明。
思考 token 按输出价计入。

---

## 4. 以提问人身份执行（本模块的安全核心）

### 4.1 已有先例

导出就是这么做的：`ExportService::impersonate()` 在消费进程里 `Ctx::set('user', AuthService::loadUser($uid))`，
`finally` 里 `Ctx::clear()`。小k 照抄这套，并把它**提升为公共能力**（放在 `AuthService` 而不是 `Ctx`：`support` 不该依赖 `service`）：

```php
// app/common/service/AuthService.php
public static function actAs(int $userId, callable $work): mixed
{
    // 进入前必须是干净的：有残留说明上一个 job 没清，宁可失败也不串号
    if (Ctx::user() !== null) {
        throw new \LogicException('Ctx 里残留着上一个身份，拒绝叠加执行');
    }
    try {
        Ctx::set('user', self::loadUser($userId));   // 停用/删除的账号在这里抛出
        return $work();
    } finally {
        Ctx::clear();
    }
}
```

`ExportService::impersonate()` 随后改成调它，全仓只留一个实现。

### 4.2 身份覆盖到了哪些东西

| 机制 | 读的是什么 | 在 actAs 里是否生效 |
|---|---|---|
| 数据权限 `DataScope::apply()` | `Ctx::user()` | ✅ |
| 数据范围缓存 `dataScope.level` / `deptTree` | `Ctx` | ✅ 一个 job 内算一次，`clear()` 随之丢弃 |
| 功能权限 `PermissionService::has()` | 传入的 user + Redis 版本号缓存 | ✅ 版本号保证授权变更立即生效（ai-prd §4.4） |
| 字段脱敏（各 service 的 presenter） | `PermissionService::has()` | ✅ |
| 审计字段 `creator_id` | `Ctx::userId()` | ✅（小k 只读，理论上不触发） |

**一次问答内**的数据范围按开始时算，问到一半管理员改了范围，下一问才生效。可以接受。

### 4.3 必须有的专项用例

「队列进程里身份串号」是那种**平时完全看不出来**的问题：只有两个不同权限的人前后脚提问、
恰好被同一个消费进程接到时才会暴露。所以第 ① 批要用脚本主动构造：

1. 消费进程数设为 1
2. 超管提问 → 紧接着普通员工提问
3. 断言普通员工那次的所有工具调用，`ai_tool_calls.acting_user_id` 都是他，且返回行数等于他自己调列表接口的 total
4. 在工具里故意抛异常，重复 2、3（验证 `finally` 在异常路径上也清了）

---

## 5. 工具（查询能力）

### 5.1 接口

```php
namespace app\common\ai;

interface AiTool
{
    public function name(): string;         // search_users
    public function description(): string;  // 给模型看的：什么时候用、返回什么、口径是什么
    public function schema(): array;        // JSON Schema，作为 function.parameters 发给 DeepSeek（不开 strict，见 §3.1）
    public function perm(): string|array;   // 与路由同一套：'' 登录即可，数组任一命中
    public function run(array $args): ToolResult;
}

final class ToolResult
{
    public function __construct(
        public array   $rows,            // 已经过 presenter 与 AI 脱敏的行
        public int     $total,           // 真实总数（rows 可能被截断）
        public string  $label,           // 人话描述：「用户列表：研发部及下属，在职」
        public ?string $scopeNote = null,// 口径：「你的可见范围：研发部及下属部门」
        public ?array  $link = null,     // ['path' => '/system/user', 'query' => [...]]
    ) {}
}
```

### 5.2 登记与分层

工具会调 `app/admin/service/*`（`UserService` 等），而 AI 执行层在 `app/common`（两端共用）。
`common` 禁止 use 任何 `app/<端>/`（server/CLAUDE.md），所以沿用导出的做法：**用配置接线**。

```php
// config/ai.php —— 与 config/export.php 同一个模式
return [
    'tools' => [
        \app\admin\ai\SearchUsersTool::class,
        \app\admin\ai\GetUserTool::class,
        // …
    ],
];
```

工具类放 `app/admin/ai/`。业务模块接入时往这里加一行，不改 AI 层。

### 5.3 执行管线

```
模型给出 tool_calls[{id, function:{name, arguments}}]
  ├─ arguments 能 json_decode？          否 → 错误结果「参数不是合法 JSON」
  ├─ 工具存在？                          否 → 错误结果「没有这个工具」
  ├─ PermissionService::hasAny(perm)？   否 → 错误结果「需要『用户管理』权限」+ 审计 denied=1
  ├─ Validator 校验参数                  失败 → 错误结果 + 字段说明（模型会自己修正重试）
  ├─ $tool->run($input)                  在 actAs 身份下，走现有 service
  ├─ AI 脱敏（§5.5）
  ├─ 截断：rows 最多 50 行，超过的给 total + 提示「完整结果见链接」
  ├─ 写 ai_tool_calls
  └─ {role: 'tool', tool_call_id, content: JSON（带 scope_note）}
```

- **两道权限**：给模型的工具清单先按权限过滤（没权限的工具模型根本不知道存在），执行时再判一次。
  第一道是体验（不让模型去试它必然失败的东西），第二道才是边界，与「前端 `v-permission` 不是安全边界」是同一个道理
- OpenAI 格式没有 `is_error` 字段，错误结果统一写成 `{"error": "…"}` 放进 tool 消息的 content，不中断整次问答——模型能据此换个方式问或如实告诉用户
- 一次给出多个 `tool_calls` 时**顺序执行**（PHP 阻塞模型，并行没有收益），每个结果一条 `role: tool` 消息，`tool_call_id` 一一对应，**一个都不能少**（少了下一轮 400）
- 含 `tool_calls` 的那条 assistant 消息必须原样带着 `reasoning_content` 回传（§3.4）

### 5.4 口径说明

`DataScope` 新增一个静态方法 `describe(): ?string`，返回当前身份的范围描述：

| 范围 | 描述 |
|---|---|
| 全部 / 超管 | `null`（不需要说明） |
| 本部门及下属 | 「研发部及下属部门」 |
| 本部门 | 「研发部」 |
| 仅本人 | 「仅你本人的数据」 |
| 自定义 | 「你被授权的 3 个部门（研发部、测试部、运维部）」 |

凡是挂了 `HasDataScope` 的模型，工具的 `ToolResult.scopeNote` 必须填它；系统提示词要求模型
「`scope_note` 非空时，回答里的数字必须带上这个口径」。通讯录类工具填「通讯录全公司可见」（它本来就不挂数据权限）。

### 5.5 AI 脱敏

现有 presenter 已经按字段权限脱敏过一次。AI 层**再加一道**：工具声明自己结果里的敏感键，
`ai.field.unmask = 0`（默认）时无条件打码，与个人权限无关（理由见 ai-prd §4.3）。

```php
public function sensitive(): array { return ['phone' => 'mask', 'email' => 'maskEmail']; }
```

放在工具声明里而不是全局按键名匹配：`remark` 里可能写了手机号，全局匹配管不到，
反而让人以为管到了。**自由文本字段（备注、公告正文、日志参数）要在工具描述里写明「可能含个人信息」**，
将来需要时再做内容级识别。

### 5.6 V1 工具清单

| 工具 | 权限点 | 封装的 service | 数据权限 |
|---|---|---|---|
| `get_my_profile` | `''` | `ProfileService` | 本人 |
| `search_contacts` | `contact:view` | `ContactService::list` | 全公司（不挂 Scope） |
| `search_users` | `sys:user:list` | `UserService::listQuery` + `rowMapper` | ✅ |
| `count_users`（可按部门/岗位/状态分组） | `sys:user:list` | `UserService` 新增聚合方法，走同一个模型 | ✅ |
| `get_user` | `sys:user:detail` | `UserService::detail` | ✅ 范围外 = 没找到 |
| `list_depts` | `sys:dept:list` | `DeptService::tree` | ✅ |
| `list_posts` | `sys:post:list` | `PostService::listQuery` + `rowMapper` | ✅ |
| `list_roles` | `sys:role:list` | `RoleService::listQuery` + `rowMapper` | — |
| `search_notices` | `''` | `NoticeService` 收件箱（与每个人看到的公告一致） | ✅ |
| `search_login_logs` | `sys:log:login:list` | `LogService::loginQuery` + `loginRowMapper` | ✅ |
| `search_operation_logs` | `sys:log:operation:list` | `LogService::operationQuery` + `operationRowMapper` | ✅ |
| `system_overview` | `sys:dashboard:view` | `DashboardService::overview` | 内部已按权限裁剪 |

`count_users` 是唯一需要新写查询的：模型不该拉 500 行回来自己数。聚合**必须通过 `SysUserModel::query()`**
（带 Scope）做 `groupBy`，否则分部门人数就会把范围外的部门也数进去——这比泄露明细更隐蔽。

**硬规则（review 按这条查）**：`app/admin/ai/` 与 `app/common/ai/` 下**不允许出现** `withoutDataScope`、
`Db::table`、`DB::select`。加一条 `scripts/acceptance.sh` 里的 grep 断言，出现即失败。

### 5.7 跳转链接

`ToolResult.link` 由工具生成，格式与 ProTable 同步到 URL 的 query 一致（M2 P1），前端直接 `router.push`。
模型在回答里用占位符 `[[link:0]]` 引用，前端替换成按钮——**模型不自己拼 URL**，拼错了就是一个打开空页面的链接；
模型输出里任何其他链接都渲染为纯文本（ai-prd §5.2）。

---

## 6. 系统使用问答

帮助文档放 `server/resources/help/*.md`，随代码发布，一篇一个主题（角色与权限、数据范围、用户导入……），
每篇头部写 `title` 与关联的路由和权限点。

**V1 不做向量检索**：全部帮助文档预计 < 3 万 token，直接放进系统提示词的缓存前缀（§3.2），
缓存命中后的成本很低，而且模型能看到全文，比检索切片更不容易答偏。
超过 10 万 token 时再改成 `search_help` 工具 + 检索。

「有权限才给链接」由后端在拼提示词时做：帮助文档里的页面链接按提问人权限预处理，
没权限的替换成「（需要 xx 权限）」。不交给模型判断。

---

## 7. 运行与流式

### 7.1 一次问答的生命周期

```
POST /admin/ai/messages {content, client_msg_id}
  ├─ ai.enabled？ai:use？配额？月预算？ 正在进行的 run？（409）
  ├─ 事务：写提问消息（seq）+ ai_runs(status=pending)
  ├─ 投递 keel:ai {run_id}
  └─ 202 {message, run_id}

AI 消费进程
  ├─ ai_runs 状态不是 pending → 跳过（队列重投的幂等，与导出同理）
  ├─ status=running，推 ai.run.started
  ├─ AuthService::actAs(user_id) {
  │     拼 messages：system（稳定前缀）+ 一条 user（提问人信息 + 折叠的历史 + 本次提问，§3.4）
  │     loop (≤ maxSteps):
  │        turn = provider.turn(…, onThinking: 推 ai.thinking, onText: 攒 100ms 推一次 ai.delta)
  │        finish=tool_calls → assistant 消息（含 reasoning_content）入栈，执行工具（§5.3），推 ai.step
  │        finish=stop       → break
  │        其他              → 按 §3.5 处理
  │  }
  ├─ 写回答消息（type=ai）+ 更新 ai_runs(done, tokens, cost)
  └─ 推 message.new（与普通消息同一帧，客户端用它替换掉流式气泡）
```

### 7.2 WS 帧

在 chat-tech §5.2 的帧格式上新增，**只推给提问人自己**（`ChatFanout` 的 `userIds = [uid]`，多设备都收到）：

| 帧 | 载荷 |
|---|---|
| `ai.run.started` | `{conv_id, run_id}` |
| `ai.thinking` | `{run_id}`（每一轮进入思考阶段时推一次，不带思考内容） |
| `ai.delta` | `{run_id, text}`（增量，不是全量） |
| `ai.step` | `{run_id, label, rows}` |
| `ai.run.finished` | `{run_id, status}`，随后是一条正常的 `message.new` |

- 增量**按 100ms 合并**再发：模型每秒几十个 token，逐 token 发布会让 Redis 与网关白白多几十倍的消息
- 客户端中途刷新、换设备：只能看到「小k 正在回答…」，拿不到已经输出的部分，完成后 `message.new` 到达。
  为此存半截回答不划算
- 帧丢了不补，最终一致由 `message.new` + `seq` 对齐保证

### 7.3 取消、超时、重启

| 情况 | 处理 |
|---|---|
| 用户点「停止」 | `POST /ai/runs/{id}/cancel` 写 Redis `ai:cancel:<run_id>`；消费进程在每个流事件与每次工具调用前检查（`RunGuard::check()`），命中则让 curl 写回调返回 `-1` 中断连接（思考阶段也在持续收数据，所以回调足够及时；为防连接建立阶段卡住，同时挂 `CURLOPT_XFERINFOFUNCTION` 做同样的检查），已输出的文本落库、`status=stopped` |
| 超时 `ai.run.timeout` | 同一个 `RunGuard` 判墙钟时间，落 `status=failed` + 「回答超时，请缩小问题范围重试」 |
| `reload`/`restart` 时正在跑 | 进程被杀，run 停在 `running`。**提问人下次打开小k 或提问时就地清理**（`AiService::activeRun()`）：`running` 超过「超时 + 60 秒」、或排队超过「两倍超时 + 60 秒」的，改 `failed` 并补一条失败消息。原计划是 `TaskProcess` 每分钟扫一次，没这么做：只有这个人来问的时候才需要知道，每分钟扫全表再写一条任务日志是为罕见情况付常驻成本 |
| 模型服务出错 | 按 §3.5 逐条处理 |

### 7.4 独立的消费进程组

AI 任务**不能和导出、日志清理共用消费进程**：现有队列进程 `QUEUE_WORKERS=2`，两个人同时问小k，
导出就要排队 90 秒。`webman/redis-queue` 一个消费进程组加载 `consumer_dir` 下的全部消费者，
所以新增一组进程，`consumer_dir` 指向 **`app/queue_ai/`**：

⚠️ 不能放 `app/queue/ai/`：插件用 `RecursiveDirectoryIterator` 扫 `consumer_dir`（已 grep vendor 确认），
子目录会被默认消费进程组一起加载，两组进程抢同一个队列，隔离形同虚设。

```php
// config/plugin/webman/redis-queue/process.php 新增一项
'ai-consumer' => [
    'handler' => Webman\RedisQueue\Process\Consumer::class,
    'count'   => Env::int('AI_WORKERS', 4),     // = 全站同时进行的问答上限
    'constructor' => ['consumer_dir' => app_path() . '/queue_ai'],
],
```

- `AI_WORKERS` 就是并发问答上限，超出的在队列里排队（界面上显示「排队中」）。生产机按每进程 ~30MB 估，4 个约 120MB
- 每个进程常驻一条 MySQL 连接，算进 `config/process.php` 注释里那道 `max_connections` 账
- 改进程配置要 `restart` 不是 `reload`

### 7.5 常驻内存红线（PROJECT.md §14）

| 东西 | 放哪 |
|---|---|
| curl 句柄 | 每轮调用新建、用完 `curl_close`。**不复用**：句柄上挂着闭包（写回调引用了本次 run 的解析器），复用等于把上一个 run 的状态带进下一个 |
| DeepSeek 密钥、模型名 | 每个 run 开始时 `ParamService::value()` 读（它走 Redis 缓存、改参数时即删），**不另存进程级 static**——否则在参数配置页改了密钥要 restart 才生效 |
| 提问人、run_id、累积的 messages | 局部变量 / `Ctx`，**job 结束必须清** |
| 工具注册表 | 进程级 static 的**类名列表**，可以；工具实例每个 job 新建 |
| 帮助文档与系统提示词 | 进程级缓存，`reload` 时重读 |

---

## 8. 数据库设计

两张新表，前缀 `ai_`。都**不挂 `HasDataScope`**：审计页只给 `ai:log:list`，而且审计员需要看全公司。

### 8.1 ai_runs 一次问答

```sql
CREATE TABLE `ai_runs` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`             BIGINT UNSIGNED NOT NULL COMMENT '提问人',
  `dept_id`             BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '提问时所在部门，统计用',
  `conv_id`             BIGINT UNSIGNED NOT NULL COMMENT '小k 会话',
  `question_msg_id`     BIGINT UNSIGNED NOT NULL COMMENT '提问消息',
  `answer_msg_id`       BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '回答消息，未完成为 0',
  `status`              TINYINT         NOT NULL DEFAULT 0 COMMENT '0排队 1运行 2完成 3停止 4失败',
  `provider`            VARCHAR(32)     NOT NULL DEFAULT 'deepseek' COMMENT '服务商',
  `model`               VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '模型，如 deepseek-flash（取自参数，记下来是因为参数会被改）',
  `reasoning_effort`    VARCHAR(8)      NOT NULL DEFAULT '' COMMENT '思考强度 none/low/high/max',
  `steps`               TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '工具调用次数',
  `rounds`              TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '模型调用轮数',
  `cache_hit_tokens`    INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '各轮 usage.prompt_cache_hit_tokens 之和',
  `cache_miss_tokens`   INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '各轮 usage.prompt_cache_miss_tokens 之和',
  `output_tokens`       INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '各轮 completion_tokens 之和（含思考）',
  `reasoning_tokens`    INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '其中思考部分',
  `is_peak`             TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否按高峰价计',
  `cost_usd`            DECIMAL(12,6)   NOT NULL DEFAULT 0 COMMENT '估算费用（美元），按 config/ai.php 单价，对账以 DeepSeek 控制台为准',
  `first_token_ms`      INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '首个正文字的延迟（不含思考阶段）',
  `http_status`         SMALLINT        NOT NULL DEFAULT 0 COMMENT '失败时 DeepSeek 的 HTTP 状态码，401/402 要一眼看出来',
  `duration_ms`         INT UNSIGNED    NOT NULL DEFAULT 0,
  `error_msg`           VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '失败原因（给人看的，不含堆栈）',
  `rating`              TINYINT         NOT NULL DEFAULT 0 COMMENT '反馈 0无 1赞 -1踩',
  `feedback`            VARCHAR(500)    NOT NULL DEFAULT '' COMMENT '踩的原因',
  `trace_id`            VARCHAR(32)     NOT NULL DEFAULT '',
  `created_at`          DATETIME        NOT NULL,
  `started_at`          DATETIME        NULL,
  `finished_at`         DATETIME        NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_status_created` (`status`, `created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 问答运行记录';
```

- **不存提问与回答原文，也不存 `reasoning_content`**：前两者在 `im_messages` 里，思考内容会复述查询结果，存下来等于又存一份业务数据（§3.4）
- 单价是小数点后很多位的美元，`DECIMAL(12,6)`；月预算参数 `ai.budget.monthly` 也按美元
- 「一个人同时只能有一个进行中的 run」靠 `AiService::ask()` 里 `lockForUpdate` 提问人的会话行判定，
  不加唯一索引——状态是会变的，唯一索引表达不了「status in (0,1) 时唯一」

### 8.2 ai_tool_calls 工具调用

```sql
CREATE TABLE `ai_tool_calls` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `run_id`          BIGINT UNSIGNED NOT NULL,
  `acting_user_id`  BIGINT UNSIGNED NOT NULL COMMENT '以谁的身份执行（取自 Ctx，不取自 run）',
  `tool`            VARCHAR(64)     NOT NULL,
  `label`           VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '人话描述，审计详情显示',
  `args`            JSON            NULL COMMENT '模型给的参数（校验后）',
  `result_rows`     INT UNSIGNED    NOT NULL DEFAULT 0,
  `result_total`    INT UNSIGNED    NOT NULL DEFAULT 0,
  `denied`          TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '因权限被拒',
  `error_msg`       VARCHAR(255)    NOT NULL DEFAULT '',
  `duration_ms`     INT UNSIGNED    NOT NULL DEFAULT 0,
  `created_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_run` (`run_id`),
  KEY `idx_denied_created` (`denied`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 工具调用审计';
```

- `acting_user_id` 故意从 `Ctx::userId()` 取而不是抄 `run.user_id`：两者不一致就是身份串号，§4.3 的用例正是断言这一列
- 不存结果内容，只存行数

### 8.3 字典、参数、权限点

| 类型 | 编码 | 说明 |
|---|---|---|
| 字典追加 | `im_conv_type` += `3 AI` · `im_msg_type` += `ai` | |
| 字典新增 | `ai_run_status`（0~4）· `ai_rating`（1 / 0 / -1） | 审计页用 |
| 参数（`advanced` 组） | `ai.enabled`（0）· `ai.quota.daily`（50）· `ai.run.maxSteps`（8）· `ai.run.timeout`（90）· `ai.field.unmask`（0）· `ai.budget.monthly`（0 不限，美元） | 写进 `seed.php` |
| 参数（`integration` 组） | `ai.deepseek.apiKey`（**is_secret=1**）· `ai.deepseek.model`（`deepseek-flash`）· `ai.deepseek.reasoningEffort`（`high`） | 见 §3.2。`seed.php` 对已有行本来就只补元信息、不覆盖值（已核对），界面上填的密钥不会被启动时的 seed 清空 |
| 权限点 | `ai:use`（协同下的 type=3，**内置角色默认都不给**）· `ai:log:list`（日志审计 / AI 调用记录，/log/ai）· `ai:log:detail` | 已按 M2.7 的方式核对：路由声明的都在树里 |
| `.env` | `DEEPSEEK_API_KEY` · `DEEPSEEK_MODEL`（参数留空时的回落）· `DEEPSEEK_BASE_URL`（**只在这里**，默认 `https://api.deepseek.com`）· `AI_WORKERS` | `.env.example` 同步补上 |

留存：`ai_runs` / `ai_tool_calls` 接进 `LogCleanupService`，沿用 `sys.log.retainDays`。

---

## 9. 接口

| 方法 | 路径 | 权限 | 说明 |
|---|---|---|---|
| GET | `/admin/ai/conversation` | `ai:use` | 取小k 会话，不存在则创建并写欢迎语（按 `uk_peer` 幂等）。返回会话 + 是否有进行中的 run |
| POST | `/admin/ai/messages` | `ai:use` | 提问。`202 {message, run_id}`；409 有进行中的 run；429 配额（带 `Retry-After` 到次日零点）|
| POST | `/admin/ai/runs/{id}/cancel` | `ai:use` | 停止。非本人的 run 404；已结束的幂等 200 |
| POST | `/admin/ai/reset` | `ai:use` | 新对话（写一条 `ai_reset` 分隔） |
| POST | `/admin/ai/runs/{id}/feedback` | `ai:use` | `{rating, feedback}` |
| GET | `/admin/ai/runs` | `ai:log:list` | 审计列表 |
| GET | `/admin/ai/runs/summary` | `ai:log:list` | 用量汇总（今天、本月、缓存命中率、月预算、最近一次需管理员处理的故障） |
| GET | `/admin/ai/runs/{id}` | `ai:log:detail` | 含工具调用明细，**不含对话内容** |
| POST | `/admin/ai/provider/test` | `sys:param:update` | 参数配置页的「测试连接」：调 DeepSeek `GET /user/balance`，返回 `{ok, is_available, balances:[{currency,total_balance}], error}`。用**已保存**的配置测，不接受前端传密钥（否则这个接口会变成「拿任意密钥去试」的跳板） |

- 历史消息**复用** `GET /admin/chat/conversations/{id}/messages`（成员校验天然成立）
- **不记操作日志**（与聊天同理）：一次对话几十个提问会淹掉真正要审计的动作，而且操作日志会把提问原文存第二份。
  AI 有自己的审计表
- 业务码 `213xx`（紧跟聊天的 `212xx`）：21301 未启用 · 21302 未配置密钥 · 21303 有进行中的问答 · 21304 配额用尽 · 21305 月预算用尽 · 21306 问题超长

---

## 10. 前端

### 10.1 web

- `views/chat/` 下新增 `AiConversation.vue`，被聊天页右栏按 `is_ai` 切换进来，不新开路由
- `stores/ai.ts`：当前 run 状态、流式缓冲；监听 `ai.*` 帧。`message.new` 到达且 `extra.run_id` 等于当前 run 时，丢弃缓冲、以落库消息为准
- Markdown：引入 `markdown-it`（禁用 `html`、禁用图片规则）+ 链接白名单（只认 `[[link:n]]` 占位符）。
  **不用 `v-html` 直接渲染模型输出**——渲染器的输出是唯一可以进 `v-html` 的东西
- 首次使用的数据外发说明：`localStorage` 记一个已读标记即可，丢了大不了再弹一次

### 10.2 staff

这一版不做（2026-09-23 定）。服务端接口不分端，将来补移动端时：`[[link:n]]` 渲染为纯文本（后台页面在 staff 里不存在），流式输出要真机验（WebView 频繁 setData，节流到 200ms）。

---

## 11. 风险清单

| 风险 | 后果 | 对策 |
|---|---|---|
| 消费进程里身份串号 | 普通员工拿到超管的数据，**且没有任何报错** | `AuthService::actAs` 进入前断言干净、`finally` 清理；§4.3 专项用例（已验）；`acting_user_id` 审计列，审计详情里不一致会标红 |
| 工具里有人顺手写了 `withoutDataScope` / 原生 SQL | 数据权限对小k 失效 | acceptance.sh 里 grep 断言 |
| 聚合查询绕过 Scope | 分部门人数泄露范围外的组织规模 | 聚合只能在带 Scope 的模型上做；权限矩阵用例覆盖 `count_users` |
| 提示词注入 | 模型说出不该说的话 | 只读 + 工具层权限 + 脱敏，不依赖提示词（ai-prd §8.3） |
| 模型编造数字 | 用户据此决策 | 系统提示词要求数字只能来自工具结果；回答附「查询了 N 项」可核对；免责小字；👎 反馈回收 |
| DeepSeek 余额耗尽（402）/ 密钥失效（401） | 全员小k 失败 | 「测试连接」显示余额；失败时提醒超管（§3.5）；失败不影响其他模块 |
| 有 `sys:param:update` 的人滥用 | 换掉密钥 / 改模型 | 密钥只写不读；base URL 不进参数表（§3.2）；该权限点只给运维 |
| 漏传 `reasoning_content` | 带工具的第二轮起全部 400 | 跨 run 历史折叠进 user 消息（§3.4）；第 ① 批专门测多轮工具调用 |
| DeepSeek 改模型名 / 参数 | 请求报错或被悄悄转到别的模型 | 模型名是参数，改了不用发版；`ai_runs.model` 记实际用的；每次升级前重核官方文档 |
| 成本失控 | 账单 | 每人每日配额 + 月预算 + 缓存 + 每次问答的 cost 可查 |
| 长任务阻塞导出队列 | 导出排队 | 独立消费进程组（§7.4） |
| reload 杀掉进行中的 run | 永远「思考中」 | 提问人下次来时就地判失败（§7.3） |

---

## 12. 实施拆分

与 ai-prd §10 对应。每一批的收尾都是：`vue-tsc` + `vite build` + 权限矩阵脚本 + 补 CHANGELOG。

| 批 | 服务端 | 前端 | 验证 |
|---|---|---|---|
| ① | `AuthService::actAs` 并迁移导出；`LlmProvider` + `DeepSeekProvider`（curl + SSE 解析）；AI 参数与 `.env` 回落；「测试连接」接口；`ai_runs`/`ai_tool_calls`；AI 消费进程组；配额 | 参数配置页「测试连接」按钮 | 生产机 curl `/user/balance`；CLI 脚本以两个身份提问；§4.3 串号用例；**连续 3 轮工具调用不 400**；SSE 在多字节字符处被切断时解析正确；**权限矩阵脚本先写** |
| ② | `type=3` 会话；提问/取消接口；WS 帧；3 个工具 | web 小k 会话 + 流式 | 浏览器里问答；并发 10 人时列表接口 P95 |
| ③ | 其余工具；`DataScope::describe()`；链接；AI 脱敏；grep 断言 | 步骤折叠、链接按钮、Markdown | 权限矩阵全部用例 |
| ④ | 帮助文档 + 链接预处理 | 来源标注 | 覆盖/越界问题各 10 条人工核对 |
| ⑤ | 审计接口；卡死 run 的就地清理；日志清理 | 审计页、反馈 | reload 补偿 |

**① 必须在 ② 之前完成且测过**：身份传递错了，后面每一个工具都是漏洞，而界面做出来以后会很想「先上再说」。

---

## 13. 待定

| 问题 | 倾向 |
|---|---|
| 模型与思考强度 | 默认 `deepseek-flash` + `high`。用 30~50 条真实问题实测准确率、首字延迟、单次成本，对比 `low` 与 `deepseek-v4-pro`，**不凭感觉调**。两者都是参数，改了即生效 |
| 跨 run 历史折叠后追问理解是否够用 | 第 ② 批用 20 组追问实测（§3.4） |
| 问答上下文是否带上一问的工具结果 | 不带（ai-prd §5.3）。若实测追问准确率明显差，再考虑带上一问的摘要 |
| `ai_runs.cost_usd` 的单价来源 | `config/ai.php` 手写单价表（含高峰/非高峰），调价时手改。不去实时拉价格 |

---

## 14. 实施记录（2026-09-23）

五批在一次里做完了，只做后台。与上文设计的偏差：

| 设计 | 实际 | 为什么 |
|---|---|---|
| `Ctx::actAs()` | `AuthService::actAs()` | `support` 不该依赖 `service`；导出已改为调它，全仓一份 |
| TaskProcess 每分钟扫卡死的 run | 提问人下次来时就地清理 | 见 §7.3 |
| 故障时「提醒超管」 | 审计页红色横幅 + error 日志；测试连接通过或有问答正常完成后撤下 | 没有只发给超管的通知通道，公告会发给全员 |
| 提问进操作日志（原文脱敏） | 不进 | 见 §9 |
| 审计页在「系统监控」 | 「日志审计 / AI 调用记录」（/log/ai） | 它回答的是「发生了什么」；系统里本来就没有「系统监控」这个目录 |
| `max_tokens` 8192 | 32768（关闭思考时 4096） | 思考 token 也算在里面 |
| Markdown 用 markdown-it | 自写 `utils/aiMarkdown.ts`（先整段转义再做有限替换） | 用到的语法只有几样；转义在前，结果可直接 v-html。主 chunk 已超 1MB，不再加依赖 |
| 首次使用弹窗说明 | 消息区顶部一条说明，点「知道了」后不再显示 | 不打断第一次提问 |

**文件地图**

| 位置 | 内容 |
|---|---|
| `server/app/common/ai/` | `DeepSeekProvider`（curl + SSE）· `SseParser` · `AiRunner`（循环）· `ToolBox`（权限两道、脱敏、审计）· `Prompt` · `RunGuard` |
| `server/app/admin/ai/` | 12 个查询工具，登记在 `config/ai.php` |
| `server/app/common/service/AiService.php` | 会话、提问、停止、配额、预算、审计查询、测试连接 |
| `server/app/queue_ai/AiRunConsumer.php` | 独立消费进程组（`AI_WORKERS`） |
| `server/resources/help/*.md` | 帮助文档，全文进系统提示词 |
| `web/src/views/chat/AiPanel.vue` | 小k 面板 |
| `web/src/views/log/ai/index.vue` | AI 调用记录 |
| `scripts/ai-acceptance.sh` + `server/scripts/ai-mock-deepseek.php` | 验收脚本与模拟 DeepSeek |

**踩过的坑**

| 坑 | 结论 |
|---|---|
| AI 消费者放 `app/queue/ai/` | 插件递归扫 consumer_dir，会被默认那组也加载。放 `app/queue_ai/` |
| 验收脚本 `wait_run` 里用 `i` 计数 | sh 没有局部变量，把调用方循环的 `i` 重置了，死循环跑出两千多次问答。函数里的计数变量要起不会撞的名字 |
| 在 `docker compose exec sh -c '… &'` 里起模拟服务 | exec 会话结束进程就被带走，用 `exec -d` |
| 自动化浏览器里小k 红点不消 | 不是 bug：那个标签页 `document.hidden = true`，按「窗口在后台不算已读」本来就不该清 |

