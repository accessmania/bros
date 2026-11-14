<?php
declare(strict_types=1);

/**
 * [AGGrid 조회 엔드포인트 — "선적미입고" 목록(v선적미입고 뷰) — 메뉴 "업무 > 선적미입고"]
 * 구성: inquiry_선적미입고_entry.php(화면) + inquiry_선적미입고_fetch.php(이 파일).
 * inquiry_문의내용_fetch.php와 같은 틀이다(Access: mSP.create("sp선적미입고",
 * "select * from v선적미입고", True)).
 *  - 원본은 뷰 v선적미입고. 뷰라서 **조회만** 한다 — save/delete 요청은 400.
 *    수정은 발주 팝업(inquiryContent_발주_amd.php)에서 한다.
 *  - 표시 필드는 $purchaseFields 순서. inquiry_선적미입고_entry.php와 같게 둔다
 *    (파일 하나 = 자기완결 원칙의 의도된 중복).
 *  - ?st=<상태>면 그 상태만(콤보 원본 tb상태_발주). ?q=는 표시 필드 전체.
 *  - 날짜 타입 컬럼(입고일 등)은 dblib 형식이라 Str::toDate()로 'Y-m-d'로 바꾼다.
 *  - 기본 정렬은 선적예정일 오름차순, 고객사 오름차순(Access grouptype 6과 같음). 헤더 클릭 정렬이 우선한다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Board', 'Crud', 'Login', 'Str');

// 독립 엔드포인트라 Layout(index)의 보호를 못 받으므로 여기서도 확인한다.
Login::isAlive();

header('Content-Type: application/json; charset=utf-8');

$connectionName = null; // config/db.php의 'driver' 기본 접속을 따른다
$db = new DbContext($connectionName);
$viewName = 'v선적미입고';

// 표시 필드 — 발주 목록과 같은 컬럼·순서(v선적미입고도 발주 행).
$purchaseFields = [
    'code', 'parentcode', '문의code', '발주번호', '상태', '제품code', '고객사', '제품종류',
    '품목코드', '모델명', '매입통화', '제조사', '수량', '적용수량', '외화매입가', '선적예정일',
    '비고', '발주서납기', '송금예정일', '원화매입가', '매입총액', '외화송금잔액', '입고수량', '입고일',
    '매입환율', 'KRW매입환율', '원화송금액', 'USD송금액', '송금일', '송금메모', '할인금액', '송금잔액',
    '외화매입총액', 'USD할인금액',
];

// 조회만 한다. AGGrid는 저장을 POST로, 삭제를 ?action=delete로 보낸다.
$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
if ($action === '') {
    if (isset($_GET['mode']) && $_GET['mode'] === 'all') {
        $action = 'list_all';
    } else {
        $action = 'list_paged';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' || !in_array($action, ['list_paged', 'list_all'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '선적미입고 목록은 조회 전용입니다(v선적미입고 뷰).'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 뷰도 INFORMATION_SCHEMA.COLUMNS에 나오므로 Crud::init()으로 스키마를 읽는다.
// 뷰에는 PK 제약이 없어 'code' 컬럼을 키로 잡는다(SqlDialectMssql::loadSchema 참고).
$probe = new Crud($db);
$probe->init($viewName);
$schema = $probe->getSchema()['fields'];

// 정한 순서 그대로, 뷰에 실제로 있는 것만.
$displayFields = [];
foreach ($purchaseFields as $field) {
    if (isset($schema[$field])) {
        $displayFields[] = $field;
    }
}

$extraCondition = '';
if (isset($_GET['st']) && trim((string) $_GET['st']) !== '') {
    $extraCondition = $db->dialect()->quoteIdentifier('상태') . ' = ' . $db->pdo()->quote(trim((string) $_GET['st']));
}
// 기본 정렬 — Access와 같은 "선적예정일 asc, 고객사 asc". 끝의 code는 같은 날짜·고객사끼리
// 순서를 고정해, 페이지(OFFSET)를 넘길 때 행이 겹치거나 빠지지 않게 한다.
$defaultOrderBy = $db->dialect()->quoteIdentifier('선적예정일') . ' ASC, '
    . $db->dialect()->quoteIdentifier('고객사') . ' ASC, '
    . $db->dialect()->quoteIdentifier('code') . ' ASC';
$keyword = isset($_GET['q']) ? (string) $_GET['q'] : '';

/**
 * Board::getRows()는 SELECT *라 표시 필드만 남기고, 날짜 타입 컬럼은 'Y-m-d'로 바꾼다.
 *
 * @param array<int, array<string, mixed>> $prows
 * @param array<int, string> $pdisplayFields
 * @param array<string, array<string, mixed>> $pschema
 * @return array<int, array<string, mixed>>
 */
function inquiry_purchase_rows_for_client(array $prows, array $pdisplayFields, array $pschema): array
{
    $dateTypes = ['datetime', 'date', 'smalldatetime', 'datetime2'];
    $result = [];
    foreach ($prows as $row) {
        $out = [];
        foreach ($pdisplayFields as $field) {
            $value = $row[$field] ?? null;
            if (in_array(strtolower((string) ($pschema[$field]['type'] ?? '')), $dateTypes, true)) {
                $value = Str::toDate($value, $value);
            }
            $out[$field] = $value;
        }
        $result[] = $out;
    }
    return $result;
}

$board = new Board($db);
if ($action === 'list_all') {
    $board->init($viewName, implode(',', $displayFields), implode(',', $displayFields), $keyword, '', 1, 1000000, 1, $extraCondition, $defaultOrderBy);
    echo json_encode([
        'rows' => inquiry_purchase_rows_for_client($board->getRows(), $displayFields, $schema),
        'where' => $board->getWhereString(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// list_paged — page/pageSize/sort/dir는 Board::init()이 $_GET에서 직접 읽는다.
$board->init($viewName, implode(',', $displayFields), implode(',', $displayFields), $keyword, '', null, null, 1, $extraCondition, $defaultOrderBy);
$totalRows = $board->getTotalRows();
$lastRow = -1;
if ($board->getPage() * $board->getPageSize() >= $totalRows) {
    $lastRow = $totalRows;
}
echo json_encode([
    'rows' => inquiry_purchase_rows_for_client($board->getRows(), $displayFields, $schema),
    'lastRow' => $lastRow,
    'where' => $board->getWhereString(),
], JSON_UNESCAPED_UNICODE);
