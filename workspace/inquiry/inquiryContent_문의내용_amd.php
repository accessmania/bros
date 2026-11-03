<?php
declare(strict_types=1);

/**
 * [문의내역 팝업 — t문의내역 한 건의 상세(바탕화면 문의내용팝업.png)]
 * 구성: inquiryContent_문의내용_amd.php(이 파일 — 입력폼 amd) + inquiryContent_문의내용_amd_fetch.php
 * (CrudJs 계약). Popup.js의 iframeUrl로 띄우는 화면이다:
 *
 *   new Popup({
 *       title: '문의내역', iframeUrl: '.../inquiryContent_문의내용_amd.php?code=16275',
 *       iframeWidth: 600, iframeHeight: 660
 *   });
 *
 * 실제로는 문의확인(inquiryContent_entry.php)이 문의 탭 더블클릭 때 비모달로 화면 오른쪽에
 * 띄운다(icOpenDetail). 한 번 뜨면 다시 열지 않고, 다른 행은 action('selectRecord', {pk})로
 * 보여준다(crudbuttons/CrudJs는 처음 한 번만 초기화). 저장/삭제 보고를 받으면 문의확인이 requery한다.
 *
 * 주소: ?code=<t문의내역.code> — 기존 행. ?code=new&parentcode=<t문의.code> — 신규.
 * 주소창에 바로 열어도 동작한다(창닫기는 그 창을 닫는다).
 *
 * 입력폼은 crudbuttons 모듈을 쓴다(src/Model/Crud/crudbuttons/crudbuttons.md):
 *  - 컨트롤 id = t문의내역 필드명. 값 채우기/모으기/저장/삭제/신규입력/창닫기는 아래
 *    crudbuttons iframe 안의 CrudJs가 한다. 이 페이지는 필드 배치와 이벤트만 정의한다.
 *  - 띄운 쪽에 보고: psender.report → 띄운 쪽의 action('amdReport', {ptype, payload, popupId})
 *      'saved'   {code, parentcode, isNew}
 *      'deleted' {code, parentcode}
 *      'closed'  (crudbuttons가 보낸다)
 *  - 신규입력: 같은 문의(parentcode)의 새 문의내역으로 폼을 비운다 — parentcode는 crudbuttons의
 *    keep=parentcode로 비우지 않고 남긴다(afterCrudClear는 처음 신규로 열었을 때만 채운다).
 *    "문의code" 칸은 이 문의내역의 PK(code)를 보여주는 읽기 전용 칸이다.
 *  - 필수입력: 모델명, 품목코드(crudbuttons의 required=) — 비어 있으면 저장하지 않는다.
 *  - 삭제하면 crudbuttons가 폼을 비운다(같은 문의의 신규 상태가 된다).
 *
 * 배치(위에서 아래로):
 *  - 문의code(읽기전용) + 재계산/수식 버튼
 *  - 찾기 버튼(돋보기) → 모델 검색 팝업(tb제품, inquiryContent_모델검색_entry.php — 고객사 검색과
 *    같은 틀). 행을 더블클릭하면 action('setModel', {row})로 모델명/품목코드/제조사가 들어오고,
 *    tb제품.종류 → 제품종류, tb제품.code → 제품code로 들어간다(AMD_PRODUCT_MAP). 제품code는 화면에 칸이 없다.
 *  - 검색(t문의내역.검색 필드 — 저장된다) + 모델명 콤보 — 화면이 뜰 때(기존·신규 모두) 콤보에 원본 목록을
 *    채운다(검색 글자가 있으면 그 글자로, 없으면 전체). 검색 칸 Enter/AfterUpdate면 검색 글자로 모델 목록을 찾아 콤보를 채우고,
 *    모델을 고르면 제품종류/품목코드/제조사가 함께 들어온다. ECount 버튼.
 *  - 상태/수량, 구매원가·USD구매원가, 판매단가·USD판매단가, 마진율·USD마진율
 *  - 구매메모/판매메모
 *  - 비고(여러 줄 — 남는 높이를 crudbuttons 바로 위까지 채운다)
 *  - crudbuttons(저장/삭제/신규입력/창닫기)
 *
 * 재계산/수식/ECount는 다음 단계. t문의내역에 없는 필드의 칸은 비활성으로 두고 저장하지 않는다.
 *
 * 페이지 함수: action(pcmd, pparams, psender) — 종류별 action_xxx로 나눠 넘긴다.
 *  - 'requery' — 다시 읽는다(저장 안 한 값이 있으면 건너뛴다).
 *  - 'close' / 'selectRecord' {pk} / 'isDirty' — 버튼 iframe에 맡긴다.
 *  - 'newRecord' {parentcode} — 그 문의의 새 문의내역으로 폼을 비운다(문의 탭 신규추가).
 *  - 'setModel' {row} / 'closeModelSearch' — 모델 검색 팝업이 부른다(고른 tb제품 행 넣기 / 팝업 닫기).
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
$crud->init('t문의내역');
// t문의내역에 실제로 있는 필드 — 없는 필드의 칸은 비활성으로 둔다.
$schemaFields = $crud->getSchema()['fields'];

// 레코드는 존재 확인과 콤보 원본(지금 값이 목록에 없을 때 넣어 둘 항목)에만 쓴다.
// 입력칸 값은 crudbuttons(CrudJs)가 채운다.
$record = null;
$loadError = '';
if ($isNew) {
    $record = [];
    if ($parentCode === '' || !ctype_digit($parentCode)) {
        $loadError = '신규 문의내역은 parentcode(문의 code)가 필요합니다.';
    }
} elseif ($code === '' || !ctype_digit($code)) {
    $loadError = '문의내역 code가 없습니다.';
} elseif ($crud->selectRecord($code)) {
    $record = $crud->getAllValues();
    $parentCode = (string) ($record['parentcode'] ?? $parentCode);
} else {
    $loadError = '문의내역(' . $code . ')을 찾을 수 없습니다.';
}

// 상태 콤보 원본 — t문의내역에 입력돼 있는 상태 값들(빈 칸 포함).
$statusOptions = [''];
try {
    $statusStmt = $db->pdo()->query('SELECT DISTINCT 상태 FROM t문의내역 WHERE 상태 IS NOT NULL ORDER BY 상태 ASC');
    foreach ($statusStmt->fetchAll(PDO::FETCH_COLUMN) as $status) {
        $status = trim((string) $status);
        if ($status !== '' && !in_array($status, $statusOptions, true)) {
            $statusOptions[] = $status;
        }
    }
} catch (Throwable $e) {
    // 콤보만 비고 화면은 뜬다.
}

/** t문의내역에 없는 필드면 disabled 속성(+ 안내 title). */
function amd_disabled(array $schemaFields, string $field): string
{
    return isset($schemaFields[$field]) ? '' : ' disabled title="t문의내역에 ' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . ' 필드가 없습니다"';
}

