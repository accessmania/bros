<?php
declare(strict_types=1);

/**
 * [AGGrid 조회 엔드포인트 — 미수금 목록(v미수금관리 뷰) — 메뉴 "미수금 > 미수금전체 / 미수금>0 / USD미수금>0"]
 * 구성: inquiry_미수금_entry.php(화면) + inquiry_미수금_fetch.php(이 파일). 그룹은 ?g=전체|원화|USD.
 * inquiry_문의내용_fetch.php와 같은 틀이다(Access: mSP.create("sp미수금관리",
 * "select * from v미수금관리", True)).
 *  - 원본은 뷰 v미수금관리. 뷰라서 **조회만** 한다 — save/delete 요청은 400.
 *    수정은 수주 팝업(inquiryContent_수주_amd.php)에서 한다.
 *  - 표시 필드는 $receivableFields 순서. inquiry_미수금_entry.php와 같게 둔다
 *    (파일 하나 = 자기완결 원칙의 의도된 중복).
 *  - **고정 조건**($fixedCondition): 미수금 > 0 또는 수금일 없음(Access vaddwhere). 늘 붙는다.
 *  - **그룹 조건**($groupConditions, ?g=): 원화 = 미수금 > 0·결제통화 KRW·수금일 없음,
 *    USD = USD미수금 > 0·결제통화 USD·수금일 없음(Access grouptype2 = 2 / 3). 전체는 없음.
 *  - **미수금B**(?g=B전체|B원화|BUSD): 원본이 q문의_미수금_bound(t문의 + t수주내역 조인 — $boundQuery,
 *    Board query 모드)로 바뀌고, 고정 조건 없이 그룹 조건(B원화 = 원화, BUSD = USD와 같음)만 붙는다.
 *    정렬은 고객사 오름차순. 컬럼은 Access 데이터시트 순서($boundFields).
 *  - ?st=<상태>면 그 상태만(콤보 원본 tb상태_수주). ?q=는 표시 필드 전체. 모두 AND.
 *  - 날짜 타입 컬럼(수주일자 등)은 dblib 형식이라 Str::toDate()로 'Y-m-d'로 바꾼다.
 *  - 기본 정렬은 수금예정일 오름차순, 문의code 내림차순, 고객사 오름차순(Access와 같음).
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
$viewName = 'v미수금관리';

// 표시 필드 — 수주 목록과 같은 컬럼·순서(Access도 수주 데이터시트 f문의_수주내역_ds2를 쓴다).
$receivableFields = [
    'code', 'parentcode', '문의code', '수주일자', '상태', '제품종류', '고객사', '모델명',
    '품목코드', '제조사', '수량', '적용수량', '판매단가', '변경납기', '비고', '출하예정일',
    '배송방법', 'USD판매단가', '공급가총액', 'USD총액', '납품일자', '수금예정일', '미수금', 'usd미수금',
    '결제통화', '결제환율', '할인금액', '수금', '수금일', 'USD할인금액', 'USD수금', '수금메모',
    '세금계산서', '배송주소',
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
    echo json_encode(['success' => false, 'error' => '미수금 목록은 조회 전용입니다(v미수금관리 뷰).'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 뷰도 INFORMATION_SCHEMA.COLUMNS에 나오므로 Crud::init()으로 스키마를 읽는다.
// 뷰에는 PK 제약이 없어 'code' 컬럼을 키로 잡는다(SqlDialectMssql::loadSchema 참고).
$probe = new Crud($db);
$probe->init($viewName);
$schema = $probe->getSchema()['fields'];

// 정한 순서 그대로, 뷰에 실제로 있는 것만.
$displayFields = [];
foreach ($receivableFields as $field) {
    if (isset($schema[$field])) {
        $displayFields[] = $field;
    }
}

/**
 * 고정 조건 — 미수금 목록에 늘 붙는 조건(화면에서 바꿀 수 없다). Access의 vaddwhere와 같다:
 *   Access : ( cdata([미수금]) > 0 or isnull_([수금일]) )
 *   MSSQL  : ( ISNULL([미수금], 0) > 0 OR [수금일] IS NULL )
 * 옮긴 규칙은 송금과 같다(cdata(x) > 0 → ISNULL(x, 0) > 0, isnull_(x) → x IS NULL).
 * 사용자 입력이 들어가지 않는 고정 문자열이라 자리표시자가 필요 없다(컬럼명은 서버 코드에 고정).
 */
$fixedCondition = '('
    . 'ISNULL(' . $db->dialect()->quoteIdentifier('미수금') . ', 0) > 0'
    . ' OR ' . $db->dialect()->quoteIdentifier('수금일') . ' IS NULL'
    . ')';

