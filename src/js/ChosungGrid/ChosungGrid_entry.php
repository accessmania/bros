<?php
declare(strict_types=1);

/**
 * ChosungGrid_entry — ChosungGrid.js 자기 검증용 페이지. `Resize_entry.php`/
 * `Domain_entry.php`와 같은 "모듈 옆에 자기 검증 페이지를 둔다"는 관례를
 * 따른다.
 *
 * 두 가지 방식으로 다 열어볼 수 있게 만들었다 — 매번 다시 만들지 않고
 * 하나의 페이지가 두 역할을 겸한다:
 *
 *   1. **직접 열기**(주소창에 이 파일을 바로 입력) — `window.parent`가
 *      없거나 `action()`이 없어(=iframe이 아니거나, 있어도 이 화면을
 *      모르는 부모) `sender`가 `null`이 된다. 이때는 `onSelect`(로컬
 *      콜백)만 동작해 이 페이지 자신의 로그 영역에 선택 결과를 남긴다.
 *   2. **Popup(iframe)으로 열기** — `ChosungGrid.open({...})`(또는 그와
 *      동일하게 `new Popup({iframeUrl: '...ChosungGrid_entry.php?...'})`)
 *      로 열리면 `window.parent`가 그 Popup을 만든 화면이 되고, 거기에
 *      `action()`이 있으면 `sender`로 잡혀 `sender.action(callback이름,
 *      ...)`가 실제로 호출된다 — `4_5`(초성그리드) 데모가 이 경로를 쓴다.
 *
 * `?fetchUrl=`/`?type=`/`?callback=` 쿼리스트링으로 실제 조회 URL/꼬리표/
 * 콜백 이름을 바꿔볼 수 있다. [2026-09-11 추가] `?callback=`(없으면
 * `'callback_초성검색'`)은 `ChosungGrid.open()`의 `callback` 옵션이
 * 여기로 온 것 — 이 화면은 재사용 가능한 위젯이라 그 이름의 뜻은
 * 몰라도 되고, 받은 그대로 `new ChosungGrid({callback: ...})`에 넘겨
 * 그대로 돌려주기만 한다(md/2026-09-11-action-callback-이름부여-규칙.md
 * 참고). `sender`(2-2)만 아래 JS가 `window.parent`로 판단해 전달한다 —
 * 직렬화할 수 없는 값(살아있는 window 참조)이라 URL이 아니라 JS에서
 * 판단하는 이유는 `ChosungGrid.js` 상단 docblock 참고.
 *
 * [2026-09-09 수정] "fetch url을 openPicker 할 때 노출되게" 지시로
 * `ChosungGrid.open()`이 이제 `fetchUrl`을 그대로 여기까지 넘겨준다 —
 * 호출부(예: `content4_5.php`의 `openPicker()`)가 정확히 어느 URL을
 * 여는지 자기 코드 안에서 바로 보인다. `?fetchUrl=`이 없으면(예:
 * 이 파일을 직접 열어 자기 검증할 때) `?source=`(기본값 `'직원'`)로
 * `ChosungGrid_fetch.php?source=...`를 대신 만든다 — `ChosungGrid.js`의
 * `_defaultFetchUrl()`과 같은 규칙.
 */
$fetchUrl = isset($_GET['fetchUrl']) && $_GET['fetchUrl'] !== ''
    ? (string) $_GET['fetchUrl']
    : 'ChosungGrid_fetch.php?source=' . rawurlencode(isset($_GET['source']) ? (string) $_GET['source'] : '직원');
$type = isset($_GET['type']) ? (string) $_GET['type'] : '테스트';
// [2026-09-11 추가] "여는 쪽이 콜백 이름을 지어서 넘긴다" 규칙 —
// ChosungGrid.open()의 callback 옵션이 여기로 온다(생략 시 기존 이름).
$callback = isset($_GET['callback']) && $_GET['callback'] !== '' ? (string) $_GET['callback'] : 'callback_초성검색';

header('Content-Type: text/html; charset=utf-8');

