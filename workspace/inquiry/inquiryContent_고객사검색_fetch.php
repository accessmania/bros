<?php
declare(strict_types=1);

/**
 * [고객사 검색 팝업(inquiryContent_고객사검색_entry.php)의 조회 엔드포인트]
 * tb고객사 중 회사명이 있는 것 — 검색어(?q=)가 있으면 표시 필드 전체에 LIKE(OR). 회사명 순.
 * 보기 전용이라 조회만 한다(save/delete 없음).
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Board', 'Crud', 'Login');

// 독립 엔드포인트라 Layout(index)의 보호를 못 받으므로 여기서도 확인한다.
Login::isAlive();

header('Content-Type: application/json; charset=utf-8');

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'list_all';
if (!in_array($action, ['list_all', 'list_paged'], true)) {
    http_response_code(400);
    echo json_encode(['error' => '잘못된 요청입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = new DbContext(null); // config/db.php의 'driver' 기본 접속
$tableName = 'tb고객사';

$probe = new Crud($db);
$probe->init($tableName);
$fields = array_keys($probe->getSchema()['fields']);

$keyword = isset($_GET['q']) ? (string) $_GET['q'] : '';
$companyName = $db->dialect()->quoteIdentifier('회사명');
$orderBy = $companyName . ' ASC';
// 회사명이 없는(NULL/빈 칸/공백뿐인) 고객사는 고를 이름이 없으므로 목록에서 뺀다.
// 검색어 조건과는 AND로 묶인다(Board::init() 9번째 인수).
$hasName = $companyName . ' IS NOT NULL AND LTRIM(RTRIM(' . $companyName . ")) <> ''";

$board = new Board($db);
$board->init($tableName, implode(',', $fields), implode(',', $fields), $keyword, '', 1, 1000000, 1, $hasName, $orderBy);

// SELECT *라 바이너리 ts가 섞이지 않게 스키마 필드만 남긴다.
$keep = array_flip($fields);
$rows = array_map(function (array $row) use ($keep): array {
    return array_intersect_key($row, $keep);
}, $board->getRows());

echo json_encode(['rows' => $rows, 'lastRow' => count($rows)], JSON_UNESCAPED_UNICODE);
