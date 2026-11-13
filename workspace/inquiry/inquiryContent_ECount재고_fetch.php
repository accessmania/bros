<?php
declare(strict_types=1);

/**
 * [ECount(전체재고) 팝업(inquiryContent_ECount재고_entry.php)의 창구]
 *  - GET  ?action=list_all|list_paged : tAPI_재고의 품목코드·수량(+ PK)을 품목코드 오름차순으로 전부(AGGrid 계약 {rows, lastRow}).
 *  - POST ?action=refresh             : 이카운트에서 전체 재고를 받아 tAPI_재고를 바꾼다(ECount::inventoryAll — 트랜잭션).
 *                                       {success:true, count} / {success:false, error}
 * 실패하면 {success:false, error:'…'} — 화면에 이유가 보이게. 보기 전용이라 save/delete는 받지 않는다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Board', 'Crud', 'Login', 'ECount');

// 독립 엔드포인트라 Layout(index)의 보호를 못 받으므로 여기서도 확인한다.
Login::isAlive();

header('Content-Type: application/json; charset=utf-8');

/** JSON으로 응답하고 끝낸다. */
function es_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'list_all';
if (!in_array($action, ['list_all', 'list_paged', 'refresh'], true)) {
    es_json(['success' => false, 'error' => '잘못된 요청입니다: ' . $action], 400);
}

try {
    $db = new DbContext(null); // config/db.php의 'driver' 기본 접속

    if ($action === 'refresh') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            es_json(['success' => false, 'error' => 'POST로 요청해야 합니다.'], 405);
        }
        $ecount = new ECount($db);
        es_json(['success' => true, 'count' => $ecount->inventoryAll()]);
    }

    // list — tAPI_재고 전부, 품목코드 오름차순. 테이블·컬럼명은 코드에 고정.
    $probe = new Crud($db);
    $probe->init(ECount::STOCK_TABLE);
    $fields = ['품목코드', '수량'];
    $primaryKey = $probe->getPrimaryKey();
    if ($primaryKey !== null && !in_array($primaryKey, $fields, true)) {
        $fields[] = $primaryKey;
    }
    $orderBy = $db->dialect()->quoteIdentifier('품목코드') . ' ASC';

    $board = new Board($db);
    $board->init(ECount::STOCK_TABLE, implode(',', $fields), '', '', $orderBy, 1, 1000000, 1);

    // SELECT *라 다른 컬럼이 섞이지 않게 보낼 필드만 남긴다.
    $keep = array_flip($fields);
    $rows = [];
    foreach ($board->getRows() as $row) {
        $rows[] = array_intersect_key($row, $keep);
    }
    es_json(['rows' => $rows, 'lastRow' => count($rows)]);
} catch (Throwable $e) {
    es_json(['success' => false, 'error' => $e->getMessage()], 500);
}
