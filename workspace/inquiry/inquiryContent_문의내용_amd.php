<?php
declare(strict_types=1);

/**
 * [문의내역 팝업 — t문의내역 한 건의 상세(바탕화면 문의내용팝업.png)]
 * 구성: inquiryContent_문의내용_amd.php(이 파일) + inquiryContent_문의내용_amd_fetch.php.
 * Popup.js의 iframeUrl로 띄우는 화면이다 — 자기 CRUD를 직접 하고, 결과만 자기를
 * 띄운 Popup에 보고한다:
 *
 *   new Popup({
 *       title: '문의내역', iframeUrl: '.../inquiryContent_문의내용_amd.php?code=16275',
 *       iframeWidth: 600, iframeHeight: 660,
 *       onAction: function (ptype, payload) { ... },  // 'saved' / 'deleted' {code}
 *   });
 *
 * 주소: ?code=<t문의내역.code> — 기존 행. ?code=new&parentcode=<t문의.code> — 신규.
 * 주소창에 바로 열어도 동작한다(그때는 보고할 곳이 없어 장닫기만 안 된다).
 *
 * 배치(위에서 아래로):
 *  - 문의code(읽기전용) + 재계산/수식 버튼
 *  - 검색필드 + 모델명 콤보 + 찾기 — 검색필드 글자로 모델 목록을 찾아 콤보를 채우고,
 *    모델을 고르면 제품종류/품목코드/제조사가 함께 들어온다. ECount 버튼.
 *  - 상태/수량, 구매원가·USD구매원가, 판매단가·USD판매단가, 마진율·USD마진율
 *  - 구매메모/판매메모/비고
 *  - 저장/삭제/신규추가, 장닫기
 *
 * 지금 동작하는 것: 표시/저장/삭제/신규추가/장닫기, 모델 검색. 재계산/수식/ECount는 다음 단계.
 * t문의내역에 없는 필드의 칸은 비활성으로 두고 저장하지 않는다.
 *
 * 페이지 함수:
 *  - requery() — 서버 값으로 다시 읽는다.
 *  - action(pcmd, pparams, psender) — 'requery'.
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
$currentStatus = $record !== null ? trim((string) ($record['상태'] ?? '')) : '';
if ($currentStatus !== '' && !in_array($currentStatus, $statusOptions, true)) {
    $statusOptions[] = $currentStatus;
}

/** 입력칸 value 속성용 — 레코드 값(없으면 ''). */
function amd_value(?array $record, string $field): string
{
    if ($record === null || !isset($record[$field])) {
        return '';
    }
    return htmlspecialchars(trim((string) $record[$field]), ENT_QUOTES, 'UTF-8');
}

/** t문의내역에 없는 필드면 disabled 속성(+ 안내 title). */
function amd_disabled(array $schemaFields, string $field): string
{
    return isset($schemaFields[$field]) ? '' : ' disabled title="t문의내역에 ' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . ' 필드가 없습니다"';
}

/** 숫자 칸 하나 — 오른쪽 정렬, 천단위 콤마는 JS가 붙인다(amdFormatNumber). */
function amd_number_input(?array $record, array $schemaFields, string $field): string
{
    return '<input type="text" class="amd-num" id="amd-' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . '"'
        . ' value="' . amd_value($record, $field) . '"' . amd_disabled($schemaFields, $field) . ' autocomplete="off">';
}

$fetchUrl = APP_BASE_URL . '/workspace/inquiry/' . rawurlencode('inquiryContent_문의내용_amd_fetch.php')
    . '?code=' . rawurlencode($code) . '&parentcode=' . rawurlencode($parentCode);
