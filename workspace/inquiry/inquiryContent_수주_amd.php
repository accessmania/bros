<?php
declare(strict_types=1);

/**
 * [수주 팝업 — t수주내역 한 건의 상세(바탕화면 수주팝업.png)]
 * 구성: inquiryContent_수주_amd.php(이 파일 — 입력폼 amd) + inquiryContent_수주_amd_fetch.php
 * (CrudJs 계약). Popup.js의 iframeUrl로 띄우는 화면이다:
 *
 *   new Popup({
 *       title: '수주', iframeUrl: '.../inquiryContent_수주_amd.php?code=1234',
 *       iframeWidth: 600, iframeHeight: 720
 *   });
 *
 * 실제로는 문의확인(inquiryContent_entry.php)이 수주 탭 더블클릭/신규추가 때 비모달로 화면
 * 오른쪽에 띄운다(icOpenSub / icOpenSubNew, 팝업 id 'ic-order'). 한 번 뜨면 다시 열지 않고, 다른 행은
 * action('selectRecord', {pk}), 신규는 action('newRecord', {parentcode})로 보여준다
 * (crudbuttons/CrudJs는 처음 한 번만 초기화). 저장/삭제 보고를 받으면 문의확인이 requery한다.
 *
 * 주소: ?code=<t수주내역.code> — 기존 행. ?code=new&parentcode=<t문의.code> — 신규.
 * 주소창에 바로 열어도 동작한다(창닫기는 그 창을 닫는다).
 *
 * 입력폼은 crudbuttons 모듈을 쓴다(src/Model/Crud/crudbuttons/crudbuttons.md):
 *  - 컨트롤 id = t수주내역 필드명. 값 채우기/모으기/저장/삭제/신규입력/창닫기는 아래
 *    crudbuttons iframe 안의 CrudJs가 한다. 이 페이지는 필드 배치와 이벤트만 정의한다.
 *  - 띄운 쪽에 보고: psender.report → 띄운 쪽의 action('amdReport', {ptype, payload, popupId})
 *      'saved'   {code, parentcode, isNew}
 *      'deleted' {code, parentcode}
 *      'closed'  (crudbuttons가 보낸다)
 *  - 신규입력: 같은 문의(parentcode)의 새 수주로 폼을 비운다 — parentcode는 crudbuttons의
 *    keep=parentcode로 남기고, afterCrudClear가 AMD_PARENT_CODE로 다시 넣는다.
 *    기본값: 수주일자 = 오늘, 결제통화 = KRW.
 *  - 필수입력: 모델명(crudbuttons의 required=) — 비어 있으면 저장하지 않는다.
 *  - 삭제하면 crudbuttons가 폼을 비운다(같은 문의의 신규 상태가 된다).
 *
 * 배치(위에서 아래로):
 *  - 검색(문의내용code) + 문의보기 버튼 + 재계산/수식 버튼
 *    문의code = 이 수주가 나온 문의내역(t문의내역.code). Enter/AfterUpdate면 그 문의내역의
 *    제품종류/품목코드/모델명/제조사/모델명code(= t문의내역.제품code)를 채운다(AMD_DETAIL_MAP).
 *    문의보기는 띄운 쪽의 action('selectRecord_문의내용', {code})로 문의내역 팝업을 연다.
 *  - 제품종류/품목코드, 모델명, 제조사, ECount 버튼
 *  - 왼쪽: 상태/수주일자/수량/적용수량/결제통화/판매단가/USD판매단가/변경납기/출하예정일/납품일자/세금계산서
 *    오른쪽: 공급가총액/미수금/할인금액/수금/USD총액/USD미수금/USD할인금액/USD수금/수금예정일/수금일/수금메모
 *  - 배송방법/배송주소/비고
 *  - crudbuttons(저장/삭제/신규입력/창닫기)
 *
 * ECount 버튼은 품목코드의 이카운트 재고를 버튼 옆에 보인다(amdEcountStock). 수식은 문의확인의 수식 팝업(action('openFormula')). 재계산은 calc() — 폼 값을 inquiryContent_calcFormula_fetch.php에 보내 tb수식_수주를 순서대로 계산하고 결과를 칸에 넣는다(빨간 레이블 칸을 바꿔도 calc). 저장은 저장 버튼. t수주내역에 없는 필드의 칸은 비활성으로 두고 저장하지 않는다.
 *
 * 페이지 함수: action(pcmd, pparams, psender) — 종류별 action_xxx로 나눠 넘긴다.
 *  - 'requery' — 다시 읽는다(저장 안 한 값이 있으면 건너뛴다).
 *  - 'close' / 'selectRecord' {pk} / 'isDirty' — 버튼 iframe에 맡긴다.
 *  - 'newRecord' {parentcode} — 그 문의의 새 수주로 폼을 비운다(수주 탭 신규추가).
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Crud', 'Login');

// 로그인이 안 살아있으면 여기서 login.php로 이동하고 끝난다.
Login::isAlive();

$code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
$parentCode = isset($_GET['parentcode']) ? trim((string) $_GET['parentcode']) : '';
$isNew = $code === 'new';

$db = new DbContext(null);
$crud = new Crud($db);
$crud->init('t수주내역');
// t수주내역에 실제로 있는 필드 — 없는 필드의 칸은 비활성으로 둔다.
$schemaFields = $crud->getSchema()['fields'];

// 레코드는 존재 확인과 parentcode에만 쓴다. 입력칸 값은 crudbuttons(CrudJs)가 채운다.
$record = null;
$loadError = '';
if ($isNew) {
    $record = [];
    if ($parentCode === '' || !ctype_digit($parentCode)) {
        $loadError = '신규 수주는 parentcode(문의 code)가 필요합니다.';
    }
} elseif ($code === '' || !ctype_digit($code)) {
    $loadError = '수주 code가 없습니다.';
} elseif ($crud->selectRecord($code)) {
    $record = $crud->getAllValues();
    $parentCode = (string) ($record['parentcode'] ?? $parentCode);
} else {
    $loadError = '수주(' . $code . ')를 찾을 수 없습니다.';
}

/** 콤보 원본 — t수주내역에 입력돼 있는 그 필드의 값들(맨 앞 빈 칸, $defaults는 늘 포함). */
function amd_distinct_options(DbContext $db, string $field, array $defaults): array
{
    $options = [''];
    foreach ($defaults as $value) {
        if (!in_array($value, $options, true)) {
            $options[] = $value;
        }
    }
    try {
        $col = $db->dialect()->quoteIdentifier($field);
        $stmt = $db->pdo()->query('SELECT DISTINCT ' . $col . ' FROM ' . $db->dialect()->quoteIdentifier('t수주내역')
            . ' WHERE ' . $col . ' IS NOT NULL ORDER BY ' . $col . ' ASC');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
            $value = trim((string) $value);
            if ($value !== '' && !in_array($value, $options, true)) {
                $options[] = $value;
            }
        }
    } catch (Throwable $e) {
        // 콤보는 기본값만 두고 화면은 뜬다.
    }
    return $options;
}

