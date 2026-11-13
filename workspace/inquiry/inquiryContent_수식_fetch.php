<?php
declare(strict_types=1);

/**
 * [수식 팝업(inquiryContent_수식_entry.php)의 CRUD 엔드포인트 — AGGrid 서버 계약]
 * ?kind=문의내용|수주|발주|견적 → 그 수식 테이블(inquiryContent_수식_tables.php의 FORMULA_TABLES).
 *  - list_all / list_paged (GET): 순서·필드명·수식·필드들(+ PK)을 순서 오름차순으로 전부 돌려준다.
 *  - save / delete (POST): ActionBulk(src/Action/ActionBulk.php)가 저장·삭제하고
 *    {results, deleteResults}로 행마다 성공/실패 이유를 돌려준다(aggrid.md "서버 계약").
 *    저장하는 필드는 위 네 필드뿐이다(BoardConfig 필드 목록 + Crud는 받은 필드만 고친다).
 *    PK가 없는 테이블은 고칠 행을 가릴 수 없어 저장·삭제를 거절한다.
 * 실패하면 {success:false, error:'…'}(400/500) — 화면에 이유가 보이게.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Board', 'Crud', 'ActionBulk', 'BoardConfig', 'Login');
require_once __DIR__ . '/inquiryContent_수식_tables.php';

// 독립 엔드포인트라 Layout(index)의 보호를 못 받으므로 여기서도 확인한다.
Login::isAlive();

/** JSON 오류로 응답하고 끝낸다. */
function fs_fail(string $message, int $status): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$validActions = ['list_all', 'list_paged', 'save', 'delete'];
$action = isset($_GET['action']) ? (string) $_GET['action'] : 'list_all';
if (!in_array($action, $validActions, true)) {
    fs_fail('잘못된 요청입니다.', 400);
}

try {
    $kind = formula_kind();
} catch (InvalidArgumentException $e) {
    fs_fail($e->getMessage(), 400);
}
$tableName = FORMULA_TABLES[$kind];

try {
    $db = new DbContext(null); // config/db.php의 'driver' 기본 접속

    $probe = new Crud($db);
    $probe->init($tableName);
    $schema = $probe->getSchema()['fields'];
    $primaryKey = $probe->getPrimaryKey();

    // 보여줄 필드가 테이블에 다 있는지 — 없으면 이유를 남긴다.
    $fields = [];
    foreach (FORMULA_FIELDS as $field) {
        if (!isset($schema[$field])) {
            throw new RuntimeException($tableName . '에 ' . $field . ' 필드가 없습니다.');
        }
        $fields[] = $field;
    }
    // 행 식별용 PK도 함께 주고받는다(그리드에는 안 보인다).
    if ($primaryKey !== null && !in_array($primaryKey, $fields, true)) {
        $fields[] = $primaryKey;
    }
} catch (Throwable $e) {
    fs_fail('수식 테이블 확인 실패: ' . $e->getMessage(), 500);
}

switch ($action) {
    case 'save':
    case 'delete':
        if ($primaryKey === null) {
            fs_fail($tableName . '에 PK가 없어 저장·삭제할 수 없습니다.', 400);
        }
        $config = new BoardConfig();
        $config->connectionName = null;
        $config->tableName = $tableName;
        $config->primaryKey = $primaryKey;
        $config->columns = implode(',', $fields);
        $config->searchFields = '';
        $config->fields = $fields;
        // body의 rows/deletedCodes를 저장·삭제하고 {results, deleteResults}를 응답한 뒤 exit한다.
        (new ActionBulk(new Crud($db), $config))->handle();
        break;

    case 'list_all':
    case 'list_paged':
    default:
        try {
            // 순서 오름차순. 테이블·컬럼명은 서버 코드에 고정(사용자 입력 없음).
            $orderBy = $db->dialect()->quoteIdentifier('순서') . ' ASC';

            $board = new Board($db);
            $board->init($tableName, implode(',', $fields), '', '', $orderBy, 1, 1000000, 1);

            // SELECT *라 바이너리 ts가 섞이지 않게 보낼 필드만 남긴다.
            $keep = array_flip($fields);
            $rows = [];
            foreach ($board->getRows() as $row) {
                $rows[] = array_intersect_key($row, $keep);
            }

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['rows' => $rows, 'lastRow' => count($rows)], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            fs_fail('수식 조회 실패: ' . $e->getMessage(), 500);
        }
        break;
}
