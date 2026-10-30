<?php
declare(strict_types=1);

/**
 * [crudbuttons_entry.php — crudbuttons 시험 화면]
 *
 * 접근 주소:
 *   http://192.168.0.40/bros/src/Model/Crud/crudbuttons/crudbuttons_entry.php   ← 이 시험 화면
 *   http://192.168.0.40/bros/src/Model/Crud/crudbuttons/fLogin_amd.php?code=1   ← 시범 폼 단독(code 생략 시 신규)
 *   http://192.168.0.40/bros/src/Model/Crud/crudbuttons/fLogin_amd_fetch.php?action=schema ← 스키마 확인
 *
 * 구성(폴더 src/Model/Crud/crudbuttons/):
 *  - crudbuttons.js / crudbuttons.php — 폼 아래 iframe에 넣는 재사용 CRUD 버튼(CrudJs 사용)
 *  - fLogin_amd.php / fLogin_amd_fetch.php — tLogin 시범 폼과 그 CrudJs 엔드포인트
 *  - crudbuttons_entry.php(이 파일) — tLogin 목록에서 고르면 fLogin_amd.php를 Popup으로
 *    띄우고, 입력폼의 보고를 이 화면의 action('amdReport')로 받는다.
 *
 * 흐름: 버튼/목록 → action(pcmd) → 팝업 열기. 입력폼의 psender.report() →
 * action('amdReport'), 팝업 onClose → action('popupClosed') → 로그 + 필요하면 목록 requery.
 *
 * 페이지 함수: action(pcmd, pparams, psender) — 종류별 action_xxx로 나눠 넘긴다(아래 참고).
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('Login');

Login::isAlive();

$fetchUrl = APP_BASE_URL . '/src/Model/Crud/crudbuttons/fLogin_amd_fetch.php';
$popupJsPath = APP_BASE_DIR . '/src/js/Popup/Popup.js';

// [id, 글자, pcmd] — 버튼은 전부 action(pcmd)로 흐른다.
$buttons = [
    ['new', '신규 (팝업)', 'openNew'],
    ['open', '선택 열기', 'openSelected'],
    ['modeless', '선택 비모달로 열기', 'openSelectedModeless'],
    ['large', '선택 슬라이드로 열기', 'openSelectedLarge'],
    ['two', '선택+다음 2개 열기', 'openTwo'],
    ['requery', '목록 requery', 'requery'],
    ['requery-popups', '열린 팝업 requery', 'requeryPopups'],
    ['caption', '마지막 팝업 캡션 바꾸기', 'captionLast'],
    ['close-all', '열린 팝업 모두 닫기', 'closeAll'],
    ['tab', '선택 새 탭으로(단독)', 'openTab'],
    ['clear-log', '로그 지우기', 'clearLog'],
];
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>crudbuttons 시험</title>
<script src="<?= APP_BASE_URL ?>/src/js/Popup/Popup.js?v=<?= filemtime($popupJsPath) ?>"></script>
<style>
html, body { margin: 0; height: 100%; }
body {
    padding: 16px; box-sizing: border-box; background: #f3f4f6; color: #1e293b;
    font-family: 'Malgun Gothic', 'Segoe UI', system-ui, sans-serif; font-size: 13px;
}
h1 { font-size: 16px; margin: 0 0 10px; }
.ce-buttons { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
.ce-buttons button {
    font: inherit; height: 30px; padding: 0 12px; cursor: pointer;
    border: 1px solid #d1d5db; border-radius: 4px; background: #fff; color: inherit;
}
.ce-buttons button:hover { background: #e5e7eb; }
.ce-main { display: grid; grid-template-columns: 340px minmax(0, 1fr); gap: 12px; height: calc(100% - 110px); }
.ce-panel { display: flex; flex-direction: column; min-height: 0; background: #fff; border: 1px solid #d1d5db; border-radius: 4px; padding: 10px; }
.ce-panel h2 { font-size: 13px; margin: 0 0 6px; color: #475569; }
#ce-list { flex: 1; min-height: 0; width: 100%; font: inherit; border: 1px solid #d1d5db; }
.ce-hint { margin-top: 6px; color: #64748b; }
#ce-log { flex: 1; min-height: 0; overflow: auto; margin: 0; font-family: Consolas, monospace; font-size: 12px; white-space: pre-wrap; }
</style>
</head>
<body>
<h1>crudbuttons 시험 — tLogin</h1>
<div class="ce-buttons">
<?php foreach ($buttons as [$id, $label, $pcmd]): ?>
    <button type="button" id="ce-<?= $id ?>" data-pcmd="<?= $pcmd ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></button>
<?php endforeach; ?>
</div>
<div class="ce-main">
    <div class="ce-panel">
        <h2>tLogin 목록 <span id="ce-count"></span></h2>
        <select id="ce-list" size="20"></select>
        <div class="ce-hint">항목을 고르면(select) 바로 팝업으로 엽니다.</div>
    </div>
    <div class="ce-panel">
        <h2>콜백 로그 (amdReport / popupClosed → action)</h2>
        <pre id="ce-log"></pre>
    </div>
</div>

<script>
// VBA식 JS — 규칙은 crudbuttons.js 맨 위 주석 참고.
var CE_FETCH_URL = <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
var CE_FORM_URL = 'fLogin_amd.php';
var ceRows = [];       // 지금 목록 행 [{code, 이름, id, ...}] — 다음 행 찾기(openTwo)에 쓴다.
var cePopupIds = [];   // 이 화면이 연 팝업 id(연 순서) — 마지막 팝업 캡션 바꾸기 등에 쓴다.
var cePopupSeq = 0;    // 팝업 id 번호

// ── 작은 도우미 ──

/** 로그 맨 위에 한 줄 쓴다. */
function ceLog(ptext) {
    var el = document.getElementById('ce-log');
    var time = new Date().toTimeString().slice(0, 8);

    el.textContent = '[' + time + '] ' + ptext + '\n' + el.textContent;
}

