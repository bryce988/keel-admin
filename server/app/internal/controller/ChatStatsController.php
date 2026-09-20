<?php
/**
 * keel admin
 * 内部服务 · 聊天网关状态
 *
 * **只给运维与验收用**，不对公网暴露（nginx 对 /internal/* 直接 404）。
 *
 * 存在的理由只有一个：连接注册表是网关**进程内**的状态，HTTP worker 读不到，
 * 而「关掉所有客户端后注册表必须归零」是这个模块的硬性验收项
 * （PROJECT.md §14 的常驻内存红线）。网关每次心跳与每次连接增减时把计数写进 Redis，
 * 这里只是把它们读出来汇总。
 *
 * 没有这个接口的话，连接泄漏只能靠观察进程内存慢慢涨来猜——
 * 而那要等好几天才看得出来，等看出来时已经不知道是哪次改动引入的。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\internal\controller;

use app\common\support\Cache;
use app\common\support\Result;
use support\Response;

class ChatStatsController
{
    public function index(): Response
    {
        $workers = [];
        $conns   = 0;
        $users   = 0;

        // 网关进程数不多（默认 2），直接按 id 探，不用 SCAN
        for ($i = 0; $i < 16; $i++) {
            $raw = Cache::get('im:gateway:' . $i);
            if ($raw === null) {
                continue;
            }

            $row = json_decode((string) $raw, true);
            if (!is_array($row)) {
                continue;
            }

            $workers[] = ['worker' => $i] + $row;
            $conns += (int) ($row['connections'] ?? 0);
            $users += (int) ($row['users'] ?? 0);
        }

        return Result::ok([
            // 验收看这两个数：关掉所有客户端后都必须归零
            'total_connections' => $conns,
            'total_users'       => $users,
            'workers'           => $workers,
        ]);
    }
}
