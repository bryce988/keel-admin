# Keel · 即时通讯（IM）技术方案

> 版本 v1.0 · 2026-09-21 · 目标里程碑 **M5**
> **功能已全部完成**。剩下的是性能与压测（虚拟滚动、2000 连接 30 分钟）——
> 用户明确表示暂不需要，拆成第 ⑦ 批挂起。
> ⚠️ **移动端一次真机都没验过**，全程只到「接口通 + SFC 编译过」。
> 配套文档：[产品文档](chat-prd.md) · [接口契约](api.md) · [数据库设计](database.md) · [项目文档](../PROJECT.md)
>
> 本文定义**怎么实现**。做到哪儿为止看 [chat-prd.md](chat-prd.md)。
> 设计一旦落地，接口写进 `api.md` §15、建表写进 `database.md` §3.17，本文退回成「为什么这么做」的存档。

---

## 1. 总体架构

```
                   ┌──────────────────────────────────────┐
   浏览器 / App ──▶ │ nginx :8080                          │
                   │  /admin /staff  → server:8787  (HTTP) │
                   │  /ws            → server:8788  (WS)   │
                   └───────────┬──────────────────────────┘
                               │
        ┌──────────────────────┴───────────────────────┐
        │                                              │
┌───────▼─────────┐                        ┌───────────▼──────────┐
│ webman HTTP     │   ①写库 ②发布           │ ChatGateway 进程      │
│ 进程 (count=N)   │ ──────────────────▶    │ (WebSocket, count=M)  │
│                 │      Redis pub/sub     │  进程内：连接注册表     │
│ ChatService     │                        │  下行：推给在线成员     │
└───────┬─────────┘                        └───────────┬──────────┘
        │                                              │
        └───────────────▶ MySQL ◀──────────────────────┘
                      （消息的唯一事实来源）
```

**三条主线**

1. **发消息走 HTTP，不走 WebSocket 上行。** 客户端 `POST /admin/chat/…/messages`，
   服务端在事务里生成序号并落库，返回消息本体
2. **落库成功后**向 Redis 频道 `im:fanout` 发布一条投递指令
3. **ChatGateway 进程**订阅该频道，把消息推给自己持有的在线成员连接

WebSocket **只承担下行推送**，是一条「快路径」。连接断了、消息漏了，客户端用
HTTP 的增量拉取补齐——**长连接不可靠，数据库可靠**，整套可靠性建立在后者上。

### 1.1 为什么上行不走 WebSocket

这是本方案最重要的一个取舍，省掉了 IM 里最容易出错的一半代码。

| 走 HTTP 发消息 | 走 WS 上行发消息 |
|---|---|
| 复用现成的鉴权、权限、限流、操作日志、幂等（`Idempotency-Key`）中间件 | 这六样要在 WS 里各写一遍 |
| 错误直接用 `api.md` §2 的状态码 + 业务码，前端拦截器不用改 | 要自造一套帧内错误协议 |
| 发送失败的语义就是 HTTP 请求失败，重试策略是现成的 | 要自己做 ACK、超时、重传、去重 |
| 代价：每条消息多一次 HTTP 往返（内网 < 5ms） | 省下的那几毫秒买不到上面任何一条 |

`app/staff` 的 `RateLimitMiddleware` 和后台的 `OperationLogMiddleware` 尤其关键——
走 WS 上行等于让聊天成为全站唯一一条不受这两层治理的写路径。

### 1.2 为什么自建 WS 进程，而不是 webman/push 或 GatewayWorker

| 方案 | 结论 |
|---|---|
| `webman/push` | 官方推送插件，自带频道订阅。但它是**通用推送通道**，没有会话语义，鉴权也是它自己那套 app_key；我们要的是「按 user_id 精确投递 + 复用 JWT」，接进来要改的地方比自己写还多 |
| GatewayWorker | 为大规模分布式长连接设计（Register / Gateway / BusinessWorker 三层）。它解决的是**多机水平扩展**，而我们的生产是一台 2 核 3.6G 的机器，2000 连接单机绰绰有余。多引一套进程模型换一个用不上的能力 |
| **自定义 webman 进程（选）** | Workerman 本来就支持 `websocket://` 协议，注册一个自定义进程即可。栈内已有 Redis 做跨进程广播，部署只多一个内部端口 |

将来真要多机，把「连接注册表 + 投递」换成 GatewayWorker 或独立网关，
**HTTP 侧与数据库一行都不用改**——因为业务逻辑不在网关里。这是分层的好处，写方案时就留好。

---

## 2. 消息可靠性模型

### 2.1 会话内单调序号 `seq`

每条消息在**所属会话内**有一个从 1 开始、严格连续的 `seq`。它是整套可靠性的地基：

- 客户端只要发现本地序号有空洞（收到 seq=42，本地最大是 39），就用 HTTP 补拉 40~41
- 未读数 = `conversation.max_seq − member.last_read_seq`，不用存计数
- 历史翻页用 `before_seq` 游标，不用 offset（消息表会很大，offset 分页迟早拖垮）
- 断线重连后只要说一句「我读到 seq=39」，服务端就知道该补什么

**怎么生成**：在事务里对会话行加锁自增。

```php
DB::transaction(function () use ($convId, $payload) {
    // 行锁：同一会话的并发发送在这里串行化，正是我们要的
    $conv = ChatConversationModel::lockForUpdate()->find($convId);
    $seq  = $conv->max_seq + 1;
    // … insert message with $seq …
    $conv->update(['max_seq' => $seq, 'last_msg_id' => $id, 'last_msg_at' => $now, …]);
});
```

