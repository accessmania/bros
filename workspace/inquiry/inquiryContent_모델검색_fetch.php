<?php
declare(strict_types=1);

/**
 * [모델 검색 팝업(inquiryContent_모델검색_entry.php)의 조회 엔드포인트]
 * tb제품 전체 — 검색어(?q=)가 있으면 화면에 보이는 필드 전체에 LIKE(OR). 모델명 순
 * (tb제품에 모델명이 없으면 PK 순). 보기 전용이라 조회만 한다(save/delete 없음).
 * 행에는 화면에 안 보이는 code 류 필드도 그대로 담는다(ts는 Crud 스키마에서 이미 빠진다).
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

/** 화면에 안 보이는 필드 — code 류(이름이 code로 끝남, 대소문자 무시)와 ts. entry와 같아야 한다. */
function model_search_hidden(string $field): bool
{
    return preg_match('/code$/i', $field) === 1 || strtolower($field) === 'ts';
}

try {
    $db = new DbContext(null); // config/db.php의 'driver' 기본 접속
    $tableName = 'tb제품';

    $probe = new Crud($db);
    $probe->init($tableName);
    $fields = array_keys($probe->getSchema()['fields']);
    // 검색어는 화면에 보이는 필드에만 건다(안 보이는 code 값으로 걸리면 왜 나왔는지 알 수 없다).
    $searchFields = array_values(array_filter($fields, function (string $field): bool {
        return !model_search_hidden($field);
    }));
    if ($searchFields === []) {
        $searchFields = $fields;
    }

    $orderField = in_array('모델명', $fields, true) ? '모델명' : (string) $probe->getPrimaryKey();
    $orderBy = $db->dialect()->quoteIdentifier($orderField) . ' ASC';
    $keyword = isset($_GET['q']) ? (string) $_GET['q'] : '';

    $board = new Board($db);
    $board->init($tableName, implode(',', $fields), implode(',', $searchFields), $keyword, '', 1, 1000000, 1, '', $orderBy);

    // SELECT *라 바이너리 ts가 섞이지 않게 스키마 필드만 남긴다.
    $keep = array_flip($fields);
    $rows = array_map(function (array $row) use ($keep): array {
        return array_intersect_key($row, $keep);
    }, $board->getRows());

    echo json_encode(['rows' => $rows, 'lastRow' => count($rows)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
