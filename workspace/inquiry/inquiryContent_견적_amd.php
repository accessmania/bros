<?php
declare(strict_types=1);

/**
 * [견적 팝업 — t문의_견적산출 한 건의 상세]
 * 구성: inquiryContent_견적_amd.php(이 파일 — 입력폼 amd) + inquiryContent_견적_amd_fetch.php
 * (CrudJs 계약). 견적 팝업(inquiryContent_견적_amd.php)과 같은 틀이다. Popup.js의 iframeUrl로 띄운다:
 *
 *   new Popup({
 *       title: '견적', iframeUrl: '.../inquiryContent_견적_amd.php?code=1234',
 *       iframeWidth: 600, iframeHeight: 860
 *   });
 *
 * 실제로는 문의확인(inquiryContent_entry.php)이 견적 탭 더블클릭/신규추가 때 비모달로 화면
 * 오른쪽에 띄운다(icOpenSub / icOpenSubNew, 팝업 id 'ic-quote'). 한 번 뜨면 다시 열지 않고,
 * 다른 행은 action('selectRecord', {pk}), 신규는 action('newRecord', {parentcode})로 보여준다.
 * 저장/삭제 보고를 받으면 문의확인이 requery한다.
 *
 * 주소: ?code=<t문의_견적산출.code> — 기존 행. ?code=new&parentcode=<t문의.code> — 신규.
 *
 * 입력폼은 crudbuttons 모듈을 쓴다(src/Model/Crud/crudbuttons/crudbuttons.md):
 *  - 컨트롤 id = t문의_견적산출 필드명. 이 페이지는 필드 배치와 이벤트만 정의한다.
 *  - 띄운 쪽에 보고: 'saved' {code, parentcode, isNew} / 'deleted' {code, parentcode} / 'closed'
 *  - 신규입력: 같은 문의(parentcode)의 새 견적으로 폼을 비운다(keep=parentcode + afterCrudClear).
 *    기본값: 견적날짜 = 오늘.
 *  - 필수입력: 모델명.
 *
 * 배치(위에서 아래로) — 그림이 없어 견적 팝업 배치를 따랐다:
 *  - 검색(문의code) + 문의보기 + 재계산/수식 — 문의code(t문의내역.code)로 Enter/AfterUpdate면
 *    제품종류/품목코드/모델명/제품code/제조사를 채운다(AMD_DETAIL_MAP). 문의보기는 문의내역 팝업.
 *  - 제품종류/품목코드, 모델명, 제조사, ECount 버튼
 *  - 왼쪽(물량·운송): 상태/견적날짜/수량/MOQ/중량/총중량/실중량KG/부피중량/Zone/중량코드/
 *          화물배율/KG배율/운임비율/유류할증요율/은행수수료/관세/승수
 *    오른쪽(가격): StandardPrice/DistiPrice/할인률/적용환율/견적환율/원화구매가/원화원가/달러원가/
 *          DHL단가/개별운송비/중량단가/중량화물비용/예상단가/예상USD/견적단가/USD단가
 *  - 비고
 *  - crudbuttons(저장/삭제/신규입력/창닫기)
 *
 * 재계산/수식/ECount는 다음 단계. t문의_견적산출에 없는 필드의 칸은 비활성으로 두고 저장하지 않는다.
 *
 * 페이지 함수: action(pcmd, pparams, psender) — 종류별 action_xxx로 나눠 넘긴다.
 *  - 'requery' — 다시 읽는다(저장 안 한 값이 있으면 건너뛴다).
 *  - 'close' / 'selectRecord' {pk} / 'isDirty' — 버튼 iframe에 맡긴다.
 *  - 'newRecord' {parentcode} — 그 문의의 새 견적으로 폼을 비운다(견적 탭 신규추가).
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
$crud->init('t문의_견적산출');
// t문의_견적산출에 실제로 있는 필드 — 없는 필드의 칸은 비활성으로 둔다.
$schemaFields = $crud->getSchema()['fields'];

// 레코드는 존재 확인과 parentcode에만 쓴다. 입력칸 값은 crudbuttons(CrudJs)가 채운다.
$record = null;
$loadError = '';
if ($isNew) {
    $record = [];
    if ($parentCode === '' || !ctype_digit($parentCode)) {
        $loadError = '신규 견적은 parentcode(문의 code)가 필요합니다.';
    }
} elseif ($code === '' || !ctype_digit($code)) {
    $loadError = '견적 code가 없습니다.';
} elseif ($crud->selectRecord($code)) {
    $record = $crud->getAllValues();
    $parentCode = (string) ($record['parentcode'] ?? $parentCode);
} else {
    $loadError = '견적(' . $code . ')을 찾을 수 없습니다.';
}

/** 콤보 원본 — t문의_견적산출에 입력돼 있는 그 필드의 값들(맨 앞 빈 칸, $defaults는 늘 포함). */
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
        $stmt = $db->pdo()->query('SELECT DISTINCT ' . $col . ' FROM ' . $db->dialect()->quoteIdentifier('t문의_견적산출')
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
// 견적에는 통화 콤보가 없다.
$currencyOptions = [''];

/** t문의_견적산출에 없는 필드면 disabled 속성(+ 안내 title). */
function amd_disabled(array $schemaFields, string $field): string
{
    return isset($schemaFields[$field]) ? '' : ' disabled title="t문의_견적산출에 ' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . ' 필드가 없습니다"';
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
// null이면 그 자리는 빈 칸.
$leftFields = [
    ['상태', '상태', '', 'status'],
    ['견적날짜', '견적날짜', '', 'date'],
    ['수량', '수량', 'red', 'num'],
    ['MOQ', 'MOQ', '', 'num'],
    ['중량', '중량', '', 'num'],
    ['총중량', '총중량', '', 'num'],
    ['실중량KG', '실중량KG', '', 'num'],
    ['부피중량', '부피중량', '', 'num'],
    ['Zone', 'Zone', '', 'text'],
    ['중량코드', '중량코드', '', 'text'],
    ['화물배율', '화물배율', '', 'num'],
    ['KG배율', 'KG배율', '', 'num'],
    ['운임비율', '운임비율', '', 'num'],
    ['유류할증요율', '유류할증요율', '', 'num'],
    ['은행수수료', '은행수수료', '', 'num'],
    ['관세', '관세', '', 'num'],
    ['승수', '승수', '', 'num'],
];
$rightFields = [
    ['StandardPrice', 'StandardPrice', 'red', 'num'],
    ['DistiPrice', 'DistiPrice', 'red', 'num'],
    ['할인률', '할인률', '', 'num'],
    ['적용환율', '적용환율', '', 'num'],
    ['견적환율', '견적환율', '', 'num'],
    ['원화구매가', '원화구매가', '', 'num'],
    ['원화원가', '원화원가', '', 'num'],
    ['달러원가', '달러원가', '', 'num'],
    ['DHL단가', 'DHL단가', '', 'num'],
    ['개별운송비', '개별운송비', '', 'num'],
    ['중량단가', '중량단가', '', 'num'],
    ['중량화물비용', '중량화물비용', '', 'num'],
    ['예상단가', '예상단가', '', 'num'],
    ['예상USD', '예상USD', '', 'num'],
    ['견적단가', '견적단가', 'blue', 'num'],
    ['USD단가', 'USD단가', 'blue', 'num'],
    null,
];

/** 두 줄 칸의 라벨 하나 — null이면 빈 칸. */
function amd_pair_label(?array $item): string
{
    if ($item === null) {
        return '<span></span>';
    }
    return amd_label($item[0], $item[1], $item[2]);
}

/** 두 줄 칸의 컨트롤 하나 — 종류에 맞게. null이면 빈 칸. */
function amd_pair_control(array $schemaFields, ?array $item, array $statusOptions, array $currencyOptions): string
{
    if ($item === null) {
        return '<span></span>';
    }
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
// keep=parentcode: 신규입력(clear)·삭제 뒤에도 parentcode(이 견적이 딸린 t문의.code)는
// 비우지 않고 남긴다 — 같은 문의의 새 견적을 이어서 입력하게.
// (PK code는 남기지 않는다 — 남으면 신규가 아니라 수정이 된다.)
$fetchUrl = APP_BASE_URL . '/workspace/inquiry/' . rawurlencode('inquiryContent_견적_amd_fetch.php');
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
<title>견적</title>
<style>
html, body { margin: 0; height: 100%; }
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
.amd-form select { text-align: center; }
.amd-wide { grid-column: 2 / 5; }
.amd-gap { grid-column: 1 / 5; height: 6px; }
.amd-red { color: #dc2626; }
.amd-blue { color: #2563eb; }

/* 검색(문의code) 줄 — 문의code + 문의보기, 오른쪽 재계산/수식 버튼. */
.amd-search-label { font-weight: 600; text-decoration: underline; }
.amd-code-row { grid-column: 2 / 5; display: flex; align-items: center; gap: 6px; }
.amd-code-row input { width: 120px; }
.amd-code-row .amd-spacer { flex: 1; }

button {
    font: inherit; height: 25px; padding: 0 10px; cursor: pointer; white-space: nowrap;
    border: 1px solid #a3a3a3; background: #e5e5e5; color: #000;
}
button:hover:not(:disabled) { background: #d4d4d4; }
button:disabled { color: #9ca3af; cursor: default; }
.amd-ecount { grid-column: 1 / 3; justify-self: start; }
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
    <!-- 검색(문의code) — 이 견적이 나온 문의내역(t문의내역.code). Enter면 제품 칸을 채운다. -->
    <label for="문의code" class="amd-search-label">검색(문의code)</label>
    <div class="amd-code-row">
        <input type="text" id="문의code" title="문의내역 code를 넣고 Enter — 제품 칸을 채웁니다"<?= amd_disabled($schemaFields, '문의code') ?>>
        <button type="button" id="amd-detail-open">&#128269; 문의보기</button>
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

    <button type="button" class="amd-ecount" id="amd-ecount"><b>!</b> ECount</button>

    <div class="amd-gap"></div>

<?php for ($i = 0; $i < count($leftFields); $i++): ?>
    <?= amd_pair_label($leftFields[$i]) ?>
    <?= amd_pair_control($schemaFields, $leftFields[$i], $statusOptions, $currencyOptions) ?>
    <?= amd_pair_label($rightFields[$i]) ?>
    <?= amd_pair_control($schemaFields, $rightFields[$i], $statusOptions, $currencyOptions) ?>

<?php endfor; ?>
    <div class="amd-gap"></div>

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

// 이 견적이 딸린 문의(t문의.code) — 신규로 비울 때 parentcode로 넣는다.
var AMD_PARENT_CODE = <?= json_encode($parentCode) ?>;
var AMD_FETCH_URL = <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
// 숫자 칸의 소수 자리 — 수량/MOQ는 정수. 나머지(계산 값)는 있는 자리 그대로 천단위 콤마만(반올림하지 않는다).
var AMD_NUMBER_DECIMALS = { '수량': 0, 'MOQ': 0 };
// "검색(문의code)"로 가져온 문의내역의 제품 정보를 넣는 칸 — fetch가 t문의_견적산출 필드 이름으로 보낸다.
// (t문의내역.제품code → 제품code. inquiryContent_견적_amd_fetch.php의 DETAIL_MAP과 같아야 한다.)
var AMD_DETAIL_MAP = ['제품종류', '품목코드', '모델명', '제품code', '제조사'];
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
 * 신규 폼을 비운 뒤 — 같은 문의(parentcode)의 새 견적으로. parentcode는 crudbuttons가
 * keep=parentcode로 남겨 준다. AMD_PARENT_CODE(주소의 parentcode, 연 레코드의 parentcode,
 * 또는 띄운 쪽이 action('newRecord', {parentcode})로 준 값)가 있으면 그 값으로 넣는다.
 * 기본값: 견적날짜 = 오늘(이 함수 안에서 넣으므로 "수정됨"이 되지 않는다).
 */
function afterCrudClear(psender, ppara) {
    amdSender = psender;
    if (amdTrim(AMD_PARENT_CODE) !== '') {
        amdSetField('parentcode', AMD_PARENT_CODE);
    }
    amdSetField('견적날짜', amdToday());
    amdLastDetailCode = '';
    amdFormatAllNumbers();
    amdCaption(psender);
}

/**
 * 레코드를 채운 뒤 — 콤보에 없는 값(상태)은 항목을 만들어 넣고 다시 고른다
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
        amdLastDetailCode = amdTrim(row['문의code']);
        if (row['parentcode'] !== null && row['parentcode'] !== undefined && row['parentcode'] !== '') {
            AMD_PARENT_CODE = String(row['parentcode']);
        }
    }
    amdFormatAllNumbers();
    amdCaption(psender);
}

// ══════════════════════════════════════════════════════════════════
// action — 바깥(띄운 쪽)이 이 화면을 부르는 창구. 종류별 하위 함수로 나눠 넘기기만 한다.
//   action_buttons — requery / close / selectRecord / isDirty(버튼 iframe에 맡긴다)
//   action_new     — newRecord(띄운 쪽의 견적 탭 신규추가)
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
            console.warn('견적.action: 알 수 없는 pcmd - ' + pcmd);
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
 * newRecord {parentcode} — 띄운 쪽(문의확인)의 견적 탭 신규추가. 이 문의(parentcode)의 새
 * 견적으로 폼을 비운다(버튼 iframe의 clear — 저장 안 한 값이 있으면 묻는다).
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
 * 필드에 값을 넣는다 — crudbuttons(CrudJs)가 준비됐으면 그쪽으로. t문의_견적산출에 없는
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

/** 제목 — "견적 (code)" / "견적 (신규)"(팝업이면 팝업 캡션). */
function amdCaption(psender) {
    var pk = psender.getPk();

    if (pk === null) {
        psender.setCaption('견적 (신규)');
    } else {
        psender.setCaption('견적 (' + pk + ')');
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
/** '54000' → '54,000', 소수 2자리 칸 '100' → '100.00'. 숫자가 아니면 그대로. */
function amdFormatNumber(pname, pvalue) {
    var raw = amdTrim(pvalue).replace(/,/g, '');
    var digits;

    if (raw === '' || isNaN(Number(raw))) {
        return raw;
    }
    // 목록에 없는 칸은 있는 소수 자리 그대로(최대 6자리) — 반올림해서 값이 바뀌지 않게.
    if (!Object.prototype.hasOwnProperty.call(AMD_NUMBER_DECIMALS, pname)) {
        return Number(raw).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
    }
    digits = AMD_NUMBER_DECIMALS[pname];
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

// ── 검색(문의code) ──
/**
 * 문의code 칸의 문의내역(t문의내역)을 찾아 제품종류/품목코드/모델명/제품code/제조사를 채운다.
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
        amdMessage('문의code가 없습니다.', true);
        return;
    }
    if (window.parent === window || typeof window.parent.action !== 'function') {
        amdMessage('문의확인 화면에서 열었을 때만 볼 수 있습니다.', true);
        return;
    }
    window.parent.action('selectRecord_문의내용', { code: detailCode }, window);
}

// ── 시작 — 이벤트 연결 ──
function amdInit() {
    var nums = document.querySelectorAll('#amd-form .amd-num');
    var soon = ['amd-recalc', 'amd-formula', 'amd-ecount'];
    var i;

    for (i = 0; i < nums.length; i++) {
        amdBindNumber(nums[i]);
    }

    // 검색(문의code) — Enter를 누르거나, 값을 바꾸고 칸을 떠나면(change) 제품 칸을 채운다.
    document.getElementById('문의code').addEventListener('keydown', function (pevent) {
        if (pevent.key === 'Enter') {
            pevent.preventDefault();
            amdFindDetail();
        }
    });
    document.getElementById('문의code').addEventListener('change', amdDetailCodeChanged);
    document.getElementById('amd-detail-open').addEventListener('click', amdOpenDetail);

    // 재계산/수식/ECount — 다음 단계(지금은 안내만).
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