**为什么不用 Redis `INCR`**：快，但 Redis 与 MySQL 之间没有事务。
Redis 发了号而落库失败，这个号就永远空了——而客户端会一直以为少了一条消息，
反复补拉。要修就得再写一套对账，比行锁贵得多。
单会话的写入天然是低频的（人打字能有多快），行锁的竞争只在同一个会话内，不影响全局。

⚠️ 建表时 `uk_conv_seq (conv_id, seq)` 是**硬兜底**：即使上面的逻辑哪天被改错，
数据库也不会让重号落地——表现为一次 500，而不是静默的消息错乱。后者排查起来要命。

### 2.2 客户端幂等 `client_msg_id`

客户端发消息时带一个自己生成的 UUID。服务端 `uk_client_msg (sender_id, client_msg_id)`
保证同一条消息重发多少次库里都只有一条，撞唯一索引时**直接返回已存在的那条**（200，不是 409）——
对用户来说重发成功了，这是正确行为。

这条覆盖了「点了发送但响应超时，用户又点一次」这个最常见的重复来源。

### 2.3 对齐流程（重连 / 刷新 / 换设备）

```
客户端启动或重连
  ├─ GET /admin/chat/conversations           拿全部会话与各自 max_seq
  ├─ 逐个比对本地 last_seq，有差就
  │    GET /…/messages?after_seq=<本地>      补齐
  └─ 建立 WebSocket，之后走推送
```

**先拉后连，还是先连后拉？先连后拉。** 顺序反了会丢消息：
拉取和建连之间的那几百毫秒里到达的消息，谁也不负责。
先建连（这期间收到的推送先进本地缓冲区），再拉历史，最后把缓冲区按 seq 归并——空洞自动被补上。

---

## 3. 数据库设计

四张表，前缀 `im_`（系统表才用 `sys_`，见 database.md §1）。

### 3.1 im_conversations 会话

```sql
CREATE TABLE `im_conversations` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `type`          TINYINT         NOT NULL COMMENT '1单聊 2群聊',
  `peer_key`      VARCHAR(48)     NULL     DEFAULT NULL COMMENT '单聊唯一键 min(uid):max(uid)，群聊为 NULL',
  `name`          VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '群名称，单聊为空（展示用对方昵称）',
  `avatar`        VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '群头像',
  `owner_id`      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '群主，单聊为 0',
  `member_count`  INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '成员数，冗余',
  `max_seq`       BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会话内最大消息序号',
  `last_msg_id`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最后一条消息 ID',
  `last_msg_at`   DATETIME        NULL     COMMENT '最后一条消息时间，列表排序用',
  `last_msg_text` VARCHAR(128)    NOT NULL DEFAULT '' COMMENT '最后一条消息摘要，冗余，避免列表 N+1',
  `status`        TINYINT         NOT NULL DEFAULT 1 COMMENT '0已解散 1正常',
  `creator_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人',
  `updater_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最后修改人',
  `created_at`    DATETIME        NOT NULL COMMENT '创建时间',
  `updated_at`    DATETIME        NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_peer` (`peer_key`),
  KEY `idx_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会话';
```

- **`uk_peer` 是单聊「一对人只有一个会话」的保证**，不是靠先查后插。
  两个人互相同时点「发消息」是真会发生的（尤其配合前端重试），先查后插挡不住
- `peer_key` 用 `min:max` 而不是两行记录：`12:87` 无论谁发起都是同一个键
- ⚠️ **群聊的 `peer_key` 必须是 `NULL`，不能是空串**。MySQL 唯一索引对 NULL 不去重
  （正是群聊要的），但空串只能存在一个——第二个群就会撞 `uk_peer`，
  表现为「建第一个群正常，建第二个群 500」。所以这一列是 `NULL DEFAULT NULL` 而不是
  全站惯用的 `NOT NULL DEFAULT ''`，这是本表唯一一处偏离 database.md §1 的地方
- `last_msg_text` 是冗余摘要。不冗余的话，会话列表要为每个会话回查一次消息表
  （20 条会话 = 21 次查询）。代价是撤回时要同步更新这一列
- **不挂 `HasDataScope`**，与 `sys_notices` 同理：会话不属于任何部门，
  按部门过滤会让跨部门聊天直接断掉。可见性由 §3.2 的成员表决定
- **不用软删**：解散群用 `status=0`，删会话是成员表上的操作，都不是删这一行

### 3.2 im_conversation_members 会话成员