/** 값을 글자로 — null/undefined면 ''. */
function ceText(pvalue) {
    if (pvalue === null || pvalue === undefined) {
        return '';
    }
    return String(pvalue);
}

/** 목록에서 고른 code — 안 골랐으면 로그를 남기고 null. */
function ceRequirePick() {
    var list = document.getElementById('ce-list');

    if (list.value === '') {
        ceLog('목록에서 먼저 고르세요.');
        return null;
    }
    return list.value;
}

/** 열려 있는 팝업 중 이 화면이 연 것들(배열). */
function ceOpenPopups() {
    var result = [];
    var popup;
    var i;

    for (i = 0; i < cePopupIds.length; i++) {
        popup = Popup.instance(cePopupIds[i]);
        if (popup !== null) {
            result.push(popup);
        }
    }
    return result;
}

// ── 목록 ──

/** 목록을 서버에서 다시 읽는다. pkeepCode를 주면 그 행을 다시 고른다(팝업은 안 연다). */
async function ceRequery(pkeepCode) {
    var list = document.getElementById('ce-list');
    var keep;
    var res;
    var data;
    var row;
    var label;
    var i;

    // 다시 고를 행 — 안 주면 지금 고른 행.
    if (pkeepCode === null || pkeepCode === undefined) {
        keep = list.value;
    } else {
        keep = String(pkeepCode);
    }

    try {
        res = await fetch(CE_FETCH_URL + '?action=selectRecords');
        data = await res.json();
        if (!data.success) {
            throw new Error(data.error);
        }
    } catch (err) {
        ceLog('목록 requery 실패: ' + err.message);
        return;
    }

    ceRows = data.data;

    // 목록 비우기
    while (list.options.length > 0) {
        list.remove(0);
    }

    // 한 줄씩 채우기 — "code  이름  (id)  [미사용]"
    for (i = 0; i < ceRows.length; i++) {
        row = ceRows[i];
        label = row.code + '  ' + ceText(row['이름']) + '  (' + ceText(row.id) + ')';
        if (String(row['사용여부']) === '0') {
            label = label + '  [미사용]';
        }
        list.add(new Option(label, String(row.code)));
    }

    list.value = keep;
    document.getElementById('ce-count').textContent = '(' + ceRows.length + '건)';
}

// ── 팝업 ──

/**
 * fLogin_amd.php를 팝업으로 연다. pcode가 null이면 신규.
 * poptions: { modal: true/false, animation: 'none'|'small'|'large' } — 생략 가능.
 * 입력폼의 보고는 action('amdReport'), 팝업이 닫히면 action('popupClosed')로 흐른다.
 */
