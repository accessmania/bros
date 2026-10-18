<?php
/**
 * DateTime_entry — DateTime.js 자기 검증 페이지. `Resize_entry.php`와 같은
 * "클래스(유틸리티) 옆에 자기 검증 페이지를 둔다"는 관례를 따르되,
 * DateTime.js는 서버/DB와 전혀 무관한 순수 클라이언트 유틸리티라 PHP는 이
 * 파일 자체(HTML 뼈대 + 캐시버스팅 계산)만 하고 나머지는 전부 JS다.
 *
 * cdata()/transMinuteHour()/mDateTime_get요일()/mDateTime_a() 4개 함수를
 * 각각 입력칸+버튼+결과 표시로 직접 호출해볼 수 있게 한다.
 */
header('Content-Type: text/html; charset=utf-8');

$dateTimeJsVersion = filemtime(__DIR__ . '/DateTime.js');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DateTime.js 시험</title>
<style>
    html, body { margin: 0; box-sizing: border-box; }
    * { box-sizing: border-box; }
    body {
        font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
        color: #1f2d3d;
        padding: 16px;
        background: #f9fafb;
    }
    h1 { font-size: 1rem; margin: 0 0 12px; }
    fieldset {
        border: 1px solid #d1d5db;
        border-radius: 8px;
        padding: 12px 14px;
        margin-bottom: 14px;
        background: #fff;
    }
    legend { font-weight: 700; font-size: 0.9rem; padding: 0 6px; }
    .de-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
    .de-row label { font-size: 0.82rem; color: #5f6368; flex: 0 0 110px; }
    .de-row input[type="text"], .de-row select {
        flex: 1 1 160px;
        min-width: 0;
        padding: 5px 8px;
        border: 1px solid #d1d5db;
        border-radius: 4px;
        font-size: 0.85rem;
        font-family: inherit;
    }
    .de-btn {
        padding: 6px 14px;
        border: 1px solid #2563eb;
        border-radius: 6px;
        background: #2563eb;
        color: #fff;
        font-size: 0.85rem;
        cursor: pointer;
    }
    .de-btn:hover { background: #1d4ed8; }
    .de-result {
        margin-top: 8px;
        font-size: 0.85rem;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 8px 10px;
    }
    .de-result b { color: #1d4ed8; }
</style>
</head>
<body>

<h1>DateTime.js 시험 — cdata / transMinuteHour / mDateTime_get요일 / mDateTime_a</h1>

<fieldset>
    <legend>cdata(x) — 숫자형으로 변환</legend>
    <div class="de-row">
        <label for="de-cdata-input">x</label>
        <input type="text" id="de-cdata-input" value="123abc">
        <button type="button" class="de-btn" id="de-cdata-btn">cdata() 실행</button>
    </div>
    <div class="de-result">결과: <b id="de-cdata-result">(아직 없음)</b></div>
</fieldset>

<fieldset>
    <legend>transMinuteHour(pmin, ph, pn) — 분 → "H시간N분"</legend>
    <div class="de-row">
        <label for="de-tmh-min">pmin(분)</label>
        <input type="text" id="de-tmh-min" value="135">
    </div>
    <div class="de-row">
        <label for="de-tmh-ph">ph(시간 접미사)</label>
        <input type="text" id="de-tmh-ph" placeholder="생략하면 '시간'">
    </div>
    <div class="de-row">
        <label for="de-tmh-pn">pn(분 접미사)</label>
        <input type="text" id="de-tmh-pn" placeholder="생략하면 '분'">
        <button type="button" class="de-btn" id="de-tmh-btn">transMinuteHour() 실행</button>
    </div>
    <div class="de-result">결과: <b id="de-tmh-result">(아직 없음)</b></div>
</fieldset>

<fieldset>
    <legend>mDateTime_get요일(pDate, pType) — 날짜의 요일</legend>
    <div class="de-row">
        <label for="de-wd-date">pDate</label>
        <input type="text" id="de-wd-date" placeholder="예: 2026-09-19, 비우면 오늘">
    </div>
    <div class="de-row">
        <label for="de-wd-type">pType</label>
        <select id="de-wd-type">
            <option value="한글" selected>한글</option>
            <option value="영어">영어</option>
            <option value="한자">한자</option>
            <option value="숫자">숫자</option>
        </select>
        <button type="button" class="de-btn" id="de-wd-btn">mDateTime_get요일() 실행</button>
    </div>
    <div class="de-result">결과: <b id="de-wd-result">(아직 없음)</b></div>
</fieldset>

<fieldset>
    <legend>mDateTime_a(pdate) — mDateTime_get요일()에 위임</legend>
    <div class="de-row">
        <label for="de-a-date">pdate</label>
        <input type="text" id="de-a-date" placeholder="예: 2026-09-19, 비우면 오늘">
        <button type="button" class="de-btn" id="de-a-btn">mDateTime_a() 실행</button>
    </div>
    <div class="de-result">결과: <b id="de-a-result">(아직 없음)</b></div>
</fieldset>

<script src="DateTime.js?v=<?= $dateTimeJsVersion ?>"></script>
<script>
(function () {
    document.getElementById('de-cdata-btn').addEventListener('click', function () {
        var x = document.getElementById('de-cdata-input').value;
        document.getElementById('de-cdata-result').textContent = JSON.stringify(DateTime.cdata(x));
    });

    document.getElementById('de-tmh-btn').addEventListener('click', function () {
        var pmin = document.getElementById('de-tmh-min').value;
        var ph = document.getElementById('de-tmh-ph').value || undefined;
        var pn = document.getElementById('de-tmh-pn').value || undefined;
        document.getElementById('de-tmh-result').textContent = DateTime.transMinuteHour(pmin, ph, pn);
    });

    document.getElementById('de-wd-btn').addEventListener('click', function () {
        var pDate = document.getElementById('de-wd-date').value || undefined;
        var pType = document.getElementById('de-wd-type').value;
        document.getElementById('de-wd-result').textContent = DateTime.mDateTime_get요일(pDate, pType);
    });

    document.getElementById('de-a-btn').addEventListener('click', function () {
        var pdate = document.getElementById('de-a-date').value || undefined;
        document.getElementById('de-a-result').textContent = DateTime.mDateTime_a(pdate);
    });
})();
</script>
</body>
</html>