```sql
CREATE TABLE `im_conversation_members` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `conv_id`       BIGINT UNSIGNED NOT NULL COMMENT '会话 ID',
  `user_id`       BIGINT UNSIGNED NOT NULL COMMENT '成员 ID（sys_users）',
  `role`          TINYINT         NOT NULL DEFAULT 0 COMMENT '0成员 1群主',
  `last_read_seq` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已读水位，未读数由此算出',
  `min_seq`       BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '可见起始序号，删除会话/清空记录时抬高',
  `join_seq`      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '入群时的序号，预留「只看入群后消息」策略',
  `is_pinned`     TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '置顶',
  `is_muted`      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '免打扰',
  `is_visible`    TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '是否出现在会话列表（删除会话置 0，来新消息自动置 1）',
  `at_seq`        BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近一次被 @ 的序号，> last_read_seq 时列表显示「@」',
  `quit_at`       DATETIME        NULL     COMMENT '退群/被踢时间，非空表示已不是成员',
  `created_at`    DATETIME        NOT NULL COMMENT '入会话时间',
  `updated_at`    DATETIME        NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_conv_user` (`conv_id`, `user_id`),
  KEY `idx_user_visible` (`user_id`, `is_visible`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会话成员';
```

**这张表是整个模块的可见性边界**。所有读写接口第一件事就是查它：
`(conv_id, user_id, quit_at IS NULL)` 命中才继续，否则 404。

- `uk_conv_user` 既防重复入群，也是那次鉴权查询的索引——一箭双雕，与 `sys_notice_reads` 同形
- 退群用 `quit_at` 而不是删行：删了就查不到「他当时在群里」，
  审计和「历史消息里显示的昵称」都会失去依据
- `min_seq` 让「删除会话」「清空聊天记录」变成一次 UPDATE，不动消息表。
  代价是查询要多带一个 `seq > min_seq` 条件，走的还是 `uk_conv_seq` 索引
- `at_seq` 只记**最近一次**被 @ 的序号。存列表的话，@ 一次写一行，
  而产品只需要回答「有没有未读的 @」这一个问题

### 3.3 im_messages 消息

```sql
CREATE TABLE `im_messages` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `conv_id`       BIGINT UNSIGNED NOT NULL COMMENT '会话 ID',
  `seq`           BIGINT UNSIGNED NOT NULL COMMENT '会话内序号，从 1 连续递增',
  `sender_id`     BIGINT UNSIGNED NOT NULL COMMENT '发送人，系统消息为 0',
  `sender_name`   VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '冗余，发送人改名/离职后历史仍可读',
  `type`          VARCHAR(16)     NOT NULL DEFAULT 'text' COMMENT 'text/image/file/system，字典 im_msg_type',
  `content`       TEXT            NOT NULL COMMENT '文本内容；图片文件类型存文件名等展示文本',
  `extra`         JSON            NULL     COMMENT '附件与扩展：{url,size,width,height,at_user_ids,...}',
  `client_msg_id` CHAR(36)        NOT NULL DEFAULT '' COMMENT '客户端幂等 ID（UUID）',
  `status`        TINYINT         NOT NULL DEFAULT 1 COMMENT '1正常 2已撤回',
  `recalled_by`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '撤回人，区分本人撤回与群主撤回',
  `recalled_at`   DATETIME        NULL     COMMENT '撤回时间',
  `created_at`    DATETIME        NOT NULL COMMENT '发送时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_conv_seq` (`conv_id`, `seq`),
  UNIQUE KEY `uk_client_msg` (`sender_id`, `client_msg_id`),
  KEY `idx_conv_created` (`conv_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='聊天消息';
```

- **没有 `updater_id` / `updated_at`**：消息不可编辑。撤回是状态变更，有自己的两个字段。
  审计字段一律照抄反而会误导——看到 `updated_at` 的人会以为消息能改
- **没有 `deleted_at`**：删除是成员侧的 `min_seq`，撤回是 `status`。
  真正的物理删除只发生在留存期清理，那是 `DELETE` 不是软删
- 撤回**保留 `content`**，只是不再下发（见 chat-prd.md §5.5 与 §8）
- `extra` 用 JSON 而不是另开附件表：一条消息最多一个附件，独立表只会让每次查询多一次 JOIN。
  真出现「一条消息多附件」的需求再拆
- ⚠️ **MySQL 8 的 JSON 不能建普通索引**，所以 `extra` 里的任何字段都不能作为查询条件。
  需要查的（比如「谁被 @ 过」）必须提成独立列。V1 没有这种查询
- `client_msg_id` 默认空串：系统消息（入群、改群名）没有客户端 ID。
  ⚠️ 空串会撞 `uk_client_msg`——**系统消息的 `sender_id` 是 0**，
  多条系统消息的 `(0, '')` 全部重复。所以系统消息插入时必须**生成一个服务端 UUID** 填进去，
  不能留空。这条不写出来 100% 会在 M5.3 建群时撞 500

### 3.4 会话与序号的并发要点

| 场景 | 保证手段 |
|---|---|
| 两人同时对同一会话发消息 | `lockForUpdate()` 行锁串行化 |
| 两人同时发起同一个单聊 | `uk_peer` 唯一索引，撞了就改查已有的返回 |
| 同一条消息重复提交 | `uk_client_msg`，撞了返回已有消息（200） |
| 同一人两个标签页同时标已读 | `last_read_seq` 用 `GREATEST(last_read_seq, ?)` 更新，只增不减 |

`GREATEST` 那条别漏：两个标签页乱序提交时，后到的小值会把已读水位**倒退**，
表现为「明明读过了，红点又冒出来」。

### 3.5 字典与参数

| 类型 | 编码 | 值 |
|---|---|---|
| 字典 | `im_msg_type` | `text` 文本 / `image` 图片 / `file` 文件 / `system` 系统 |
| 字典 | `im_conv_type` | `1` 单聊 / `2` 群聊 |
| 参数 | `chat.message.retainDays` | 365，留存天数 |
| 参数 | `chat.message.maxLength` | 5000，单条文本上限 |
| 参数 | `chat.group.maxMembers` | 200，群人数上限 |
| 参数 | `chat.rateLimit.perMinute` | 20，每人每分钟发送上限 |

---

## 4. 接口设计

前缀 `/admin/chat`（移动端 `/staff/v1/chat`，形状一致）。
**完全遵循 `api.md` §1**：字段 `snake_case`，成功 2xx 直返数据体，错误带 `{code, message, trace_id}`。

### 4.1 接口清单

| 方法 | 路径 | 说明 | 权限点 |
|---|---|---|---|
| GET | `/admin/chat/conversations` | 我的会话列表（含 `max_seq`、`unread`、`has_at`） | `chat:use` |
| POST | `/admin/chat/conversations` | 建会话：`{type, user_ids[], name?}`；单聊已存在则返回已有（200 而非 201） | `chat:use` |
| GET | `/admin/chat/conversations/{id}` | 会话详情（群成员列表） | `chat:use` |
| PUT | `/admin/chat/conversations/{id}` | 改群名 / 群头像 | `chat:use` |
| DELETE | `/admin/chat/conversations/{id}` | 从我的列表移除（置 `is_visible=0`、抬 `min_seq`） | `chat:use` |
| PUT | `/admin/chat/conversations/{id}/settings` | 置顶 / 免打扰 | `chat:use` |
| POST | `/admin/chat/conversations/{id}/members` | 加人（群主） | `chat:use` |
| DELETE | `/admin/chat/conversations/{id}/members/{uid}` | 踢人 / 退群 | `chat:use` |
| GET | `/admin/chat/conversations/{id}/messages` | 历史：`?before_seq=&after_seq=&limit=30` | `chat:use` |
| POST | `/admin/chat/conversations/{id}/messages` | 发消息 | `chat:use` |
| POST | `/admin/chat/conversations/{id}/read` | 标已读：`{last_read_seq}` | `chat:use` |
| POST | `/admin/chat/messages/{id}/recall` | 撤回 | `chat:use` |
| GET | `/admin/chat/unread` | 全局未读汇总（顶栏红点用，轻量） | `chat:use` |

同事名片两端各一个入口、同一份 `ContactService::detail`：后台 `GET /admin/contacts/{id}`，
移动端 `GET /staff/v1/chat/contacts/{id}`，权限点都是 `contact:view`（看资料是通讯录的能力，不是聊天的）。
只收在职员工，停用或不存在一律 404；手机号、邮箱受字段级权限管。

**会话对象里的 `peer_active`**：单聊对方是否在职（未停用且未删除），群聊恒为 `true`。
为 `false` 时前端把输入框换成「对方已离职」提示，历史照常可看；发消息接口同样会拦（400 + `21205`），
前端那一层只是界面收敛。

**只有一个权限点 `chat:use`**，不按增删改查拆。聊天的边界是「在不在这个会话里」，
拆成 `chat:message:add` / `chat:message:recall` 只会制造一堆永远一起授予的权限点。

⚠️ M2.7 的教训照旧适用：**路由声明了 `perm` 但权限树里没有 = 谁也授不了 = 永久 403**。
`chat:use` 必须同时出现在 `route.php` 与权限点种子数据里。

### 4.2 发消息（核心接口）

```http
POST /admin/chat/conversations/12/messages
Authorization: Bearer <token>
Idempotency-Key: 8f21c4d9-…        # 可选，与 client_msg_id 双保险
```
```json
{
  "client_msg_id": "8f21c4d9-3b1e-4c77-9a02-5d6e7f801234",
  "type": "text",
  "content": "下午三点的评审改到四点",
  "extra": { "at_user_ids": [87] }
}
```

```http
HTTP/1.1 201 Created
```
```json
{
  "id": 90412,
  "conv_id": 12,
  "seq": 305,
  "sender_id": 34,
  "sender_name": "张明",
  "type": "text",
  "content": "下午三点的评审改到四点",
  "extra": { "at_user_ids": [87] },
  "client_msg_id": "8f21c4d9-…",
  "status": 1,
  "created_at": "2026-09-20 15:12:08"
}
```

**服务端处理顺序**（顺序本身就是设计，不能调换）：

```
1. 成员校验            不是成员 → 404
2. 参数与长度校验      超长 → 422
3. 频率限制            超限 → 429 + Retry-After
4. 文本净化            见 §4.4
5. 事务：行锁取 seq → 插消息 → 更新会话冗余字段
6. 事务提交之后        才发 Redis 广播
7. 返回消息本体
```

⚠️ **第 6 步必须在事务提交之后**。在事务里发广播，接收端可能在事务还没提交时就来拉这条消息，
拉不到——表现是「偶尔收到通知但没有内容」，而且只在高并发下出现，本地永远复现不了。

### 4.3 可见性校验的唯一入口

```php
// app/common/service/ChatService.php
private function assertMember(int $convId, int $userId): ChatConversationMemberModel
{
    $m = ChatConversationMemberModel::where('conv_id', $convId)
        ->where('user_id', $userId)
        ->whereNull('quit_at')
        ->first();
    // 404 而不是 403：403 等于确认这个会话存在（api.md §2.1 的一贯做法）
    return $m ?? throw ApiException::notFound();
}
```

**每个接口的第一行都调它**，没有例外，包括超级管理员。
将来要加「可达范围」策略（产品文档 §4 提到的实习生限制），加在**建会话**那一步，
而不是这里——这里只回答「已经建好的会话你在不在里面」。

### 4.4 内容安全

聊天文本**按纯文本处理**：存原文，前端渲染时转义。
**不复用 `support/Html.php` 的富文本白名单**——那是给系统公告的富文本编辑器用的，
聊天没有富文本需求，引白名单反而意味着某些标签会被真的渲染出来。

- 链接识别在**前端**做（正则匹配后渲染成 `<a>`，`rel="noopener noreferrer"`）
- 图片 / 文件的 `url` 必须校验前缀在 `/uploads/chat/` 之内，
  防止把任意路径塞进 `extra`（换头像接口踩过同类问题，见 api.md §11.1）
- 附件下载沿用 nginx 的 `location ^~ /uploads/`

### 4.5 业务码

后台段 `20000-29999` 内新开 `212xx`：

| HTTP | code | message | 场景 |
|---|---|---|---|
| 404 | `10404` | 数据不存在或已被删除 | 非会话成员、会话已解散（复用通用码，不单开） |
| 400 | `21201` | 消息内容超出长度限制 | 超过 `chat.message.maxLength` |
| 400 | `21202` | 消息已超过可撤回时间 | 超 2 分钟且不是群主 |
| 403 | `21203` | 只有群主可以执行该操作 | 踢人、改群名、解散、@所有人 |
| 400 | `21204` | 群成员数量已达上限 | 超过 `chat.group.maxMembers` |
| 400 | `21205` | 不能与已停用的员工发起会话 | 离职员工：发起单聊，或往已有单聊里发消息（此时 message 是「对方已离职，无法发送消息」） |
| 400 | `21206` | 不能和自己发起会话 | |
| 409 | `21207` | 该成员已在群中 | |
| 429 | `10429` | 操作过于频繁 | 复用通用码，带 `Retry-After` |

码表以 `app/common/constant/BizCode.php` 为准，本文只做说明（api.md §2.2 的约定）。

---

## 5. WebSocket 协议

### 5.1 连接与鉴权

```
ws://<host>:8080/ws?token=<access_token>
```

- **token 走 query 不走头**：浏览器的 `WebSocket` 构造函数不支持自定义请求头，这是硬限制。
  代价是 token 可能进 nginx access_log，所以 **nginx 的 `/ws` location 必须关掉 access_log
  或过滤 query**，这条写进部署清单
- 握手时用 `JwtService` 验签，校验 `type=admin`（与 C 端 token 互调一律 401，PROJECT.md §8.4）
- 验不过直接 `close(4001)`，不留半开连接
- 一个用户可以有多条连接（多标签页 + 手机），消息**推给他的全部连接**

### 5.2 帧格式

统一 `{ "ev": "<事件名>", "data": { … } }`，字段仍是 `snake_case`。

**下行**

| 事件 | data | 客户端动作 |
|---|---|---|
| `ready` | `{ user_id, server_time }` | 握手完成，开始对齐流程 |
| `message.new` | 消息本体（与 §4.2 响应同构） | 按 seq 插入；有空洞则补拉 |
| `message.recalled` | `{ conv_id, seq, recalled_by }` | 替换为撤回提示 |
| `conversation.updated` | `{ conv_id, … }` | 群名变更、成员变动 |
| `conversation.read` | `{ conv_id, last_read_seq }` | 多端同步已读，更新「已读」标记 |

**上行**：只有 `ping`。发消息、标已读全部走 HTTP。
服务端每 30 秒无帧则发 `ping`，连续两次无 `pong` 判定为死连接并清理。

### 5.3 跨进程投递

```
HTTP 进程（事务提交后）
   └─ Redis PUBLISH im:fanout {"conv_id":12,"user_ids":[34,87],"payload":{…}}
                      │
      ChatGateway × M （每个进程都订阅，各自过滤自己持有的连接）
```

- `user_ids` 由 HTTP 侧算好（一次 `im_conversation_members` 查询），
  **不让网关去查库**——网关进程要保持"无业务逻辑"，这样将来换网关实现时它才是可替换的
- 群规模 200 时 payload 里有 200 个 id，一条消息约 2KB，可接受
- Redis 的 pub/sub 是**不可靠投递**（订阅方不在就丢）。这是可以接受的：
  丢了的后果是客户端暂时没收到推送，下次对齐时补齐。**可靠性不在这条通道上**

### 5.4 连接注册表 —— webman 常驻内存红线

网关进程里维护两个 map：

```php
// app/process/ChatGateway.php
private array $connections = [];   // connectionId => ['uid' => int, 'conn' => TcpConnection]
private array $userIndex   = [];   // uid => [connectionId => true]
```

⚠️ **这是全项目最容易泄漏内存的一处**（PROJECT.md §14）。硬性要求：

1. `onClose` 里**两个 map 都要清**。只清 `connections` 会让 `userIndex` 越积越大，
   而且它是嵌套数组——泄漏速度比想象的快
2. `userIndex[$uid]` 清空后要 `unset($userIndex[$uid])`，
   留一个空数组在那里等于按用户数泄漏
3. 心跳超时的连接必须主动 `close()`，触发 `onClose` 走同一条清理路径。
   **不要在超时处理里直接 unset** ——两条清理路径迟早会不一致
4. 验收项：关掉所有浏览器标签后，`count($connections)` 与 `count($userIndex)` 都归零。
   这条要能用运维接口查到（`GET /internal/chat/stats`，`/internal/*` 不对公网暴露）

进程配置：

```php
'chat-gateway' => [
    'handler'    => app\process\ChatGateway::class,
    'listen'     => 'websocket://0.0.0.0:8788',
    // count 暂定 2。长连接是 I/O 复用模型，不像 HTTP 那样被查库阻塞，
    // 进程数不需要跟着核数走。2000 连接 / 2 进程 = 每进程 1000，绰绰有余。
    // ⚠️ count > 1 时每个进程各持一部分连接，所以广播必须走 Redis 而不是进程内变量
    'count'      => Env::int('CHAT_GATEWAY_COUNT', 2),
    'reloadable' => true,
],
```

⚠️ `reloadable => true` 意味着 `reload` 会重启网关、**断开所有长连接**。
这是可接受的（客户端 30 秒内自动重连，M4 实测 reload 停机窗口为 0 秒），
但前端的重连退避必须做对，否则几千个客户端会在同一秒重连把服务打垮——见 §6.2。

---

## 6. 前端设计（web）

### 6.1 结构

```
web/src/
├── views/chat/
│   ├── index.vue              两栏容器
│   ├── components/
│   │   ├── ConversationList.vue
│   │   ├── MessageList.vue     虚拟滚动 + 向上翻页
│   │   ├── MessageItem.vue     按 type 分发渲染
│   │   ├── MessageInput.vue    粘贴图片 / 拖拽文件 / @ 面板
│   │   └── MemberPicker.vue    复用部门树选人
├── stores/chat.ts             会话、消息、未读的单一数据源
└── utils/chatSocket.ts        WS 客户端：连接、心跳、重连、事件派发
```

- `chatSocket.ts` **只管连接，不碰业务**：收到帧后派 `store` 的 action。
  混在一起的话，重连逻辑和消息归并逻辑会互相纠缠，是 IM 前端最常见的烂泥
- 消息列表**不用 `ProTable`**。它是分页表格的抽象，聊天要的是倒序无限滚动 + 粘底，
  两者的滚动语义完全相反
- 未读数只有一个来源：`stores/chat.ts` 的 getter。顶栏红点、列表角标、
  浏览器标题三处都从它派生，**不允许任何一处自己算**（产品验收明确要求三者一致）

### 6.2 重连退避

```ts
// 指数退避 + 抖动。没有抖动的话，一次 reload 会让所有客户端在同一秒重连
const delay = Math.min(1000 * 2 ** attempt, 30_000) * (0.5 + Math.random())
```

- 页面 `visibilitychange` 回到前台时立即尝试一次（用户切回来就想看到最新的）
- `navigator.onLine` 为 false 时不重试，等 `online` 事件
- 重连成功后**必须走一次完整对齐**（§2.3），不能假设断线期间没消息

### 6.3 消息状态机

```
sending ──成功──▶ sent ──对方已读──▶ read
   └────失败────▶ failed ──点击重发──▶ sending
```

本地乐观上屏的消息用 `client_msg_id` 占位，收到服务端响应后**用 seq 归位**——
不是简单替换：期间可能已经收到别人的消息，直接替换会让顺序错乱。

---

## 7. 移动端（staff）与双端联调

**移动端和后台同步开发，不排在后面做。** 「手机发、电脑收」是这个模块最有效的验证手段——
它一次同时压到跨进程广播、跨端共用一份 service、员工身份两端共用这三件事。
后端只要有一处把业务写进了 controller，双端一跑就露馅；等后台全做完再补移动端，
这类问题会以「移动端改不动」的形式在最后爆发。

### 7.1 复用与差异

- 路由前缀 `/staff/v1/chat/*`，**controller 只做编排，业务全在 `app/common/service/ChatService.php`**
  ——「一个业务规则只有一份实现」是铁律（PROJECT.md §8.2）
- WS 连同一个网关、用同一个 token（员工身份两端共用，PROJECT.md §8.4）
- 接口差异只有一处：列表返回更聚合（一次带回会话 + 未读 + 对方信息，减少往返）
- 行为差异一处：退到后台就收不到消息（不接厂商推送，见 chat-prd.md §5.7）

### 7.2 WS 客户端要抽一层适配

uni-app **没有** `WebSocket` 构造函数，用的是 `uni.connectSocket` / `SocketTask`，
回调风格也不同（`onMessage` 而非 `addEventListener`）。

所以连接层按同一个接口写两份实现，**重连退避、心跳、对齐流程这些逻辑只写一遍**：

```
web/src/utils/chatSocket.ts      —— 浏览器 WebSocket
staff/common/chatSocket.js       —— uni.connectSocket
```

两边都只负责「连上、收帧、派事件」，业务逻辑一律在各自的 store / 页面里。
⚠️ 不要试图把这层做成跨端共享的包：两个工程一个 TS 一个 JS、一个走 vite 一个走 HBuilderX，
共享的成本高于重写这 80 行的成本。**要共享的是协议约定，不是代码**。

token 统一走 query（`?token=…`）而不是请求头：浏览器的 `WebSocket` 不支持自定义头是硬限制，
uni-app 的 App 端虽然支持，但为了两端同构，一起走 query。

### 7.3 联调环境

**这一节描述的是现状，不是待办**——第 ① 批（提交 `233f4ba`、`adf6a25`）已经把它做完了。

开发期两端要打同一个后端。`staff/common/config.js` 的 `BASE_URL` 现在**按环境自动推断**，
不需要手改：

| 场景 | 取值 |
|---|---|
| 发行版（`NODE_ENV=production`） | 线上预览地址 |
| 开发 + H5（含手机浏览器访问 dev server） | `location.hostname` + `:8787` |
| 开发 + 真机基座 / 模拟器 | `DEV_HOST` 填了就用它，留空回落线上 |

H5 那条是关键：手机访问的是 `http://<开发机IP>:5173`，`hostname` 就是开发机地址，
直接借用即可。换 wifi、换机器、换同事都不用改配置，也就不存在「临时改一行、提交前改回去」
这件事——那正是此前最容易忘、且症状只表现为「登录转圈」的一个坑。

| 项 | 说明 |
|---|---|
| 开发机局域网 IP | `ipconfig getifaddr en0` 查；只有真机基座要手填进 `DEV_HOST` |
| staff WS 地址 | `ws://<同 BASE_URL 的 host>:8788`，与 `BASE_URL` 同源推断，不单独配 |
| web WS 地址 | `ws://localhost:8788`（同一个容器，走哪个地址都行） |
| docker-compose | 开发环境已把 `8788` 映射到宿主机；**生产不映射**，只走 nginx 反代（§8.1） |
| 跨域 | `.env` 的 `CORS_ALLOW_ORIGINS` 必须放行局域网段，见下 |
| 手机与电脑 | 必须同一个 wifi。公司网络若开了 AP 隔离，改用手机热点 |

⚠️ **跨域这条容易漏**：手机侧的 Origin 是 `http://<局域网IP>:5173`，
不在默认的 `http://localhost:*,http://127.0.0.1:*` 里，预检会被 `CorsMiddleware` 拦掉，
浏览器只报一句「跨域失败」，指不到这一层。`.env` 里追加三个私有地址段：

```
CORS_ALLOW_ORIGINS=http://localhost:*,http://127.0.0.1:*,http://192.168.*,http://10.*,http://172.*
```

三段都要加：私有地址段有三个（192.168/16、10/8、172.16-31/12），
公司网络用哪一段不一定——本机实测拿到的是 `172.18.16.155`，只加 `192.168.*` 会漏。
`.env` 不进 Git，改它没有提交风险；`.env.example` 里已写明这条，并标注**生产不要加**
（那是一整段内网地址，等于对同网段的任意页面开放接口）。

其余两条：

- WS 不做 Origin 白名单校验，否则局域网联调会被自己挡住。鉴权靠 token，
  Origin 校验在这里没有安全收益
- 手机侧最快的跑法是 HBuilderX「运行到浏览器」后，用手机访问那个 vite dev server 的**局域网地址**
  （端口不固定，`lsof -iTCP -sTCP:LISTEN -P -n | grep node` 找）。
  真机基座和打包留到最后验，H5 已经能验证消息互通
- 汇报时要说清「验的是 H5 还是真机」（staff/CLAUDE.md 的既有要求）


---

## 8. 部署

### 8.1 nginx

⚠️ 生产**只开了 8080**（宝塔占着 80，见 CLAUDE.md），所以 WebSocket 必须从 8080 反代过去，
不能另开公网端口。

```nginx
location ^~ /ws {
    proxy_pass         http://server:8788;
    proxy_http_version 1.1;
    proxy_set_header   Upgrade    $http_upgrade;     # 这两行缺一不可
    proxy_set_header   Connection "upgrade";         # 缺了就是 200 + 普通响应，不会升级
    proxy_set_header   Host              $host;
    proxy_set_header   X-Real-IP         $remote_addr;
    proxy_set_header   X-Forwarded-For   $remote_addr;   # 覆盖式，与现有 location 一致
    proxy_read_timeout 600s;                         # 默认 60s 会把空闲长连接掐掉
    access_log off;                                  # URL 里带 token，不进日志
}
```

⚠️ `location ^~ /ws` 必须放在 SPA 兜底 `location /` **之前**，
否则会被 `try_files` 接走返回 index.html——表现是「WS 连接失败，但接口都正常」，
和当年 `/staff/v1/*` 漏改 nginx 是同一类坑。

### 8.2 docker-compose

`server` 服务增加 `8788` 的内部端口（**不对宿主机 expose**，只在 compose 网络里让 nginx 访问）。

### 8.3 清理任务

留存期清理挂在已有的 `TaskProcess` 上（count=1），投递到队列由 `ChatCleanupConsumer` 分批删，
与 M3 的 `LogCleanupService` 同一套模式，不新建机制。
附件删除与消息删除**在同一批次里做**，否则 `uploads/chat/` 会积压孤儿文件。

---

## 9. 容量估算

| 项 | 估算 | 依据 |
|---|---|---|
| 单连接内存 | ~8KB | Workerman TcpConnection + 我们的注册表两个 entry |
| 2000 连接 | ~16MB + 进程基线 32MB ≈ 50MB / 进程 | 远低于产品要求的 200MB，余量充足 |
| 消息行大小 | ~400B（文本）| 含冗余的 sender_name 与 extra |
| 100 人 / 天 50 条 | 500 万条 / 年，约 2GB | 单表可承受，**暂不分表** |
| 广播扇出 | 200 人群 × 2KB = 400KB / 条 | 200 人群发一条消息的网络开销，内网无压力 |

**什么时候需要重新设计**：消息表过 5000 万行、或单机连接数过 5000。
前者要按 `conv_id` 分表，后者要上多机网关。V1 两个都碰不到，
**现在就做分表是拍脑袋**（与 M4 里「进程数定值」「前端拆包」不做是同一个理由）。

---

## 10. 实施拆分（双端并行）

批次边界按**「每批结束时能双端真点一遍」**划，不按技术层次划。
「后端全做完再做前端」在 IM 上是行不通的：接口通了但没有界面，
既看不出消息丢没丢，也看不出顺序对不对。

| 批 | 内容 | 验收方式（必须双端真点） | 估时 |
|---|---|---|---|
| **① 地基** ✅ | `/admin/upload` 通用上传（api.md §12 的历史欠账）+ 联调环境打通（§7.3） | 手机上的 staff 能登进**本地**后端；电脑传张图拿到可访问 url | 已完成 |
| **② 一条消息跑通** ✅ | 四张表 + `ChatGateway` + 发/收/历史三个接口 + 两端各一个最简聊天页（会话固定为一个单聊，不做列表） | **手机发一句，电脑 1 秒内出现；反过来也是** | 已完成 |
| **③ 会话列表与未读** ✅ | 会话列表、建会话、未读数、已读水位、置顶免打扰、删除会话 | 手机发消息，电脑顶栏红点亮；电脑点开后手机侧「已读」出现 | 已完成 |
| **③.5 通讯录** ✅ | 组织架构只读视图（部门树 + 人员 + 字段级权限），后台做成消息页里的页签，移动端做成独立 tab | 部门主管能看到别的部门的人；无字段权限看到掩码 | 已完成 |
| **④ 富消息与撤回** ✅ | 图片、文件、撤回、已读标记、发送失败点击重发 | 手机拍张照发出去，电脑能预览；两分钟内撤回，两端同时变灰 | 已完成 |
| **⑤ 群聊** ✅ | 建群、成员管理、@（服务端）、群主撤回 | 三方群：手机 + 两个浏览器，验收清单「群」那组 | 已完成 |
| **⑥ 功能收尾** ✅ | @ 输入面板、桌面通知 + 提示音、切会话保留滚动位置、暗色模式核对 | 后台真点过；移动端只到 SFC 编译 | 已完成 |
| **⑦ 性能与压测** ⏸ | 虚拟滚动、2000 连接 30 分钟、拔网线/切 wifi/挂起手机 | 内存曲线（剔除冷启动点，M4 那个坑） | **用户明确表示暂不需要** |

### 10.0 实际执行与计划的偏差（2026-09-20 回填）

计划是写在做之前的，这里记实际发生的事，免得下次照着一个理想化的拆分估工期。

- **通讯录是中途插进来的**（表里的 ③.5）。原计划它只是聊天页里「发起会话」的选人弹窗，
  产品文档里也只出现过一次。做完第 ③ 批之后才明确它要成为一个独立模块——
  先做成了顶级菜单，又改成消息页里的页签。**两次返工都是范围没谈清楚造成的**，
  不是实现问题
- **移动端 tabBar 跟着重排**：首页→工作台、公告从 tab 降级为工作台入口、
  消息位让给聊天、新增通讯录 tab。连带改了七处（角标索引、跳转 API、
  公告页的导航栏与内边距、登录落地页）——**tab 顺序一变，
  `uni.setTabBarBadge` 的 index 就全错位**，而这类错位不报错，只是红点跑到别的 tab 上
- **第 ② 批的估时准了**（4~5 天），但坑全在预料之外的地方：WS 握手回调的参数类型、
  回调触发顺序、webman 的 `$callbackMap` 里没有 `onWebSocketConnected`。
  三个都是「不熟的 API 凭记忆写」，查一次 vendor 源码就能避免
- **两处半成品要在第 ④ 批补上**：已读水位服务端广播通了但前端没渲染「已读」标记；
  发送失败的状态显示了但没接「点击重发」。都是做第 ③ 批时只做到了数据层

### 10.1 第 ② 批不能再拆，风险也集中在这里

它是唯一一个「做完之前什么都验证不了」的批次，四个坑全在这批：

WS 鉴权 · nginx/dev 的连接链路 · 事务与广播的时序（§4.2 第 6 步）· 连接注册表清理（§5.4）

代价是这批偏重（4~5 天）。**不要为了让批次好看而把它切成「先后端后前端」**——
那样切出来的前半截，你点不了任何东西，等于把验证推迟到后半截，风险一点没减少。

### 10.2 批内的并行与串行

- **能并行**：同一批里 web 与 staff 的页面可以并行做，前提是接口契约先写进 `api.md` 定死
- **不能并行**：⑥ 的压测期间不要跑功能用例。M4 已经证明同一运行时上的多项测量会互相污染
- **不能并行**：② 之前 staff 那版未提交的界面改动最好先定下来。
  聊天页要按 `staff/docs/DESIGN.md` 做，设计规范还在变的话聊天页会跟着返工

### 10.3 每批的收尾

按既定节奏：**改代码 → 双端真点一遍 → 补 CHANGELOG → 汇报改了什么**，然后停下等指令。
提交、推送、发布是三道独立的闸。

## 11. 风险清单

| 风险 | 后果 | 对策 |
|---|---|---|
| 网关进程内存泄漏 | 跑几天 OOM，全员掉线 | §5.4 的四条硬性要求 + `/internal/chat/stats` 可观测 |
| nginx 漏配 Upgrade 头 | WS 连不上，且症状指不到 nginx | §8.1，部署清单单列一条 |
| 事务内发广播 | 偶发「有通知没内容」，只在高并发出现 | §4.2 第 6 步，代码里加注释说明为什么 |
| 已读水位倒退 | 红点反复出现 | `GREATEST` 更新，§3.4 |
| 系统消息撞 `uk_client_msg` | 建群即 500 | 系统消息也生成服务端 UUID，§3.3 |
| `chat:use` 漏进权限树 | 永久 403，只有用户点到才发现 | 沿用 M2.7 的核对方式：`route.php` 与权限树对一遍 |
| ~~两端打了不同的后端~~ | 消息「丢了」，实际是进了两个库 | **已消解**：`BASE_URL` 按环境自动推断（`233f4ba`），§7.3 |
| ~~`BASE_URL` 临时改动被提交~~ | 别人拉下来连不上后端 | **已消解**：不再需要临时改动 |
| 跨域没放行局域网段 | 手机连不上本地后端，只报「跨域失败」 | `.env` 追加三个私有地址段，§7.3 |

---

## 12. 待定

| 问题 | 倾向 | 何时必须定 |
|---|---|---|
| ~~附件存本地还是对象存储~~ | **已定案：V1 存本地**。落盘与 url 生成集中在 `UploadService::store()` 一处，换对象存储只改那一个方法，调用方拿到的仍是一个 url 字符串 | 第 ① 批已定 |
| WS 端口要不要做 TLS | 生产还是 http，上了 https 之后 `ws://` 必须同步改 `wss://` | 上 https 时 |
| 消息表分表策略 | 不做。触发条件写在 §9 | 消息量真到量级时 |
| 网关多机方案 | 不做。换实现时 HTTP 侧不用改，这是现在唯一要保证的 | 单机撑不住时 |