$selfUrl = rawurlencode('inquiryContent_문의내용_amd.php');
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
    padding: 18px 40px 14px; box-sizing: border-box; background: #ececec; color: #000;
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
.amd-form .amd-dirty { background: #fffbeb; }
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
.amd-model-row #amd-keyword { width: 58px; flex: none; }
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

.amd-buttons { display: flex; gap: 6px; margin-top: 30px; }
.amd-buttons .amd-spacer { flex: 1; }
.amd-buttons button { min-width: 74px; }

.amd-message { min-height: 18px; margin-top: 8px; color: #374151; }
.amd-message.amd-error { color: #dc2626; }
</style>
</head>
<body>
<?php if ($loadError !== ''): ?>
<p class="amd-message amd-error"><?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></p>
<?php else: ?>
<form class="amd-form" id="amd-form" autocomplete="off" onsubmit="return false;">
    <label for="amd-code">문의code</label>
    <div class="amd-code-row">
        <input type="text" id="amd-code" value="<?= $isNew ? '(신규)' : htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" readonly>
        <span class="amd-spacer"></span>
        <button type="button" id="amd-recalc">&#9998; 재계산</button>
        <button type="button" id="amd-formula">&#128269; 수식</button>
    </div>

    <label for="amd-모델명">모델명</label>
    <div class="amd-model-row">
        <input type="text" id="amd-keyword" placeholder="검색" title="모델명에 들어간 글자로 찾습니다(Enter)">
        <select id="amd-모델명"<?= amd_disabled($schemaFields, '모델명') ?>>
<?php $currentModel = amd_value($record, '모델명'); ?>
<?php if ($currentModel !== ''): ?>
            <option value="<?= $currentModel ?>" selected><?= $currentModel ?></option>
<?php endif; ?>
        </select>
        <button type="button" class="amd-icon-btn" id="amd-model-find" title="모델 찾기">&#128269;</button>
    </div>

    <label for="amd-제품종류">제품종류</label>
    <input type="text" id="amd-제품종류" value="<?= amd_value($record, '제품종류') ?>"<?= amd_disabled($schemaFields, '제품종류') ?>>
    <label for="amd-품목코드">품목코드</label>
    <input type="text" id="amd-품목코드" value="<?= amd_value($record, '품목코드') ?>"<?= amd_disabled($schemaFields, '품목코드') ?>>

    <label for="amd-제조사">제조사</label>
    <input type="text" id="amd-제조사" class="amd-wide" value="<?= amd_value($record, '제조사') ?>"<?= amd_disabled($schemaFields, '제조사') ?>>

    <button type="button" class="amd-ecount" id="amd-ecount"><b>!</b> ECount</button>

    <hr class="amd-sep">

    <label for="amd-상태">상태</label>
    <select id="amd-상태"<?= amd_disabled($schemaFields, '상태') ?>>
<?php foreach ($statusOptions as $status): ?>
        <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"<?= $status === $currentStatus ? ' selected' : '' ?>><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
    </select>
    <span></span><span></span>

    <label for="amd-수량" class="amd-red">수량</label>
    <?= amd_number_input($record, $schemaFields, '수량') ?>
    <span></span><span></span>

    <label for="amd-구매원가" class="amd-red">구매원가</label>
    <?= amd_number_input($record, $schemaFields, '구매원가') ?>
    <label for="amd-USD구매원가" class="amd-red">USD구매원가</label>
    <?= amd_number_input($record, $schemaFields, 'USD구매원가') ?>

    <label for="amd-판매단가" class="amd-red">판매단가</label>
    <?= amd_number_input($record, $schemaFields, '판매단가') ?>
    <label for="amd-USD판매단가" class="amd-red">USD판매단가</label>
    <?= amd_number_input($record, $schemaFields, 'USD판매단가') ?>

    <label for="amd-마진율" class="amd-blue">마진율</label>
    <?= amd_number_input($record, $schemaFields, '마진율') ?>
    <label for="amd-USD마진율" class="amd-blue">USD마진율</label>
    <?= amd_number_input($record, $schemaFields, 'USD마진율') ?>

    <hr class="amd-sep">

    <label for="amd-구매메모">구매메모</label>
    <input type="text" id="amd-구매메모" class="amd-wide" value="<?= amd_value($record, '구매메모') ?>"<?= amd_disabled($schemaFields, '구매메모') ?>>
    <label for="amd-판매메모">판매메모</label>
    <input type="text" id="amd-판매메모" class="amd-wide" value="<?= amd_value($record, '판매메모') ?>"<?= amd_disabled($schemaFields, '판매메모') ?>>
    <label for="amd-비고">비고</label>
    <input type="text" id="amd-비고" class="amd-wide" value="<?= amd_value($record, '비고') ?>"<?= amd_disabled($schemaFields, '비고') ?>>
</form>

<div class="amd-buttons">
    <button type="button" id="amd-save">&#128190; 저장</button>
    <button type="button" id="amd-delete"<?= $isNew ? ' disabled' : '' ?>>&#10005; 삭제</button>
    <button type="button" id="amd-new">&#10010; 신규추가</button>
    <span class="amd-spacer"></span>
    <button type="button" id="amd-close">&#128682; 장닫기</button>
</div>
<div class="amd-message" id="amd-message"></div>

<script>
var AMD_CODE = <?= json_encode($code) ?>;
var AMD_PARENT_CODE = <?= json_encode($parentCode) ?>;
var AMD_IS_NEW = <?= $isNew ? 'true' : 'false' ?>;
var AMD_FETCH_URL = <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
var AMD_SELF_URL = <?= json_encode($selfUrl, JSON_UNESCAPED_SLASHES) ?>;
// inquiryContent_문의내용_amd_fetch.php의 RECORD_FIELDS / NUMBER_FIELDS와 같아야 한다.
var AMD_FIELDS = ['모델명', '제품종류', '품목코드', '제조사', '상태', '수량', '구매원가', 'USD구매원가',
    '판매단가', 'USD판매단가', '마진율', 'USD마진율', '구매메모', '판매메모', '비고'];
// 소수점 자리 — 마진율은 0.00, 나머지는 정수에 천단위 콤마.
var AMD_NUMBER_DECIMALS = { '수량': 0, '구매원가': 0, 'USD구매원가': 2, '판매단가': 0, 'USD판매단가': 2, '마진율': 2, 'USD마진율': 2 };
var AMD_HEADERS = { 'X-Requested-With': 'XMLHttpRequest' };
// 모델 검색 결과 {모델명: {제품종류, 품목코드, 제조사}} — 모델을 고르면 그 값을 칸에 채운다.
var AMD_MODELS = {};

function amdField(name) {
    return document.getElementById('amd-' + name);
}

function amdMessage(text, isError) {
    var el = document.getElementById('amd-message');
    el.textContent = text || '';
    el.classList.toggle('amd-error', !!isError);
}

// ── 나를 띄운 Popup ──
/** 나를 띄운 Popup 인스턴스(onAction 유무와 무관). 주소창에 바로 열었으면 null. */
function amdPopup() {
    try {
        return window.parent !== window && window.parent.Popup ? window.parent.Popup.ofWindow(window) : null;
    } catch (e) {
        return null;
    }
}

/** 결과를 나를 띄운 Popup의 onAction으로 보고한다 — 'saved' / 'deleted' {code, parentcode}. */
function amdReport(ptype, payload) {
    var popup = amdPopup();
    if (popup) {
        popup.action(ptype, payload);
    }
}

// ── 숫자 칸 ──
/** '54000' → '54,000', 마진율 '100' → '100.00'. 숫자가 아니면 그대로. */
function amdFormatNumber(name, value) {
    var raw = String(value == null ? '' : value).replace(/,/g, '').trim();
    if (raw === '' || isNaN(Number(raw))) {
        return raw;
    }
    var digits = AMD_NUMBER_DECIMALS[name] || 0;
    return Number(raw).toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

// ── 입력폼 ──
/** 폼에 레코드 값을 채우고 "수정됨" 표시를 지운다. */
function amdFill(record) {
    AMD_FIELDS.forEach(function (name) {
        var el = amdField(name);
        var value = record[name] == null ? '' : String(record[name]).trim();
        if (el.tagName === 'SELECT' && !Array.prototype.some.call(el.options, function (o) { return o.value === value; })) {
            el.add(new Option(value, value));
        }
        el.value = name in AMD_NUMBER_DECIMALS ? amdFormatNumber(name, value) : value;
        el.classList.remove('amd-dirty');
    });
}

function amdDirty() {
    return document.querySelectorAll('#amd-form .amd-dirty').length > 0;
}

function amdMarkDirty(el) {
    el.classList.add('amd-dirty');
}

// ── 모델 검색 ──
/**
 * 검색필드 글자로 모델 목록을 찾아 모델명 콤보를 채운다. 지금 고른 모델은 목록에
 * 없어도 남겨 둔다. 찾은 게 하나뿐이면 바로 고른다.
 */
function amdFindModels() {
    var keyword = document.getElementById('amd-keyword').value.trim();
    var select = amdField('모델명');
    amdMessage('모델 찾는 중...');
    return fetch(AMD_FETCH_URL + '&action=models&keyword=' + encodeURIComponent(keyword), { headers: AMD_HEADERS })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data.models) {
                throw new Error(data.error || '모델 목록을 불러오지 못했습니다.');
            }
            var current = select.value;
            while (select.options.length) {
                select.remove(0);
            }
            AMD_MODELS = {};
            data.models.forEach(function (m) {
                AMD_MODELS[m['모델명']] = m;
                select.add(new Option(m['모델명'], m['모델명']));
            });
            if (current !== '' && !(current in AMD_MODELS)) {
                select.add(new Option(current, current), 0);
            }
            select.value = current;
            amdMessage(data.models.length ? data.models.length + '건 찾았습니다. 모델명을 고르세요.' : '찾은 모델이 없습니다.');
            if (data.models.length === 1) {
                select.value = data.models[0]['모델명'];
                amdSelectModel();
            } else {
                select.focus();
            }
        })
        .catch(function (e) {
            amdMessage('모델 찾기 실패: ' + e.message, true);
        });
}

/** 고른 모델의 제품종류/품목코드/제조사를 칸에 채운다(검색 결과에 있는 모델만). */
function amdSelectModel() {
    var select = amdField('모델명');
    amdMarkDirty(select);
    var model = AMD_MODELS[select.value];
    if (!model) {
        return;
    }
    ['제품종류', '품목코드', '제조사'].forEach(function (name) {
        var el = amdField(name);
        var value = model[name] == null ? '' : String(model[name]);
        if (!el.disabled && el.value !== value) {
            el.value = value;
            amdMarkDirty(el);
        }
    });
}

// ── 저장/삭제/신규/닫기 ──
function amdSave() {
    var values = {};
    AMD_FIELDS.forEach(function (name) {
        var el = amdField(name);
        if (!el.disabled) {
            values[name] = el.value;
        }
    });
    amdMessage('저장 중...');
    return fetch(AMD_FETCH_URL + '&action=save_record', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ values: values })
    })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data.ok) {
                throw new Error(data.error || '저장 실패');
            }
            document.querySelectorAll('#amd-form .amd-dirty').forEach(function (el) { el.classList.remove('amd-dirty'); });
            amdReport('saved', { code: data.code, parentcode: AMD_PARENT_CODE, isNew: !!data.isNew });
            // 신규를 처음 저장했으면 같은 팝업에서 새 code로 다시 연다.
            if (data.isNew && data.code) {
                location.href = AMD_SELF_URL + '?code=' + encodeURIComponent(data.code);
                return;
            }
            amdMessage('저장했습니다.');
        })
        .catch(function (e) {
            amdMessage('저장 실패: ' + e.message, true);
        });
}