/** 글자 칸 하나 — id = 필드명. */
function amd_text_input(array $schemaFields, string $field, string $class = ''): string
{
    $classAttr = $class === '' ? '' : ' class="' . $class . '"';
    return '<input type="text" id="' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . '"' . $classAttr
        . amd_disabled($schemaFields, $field) . '>';
}

/** 숫자 칸 하나 — 오른쪽 정렬, 소수 자리 맞추기는 JS가 한다(amdFormatNumber). */
function amd_number_input(array $schemaFields, string $field): string
{
    return amd_text_input($schemaFields, $field, 'amd-num');
}

// crudbuttons iframe — fetch(CrudJs 계약)와 처음 열 PK(신규면 빈 값 → 빈 폼).
// keep=parentcode: 신규입력(clear)·삭제 뒤에도 parentcode(이 문의내역이 딸린 t문의.code)는
// 비우지 않고 남긴다 — 같은 문의의 새 문의내역을 이어서 입력하게.
// (PK code는 남기지 않는다 — 남으면 신규가 아니라 수정이 된다.)
$fetchUrl = APP_BASE_URL . '/workspace/inquiry/' . rawurlencode('inquiryContent_문의내용_amd_fetch.php');
$pk = $isNew ? '' : $code;
// required=모델명,품목코드: 필수입력 — 비어 있으면 저장하지 않는다.
// closeconfirm=0: 창닫기 때 저장 안 한 값이 있어도 묻지 않고 닫는다.
$buttonsSrc = APP_BASE_URL . '/src/Model/Crud/crudbuttons/crudbuttons.php?fetch=' . rawurlencode($fetchUrl)
    . '&pk=' . rawurlencode($pk) . '&keep=' . rawurlencode('parentcode')
    . '&required=' . rawurlencode('모델명,품목코드') . '&closeconfirm=0';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>문의내역</title>
