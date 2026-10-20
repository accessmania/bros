<?php
declare(strict_types=1);

/**
 * [AGGrid 모듈용 CRUD 엔드포인트 — "고객사"(tb고객사, MSSQL deco DB) 그리드]
 * 기초자료형(src/js/aggrid/aggrid.md "기초자료형") — name: customer,
 * tablename: tb고객사. 구성: customer_entry.php(화면) + customer_fetch.php(이 파일).
 * GET은 목록 조회, POST는 저장/삭제(aggrid.md "서버 계약").
 *
 * 컬럼은 하드코딩하지 않고 요청마다 Crud::init()으로 스키마를 조회해
 * 전체 필드를 그대로 주고받는다(숨기는 컬럼/민감 필드 없음).
 *
 * 접속 정보는 config/db.php의 'driver' 기본 접속을 따른다($connectionName = null).
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Board', 'Crud', 'ActionBulk', 'BoardConfig');

$connectionName = null; // config/db.php의 'driver' 기본 접속을 따른다
$db = new DbContext($connectionName); // 이 요청의 DB 접속(처음 쓸 때 연결)
$tableName = 'tb고객사';

$probe = new Crud($db);
$probe->init($tableName);
$primaryKey = $probe->getPrimaryKey();
$allFields = array_keys($probe->getSchema()['fields']);

$config = new BoardConfig();
$config->connectionName = $connectionName;
$config->tableName = $tableName;
$config->primaryKey = $primaryKey;
$config->columns = implode(',', $allFields);
$config->searchFields = implode(',', $allFields);
$config->fields = $allFields;

// --- action 결정 (login_fetch.php와 동일한 방식) -----------------------
$validActions = ['list_paged', 'list_all', 'save', 'delete'];
$action = isset($_GET['action']) && in_array($_GET['action'], $validActions, true)
    ? $_GET['action']
    : ($_SERVER['REQUEST_METHOD'] === 'POST'
        ? 'save'
        : ((isset($_GET['mode']) && $_GET['mode'] === 'all') ? 'list_all' : 'list_paged'));

// --- 고객사 삭제 — 담당자(tb고객사_직원목록, parentcode = 고객사 code)도 같이 지운다 ---
// (2026-10-20 지시) ActionBulk는 고객사만 지우므로 여기서 직접 처리한다. 담당자와
// 고객사를 한 트랜잭션으로 지워, 고객사 삭제가 실패하면 담당자 삭제도 되돌린다.
// 응답은 ActionBulk와 같은 {results, deleteResults} 형식(목록 그리드의 행 삭제와
// 고객사 팝업의 삭제 버튼 모두 이 경로를 탄다).
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $deletedCodes = isset($payload['deletedCodes']) && is_array($payload['deletedCodes']) ? $payload['deletedCodes'] : [];
    $pdo = $db->pdo();
    $dialect = $db->dialect();
    $customerTable = $dialect->quoteIdentifier($tableName);
    $staffTable = $dialect->quoteIdentifier('tb고객사_직원목록');
    $quotedPk = $dialect->quoteIdentifier((string) $primaryKey);
    $deleteResults = [];
    foreach ($deletedCodes as $code) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                'DELETE FROM ' . $staffTable . ' WHERE parentcode IN (SELECT code FROM ' . $customerTable . ' WHERE ' . $quotedPk . ' = :k)'
            )->execute(['k' => (string) $code]);
            $deleteCustomer = $pdo->prepare('DELETE FROM ' . $customerTable . ' WHERE ' . $quotedPk . ' = :k');
            $deleteCustomer->execute(['k' => (string) $code]);
            if ($deleteCustomer->rowCount() === 0) {
                throw new RuntimeException('삭제할 고객사를 찾을 수 없습니다: ' . $code);
            }
            $pdo->commit();
            $deleteResults[] = ['code' => $code, 'success' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $deleteResults[] = ['code' => $code, 'success' => false, 'message' => $e->getMessage()];
        }
    }
    echo json_encode(['results' => [], 'deleteResults' => $deleteResults], JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($action) {
    case 'save':
    case 'delete':
        // ActionBulk::handle()이 body의 rows/deletedCodes를 보고 저장/삭제를
        // 처리하고 {results, deleteResults} JSON을 응답한 뒤 exit한다.
        $crud = new Crud($db);
        (new ActionBulk($crud, $config))->handle();
        break;

    case 'list_all':
        header('Content-Type: application/json; charset=utf-8');
        $board = new Board($db);
        $keyword = isset($_GET['q']) ? (string) $_GET['q'] : '';
        $board->init($config->tableName, $config->columns, $config->searchFields, $keyword, '', 1, 1000000, 1);
        echo json_encode(['rows' => $board->getRows()], JSON_UNESCAPED_UNICODE);
        break;

    case 'list_paged':
    default:
        // page/pageSize/sort/dir는 Board::init()이 $_GET에서 직접 읽는다.
        header('Content-Type: application/json; charset=utf-8');
        $board = new Board($db);
        $keyword = isset($_GET['q']) ? (string) $_GET['q'] : '';
        $board->init($config->tableName, $config->columns, $config->searchFields, $keyword, '', null, null, 1);
        $totalRows = $board->getTotalRows();
        $lastRow = ($board->getPage() * $board->getPageSize() >= $totalRows) ? $totalRows : -1;
        echo json_encode(['rows' => $board->getRows(), 'lastRow' => $lastRow], JSON_UNESCAPED_UNICODE);
        break;
}
