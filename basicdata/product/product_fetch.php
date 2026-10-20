<?php
declare(strict_types=1);

/**
 * [AGGrid 모듈용 CRUD 엔드포인트 — "제품"(tb제품, MSSQL deco DB) 그리드]
 * 기초자료형(src/js/aggrid/aggrid.md "기초자료형") — name: product,
 * tablename: tb제품. 구성: product_entry.php(화면) + product_fetch.php(이 파일).
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
$tableName = 'tb제품';

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
