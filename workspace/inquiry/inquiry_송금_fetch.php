<?php
declare(strict_types=1);

/**
 * [AGGrid 조회 엔드포인트 — 송금 목록(v송금관리 뷰) — 메뉴 "송금 > 송금전체 / 원화송금관리 / 외화송금관리"]
 * 구성: inquiry_송금_entry.php(화면) + inquiry_송금_fetch.php(이 파일). 그룹은 ?g=전체|원화|외화.
 * inquiry_문의내용_fetch.php와 같은 틀이다(Access: mSP.create("sp송금관리",
 * "select * from v송금관리", True)).
 *  - 원본은 뷰 v송금관리. 뷰라서 **조회만** 한다 — save/delete 요청은 400.
 *    수정은 발주 팝업(inquiryContent_발주_amd.php)에서 한다.
 *  - 표시 필드는 $purchaseFields 순서. inquiry_송금_entry.php와 같게 둔다
 *    (파일 하나 = 자기완결 원칙의 의도된 중복).
 *  - **고정 조건**($fixedCondition): 원화송금액 > 0 또는 USD송금액 > 0 또는 송금일 없음
 *    (Access vaddwhere). 모든 조회에 늘 붙는다.
 *  - **그룹 조건**($groupConditions, ?g=): 원화 = 송금일 없음·송금잔액 > 0·매입통화 KRW,
 *    외화 = 송금일 없음·외화송금잔액 > 0·매입통화 KRW 아님(Access grouptype2 = 2 / 3). 전체는 없음.
 *  - ?st=<상태>면 그 상태만(콤보 원본 tb상태_발주). ?q=는 표시 필드 전체. 모두 AND.
 *  - 날짜 타입 컬럼(입고일 등)은 dblib 형식이라 Str::toDate()로 'Y-m-d'로 바꾼다.
 *  - 기본 정렬은 송금예정일 오름차순, 문의code 내림차순, 고객사 오름차순(세 그룹 모두, Access와 같음).
 *    헤더 클릭 정렬이 우선한다.
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
$viewName = 'v송금관리';

// 표시 필드 — 발주 목록과 같은 컬럼·순서(Access도 발주 데이터시트 f문의_발주내역_ds2를 쓴다).
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
    echo json_encode(['success' => false, 'error' => '송금전체 목록은 조회 전용입니다(v송금관리 뷰).'], JSON_UNESCAPED_UNICODE);
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

/**
 * 고정 조건 — 송금전체에 늘 붙는 조건(화면에서 바꿀 수 없다). Access의 vaddwhere와 같다:
 *   Access : ( cdata([원화송금액]) > 0 OR cdata([USD송금액]) > 0 OR isnull_([송금일]) )
 *   MSSQL  : ( ISNULL([원화송금액], 0) > 0 OR ISNULL([USD송금액], 0) > 0 OR [송금일] IS NULL )
 * cdata / isnull_은 Access 안에서만 도는 VBA 함수라 SQL Server 문법으로 옮겼다:
 *  - cdata(x) > 0  → ISNULL(x, 0) > 0   (값이 없으면 0으로 보고 비교)
 *  - isnull_(x)    → x IS NULL          (송금일이 비어 있음)
 * 사용자 입력이 들어가지 않는 고정 문자열이라 자리표시자가 필요 없다(컬럼명은 서버 코드에 고정).
 */
$fixedCondition = '('
    . 'ISNULL(' . $db->dialect()->quoteIdentifier('원화송금액') . ', 0) > 0'
    . ' OR ISNULL(' . $db->dialect()->quoteIdentifier('USD송금액') . ', 0) > 0'
    . ' OR ' . $db->dialect()->quoteIdentifier('송금일') . ' IS NULL'
    . ')';

/**
 * 그룹 조건(?g=) — Access grouptype2. 메뉴가 고른 그룹의 조건을 고정 조건 뒤에 AND로 더 붙인다.
 * g는 아래 키 중 하나를 고르는 이름일 뿐이고, 조건 SQL은 여기 고정돼 있다(모르는 g는 '전체').
 *   원화(grouptype2 = 2) Access: isnull_([송금일]) and cdata([송금잔액]) > 0 and 매입통화='KRW'
 *   외화(grouptype2 = 3) Access: isnull_([송금일]) and cdata([외화송금잔액]) > 0 and 매입통화 <> 'KRW'
 * 옮긴 규칙은 위 고정 조건과 같다(isnull_ → IS NULL, cdata(x) > 0 → ISNULL(x, 0) > 0).
 * 매입통화 <> 'KRW'는 Access와 마찬가지로 매입통화가 비어 있는(NULL) 행은 고르지 않는다.
 */
$groupConditions = [
    '전체' => '',
    '원화' => '('
        . $db->dialect()->quoteIdentifier('송금일') . ' IS NULL'
        . ' AND ISNULL(' . $db->dialect()->quoteIdentifier('송금잔액') . ', 0) > 0'
        . ' AND ' . $db->dialect()->quoteIdentifier('매입통화') . " = 'KRW'"
        . ')',
    '외화' => '('
        . $db->dialect()->quoteIdentifier('송금일') . ' IS NULL'
        . ' AND ISNULL(' . $db->dialect()->quoteIdentifier('외화송금잔액') . ', 0) > 0'
        . ' AND ' . $db->dialect()->quoteIdentifier('매입통화') . " <> 'KRW'"
        . ')',
];
// 송금B 메뉴(B전체/B원화/B외화)는 조건이 송금과 같다 — 메뉴 권한(button송금B)만 다르다.
$groupConditions['B전체'] = $groupConditions['전체'];
$groupConditions['B원화'] = $groupConditions['원화'];
$groupConditions['B외화'] = $groupConditions['외화'];

$group = isset($_GET['g']) ? trim((string) $_GET['g']) : '전체';
if (!isset($groupConditions[$group])) {
    $group = '전체';
}

// 추가 조건 = 고정 조건 AND (그룹 조건) AND (상태 콤보로 고른 상태). 검색어(?q=)는 Board가 다시 AND로 묶는다.
$extraCondition = $fixedCondition;
if ($groupConditions[$group] !== '') {
    $extraCondition = $extraCondition . ' AND ' . $groupConditions[$group];
}
if (isset($_GET['st']) && trim((string) $_GET['st']) !== '') {
    $extraCondition = $extraCondition . ' AND '
        . $db->dialect()->quoteIdentifier('상태') . ' = ' . $db->pdo()->quote(trim((string) $_GET['st']));
}
// 기본 정렬 — Access와 같은 "송금예정일 asc, 문의code desc, 고객사 asc"(세 그룹 모두 같다).
// 끝의 code는 앞 셋이 같은 행끼리 순서를 고정해, 페이지(OFFSET)를 넘길 때 행이 겹치거나 빠지지 않게 한다.
$defaultOrderBy = $db->dialect()->quoteIdentifier('송금예정일') . ' ASC, '
    . $db->dialect()->quoteIdentifier('문의code') . ' DESC, '
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
