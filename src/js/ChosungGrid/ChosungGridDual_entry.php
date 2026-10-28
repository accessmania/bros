<?php
declare(strict_types=1);

/**
 * ChosungGridDual_entry — 바탕화면 `매출/초성검색2.png`(초성검색 2개가
 * 나란히 붙은 화면)를 `ChosungGrid.js` 재사용만으로 구현한 조립 화면.
 * `ChosungGrid.js`/`ChosungGrid_fetch.php` 자체는 전혀 안 고치고,
 * 인스턴스를 **두 번** 만들어 이 파일이 "누가 최종인지, 무엇과
 * 합칠지"만 조율한다(md/2026-09-09-chosunggrid-신설.md "ChosungGrid를
 * 재사용 가능한가" 설계 논의 절 참고).
 *
 * ## 동작
 * - **왼쪽**은 `sender` 없이 `onSelect`만 쓴다 — 고르면 위쪽 "선택 된
 *   {leftLabel}: 값" 문구만 갱신되고, 팝업은 안 닫힌다(그림의 "선택 된
 *   룸번호: 7T(5)"와 동일).
 * - **오른쪽**도 `sender` 없이 `onSelect`만 쓰지만, 그 콜백 **안에서
 *   이 화면이 직접** 왼쪽의 마지막 선택(`leftSelection`) + 오른쪽 선택을
 *   합쳐 부모(`window.parent`, 있고 `action()`이 있을 때만)에게
 *     action(callbackName, { left: {key,display},
 *         right: {key,display}, type }, window)
 *   를 보고한다 — 오른쪽을 고르는 순간이 최종 확정(그림처럼 오른쪽
 *   목록을 고르면 팝업이 닫힘). [2026-09-11 추가] `callbackName`은
 *   `?callback=`으로 넘어온 값(없으면 `'callback_초성검색2'`) — 이
 *   화면은 재사용 가능한 위젯이라 "선택이 끝나면 무엇을 해야 하는지"를
 *   미리 못박지 않고, 여는 쪽(`ChosungGrid.openDual()`의 `callback`
 *   옵션)이 열 때 이름을 지어서 넘긴다.
 * - 어느 쪽 Quit을 누르든(둘 다 `onQuit`에 같은 함수 연결) 같은 창구로
 *   `{left:null, right:null, type, cancelled:true}`를 보고한다.
 * - **팝업을 닫는 것은 이 화면의 책임이 아니다** — `ChosungGrid_entry.php`
 *   와 동일하게, 받는 쪽(`callback_초성검색2` 케이스)이 처리 후 직접
 *   닫는다.
 * - **왼쪽 미선택 방어(2026-09-09 추가)**: `leftSelection`이 아직 없는
 *   상태(=`?leftInitialDisplay=`도 안 왔고, 왼쪽 그리드도 한 번도 안
 *   눌렸음)에서 오른쪽을 고르면, `alert()`로 `leftLabel`을 먼저 고르라고
 *   안내만 하고 `sender.action(...)` 자체를 안 부른다 — 콜백을 안
 *   보내면 받는 쪽이 닫을 일도 없으니 팝업은 자연히 열린 채로 남는다.
 *
 * `?leftFetchUrl=`/`?rightFetchUrl=`/`?leftLabel=`/`?rightLabel=`/`?type=`
 * 쿼리스트링으로 바꿔볼 수 있다. 기본값은 이번 지시 그대로(왼쪽=t룸,
 * 오른쪽=t직원) 자기 검증도 겸한다(`ChosungGrid_entry.php`가
 * `?source=`를 기본값으로 자기 검증하는 것과 같은 관례).
 */
$leftFetchUrl = isset($_GET['leftFetchUrl']) && $_GET['leftFetchUrl'] !== ''
    ? (string) $_GET['leftFetchUrl']
    : 'ChosungGrid_fetch.php?source=룸';
$rightFetchUrl = isset($_GET['rightFetchUrl']) && $_GET['rightFetchUrl'] !== ''
    ? (string) $_GET['rightFetchUrl']
    : 'ChosungGrid_fetch.php?source=직원';
$leftLabel = isset($_GET['leftLabel']) && $_GET['leftLabel'] !== '' ? (string) $_GET['leftLabel'] : '룸번호';
$rightLabel = isset($_GET['rightLabel']) && $_GET['rightLabel'] !== '' ? (string) $_GET['rightLabel'] : '영업';
$type = isset($_GET['type']) ? (string) $_GET['type'] : '테스트';
// [2026-09-11 추가] "여는 쪽이 콜백 이름을 지어서 넘긴다" 지시 —
// ChosungGrid.openDual()의 callback 옵션이 여기로 온다. 이 화면은
// 재사용 가능한 위젯이라 그 이름의 뜻은 몰라도 되고, 받은 그대로
// action()에 실어 돌려주기만 한다(생략 시 기존 이름으로 폴백).
$callbackName = isset($_GET['callback']) && $_GET['callback'] !== '' ? (string) $_GET['callback'] : 'callback_초성검색2';

