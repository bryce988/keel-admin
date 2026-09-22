<?php
/**
 * keel admin
 * 聊天 WebSocket 网关
 *
 * 职责**只有三件**：握手鉴权、维护连接注册表、把 Redis 里来的消息推给对应连接。
 * 不查库、不写库、不做任何业务判断——业务全在 `ChatService`。
 *
 * 这条边界是有意的：将来要换成 GatewayWorker 或独立网关做多机扩展时，
 * 只要替换这个文件，HTTP 侧与数据库一行都不用改（docs/chat-tech.md §1.2）。
 *
 * ## 上行只有 ping
 *
 * 发消息、标已读全部走 HTTP。这样鉴权、权限、限流、操作日志、幂等
 * 五层中间件全部复用，错误也直接用 api.md §2 的状态码——
 * 走 WS 上行的话这些要在帧协议里各写一遍，而且聊天会成为全站唯一
 * 一条不受限流与审计治理的写路径（docs/chat-tech.md §1.1）。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\process;

use app\common\service\JwtService;
use app\common\support\Cache;
use app\common\support\ChatFanout;
use app\common\support\Env;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WsRequest;
use Workerman\Redis\Client as AsyncRedis;
use Workerman\Timer;
use Workerman\Worker;

class ChatGateway
{
    /**
     * 连接注册表 —— ⚠️ **全项目最容易泄漏内存的地方**（PROJECT.md §14）
     *
     * 这两个数组是进程级状态，会跨请求、跨连接一直活着。
     * 硬性要求四条，少一条就是慢性泄漏：
     *
     * 1. `onClose` 里**两个都要清**。只清 $connections 的话 $userIndex 会越积越大，
     *    而且它是嵌套数组，泄漏比想象中快
     * 2. `$userIndex[$uid]` 清空后必须 `unset`，留一个空数组在那儿等于按用户数泄漏
     * 3. 心跳超时的连接要主动 `close()` 触发 onClose，**不要在超时处理里直接 unset**
     *    ——两条清理路径迟早会不一致
     * 4. 验收：关掉所有标签页后两个数组都必须归零（`/internal/chat/stats` 能查）
     *
     * 它们是**进程内**的：count>1 时每个进程只持有一部分连接，
     * 所以广播必须走 Redis，不能直接遍历这里（见 ChatFanout）。
     */
    private array $connections = [];   // connId => ['uid' => int, 'alive' => int]
    private array $userIndex   = [];   // uid    => [connId => true]

    /** 本进程的 Worker，dispatch 要靠它按 id 取连接对象。onWorkerStart 时存下来，
     *  不要每条消息都去 getAllWorkers() 里找——那是每秒几百次的热路径 */
    private ?Worker $worker = null;

    /** 心跳间隔与容忍次数：30s 无帧发 ping，连续两次没回应判死 */
    private const HEARTBEAT_INTERVAL = 30;
    private const HEARTBEAT_TIMEOUT  = 70;

    public function onWorkerStart(Worker $worker): void
    {
        $this->worker = $worker;

        /*
         * ⚠️ onWebSocketConnected 必须手动绑
         *
         * webman 只把固定的一批回调从 handler 映射到 worker
         * （vendor/workerman/webman-framework/src/support/helpers.php 的 $callbackMap），
         * 那里面有 onWebSocketConnect，**没有** onWebSocketConnected。
         * 不手动绑的话这个方法永远不会被调用，表现是「连上了但收不到 ready 帧」——
         * 而 ping/pong 是好的，所以看起来像协议实现不全，不像回调没挂上。
         */
        $worker->onWebSocketConnected = [$this, 'onWebSocketConnected'];

        $this->subscribe();
        $this->startHeartbeat($worker);
    }

    /**
     * 握手鉴权：只认 query 里的 token
     *
     * **token 走 query 不走请求头**是硬限制：浏览器的 `WebSocket` 构造函数
     * 不支持自定义头。代价是 token 可能进 nginx 的 access_log，
     * 所以 `/ws` 那个 location 必须关掉 access_log（docs/chat-tech.md §8.1）。
     *
     * ⚠️ 两件容易写错的事，都是实测踩出来的：
     *
     * 1. 第二个参数是 `Workerman\Protocols\Http\Request` **对象**，不是 header 数组。
     *    `$_GET` 在这个回调里是空的（webman 本来也禁止用超全局，见 server/CLAUDE.md）
     * 2. 这个回调跑在**握手响应发出之前**（Websocket.php 里 onWebSocketConnect 在
     *    第 440 行、发 101 在第 459 行）。所以这里只能鉴权与关闭，
     *    **不能发帧**——连接还不是 WebSocket，发出去的字节会污染握手。
     *    欢迎帧在 onWebSocketConnected 里发
     *
     * 验不过直接关闭，不留半开连接——留着的话注册表里会有一堆没有身份的条目。
     */
    public function onWebSocketConnect(TcpConnection $conn, WsRequest $request): void
    {
        $token = (string) $request->get('token', '');

        try {
            $payload = JwtService::decode((string) $token);
        } catch (\Throwable $e) {
            $conn->close();
            return;
        }

        // 员工 token 与 C 端 token 永不混用（PROJECT.md §8.4）。
        // 不放行 client 类型：聊天是员工之间的事
        if (($payload['type'] ?? '') !== 'admin') {
            $conn->close();
            return;
        }

        /*
         * 吊销校验不能省
         *
         * 光验签只能证明「这个 token 曾经是合法的」。用户登出、管理员改密之后
         * token 会进黑名单，HTTP 那边立刻 401——但长连接是**握手时**鉴权一次，
         * 之后能活几个小时。不查黑名单的话，登出的人还在实时收消息。
         */
        if (JwtService::isRevoked((string) ($payload['jti'] ?? ''))) {
            $conn->close();
            return;
        }

        $uid = (int) ($payload['uid'] ?? 0);
        if ($uid <= 0) {
            $conn->close();
            return;
        }

        $connId = (int) $conn->id;

        $this->connections[$connId] = ['uid' => $uid, 'alive' => time()];
        // 一个人可以有多条连接（多标签页 + 手机），消息推给他的全部连接
        $this->userIndex[$uid][$connId] = true;

        $conn->uid = $uid;   // @phpstan-ignore-line 动态属性，onClose 与 onWebSocketConnected 要用

        $this->reportStats();
    }

    /**
     * 握手完成之后
     *
     * `ready` 帧必须在这里发，不能在 onWebSocketConnect 里——那时候 101 响应
     * 还没发出去，连接还不是 WebSocket。在那里 send 的话字节会直接拼到握手响应前面，
     * 客户端看到的是一个畸形的 HTTP 响应，表现为「连不上」而不是「收不到欢迎帧」。
     */
    public function onWebSocketConnected(TcpConnection $conn, WsRequest $request): void
    {
        $uid = (int) ($conn->uid ?? 0);
        if ($uid <= 0) {
            return;
        }

        $this->send($conn, 'ready', ['user_id' => $uid, 'server_time' => date('Y-m-d H:i:s')]);
    }

    /** 上行只处理 ping。其余一概忽略——不是协议的一部分，不该有默认行为 */
    public function onMessage(TcpConnection $conn, string $data): void
    {
        $connId = (int) $conn->id;

        if (isset($this->connections[$connId])) {
            $this->connections[$connId]['alive'] = time();
        }

        $frame = json_decode($data, true);
        if (is_array($frame) && ($frame['ev'] ?? '') === 'ping') {
            $this->send($conn, 'pong', ['server_time' => date('Y-m-d H:i:s')]);
        }
    }

    /**
     * ⚠️ 注册表清理的**唯一路径**
     *
     * 心跳超时也是调 close() 走到这里，而不是自己 unset——
     * 两条清理路径迟早会不一致，而不一致的表现是「连接数对不上」这种
     * 看不出根因的慢性问题。
     */
    public function onClose(TcpConnection $conn): void
    {
        $connId = (int) $conn->id;
        $uid    = $this->connections[$connId]['uid'] ?? ($conn->uid ?? 0);

        unset($this->connections[$connId]);

        if ($uid) {
            unset($this->userIndex[$uid][$connId]);

            // 留一个空数组在这儿就是按用户数泄漏，必须整个 unset
            if (empty($this->userIndex[$uid])) {
                unset($this->userIndex[$uid]);
            }
        }

        // 立刻上报而不是等下一次心跳：验收要看「关掉标签页后马上归零」
        $this->reportStats();
    }

    /**
     * 订阅 Redis 广播
     *
     * 用 `workerman/redis` 的**异步**客户端，不是 predis。
     * predis 的 pubsubLoop 是阻塞的，在 Workerman 里会把整个事件循环卡死——
     * 表现是这个进程的所有长连接同时失去响应。
     * 这个客户端自带断线重连，Redis 重启后不需要我们做什么。
     */
    private function subscribe(): void
    {
        $host = Env::get('REDIS_HOST', 'redis');
        $port = Env::int('REDIS_PORT', 6379);
        $pass = (string) Env::get('REDIS_PASSWORD', '');
        $db   = Env::int('REDIS_DB', 0);

        $options = [];
        if ($pass !== '') {
            $options['auth'] = $pass;
        }
        if ($db > 0) {
            $options['db'] = $db;
        }

        $redis = new AsyncRedis("redis://{$host}:{$port}", $options);
        $redis->subscribe([ChatFanout::CHANNEL], function ($channel, $message) {
            $this->dispatch((string) $message);
        });
    }

    /** 把一条广播推给本进程持有的、属于收件人的连接 */
    private function dispatch(string $raw): void
    {
        $frame = json_decode($raw, true);
        if (!is_array($frame) || !isset($frame['ev'], $frame['user_ids'])) {
            return;
        }

        $payload = json_encode([
            'ev'   => $frame['ev'],
            'data' => $frame['data'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        // 全员广播（系统公告）：推给本进程持有的每一条连接。
        // 这仍是纯传输——「谁该收」已经由发布方决定成了「所有人」，网关不做判断
        if (!empty($frame['all'])) {
            foreach (array_keys($this->connections) as $connId) {
                $conn = $this->worker->connections[$connId] ?? null;
                $conn?->send($payload);
            }
            return;
        }

        foreach ((array) $frame['user_ids'] as $uid) {
            foreach (array_keys($this->userIndex[(int) $uid] ?? []) as $connId) {
                // 本进程持有的连接才推得到。count>1 时其余连接在别的进程里，
                // 它们各自订阅了同一个频道，会各推各的那一部分
                $conn = $this->worker->connections[$connId] ?? null;
                $conn?->send($payload);
            }
        }
    }

    /**
     * 心跳
     *
     * 服务端主动发 ping。不能只靠客户端发——手机息屏、笔记本挂起之后
     * 客户端根本不执行代码，而 TCP 连接可能在很长时间里都不会自己断，
     * 注册表里就会留着一堆实际已经死掉的条目。
     */
    private function startHeartbeat(Worker $worker): void
    {
        Timer::add(self::HEARTBEAT_INTERVAL, function () use ($worker) {
            $now = time();

            foreach ($this->connections as $connId => $meta) {
                $conn = $worker->connections[$connId] ?? null;

                if (!$conn) {
                    // 连接对象已经没了但注册表还留着：正常情况不该发生，
                    // 真发生了就是泄漏，按 onClose 的逻辑补清一次
                    $this->forget($connId, $meta['uid']);
                    continue;
                }

                if ($now - $meta['alive'] > self::HEARTBEAT_TIMEOUT) {
                    // 主动关闭 → 触发 onClose → 走统一的清理路径
                    $conn->close();
                    continue;
                }

                $this->send($conn, 'ping', []);
            }

            $this->reportStats();
        });
    }

    /**
     * 把本进程的注册表计数写进 Redis
     *
     * 注册表是**进程内**状态，HTTP worker 读不到——而「关掉所有标签页后两个数组
     * 都必须归零」是这个模块的硬性验收项（PROJECT.md §14 的内存红线）。
     * 没有这个上报，泄漏就只能靠观察进程内存慢慢涨来猜，而那要等好几天才看得出来。
     *
     * 带 TTL：进程挂掉之后这个键自己过期，不会留下一个永远显示「有 300 条连接」
     * 的幽灵进程——那比没有监控更糟。
     */
    private function reportStats(): void
    {
        try {
            Cache::set(
                'im:gateway:' . ($this->worker?->id ?? 0),
                json_encode([
                    'connections' => count($this->connections),
                    'users'       => count($this->userIndex),
                    'at'          => date('Y-m-d H:i:s'),
                ], JSON_UNESCAPED_UNICODE),
                // TTL 取心跳间隔的 3 倍：漏报一两次不该让键消失
                self::HEARTBEAT_INTERVAL * 3
            );
        } catch (\Throwable $e) {
            // Redis 不可用不该影响推送，监控数据晚一轮无所谓
        }
    }

    /** 兜底清理。正常路径是 onClose，这里只处理「连接对象没了注册表还在」 */
    private function forget(int $connId, int $uid): void
    {
        unset($this->connections[$connId], $this->userIndex[$uid][$connId]);

        if (empty($this->userIndex[$uid])) {
            unset($this->userIndex[$uid]);
        }
    }

    private function send(TcpConnection $conn, string $event, array $data): void
    {
        $conn->send(json_encode([
            'ev' => $event,
            // 空数组要转成对象，否则 PHP 会编码成 `[]`。客户端按 `data.xxx` 取值时
            // 一个是数组一个是对象，弱类型语言下不报错但行为不一致，是个定时炸弹
            'data' => $data ?: new \stdClass(),
        ], JSON_UNESCAPED_UNICODE));
    }
}
