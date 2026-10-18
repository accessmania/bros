<?php
/**
 * Grid_entry — Grid.js 자기 검증용 페이지. Domain_entry.php/Board_entry.php/
 * Crud_entry.php와 같은 "클래스 옆에 자기 검증 페이지를 둔다"는 관례를
 * 따르되, Grid.js는 서버/DB와 전혀 무관한 순수 클라이언트 클래스라 PHP는
 * 이 파일 자체(HTML 뼈대 + 캐시버스팅 계산)만 하고 나머지는 전부 JS다.
 *
 * 제목/설명 문구는 화면에 안 두고(도구 fieldset의 legend만으로 용도를
 * 표시), 좌측 도구 패널과 우측 그리드 영역이 화면 상하를 가득 채우게
 * 구성했다. 글자 크기는 그리드 전체에 일괄 적용하는 설정이 아니라
 * addCell/setValue의 "스타일을 입힌 문구" 조립 옵션 중 하나다(셀마다
 * 다르게 줄 수 있음).
 *
 * 제공하는 도구:
 *   1) init(n, m) / setNxM(n, m) / clear() — 모양+데이터 리셋 / 모양만
 *      변경(데이터 유지) / 데이터만 삭제(모양 유지).
 *   2) setScroll(true|false) — 스크롤로 전체 표시(셀 크기는 페이징 때와
 *      동일하게 고정, 행 개수만 늘어나 넘치는 만큼 스크롤) vs 페이징.
 *   2-1) setStyle(null|'default'|'card') — 칸 외형을 기본형(표)과
 *      카드형(간격+둥근모서리+그림자) 사이에서 토글. addCell/setValue 등의
 *      규칙은 기본형과 완전히 동일, 겉모습만 바뀐다.
 *   3) addCell(displayValue, dataValue, keyValue) — 셀을 끝에 추가,
 *      배경색/글자색/굵게/글자크기 옵션으로 "스타일을 입힌 문구"를
 *      조립, 키값(선택)을 주면 생성과 동시에 매겨짐.
 *   4) setValue(cellNo, ..., keyValue)(덮어쓰기, 4번째 인수로 키값도
 *      매길 수 있음) / setValueAdd(cellNo, ...)(이어붙이기, 키값 없음)
 *      — 스타일 옵션(글자크기 포함)은 addCell과 동일하게 공유.
 *   5) setValueKey(키값, ...)(덮어쓰기) / setValueKeyAdd(키값, ...)
 *      (이어붙이기) / getValueKey(키값, display|data|key) — 셀번호 대신
 *      키값으로 지정해 덮어쓰기/이어붙이기/조회.
 *   6) clearCell(셀번호) / clearCellKey(키값) — 그 셀의 값/키만 비움
 *      (셀번호 슬롯 자체는 안 없어짐).
 *   7) getValue(cellNo, display|data|key) — 셀 하나 조회.
 *   8) getCell(키값) — 키값을 가진 셀번호를 역조회.
 *   9) getValueAll() — 그리드 전체를 JSON 문자열로 조회.
 *   10) 페이지 이동(처음/이전/다음/마지막) + getPager() 표시("2/10" 형식).
 *
 * 그리드 셀을 클릭하면 "값 변경"/"조회" 도구의 셀번호 입력칸에 그 번호가
 * 자동으로 채워진다.
 */
header('Content-Type: text/html; charset=utf-8');

