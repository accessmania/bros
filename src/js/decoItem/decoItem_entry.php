<?php
declare(strict_types=1);

/**
 * decoItem_entry — decoItem.js 자기 검증용 페이지. `ChosungGrid_entry.php`/
 * `NumberKeypad_entry.php`와 동일한 "모듈 옆에 자기 검증 페이지를 둔다"는
 * 관례를 따른다.
 *
 * [2026-09-12 개명] 이전 이름 `deco상품_entry.php` — "한글파일명 대신
 * 영문파일명으로" 지시로 `decoItem_entry.php`로 개명(내용/로직은 동일).
 *
 * 두 가지 방식으로 다 열어볼 수 있게 만들었다 — 기존 위젯들과 완전히
 * 같은 판별 방식:
 *
 *   1. **직접 열기**(주소창에 이 파일을 바로 입력) — `window.parent`가
 *      없거나 `action()`이 없어 `sender`가 `null`이 된다. 이때는
 *      `onSelect`(로컬 콜백)만 동작해 이 페이지 자신의 로그 영역에
 *      선택 결과를 남긴다.
 *   2. **Popup(iframe)으로 열기** — `DecoItem.open({...})`로 열리면
 *      `window.parent`가 그 Popup을 만든 화면이 되고, 거기에 `action()`
 *      이 있으면 `sender`로 잡혀 `sender.action(callback이름, ...)`가
 *      실제로 호출된다 — `4_9`(상품선택) 데모가 이 경로를 쓴다.
 *
 * `?pageUrl=`/`?fetchUrl=`/`?callback=` 쿼리스트링으로 마크업 페이지/
 * 조회 URL/콜백 이름을 바꿔볼 수 있다. `?pageUrl=`(없으면
 * `'decoItem.php'`) — [2026-09-12 추가] 좌측 분류 열+검색바+그리드
 * 마크업을 통째로 그려 보내는 페이지(위 decoItem.js docblock의
 * "분류 목록: JS fetch/JSON이 아니라 decoItem.php가..." 절 참고).
 * `?callback=`(없으면 `'callback_상품_선택'`)은
 * `DecoItem.open()`의 `callback` 옵션이 여기로 온 것 — 받은 그대로
 * `new DecoItem({callback: ...})`에 넘겨 그대로 돌려준다(md/2026-09-11-
 * action-callback-이름부여-규칙.md 참고). `sender`만 아래 JS가
 * `window.parent`로 판단해 전달한다(직렬화할 수 없는 값이라 URL이
 * 아니라 JS에서 판단 — 다른 위젯과 동일한 이유).
 */
$pageUrl = isset($_GET['pageUrl']) && $_GET['pageUrl'] !== ''
    ? (string) $_GET['pageUrl']
    : 'decoItem.php';
$fetchUrl = isset($_GET['fetchUrl']) && $_GET['fetchUrl'] !== ''
    ? (string) $_GET['fetchUrl']
    : 'decoItem_fetch.php';
$callback = isset($_GET['callback']) && $_GET['callback'] !== '' ? (string) $_GET['callback'] : 'callback_상품_선택';

header('Content-Type: text/html; charset=utf-8');

// [2026-09-12 추가] 우측 초성 키패드(ChosungKeypad, 새로 안 만들고 기존
// 모듈 재사용) — ChosungGrid_entry.php와 동일한 관례로 decoItem.js보다
// 먼저 로드해둬야 한다(decoItem.js는 이 모듈을 자동으로 안 불러옴).
$keypadJsVersion = filemtime(__DIR__ . '/../ChosungKeypad/ChosungKeypad.js');
$decoItemJsVersion = filemtime(__DIR__ . '/decoItem.js');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>decoItem.js 시험 — 상품선택 팝업</title>
<style>
    html, body { margin: 0; height: 100%; box-sizing: border-box; overflow: hidden; }
    body {
        font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
        color: #1f2d3d;
        padding: 10px;
        display: flex;
        flex-direction: column;
        height: 100%;
    }
    .dpe-body { flex: 1; min-height: 0; }
    #dp-root { height: 100%; }
    /* [ChosungGrid_entry.php/NumberKeypad_entry.php와 동일한 규칙] Popup
       (iframe)으로 열렸을 때(=sender가 잡혀 실제 콜백이 부모로 가는 정상
       경로)는 이 로그 영역이 화면 공간만 차지하는 군더더기라, JS가
       sender===null(직접 열었을 때)일 때만 hidden을 해제한다(기본은 숨김). */
    .dpe-log-wrap { flex: none; margin-top: 6px; }
    .dpe-log {
        max-height: 90px; overflow-y: auto; background: #fafafa;
        border: 1px solid #eee; border-radius: 6px; padding: 6px 10px;
        font-family: Consolas, 'Courier New', monospace; font-size: 0.76rem; color: #3c4043;
    }
</style>
</head>
<body>
<div class="dpe-body">
    <div id="dp-root"></div>
</div>
<div class="dpe-log-wrap" id="dpe-log-wrap" hidden>
    <div class="dpe-log" id="dpe-log"></div>
</div>

<script src="../ChosungKeypad/ChosungKeypad.js?v=<?= $keypadJsVersion ?>"></script>
<script src="decoItem.js?v=<?= $decoItemJsVersion ?>"></script>
<script>
(function () {
    var logEl = document.getElementById('dpe-log');
    function log(message) {
        var line = document.createElement('div');
        var now = new Date();
        line.textContent = '[' + now.getHours() + ':' + now.getMinutes() + ':' + now.getSeconds() + '] ' + message;
        logEl.insertBefore(line, logEl.firstChild);
    }

    // ChosungGrid_entry.php/NumberKeypad_entry.php와 동일한 방어적 판별.
    // 나를 띄운 Popup에 onAction이 있으면 그 Popup이 sender(Popup.senderFor()
    // 참고), 없으면 기존처럼 부모의 전역 action()이 sender.
    var sender = null;
    var popupSender = (window.parent && window.parent !== window && window.parent.Popup)
        ? window.parent.Popup.senderFor(window) : null;
    if (popupSender) {
        sender = popupSender;
    } else if (window.parent && window.parent !== window && typeof window.parent.action === 'function') {
        sender = window.parent;
    } else {
        document.getElementById('dpe-log-wrap').hidden = false;
    }

    var callbackName = <?= json_encode($callback) ?>;

    new DecoItem({
        containerId: 'dp-root',
        pageUrl: <?= json_encode($pageUrl) ?>,
        fetchUrl: <?= json_encode($fetchUrl) ?>,
        sender: sender,
        callback: callbackName,
        onSelect: function (row) {
            log('선택: ' + row.상품명 + ' (현금가 ' + row.현금가 + ' / 카드가 ' + row.카드가 + ')' +
                (sender ? ' (부모에도 ' + callbackName + ' 전달됨)' : ' (부모 없음 — 로컬 로그만)'));
        },
    });
})();
</script>
</body>
</html>
