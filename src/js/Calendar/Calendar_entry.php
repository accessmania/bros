<?php
declare(strict_types=1);

/**
 * Calendar_entry — Calendar.js 자기 검증용 페이지. `Memo_entry.php`/
 * `NumberKeypad_entry.php`/`ChosungGrid_entry.php`와 동일한 "모듈 옆에
 * 자기 검증 페이지를 둔다"는 관례를 따른다.
 *
 * [2026-09-17 신설] "입금 팝업 안에서 연 위젯이 그 팝업 iframe 박스를
 * 못 벗어난다" 문제로 `Calendar.js`에 `static open()`이 새로 생기면서
 * 함께 만들었다(`Calendar.js`에 그 이유가 자세히 적혀 있다).
 *
 * 두 가지 방식으로 다 열어볼 수 있게 만들었다 — 다른 entry들과 완전히
 * 같은 판별 방식:
 *
 *   1. **직접 열기**(주소창에 이 파일을 바로 입력) — `window.parent`가
 *      없거나 `action()`이 없어 `sender`가 `null`이 된다. 이때는
 *      결과가 부모로 안 가고 이 페이지 자신의 `action()`이 받아 버린다.
 *   2. **Popup(iframe)으로 열기** — `Calendar.open({...})`로 열리면
 *      `window.parent`가 그 Popup을 만든 화면이 되고, 거기에 `action()`
 *      이 있으면 `sender`로 잡혀 `sender.action(callback이름, ...)`가
 *      실제로 호출된다.
 *
 * `?callback=`/`?selectionMode=`/`?showYearMonthList=`/`?memoPopup=0`/`?initialValue=`
 * 쿼리스트링으로 실제 콜백 이름/선택 모드/년월 리스트박스 여부/초기값을
 * 바꿔볼 수 있다. `selectionMode=range`일 때 `initialValue`는
 * `{"from":"...","to":"..."}` 형태의 JSON 문자열로 온다(`Calendar.open()`
 * 이 그렇게 실어 보낸다) — `single`이면 `'YYYY-MM-DD'` 문자열 그대로.
 * `sender`만 아래 JS가 `window.parent`로 판단해 전달한다(직렬화할 수
 * 없는 값이라 URL이 아니라 JS에서 판단 — 다른 entry들과 동일한 이유).
 */
$callback = isset($_GET['callback']) && $_GET['callback'] !== '' ? (string) $_GET['callback'] : 'callback_달력';
$selectionMode = isset($_GET['selectionMode']) && $_GET['selectionMode'] === 'range' ? 'range' : 'single';
$showYearMonthList = isset($_GET['showYearMonthList']) && $_GET['showYearMonthList'] === '1';
// 셀 클릭 시 메모 팝업을 기본으로 띄운다 — `?memoPopup=0`일 때만 끈다
// (날짜 선택만 하는 기존 동작).
$memoPopup = !(isset($_GET['memoPopup']) && $_GET['memoPopup'] === '0');
$initialValueRaw = isset($_GET['initialValue']) ? (string) $_GET['initialValue'] : '';

$initialValueForJs = null;
if ($initialValueRaw !== '') {
    if ($selectionMode === 'range') {
        $decoded = json_decode($initialValueRaw, true);
        if (is_array($decoded)) {
            $initialValueForJs = [
                'from' => isset($decoded['from']) ? (string) $decoded['from'] : null,
                'to' => isset($decoded['to']) ? (string) $decoded['to'] : null,
            ];
        }
    } else {
        $initialValueForJs = $initialValueRaw;
    }
}

header('Content-Type: text/html; charset=utf-8');