// [2026-09-09 추가] "left에는 초기값 key/display가 전달되어 최초
// 선택된 상태가 되게, right는 빈값으로" 지시 — ChosungGrid.openDual()의
// left.initial이 있을 때만 넘어온다(ChosungGrid.js 참고). 오른쪽은 이
// 개념 자체가 없다 — 항상 빈 값으로 시작.
$leftInitialKey = isset($_GET['leftInitialKey']) ? (string) $_GET['leftInitialKey'] : null;
$leftInitialDisplay = isset($_GET['leftInitialDisplay']) && $_GET['leftInitialDisplay'] !== ''
    ? (string) $_GET['leftInitialDisplay']
    : null;

header('Content-Type: text/html; charset=utf-8');

$keypadJsVersion = filemtime(__DIR__ . '/../ChosungKeypad/ChosungKeypad.js');
$chosungGridJsVersion = filemtime(__DIR__ . '/ChosungGrid.js');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ChosungGrid.js 시험 — 초성검색 2개 (듀얼 선택 팝업)</title>
<style>
    html, body { margin: 0; height: 100%; box-sizing: border-box; }
    body {
        font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
        color: #1f2d3d;
        /* ChosungGrid_entry.php와 동일한 여백 규칙(우측/하단 1cm) —
           .cgd-col 두 개의 콘텐츠 폭 계산도 그 파일과 같은 방식으로
           ChosungGrid.openDual()의 iframeWidth 기본값(1086)과 맞춰뒀다. */
        padding: 10px 1cm 1cm 10px;
        display: flex;
        flex-direction: column;
        height: 100%;
    }
    /* [2026-09-09 수정] "좌우 객체 사이에 1cm 여백" 지시로 24px → 1cm. */
    .cgd-body { flex: 1; min-height: 0; display: flex; gap: 1cm; }
    .cgd-col { flex: 1 1 50%; min-width: 0; display: flex; flex-direction: column; height: 100%; }
    /* [2026-09-09 수정] "선택된 룸번호가 크고 찐하게 배경색이 눈에
       띄게" + "좌측 선택문구 중앙정렬, 우측 키패드가 좌측 키패드와
       같은 top값을 같게 정렬" 지시 — 두 라벨(왼쪽 배지/오른쪽 평범한
       안내문)의 세로 높이를 `height`(고정값, box-sizing:border-box라
       padding 포함)로 똑같이 맞춰, 그 아래 오는 `.cgd-col-root`(그리드+
       키패드)가 좌우 모두 같은 top에서 시작하게 한다 — 왼쪽 배지가
       커진 뒤로 라벨 높이가 서로 달라 오른쪽 키패드가 더 위에서
       시작해버리던 문제를 이렇게 해결. `display:flex;
       align-items:center`(공통)로 세로 중앙 정렬, `justify-content`는
       왼쪽만 `center`로 오버라이드(가로 중앙 정렬 지시는 왼쪽 라벨만
       해당 — 오른쪽 "영업"은 그대로 왼쪽 정렬 유지). */
    .cgd-col-label {
        flex: none; margin: 0 0 6px 0; height: 41px; box-sizing: border-box;
        display: flex; align-items: center;
        font-size: 0.82rem; color: #1f2d3d; font-weight: 600;
    }
    /* 왼쪽(룸) "선택 된 ..." 라벨만 큰 배지 스타일로 강조한다(오른쪽
       "영업" 라벨은 고정 안내문일 뿐 대상이 아니라 #cgd-left-label로
       한정). 룸종료/O 배지(deco_main.php get매출style())와 같은
       계열의 "검은 글자+밝은 배경" 강조 배색. 세로 padding은 위
       `.cgd-col-label`의 고정 height로 이미 자리를 잡아뒀으므로 좌우
       padding만 준다(둘 다 주면 오른쪽 라벨과 높이가 달라짐). */
    #cgd-left-label {
        justify-content: center;
        font-size: 1.5rem;
        font-weight: 800;
        color: #000;
        background: #ffeb3b;
        padding: 0 14px;
        border-radius: 8px;
    }
    /* [2026-09-09 추가] "팝업이 좁게 뜨고 스크롤바까지 생김" 진짜 원인
       조사 — `.cgd-col`엔 `min-width:0`이 있어도, 세로 방향 flex
       컨테이너(`flex-direction:column`)의 자식인 이 엘리먼트 자신은
       가로(cross axis) 최소폭이 기본값 `auto`라 안의 `.cg-root`(그
       자식들이 예전엔 flex-shrink:0이라 줄어들 수 없었던 것, ChosungGrid.js
       참고)를 만나면 이 칸도 함께 안 줄어들 수 있었다 — 체인 전체가
       shrink 가능하도록 `min-width: 0`도 명시.  */
    .cgd-col-root { flex: 1; min-height: 0; min-width: 0; }
    .cgd-log-wrap { flex: none; margin-top: 6px; }
    .cgd-log {
        max-height: 90px; overflow-y: auto; background: #fafafa;
        border: 1px solid #eee; border-radius: 6px; padding: 6px 10px;
        font-family: Consolas, 'Courier New', monospace; font-size: 0.76rem; color: #3c4043;
    }
