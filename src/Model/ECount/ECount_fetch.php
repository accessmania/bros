<?php
declare(strict_types=1);

/**
 * [ECount 시험 화면(ECount_entry.php)의 창구]
 * POST ?action=config|zone|login|one   body(JSON) {"productCode": "…"}  (one만)
 * 응답: {"success": true,  "action": "…", "result": …, "log": [요청·응답 기록]}
 *       {"success": false, "action": "…", "error": "이유", "log": […]}
 *  - log: ECount::getLog() — 인증키·세션은 앞 4글자만 남기고 가린다.
 *  - config는 tAPI 첫 행을 읽되 APIKEY는 가려서 돌려준다.
 * 접속 정보는 tAPI(MSSQL), 세션은 PHP 세션.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Login', 'ECount');

// 독립 엔드포인트라 Layout(index)의 보호를 못 받으므로 여기서도 확인한다.
Login::isAlive();

header('Content-Type: application/json; charset=utf-8');

/** JSON으로 응답하고 끝낸다. */
function ec_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
if (!in_array($action, ['config', 'zone', 'login', 'one'], true)) {
    ec_json(['success' => false, 'error' => '알 수 없는 action입니다: ' . $action], 400);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ec_json(['success' => false, 'error' => 'POST로 요청해야 합니다.'], 405);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}

$ecount = new ECount(new DbContext(null));   // 접속 정보는 tAPI, 세션은 PHP 세션
try {
    $result = null;

    switch ($action) {
        case 'config':
            $result = $ecount->loadConfig();
            $result['APIKEY'] = substr($result['APIKEY'], 0, 4) . '****';
            $result['서버'] = ECount::TEST_SERVER ? '시험(sboapi)' : '실제(oapi)';
            break;
        case 'zone':
            $result = $ecount->zone();
            break;
        case 'login':
            $result = substr($ecount->login(), 0, 4) . '****';
            break;
        case 'one':
            $result = $ecount->inventoryOne(isset($body['productCode']) ? (string) $body['productCode'] : '');
            break;
    }

    ec_json(['success' => true, 'action' => $action, 'result' => $result, 'log' => $ecount->getLog()]);
} catch (Throwable $e) {
    ec_json(['success' => false, 'action' => $action, 'error' => $e->getMessage(), 'log' => $ecount->getLog()], 500);
}