function ceOpen(pcode, poptions) {
    var id;
    var title;
    var url;
    var modal;
    var animation;
    var position;
    var offset;

    if (!poptions) {
        poptions = {};
    }

    cePopupSeq = cePopupSeq + 1;
    id = 'fLogin-' + cePopupSeq;

    // 신규 / 기존
    if (pcode === null) {
        title = '로그인 (신규)';
        url = CE_FORM_URL + '?code=new';
    } else {
        title = '로그인 (' + pcode + ')';
        url = CE_FORM_URL + '?code=' + encodeURIComponent(pcode);
    }

    // 모달 여부 — 안 주면 모달.
    if (poptions.modal === false) {
        modal = false;
    } else {
        modal = true;
    }

    // 뜰 때 애니메이션 — 안 주면 'small'.
    if (poptions.animation) {
        animation = poptions.animation;
    } else {
        animation = 'small';
    }

    // 위치 — 모달은 가운데, 비모달은 여러 개가 겹치지 않게 조금씩 비켜 놓는다.
    if (modal) {
        position = 'center';
    } else {
        offset = (cePopupSeq % 5) * 30;
        position = { top: 60 + offset, left: 380 + offset };
    }

    new Popup({
        id: id,
        title: title,
        iframeUrl: url,
        iframeWidth: 560,
        iframeHeight: 400,
        modal: modal,
        animation: animation,
        position: position,
        // 입력폼의 보고(saved/deleted/closed)는 onAction이 아니라 이 화면의
        // action('amdReport')로 바로 온다(crudbuttons의 report).
        onClose: function (ppopupId) {
            action('popupClosed', { id: ppopupId });
        }
    });

    cePopupIds.push(id);
    if (modal) {
        ceLog('열기 ' + id + ' code=' + ceText(pcode));
    } else {
        ceLog('열기 ' + id + ' code=' + ceText(pcode) + ' (비모달)');
    }
}

/** 고른 행과 그다음 행을 비모달로 2개 연다 — 콜백의 id로 어느 팝업에서 왔는지 구분된다. */
function ceOpenTwo(pcode) {
    var nextCode;
    var i;

    // 다음 행 찾기 — 마지막 행이면 첫 행.
    nextCode = String(ceRows[0].code);
    for (i = 0; i < ceRows.length - 1; i++) {
        if (String(ceRows[i].code) === pcode) {
            nextCode = String(ceRows[i + 1].code);
            break;
        }
    }

    ceOpen(pcode, { modal: false, animation: 'none' });
    ceOpen(nextCode, { modal: false, animation: 'none' });
}

/** 열린 팝업마다 안의 폼에 action('requery')를 보낸다 → 버튼 iframe → CrudJs 다시 조회. */
function ceRequeryPopups() {
    var popups = ceOpenPopups();
    var win;
    var i;

    for (i = 0; i < popups.length; i++) {
        win = popups[i].iframeEl.contentWindow;
        if (typeof win.action === 'function') {
            win.action('requery', {}, window);
        }
    }
    ceLog('열린 팝업 ' + popups.length + '개에 requery를 보냈습니다.');
}

/** 마지막으로 연 팝업의 캡션을 바꾼다. */
function ceCaptionLast() {
    var popups = ceOpenPopups();
    var last;

    if (popups.length === 0) {
        ceLog('열린 팝업이 없습니다.');
        return;
    }

    last = popups[popups.length - 1];
    last.setCaption('캡션 바꿈 ' + new Date().toTimeString().slice(0, 8));
    ceLog(last.id + ' 캡션을 바꿨습니다.');
}

/** 열린 팝업을 모두 닫는다. */
function ceCloseAll() {
    var popups = ceOpenPopups();
    var i;

    for (i = 0; i < popups.length; i++) {
        popups[i].close();
    }
}

/** 입력폼(fLogin_amd.php)의 보고 — 저장/삭제면 목록을 다시 읽는다. */
function ceAmdReport(pptype, ppayload, ppopupId) {
    ceLog('보고 ' + ppopupId + ' ← ' + pptype + ' ' + JSON.stringify(ppayload));

    if (pptype === 'saved') {
        ceRequery(ppayload.code);
    } else if (pptype === 'deleted') {
        ceRequery('');
    }
}

/** 팝업이 닫혔다 — 목록에서 그 id를 뺀다. */
function cePopupClosed(pid) {
    var remain = [];
    var i;

    for (i = 0; i < cePopupIds.length; i++) {
        if (cePopupIds[i] !== pid) {
            remain.push(cePopupIds[i]);
        }
    }
    cePopupIds = remain;
    ceLog('닫힘 ' + pid);
}

// ── action — 버튼/목록/입력폼 보고가 전부 이리로 흐른다 ──

