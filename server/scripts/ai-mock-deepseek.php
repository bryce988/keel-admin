<?php
/**
 * 模拟 DeepSeek —— 只给 scripts/ai-acceptance.sh 用，不花钱、结果可重复
 *
 *   docker compose exec -d server php -S 127.0.0.1:9999 scripts/ai-mock-deepseek.php
 *   并在 .env 里设 DEEPSEEK_BASE_URL=http://127.0.0.1:9999 后 `docker compose up -d server`
 *
 * 模仿官方文档里会让我们 400 的两条规则（docs/ai-tech.md §3.4）：
 *   · 带 tools 时，每个 assistant 回合都必须回传 reasoning_content
 *   · 每个 tool_call 都要有对应的 role=tool 消息
 * 按提问里的关键词决定调哪个工具；回答里会故意把一个事件切在多字节字符中间，
 * 验证 SseParser 会留尾巴而不是按回调边界解析。
 *
 * 密钥固定为 sk-mock，别的一律 401。每次请求体写进 /tmp/mock_req_N.json 供断言。
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

function jsonOut(int $status, array $data): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

if ($auth !== 'Bearer sk-mock') {
    jsonOut(401, ['error' => ['message' => 'Authentication Fails', 'type' => 'authentication_error']]);
    return;
}

if ($path === '/user/balance') {
    jsonOut(200, ['is_available' => true, 'balance_infos' => [['currency' => 'CNY', 'total_balance' => '88.80', 'granted_balance' => '0', 'topped_up_balance' => '88.80']]]);
    return;
}

if ($path !== '/chat/completions') {
    jsonOut(404, ['error' => 'not found']);
    return;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
$n    = (int) @file_get_contents('/tmp/mock_n') + 1;
file_put_contents('/tmp/mock_n', (string) $n);
file_put_contents("/tmp/mock_req_{$n}.json", json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$msgs = $body['messages'] ?? [];

// 官方规则：带 tools 时 assistant 回合必须回传 reasoning_content
if (!empty($body['tools'])) {
    foreach ($msgs as $m) {
        if (($m['role'] ?? '') === 'assistant' && !isset($m['reasoning_content'])) {
            jsonOut(400, ['error' => ['message' => 'reasoning_content must be passed back', 'type' => 'invalid_request_error']]);
            return;
        }
    }
}
// 每个 tool_call 都要有对应的 tool 消息
$ids = [];
foreach ($msgs as $m) {
    foreach ($m['tool_calls'] ?? [] as $c) $ids[$c['id']] = false;
    if (($m['role'] ?? '') === 'tool') $ids[$m['tool_call_id']] = true;
}
if (in_array(false, $ids, true)) {
    jsonOut(400, ['error' => ['message' => 'tool_call without tool message']]);
    return;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
while (ob_get_level()) ob_end_flush();

function sse(array $chunk): void { echo 'data: ' . json_encode($chunk, JSON_UNESCAPED_UNICODE) . "\n\n"; flush(); }
function delta(array $d, ?string $finish = null): void { sse(['choices' => [['index' => 0, 'delta' => $d, 'finish_reason' => $finish]]]); }

$last     = end($msgs);
$userText = '';
foreach ($msgs as $m) if ($m['role'] === 'user') $userText = $m['content'];
$question = trim((string) substr($userText, strrpos($userText, '【本次提问】') + strlen('【本次提问】')));

$usage = ['prompt_tokens' => 3000, 'prompt_cache_hit_tokens' => 2800, 'prompt_cache_miss_tokens' => 200,
          'completion_tokens' => 120, 'completion_tokens_details' => ['reasoning_tokens' => 80]];

if (($last['role'] ?? '') === 'user') {
    delta(['role' => 'assistant', 'reasoning_content' => '用户在问']);
    delta(['reasoning_content' => '数据，需要调用工具。']);

    $call = null;
    if (str_contains($question, '多少人')) $call = ['count_users', '{"group_by":"dept","status":1}'];
    elseif (str_contains($question, '登录失败')) $call = ['search_login_logs', '{"status":0,"limit":5}'];
    elseif (str_contains($question, '公告')) $call = ['search_notices', '{"limit":3}'];
    elseif (str_contains($question, '越权')) $call = ['search_operation_logs', '{}'];
    elseif (str_contains($question, '编造')) $call = ['delete_all_users', '{}'];
    elseif (str_contains($question, '慢')) {
        for ($i = 0; $i < 200; $i++) { delta(['content' => '慢']); usleep(300000); if (connection_aborted()) exit; }
        delta([], 'stop'); echo "data: [DONE]\n\n"; return;
    }

    if ($call) {
        // 参数分三片发，验证按 index 累积
        [$name, $args] = $call;
        $third = intdiv(strlen($args), 3);
        delta(['tool_calls' => [['index' => 0, 'id' => 'call_' . $n, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => substr($args, 0, $third)]]]]);
        delta(['tool_calls' => [['index' => 0, 'function' => ['arguments' => substr($args, $third, $third)]]]]);
        delta(['tool_calls' => [['index' => 0, 'function' => ['arguments' => substr($args, 2 * $third)]]]], 'tool_calls');
        sse(['choices' => [], 'usage' => $usage]);
        echo "data: [DONE]\n\n";
        return;
    }

    $answer = '你好，我是小k。这是一个不需要查数据的回答。可以去 [[page:/system/role]] 或 [[page:/config/param]] 看看。';
} else {
    // 最后一条是 tool 结果：汇总回答
    $tool = json_decode((string) $last['content'], true) ?: [];
    if (isset($tool['error'])) {
        $answer = '查询失败：' . $tool['error'];
    } else {
        $scope  = isset($tool['scope_note']) ? "（{$tool['scope_note']}）" : '';
        $groups = $tool['summary']['groups'] ?? [];
        $list   = $groups ? "\n\n" . implode("\n", array_map(fn ($g) => "- {$g['name']}：{$g['count']} 人", $groups)) : '';
        $answer = "**查询结果**{$scope}：共 {$tool['total']} 条。{$list}\n\n" . ($tool['link'] ?? '') . ' 也可以看 [[page:/system/role]] 和 [[page:/log/ai]]。';
    }
}

// 把回答按字节切成不规则的块，故意切在多字节字符中间，验证解析器会留尾巴
delta(['role' => 'assistant', 'reasoning_content' => '整理一下结果。']);
$json = json_encode(['choices' => [['index' => 0, 'delta' => ['content' => $answer], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE);
$event = 'data: ' . $json . "\n\n";
// 先按字符发几段正常的增量
foreach (mb_str_split($answer, 7) as $part) {
    delta(['content' => $part]);
    usleep(30000);
}
// 再发一个被拆成两半的事件（切在 UTF-8 中间）：这一段的内容是空串，只检验解析不出错
$tricky = 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['content' => '【完】'], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE) . "\n\n";
$cut = strpos($tricky, '完') + 1;   // 切在「完」的三个字节中间
echo substr($tricky, 0, $cut); flush(); usleep(80000); echo substr($tricky, $cut); flush();
delta([], 'stop');
sse(['choices' => [], 'usage' => $usage]);
echo "data: [DONE]\n\n";
