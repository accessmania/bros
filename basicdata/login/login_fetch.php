<?php
declare(strict_types=1);

/**
 * [AGGrid 모듈용 CRUD 엔드포인트 — "로그인"(tLogin, MSSQL deco DB) 그리드]
 * src/js/aggrid/AGGrid.js가 fetch로 부르는 단일 엔드포인트. GET은 목록
 * 조회(select), POST는 저장/삭제(save/delete)를 처리한다(1_9/1_20의
 * fetch_회원.php와 동일한 서버 계약, src/js/aggrid/aggrid.md "서버 계약" 참고).
 *
 * 2026-08-25 추가 지시 — "어떤 값의 유무로 분기하는 구조라 이 페이지를
 * 호출하는 의도가 안 드러난다"는 지적에 따라, GET/POST 구분은 그대로
 * 유지한 채(읽기/쓰기라는 큰 구분은 HTTP 메서드가 이미 표현해주는 의미라
 * 남겨둠) 그 아래에서 정확히 무슨 요청인지를 `$_GET['action']`
 * (`'list_paged'`|`'list_all'`|`'save'`|`'delete'`) 하나로 확정한 뒤
 * `switch`로 분기한다 — `mode`가 있는지, POST body의 `rows`/`deletedCodes`
 * 중 어느 쪽이 채워졌는지를 코드를 읽는 사람이 역산할 필요가 없다.
 * `action`은 `AGGrid.js`가 이제 네 요청 모두에 실제로 함께 보내는 값이고
 * (aggrid.md "서버 계약" 참고), 혹시 없는 요청(예: 예전 캐시된 스크립트,
 * 수동 테스트 호출)이 와도 기존 신호(REQUEST_METHOD/mode)로 똑같이 추정해
 * 동작은 그대로 유지된다(아래 "action 결정" 참고) — 안전망일 뿐 필수
 * 요구사항으로 강제하지 않는다.
 *
 * tLogin의 실제 컬럼 구성을 미리 알 수 없어서(기존 이 폴더의 config.php가
 * 쓰던 것과 동일한 이유 — 스키마를 사전에 조사할 방법이 없었음), 컬럼 목록을
 * 하드코딩하지 않고 요청마다 Crud::init()으로 스키마를 직접 조회해 표시
 * 대상을 구성한다.
 *
 * 2026-08-25 추가 지시로 비밀번호 컬럼은 더 이상 제외하지 않는다(화면에서
 * 직접 보고 고칠 수 있어야 한다는 요구). 권한코드로 보이는 컬럼도
 * 마찬가지로 이제는 안 가린다 — 처음엔("감춤" 지시) 응답에서 아예
 * 뺐었지만, 뒤이은 지시로 그 필드가 콤보박스(tAuth 참조)로 바뀌면서 값을
 * 읽고 써야 하는 편집 대상이 됐으므로 숨길 이유가 없어짐(콤보 렌더링은
 * 전적으로 login_entry.php 책임 — 이 파일은 그냥 값을 있는 그대로
 * 주고받는다).
 *
 * 다만 tLogin엔 "권한코드"(콤보가 다루는 FK)와는 별개로 순수 텍스트
 * "권한" 컬럼이 실제로 따로 있었음이 확인됨 — 콤보 헤더도 우연히 같은
 * 이름("권한")이라 화면에 "권한"이 두 번 보이는 혼동이 생겨, 이 텍스트
 * "권한" 컬럼(정확히 '권한'인 필드 하나)만 응답에서 제외한다(감춤 —
 * 이 화면에서 편집 대상도 아니고, 콤보가 이미 그 자리를 대신함).
 *
 * "사용여부" 컬럼도 그대로 포함하되, 1/0 ↔ 체크박스 변환은 전적으로
 * 클라이언트(login_entry.php의 valueGetter/valueSetter)가 맡는다 — 이
 * 파일은 그 값을 그냥 있는 그대로 주고받을 뿐이다(클라이언트가 저장 시
 * 항상 정수 1 또는 0으로 바꿔 보낸다).
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
$tableName = 'tLogin';
$passwordPattern = '/pw|pass|암호|비밀번호/i';

$probe = new Crud($db);
$probe->init($tableName);
$primaryKey = $probe->getPrimaryKey();
$allFields = array_keys($probe->getSchema()['fields']);

// 비밀번호/권한코드/사용여부는 전부 화면에서 다루게 됐으므로 걸러내지
// 않는다 — 순수 텍스트 "권한" 필드(정확히 '권한'인 것) 하나만 감춘다.
$displayFields = array_values(array_filter($allFields, function ($field) {
    return $field !== '권한';
}));

// 비밀번호는 화면에 보이지만, 검색(LIKE) 대상에서는 계속 제외한다 —
// 값 자체를 노출하는 것과 그 값으로 부분일치 검색을 허용하는 것은 별개 문제.
$passwordField = null;
foreach ($displayFields as $field) {
    if (preg_match($passwordPattern, $field) === 1) {
        $passwordField = $field;
        break;
    }
}
$searchFields = array_values(array_filter($displayFields, function ($field) use ($passwordField) {
    return $field !== $passwordField;
}));

$config = new BoardConfig();
$config->connectionName = $connectionName;
$config->tableName = $tableName;
$config->primaryKey = $primaryKey;
$config->columns = implode(',', $displayFields);
$config->searchFields = implode(',', $searchFields);
$config->fields = $displayFields;

// --- action 결정 -----------------------------------------------------
// AGGrid.js가 보내는 명시적 신호를 최우선으로 쓰고, 없으면(예전 캐시된
// 스크립트 등) 기존 신호(REQUEST_METHOD/mode)로 폴백한다. POST 폴백은
// 'save'/'delete'를 구분할 근거가 없어 일단 'save'로 잡지만, 실제 처리
// (ActionBulk::handle())는 body의 rows/deletedCodes를 그대로 보고 둘 다
// 처리하므로 라벨이 어느 쪽이든 결과는 동일하다.
$validActions = ['list_paged', 'list_all', 'save', 'delete'];
$action = isset($_GET['action']) && in_array($_GET['action'], $validActions, true)
    ? $_GET['action']
    : ($_SERVER['REQUEST_METHOD'] === 'POST'
        ? 'save'
        : ((isset($_GET['mode']) && $_GET['mode'] === 'all') ? 'list_all' : 'list_paged'));

// --- action별 처리 -----------------------------------------------------
switch ($action) {
    case 'save':
    case 'delete':
        // 두 action이 같은 코드로 이어지는 건 의도된 것 — ActionBulk::handle()
        // 이 POST body의 rows/deletedCodes 유무로 저장/삭제를 이미 자체
        // 판단하므로(둘 다 한 번에 처리 가능하게 설계됨, src/Action/ActionBulk.php
        // 참고) 여기서 라벨별로 다시 나눌 이유가 없다. ActionBulk::handle()이
        // 자체적으로 {results, deleteResults} JSON을 응답하고 exit한다.
        // 컬럼 필터링이 없어졌으므로 클라이언트가 보내는 그대로(비밀번호/
        // 권한코드/사용여부 포함) 저장된다 — 권한코드/사용여부 값 변환
        // (콤보 선택→code, 체크박스→1/0)은 클라이언트가 이미 끝내고 보내므로
        // 이 파일은 아무것도 더 할 게 없다.
        $crud = new Crud($db);
        (new ActionBulk($crud, $config))->handle();
        break;

    case 'list_all':
        // 검색 조건에 맞는 전체를 한 번에(1_6/1_7의 기존 SG와 동일한 "사실상
        // 전체 조회" 원칙) — 정렬/열별 필터는 AG Grid가 브라우저 안에서 처리.
        header('Content-Type: application/json; charset=utf-8');
        $board = new Board($db);
        $keyword = isset($_GET['q']) ? (string) $_GET['q'] : '';
        // Board::getRows()는 항상 SELECT *로 전체 컬럼을 반환하므로(1_9의
        // fetch_회원.php와 같은 문제), config가 뺀 컬럼("권한" 텍스트 필드
        // 등)이 응답에 다시 새지 않도록 여기서 한 번 더 명시적으로 거른다.
        $onlyConfiguredColumns = function (array $rows) use ($board): array {
            $columns = array_flip($board->getArrayColumns());
            return array_map(function (array $row) use ($columns): array {
                return array_intersect_key($row, $columns);
            }, $rows);
        };
        $board->init($config->tableName, $config->columns, $config->searchFields, $keyword, '', 1, 1000000, 1);
        echo json_encode(['rows' => $onlyConfiguredColumns($board->getRows())], JSON_UNESCAPED_UNICODE);
        break;

    case 'list_paged':
    default:
        // page/pageSize/sort/dir는 명시적으로 안 넘기고 null로 둬서
        // Board::init()이 $_GET에서 직접 읽게 한다(AGGrid.js가 그 이름 그대로
        // 쿼리스트링을 만들어 보낸다 — aggrid.md "서버 계약" 참고).
        header('Content-Type: application/json; charset=utf-8');
        $board = new Board($db);
        $keyword = isset($_GET['q']) ? (string) $_GET['q'] : '';
        $onlyConfiguredColumns = function (array $rows) use ($board): array {
            $columns = array_flip($board->getArrayColumns());
            return array_map(function (array $row) use ($columns): array {
                return array_intersect_key($row, $columns);
            }, $rows);
        };
        $board->init($config->tableName, $config->columns, $config->searchFields, $keyword, '', null, null, 1);
        $totalRows = $board->getTotalRows();
        $lastRow = ($board->getPage() * $board->getPageSize() >= $totalRows) ? $totalRows : -1;
        echo json_encode(['rows' => $onlyConfiguredColumns($board->getRows()), 'lastRow' => $lastRow], JSON_UNESCAPED_UNICODE);
        break;
}