/**
 * action(pcmd, pparams, psender) — 이 화면의 창구.
 * 여기서는 명령을 종류별로 나눠 넘기기만 한다(case를 겹쳐 쓰면 VBA의 Case "a", "b"와 같다).
 *   action_list   — 목록: requery
 *   action_open   — 입력폼 열기: openNew / openSelected / openSelectedModeless /
 *                   openSelectedLarge / openTwo / openTab
 *   action_popups — 열린 팝업 다루기: requeryPopups / captionLast / closeAll
 *   action_report — 띄운 쪽에서 알려옴: amdReport(입력폼) / popupClosed(Popup)
 *   action_log    — 로그: clearLog
 */
function action(pcmd, pparams, psender) {
    if (!pparams) {
        pparams = {};
    }

    switch (pcmd) {
        case 'requery':
            return action_list(pcmd, pparams, psender);

        case 'openNew':
        case 'openSelected':
        case 'openSelectedModeless':
        case 'openSelectedLarge':
        case 'openTwo':
        case 'openTab':
            return action_open(pcmd, pparams, psender);

        case 'requeryPopups':
        case 'captionLast':
        case 'closeAll':
            return action_popups(pcmd, pparams, psender);

        case 'amdReport':
        case 'popupClosed':
            return action_report(pcmd, pparams, psender);

        case 'clearLog':
            return action_log(pcmd, pparams, psender);

        default:
            console.warn('crudbuttons_entry.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

/** 목록 — 서버에서 다시 읽는다(pparams.code를 주면 그 행을 다시 고른다). */
function action_list(pcmd, pparams, psender) {
    switch (pcmd) {
        case 'requery':
            ceRequery(pparams.code);
            return;
    }
}

/** 입력폼 열기 — 신규는 바로, 나머지는 목록에서 고른 행으로 연다. */
function action_open(pcmd, pparams, psender) {
    var code;

    if (pcmd === 'openNew') {
        ceOpen(null);
        return;
    }

    // 여기부터는 목록에서 고른 행이 있어야 한다.
    code = ceRequirePick();
    if (code === null) {
        return;
    }

    switch (pcmd) {
        case 'openSelected':
            ceOpen(code);
            return;
        case 'openSelectedModeless':
            ceOpen(code, { modal: false, animation: 'none' });
            return;
        case 'openSelectedLarge':
            ceOpen(code, { animation: 'large' });
            return;
        case 'openTwo':
            ceOpenTwo(code);
            return;
        case 'openTab':
            window.open(CE_FORM_URL + '?code=' + encodeURIComponent(code), '_blank');
            return;
    }
}

/** 열린 팝업 다루기 — requery 보내기, 캡션 바꾸기, 모두 닫기. */
function action_popups(pcmd, pparams, psender) {
    switch (pcmd) {
        case 'requeryPopups':
            ceRequeryPopups();
            return;
        case 'captionLast':
            ceCaptionLast();
            return;
        case 'closeAll':
            ceCloseAll();
            return;
    }
}

/**
 * 띄운 쪽에서 알려옴.
 *   amdReport   — 입력폼의 psender.report() → { ptype, payload, popupId }
 *   popupClosed — Popup의 onClose → { id }
 */
function action_report(pcmd, pparams, psender) {
    switch (pcmd) {
        case 'amdReport':
            ceAmdReport(pparams.ptype, pparams.payload, pparams.popupId);
            return;
        case 'popupClosed':
            cePopupClosed(pparams.id);
            return;
    }
}

/** 로그 — 지우기. */
function action_log(pcmd, pparams, psender) {
    switch (pcmd) {
        case 'clearLog':
            document.getElementById('ce-log').textContent = '';
            return;
    }
}

// ── 시작 ──

/** 버튼 하나 — 누르면 그 버튼의 data-pcmd로 action을 부른다. */
function ceWireButton(pbutton) {
    pbutton.addEventListener('click', function () {
        action(pbutton.getAttribute('data-pcmd'), {}, window);
    });
}

/** 화면이 뜰 때 한 번 — 버튼/목록 이벤트를 달고 목록을 읽는다. */
function ceStart() {
    var buttons = document.querySelectorAll('.ce-buttons button');
    var list = document.getElementById('ce-list');
    var i;

    for (i = 0; i < buttons.length; i++) {
        ceWireButton(buttons[i]);
    }

    // 목록에서 고르면(select) 바로 연다.
    list.addEventListener('change', function () {
        if (list.value === '') {
            return;
        }
        // 키보드 화살표로 옮길 때마다 팝업이 또 열리지 않게 포커스를 뺀다.
        list.blur();
        action('openSelected', {}, window);
    });

    action('requery');
}

ceStart();
</script>
</body>
</html>