$statusOptions = amd_distinct_options($db, '상태', []);
$currencyOptions = amd_distinct_options($db, '결제통화', ['KRW', 'USD']);

/** t수주내역에 없는 필드면 disabled 속성(+ 안내 title). */
function amd_disabled(array $schemaFields, string $field): string
{
    return isset($schemaFields[$field]) ? '' : ' disabled title="t수주내역에 ' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . ' 필드가 없습니다"';
}

/** 글자 칸 하나 — id = 필드명. */
function amd_text_input(array $schemaFields, string $field, string $class = '', string $type = 'text'): string
{
    $classAttr = $class === '' ? '' : ' class="' . $class . '"';
    return '<input type="' . $type . '" id="' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . '"' . $classAttr
        . amd_disabled($schemaFields, $field) . '>';
}

/** 숫자 칸 하나 — 오른쪽 정렬, 소수 자리 맞추기는 JS가 한다(amdFormatNumber). */
function amd_number_input(array $schemaFields, string $field): string
{
    return amd_text_input($schemaFields, $field, 'amd-num');
}

/** 날짜 칸 하나 — 가운데 정렬. */
function amd_date_input(array $schemaFields, string $field): string
{
    return amd_text_input($schemaFields, $field, 'amd-date', 'date');
}

/** 콤보 하나 — id = 필드명, 항목은 $options. */
function amd_select(array $schemaFields, string $field, array $options): string
{
    $html = '<select id="' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . '"' . amd_disabled($schemaFields, $field) . '>';
    foreach ($options as $value) {
        $value = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $html .= '<option value="' . $value . '">' . $value . '</option>';
    }
    return $html . '</select>';
}

/** 라벨 하나 — $color는 'red' / 'blue' / ''. */
function amd_label(string $field, string $text, string $color = ''): string
{
    $classAttr = $color === '' ? '' : ' class="amd-' . $color . '"';
    return '<label for="' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . '"' . $classAttr . '>'
        . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</label>';
}

// 왼쪽/오른쪽 두 줄 칸 — [필드, 라벨, 색, 종류(num/date/text/status/currency)]. 위에서 아래로 한 줄씩 짝지어 그린다.
$leftFields = [
    ['상태', '상태', '', 'status'],
    ['수주일자', '수주일자', '', 'date'],
    ['수량', '수량', '', 'num'],
    ['적용수량', '적용수량', 'red', 'num'],
    ['결제통화', '결제통화', 'red', 'currency'],
    ['판매단가', '판매단가', 'red', 'num'],
    ['USD판매단가', 'USD판매단가', 'red', 'num'],
    ['변경납기', '변경납기', '', 'date'],
    ['출하예정일', '출하예정일', '', 'date'],
    ['납품일자', '납품일자', '', 'date'],
    ['세금계산서', '세금계산서', '', 'text'],
];
$rightFields = [
    ['공급가총액', '공급가총액', 'blue', 'num'],
    ['미수금', '미수금', 'blue', 'num'],
    ['할인금액', '할인금액', 'red', 'num'],
    ['수금', '수금', 'red', 'num'],
    ['USD총액', 'USD총액', 'blue', 'num'],
    ['usd미수금', 'USD미수금', 'blue', 'num'],
    ['USD할인금액', 'USD할인금액', 'red', 'num'],
    ['USD수금', 'USD수금', 'red', 'num'],
    ['수금예정일', '수금예정일', '', 'date'],
    ['수금일', '수금일', '', 'date'],
    ['수금메모', '수금메모', '', 'text'],
];

/** 두 줄 칸의 컨트롤 하나 — 종류에 맞게. */
function amd_pair_control(array $schemaFields, array $item, array $statusOptions, array $currencyOptions): string
{
    $field = $item[0];
    switch ($item[3]) {
        case 'num':
            return amd_number_input($schemaFields, $field);
        case 'date':
            return amd_date_input($schemaFields, $field);
        case 'status':
            return amd_select($schemaFields, $field, $statusOptions);
        case 'currency':
            return amd_select($schemaFields, $field, $currencyOptions);
        default:
            return amd_text_input($schemaFields, $field);
    }
}

