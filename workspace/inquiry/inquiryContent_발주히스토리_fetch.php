<?php
declare(strict_types=1);

/**
 * [문의확인 > "발주 히스토리" 탭(inquiryContent_발주히스토리_entry.php)의 조회 엔드포인트]
 * v발주내역 중 parentcode = ?code(t문의.code)인 행. 보기 전용이라 조회만 한다
 * (save/delete는 400). 날짜 컬럼은 dblib 형식이라 Str::toDate()로 'Y-m-d'로 바꾼다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Board', 'Crud', 'Login', 'Str');

// 독립 엔드포인트라 Layout(index)의 보호를 못 받으므로 여기서도 확인한다.
Login::isAlive();

header('Content-Type: application/json; charset=utf-8');

$code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
$action = isset($_GET['action']) ? (string) $_GET['action'] : 'list_all';
if ($code === '' || !ctype_digit($code) || !in_array($action, ['list_all', 'list_paged'], true)) {
    http_response_code(400);
    echo json_encode(['error' => '잘못된 요청입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = new DbContext(null); // config/db.php의 'driver' 기본 접속
$tableName = 'v발주내역';

$probe = new Crud($db);
$probe->init($tableName);
$schema = $probe->getSchema()['fields'];
$fields = array_keys($schema);

// parentcode = 이 문의(t문의.code). 값은 PDO quote로 넣는다.
$condition = $db->dialect()->quoteIdentifier('parentcode') . ' = ' . $db->pdo()->quote($code);
$orderBy = $db->dialect()->quoteIdentifier('code') . ' DESC';

$board = new Board($db);
$board->init($tableName, implode(',', $fields), '', '', '', 1, 1000000, 1, $condition, $orderBy);

// SELECT *라 바이너리 ts가 섞이지 않게 스키마 필드만 남기고, 날짜는 'Y-m-d'로.
$keep = array_flip($fields);
$rows = array_map(function (array $row) use ($keep, $schema): array {
    $row = array_intersect_key($row, $keep);
    foreach ($row as $field => $value) {
        if (in_array(strtolower((string) ($schema[$field]['type'] ?? '')), ['datetime', 'date', 'smalldatetime', 'datetime2'], true)) {
            $row[$field] = Str::toDate($value, $value);
        }
    }
    return $row;
}, $board->getRows());

echo json_encode(['rows' => $rows, 'lastRow' => count($rows)], JSON_UNESCAPED_UNICODE);
