<?php
declare(strict_types=1);

/**
 * [AGGrid 모듈용 CRUD 엔드포인트 — "권한"(tAuth, MSSQL deco DB) 그리드]
 * src/js/aggrid/AGGrid.js가 fetch로 부르는 단일 엔드포인트. 서버 계약/
 * action 파라미터는 login/login_fetch.php와 완전히 동일한 형식이다
 * (aggrid.md "서버 계약" 참고) — 다만 이 화면은 그리드 자체에서 인라인
 * 편집/행추가를 아예 안 하고(auth_entry.php가 "편집"/"+ 행 추가" 버튼을
 * 숨김) "권한추가" 버튼으로 연 모달에서만 저장/삭제하므로, POST가 항상
 * 레코드 1건짜리 `rows`/`deletedCodes`만 보낸다는 점이 2_1과 다르다 —
 * 그래도 계약(그리고 서버가 쓰는 ActionBulk::handle())은 그대로 재사용
 * 가능하다(배열 원소가 1개일 뿐 계약 자체는 동일).
 *
 * tAuth의 컬럼 구성(권한명이 정확히 어떤 필드명인지 등)은 이 파일이
 * 몰라도 된다 — 컬럼을 특정해서 걸러내는 화면이 아니라(비밀번호 같은
 * 민감 필드가 없음) 스키마 전체를 그대로 응답에 실어 보내고, 어떤
 * 필드를 어떻게 보여줄지는 전적으로 auth_entry.php(클라이언트)가
 * 판단한다. authstring(체크박스 조합 결과가 담기는 긴 문자열)도 그리드
 * 컬럼으로는 안 보이지만 응답 데이터 자체에는 포함돼야, 더블클릭으로
 * 모달을 열 때(auth_entry.php의 onRowDblClick) 그 값을 읽어 체크박스
 * 상태를 복원할 수 있다.
 *
 * tAuth_ribbon/tAuth_add(메뉴권한/일반권한 후보 목록)는 이 화면이 전혀
 * 안 건드린다 — MS Access에서 직접 입력/관리되는 참조 테이블이라고
 * 사용자가 명시했다(auth_entry.php가 읽기 전용으로만 조회).
 *
 * 접속 정보는 이 파일에 고정하지 않고 config/db.php의 'driver' 기본 접속을
 * 그대로 따른다($connectionName = null).
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
$tableName = 'tAuth';

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

// --- action 결정 (login/login_fetch.php와 동일한 방식) --------------
$validActions = ['list_paged', 'list_all', 'save', 'delete'];
$action = isset($_GET['action']) && in_array($_GET['action'], $validActions, true)
    ? $_GET['action']
    : ($_SERVER['REQUEST_METHOD'] === 'POST'
        ? 'save'
        : ((isset($_GET['mode']) && $_GET['mode'] === 'all') ? 'list_all' : 'list_paged'));

switch ($action) {
    case 'save':
    case 'delete':
        // ActionBulk::handle()이 자체적으로 {results, deleteResults} JSON을
        // 응답하고 exit한다. authstring 조합(체크된 항목들을 이어붙이는 것)은
        // 전적으로 클라이언트(auth_entry.php)가 끝내고 완성된 문자열
        // 하나를 보내므로, 이 파일은 그 값을 그대로 저장할 뿐이다.
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
        header('Content-Type: application/json; charset=utf-8');
        $board = new Board($db);
        $keyword = isset($_GET['q']) ? (string) $_GET['q'] : '';
        $board->init($config->tableName, $config->columns, $config->searchFields, $keyword, '', null, null, 1);
        $totalRows = $board->getTotalRows();
        $lastRow = ($board->getPage() * $board->getPageSize() >= $totalRows) ? $totalRows : -1;
        echo json_encode(['rows' => $board->getRows(), 'lastRow' => $lastRow], JSON_UNESCAPED_UNICODE);
        break;
}