<style>
html, body { margin: 0; height: 100%; }
body {
    display: flex; flex-direction: column;
    padding: 18px 40px 6px; box-sizing: border-box; background: #ececec; color: #000;
    font-family: 'Malgun Gothic', 'Segoe UI', system-ui, sans-serif; font-size: 13px;
}
.amd-form { display: grid; grid-template-columns: 80px 1fr 90px 1fr; gap: 8px 10px; align-items: center; }
.amd-form label { white-space: nowrap; }
.amd-form input, .amd-form select {
    font: inherit; width: 100%; box-sizing: border-box; height: 25px; padding: 0 6px;
    border: 1px solid #a3a3a3; background: #fff; color: #000;
}
.amd-form input[readonly] { background: #e5e5e5; color: #6b7280; }
.amd-form input:disabled, .amd-form select:disabled { background: #e5e7eb; cursor: not-allowed; }
.amd-form input:focus, .amd-form select:focus { outline: none; border-color: #3b82f6; }
.amd-form .amd-num { text-align: right; }
.amd-wide { grid-column: 2 / 5; }
.amd-sep { grid-column: 1 / 5; border: 0; border-top: 1px solid #9ca3af; margin: 10px 0; }
.amd-red { color: #dc2626; }
.amd-blue { color: #2563eb; }

/* 문의code 줄 — 왼쪽 code, 오른쪽 재계산/수식 버튼. */
.amd-code-row { grid-column: 2 / 5; display: flex; align-items: center; gap: 8px; }
.amd-code-row input { width: 100px; }
.amd-code-row .amd-spacer { flex: 1; }

/* 모델명 줄 — 검색필드(좁게) + 모델명 콤보 + 찾기 버튼. */
.amd-model-row { grid-column: 2 / 5; display: flex; gap: 2px; }
.amd-model-row #검색 { width: 87px; flex: none; }
.amd-model-row select { flex: 1; min-width: 0; }

button {
    font: inherit; height: 25px; padding: 0 10px; cursor: pointer; white-space: nowrap;
    border: 1px solid #a3a3a3; background: #e5e5e5; color: #000;
}
button:hover:not(:disabled) { background: #d4d4d4; }
button:disabled { color: #9ca3af; cursor: default; }
.amd-icon-btn { width: 25px; padding: 0; flex: none; }
.amd-ecount { grid-column: 1 / 3; justify-self: start; }
.amd-ecount b { color: #dc2626; }

/* 비고 — 폼과 같은 칸 배치(라벨 80px + 값), 남는 높이를 다 차지한다(body가 세로 flex). */
.amd-memo-row { flex: 1; min-height: 60px; display: grid; grid-template-columns: 80px 1fr; gap: 10px; margin-top: 8px; }
.amd-memo-row label { white-space: nowrap; padding-top: 4px; }
.amd-memo-row textarea {
    font: inherit; width: 100%; height: 100%; box-sizing: border-box; padding: 4px 6px; resize: none;
    border: 1px solid #a3a3a3; background: #fff; color: #000;
}
.amd-memo-row textarea:focus { outline: none; border-color: #3b82f6; }
.amd-memo-row textarea:disabled { background: #e5e7eb; cursor: not-allowed; }

/* 모델 찾기/준비 중 안내(저장·삭제 결과는 crudbuttons가 버튼 옆에 보인다). */
.amd-message { min-height: 18px; margin-top: 8px; color: #374151; }
.amd-message.amd-error { color: #dc2626; }

/* 모델 검색 팝업(모달) — 고객사 검색과 같은 틀. 화면 위에 반투명 막을 깔고 가운데 창을 띄운다.
   창 안은 iframe(inquiryContent_모델검색_entry.php). */
.amd-modal { position: fixed; inset: 0; z-index: 100; display: flex; align-items: center; justify-content: center; background: rgba(15, 23, 42, 0.35); }
.amd-modal[hidden] { display: none; }
.amd-modal-window {
    display: flex; flex-direction: column; width: 96vw; height: 92vh;
    background: #fff; border: 1px solid #6b7280; border-radius: 4px;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
}
.amd-modal-title {
    display: flex; align-items: center; justify-content: space-between; height: 32px; padding: 0 6px 0 12px;
    border-bottom: 1px solid #d1d5db; font-size: 13px; color: #000; user-select: none;
}
.amd-modal-close { width: 28px; height: 24px; padding: 0; border: 0; background: transparent; font-size: 18px; line-height: 1; cursor: pointer; color: #374151; }
.amd-modal-close:hover:not(:disabled) { background: #ef4444; color: #fff; }
.amd-modal-window iframe { flex: 1; width: 100%; border: 0; display: block; }

/* crudbuttons iframe(저장/삭제/신규입력/창닫기) — 폼 아래 바닥에, 배경이 비치게. */
.cb-frame { display: block; flex: none; width: 100%; height: 44px; border: 0; background: transparent; }
</style>
</head>
<body>
<?php if ($loadError !== ''): ?>
<p class="amd-message amd-error"><?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></p>
<?php else: ?>
<form class="amd-form" id="amd-form" autocomplete="off" onsubmit="return false;">
    <!-- 문의code — 이 문의내역의 PK(t문의내역.code). 읽기 전용, 신규면 빈 칸. -->
    <label for="code">문의code</label>
    <div class="amd-code-row">
        <input type="text" id="code" placeholder="(신규)" readonly>
        <span class="amd-spacer"></span>
        <button type="button" id="amd-recalc">&#9998; 재계산</button>
        <button type="button" id="amd-formula">&#128269; 수식</button>
    </div>

    <label for="모델명">모델명</label>
    <div class="amd-model-row">
        <!-- 검색 — t문의내역.검색 필드(저장된다). 이 글자로 모델 목록을 찾는다(Enter / 찾기 버튼). -->
        <input type="text" id="검색" placeholder="검색" title="모델명에 들어간 글자로 찾습니다(Enter)">
        <select id="모델명"<?= amd_disabled($schemaFields, '모델명') ?>></select>
        <button type="button" class="amd-icon-btn" id="amd-model-find" title="모델 찾기">&#128269;</button>
    </div>

    <label for="제품종류">제품종류</label>
    <?= amd_text_input($schemaFields, '제품종류') ?>

    <label for="품목코드">품목코드</label>
    <?= amd_text_input($schemaFields, '품목코드') ?>

    <label for="제조사">제조사</label>
    <?= amd_text_input($schemaFields, '제조사', 'amd-wide') ?>

    <button type="button" class="amd-ecount" id="amd-ecount"><b>!</b> ECount</button>

    <hr class="amd-sep">

    <label for="상태">상태</label>
    <select id="상태"<?= amd_disabled($schemaFields, '상태') ?>>
<?php foreach ($statusOptions as $status): ?>
        <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
    </select>
    <span></span><span></span>

    <label for="수량" class="amd-red">수량</label>
    <?= amd_number_input($schemaFields, '수량') ?>

    <span></span><span></span>

    <label for="구매원가" class="amd-red">구매원가</label>
    <?= amd_number_input($schemaFields, '구매원가') ?>

    <label for="USD구매원가" class="amd-red">USD구매원가</label>
    <?= amd_number_input($schemaFields, 'USD구매원가') ?>

    <label for="판매단가" class="amd-red">판매단가</label>
    <?= amd_number_input($schemaFields, '판매단가') ?>

    <label for="USD판매단가" class="amd-red">USD판매단가</label>
    <?= amd_number_input($schemaFields, 'USD판매단가') ?>

    <label for="마진율" class="amd-blue">마진율</label>
    <?= amd_number_input($schemaFields, '마진율') ?>

    <label for="USD마진율" class="amd-blue">USD마진율</label>
    <?= amd_number_input($schemaFields, 'USD마진율') ?>

    <hr class="amd-sep">

    <label for="구매메모">구매메모</label>
    <?= amd_text_input($schemaFields, '구매메모', 'amd-wide') ?>

    <label for="판매메모">판매메모</label>
    <?= amd_text_input($schemaFields, '판매메모', 'amd-wide') ?>

</form>

<!-- 비고 — 폼 아래 남는 높이를 crudbuttons 바로 위까지 채운다(여러 줄). -->
<div class="amd-memo-row">
    <label for="비고">비고</label>
    <textarea id="비고"<?= amd_disabled($schemaFields, '비고') ?>></textarea>
</div>
<div class="amd-message" id="amd-message"></div>

<!-- 모델 검색 팝업 — 모델 찾기 버튼이 연다(amdOpenModelSearch). iframe은 열 때마다 검색 칸 글자로 불러온다. -->
<div class="amd-modal" id="amd-model-modal" hidden>
    <div class="amd-modal-window" role="dialog" aria-modal="true" aria-label="모델 검색">
        <div class="amd-modal-title">
            <span>모델 검색</span>
            <button type="button" class="amd-modal-close" id="amd-model-modal-close" title="닫기" aria-label="닫기">&times;</button>
        </div>
        <iframe id="amd-model-frame" title="모델 검색"></iframe>
    </div>
</div>

<!-- 저장/삭제/신규입력/창닫기 — crudbuttons(CrudJs가 위 컨트롤에 붙는다) -->
<iframe class="cb-frame" id="cb-frame" src="<?= htmlspecialchars($buttonsSrc, ENT_QUOTES, 'UTF-8') ?>" title="저장/삭제 버튼"></iframe>

<script>
// VBA식 JS — var(함수 맨 위에 모아 선언), function, if/else, for, 매개변수는 p로 시작,
// 문자열은 +로 잇기, 서버를 기다리는 일은 async/await(crudbuttons.md 1절).
// 화면 전용 함수·변수는 amd / AMD_ 접두사. 이벤트 함수와 action/action_xxx에는 붙이지 않는다.

// 이 문의내역이 딸린 문의(t문의.code) — 신규로 비울 때 parentcode로 넣는다.
var AMD_PARENT_CODE = <?= json_encode($parentCode) ?>;
var AMD_FETCH_URL = <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
// 숫자 칸의 소수 자리 — 마진율은 0.00, 나머지는 정수에 천단위 콤마.
var AMD_NUMBER_DECIMALS = { '수량': 0, '구매원가': 0, 'USD구매원가': 2, '판매단가': 0, 'USD판매단가': 2, '마진율': 2, 'USD마진율': 2 };
// 모델을 고르면 함께 채우는 칸(콤보 원본은 tb제품 — fetch가 t문의내역 필드 이름으로 보낸다).
var AMD_MODEL_DETAIL_FIELDS = ['제품종류', '제품code', '품목코드', '제조사'];
// 모델 검색 팝업(tb제품)에서 행을 고르면 옮겨 넣는 필드 — {tb제품 필드: t문의내역 필드}.
// tb제품 행에 없는 필드나 t문의내역에 없는 필드는 건너뛴다.
var AMD_PRODUCT_MAP = {
    '모델명': '모델명',
    '종류': '제품종류',
    'code': '제품code',
    '품목코드': '품목코드',
    '제조사': '제조사'
};
var AMD_MODEL_SEARCH_URL = encodeURIComponent('inquiryContent_모델검색_entry.php');
// 모델 검색 결과 {모델명: {제품종류, 품목코드, 제조사}} — 모델을 고르면 그 값을 칸에 채운다.
var amdModels = {};
// 마지막으로 찾은 검색어 — Enter로 찾은 뒤 칸을 떠날 때(change) 같은 검색을 또 하지 않게.
var amdLastKeyword = null;
// 모델 찾기 요청 순번 — 늦게 온 앞 요청의 결과가 나중 목록을 덮어쓰지 않게(레코드를 빨리 바꿀 때).
var amdModelSeq = 0;
// crudbuttons(psender) — 이벤트가 처음 불릴 때 담아 둔다. 이벤트 밖(모델 고르기)에서
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
 * 신규 폼을 비운 뒤 — 같은 문의(parentcode)의 새 문의내역으로. parentcode는 crudbuttons가
 * keep=parentcode로 남겨 준다. AMD_PARENT_CODE(주소의 parentcode, 연 레코드의 parentcode,
 * 또는 띄운 쪽이 action('newRecord', {parentcode})로 준 값)가 있으면 그 값으로 넣는다.
 */
function afterCrudClear(psender, ppara) {
    amdSender = psender;
    if (amdTrim(AMD_PARENT_CODE) !== '') {
        amdSetField('parentcode', AMD_PARENT_CODE);
    }
    // 앞 레코드의 모델 목록을 비우고, 원본 전체 목록을 다시 채운다(펼치거나 고르지 않음).
    // amd가 뜰 때는 신규든 기존이든 늘 콤보에 원본 목록이 있게 한다.
    amdResetModelList('');
    amdFindModels(true);
    amdFormatAllNumbers();
    amdCaption(psender);
}

/**
 * 레코드를 채운 뒤 — 콤보에 없는 값(모델명, 상태)은 항목을 만들어 넣고 다시 고른다
 * (CrudJs가 그릴 때는 항목이 없어 빈 칸이 됐다). 숫자 칸은 소수 자리를 맞춘다.
 * 이 함수가 끝난 직후 crudbuttons가 지금 값을 기준값으로 기억하므로 여기서 바로 맞춘다.
 */
function afterCrudSelectRecord(psender, ppara) {
    var rows = psender.getCrud().getRecordSet();
    var row;

    amdSender = psender;
    if (rows.length > 0) {
        row = rows[0];
        // 모델명 콤보 — 우선 이 레코드의 모델명만 두고(그래서 기준값이 바로 맞다), 원본 목록을
        // 다시 채운다(펼치지 않음). 검색 글자가 있으면 그 글자로, 없으면 전체 목록(최대 200건).
        amdResetModelList(row['모델명']);
        amdFindModels(true);
        amdEnsureOption('상태', row['상태']);
        amdSetField('상태', amdTrim(row['상태']));
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

        case 'setModel':
        case 'closeModelSearch':
            return action_model(pcmd, pparams, psender);

        default:
            console.warn('문의내역.action: 알 수 없는 pcmd - ' + pcmd);
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
 * newRecord {parentcode} — 띄운 쪽(문의확인)의 문의 탭 신규추가. 이 문의(parentcode)의 새
 * 문의내역으로 폼을 비운다(버튼 iframe의 clear — 저장 안 한 값이 있으면 묻는다).
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

/**
 * 모델 검색 팝업(inquiryContent_모델검색_entry.php)이 부른다.
 *  - setModel {row}: 더블클릭한 tb제품 행 — 관련 필드에 넣고 팝업을 닫는다(amdSetModel).
 *  - closeModelSearch: 팝업을 닫는다(팝업에서 Esc).
 */
function action_model(pcmd, pparams, psender) {
    switch (pcmd) {
        case 'setModel':
            return amdSetModel(pparams.row);
        case 'closeModelSearch':
            return amdCloseModelSearch();
    }
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
 * 필드에 값을 넣는다 — crudbuttons(CrudJs)가 준비됐으면 그쪽으로. t문의내역에 없는
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

/** 제목 — "문의내역 (code)" / "문의내역 (신규)"(팝업이면 팝업 캡션). */
function amdCaption(psender) {
    var pk = psender.getPk();

    if (pk === null) {
        psender.setCaption('문의내역 (신규)');
    } else {
        psender.setCaption('문의내역 (' + pk + ')');
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
/** '54000' → '54,000', 마진율 '100' → '100.00'. 숫자가 아니면 그대로. */
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

// ── 모델 검색 ──
/**
 * 모델명 콤보 목록을 비우고 pmodel(이 레코드의 모델명) 하나만 둔다 — 레코드를 열거나 비울 때.
 * 앞 레코드의 모델이 목록에 쌓이지 않게 한다. pmodel이 비었으면 빈 목록.
 */
function amdResetModelList(pmodel) {
    var select = document.getElementById('모델명');
    var model = amdTrim(pmodel);

    amdModelSeq = amdModelSeq + 1;   // 아직 오고 있는 앞 검색 결과는 버린다
    while (select.options.length > 0) {
        select.remove(0);
    }
    amdModels = {};
    if (model !== '') {
        select.add(new Option(model, model));
    }
    amdSetField('모델명', model);
    amdLastKeyword = amdTrim(document.getElementById('검색').value);
}

/**
 * 검색 글자로 모델 목록을 찾아 모델명 콤보를 채운다. 지금 고른 모델은 목록에
 * 없어도 남겨 둔다.
 *  - 사용자가 찾을 때(pquiet 생략/false — 검색 칸 Enter, AfterUpdate): 찾은 게 하나뿐이면
 *    바로 고르고, 여럿이면 콤보를 펼친다(amdOpenModelList).
 *  - 레코드를 열 때(pquiet true — afterCrudSelectRecord): 목록만 채운다. 고르거나 펼치지
 *    않고, 지금 모델명을 그대로 두므로 "수정됨"이 되지 않는다.
 */
async function amdFindModels(pquiet) {
    var keyword = document.getElementById('검색').value.trim();
    var select = document.getElementById('모델명');
    var current;
    var seq;
    var res;
    var data;
    var i;

    amdModelSeq = amdModelSeq + 1;
    seq = amdModelSeq;
    amdLastKeyword = keyword;
    if (pquiet !== true) {
        amdMessage('모델 찾는 중...', false);
    }
    try {
        res = await fetch(AMD_FETCH_URL + '?action=models&keyword=' + encodeURIComponent(keyword));
        data = await res.json();
        if (!data.success) {
            throw new Error(data.error);
        }
    } catch (err) {
        if (seq === amdModelSeq) {
            amdMessage('모델 찾기 실패: ' + err.message, true);
        }
        return;
    }
    // 그 사이에 다른 레코드를 열었거나 다시 찾았으면 이 결과는 버린다.
    if (seq !== amdModelSeq) {
        return;
    }

    current = select.value;
    while (select.options.length > 0) {
        select.remove(0);
    }
    amdModels = {};
    for (i = 0; i < data.models.length; i++) {
        amdModels[data.models[i]['모델명']] = data.models[i];
        select.add(new Option(data.models[i]['모델명'], data.models[i]['모델명']));
    }
    if (current !== '' && !amdModels[current]) {
        select.add(new Option(current, current), 0);
    }
    select.value = current;
    if (current === '') {
        select.selectedIndex = -1;
    }

    // 레코드를 열 때는 목록만 채우고 끝(고르기/펼치기/안내 없음).
    if (pquiet === true) {
        amdButtonsRefresh();
        return;
    }

    if (data.models.length > 0) {
        amdMessage(data.models.length + '건 찾았습니다. 모델명을 고르세요.', false);
    } else {
        amdMessage('찾은 모델이 없습니다.', false);
    }
    if (data.models.length === 1) {
        select.value = data.models[0]['모델명'];
        amdSelectModel();
    } else if (data.models.length > 1) {
        amdOpenModelList(select);
    } else {
        select.focus();
    }
    amdButtonsRefresh();
}

/**
 * 모델명 콤보를 펼친다 — 브라우저의 showPicker()(Chrome 121 이상). 사용자가 방금 누르거나
 * 친 직후에만 브라우저가 허락하므로, 안 되면(오래된 브라우저, 허락 안 됨) 포커스만 옮긴다.
 */
function amdOpenModelList(pselect) {
    pselect.focus();
    if (typeof pselect.showPicker !== 'function') {
        return;
    }
    try {
        pselect.showPicker();
    } catch (err) {
        // 펼치기를 허락하지 않으면 포커스만 둔다(↓ 키나 클릭으로 펼칠 수 있다).
    }
}

/** 검색 칸의 AfterUpdate(change) — 글자를 바꾸고 칸을 떠나면 찾는다. 방금 Enter로 찾은 글자면 건너뛴다. */
function amdKeywordChanged() {
    var keyword = document.getElementById('검색').value.trim();

    if (keyword === amdLastKeyword) {
        return;
    }
    amdFindModels();
}

/** 고른 모델의 제품종류/품목코드/제조사를 칸에 채운다(검색 결과에 있는 모델만). */
function amdSelectModel() {
    var select = document.getElementById('모델명');
    var model = amdModels[select.value];
    var name;
    var i;

    if (!model) {
        amdButtonsRefresh();
        return;
    }
    for (i = 0; i < AMD_MODEL_DETAIL_FIELDS.length; i++) {
        name = AMD_MODEL_DETAIL_FIELDS[i];
        // tb제품에 없어 응답에 안 온 필드는 건드리지 않는다.
        if (!Object.prototype.hasOwnProperty.call(model, name)) {
            continue;
        }
        amdSetField(name, amdTrim(model[name]));
    }
    amdButtonsRefresh();
}

// ── 모델 검색 팝업(tb제품) — 고객사 검색과 같은 틀 ──
/**
 * 모델 찾기 버튼 — 모델 검색 팝업을 연다. 검색 칸 글자를 첫 검색어로 넘긴다.
 * 이 팝업은 작으므로 띄운 쪽(문의확인)에 action('openModelSearch')로 맡겨 화면 전체 크기로
 * 띄우게 한다 — 띄운 쪽이 처리하면(true) 고른 값은 이 화면의 action('setModel')로 돌아온다.
 * 띄운 쪽이 맡지 않으면(단독으로 열었을 때 등) 이 화면 안의 모달로 띄운다.
 */
function amdOpenModelSearch() {
    var frame = document.getElementById('amd-model-frame');
    var keyword = amdTrim(document.getElementById('검색').value);
    var handled = false;

    if (window.parent !== window && typeof window.parent.action === 'function') {
        try {
            handled = window.parent.action('openModelSearch', { keyword: keyword }, window);
        } catch (err) {
            handled = false;
        }
    }
    if (handled === true) {
        return;
    }

    frame.src = AMD_MODEL_SEARCH_URL + '?q=' + encodeURIComponent(keyword);
    document.getElementById('amd-model-modal').hidden = false;
}

/** 모델 검색 팝업을 닫는다. */
function amdCloseModelSearch() {
    document.getElementById('amd-model-modal').hidden = true;
}

/**
 * 모델 검색 팝업에서 고른 tb제품 행(prow)을 관련 필드에 넣는다 — AMD_PRODUCT_MAP대로
 * (종류 → 제품종류, code → 제품code 등). 행에 없는 필드는 건너뛴다.
 * 모델명 콤보에 없는 모델이면 항목을 만들어 넣는다. 넣은 뒤 팝업을 닫는다.
 */
function amdSetModel(prow) {
    var names;
    var source;
    var target;
    var i;

    if (!prow) {
        return;
    }
    names = Object.keys(AMD_PRODUCT_MAP);
    for (i = 0; i < names.length; i++) {
        source = names[i];
        target = AMD_PRODUCT_MAP[source];
        if (!Object.prototype.hasOwnProperty.call(prow, source)) {
            continue;
        }
        if (target === '모델명') {
            amdEnsureOption('모델명', prow[source]);
        }
        amdSetField(target, amdTrim(prow[source]));
    }
    amdCloseModelSearch();
    document.getElementById('모델명').focus();
    amdButtonsRefresh();
}

// ── 시작 — 이벤트 연결 ──
function amdInit() {
    var nums = document.querySelectorAll('#amd-form .amd-num');
    var soon = ['amd-recalc', 'amd-formula', 'amd-ecount'];
    var i;

    for (i = 0; i < nums.length; i++) {
        amdBindNumber(nums[i]);
    }

    // 검색 — Enter를 누르거나, 글자를 바꾸고 칸을 떠나면(change) 모델 목록을 찾아 콤보를 펼친다.
    document.getElementById('검색').addEventListener('keydown', function (pevent) {
        if (pevent.key === 'Enter') {
            pevent.preventDefault();
            amdFindModels();
        }
    });
    document.getElementById('검색').addEventListener('change', amdKeywordChanged);
    // 모델 찾기 버튼 — tb제품 검색 팝업(고객사 검색과 같은 틀).
    document.getElementById('amd-model-find').addEventListener('click', amdOpenModelSearch);
    document.getElementById('amd-model-modal-close').addEventListener('click', amdCloseModelSearch);
    // 팝업 바깥(반투명 막)을 누르거나 Esc를 누르면 닫는다.
    document.getElementById('amd-model-modal').addEventListener('click', function (pevent) {
        if (pevent.target === this) {
            amdCloseModelSearch();
        }
    });
    document.addEventListener('keydown', function (pevent) {
        if (pevent.key === 'Escape' && !document.getElementById('amd-model-modal').hidden) {
            amdCloseModelSearch();
        }
    });
    document.getElementById('모델명').addEventListener('change', amdSelectModel);

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
