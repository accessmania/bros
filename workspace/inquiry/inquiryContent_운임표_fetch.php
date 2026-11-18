<?php
declare(strict_types=1);

/**
 * [운임표 팝업(inquiryContent_운임표_entry.php)의 창구]
 *  - GET  ?action=list_all|list_paged : tb운임표 전부를 중량코드 오름차순으로(AGGrid 계약 {rows, lastRow}).
 *  - POST ?action=save|delete         : ActionBulk(src/Action/ActionBulk.php)가 고친 행을 저장·삭제하고
 *                                       {results, deleteResults}로 행마다 성공/실패 이유를 돌려준다(aggrid.md "서버 계약").
 *                                       PK가 없는 테이블은 고칠 행을 가릴 수 없어 거절한다.
 * 실패하면 {success:false, error:'…'} — 화면에 이유가 보이게.
 * 메뉴 "기초자료 > 운임표"(basicdata/freight/)와 같은 테이블을 고친다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Board', 'Crud', 'ActionBulk', 'BoardConfig', 'Login');

// 독립 엔드포인트라 Layout(index)의 보호를 못 받으므로 여기서도 확인한다.
Login::isAlive();

// 테이블과 정렬 필드는 클라이언트가 고르지 못하게 이 파일에 고정한다.
const FREIGHT_TABLE = 'tb운임표';
const FREIGHT_ORDER_FIELDS = ['중량코드'];

/** JSON으로 응답하고 끝낸다. */
function fr_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'list_all';
if (!in_array($action, ['list_all', 'list_paged', 'save', 'delete'], true)) {
    fr_json(['success' => false, 'error' => '잘못된 요청입니다: ' . $action], 400);
}

try {
    $db = new DbContext(null); // config/db.php의 'driver' 기본 접속

    // 필드 목록 — 테이블에 있는 필드 전부(바이너리 ts 등이 섞이지 않게 스키마 필드만 보낸다).
    $probe = new Crud($db);
    $probe->init(FREIGHT_TABLE);
    $schema = $probe->getSchema()['fields'];
    $fields = array_keys($schema);
    $primaryKey = $probe->getPrimaryKey();
} catch (Throwable $e) {
    fr_json(['success' => false, 'error' => '운임표 확인 실패: ' . $e->getMessage()], 500);
}

// 저장·삭제 — 편집 버튼으로 고친 행. 응답({results, deleteResults})을 보내고 exit한다.
if ($action === 'save' || $action === 'delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fr_json(['success' => false, 'error' => 'POST로 요청해야 합니다.'], 405);
    }
    if ($primaryKey === null) {
        fr_json(['success' => false, 'error' => FREIGHT_TABLE . '에 PK가 없어 저장·삭제할 수 없습니다.'], 400);
    }
    $config = new BoardConfig();
    $config->connectionName = null;
    $config->tableName = FREIGHT_TABLE;
    $config->primaryKey = $primaryKey;
    $config->columns = implode(',', $fields);
    $config->searchFields = '';
    $config->fields = $fields;
    (new ActionBulk(new Crud($db), $config))->handle();
}

try {
    // 정렬: 중량코드 ASC — 필드가 없으면 이유를 남긴다(이름은 방언이 감싼다).
    $orderParts = [];
    foreach (FREIGHT_ORDER_FIELDS as $field) {
        if (!isset($schema[$field])) {
            throw new RuntimeException(FREIGHT_TABLE . '에 ' . $field . ' 필드가 없습니다.');
        }
        $orderParts[] = $db->dialect()->quoteIdentifier($field) . ' ASC';
    }

    $board = new Board($db);
    $board->init(FREIGHT_TABLE, implode(',', $fields), '', '', implode(', ', $orderParts), 1, 1000000, 1);

    $keep = array_flip($fields);
    $rows = [];
    foreach ($board->getRows() as $row) {
        $rows[] = array_intersect_key($row, $keep);
    }
    fr_json(['rows' => $rows, 'lastRow' => count($rows)]);
} catch (Throwable $e) {
    fr_json(['success' => false, 'error' => '운임표 조회 실패: ' . $e->getMessage()], 500);
}