$keypadJsVersion = filemtime(__DIR__ . '/../ChosungKeypad/ChosungKeypad.js');
$chosungGridJsVersion = filemtime(__DIR__ . '/ChosungGrid.js');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ChosungGrid.js 시험 — 초성검색 선택 팝업</title>
<style>
    html, body { margin: 0; height: 100%; box-sizing: border-box; }
    body {
        font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
        color: #1f2d3d;
        /* [2026-09-09 수정] "초성검색 오른쪽 여백은 1cm만" / "창의 높이를
           Clear 버튼으로부터 1cm 여백만 나오게" 지시 — 위/왼쪽은 기존
           10px 그대로 두고, 오른쪽/아래만 1cm로 키워 그 여백을 만든다
           (그리드/키패드 폭·높이 자체는 이 여백을 감안해
           ChosungGrid.js의 .cg-grid-col(60%)와 ChosungGrid.open()의
           iframeWidth/iframeHeight 기본값(548×422)이 서로 맞물리도록
           역산해뒀다 — 셋 중 하나만 바꾸면 다시 안 맞을 수 있음). */
        padding: 10px 1cm 1cm 10px;
        display: flex;
        flex-direction: column;
        height: 100%;
    }
    .cge-body { flex: 1; min-height: 0; }
    /* 지시(1) — 좌측 aggrid, 우측 초성검색. ChosungGrid.js가 .cg-root
       안에 두 컬럼을 직접 채운다(여기서는 높이만 내려줌). */
    #cg-root { height: 100%; }
    /* [2026-09-09 수정] "불필요한 문구는 제거" 지시 — 이 로그 영역은
       Popup(iframe)으로 열렸을 때(=sender가 잡혀 실제 콜백이 부모로
       가는 정상 경로)는 화면 공간만 차지하는 군더더기라, JS가
       sender===null(직접 열었을 때)일 때만 hidden을 해제한다(기본은
       숨김 — 아래 <div hidden> 참고). */
    .cge-log-wrap { flex: none; margin-top: 6px; }
    .cge-log {
        max-height: 90px; overflow-y: auto; background: #fafafa;
        border: 1px solid #eee; border-radius: 6px; padding: 6px 10px;
        font-family: Consolas, 'Courier New', monospace; font-size: 0.76rem; color: #3c4043;
    }
</style>
</head>
<body>
<div class="cge-body">
    <div id="cg-root"></div>
</div>
<div class="cge-log-wrap" id="cge-log-wrap" hidden>
    <div class="cge-log" id="cge-log"></div>
</div>

<script src="../ChosungKeypad/ChosungKeypad.js?v=<?= $keypadJsVersion ?>"></script>
<script src="ChosungGrid.js?v=<?= $chosungGridJsVersion ?>"></script>
<script>
(function () {
    var logEl = document.getElementById('cge-log');
    function log(message) {
        var line = document.createElement('div');
        var now = new Date();
        line.textContent = '[' + now.getHours() + ':' + now.getMinutes() + ':' + now.getSeconds() + '] ' + message;
        logEl.insertBefore(line, logEl.firstChild);
    }

    // ChosungGrid_left.php류 자식들이 부모를 찾는 것과 동일한 방어적
    // 판별 — window.parent가 있고 자기 자신이 아니고 action()이 있을
    // 때만 실제 sender로 쓴다(attendance_entry.php/deco_amd_amd.php와
    // 동일한 패턴).
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
        // 직접 열었을 때만(=sender 없음) 로컬 로그 영역을 보여준다 —
        // Popup으로 열렸을 때는 콜백이 부모로 가므로 여기서 또 보여줄
        // 필요가 없다(불필요한 문구 제거).
        document.getElementById('cge-log-wrap').hidden = false;
    }

    var callbackName = <?= json_encode($callback) ?>;

    new ChosungGrid({
        containerId: 'cg-root',
        fetchUrl: <?= json_encode($fetchUrl) ?>,
        sender: sender,
        type: <?= json_encode($type) ?>,
        callback: callbackName,
        onSelect: function (key, display) {
            log('선택: key=' + key + ', display=' + display + (sender ? ' (부모에도 ' + callbackName + ' 전달됨)' : ' (부모 없음 — 로컬 로그만)'));
        },
    });
})();
</script>
</body>
</html>