/**
 * 그룹 조건(?g=) — Access grouptype2. 메뉴가 고른 그룹의 조건을 고정 조건 뒤에 AND로 더 붙인다.
 * g는 아래 키 중 하나를 고르는 이름일 뿐이고, 조건 SQL은 여기 고정돼 있다(모르는 g는 '전체').
 *   원화(grouptype2 = 2) Access: cdata([미수금]) > 0 and 결제통화 = 'KRW' AND isnull_([수금일])
 *   USD (grouptype2 = 3) Access: cdata([USD미수금]) > 0 and 결제통화 = 'USD' AND isnull_([수금일])
 */
$groupConditions = [
    '전체' => '',
    '원화' => '('
        . 'ISNULL(' . $db->dialect()->quoteIdentifier('미수금') . ', 0) > 0'
        . ' AND ' . $db->dialect()->quoteIdentifier('결제통화') . " = 'KRW'"
        . ' AND ' . $db->dialect()->quoteIdentifier('수금일') . ' IS NULL'
        . ')',
    'USD' => '('
        . 'ISNULL(' . $db->dialect()->quoteIdentifier('USD미수금') . ', 0) > 0'
        . ' AND ' . $db->dialect()->quoteIdentifier('결제통화') . " = 'USD'"
        . ' AND ' . $db->dialect()->quoteIdentifier('수금일') . ' IS NULL'
        . ')',
];
/**
 * 미수금B 그룹(B전체/B원화/BUSD) — Access 미수금B 폼의 search():
 *   원본은 q문의_미수금_bound(아래 $boundQuery)이고 **고정 조건(vaddwhere)은 없다**.
 *   B원화: paddwhere "미수금 > 0" + vadd "( cdata([미수금]) > 0 and 결제통화 ='KRW' AND ISNULL_([수금일]) )"
 *   BUSD : paddwhere "usd미수금 > 0" + vadd "( cdata([USD미수금]) > 0 and 결제통화 ='USD' AND ISNULL_([수금일]) )"
 *   paddwhere의 "미수금 > 0"은 vadd 안의 같은 조건과 겹치므로(NULL도 둘 다 빠진다) 위 원화/USD 조건을 그대로 쓴다.
 */
$groupConditions['B전체'] = '';
$groupConditions['B원화'] = $groupConditions['원화'];
$groupConditions['BUSD'] = $groupConditions['USD'];

$group = isset($_GET['g']) ? trim((string) $_GET['g']) : '전체';
if (!isset($groupConditions[$group])) {
    $group = '전체';
}
$boundMode = in_array($group, ['B전체', 'B원화', 'BUSD'], true);

// 추가 조건 = 고정 조건(미수금B는 없음) AND (그룹 조건) AND (상태 콤보로 고른 상태).
// 검색어(?q=)는 Board가 다시 AND로 묶는다.
$extraCondition = $fixedCondition;
if ($boundMode) {
    $extraCondition = '';
}
if ($groupConditions[$group] !== '') {
    if ($extraCondition === '') {
        $extraCondition = $groupConditions[$group];
    } else {
        $extraCondition = $extraCondition . ' AND ' . $groupConditions[$group];
    }
}
if (isset($_GET['st']) && trim((string) $_GET['st']) !== '') {
    $statusCondition = $db->dialect()->quoteIdentifier('상태') . ' = ' . $db->pdo()->quote(trim((string) $_GET['st']));
    if ($extraCondition === '') {
        $extraCondition = $statusCondition;
    } else {
        $extraCondition = $extraCondition . ' AND ' . $statusCondition;
    }
}

// 기본 정렬 — Access와 같은 "수금예정일 asc, 문의code desc, 고객사 asc"(세 그룹 모두 같다).
// 끝의 code는 앞 셋이 같은 행끼리 순서를 고정해, 페이지(OFFSET)를 넘길 때 행이 겹치거나 빠지지 않게 한다.
$defaultOrderBy = $db->dialect()->quoteIdentifier('수금예정일') . ' ASC, '
    . $db->dialect()->quoteIdentifier('문의code') . ' DESC, '
    . $db->dialect()->quoteIdentifier('고객사') . ' ASC, '
    . $db->dialect()->quoteIdentifier('code') . ' ASC';
$keyword = isset($_GET['q']) ? (string) $_GET['q'] : '';

/**
 * 미수금B — 원본을 q문의_미수금_bound로 바꾼다:
 *   SELECT t문의.문의일자, t문의.고객사, t수주내역.* FROM t문의 INNER JOIN t수주내역 ON t문의.code = t수주내역.parentcode
 * Board의 query 모드(initFromQuery — 이 SELECT를 (원본) AS sub로 감싸 페이징·검색·정렬·추가 조건을 건다)로 읽는다.
 * t수주내역.*는 스키마로 컬럼을 펼친다 — 서브쿼리 안에서 이름이 겹치면(t수주내역에도 고객사가 있으면)
 * 오류가 나므로 고객사·문의일자는 t문의 것만 쓰고, 바이너리 컬럼(ts 등)은 뺀다.
 * 컬럼명은 DB 스키마에서 온 값이라 사용자 입력이 아니다.
 * 표시 필드는 Access 데이터시트 순서($boundFields, inquiry_미수금_entry.php와 같게 둔다) + 행 식별용 code·parentcode.
 * 기본 정렬은 Access grouptype 8(미수금)과 같은 "고객사 asc"(+ 페이지 고정용 code).
 */
