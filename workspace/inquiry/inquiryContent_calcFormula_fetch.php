<?php
declare(strict_types=1);

/**
 * [수식 계산 창구 — amd 화면의 폼 값으로 수식 테이블을 순서대로 계산한다]
 * 요청: POST ?kind=문의내용|수주|발주|견적   body(JSON) {"values": {필드: 값, …}}  ← 폼의 현재 값
 * 응답: {"success": true,  "kind": "…", "table": "…", "results": {필드명: 결과, …}, "values": {…}}
 *       {"success": false, "error": "수식 2번(미수금): …"}
 *  - results: 계산한 필드만(순서대로) — 화면은 이것을 칸에 넣는다.
 *  - values : 보낸 폼 값 + 계산 결과(확인용).
 * 수식 테이블은 kind로만 고른다(inquiryContent_수식_tables.php의 FORMULA_TABLES — 사용자 입력으로 테이블을 만들지 않는다).
 * 계산은 src/Model/Formula/calcFormula.php의 calcAll(사용법·규칙은 calcFormula.md).
 * 저장하지 않는다 — 저장은 화면의 저장 버튼(crudbuttons)이 한다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Crud', 'Login', 'calcFormula');
require_once __DIR__ . '/inquiryContent_수식_tables.php';

// 독립 엔드포인트라 Layout(index)의 보호를 못 받으므로 여기서도 확인한다.
Login::isAlive();

header('Content-Type: application/json; charset=utf-8');

/** JSON으로 응답하고 끝낸다. */
function cf_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cf_json(['success' => false, 'error' => 'POST로 요청해야 합니다.'], 405);
}

try {
    $kind = formula_kind();
} catch (InvalidArgumentException $e) {
    cf_json(['success' => false, 'error' => $e->getMessage()], 400);
}
$table = FORMULA_TABLES[$kind];

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body) || !isset($body['values']) || !is_array($body['values'])) {
    cf_json(['success' => false, 'error' => '요청 본문에 values(폼 값)가 없습니다.'], 400);
}

try {
    $db = new DbContext(null); // config/db.php의 'driver' 기본 접속
    // 폼 테이블의 글자·날짜 필드(결제통화 등)는 cdata 없이 그대로 수식에 넣는다(숫자 필드만 cdata).
    $textFields = calcFormula::textFieldsOf($db, FORMULA_FORM_TABLES[$kind]);
    $result = calcFormula::calcAll($db, $table, $body['values'], $textFields);
    cf_json([
        'success' => true,
        'kind' => $kind,
        'table' => $table,
        'results' => $result['results'],
        'values' => $result['values'],
    ]);
} catch (Throwable $e) {
    cf_json(['success' => false, 'error' => $e->getMessage()], 500);
}
