<?php
declare(strict_types=1);

/**
 * [AGGrid 조회 엔드포인트 — "견적" 목록(v문의_견적산출 뷰) — 메뉴 "업무 > 견적"]
 * 구성: inquiry_견적_entry.php(화면) + inquiry_견적_fetch.php(이 파일).
 * inquiry_문의내용_fetch.php와 같은 틀이다(Access: mSP.create("sp문의_견적산출",
 * "select * from v문의_견적산출", True)).
 *  - 원본은 뷰 v문의_견적산출. 뷰라서 **조회만** 한다 — save/delete 요청은 400.
 *    수정은 견적 팝업(inquiryContent_견적_amd.php)에서 한다.
 *  - 표시 필드는 $quoteFields 순서. inquiry_견적_entry.php와 같게 둔다
 *    (파일 하나 = 자기완결 원칙의 의도된 중복).
 *  - ?model=<모델명>면 그 모델명만(모델 이력 팝업 — 업체 관계없이). 상태와 AND.
 *  - ?st=<상태>면 그 상태만(콤보 원본 tb상태_견적). ?q=는 표시 필드 전체.
 *  - 날짜 타입 컬럼(견적날짜 등)은 dblib 형식이라 Str::toDate()로 'Y-m-d'로 바꾼다.
 *  - 기본 정렬은 code(t문의_견적산출.code) 내림차순.
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
$viewName = 'v문의_견적산출';

// 표시 필드 — Access 견적 목록 순서.
$quoteFields = [
    'code', 'parentcode', '문의code', '상태', '견적날짜', '제품code', '고객사', '제품종류',
    '품목코드', '모델명', '제조사', '수량', '중량', 'Zone', '중량코드', 'StandardPrice',
    'DistiPrice', '적용환율', '할인률', '원화구매가', '유류할증요율', '은행수수료', '관세', '승수',
    '견적환율', '견적단가', 'USD단가', '실중량KG', 'DHL단가', '개별운송비', '운임비율', 'MOQ',
    '원화원가', '달러원가', '예상단가', '예상USD', '중량단가', '중량화물비용', 'KG배율', '부피중량',
    '화물배율', '비고',
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
    echo json_encode(['success' => false, 'error' => '견적 목록은 조회 전용입니다(v문의_견적산출 뷰).'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 뷰도 INFORMATION_SCHEMA.COLUMNS에 나오므로 Crud::init()으로 스키마를 읽는다.
// 뷰에는 PK 제약이 없어 'code' 컬럼을 키로 잡는다(SqlDialectMssql::loadSchema 참고).
$probe = new Crud($db);
$probe->init($viewName);
$schema = $probe->getSchema()['fields'];

// 정한 순서 그대로, 뷰에 실제로 있는 것만.
$displayFields = [];
foreach ($quoteFields as $field) {
    if (isset($schema[$field])) {
        $displayFields[] = $field;
    }
}

$extraCondition = '';
if (isset($_GET['st']) && trim((string) $_GET['st']) !== '') {
    $extraCondition = $db->dialect()->quoteIdentifier('상태') . ' = ' . $db->pdo()->quote(trim((string) $_GET['st']));
}
// ?model=<모델명> — 모델 이력 팝업(inquiryContent_모델이력_entry.php, amd의 빨간 ! 버튼)이 이 화면을 띄울 때.
// 그 모델명과 똑같은 행만(업체 관계없이). 상태 조건과는 AND. 값은 PDO quote로 넣는다.
if (isset($_GET['model']) && trim((string) $_GET['model']) !== '') {
    $modelCondition = $db->dialect()->quoteIdentifier('모델명') . ' = ' . $db->pdo()->quote(trim((string) $_GET['model']));
    if ($extraCondition === '') {
        $extraCondition = $modelCondition;
    } else {
        $extraCondition = '(' . $extraCondition . ') AND ' . $modelCondition;
    }
}
$defaultOrderBy = $db->dialect()->quoteIdentifier('code') . ' DESC';
$keyword = isset($_GET['q']) ? (string) $_GET['q'] : '';

/**
 * Board::getRows()는 SELECT *라 표시 필드만 남기고, 날짜 타입 컬럼은 'Y-m-d'로 바꾼다.
 *
 * @param array<int, array<string, mixed>> $prows
 * @param array<int, string> $pdisplayFields
 * @param array<string, array<string, mixed>> $pschema
 * @return array<int, array<string, mixed>>
 */
function inquiry_quote_rows_for_client(array $prows, array $pdisplayFields, array $pschema): array
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
        'rows' => inquiry_quote_rows_for_client($board->getRows(), $displayFields, $schema),
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
    'rows' => inquiry_quote_rows_for_client($board->getRows(), $displayFields, $schema),
    'lastRow' => $lastRow,
    'where' => $board->getWhereString(),
], JSON_UNESCAPED_UNICODE);
