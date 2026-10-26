<?php
declare(strict_types=1);

/**
 * [AGGrid 모듈용 CRUD 엔드포인트 — "문의"(t문의) 그리드 — 메뉴 "업무 > 문의"]
 * 구성: inquiry_entry.php(화면) + inquiry_fetch.php(이 파일). 서버 계약은
 * 기초자료형과 같다(src/js/aggrid/aggrid.md "서버 계약"/"기초자료형").
 *
 * 기초자료형과 다른 점:
 *  - 표시 필드는 문의.png 순서로 고정한다($inquiryFields) — 그림에 없는
 *    배송주소는 화면과 응답에서 뺀다(저장 시엔 보내지 않으므로 기존 값 유지).
 *  - 화면의 상태 콤보 값이 ?st=<상태>로 오면 그 상태만 조회한다. 검색어
 *    (?q=)는 표시 필드 전체에서 찾고, 둘 다 있으면 AND로 묶는다.
 *  - 기본 정렬은 code 내림차순(헤더 클릭 정렬은 그대로 동작).
 *  - 문의일자는 dblib 형식('Sep 30 2026 12:00:00:000AM')으로 오므로
 *    Str::toDate()로 'Y-m-d'로 바꿔 내려보낸다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Board', 'Crud', 'ActionBulk', 'BoardConfig', 'Login', 'Str');

// 독립 엔드포인트라 Layout(index)의 보호를 못 받으므로 여기서도 확인한다.
Login::isAlive();

$connectionName = null; // config/db.php의 'driver' 기본 접속을 따른다
$db = new DbContext($connectionName); // 이 요청의 DB 접속(처음 쓸 때 연결)
$tableName = 't문의';

// 표시 필드 — 문의.png 순서. inquiry_entry.php의 $inquiryFields와 같게 둔다
// (파일 하나 = 자기완결 원칙의 의도된 중복, aggrid.md "서버 구현 패턴" 참고).
$inquiryFields = [
    'code', '문의일자', '멤버code', '멤버', '상태',
    '고객사code', '고객사', '고객사직원code', '고객사직원',
    '직책', '회사전화번호', '휴대폰', '팩스', '이메일', '근무지주소', '비고',
];

$probe = new Crud($db);
$probe->init($tableName);
$primaryKey = $probe->getPrimaryKey();
$schemaFields = array_keys($probe->getSchema()['fields']);

// 표시 필드 — 그림 순서 그대로, 실제 스키마에 있는 것만.
$displayFields = array_values(array_filter($inquiryFields, function (string $field) use ($schemaFields): bool {
    return in_array($field, $schemaFields, true);
}));

// 검색어(?q=)는 표시 필드 전체에서 찾는다.
$searchFields = $displayFields;

$config = new BoardConfig();
$config->connectionName = $connectionName;
$config->tableName = $tableName;
$config->primaryKey = $primaryKey;
$config->columns = implode(',', $displayFields);
$config->searchFields = implode(',', $searchFields);
$config->fields = $displayFields;

$defaultOrderBy = $db->dialect()->quoteIdentifier('code') . ' DESC';

$keyword = isset($_GET['q']) ? (string) $_GET['q'] : '';

// 상태 필터(?st=) — 화면의 상태 콤보(tb상태_문의.상태)에서 고른 값과
// 정확히 같은 상태만. 비어 있으면 전체. 검색어 조건과는 AND로 묶인다
// (Board::init() 9번째 인수). 값은 PDO quote로 넣는다.
$extraCondition = '';
if (isset($_GET['st']) && trim((string) $_GET['st']) !== '') {
    $extraCondition = $db->dialect()->quoteIdentifier('상태') . ' = ' . $db->pdo()->quote(trim((string) $_GET['st']));
}

/**
 * Board::getRows()는 SELECT *라 표시 필드만 남기고(배송주소/ts 제외),
 * 문의일자를 'Y-m-d'로 바꾼다.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function inquiry_rows_for_client(array $rows, array $displayFields): array
{
    $keep = array_flip($displayFields);
    return array_map(function (array $row) use ($keep): array {
        $row = array_intersect_key($row, $keep);
        if (array_key_exists('문의일자', $row)) {
            $row['문의일자'] = Str::toDate($row['문의일자'], $row['문의일자']);
        }
        return $row;
    }, $rows);
}

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
        $board->init($config->tableName, $config->columns, $config->searchFields, $keyword, '', 1, 1000000, 1, $extraCondition, $defaultOrderBy);
        echo json_encode(['rows' => inquiry_rows_for_client($board->getRows(), $displayFields), 'where' => $board->getWhereString()], JSON_UNESCAPED_UNICODE);
        break;

    case 'list_paged':
    default:
        // page/pageSize/sort/dir는 Board::init()이 $_GET에서 직접 읽는다.
        header('Content-Type: application/json; charset=utf-8');
        $board = new Board($db);
        $board->init($config->tableName, $config->columns, $config->searchFields, $keyword, '', null, null, 1, $extraCondition, $defaultOrderBy);
        $totalRows = $board->getTotalRows();
        $lastRow = ($board->getPage() * $board->getPageSize() >= $totalRows) ? $totalRows : -1;
        // where — 이 rows를 조회할 때 실제로 쓴 WHERE(검색 확인용, inquiry_entry.php의 search()가 alert).
        echo json_encode([
            'rows' => inquiry_rows_for_client($board->getRows(), $displayFields),
            'lastRow' => $lastRow,
            'where' => $board->getWhereString(),
        ], JSON_UNESCAPED_UNICODE);
        break;
}
