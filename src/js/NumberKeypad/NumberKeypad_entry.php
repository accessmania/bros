<?php
declare(strict_types=1);

/**
 * NumberKeypad_entry — NumberKeypad.js 자기 검증용 페이지. `ChosungGrid_entry.php`
 * 와 동일한 "모듈 옆에 자기 검증 페이지를 둔다"는 관례를 따른다.
 *
 * 두 가지 방식으로 다 열어볼 수 있게 만들었다 — `ChosungGrid_entry.php`와
 * 완전히 같은 판별 방식:
 *
 *   1. **직접 열기**(주소창에 이 파일을 바로 입력) — `window.parent`가
 *      없거나 `action()`이 없어 `sender`가 `null`이 된다. 이때는
 *      `onConfirm`/`onClear`(로컬 콜백)만 동작해 이 페이지 자신의 로그
 *      영역에 결과를 남긴다.
 *   2. **Popup(iframe)으로 열기** — `NumberKeypad.open({...})`로 열리면
 *      `window.parent`가 그 Popup을 만든 화면이 되고, 거기에 `action()`
 *      이 있으면 `sender`로 잡혀 `sender.action(callback이름, ...)`가
 *      실제로 호출된다 — `4_6`(숫자키패드) 데모가 이 경로를 쓴다.
 *
 * `?callback=`/`?caption=`/`?initial=`/`?maxLength=`/`?allowDecimal=`/
 * `?displayMode=`(2026-09-16 추가 — `'number'`(기본)/`'plain'`/
 * `'password'`, 그 밖의 값은 화이트리스트 검증에서 걸러 기본값으로
 * 되돌림, `NumberKeypad.js`의 `_DISPLAY_MODES` 참고)
 * 쿼리스트링으로 실제 콜백 이름/안내 문구/초기값/자릿수 제한/소수점
 * 허용 여부/디스플레이 정렬·포맷을 바꿔볼 수 있다. `?callback=`(없으면
 * `'callback_숫자키패드'`)은 `NumberKeypad.open()`의 `callback` 옵션이
 * 여기로 온 것 — 이 화면은 재사용 가능한 위젯이라 그 이름의 뜻은
 * 몰라도 되고, 받은 그대로 `new NumberKeypad({callback: ...})`에 넘겨
 * 그대로 돌려주기만 한다(md/2026-09-11-action-callback-이름부여-규칙.md
 * 참고). [2026-09-11 추가] `?caption=`은 `NumberKeypad.open()`의
 * `caption` 옵션이 여기로 온 것 — Popup 창 제목표시줄(`ptitle`)과는
 * 별개로, 위젯 디스플레이 위에 "무엇을 입력하는 중인지" 안내 문구를
 * 보여준다("팝업 띄울 때 캡션도 매개변수로" 지시). `sender`만 아래
 * JS가 `window.parent`로 판단해 전달한다(직렬화할 수 없는 값이라
 * URL이 아니라 JS에서 판단 — ChosungGrid.js와 동일한 이유).
 */
$callback = isset($_GET['callback']) && $_GET['callback'] !== '' ? (string) $_GET['callback'] : 'callback_숫자키패드';
$caption = isset($_GET['caption']) ? (string) $_GET['caption'] : '';
$initial = isset($_GET['initial']) ? (string) $_GET['initial'] : '';
$maxLength = isset($_GET['maxLength']) && $_GET['maxLength'] !== '' ? (int) $_GET['maxLength'] : 12;
$allowDecimal = !(isset($_GET['allowDecimal']) && $_GET['allowDecimal'] === '0');
// [2026-09-16 추가] "입력 매개변수에 따라 정렬/콤마/비밀번호 표시를
// 조절 가능하게" 지시 — 화이트리스트(NumberKeypad.js의 _DISPLAY_MODES와
// 동일한 3개 값) 밖의 값은 무시하고 기본값('number')으로 둔다(URL을
// 직접 조작해 이상한 값을 넣어도 NumberKeypad.js 생성자가 어차피 같은
// 화이트리스트로 다시 걸러내지만, 여기서도 명시적으로 좁혀 그대로
// 넘긴다).
$displayModeWhitelist = ['number', 'plain', 'password'];
$displayMode = 'number';
if (isset($_GET['displayMode']) && in_array($_GET['displayMode'], $displayModeWhitelist, true)) {
    $displayMode = (string) $_GET['displayMode'];
}

header('Content-Type: text/html; charset=utf-8');

$numberKeypadJsVersion = filemtime(__DIR__ . '/NumberKeypad.js');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NumberKeypad.js 시험 — 숫자 입력 팝업</title>
<style>
    html, body { margin: 0; height: 100%; box-sizing: border-box; }
    body {
        font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
        color: #1f2d3d;
        padding: 12px;
        display: flex;
        flex-direction: column;
        height: 100%;
    }
    .nke-body { flex: 1; min-height: 0; display: flex; justify-content: center; }
    /* [ChosungGrid_entry.php와 동일한 규칙] Popup(iframe)으로 열렸을 때
       (=sender가 잡혀 실제 콜백이 부모로 가는 정상 경로)는 이 로그
       영역이 화면 공간만 차지하는 군더더기라, JS가 sender===null(직접
       열었을 때)일 때만 hidden을 해제한다(기본은 숨김). */
    .nke-log-wrap { flex: none; margin-top: 6px; }
    .nke-log {
        max-height: 90px; overflow-y: auto; background: #fafafa;
        border: 1px solid #eee; border-radius: 6px; padding: 6px 10px;
        font-family: Consolas, 'Courier New', monospace; font-size: 0.76rem; color: #3c4043;
    }
</style>
</head>
<body>
<div class="nke-body">
    <div id="nk-root"></div>
</div>
<div class="nke-log-wrap" id="nke-log-wrap" hidden>
    <div class="nke-log" id="nke-log"></div>
</div>

<script src="NumberKeypad.js?v=<?= $numberKeypadJsVersion ?>"></script>
<script>
(function () {
    var logEl = document.getElementById('nke-log');
    function log(message) {
        var line = document.createElement('div');
        var now = new Date();
        line.textContent = '[' + now.getHours() + ':' + now.getMinutes() + ':' + now.getSeconds() + '] ' + message;
        logEl.insertBefore(line, logEl.firstChild);
    }

    // ChosungGrid_entry.php와 동일한 방어적 판별.
    var sender = null;
    if (window.parent && window.parent !== window && typeof window.parent.action === 'function') {
        sender = window.parent;
    } else {
        document.getElementById('nke-log-wrap').hidden = false;
    }

    var callbackName = <?= json_encode($callback) ?>;

    new NumberKeypad({
        containerId: 'nk-root',
        caption: <?= json_encode($caption) ?>,
        initial: <?= json_encode($initial) ?>,
        maxLength: <?= json_encode($maxLength) ?>,
        allowDecimal: <?= json_encode($allowDecimal) ?>,
        displayMode: <?= json_encode($displayMode) ?>,
        showQuitButton: true,
        sender: sender,
        callback: callbackName,
        onConfirm: function (value) {
            log('확인: value=' + value + (sender ? ' (부모에도 ' + callbackName + ' 전달됨)' : ' (부모 없음 — 로컬 로그만)'));
        },
        onQuit: function () {
            log('취소(Quit)됨' + (sender ? ' (부모에도 ' + callbackName + ' 전달됨)' : ' (부모 없음 — 로컬 로그만)'));
        },
    });
})();
</script>
</body>
</html>