</style>
</head>
<body>
<div class="cgd-body">
    <div class="cgd-col">
        <p class="cgd-col-label" id="cgd-left-label">선택 된 <?= htmlspecialchars($leftLabel, ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars($leftInitialDisplay ?? '(없음)', ENT_QUOTES, 'UTF-8') ?></p>
        <div class="cgd-col-root" id="cgd-left-root"></div>
    </div>
    <div class="cgd-col">
        <p class="cgd-col-label"><?= htmlspecialchars($rightLabel, ENT_QUOTES, 'UTF-8') ?></p>
        <div class="cgd-col-root" id="cgd-right-root"></div>
    </div>
</div>
<div class="cgd-log-wrap" id="cgd-log-wrap" hidden>
    <div class="cgd-log" id="cgd-log"></div>
</div>

<script src="../ChosungKeypad/ChosungKeypad.js?v=<?= $keypadJsVersion ?>"></script>
<script src="ChosungGrid.js?v=<?= $chosungGridJsVersion ?>"></script>
<script>
(function () {
    var logEl = document.getElementById('cgd-log');
    function log(message) {
        var line = document.createElement('div');
        var now = new Date();
        line.textContent = '[' + now.getHours() + ':' + now.getMinutes() + ':' + now.getSeconds() + '] ' + message;
        logEl.insertBefore(line, logEl.firstChild);
    }

    // ChosungGrid_entry.php와 동일한 방어적 판별.
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
        document.getElementById('cgd-log-wrap').hidden = false;
    }

    var type = <?= json_encode($type) ?>;
    var callbackName = <?= json_encode($callbackName) ?>;
    var leftLabel = <?= json_encode($leftLabel) ?>;
    var leftInitialKey = <?= json_encode($leftInitialKey) ?>;
    var leftInitialDisplay = <?= json_encode($leftInitialDisplay) ?>;

    // 왼쪽의 "마지막 선택" — 오른쪽을 확정할 때 이 값과 합친다. 호출부가
    // left.initial을 줬으면 그 값으로 "최초 선택된 상태"에서 시작한다
    // (위 <p id="cgd-left-label">도 PHP에서 이미 같은 값으로 미리
    // 그려둠 — 여기서는 오른쪽 확정 시 합칠 JS 값만 맞춰준다).
    var leftSelection = leftInitialDisplay !== null
        ? { key: leftInitialKey, display: leftInitialDisplay }
        : null;

    function reportCancelled() {
        log('취소(Quit)됨');
        if (sender) {
            sender.action(callbackName, { left: null, right: null, type: type, cancelled: true }, window);
        }
    }

    new ChosungGrid({
        containerId: 'cgd-left-root',
        fetchUrl: <?= json_encode($leftFetchUrl) ?>,
        onSelect: function (key, display) {
            leftSelection = { key: key, display: display };
            document.getElementById('cgd-left-label').textContent = '선택 된 ' + leftLabel + ': ' + display;
            log('왼쪽 선택(임시): key=' + key + ', display=' + display);
        },
        onQuit: reportCancelled,
    });

    new ChosungGrid({
        containerId: 'cgd-right-root',
        fetchUrl: <?= json_encode($rightFetchUrl) ?>,
        onSelect: function (key, display) {
            // [2026-09-09 추가] "right에서 선택 시 좌측이 선택 안 돼
            // 있다면 alert로 안내하고 창이 안 닫히게" 지시 — leftSelection
            // 이 아직 없으면(초기값도 없고, 왼쪽 그리드도 한 번도 안
            // 눌렀으면) sender.action() 자체를 안 불러 팝업이 안 닫히게
            // 막는다(팝업을 닫는 건 항상 그 콜백을 받는 쪽 책임이므로,
            // 콜백을 안 보내는 것만으로 충분).
            if (!leftSelection) {
                log('오른쪽 선택 무시됨 — ' + leftLabel + ' 먼저 선택 필요');
                alert(leftLabel + '을(를) 먼저 선택하세요.');
                return;
            }
            var right = { key: key, display: display };
            log('오른쪽 선택(확정): left=' + JSON.stringify(leftSelection) + ', right=' + JSON.stringify(right));
            if (sender) {
                sender.action(callbackName, { left: leftSelection, right: right, type: type }, window);
            }
        },
        onQuit: reportCancelled,
    });
})();
</script>
</body>
</html>