// crudbuttons iframe — fetch(CrudJs 계약)와 처음 열 PK(신규면 빈 값 → 빈 폼).
// keep=parentcode: 신규입력(clear)·삭제 뒤에도 parentcode(이 수주가 딸린 t문의.code)는
// 비우지 않고 남긴다 — 같은 문의의 새 수주를 이어서 입력하게.
// (PK code는 남기지 않는다 — 남으면 신규가 아니라 수정이 된다.)
$fetchUrl = APP_BASE_URL . '/workspace/inquiry/' . rawurlencode('inquiryContent_수주_amd_fetch.php');
$pk = $isNew ? '' : $code;
// required=모델명: 필수입력 — 비어 있으면 저장하지 않는다.
// closeconfirm=0: 창닫기 때 저장 안 한 값이 있어도 묻지 않고 닫는다.
$buttonsSrc = APP_BASE_URL . '/src/Model/Crud/crudbuttons/crudbuttons.php?fetch=' . rawurlencode($fetchUrl)
    . '&pk=' . rawurlencode($pk) . '&keep=' . rawurlencode('parentcode')
    . '&required=' . rawurlencode('모델명') . '&closeconfirm=0';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>수주</title>
<style>
/* amd 창은 스크롤바를 보이지 않는다 — 팝업 크기에 맞춰 배치한다(창이 작으면 아래가 잘린다). */
html, body { margin: 0; height: 100%; overflow: hidden; }
body {
    display: flex; flex-direction: column;
    padding: 14px 30px 6px; box-sizing: border-box; background: #ececec; color: #000;
    font-family: 'Malgun Gothic', 'Segoe UI', system-ui, sans-serif; font-size: 13px;
}
.amd-form { display: grid; grid-template-columns: 90px 1fr 90px 1fr; gap: 7px 10px; align-items: center; }
.amd-form label { white-space: nowrap; }
.amd-form input, .amd-form select {
    font: inherit; width: 100%; box-sizing: border-box; height: 25px; padding: 0 6px;
    border: 1px solid #a3a3a3; background: #fff; color: #000;
}
.amd-form input:disabled, .amd-form select:disabled { background: #e5e7eb; cursor: not-allowed; }
.amd-form input:focus, .amd-form select:focus { outline: none; border-color: #3b82f6; }
.amd-form .amd-num { text-align: right; }
.amd-form .amd-date { text-align: center; }
/* 날짜 칸이 비었으면(null) "연도-월-일" 안내 글자를 숨긴다. 칸에 들어가면(focus) 다시 보여 입력할 수 있다.
   amd-date-empty는 JS(amdMarkEmptyDates)가 붙인다. ::-webkit-datetime-edit는 크롬/엣지 전용. */
.amd-date.amd-date-empty:not(:focus)::-webkit-datetime-edit { color: transparent; }
.amd-form select { text-align: center; }
.amd-wide { grid-column: 2 / 5; }
.amd-gap { grid-column: 1 / 5; height: 6px; }
.amd-red { color: #dc2626; }
.amd-blue { color: #2563eb; }

/* 검색(문의내용code) 줄 — 문의code + 문의보기, 오른쪽 재계산/수식 버튼. */
.amd-search-label { font-weight: 600; text-decoration: underline; white-space: nowrap; flex: none; min-width: 90px; margin-right: 4px; }
/* 라벨 "검색(문의내용code)"이 90px 열보다 길어 줄 전체(1~4열)를 한 줄 flex로 쓴다. */
.amd-code-row { grid-column: 1 / 5; display: flex; align-items: center; gap: 6px; }
/* 문의내용code 칸은 줄어들 수 있게(창이 좁으면 버튼이 밀려나지 않도록). 버튼은 좌우 여백을 줄인다. */
.amd-form .amd-code-row input { flex: 0 1 90px; min-width: 60px; }
.amd-code-row button { padding: 0 6px; }
.amd-code-row .amd-spacer { flex: 1; }

button {
    font: inherit; height: 25px; padding: 0 10px; cursor: pointer; white-space: nowrap;
    border: 1px solid #a3a3a3; background: #e5e5e5; color: #000;
}
button:hover:not(:disabled) { background: #d4d4d4; }
button:disabled { color: #9ca3af; cursor: default; }
/* ECount 버튼 줄 — 버튼 옆에 이카운트 재고(제품·수량) 또는 실패 이유 */
.amd-ecount-row { grid-column: 1 / 5; display: flex; align-items: center; gap: 10px; }
.amd-ecount-result { color: #dc2626; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.amd-ecount-result.amd-error { color: #dc2626; }
.amd-ecount b { color: #dc2626; }

/* 문의code 찾기/준비 중 안내(저장·삭제 결과는 crudbuttons가 버튼 옆에 보인다). */
.amd-message { min-height: 18px; margin-top: 6px; color: #374151; }
.amd-message.amd-error { color: #dc2626; }

/* crudbuttons iframe(저장/삭제/신규입력/창닫기) — 폼 아래 바닥에, 배경이 비치게. */
.cb-frame { display: block; flex: none; width: 100%; height: 44px; margin-top: auto; border: 0; background: transparent; }
</style>
</head>
<body>
<?php if ($loadError !== ''): ?>
<p class="amd-message amd-error"><?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></p>
<?php else: ?>
<form class="amd-form" id="amd-form" autocomplete="off" onsubmit="return false;">
    <!-- 검색(문의내용code) — 이 수주가 나온 문의내역(t문의내역.code). Enter면 제품 칸을 채운다. -->
    <div class="amd-code-row">
        <label for="문의code" class="amd-search-label">검색(문의내용code)</label>
        <input type="text" id="문의code" title="문의내용 code를 넣고 Enter — 제품 칸을 채웁니다"<?= amd_disabled($schemaFields, '문의code') ?>>
        <button type="button" id="amd-detail-open" title="문의보기" aria-label="문의보기">&#128269;</button>
        <span class="amd-spacer"></span>
        <button type="button" id="amd-recalc">&#9998; 재계산</button>
        <button type="button" id="amd-formula">&#128269; 수식</button>
    </div>

    <div class="amd-gap"></div>

    <?= amd_label('제품종류', '제품종류') ?>
    <?= amd_text_input($schemaFields, '제품종류') ?>

    <?= amd_label('품목코드', '품목코드') ?>
    <?= amd_text_input($schemaFields, '품목코드') ?>

    <?= amd_label('모델명', '모델명') ?>
    <?= amd_text_input($schemaFields, '모델명', 'amd-wide') ?>

    <?= amd_label('제조사', '제조사') ?>
    <?= amd_text_input($schemaFields, '제조사', 'amd-wide') ?>

    <div class="amd-ecount-row">
        <button type="button" class="amd-ecount" id="amd-ecount"><b>!</b> ECount</button>
        <span class="amd-ecount-result" id="amd-ecount-result"></span>
    </div>

    <div class="amd-gap"></div>

<?php for ($i = 0; $i < count($leftFields); $i++): ?>
    <?= amd_label($leftFields[$i][0], $leftFields[$i][1], $leftFields[$i][2]) ?>
    <?= amd_pair_control($schemaFields, $leftFields[$i], $statusOptions, $currencyOptions) ?>
    <?= amd_label($rightFields[$i][0], $rightFields[$i][1], $rightFields[$i][2]) ?>
    <?= amd_pair_control($schemaFields, $rightFields[$i], $statusOptions, $currencyOptions) ?>

<?php endfor; ?>
    <div class="amd-gap"></div>

    <?= amd_label('배송방법', '배송방법') ?>
    <?= amd_text_input($schemaFields, '배송방법', 'amd-wide') ?>

    <?= amd_label('배송주소', '배송주소') ?>
    <?= amd_text_input($schemaFields, '배송주소', 'amd-wide') ?>

    <?= amd_label('비고', '비고') ?>
    <?= amd_text_input($schemaFields, '비고', 'amd-wide') ?>
</form>
<div class="amd-message" id="amd-message"></div>

<!-- 저장/삭제/신규입력/창닫기 — crudbuttons(CrudJs가 위 컨트롤에 붙는다) -->
<iframe class="cb-frame" id="cb-frame" src="<?= htmlspecialchars($buttonsSrc, ENT_QUOTES, 'UTF-8') ?>" title="저장/삭제 버튼"></iframe>

<script>
// VBA식 JS — var(함수 맨 위에 모아 선언), function, if/else, for, 매개변수는 p로 시작,
// 문자열은 +로 잇기, 서버를 기다리는 일은 async/await(crudbuttons.md 1절).
// 화면 전용 함수·변수는 amd / AMD_ 접두사. 이벤트 함수와 action/action_xxx에는 붙이지 않는다.

// 이 수주가 딸린 문의(t문의.code) — 신규로 비울 때 parentcode로 넣는다.
var AMD_PARENT_CODE = <?= json_encode($parentCode) ?>;
var AMD_FETCH_URL = <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
// 수식 계산 창구 — 폼 값을 보내면 tb수식_수주를 순서대로 계산한 결과(JSON)를 돌려준다.
var AMD_CALC_URL = encodeURIComponent('inquiryContent_calcFormula_fetch.php') + '?kind=' + encodeURIComponent('수주');
// ECount 버튼 창구 — 품목코드로 이카운트 재고(품목 하나)를 조회한다.
var AMD_ECOUNT_URL = encodeURIComponent('inquiryContent_ECount_fetch.php');
// calc 요청 순번 — 늦게 온 앞 요청의 결과가 나중 결과를 덮지 않게.
var amdCalcSeq = 0;
// 숫자 칸의 소수 자리 — USD는 0.00, 나머지는 정수에 천단위 콤마.
var AMD_NUMBER_DECIMALS = {
    '수량': 0, '적용수량': 0, '판매단가': 0, 'USD판매단가': 2,
    '공급가총액': 0, '미수금': 0, '할인금액': 0, '수금': 0,
    'USD총액': 2, 'usd미수금': 2, 'USD할인금액': 2, 'USD수금': 2
};
// "검색(문의내용code)"로 가져온 문의내역의 제품 정보를 넣는 칸 — fetch가 t수주내역 필드 이름으로 보낸다.
// (t문의내역.제품code → 모델명code. inquiryContent_수주_amd_fetch.php의 DETAIL_MAP과 같아야 한다.)
var AMD_DETAIL_MAP = ['제품종류', '품목코드', '모델명', '모델명code', '제조사'];
// 신규 기본값.
var AMD_DEFAULT_CURRENCY = 'KRW';
// 마지막으로 찾은 문의code — Enter로 찾은 뒤 칸을 떠날 때(change) 같은 찾기를 또 하지 않게.
var amdLastDetailCode = null;
// crudbuttons(psender) — 이벤트가 처음 불릴 때 담아 둔다. 이벤트 밖(문의code 찾기)에서
// 값을 넣을 때 psender.getCrud().setValue로 넣기 위해서다.
var amdSender = null;
// 저장하려는 레코드가 신규였는지 — afterCrudSave의 보고(isNew)에 쓴다.
var amdSavingNew = false;

// ══════════════════════════════════════════════════════════════════
// CRUD 이벤트 — crudbuttons가 부른다(psender = CrudButtons). false면 막음.
// ══════════════════════════════════════════════════════════════════

/** 저장 전 — 신규인지 기억해 둔다(저장 뒤에는 PK가 생겨 알 수 없다). */
function beforeCrudSave(psender, ppara) {
    amdSender = psender;
    if (psender.getPk() === null) {
        amdSavingNew = true;
    } else {
        amdSavingNew = false;
    }
    return true;
}

/** 저장 뒤 — 띄운 쪽에 'saved'로 알리고 제목을 맞춘다. */
function afterCrudSave(psender, ppara) {
    amdCaption(psender);
    psender.report('saved', {
        code: psender.getPk(),
        parentcode: amdParentCode(psender),
        isNew: amdSavingNew
    });
}

/** 삭제 뒤 — 띄운 쪽에 'deleted'로 알린다(폼은 crudbuttons가 곧 비운다). */
function afterCrudDelete(psender, ppara) {
    psender.report('deleted', {
        code: psender.getPk(),
        parentcode: amdParentCode(psender)
    });
}

/**
 * 신규 폼을 비운 뒤 — 같은 문의(parentcode)의 새 수주로. parentcode는 crudbuttons가
 * keep=parentcode로 남겨 준다. AMD_PARENT_CODE(주소의 parentcode, 연 레코드의 parentcode,
 * 또는 띄운 쪽이 action('newRecord', {parentcode})로 준 값)가 있으면 그 값으로 넣는다.
 * 기본값: 수주일자 = 오늘, 결제통화 = KRW(이 함수 안에서 넣으므로 "수정됨"이 되지 않는다).
 */
function afterCrudClear(psender, ppara) {
    amdSender = psender;
    if (amdTrim(AMD_PARENT_CODE) !== '') {
        amdSetField('parentcode', AMD_PARENT_CODE);
    }
    amdSetField('수주일자', amdToday());
    amdSetField('결제통화', AMD_DEFAULT_CURRENCY);
    amdLastDetailCode = '';
    amdFormatAllNumbers();
    amdMarkEmptyDates();
    amdEcountShow('', false);   // 다른 레코드 — 앞 품목의 이카운트 재고를 지운다
    amdCaption(psender);
}

/**
 * 레코드를 채운 뒤 — 콤보에 없는 값(상태, 결제통화)은 항목을 만들어 넣고 다시 고른다
 * (CrudJs가 그릴 때는 항목이 없어 빈 칸이 됐다). 숫자 칸은 소수 자리를 맞춘다.
 * 이 함수가 끝난 직후 crudbuttons가 지금 값을 기준값으로 기억하므로 여기서 바로 맞춘다.
 */
function afterCrudSelectRecord(psender, ppara) {
    var rows = psender.getCrud().getRecordSet();
    var row;

    amdSender = psender;
    if (rows.length > 0) {
        row = rows[0];
        amdEnsureOption('상태', row['상태']);
        amdSetField('상태', amdTrim(row['상태']));
        amdEnsureOption('결제통화', row['결제통화']);
        amdSetField('결제통화', amdTrim(row['결제통화']));
        amdLastDetailCode = amdTrim(row['문의code']);
        if (row['parentcode'] !== null && row['parentcode'] !== undefined && row['parentcode'] !== '') {
            AMD_PARENT_CODE = String(row['parentcode']);
        }
    }
    amdFormatAllNumbers();
    amdMarkEmptyDates();
    amdEcountShow('', false);   // 다른 레코드 — 앞 품목의 이카운트 재고를 지운다
    amdCaption(psender);
}

// ══════════════════════════════════════════════════════════════════
// action — 바깥(띄운 쪽)이 이 화면을 부르는 창구. 종류별 하위 함수로 나눠 넘기기만 한다.
//   action_buttons — requery / close / selectRecord / isDirty(버튼 iframe에 맡긴다)
//   action_new     — newRecord(띄운 쪽의 수주 탭 신규추가)
// ══════════════════════════════════════════════════════════════════
function action(pcmd, pparams, psender) {
    if (!pparams) {
        pparams = {};
    }

    switch (pcmd) {
        case 'requery':
        case 'close':
        case 'selectRecord':
        case 'isDirty':
            return action_buttons(pcmd, pparams, psender);

        case 'newRecord':
            return action_new(pcmd, pparams, psender);

        default:
            console.warn('수주.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

/**
 * 버튼 iframe(crudbuttons)의 action에 맡긴다. requery는 저장 안 한 값이 있으면
 * 묻지 않고 건너뛴다(바깥에서 다시 읽으라고 할 때 입력 중인 값이 날아가지 않게).
 */
function action_buttons(pcmd, pparams, psender) {
    var buttons = amdButtons();

    if (buttons === null) {
        return;
    }
    if (pcmd === 'requery' && buttons.action('isDirty') === true) {
        return;
    }
    return buttons.action(pcmd, pparams, window);
}

/**
 * newRecord {parentcode} — 띄운 쪽(문의확인)의 수주 탭 신규추가. 이 문의(parentcode)의 새
 * 수주로 폼을 비운다(버튼 iframe의 clear — 저장 안 한 값이 있으면 묻는다).
 * parentcode는 afterCrudClear가 AMD_PARENT_CODE로 넣는다.
 */
function action_new(pcmd, pparams, psender) {
    var buttons = amdButtons();

    if (buttons === null) {
        return;
    }
    if (amdTrim(pparams.parentcode) !== '') {
        AMD_PARENT_CODE = amdTrim(pparams.parentcode);
    }
    return buttons.action('clear', {}, window);
}

// ══════════════════════════════════════════════════════════════════
// 화면 도우미
// ══════════════════════════════════════════════════════════════════

/** 값을 앞뒤 공백 없는 글자로 — null/undefined면 ''. */
function amdTrim(pvalue) {
    if (pvalue === null || pvalue === undefined) {
        return '';
    }
    return String(pvalue).trim();
}

/** 오늘 날짜 'yyyy-mm-dd'. */
function amdToday() {
    var d = new Date();
    var m = String(d.getMonth() + 1);
    var day = String(d.getDate());

    if (m.length < 2) {
        m = '0' + m;
    }
    if (day.length < 2) {
        day = '0' + day;
    }
    return d.getFullYear() + '-' + m + '-' + day;
}

/** 버튼 iframe의 창 — 명령을 그쪽 action에 맡길 때 쓴다. 아직 로드 전이면 null. */
function amdButtons() {
    var frame = document.getElementById('cb-frame');

    if (frame === null || !frame.contentWindow) {
        return null;
    }
    if (typeof frame.contentWindow.action !== 'function') {
        return null;
    }
    return frame.contentWindow;
}

/** 버튼 상태(수정됨 표시 포함)를 다시 정하게 한다 — 스크립트로 값을 넣었을 때(입력 이벤트가 안 생긴다). */
function amdButtonsRefresh() {
    var buttons = amdButtons();

    if (buttons !== null) {
        buttons.action('refresh', {}, window);
    }
}

/**
 * 필드에 값을 넣는다 — crudbuttons(CrudJs)가 준비됐으면 그쪽으로. t수주내역에 없는
 * 필드(스키마에 없음)는 건너뛴다.
 */
function amdSetField(pfield, pvalue) {
    var crud;

    if (amdSender === null || amdSender.getCrud() === null) {
        return;
    }
    crud = amdSender.getCrud();
    if (!crud.schema.fields || !crud.schema.fields[pfield]) {
        return;
    }
    crud.setValue(pfield, pvalue);
}

/** 지금 레코드의 parentcode — 없으면 주소로 받은 값. */
function amdParentCode(psender) {
    var crud = psender.getCrud();
    var value = null;

    if (crud.schema.fields && crud.schema.fields['parentcode']) {
        value = crud.getValue('parentcode');
    }
    if (value === null || value === undefined || value === '') {
        return AMD_PARENT_CODE;
    }
    return String(value);
}

/** 제목 — "수주 (code)" / "수주 (신규)"(팝업이면 팝업 캡션). */
function amdCaption(psender) {
    var pk = psender.getPk();

    if (pk === null) {
        psender.setCaption('수주 (신규)');
    } else {
        psender.setCaption('수주 (' + pk + ')');
    }
}

/** 콤보(pid)에 값(pvalue)이 없으면 항목을 만들어 넣는다. 빈 값이면 아무것도 안 한다. */
function amdEnsureOption(pid, pvalue) {
    var el = document.getElementById(pid);
    var value = amdTrim(pvalue);
    var i;

    if (el === null || value === '') {
        return;
    }
    for (i = 0; i < el.options.length; i++) {
        if (el.options[i].value === value) {
            return;
        }
    }
    el.add(new Option(value, value));
}

function amdMessage(ptext, pisError) {
    var el = document.getElementById('amd-message');

    if (!ptext) {
        ptext = '';
    }
    el.textContent = ptext;
    if (pisError) {
        el.classList.add('amd-error');
    } else {
        el.classList.remove('amd-error');
    }
}

// ── 숫자 칸 ──
/** '54000' → '54,000', USD '100' → '100.00'. 숫자가 아니면 그대로. */
function amdFormatNumber(pname, pvalue) {
    var raw = amdTrim(pvalue).replace(/,/g, '');
    var digits = 0;

    if (raw === '' || isNaN(Number(raw))) {
        return raw;
    }
    if (AMD_NUMBER_DECIMALS[pname]) {
        digits = AMD_NUMBER_DECIMALS[pname];
    }
    return Number(raw).toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

/** 숫자 칸 전부의 소수 자리를 맞춘다(화면 글자만 — CrudJs는 읽을 때 콤마를 뗀다). */
function amdFormatAllNumbers() {
    var els = document.querySelectorAll('#amd-form .amd-num');
    var i;

    for (i = 0; i < els.length; i++) {
        els[i].value = amdFormatNumber(els[i].id, els[i].value);
    }
}

// ── 날짜 칸 ──
/**
 * 날짜 칸 하나 — 값이 비었으면 amd-date-empty를 붙여 "연도-월-일" 안내 글자를 숨긴다(CSS).
 * 입력하다 만 날짜(badInput — 값은 ''이지만 글자는 있음)는 숨기지 않는다.
 */
function amdMarkEmptyDate(pel) {
    if (pel.value === '' && !pel.validity.badInput) {
        pel.classList.add('amd-date-empty');
    } else {
        pel.classList.remove('amd-date-empty');
    }
}

/** 날짜 칸 전부 — 스크립트로 값을 넣으면 입력 이벤트가 안 생기므로 레코드를 채운 뒤·비운 뒤에 부른다. */
function amdMarkEmptyDates() {
    var els = document.querySelectorAll('#amd-form .amd-date');
    var i;

    for (i = 0; i < els.length; i++) {
        amdMarkEmptyDate(els[i]);
    }
}

/** 날짜 칸 전부에 이벤트를 단다 — 사용자가 값을 넣거나 지우면 표시를 다시 정한다. */
function amdBindDates() {
    var els = document.querySelectorAll('#amd-form .amd-date');
    var i;

    for (i = 0; i < els.length; i++) {
        els[i].addEventListener('input', amdDateChanged);
        els[i].addEventListener('change', amdDateChanged);
        els[i].addEventListener('blur', amdDateChanged);
    }
    amdMarkEmptyDates();
}

/** 날짜 칸 이벤트 — this = 그 칸. */
function amdDateChanged() {
    amdMarkEmptyDate(this);
}

/** 숫자 칸 하나 — 입력할 때는 콤마 없이, 칸을 떠나면 다시 콤마. */
function amdBindNumber(pel) {
    pel.addEventListener('focus', function () {
        pel.value = pel.value.replace(/,/g, '');
        pel.select();
    });
    pel.addEventListener('blur', function () {
        pel.value = amdFormatNumber(pel.id, pel.value);
    });
}

// ── 검색(문의내용code) ──
/**
 * 문의code 칸의 문의내역(t문의내역)을 찾아 제품종류/품목코드/모델명/모델명code/제조사를 채운다.
 * 같은 문의(AMD_PARENT_CODE)의 문의내역만 찾는다. 빈 칸이면 아무것도 안 한다.
 */
async function amdFindDetail() {
    var detailCode = amdTrim(document.getElementById('문의code').value);
    var res;
    var data;
    var name;
    var i;

    amdLastDetailCode = detailCode;
    if (detailCode === '') {
        return;
    }
    amdMessage('문의내역 찾는 중...', false);
    try {
        res = await fetch(AMD_FETCH_URL + '?action=inquiryDetail&code=' + encodeURIComponent(detailCode)
            + '&parentcode=' + encodeURIComponent(AMD_PARENT_CODE));
        data = await res.json();
        if (!data.success) {
            throw new Error(data.error);
        }
    } catch (err) {
        amdMessage('문의내역 찾기 실패: ' + err.message, true);
        return;
    }

    for (i = 0; i < AMD_DETAIL_MAP.length; i++) {
        name = AMD_DETAIL_MAP[i];
        // t문의내역에 없어 응답에 안 온 필드는 건드리지 않는다.
        if (!Object.prototype.hasOwnProperty.call(data.detail, name)) {
            continue;
        }
        amdSetField(name, amdTrim(data.detail[name]));
    }
    amdMessage('문의내역(' + detailCode + ')의 제품 정보를 넣었습니다.', false);
    amdButtonsRefresh();
}

/** 문의code 칸의 AfterUpdate(change) — 글자를 바꾸고 칸을 떠나면 찾는다. 방금 Enter로 찾은 값이면 건너뛴다. */
function amdDetailCodeChanged() {
    var detailCode = amdTrim(document.getElementById('문의code').value);

    if (detailCode === amdLastDetailCode) {
        return;
    }
    amdFindDetail();
}

/**
 * 문의보기 버튼 — 문의code의 문의내역 팝업을 띄운 쪽(문의확인)에 맡겨 연다
 * (action('selectRecord_문의내용', {code}) — 문의 탭 더블클릭과 같다).
 */
function amdOpenDetail() {
    var detailCode = amdTrim(document.getElementById('문의code').value);

    if (detailCode === '') {
        amdMessage('문의내용code가 없습니다.', true);
        return;
    }
    if (window.parent === window || typeof window.parent.action !== 'function') {
        amdMessage('문의확인 화면에서 열었을 때만 볼 수 있습니다.', true);
        return;
    }
    window.parent.action('selectRecord_문의내용', { code: detailCode }, window);
}

/**
 * 수식 버튼 — 수주 수식(tb수식_수주, inquiryContent_수식_entry.php?kind=수주) 목록을 띄운 쪽(문의확인)에 맡겨 연다
 * (action('openFormula', {kind: '수주'}) — amd 팝업 안이 아니라 문의확인 화면의 팝업).
 */
function amdOpenFormula() {
    if (window.parent === window || typeof window.parent.action !== 'function') {
        amdMessage('문의확인 화면에서 열었을 때만 볼 수 있습니다.', true);
        return;
    }
    window.parent.action('openFormula', { kind: '수주' }, window);
}

/**
 * calc() — 수식 적용(재계산 버튼, 빨간 레이블 칸의 값 변경이 부른다).
 *  1. 지금 폼 값 전부를 계산 창구(inquiryContent_calcFormula_fetch.php?kind=수주)에 보낸다.
 *  2. 서버가 tb수식_수주를 순서대로 계산해 {success, results: {필드명: 결과}}를 돌려준다.
 *  3. results의 값을 폼 칸에 넣는다(CrudJs setValue — 저장은 하지 않는다. 저장 버튼으로 저장).
 *     콤보 칸은 목록에 없는 값이면 항목을 만들어 넣는다. 이 폼에 없는 필드는 넣지 않고 안내 글에 이름을 보인다.
 * 실패하면 칸은 그대로 두고 "재계산 실패: 이유"를 보인다. 처리했으면 true.
 * calc가 겹치면(빨간 칸을 빨리 연달아 고침) 마지막 요청의 결과만 넣는다(amdCalcSeq).
 */
async function calc() {
    var crud;
    var names;
    var values = {};
    var res;
    var data;
    var resultNames;
    var applied = 0;
    var skipped = [];
    var name;
    var el;
    var seq;
    var i;

    if (amdSender === null || amdSender.getCrud() === null) {
        amdMessage('재계산 실패: 폼이 아직 준비되지 않았습니다.', true);
        return false;
    }
    amdCalcSeq = amdCalcSeq + 1;
    seq = amdCalcSeq;

    // 1. 폼 값 전부 — getValue는 화면 값을 먼저 흡수한다(숫자 칸의 콤마도 뗀다).
    crud = amdSender.getCrud();
    names = Object.keys(crud.schema.fields);
    for (i = 0; i < names.length; i++) {
        values[names[i]] = crud.getValue(names[i]);
    }

    // 2. 계산 의뢰.
    amdMessage('재계산 중...', false);
    try {
        res = await fetch(AMD_CALC_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ values: values })
        });
        data = await res.json();
        if (!data.success) {
            throw new Error(data.error);
        }
    } catch (err) {
        if (seq === amdCalcSeq) {
            amdMessage('재계산 실패: ' + err.message, true);
        }
        return false;
    }
    // 기다리는 사이 새 calc가 시작됐으면 이 결과는 버린다(새 calc가 최신 값으로 계산한다).
    if (seq !== amdCalcSeq) {
        return false;
    }

    // 3. 결과를 칸에 — 이 폼에 있는 필드만.
    resultNames = Object.keys(data.results);
    for (i = 0; i < resultNames.length; i++) {
        name = resultNames[i];
        if (crud.schema.fields[name]) {
            el = document.getElementById(name);
            if (el !== null && el.tagName === 'SELECT') {
                amdEnsureOption(name, data.results[name]);
            }
            amdSetField(name, data.results[name]);
            applied++;
        } else {
            skipped.push(name);
        }
    }
    amdFormatAllNumbers();
    amdMarkEmptyDates();
    // 스크립트로 넣은 값은 입력 이벤트가 안 생긴다 — 저장 버튼의 "수정됨" 표시를 다시 정하게 한다.
    amdButtonsRefresh();

    if (skipped.length > 0) {
        amdMessage('재계산했습니다(' + applied + '개 칸). 폼에 없는 필드는 넣지 않았습니다: ' + skipped.join(', '), false);
    } else {
        amdMessage('재계산했습니다(' + applied + '개 칸).', false);
    }
    return true;
}

/**
 * 빨간 레이블의 칸(레이블 class amd-red의 for)은 사용자가 값을 바꾸고 칸을 떠나면
 * (change — Access AfterUpdate) calc()를 부른다.
 * 스크립트로 넣은 값(calc 결과, 레코드 열기)은 change가 생기지 않으므로 calc가 되풀이되지 않는다.
 * 빨간 레이블을 더하거나 빼면 화면의 필드 목록(색)만 고치면 된다(여기서 레이블을 찾는다).
 */
function amdBindCalcTriggers() {
    var labels = document.querySelectorAll('#amd-form label.amd-red');
    var el;
    var i;

    for (i = 0; i < labels.length; i++) {
        el = document.getElementById(labels[i].htmlFor);
        if (el !== null && !el.disabled) {
            el.addEventListener('change', calc);
        }
    }
}

/**
 * ECount 버튼 — 폼의 품목코드로 이카운트 재고를 조회해 버튼 옆에 빨간 글로 "제품코드 : 수량"을 보인다
 * 창구: inquiryContent_ECount_fetch.php(ECount::inventoryOne, 시험 서버/실제 서버는 ECount::TEST_SERVER).
 * 실패하면 이유를 빨간 글로 보인다. 레코드를 열거나 비우면 지운다(다른 품목의 재고가 남아 보이지 않게).
 */
async function amdEcountStock() {
    var code = amdTrim(document.getElementById('품목코드').value);
    var res;
    var data;
    var qty;

    if (code === '') {
        amdEcountShow('품목코드가 없습니다.', true);
        return;
    }
    amdEcountShow('조회 중...', false);
    try {
        res = await fetch(AMD_ECOUNT_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ productCode: code })
        });
        data = await res.json();
        if (!data.success) {
            throw new Error(data.error);
        }
    } catch (err) {
        amdEcountShow('ECount 실패: ' + err.message, true);
        return;
    }
    if (data.result === null) {
        amdEcountShow('이카운트에 없는 품목입니다: ' + code, true);
        return;
    }
    qty = Number(String(data.result['수량']).replace(/,/g, ''));
    if (isNaN(qty)) {
        qty = data.result['수량'];
    } else {
        qty = qty.toLocaleString('en-US', { maximumFractionDigits: 0 });
    }
    amdEcountShow(data.result['품목코드'] + ' : ' + qty, false);
}

/** ECount 결과 글 — pisError면 빨간 글. 빈 글이면 지운다. */
function amdEcountShow(ptext, pisError) {
    var el = document.getElementById('amd-ecount-result');

    if (el === null) {
        return;
    }
    el.textContent = ptext;
    if (pisError) {
        el.classList.add('amd-error');
    } else {
        el.classList.remove('amd-error');
    }
}

// ── 시작 — 이벤트 연결 ──
function amdInit() {
    var nums = document.querySelectorAll('#amd-form .amd-num');
    var soon = [];
    var i;

    for (i = 0; i < nums.length; i++) {
        amdBindNumber(nums[i]);
    }
    amdBindDates();

    // 검색(문의내용code) — Enter를 누르거나, 값을 바꾸고 칸을 떠나면(change) 제품 칸을 채운다.
    document.getElementById('문의code').addEventListener('keydown', function (pevent) {
        if (pevent.key === 'Enter') {
            pevent.preventDefault();
            amdFindDetail();
        }
    });
    document.getElementById('문의code').addEventListener('change', amdDetailCodeChanged);
    document.getElementById('amd-detail-open').addEventListener('click', amdOpenDetail);
    document.getElementById('amd-formula').addEventListener('click', amdOpenFormula);

    // 준비 중인 버튼 — 누르면 안내만(지금은 없음).
    // 재계산 버튼 = calc()(수식 적용), 빨간 레이블 칸을 바꾸면 calc().
    document.getElementById('amd-recalc').addEventListener('click', calc);
    amdBindCalcTriggers();

    // ECount 버튼 = 품목코드의 이카운트 재고를 버튼 옆에.
    document.getElementById('amd-ecount').addEventListener('click', amdEcountStock);

    for (i = 0; i < soon.length; i++) {
        amdBindSoon(document.getElementById(soon[i]));
    }
}

/** 준비 중인 버튼 하나 — 누르면 안내만 한다. */
function amdBindSoon(pbutton) {
    pbutton.addEventListener('click', function () {
        amdMessage(pbutton.textContent.trim() + ' — 준비 중입니다.', false);
    });
}

amdInit();
</script>
<?php endif; ?>
</body>
</html>
