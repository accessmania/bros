<?php
declare(strict_types=1);

/**
 * Memo_entry — Memo.js 자기 검증용 페이지. `ChosungGrid_entry.php`/
 * `NumberKeypad_entry.php`와 동일한 "모듈 옆에 자기 검증 페이지를 둔다"는
 * 관례를 따른다.
 *
 * 두 가지 방식으로 다 열어볼 수 있게 만들었다 — 위 두 entry와 완전히
 * 같은 판별 방식:
 *
 *   1. **직접 열기**(주소창에 이 파일을 바로 입력) — `window.parent`가
 *      없거나 `action()`이 없어 `sender`가 `null`이 된다. 이때는
 *      `onSave`/`onCancel`(로컬 콜백)만 동작해 이 페이지 자신의 로그
 *      영역에 결과를 남긴다.
 *   2. **Popup(iframe)으로 열기** — `Memo.open({...})`로 열리면
 *      `window.parent`가 그 Popup을 만든 화면이 되고, 거기에 `action()`
 *      이 있으면 `sender`로 잡혀 `sender.action(callback이름, ...)`가
 *      실제로 호출된다 — `4_7`(메모연습) 데모가 이 경로를 쓴다.
 *
 * `?callback=`/`?caption=`/`?initial=` 쿼리스트링으로 실제 콜백 이름/
 * 안내 문구/초기값을 바꿔볼 수 있다. `?callback=`(없으면
 * `'callback_Memo_메모'`)은 `Memo.open()`의 `callback` 옵션이 여기로
 * 온 것 — 이 화면은 재사용 가능한 위젯이라 그 이름의 뜻은 몰라도 되고,
 * 받은 그대로 `new Memo({callback: ...})`에 넘겨 그대로 돌려주기만
 * 한다(md/2026-09-11-action-callback-이름부여-규칙.md 참고). `sender`만
 * 아래 JS가 `window.parent`로 판단해 전달한다(직렬화할 수 없는 값이라
 * URL이 아니라 JS에서 판단 — ChosungGrid.js와 동일한 이유).
 */
$callback = isset($_GET['callback']) && $_GET['callback'] !== '' ? (string) $_GET['callback'] : 'callback_Memo_메모';
$caption = isset($_GET['caption']) ? (string) $_GET['caption'] : '';
$initial = isset($_GET['initial']) ? (string) $_GET['initial'] : '';

header('Content-Type: text/html; charset=utf-8');

$memoJsVersion = filemtime(__DIR__ . '/Memo.js');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Memo.js 시험 — 메모 입력 팝업</title>
<style>
    html, body { margin: 0; height: 100%; box-sizing: border-box; }
    body {
        font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
        color: #1f2d3d;
        padding: 12px;
        display: flex;
        flex-direction: column;
        height: 100%;
        box-sizing: border-box;
    }
    .me-body { flex: 1; min-height: 0; display: flex; }
    #memo-root { flex: 1; display: flex; flex-direction: column; min-height: 0; }
    /* [ChosungGrid_entry.php/NumberKeypad_entry.php와 동일한 규칙]
       Popup(iframe)으로 열렸을 때(=sender가 잡혀 실제 콜백이 부모로
       가는 정상 경로)는 이 로그 영역이 화면 공간만 차지하는 군더더기라,
       JS가 sender===null(직접 열었을 때)일 때만 hidden을 해제한다
       (기본은 숨김). */
    .me-log-wrap { flex: none; margin-top: 6px; }
    .me-log {
        max-height: 90px; overflow-y: auto; background: #fafafa;
        border: 1px solid #eee; border-radius: 6px; padding: 6px 10px;
        font-family: Consolas, 'Courier New', monospace; font-size: 0.76rem; color: #3c4043;
    }
</style>
</head>
<body>
<div class="me-body">
    <div id="memo-root"></div>
</div>
<div class="me-log-wrap" id="me-log-wrap" hidden>
    <div class="me-log" id="me-log"></div>
</div>

<script src="Memo.js?v=<?= $memoJsVersion ?>"></script>
<script>
(function () {
    var logEl = document.getElementById('me-log');
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
        document.getElementById('me-log-wrap').hidden = false;
    }

    var callbackName = <?= json_encode($callback) ?>;

    new Memo({
        containerId: 'memo-root',
        caption: <?= json_encode($caption) ?>,
        initial: <?= json_encode($initial) ?>,
        sender: sender,
        callback: callbackName,
        onSave: function (value) {
            log('저장: value=' + value + (sender ? ' (부모에도 ' + callbackName + ' 전달됨)' : ' (부모 없음 — 로컬 로그만)'));
        },
        onCancel: function () {
            log('취소됨' + (sender ? ' (부모에도 ' + callbackName + ' 전달됨)' : ' (부모 없음 — 로컬 로그만)'));
        },
    });
})();
</script>
</body>
</html>