$gridJsVersion = filemtime(__DIR__ . '/Grid.js');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Grid.js 시험 — n x m 페이지 + addCell</title>
<style>
    html, body { margin: 0; height: 100%; }
    body {
        font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
        color: #1f2d3d;
        display: flex;
        height: 100%;
        box-sizing: border-box;
    }
    .ge-tools {
        width: 320px;
        flex: none;
        padding: 12px;
        border-right: 1px solid #e5e7eb;
        overflow-y: auto;
        box-sizing: border-box;
    }
    .ge-tools fieldset {
        border: 1px solid #d1d5db;
        border-radius: 6px;
        margin: 0 0 10px;
        padding: 10px 12px;
    }
    .ge-tools legend { font-size: 0.82rem; color: #374151; padding: 0 4px; font-weight: bold; }
    .ge-row { display: flex; align-items: center; gap: 6px; margin-bottom: 8px; }
    .ge-row label { font-size: 0.78rem; color: #4b5563; width: 78px; flex: none; }
    .ge-row input[type="text"], .ge-row input[type="number"], .ge-row select {
        flex: 1; font-size: 0.82rem; padding: 3px 6px;
        border: 1px solid #d1d5db; border-radius: 4px;
    }
    .ge-row input[type="checkbox"] { margin: 0; }
    .ge-btn {
        width: 100%; padding: 6px 10px; font-size: 0.82rem;
        background: #2563eb; color: #fff; border: none; border-radius: 4px; cursor: pointer;
        margin-bottom: 6px;
    }
    .ge-btn:last-child { margin-bottom: 0; }
    .ge-btn:hover { background: #1d4ed8; }
    .ge-btn:disabled { background: #9ca3af; cursor: not-allowed; }
    .ge-btn-row { display: flex; gap: 6px; }
    .ge-btn-row .ge-btn { margin-bottom: 0; }
    .ge-output {
        margin-top: 6px; padding: 6px 8px; background: #f3f4f6; border-radius: 4px;
        font-size: 0.78rem; word-break: break-all; min-height: 1.2em;
        max-height: 160px; overflow-y: auto; white-space: pre-wrap;
    }
    .ge-pager-label { text-align: center; font-size: 0.95rem; font-weight: bold; margin-bottom: 8px; }
    .ge-grid-area { flex: 1; padding: 12px; box-sizing: border-box; min-width: 0; }
    #ge-grid { height: 100%; }
</style>
</head>
<body>

<div class="ge-tools">
    <fieldset>
        <legend>init / setNxM / clear</legend>
        <div class="ge-row">
            <label for="ge-cols">열(n)</label>
            <input type="number" id="ge-cols" value="5" min="1">
        </div>
        <div class="ge-row">
            <label for="ge-rows">행(m)</label>
            <input type="number" id="ge-rows" value="3" min="1">
        </div>
        <button type="button" class="ge-btn" id="ge-btn-init" style="background:#7c3aed;">init 실행 (모양+데이터 초기화)</button>
        <button type="button" class="ge-btn" id="ge-btn-setnxm">setNxM 적용 (모양만, 데이터 유지)</button>
        <button type="button" class="ge-btn" id="ge-btn-clear" style="background:#dc2626;">clear 실행 (모양 유지, 데이터만 삭제)</button>
    </fieldset>

    <fieldset>
        <legend>setScroll(true|false)</legend>
        <div class="ge-btn-row">
            <button type="button" class="ge-btn" id="ge-btn-scroll-on">setScroll(true) 스크롤</button>
            <button type="button" class="ge-btn" id="ge-btn-scroll-off">setScroll(false) 페이징</button>
        </div>
    </fieldset>

    <fieldset>
        <legend>setStyle(null|'default'|'card')</legend>
        <div class="ge-btn-row">
            <button type="button" class="ge-btn" id="ge-btn-style-default">기본형 (표)</button>
            <button type="button" class="ge-btn" id="ge-btn-style-card">카드형 (card)</button>
        </div>
    </fieldset>

    <fieldset>
        <legend>addCell(displayValue, dataValue, keyValue)</legend>
        <div class="ge-row">
            <label for="ge-add-text">문구</label>
            <input type="text" id="ge-add-text" value="1T(5)">
        </div>
        <div class="ge-row">
            <label for="ge-add-bg">배경색</label>
            <select id="ge-add-bg">
                <option value="">없음</option>
                <option value="#ffe58a">노랑</option>
                <option value="#c6f6d5">초록</option>
                <option value="#bfdbfe">파랑</option>
                <option value="#fecaca">빨강</option>
            </select>
        </div>
        <div class="ge-row">
            <label for="ge-add-color">글자색</label>
            <select id="ge-add-color">
                <option value="">기본</option>
                <option value="#dc2626">빨강</option>
                <option value="#2563eb">파랑</option>
                <option value="#16a34a">초록</option>
                <option value="#6b7280">회색</option>
            </select>
        </div>
        <div class="ge-row">
            <label for="ge-add-bold">굵게</label>
            <input type="checkbox" id="ge-add-bold">
        </div>
        <div class="ge-row">
            <label for="ge-add-fontsize">글자크기</label>
            <input type="number" id="ge-add-fontsize" placeholder="예: 20" min="8" max="72">
        </div>
        <div class="ge-row">
            <label for="ge-add-data">dataValue</label>
            <input type="text" id="ge-add-data" value="{&quot;room&quot;:&quot;1T&quot;,&quot;capacity&quot;:5}">
        </div>
        <div class="ge-row">
            <label for="ge-add-key">키값</label>
            <input type="text" id="ge-add-key" placeholder="비우면 키 없이 생성">
        </div>
        <button type="button" class="ge-btn" id="ge-btn-add">셀 추가</button>
        <button type="button" class="ge-btn" id="ge-btn-add-sample" style="background:#16a34a;">샘플 20개 채우기</button>
        <div class="ge-output" id="ge-add-output">(아직 추가 안 함)</div>
    </fieldset>

    <fieldset>
        <legend>setValue(덮어쓰기) / setValueAdd(이어붙이기)</legend>
        <div class="ge-row">
            <label for="ge-set-cell">셀번호</label>
            <input type="number" id="ge-set-cell" value="1" min="1">
        </div>
        <div class="ge-row">
            <label for="ge-set-text">문구</label>
            <input type="text" id="ge-set-text" value="수정됨">
        </div>
        <div class="ge-row">
            <label for="ge-set-bg">배경색</label>
            <select id="ge-set-bg">
                <option value="">없음</option>
                <option value="#ffe58a">노랑</option>
                <option value="#c6f6d5">초록</option>
                <option value="#bfdbfe">파랑</option>
                <option value="#fecaca">빨강</option>
            </select>
        </div>
        <div class="ge-row">
            <label for="ge-set-color">글자색</label>
            <select id="ge-set-color">
                <option value="">기본</option>
                <option value="#dc2626">빨강</option>
                <option value="#2563eb">파랑</option>
                <option value="#16a34a">초록</option>
                <option value="#6b7280">회색</option>
            </select>
        </div>
        <div class="ge-row">
            <label for="ge-set-bold">굵게</label>
            <input type="checkbox" id="ge-set-bold">
        </div>
        <div class="ge-row">
            <label for="ge-set-fontsize">글자크기</label>
            <input type="number" id="ge-set-fontsize" placeholder="예: 20" min="8" max="72">
        </div>
        <div class="ge-row">
            <label for="ge-set-data">dataValue</label>
            <input type="text" id="ge-set-data" value="">
        </div>
        <div class="ge-row">
            <label for="ge-set-key">키값</label>
            <input type="text" id="ge-set-key" placeholder="비우면 기존 키 유지">
        </div>
        <div class="ge-btn-row">
            <button type="button" class="ge-btn" id="ge-btn-set">덮어쓰기</button>
            <button type="button" class="ge-btn" id="ge-btn-set-add" style="background:#0891b2;">이어붙이기</button>
        </div>
        <p style="font-size:0.72rem; color:#6b7280; margin:6px 0 0;">
            "이어붙이기"는 키값을 받지 않는다(덮어쓰기에서만 키를 매길 수 있음).
        </p>
    </fieldset>

    <fieldset>
        <legend>setValueKey / setValueKeyAdd / getValueKey (키값, ...)</legend>
        <div class="ge-row">
            <label for="ge-vk-key">키값</label>
            <input type="text" id="ge-vk-key" placeholder="대상 키값">
        </div>
        <div class="ge-row">
            <label for="ge-vk-text">문구</label>
            <input type="text" id="ge-vk-text" value="키로 수정됨">
        </div>
        <div class="ge-row">
            <label for="ge-vk-bg">배경색</label>
            <select id="ge-vk-bg">
                <option value="">없음</option>
                <option value="#ffe58a">노랑</option>
                <option value="#c6f6d5">초록</option>
                <option value="#bfdbfe">파랑</option>
                <option value="#fecaca">빨강</option>
            </select>
        </div>
        <div class="ge-row">
            <label for="ge-vk-color">글자색</label>
            <select id="ge-vk-color">
                <option value="">기본</option>
                <option value="#dc2626">빨강</option>
                <option value="#2563eb">파랑</option>
                <option value="#16a34a">초록</option>
                <option value="#6b7280">회색</option>
            </select>
        </div>
        <div class="ge-row">
            <label for="ge-vk-bold">굵게</label>
            <input type="checkbox" id="ge-vk-bold">
        </div>
        <div class="ge-row">
            <label for="ge-vk-fontsize">글자크기</label>
            <input type="number" id="ge-vk-fontsize" placeholder="예: 20" min="8" max="72">
        </div>
        <div class="ge-row">
            <label for="ge-vk-data">dataValue</label>
            <input type="text" id="ge-vk-data" value="">
        </div>
        <div class="ge-btn-row">
            <button type="button" class="ge-btn" id="ge-btn-vk-set">setValueKey(덮어쓰기)</button>
            <button type="button" class="ge-btn" id="ge-btn-vk-set-add" style="background:#0891b2;">setValueKeyAdd(이어붙이기)</button>
        </div>
        <div class="ge-row" style="margin-top:8px;">
            <label for="ge-vk-kind">조회 종류</label>
            <select id="ge-vk-kind">
                <option value="display">display</option>
                <option value="data">data</option>
                <option value="key">key</option>
            </select>
        </div>
        <button type="button" class="ge-btn" id="ge-btn-vk-get" style="background:#0891b2;">getValueKey 조회</button>
        <div class="ge-output" id="ge-vk-output">(아직 조회 안 함)</div>
    </fieldset>

    <fieldset>
        <legend>clearCell(셀번호) / clearCellKey(키값)</legend>
        <div class="ge-row">
            <label for="ge-clear-cell">셀번호</label>
            <input type="number" id="ge-clear-cell" value="1" min="1">
        </div>
        <button type="button" class="ge-btn" id="ge-btn-clearcell" style="background:#dc2626;">clearCell 실행</button>
        <div class="ge-row" style="margin-top:8px;">
            <label for="ge-clear-key">키값</label>
            <input type="text" id="ge-clear-key">
        </div>
        <button type="button" class="ge-btn" id="ge-btn-clearcellkey" style="background:#dc2626;">clearCellKey 실행</button>
        <div class="ge-output" id="ge-clear-output">(아직 실행 안 함)</div>
    </fieldset>

    <fieldset>
        <legend>getValue(셀번호, display|data|key)</legend>
        <div class="ge-row">
            <label for="ge-get-cell">셀번호</label>
            <input type="number" id="ge-get-cell" value="1" min="1">
        </div>
        <div class="ge-row">
            <label for="ge-get-kind">종류</label>
            <select id="ge-get-kind">
                <option value="display">display</option>
                <option value="data">data</option>
                <option value="key">key</option>
            </select>
        </div>
        <button type="button" class="ge-btn" id="ge-btn-get">조회</button>
        <div class="ge-output" id="ge-get-output">(아직 조회 안 함)</div>
    </fieldset>

    <fieldset>
        <legend>getCell(키값)</legend>
        <div class="ge-row">
            <label for="ge-getcell-key">키값</label>
            <input type="text" id="ge-getcell-key">
        </div>
        <button type="button" class="ge-btn" id="ge-btn-getcell">셀번호 조회</button>
        <div class="ge-output" id="ge-getcell-output">(아직 조회 안 함)</div>
    </fieldset>

    <fieldset>
        <legend>getValueAll()</legend>
        <button type="button" class="ge-btn" id="ge-btn-get-all">전체 조회(JSON)</button>
        <div class="ge-output" id="ge-get-all-output">(아직 조회 안 함)</div>
    </fieldset>

    <fieldset>
        <legend>페이지 이동 / getPager()</legend>
        <div class="ge-pager-label" id="ge-pager-label">1/1</div>
        <div class="ge-output" id="ge-pager-detail" style="margin-bottom:8px;">전체 0칸 / 페이지당 0칸</div>
        <div class="ge-btn-row">
            <button type="button" class="ge-btn" id="ge-btn-first">처음</button>
            <button type="button" class="ge-btn" id="ge-btn-prev">이전</button>
            <button type="button" class="ge-btn" id="ge-btn-next">다음</button>
            <button type="button" class="ge-btn" id="ge-btn-last">마지막</button>
        </div>
    </fieldset>
</div>

<div class="ge-grid-area">
    <div id="ge-grid"></div>
</div>

<script src="Grid.js?v=<?= $gridJsVersion ?>"></script>
<script>
(function () {
    function onCellClick(cellNo) {
        document.getElementById('ge-set-cell').value = cellNo;
        document.getElementById('ge-get-cell').value = cellNo;
    }

    // 최초 생성 시 n:5, m:3을 넘기면 생성자 안의 action('init', ...)이
    // 곧바로 5x3 모양 + clear()까지 해준다(매출1.png와 동일한 모양으로
    // 시작, 화면은 아직 비어있음 — addCell()을 불러야 칸이 생긴다).
    var grid = new Grid({ containerId: 'ge-grid', n: 5, m: 3, onCellClick: onCellClick });

    function buildDisplayValue(prefix) {
        var text = document.getElementById(prefix + 'text').value;
        var bg = document.getElementById(prefix + 'bg').value;
        var color = document.getElementById(prefix + 'color').value;
        var bold = document.getElementById(prefix + 'bold').checked;
        var fontSize = document.getElementById(prefix + 'fontsize').value;
        var styleParts = [];
        if (bg) { styleParts.push('background:' + bg); }
        if (color) { styleParts.push('color:' + color); }
        if (bold) { styleParts.push('font-weight:bold'); }
        if (fontSize) { styleParts.push('font-size:' + fontSize + 'px'); }
        if (styleParts.length === 0) {
            return text;
        }
        return '<span style="' + styleParts.join(';') + '">' + text + '</span>';
    }

    function parseDataValue(prefix) {
        var raw = document.getElementById(prefix + 'data').value;
        try {
            return JSON.parse(raw);
        } catch (e) {
            return raw; // JSON이 아니면 입력한 문자열 그대로 dataValue로 씀.
        }
    }

    function refreshPagerDisplay() {
        var pager = grid.getPager();
        document.getElementById('ge-pager-label').textContent = pager.label;
        document.getElementById('ge-pager-detail').textContent =
            '전체 ' + pager.totalCells + '칸 / 페이지당 ' + pager.pageSize + '칸';
        document.getElementById('ge-btn-first').disabled = grid.bof();
        document.getElementById('ge-btn-prev').disabled = grid.bof();
        document.getElementById('ge-btn-next').disabled = grid.eof();
        document.getElementById('ge-btn-last').disabled = grid.eof();
    }

    function readShapeInputs() {
        return {
            cols: parseInt(document.getElementById('ge-cols').value, 10) || 1,
            rows: parseInt(document.getElementById('ge-rows').value, 10) || 1,
        };
    }

    function applyInit() {
        var shape = readShapeInputs();
        grid.init(shape.cols, shape.rows); // 모양+데이터 한 번에 리셋.
        document.getElementById('ge-add-output').textContent = '(아직 추가 안 함)';
        refreshPagerDisplay();
    }

    function applySetNxM() {
        var shape = readShapeInputs();
        grid.setNxM(shape.cols, shape.rows); // 모양만 변경, 이미 추가된 셀은 유지.
        refreshPagerDisplay();
    }

    function applyClear() {
        grid.clear(); // 모양은 그대로, 셀만 전부 삭제.
        document.getElementById('ge-add-output').textContent = '(아직 추가 안 함)';
        refreshPagerDisplay();
    }

    function addCellFromTool() {
        var keyRaw = document.getElementById('ge-add-key').value;
        var keyValue = keyRaw === '' ? undefined : keyRaw;
        var cellNo = grid.addCell(buildDisplayValue('ge-add-'), parseDataValue('ge-add-'), keyValue);
        document.getElementById('ge-add-output').textContent = '추가된 셀번호: ' + cellNo;
        refreshPagerDisplay();
    }

    function addSampleCells() {
        for (var i = 0; i < 20; i++) {
            grid.addCell('셀 ' + (grid.getPager().totalCells + 1), { sample: true, index: i });
        }
        document.getElementById('ge-add-output').textContent = '샘플 20개 추가 완료';
        refreshPagerDisplay();
    }

    function setValueFromTool() {
        var cellNo = parseInt(document.getElementById('ge-set-cell').value, 10);
        var keyRaw = document.getElementById('ge-set-key').value;
        // 빈 칸이면 undefined를 넘겨 기존 키를 그대로 둔다(Grid.js의 규칙).
        var keyValue = keyRaw === '' ? undefined : keyRaw;
        grid.setValue(cellNo, buildDisplayValue('ge-set-'), parseDataValue('ge-set-'), keyValue);
    }

    function setValueAddFromTool() {
        var cellNo = parseInt(document.getElementById('ge-set-cell').value, 10);
        grid.setValueAdd(cellNo, buildDisplayValue('ge-set-'), parseDataValue('ge-set-'));
    }

    function getValueFromTool() {
        var cellNo = parseInt(document.getElementById('ge-get-cell').value, 10);
        var kind = document.getElementById('ge-get-kind').value;
        var value = grid.getValue(cellNo, kind);
        var output = document.getElementById('ge-get-output');
        if (value === null) {
            output.textContent = '(값 없음 — 아직 addCell() 안 된 셀번호)';
        } else if (typeof value === 'object') {
            output.textContent = JSON.stringify(value);
        } else {
            output.textContent = String(value);
        }
    }

    function getValueAllFromTool() {
        document.getElementById('ge-get-all-output').textContent = grid.getValueAll();
    }

    function setValueKeyFromTool() {
        var keyValue = document.getElementById('ge-vk-key').value;
        grid.setValueKey(keyValue, buildDisplayValue('ge-vk-'), parseDataValue('ge-vk-'));
        refreshPagerDisplay();
    }

    function setValueKeyAddFromTool() {
        var keyValue = document.getElementById('ge-vk-key').value;
        grid.setValueKeyAdd(keyValue, buildDisplayValue('ge-vk-'), parseDataValue('ge-vk-'));
        refreshPagerDisplay();
    }

    function getValueKeyFromTool() {
        var keyValue = document.getElementById('ge-vk-key').value;
        var kind = document.getElementById('ge-vk-kind').value;
        var value = grid.getValueKey(keyValue, kind);
        var output = document.getElementById('ge-vk-output');
        if (value === null) {
            output.textContent = '(값 없음 — 해당 키값의 셀이 없거나 값이 없음)';
        } else if (typeof value === 'object') {
            output.textContent = JSON.stringify(value);
        } else {
            output.textContent = String(value);
        }
    }

    function clearCellFromTool() {
        var cellNo = parseInt(document.getElementById('ge-clear-cell').value, 10);
        grid.clearCell(cellNo);
        document.getElementById('ge-clear-output').textContent = '셀 ' + cellNo + ' 비움';
        refreshPagerDisplay();
    }

    function clearCellKeyFromTool() {
        var keyValue = document.getElementById('ge-clear-key').value;
        grid.clearCellKey(keyValue);
        document.getElementById('ge-clear-output').textContent = "키 '" + keyValue + "' 셀 비움";
        refreshPagerDisplay();
    }

    function getCellFromTool() {
        var keyValue = document.getElementById('ge-getcell-key').value;
        var cellNo = grid.getCell(keyValue);
        var output = document.getElementById('ge-getcell-output');
        output.textContent = cellNo === null ? '(해당 키값을 가진 셀 없음)' : ('셀번호: ' + cellNo);
    }

    document.getElementById('ge-btn-init').addEventListener('click', applyInit);
    document.getElementById('ge-btn-setnxm').addEventListener('click', applySetNxM);
    document.getElementById('ge-btn-clear').addEventListener('click', applyClear);
    document.getElementById('ge-btn-scroll-on').addEventListener('click', function () { grid.setScroll(true); refreshPagerDisplay(); });
    document.getElementById('ge-btn-scroll-off').addEventListener('click', function () { grid.setScroll(false); refreshPagerDisplay(); });
    document.getElementById('ge-btn-style-default').addEventListener('click', function () { grid.setStyle(null); });
    document.getElementById('ge-btn-style-card').addEventListener('click', function () { grid.setStyle('card'); });
    document.getElementById('ge-btn-add').addEventListener('click', addCellFromTool);
    document.getElementById('ge-btn-add-sample').addEventListener('click', addSampleCells);
    document.getElementById('ge-btn-set').addEventListener('click', setValueFromTool);
    document.getElementById('ge-btn-set-add').addEventListener('click', setValueAddFromTool);
    document.getElementById('ge-btn-vk-set').addEventListener('click', setValueKeyFromTool);
    document.getElementById('ge-btn-vk-set-add').addEventListener('click', setValueKeyAddFromTool);
    document.getElementById('ge-btn-vk-get').addEventListener('click', getValueKeyFromTool);
    document.getElementById('ge-btn-clearcell').addEventListener('click', clearCellFromTool);
    document.getElementById('ge-btn-clearcellkey').addEventListener('click', clearCellKeyFromTool);
    document.getElementById('ge-btn-get').addEventListener('click', getValueFromTool);
    document.getElementById('ge-btn-getcell').addEventListener('click', getCellFromTool);
    document.getElementById('ge-btn-get-all').addEventListener('click', getValueAllFromTool);
    document.getElementById('ge-btn-first').addEventListener('click', function () { grid.moveFirst(); refreshPagerDisplay(); });
    document.getElementById('ge-btn-prev').addEventListener('click', function () { grid.movePrevious(); refreshPagerDisplay(); });
    document.getElementById('ge-btn-next').addEventListener('click', function () { grid.moveNext(); refreshPagerDisplay(); });
    document.getElementById('ge-btn-last').addEventListener('click', function () { grid.moveLast(); refreshPagerDisplay(); });

    refreshPagerDisplay(); // 생성자가 이미 5x3 + clear()까지 끝낸 상태를 화면에 반영.
})();
</script>
</body>
</html>