function amdDelete() {
    if (AMD_IS_NEW || !confirm('문의내역(' + AMD_CODE + ')을 삭제할까요? 되돌릴 수 없습니다.')) {
        return;
    }
    amdMessage('삭제 중...');
    return fetch(AMD_FETCH_URL + '&action=delete_record', { method: 'POST', headers: AMD_HEADERS })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data.ok) {
                throw new Error(data.error || '삭제 실패');
            }
            amdReport('deleted', { code: AMD_CODE, parentcode: AMD_PARENT_CODE });
            amdMessage('삭제했습니다.');
            amdCloseNow();
        })
        .catch(function (e) {
            amdMessage('삭제 실패: ' + e.message, true);
        });
}

/** 신규추가 — 같은 문의(parentcode)의 새 문의내역으로 이 팝업을 다시 연다. */
function amdNew() {
    if (amdDirty() && !confirm('저장하지 않은 내용이 있습니다. 버리고 새 문의내역을 열까요?')) {
        return;
    }
    location.href = AMD_SELF_URL + '?code=new&parentcode=' + encodeURIComponent(AMD_PARENT_CODE);
}

/** 나를 띄운 Popup을 닫는다(바로 열었으면 아무것도 안 함). */
function amdCloseNow() {
    var popup = amdPopup();
    if (popup) {
        popup.close();
    }
}