$boundQuery = '';
if ($boundMode) {
    $boundFields = [
        '문의일자', '고객사', '수주일자', '수금일', '수금예정일', '공급가총액', '미수금', '할인금액',
        '수금', '상태', 'USD수금', 'USD할인금액', 'usd미수금', 'USD총액', '수금메모', '제품종류',
        '품목코드', '모델명', '제조사', '수량', '결제통화', '결제환율', '판매단가', 'USD판매단가',
        '적용수량', '변경납기', '출하예정일', '납품일자', '세금계산서', '배송방법', '배송주소', '비고',
    ];
    $boundProbe = new Crud($db);
    $boundProbe->init('t수주내역');
    $orderSchema = $boundProbe->getSchema()['fields'];
    $dialect = $db->dialect();
    $binaryTypes = ['timestamp', 'rowversion', 'binary', 'varbinary', 'image'];

    $selectParts = [
        $dialect->quoteIdentifier('t문의') . '.' . $dialect->quoteIdentifier('문의일자') . ' AS ' . $dialect->quoteIdentifier('문의일자'),
        $dialect->quoteIdentifier('t문의') . '.' . $dialect->quoteIdentifier('고객사') . ' AS ' . $dialect->quoteIdentifier('고객사'),
    ];
    // 날짜 변환용 스키마 — 문의일자는 t문의의 날짜다.
    $schema = ['문의일자' => ['type' => 'datetime'], '고객사' => ['type' => 'nvarchar']];
    foreach ($orderSchema as $field => $info) {
        if ($field === '문의일자' || $field === '고객사') {
            continue;
        }
        if (in_array(strtolower((string) ($info['type'] ?? '')), $binaryTypes, true)) {
            continue;
        }
        $selectParts[] = $dialect->quoteIdentifier('t수주내역') . '.' . $dialect->quoteIdentifier($field);
        $schema[$field] = $info;
    }
    $boundQuery = 'SELECT ' . implode(', ', $selectParts)
        . ' FROM ' . $dialect->quoteIdentifier('t문의')
        . ' INNER JOIN ' . $dialect->quoteIdentifier('t수주내역')
        . ' ON ' . $dialect->quoteIdentifier('t문의') . '.' . $dialect->quoteIdentifier('code')
        . ' = ' . $dialect->quoteIdentifier('t수주내역') . '.' . $dialect->quoteIdentifier('parentcode');

    $displayFields = [];
    foreach ($boundFields as $field) {
        if (isset($schema[$field])) {
            $displayFields[] = $field;
        }
    }
    $displayFields[] = 'code';
    $displayFields[] = 'parentcode';

    $defaultOrderBy = $dialect->quoteIdentifier('고객사') . ' ASC, '
        . $dialect->quoteIdentifier('code') . ' ASC';
}

/**
 * Board::getRows()는 SELECT *라 표시 필드만 남기고, 날짜 타입 컬럼은 'Y-m-d'로 바꾼다.
 *
 * @param array<int, array<string, mixed>> $prows
 * @param array<int, string> $pdisplayFields
 * @param array<string, array<string, mixed>> $pschema
 * @return array<int, array<string, mixed>>
 */
function inquiry_receivable_rows_for_client(array $prows, array $pdisplayFields, array $pschema): array
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
    if ($boundMode) {
        $board->initFromQuery($boundQuery, implode(',', $displayFields), $keyword, '', 1, 1000000, 1, $extraCondition, $defaultOrderBy, 'code');
    } else {
        $board->init($viewName, implode(',', $displayFields), implode(',', $displayFields), $keyword, '', 1, 1000000, 1, $extraCondition, $defaultOrderBy);
    }
    echo json_encode([
        'rows' => inquiry_receivable_rows_for_client($board->getRows(), $displayFields, $schema),
        'where' => $board->getWhereString(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// list_paged — page/pageSize/sort/dir는 Board::init()/initFromQuery()가 $_GET에서 직접 읽는다.
if ($boundMode) {
    $board->initFromQuery($boundQuery, implode(',', $displayFields), $keyword, '', null, null, 1, $extraCondition, $defaultOrderBy, 'code');
} else {
    $board->init($viewName, implode(',', $displayFields), implode(',', $displayFields), $keyword, '', null, null, 1, $extraCondition, $defaultOrderBy);
}
$totalRows = $board->getTotalRows();
$lastRow = -1;
if ($board->getPage() * $board->getPageSize() >= $totalRows) {
    $lastRow = $totalRows;
}
echo json_encode([
    'rows' => inquiry_receivable_rows_for_client($board->getRows(), $displayFields, $schema),
    'lastRow' => $lastRow,
    'where' => $board->getWhereString(),
], JSON_UNESCAPED_UNICODE);
