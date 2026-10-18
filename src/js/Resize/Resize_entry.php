<?php
/**
 * Resize_entry — Resize.js 자기 검증용 페이지. Grid_entry.php와 같은
 * "클래스(유틸리티) 옆에 자기 검증 페이지를 둔다"는 관례를 따르되,
 * Resize.js는 서버/DB와 전혀 무관한 순수 클라이언트 유틸리티라 PHP는 이
 * 파일 자체(HTML 뼈대 + 캐시버스팅 계산)만 하고 나머지는 전부 JS다.
 *
 * 메인 영역(#re-main) 안에 iframe 5개(Resize_form1~5.html)를 두고,
 * Resize.observe()로 배치한 뒤 창 크기(또는 이 영역 자체의 크기)가 바뀌어도
 * 그 비율대로 다시 배치되는지 확인한다. 프리셋 버튼 2개를 두어 서로 다른
 * 배치 규칙(영문 함수명 위주 vs 한글 별칭 위주 — 둘이 같은 함수를
 * 가리킨다는 것을 실제로 확인할 수 있게)을 전환해본다.
 */
header('Content-Type: text/html; charset=utf-8');

$resizeJsVersion = filemtime(__DIR__ . '/Resize.js');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Resize.js 시험 — iframe 5개 배치/리사이즈</title>
<style>
    html, body { margin: 0; height: 100%; box-sizing: border-box; }
    body {
        font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
        color: #1f2d3d;
        display: flex;
        flex-direction: column;
        height: 100%;
    }
    .re-toolbar {
        flex: none;
        padding: 10px 14px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .re-toolbar h1 {
        font-size: 0.95rem;
        margin: 0;
        flex: none;
    }
    .re-btn {
        padding: 5px 12px;
        font-size: 0.82rem;
        background: #f3f4f6;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        cursor: pointer;
    }
    .re-btn:hover { background: #e5e7eb; }
    .re-btn.re-btn-active { background: #2563eb; color: #fff; border-color: #2563eb; }
    .re-legend {
        flex: 1 1 100%;
        font-size: 0.76rem;
        color: #6b7280;
        white-space: pre-wrap;
    }
    .re-main {
        flex: 1;
        position: relative;
        margin: 12px;
        min-height: 0;
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        overflow: hidden;
    }
    .re-form-frame {
        position: absolute;
        border: 1px solid rgba(0, 0, 0, 0.15);
        box-sizing: border-box;
        background: #fff;
    }
</style>
</head>
<body>

<div class="re-toolbar">
    <h1>Resize.js 연습</h1>
    <button type="button" class="re-btn re-btn-active" id="re-btn-dashboard">① 대시보드형 (영문 이름 위주)</button>
    <button type="button" class="re-btn" id="re-btn-centered">② 중앙 정렬형 (한글 별칭 위주)</button>
    <div class="re-legend" id="re-legend"></div>
</div>

<div class="re-main" id="re-main">
    <iframe class="re-form-frame" id="re-form1" src="Resize_form1.html" title="Form 1"></iframe>
    <iframe class="re-form-frame" id="re-form2" src="Resize_form2.html" title="Form 2"></iframe>
    <iframe class="re-form-frame" id="re-form3" src="Resize_form3.html" title="Form 3"></iframe>
    <iframe class="re-form-frame" id="re-form4" src="Resize_form4.html" title="Form 4"></iframe>
    <iframe class="re-form-frame" id="re-form5" src="Resize_form5.html" title="Form 5"></iframe>
</div>

<script src="Resize.js?v=<?= $resizeJsVersion ?>"></script>
<script>
(function () {
    var container = document.getElementById('re-main');
    var currentObserver = null;

    // ① 대시보드형 — 헤더/사이드바/본문/보조패널/상태바 5분할.
    // fillWidthToMargin/setHeight/dockBottom/below/setWidth/
    // fillHeightToControl/above/rightOf를 전부 영문 이름으로 호출한다.
    var layoutDashboard = [
        { id: 're-form1', top: 0, left: 0, fn: 'fillWidthToMargin', args: [1] },
        { id: 're-form1', fn: 'setHeight', args: [56] },

        { id: 're-form5', left: 10, fn: 'dockBottom', args: [10] },
        { id: 're-form5', fn: 'fillWidthToMargin', args: [10] },
        { id: 're-form5', fn: 'setHeight', args: [32] },

        { id: 're-form2', fn: 'below', args: ['re-form1', 10] },
        { id: 're-form2', left: 10, fn: 'setWidth', args: [200] },
        { id: 're-form2', fn: 'fillHeightToControl', args: ['re-form5', 10] },

        // Form3(본문)은 Form4가 아니라 항상 상태바(Form5)까지 채운다 —
        // Form4를 참조해 높이를 계산했다면, Form4가 visible:false로
        // 숨겨질 때 offsetTop/offsetHeight가 0이 되어(hidden 요소는
        // 렌더링 자체가 안 됨) 계산이 틀어진다(resize.md "알려진 한계"
        // 참고). 대신 Form4는 Form3 위에 겹쳐 뜨는 오버레이 패널로
        // 두고(뒤에 그려지는 요소가 위로 보이는 자연스러운 쌓임 순서),
        // 보이거나 숨겨져도 Form3 쪽은 전혀 다시 계산할 필요가 없다.
        { id: 're-form3', fn: 'below', args: ['re-form1', 10] },
        { id: 're-form3', fn: 'rightOf', args: ['re-form2', 10] },
        { id: 're-form3', fn: 'fillWidthToMargin', args: [10] },
        { id: 're-form3', fn: 'fillHeightToControl', args: ['re-form5', 10] },

        { id: 're-form4', fn: 'above', args: ['re-form5', 10] },
        { id: 're-form4', fn: 'rightOf', args: ['re-form2', 10] },
        { id: 're-form4', fn: 'fillWidthToMargin', args: [10] },
        // visible을 함수로 주면 매 apply()(=리사이즈될 때)마다 다시
        // 평가된다 — 이 영역(#re-main)의 폭이 700px 미만이면 보조패널을
        // 자동으로 숨긴다(hidden 속성, 실제로 화면에서 사라짐).
        {
            id: 're-form4', fn: 'setHeight', args: [130],
            visible: function (container) { return container.clientWidth >= 700; },
        },
    ];
    var legendDashboard =
        'Form1=헤더(fillWidthToMargin+setHeight) / Form5=상태바(dockBottom+fillWidthToMargin) / ' +
        'Form2=사이드바(below+setWidth+fillHeightToControl) / Form3=본문(below+rightOf+fillWidthToMargin+fillHeightToControl, 항상 상태바까지) / ' +
        'Form4=보조패널(above+rightOf+fillWidthToMargin, Form3 위에 겹쳐 뜸, 폭 700px 미만이면 visible 함수로 자동 숨김)';

    // ② 중앙 정렬형 — 카드 하나가 세로 중앙에 뜨고 좌우/아래/우하단에
    // 보조 패널이 붙는다. 대부분 한글 별칭으로 호출해, 같은 Resize.js
    // 함수를 영문/한글 어느 이름으로 불러도 동일하게 동작함을 보여준다.
    var layoutCentered = [
        { id: 're-form1', left: 260, fn: 'setWidth', args: [280] },
        { id: 're-form1', fn: 'setHeight', args: [160] },
        { id: 're-form1', fn: '위치_top가운데로', args: [] },

        { id: 're-form2', fn: '크기_너비와높이동일하게', args: ['re-form1'] },
        { id: 're-form2', fn: '위치_컨트롤왼쪽에위치', args: ['re-form1', 16] },

        { id: 're-form3', fn: 'matchSize', args: ['re-form2'] },
        { id: 're-form3', fn: 'rightOf', args: ['re-form1', 16] },

        { id: 're-form4', left: 260, fn: 'below', args: ['re-form1', 16] },
        { id: 're-form4', fn: 'setWidth', args: [280] },
        { id: 're-form4', fn: 'fillHeightDown', args: [16, 0.5] },

        { id: 're-form5', fn: 'setWidth', args: [160] },
        { id: 're-form5', fn: 'setHeight', args: [80] },
        { id: 're-form5', fn: 'dockRight', args: [16] },
        { id: 're-form5', fn: 'dockBottom', args: [16] },
    ];
    var legendCentered =
        'Form1=중앙카드(위치_top가운데로) / Form2=좌측(크기_너비와높이동일하게+위치_컨트롤왼쪽에위치) / ' +
        'Form3=우측(matchSize+rightOf) / Form4=하단(below+fillHeightDown rate=0.5) / ' +
        'Form5=우하단 모서리(dockRight+dockBottom)';

    function useLayout(layout, legendText, activeBtnId) {
        if (currentObserver) {
            currentObserver.disconnect();
        }
        currentObserver = Resize.observe(container, layout);
        //document.getElementById('re-legend').textContent = legendText;
        //document.getElementById('re-btn-dashboard').classList.remove('re-btn-active');
        //document.getElementById('re-btn-centered').classList.remove('re-btn-active');
        //document.getElementById(activeBtnId).classList.add('re-btn-active');
    }

    document.getElementById('re-btn-dashboard').addEventListener('click', function () {
        useLayout(layoutDashboard, legendDashboard, 're-btn-dashboard');
    });
    document.getElementById('re-btn-centered').addEventListener('click', function () {
        useLayout(layoutCentered, legendCentered, 're-btn-centered');
    });

    useLayout(layoutDashboard, legendDashboard, 're-btn-dashboard'); // 최초 진입 시 대시보드형으로 시작
})();
</script>
</body>
</html>