/** 장닫기 — 저장 안 한 값이 있으면 묻고 닫는다. */
function amdClose() {
    if (amdDirty() && !confirm('저장하지 않은 내용이 있습니다. 닫을까요?')) {
        return;
    }
    amdCloseNow();
}

/** requery() — 서버 값으로 다시 읽는다(신규는 다시 읽을 게 없다). */
function requery() {
    if (AMD_IS_NEW) {
        return Promise.resolve();
    }
    return fetch(AMD_FETCH_URL + '&action=record', { headers: AMD_HEADERS })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data.record) {
                throw new Error(data.error || '불러오기 실패');
            }
            amdFill(data.record);
            amdMessage('');
        })
        .catch(function (e) {
            amdMessage('불러오기 실패: ' + e.message, true);
        });
}

/** action(pcmd, pparams, psender) — 바깥에서 이 화면을 부르는 창구. */
function action(pcmd, pparams, psender) {
    switch (pcmd) {
        case 'requery':
            if (amdDirty()) {
                return;
            }
            return requery();
        default:
            console.warn('문의내역.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

// ── 이벤트 ──
document.querySelectorAll('#amd-form input, #amd-form select').forEach(function (el) {
    if (el.readOnly || el.id === 'amd-keyword' || el.id === 'amd-모델명') {
        return;
    }
    el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', function () {
        amdMarkDirty(el);
    });
});
document.querySelectorAll('#amd-form .amd-num').forEach(function (el) {
    var name = el.id.replace(/^amd-/, '');
    el.value = amdFormatNumber(name, el.value);
    // 입력할 때는 콤마 없이, 칸을 떠나면 다시 콤마.
    el.addEventListener('focus', function () { el.value = el.value.replace(/,/g, ''); el.select(); });
    el.addEventListener('blur', function () { el.value = amdFormatNumber(name, el.value); });
});
document.getElementById('amd-keyword').addEventListener('keydown', function (event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        amdFindModels();
    }
});
document.getElementById('amd-model-find').addEventListener('click', amdFindModels);
amdField('모델명').addEventListener('change', amdSelectModel);
document.getElementById('amd-save').addEventListener('click', amdSave);
document.getElementById('amd-delete').addEventListener('click', amdDelete);
document.getElementById('amd-new').addEventListener('click', amdNew);
document.getElementById('amd-close').addEventListener('click', amdClose);
// 재계산/수식/ECount — 다음 단계(지금은 안내만).
['amd-recalc', 'amd-formula', 'amd-ecount'].forEach(function (id) {
    var btn = document.getElementById(id);
    btn.addEventListener('click', function () {
        amdMessage(btn.textContent.trim() + ' — 준비 중입니다.');
    });
});
// 주소창에 바로 열었으면 닫을 팝업이 없다.
if (!amdPopup()) {
    document.getElementById('amd-close').disabled = true;
}
</script>
<?php endif; ?>
</body>
</html>
