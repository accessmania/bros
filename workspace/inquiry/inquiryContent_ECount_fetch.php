<?php
declare(strict_types=1);

/**
 * [amd 화면의 ECount 버튼 창구 — 품목 하나의 이카운트 재고]
 * 요청: POST  body(JSON) {"productCode": "품목코드"}
 * 응답: {"success": true,  "result": {"품목코드": "…", "수량": "…"}}   ← 이카운트에 없는 품목이면 "result": null
 *       {"success": false, "error": "이유"}
 * 조회는 src/Model/ECount/ECount.php의 inventoryOne(사용법은 ECount.md). 세션이 없으면 그 안에서 로그인한다.
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
function ecf_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ecf_json(['success' => false, 'error' => 'POST로 요청해야 합니다.'], 405);
}

$body = json_decode((string) file_get_contents('php://input'), true);
$productCode = '';
if (is_array($body) && isset($body['productCode'])) {
    $productCode = trim((string) $body['productCode']);
}
if ($productCode === '') {
    ecf_json(['success' => false, 'error' => '품목코드가 없습니다.'], 400);
}

try {
    $ecount = new ECount(new DbContext(null));   // 접속 정보는 tAPI, 세션은 PHP 세션
    ecf_json(['success' => true, 'result' => $ecount->inventoryOne($productCode)]);
} catch (Throwable $e) {
    ecf_json(['success' => false, 'error' => $e->getMessage()], 500);
}