$calendarJsVersion = filemtime(__DIR__ . '/Calendar.js');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Calendar.js 시험 — 날짜 선택 팝업</title>
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
        /* [2026-09-17 추가] "달력 팝업이 세로 스크롤이 안 생기게" 지시 —
           `Calendar.open()`의 iframeHeight를 실제 필요 높이만큼 넉넉히
           올렸지만(그 파일 주석 참고), 브라우저별 폰트 렌더링 오차로
           그래도 몇 px 넘칠 가능성에 대비해 여기서도 확실히 막는다 —
           내용이 넘쳐도 스크롤바 대신 조용히 잘리게(스크롤바가 뜨는
           것보다 사용자 요구에 더 맞는 선택). */
        overflow: hidden;
    }
    .ce-body { flex: 1; min-height: 0; display: flex; }
    #calendar-root { flex: 1; display: flex; flex-direction: column; min-height: 0; }
    /* 달력 그리드를 맨 밑(페이지/Popup iframe 바닥)까지
       늘린다 — Calendar.js 기본 스타일은 @layer 안이라 여기 규칙이
       우선한다. 6주 행이 남는 높이를 똑같이 나눠 갖고, 칸 min-height(44px)
       보다 작아지지는 않는다. 다른 화면에 임베드된 Calendar에는 영향 없음. */
    #calendar-root .wc-cal-columns { flex: 1; min-height: 0; align-items: stretch; }
    #calendar-root .wc-cal-main { display: flex; flex-direction: column; }
    #calendar-root .wc-cal-days { flex: 1; display: flex; flex-direction: column; min-height: 0; }
    /* minmax(54px, 1fr) — 칸 높이(min-height 44 + 상하패딩 8 + 테두리 2)
       아래로는 안 줄고, 여러 줄 메모가 들어가도 행이 내용만큼 늘어나지
       않게 한다(1fr만 쓰면 가장 긴 메모에 맞춰 6주 행이 전부 커진다).
       칸은 Calendar.js 기본의 고정 높이(44px) 대신 행 높이를 꽉 채우고,
       넘치는 메모는 .wc-cal-day-memo 안에서 스크롤된다.
       contain: size — 그리드 높이가 브라우저 기준으로 "확정된 높이"가
       아니라서 1fr이 칸 내용(긴 메모) 높이를 따라 행을 키운다(실측:
       8줄 메모에 6주 행이 전부 135→173px). 칸 내용이 행 크기 계산에
       끼지 않게 막아야 메모가 칸 안에서 스크롤된다. */
    #calendar-root .wc-cal-grid { flex: 1; grid-auto-rows: minmax(54px, 1fr); }
    #calendar-root .wc-cal-day { height: auto; overflow: hidden; contain: size; }
    /* 년/월 리스트박스도 같이 늘린다 — _syncYearMonthList()가 처음
       그리드 높이로 박아 넣는 inline height를 덮어써야 해서 !important. */
    #calendar-root .wc-cal-year-select,
    #calendar-root .wc-cal-month-select { flex: 1; min-height: 0; height: auto !important; }
</style>
</head>
<body>
<div class="ce-body">
    <div id="calendar-root"></div>
</div>

<script src="Calendar.js?v=<?= $calendarJsVersion ?>"></script>
<script>
(function () {
    // Calendar는 날짜를 클릭한 순간 onSelectDate 같은 로컬 콜백 없이
    // 곧장 `sender.action(callback, {...}, window)`만 부른다(Calendar.js
    // 상단 docblock "선택 시 — sender.action(...)" 참고, Memo/NumberKeypad
    // 처럼 별도 onSave/onCancel 로컬 콜백이 없다) — 그래서 이 entry는
    // 직접 열렸을 때(부모가 없을 때)도 "무언가 선택됐다"는 걸 알아채려면
    // sender 자체를 이 문서(window)로 잡아야 한다. Popup으로 열렸을
    // 때는 진짜 부모로 잡아 정상적으로 보고되게 한다(다른 entry들과
    // 동일한 방어적 판별).
    // 나를 띄운 Popup에 onAction이 있으면 그 Popup이 sender(Popup.senderFor()
    // 참고), 없으면 기존처럼 부모의 전역 action()이 sender.
    var sender = window;
    var popupSender = (window.parent && window.parent !== window && window.parent.Popup)
        ? window.parent.Popup.senderFor(window) : null;
    var hasRealParent = Boolean(window.parent && window.parent !== window && typeof window.parent.action === 'function');
    if (popupSender) {
        sender = popupSender;
    } else if (hasRealParent) {
        sender = window.parent;
    }

    var callbackName = <?= json_encode($callback) ?>;

    // 직접 열렸을 때만 호출되는 로컬 진입점(Popup으로 열렸을 때는
    // sender가 진짜 부모라 이 함수 자체가 안 불린다). 로그 영역을
    // 없앴으므로 받기만 하고 아무것도 하지 않는다.
    window.action = function () {};

    new Calendar({
        containerId: 'calendar-root',
        selectionMode: <?= json_encode($selectionMode) ?>,
        showYearMonthList: <?= json_encode($showYearMonthList) ?>,
        memoPopup: <?= json_encode($memoPopup) ?>,
        // 메모 팝업을 쓰는 화면에서만 tCalendar_schedule의 메모를 가져와
        // 표시/저장/삭제한다(?memoPopup=0인 날짜 선택용 달력은 서버 안 감).
        // cmd(show/save/delete)는 Calendar.js가 붙인다.
        fetchUrl: <?= json_encode($memoPopup ? 'Calendar_fetch.php' : null) ?>,
        // 이 페이지는 Popup(iframe) 안의 일회성 화면이라 자기 주소창
        // 상태를 유지할 필요가 없다 — Memo/NumberKeypad entry에 없는
        // 옵션이지만 Calendar 생성자 기본값(true)을 그대로 두면 이
        // iframe 문서의 history를 계속 갈아치워 무의미하게 낭비된다.
        syncUrl: false,
        initialValue: <?= json_encode($initialValueForJs) ?>,
        sender: sender,
        callback: callbackName,
    });
})();
</script>
</body>
</html>
