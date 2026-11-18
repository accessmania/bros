<?php
declare(strict_types=1);

/**
 * [모델 이력 — 같은 모델명의 문의내용/수주/발주/견적. 업체 관계없이] (그림: 느낌표.png, 2026-11-18)
 * amd(문의내용/수주/발주/견적)의 모델명 옆 빨간 ! 버튼 → 문의확인(inquiryContent_entry.php)이
 * action('openModelHistory', {model, maker})로 이 화면을 비모달 팝업(Popup.js)에 띄운다.
 * 팝업 제목은 "모델명 (제조사)".
 *
 * 배치:
 *  - 왼쪽 위 콤보: 문의내용 / 수주 / 발주 / 견적. 바꾸면 아래 그리드가 그 종류로 바뀐다(같은 모델명).
 *  - 아래: 업무 메뉴의 그 화면(inquiry_<종류>_entry.php)을 ?model=로 그대로 띄운다 — 그래서 그리드의 컬럼·
 *    필드감추기·검색 묶음(상태 콤보 + 검색어 + 검색)·더블클릭이 업무 메뉴에서 연 화면과 같다.
 *    그 화면들은 ?model=이면 그 모델명만 조회하고(fetch의 모델명 = 조건) 신규추가 버튼을 숨긴다.
 *
 * 주소: ?model=<모델명>&kind=<문의내용|수주|발주|견적>(생략하면 문의내용)
 * 페이지 함수: action(pcmd, pparams, psender)
 *  - 'showModel' {model, kind} — 떠 있는 팝업에 다른 모델명(또는 종류)을 보인다(다시 열지 않음).
 * Esc를 누르면 부모(문의확인)의 action('closeModelHistory')로 팝업을 닫는다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('Login');

// 로그인이 안 살아있으면 여기서 login.php로 이동하고 끝난다.
Login::isAlive();

// 콤보 항목 — 종류 => 업무 메뉴 화면 파일. 이 목록 밖의 종류는 받지 않는다.
$kinds = [
    '문의내용' => 'inquiry_문의내용_entry.php',
    '수주' => 'inquiry_수주_entry.php',
    '발주' => 'inquiry_발주_entry.php',
    '견적' => 'inquiry_견적_entry.php',
];
$model = isset($_GET['model']) ? trim((string) $_GET['model']) : '';
$kind = isset($_GET['kind']) ? trim((string) $_GET['kind']) : '';
if (!isset($kinds[$kind])) {
    $kind = '문의내용';
}

// 종류 => 화면 주소(모델명은 JS가 붙인다).
$kindUrls = [];
foreach ($kinds as $key => $file) {
    $kindUrls[$key] = APP_BASE_URL . '/workspace/inquiry/' . rawurlencode($file);
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>모델 이력</title>
<style>
html, body { margin: 0; height: 100%; overflow: hidden; }
body {
    display: flex; flex-direction: column; box-sizing: border-box; padding: 8px 8px 0;
    font-family: 'Malgun Gothic', 'Segoe UI', system-ui, sans-serif; font-size: 13px; color: #1e293b; background: #fff;
}
/* 왼쪽 위 종류 콤보 */
.mh-top { flex: none; display: flex; align-items: center; gap: 8px; }
.mh-top select {
    font: inherit; height: 28px; min-width: 130px; padding: 0 6px; box-sizing: border-box;
    border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: inherit;
}
.mh-top select:focus { outline: none; border-color: #6b7280; }
.mh-error { color: #dc2626; }
/* 업무 메뉴 화면 — 남는 높이를 다 쓴다 */
.mh-frame { flex: 1; min-height: 0; width: 100%; border: 0; display: block; }
</style>
</head>
<body>
<div class="mh-top">
    <select id="mh-kind" aria-label="종류">
<?php foreach ($kinds as $key => $file): ?>
        <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"<?= $key === $kind ? ' selected' : '' ?>><?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
    </select>
    <span class="mh-error" id="mh-error"></span>
</div>
<iframe class="mh-frame" id="mh-frame" title="모델 이력 목록"></iframe>
<script>
// VBA식 JS — var(함수 맨 위에 모아 선언), function, if/else, 매개변수는 p로 시작.
// 화면 전용 함수·변수는 mh / MH_ 접두사(Model History). action/action_xxx에는 붙이지 않는다.
// 종류 => 업무 메뉴 화면 주소.
var MH_URLS = <?= json_encode($kindUrls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
// 지금 보이는 모델명 — showModel로 바뀐다.
var mhModel = <?= json_encode($model, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

/** 콤보의 종류와 mhModel로 아래 화면을 다시 띄운다. 모델명이 비었으면 이유를 보인다. */
function mhLoad() {
    var kind = document.getElementById('mh-kind').value;
    var frame = document.getElementById('mh-frame');
    var errorEl = document.getElementById('mh-error');

    if (mhModel === '') {
        errorEl.textContent = '모델명이 없습니다 — amd의 모델명 칸을 채운 뒤 ! 버튼을 누르세요.';
        frame.removeAttribute('src');
        return false;
    }
    if (!MH_URLS[kind]) {
        errorEl.textContent = '알 수 없는 종류입니다: ' + kind;
        return false;
    }
    errorEl.textContent = '';
    frame.src = MH_URLS[kind] + '?model=' + encodeURIComponent(mhModel);
    return true;
}

// ══════════════════════════════════════════════════════════════════
// action — 부모(문의확인)가 이 화면을 부르는 창구.
// ══════════════════════════════════════════════════════════════════
function action(pcmd, pparams, psender) {
    if (!pparams) {
        pparams = {};
    }
    switch (pcmd) {
        case 'showModel':
            return action_show(pcmd, pparams, psender);
        default:
            console.warn('모델이력.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

/** showModel {model, kind} — 다른 모델명(kind가 있으면 그 종류로)을 보인다. */
function action_show(pcmd, pparams, psender) {
    if (pparams.model === null || pparams.model === undefined) {
        mhModel = '';
    } else {
        mhModel = String(pparams.model).trim();
    }
    if (pparams.kind && MH_URLS[pparams.kind]) {
        document.getElementById('mh-kind').value = pparams.kind;
    }
    return mhLoad();
}

/** Esc — 부모(문의확인)에게 팝업을 닫게 한다. */
function mhKeyDown(pevent) {
    if (pevent.key !== 'Escape') {
        return;
    }
    if (window.parent === window || typeof window.parent.action !== 'function') {
        return;
    }
    window.parent.action('closeModelHistory', {}, window);
}

document.getElementById('mh-kind').addEventListener('change', mhLoad);
document.addEventListener('keydown', mhKeyDown);
mhLoad();
</script>
</body>
</html>
