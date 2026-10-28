<?php
declare(strict_types=1);

/**
 * TimePicker_entry — TimePicker.js 자기 검증용 페이지. `NumberKeypad_entry.php`/
 * `ChosungGrid_entry.php`와 동일한 "모듈 옆에 자기 검증 페이지를 둔다"는 관례를 따른다.
 *
 * 두 가지 방식으로 다 열어볼 수 있게 만들었다 — 기존 위젯들과 완전히 같은 판별 방식:
 *
 *   1. **직접 열기**(주소창에 이 파일을 바로 입력) — `window.parent`가
 *      없거나 `action()`이 없어 `sender`가 `null`이 된다. 이때는
 *      `onConfirm`/`onCancel`(로컬 콜백)만 동작해 이 페이지 자신의 로그
 *      영역에 결과를 남긴다.
 *   2. **Popup(iframe)으로 열기** — `TimePicker.open({...})`로 열리면
 *      `window.parent`가 그 Popup을 만든 화면이 되고, 거기에 `action()`
 *      이 있으면 `sender`로 잡혀 `sender.action(callback이름, ...)`가
 *      실제로 호출된다 — `4_8`(시분초) 데모가 이 경로를 쓴다.
 *
 * `?callback=`/`?initialHour=`/`?initialMinute=` 쿼리스트링으로 실제
 * 콜백 이름/초기 선택값을 바꿔볼 수 있다. `?callback=`(없으면
 * `'callback_시분초'`)은 `TimePicker.open()`의 `callback` 옵션이 여기로
 * 온 것 — 이 화면은 재사용 가능한 위젯이라 그 이름의 뜻은 몰라도 되고,
 * 받은 그대로 `new TimePicker({callback: ...})`에 넘겨 그대로 돌려주기만
 * 한다(md/2026-09-11-action-callback-이름부여-규칙.md 참고).
 * `?initialHour=`/`?initialMinute=`는 **둘 다** 유효한 정수(시:0~23,
 * 분:0~59)로 와야 초기값으로 인정한다 — 하나만 오거나 형식이 안 맞으면
 * 무시하고, `TimePicker` 생성자가 스스로 현재 시각으로 대체한다(지시 3
 * — "선택 컨트롤에 값이 없으면 현재 시각"). `sender`만 아래 JS가
 * `window.parent`로 판단해 전달한다(직렬화할 수 없는 값이라 URL이 아니라
 * JS에서 판단 — 다른 위젯과 동일한 이유).
 */
$callback = isset($_GET['callback']) && $_GET['callback'] !== '' ? (string) $_GET['callback'] : 'callback_시분초';

$initialHour = null;
$initialMinute = null;
if (isset($_GET['initialHour']) && isset($_GET['initialMinute'])
    && ctype_digit((string) $_GET['initialHour']) && ctype_digit((string) $_GET['initialMinute'])) {
    $hourCandidate = (int) $_GET['initialHour'];
    $minuteCandidate = (int) $_GET['initialMinute'];
    if ($hourCandidate >= 0 && $hourCandidate <= 23 && $minuteCandidate >= 0 && $minuteCandidate <= 59) {
        $initialHour = $hourCandidate;
        $initialMinute = $minuteCandidate;
    }
}

header('Content-Type: text/html; charset=utf-8');

$timePickerJsVersion = filemtime(__DIR__ . '/TimePicker.js');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TimePicker.js 시험 — 시분초 팝업</title>
<style>
    /* [2026-09-12 추가] "팝업창에 스크롤바가 안생기게" 지시 — overflow:
       hidden을 html/body 둘 다에 걸어 스크롤바 자체가 생길 여지를
       없앤다. `TimePicker.open()`의 기본 iframeWidth/iframeHeight가
       내용을 넉넉히 담도록 잡혀 있어(같은 파일의 "남은 이슈" 참고),
       평소엔 잘림 없이 그대로 보인다 — 혹시 부족하면 그 두 숫자만
       키우면 된다(overflow:hidden을 풀 필요는 없음). */
    html, body { margin: 0; height: 100%; box-sizing: border-box; overflow: hidden; }
    body {
        font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
        color: #1f2d3d;
        padding: 12px;
        display: flex;
        flex-direction: column;
        height: 100%;
    }
    /* align-items:center로 세로 방향도 가운데 — 위 tp-columns의
       justify-content:center(가로)와 짝을 이뤄 팝업 안에서 위젯 전체가
       중앙에 오게 한다. */
    .tpe-body { flex: 1; min-height: 0; display: flex; justify-content: center; align-items: center; overflow: hidden; }
    /* [ChosungGrid_entry.php/NumberKeypad_entry.php와 동일한 규칙] Popup
       (iframe)으로 열렸을 때(=sender가 잡혀 실제 콜백이 부모로 가는 정상
       경로)는 이 로그 영역이 화면 공간만 차지하는 군더더기라, JS가
       sender===null(직접 열었을 때)일 때만 hidden을 해제한다(기본은 숨김). */
    .tpe-log-wrap { flex: none; margin-top: 6px; }
    .tpe-log {
        max-height: 90px; overflow-y: auto; background: #fafafa;
        border: 1px solid #eee; border-radius: 6px; padding: 6px 10px;
        font-family: Consolas, 'Courier New', monospace; font-size: 0.76rem; color: #3c4043;
    }
</style>
</head>
<body>
<div class="tpe-body">
    <div id="tp-root"></div>
</div>
<div class="tpe-log-wrap" id="tpe-log-wrap" hidden>
    <div class="tpe-log" id="tpe-log"></div>
</div>

<script src="TimePicker.js?v=<?= $timePickerJsVersion ?>"></script>
<script>
(function () {
    var logEl = document.getElementById('tpe-log');
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
        document.getElementById('tpe-log-wrap').hidden = false;
    }

    var callbackName = <?= json_encode($callback) ?>;
    var initialHour = <?= json_encode($initialHour) ?>;
    var initialMinute = <?= json_encode($initialMinute) ?>;
    var initial = (initialHour !== null && initialMinute !== null)
        ? { hour: initialHour, minute: initialMinute }
        : null;

    new TimePicker({
        containerId: 'tp-root',
        initial: initial,
        sender: sender,
        callback: callbackName,
        onConfirm: function (value) {
            log('확인: ' + value.hour + '시 ' + value.minute + '분' + (sender ? ' (부모에도 ' + callbackName + ' 전달됨)' : ' (부모 없음 — 로컬 로그만)'));
        },
        onCancel: function () {
            log('취소됨' + (sender ? ' (부모에도 ' + callbackName + ' 전달됨)' : ' (부모 없음 — 로컬 로그만)'));
        },
    });
})();
</script>
</body>
</html>
