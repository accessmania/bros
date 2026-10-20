<?php
declare(strict_types=1);

/**
 * [AGGrid 모듈용 CRUD 엔드포인트 — 상태 테이블들(tb상태_*, MSSQL deco DB)]
 * 기초자료형(src/js/aggrid/aggrid.md "기초자료형")의 fetch이되, 한 화면
 * (status_entry.php)에 그리드가 5개라 파일 하나가 테이블 5개를 맡는다 —
 * 어느 테이블인지는 `?t=<키>`로 받고, status_tables.php의 목록에 있는 키만
 * 허용한다(임의 테이블 접근 차단). AGGrid.js는 fetchUrl에 '?'가 있으면
 * action 등을 '&'로 이어 붙인다(_fetchUrlWith).
 *
 * 그 밖의 동작은 product_fetch.php 등과 같다 — 스키마를 조회해 전체 필드를
 * 그대로 주고받는다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Board', 'Crud', 'ActionBulk', 'BoardConfig');

$statusTables = require __DIR__ . '/status_tables.php';
$tableKey = isset($_GET['t']) ? (string) $_GET['t'] : '';
if (!isset($statusTables[$tableKey])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '알 수 없는 상태 테이블입니다: ' . $tableKey], JSON_UNESCAPED_UNICODE);
    exit;
}

$connectionName = null; // config/db.php의 'driver' 기본 접속을 따른다
$db = new DbContext($connectionName); // 이 요청의 DB 접속(처음 쓸 때 연결)
$tableName = $statusTables[$tableKey]['table'];

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

// 기본 정렬은 code 오름차순(2026-10-20 지시) — code 필드가 없으면 PK로.
// Board::init()의 마지막 인자(기본 정렬)라, 사용자가 헤더로 정렬하면
// 그쪽(sort/dir)이 우선한다.
$sortField = in_array('code', $allFields, true) ? 'code' : $primaryKey;
$defaultOrderBy = $sortField !== null ? $db->dialect()->quoteIdentifier($sortField) . ' ASC' : '';

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
        $board->init($config->tableName, $config->columns, $config->searchFields, $keyword, '', 1, 1000000, 1, '', $defaultOrderBy);
        echo json_encode(['rows' => $board->getRows()], JSON_UNESCAPED_UNICODE);
        break;

    case 'list_paged':
    default:
        // page/pageSize/sort/dir는 Board::init()이 $_GET에서 직접 읽는다.
        header('Content-Type: application/json; charset=utf-8');
        $board = new Board($db);
        $keyword = isset($_GET['q']) ? (string) $_GET['q'] : '';
        $board->init($config->tableName, $config->columns, $config->searchFields, $keyword, '', null, null, 1, '', $defaultOrderBy);
        $totalRows = $board->getTotalRows();
        $lastRow = ($board->getPage() * $board->getPageSize() >= $totalRows) ? $totalRows : -1;
        echo json_encode(['rows' => $board->getRows(), 'lastRow' => $lastRow], JSON_UNESCAPED_UNICODE);
        break;
}
