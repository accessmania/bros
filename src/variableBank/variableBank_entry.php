<?php
declare(strict_types=1);

/**
 * variableBank 연습(자기 검증) 화면 — 코드 옆에 자기 검증 페이지를 두는
 * 이 프로젝트 관례(Domain_entry.php, Calendar_entry.php 등)를 따른다.
 *
 * 두 가지로 동작한다.
 *  - index 탭 안에서 열면(메뉴 현장업무 > variableBank) index가 불러둔
 *    저장소(variableBank.js)를 parent.action('variableBank', { cmd, ... })로
 *    쓴다 — 실제 앱과 같은 경로. 폼/컨트롤은 "탭 제목" 기준으로 저장되므로,
 *    이 탭을 닫았다 다시 열어도 같은 별명으로 새 창/요소를 다시 찾는다.
 *  - index 밖에서 직접 열면 이 화면에 불러온 variableBank.js를
 *    VariableBank.call()로 직접 부른다(탭이 없어 폼/컨트롤은 최상위 창 기준
 *    경로로 저장).
 */
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>variableBank 연습</title>
<style>
:root {
    --vb-bg: #f5f6f8;
    --vb-panel: #fff;
    --vb-line: #d0d7de;
    --vb-text: #1f2328;
    --vb-muted: #57606a;
    --vb-accent: #0969da;
    --vb-ok: #1a7f37;
    --vb-err: #cf222e;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    padding: 20px 16px;
    font-family: 'Segoe UI', Inter, system-ui, sans-serif;
    background: var(--vb-bg);
    color: var(--vb-text);
}
h1 { font-size: 1.2rem; margin: 0 0 4px; }
.vb-sub { color: var(--vb-muted); font-size: 0.85rem; margin: 0 0 6px; }
.vb-mode { display: inline-block; font-size: 0.78rem; padding: 2px 10px; border-radius: 10px; background: #000; color: #ffeb3b; margin-bottom: 14px; }
.vb-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; }
@media (max-width: 760px) { .vb-grid { grid-template-columns: minmax(0, 1fr); } }
.vb-panel {
    background: var(--vb-panel);
    border: 1px solid var(--vb-line);
    border-radius: 6px;
    padding: 14px 16px;
    min-width: 0;
}
.vb-panel h2 { font-size: 0.95rem; margin: 0 0 10px; }
.vb-panel h2 + h2, .vb-panel .vb-gap { margin-top: 14px; }
label { display: block; font-size: 0.8rem; color: var(--vb-muted); margin: 8px 0 3px; }
input[type="text"], select, textarea {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid var(--vb-line);
    border-radius: 4px;
    font: inherit;
}
textarea { min-height: 70px; font-family: Consolas, 'D2Coding', monospace; font-size: 0.85rem; }
.vb-buttons { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; }
button {
    padding: 6px 12px;
    border: 1px solid var(--vb-line);
    border-radius: 4px;
    background: #f6f8fa;
    font: inherit;
    font-size: 0.85rem;
    cursor: pointer;
}
button.vb-primary { background: var(--vb-accent); border-color: var(--vb-accent); color: #fff; }
.vb-hint { font-size: 0.78rem; color: var(--vb-muted); margin-top: 4px; }
#vbDemoInput.vb-found { outline: 3px solid #ffeb3b; outline-offset: 1px; }
#vbBankJson { min-height: 240px; background: #fbfbfc; }
#vbResult {
    margin: 0;
    padding: 8px 10px;
    min-height: 48px;
    background: #f6f8fa;
    border-radius: 4px;
    font: 12px/1.5 Consolas, 'D2Coding', monospace;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
#vbLog { margin: 0; padding-left: 18px; font-size: 0.8rem; max-height: 180px; overflow: auto; }
#vbLog .ok { color: var(--vb-ok); }
#vbLog .err { color: var(--vb-err); }
.vb-steps { margin: 0; padding-left: 18px; font-size: 0.8rem; color: var(--vb-muted); line-height: 1.6; }
</style>
</head>
<body>
<h1>variableBank 연습</h1>
<p class="vb-sub">앱 전역 값 저장소(VBA getvalue/setvalue/exists/clear의 JSON판).</p>
<span class="vb-mode" id="vbMode">연결 확인 중…</span>

<div class="vb-grid">
    <div class="vb-panel">
        <h2>입력</h2>

        <label for="vbNickname">별명 (pnickname)</label>
        <input type="text" id="vbNickname" value="userName">

        <label for="vbAttname">속성 (pattname)</label>
        <input type="text" id="vbAttname" value="value">

        <label for="vbKind">값 종류 (setvalue용)</label>
        <select id="vbKind">
            <option value="string">문자열</option>
            <option value="number">숫자</option>
            <option value="json">JSON(개체/배열/불린)</option>
            <option value="control">컨트롤 — 아래 데모 입력칸</option>
            <option value="form">폼 — 이 연습 화면(이 탭)</option>
            <option value="otherForm">폼 — 다른 탭(값 칸에 탭 제목)</option>
        </select>

        <label for="vbValue">값 (pvalue)</label>
        <textarea id="vbValue">홍길동</textarea>
        <div class="vb-hint">컨트롤/이 화면 폼을 고르면 이 칸은 쓰지 않습니다. "다른 탭"은 이 칸에 열려 있는 탭 제목(예: 룸입력)을 적습니다.</div>

        <label for="vbDemoInput">데모 컨트롤 (id = vbDemoInput)</label>
        <input type="text" id="vbDemoInput" value="데모 컨트롤 값">

        <div class="vb-buttons">
            <button type="button" class="vb-primary" data-cmd="setvalue">setvalue</button>
            <button type="button" data-cmd="getvalue">getvalue</button>
            <button type="button" data-cmd="exists">exists</button>
            <button type="button" data-cmd="clearOne">clear(이 별명)</button>
            <button type="button" data-cmd="clearAll">clear(전체)</button>
            <button type="button" data-cmd="getJsonString">getJsonString</button>
        </div>

        <h2 class="vb-gap">폼/컨트롤 다시 찾기 확인</h2>
        <ol class="vb-steps">
            <li>값 종류 "컨트롤"로 별명 <code>focusInput</code>에 setvalue</li>
            <li>이 탭(variableBank)을 닫고 메뉴에서 다시 연다</li>
            <li>별명 <code>focusInput</code>을 getvalue → 새로 열린 이 화면의 데모 입력칸이 노란 테두리로 표시된다</li>
            <li>탭을 닫은 채로는 다른 탭에서 getvalue하면 <code>null</code>(닫힌 폼)</li>
        </ol>
    </div>

    <div class="vb-panel">
        <h2>저장소 JSON</h2>
        <textarea id="vbBankJson" readonly aria-label="저장소 JSON"></textarea>

        <h2 class="vb-gap">결과</h2>
        <pre id="vbResult"></pre>

        <h2 class="vb-gap">로그</h2>
        <ol id="vbLog"></ol>
    </div>
</div>

<!-- index 밖에서 직접 열었을 때 쓸 저장소. index 안에서는 index의 저장소를 쓴다. -->
<script src="variableBank.js"></script>
<script>
(function () {
    'use strict';

    var resultBox = document.getElementById('vbResult');
    var logBox = document.getElementById('vbLog');
    var buttons = document.querySelectorAll('[data-cmd]');
    var demoInput = document.getElementById('vbDemoInput');

    // ── 저장소 연결: index 안이면 index의 저장소, 아니면 이 화면에 불러온 저장소 ──

    var host = null;      // index(셸) — parent.action('variableBank', ...)
    var jsonView = document.getElementById('vbBankJson');

    try {
        if (window.parent !== window && typeof window.parent.action === 'function'
            && window.parent.action('variableBankAvailable', {}, window) === true) {
            host = window.parent;
        }
    } catch (e) {
        host = null;
    }

    /** 저장소 명령 하나 — 두 모드의 차이를 여기서만 흡수한다. */
    function callBank(cmd, p) {
        if (host) {
            p.cmd = cmd;
            return host.action('variableBank', p, window);
        }
        return VariableBank.call(cmd, p, window);
    }

    function refreshJson() {
        var text = callBank('getJsonString', {});
        jsonView.value = text ? JSON.stringify(JSON.parse(text), null, 2) : '';
    }

    function log(text, ok) {
        var li = document.createElement('li');
        li.className = ok ? 'ok' : 'err';
        li.textContent = new Date().toLocaleTimeString() + '  ' + text;
        logBox.insertBefore(li, logBox.firstChild);
    }

    function show(value) {
        resultBox.textContent = value;
    }

    /** setvalue에 넘길 값을 "값 종류"에 맞게 만든다. */
    function readValue() {
        var kind = document.getElementById('vbKind').value;
        var text = document.getElementById('vbValue').value;
        switch (kind) {
            case 'number': return Number(text);
            case 'json': return JSON.parse(text);
            case 'control': return demoInput;
            case 'form': return window;
            case 'otherForm':
                if (!host) {
                    throw new Error('"다른 탭"은 index 안에서 열었을 때만 쓸 수 있습니다.');
                }
                // index의 탭 iframe은 data-tab-title로 찾는다(같은 origin이라 직접 접근).
                var frames = host.document.querySelectorAll('iframe[data-tab-title]');
                for (var k = 0; k < frames.length; k++) {
                    if (frames[k].getAttribute('data-tab-title') === text.trim() && frames[k].contentWindow) {
                        return frames[k].contentWindow;
                    }
                }
                throw new Error('열려 있는 탭이 없습니다: ' + text.trim());
            default: return text;
        }
    }

    /** getvalue 결과(창/요소/값)를 사람이 읽을 문자열로. */
    function describe(value, type) {
        demoInput.classList.remove('vb-found');
        if (value === null) {
            return type === 'string' ? 'null' : 'null — 지금 열려 있지 않습니다(닫힌 탭/없는 요소).';
        }
        if (type === 'control') {
            if (value === demoInput) {
                demoInput.classList.add('vb-found');
            }
            var where = value.ownerDocument === document ? '이 화면' : value.ownerDocument.title;
            return '<' + value.tagName.toLowerCase() + ' id="' + value.id + '"> (' + where + ') — 현재 값: ' + JSON.stringify(value.value);
        }
        if (type === 'form') {
            var self = value === window ? ' ← 이 화면' : '';
            return '창: ' + value.location.pathname + ' (제목: ' + value.document.title + ')' + self;
        }
        return JSON.stringify(value, null, 2);
    }

    function run(cmd) {
        var nickname = document.getElementById('vbNickname').value;
        var attname = document.getElementById('vbAttname').value || 'value';
        var p;
        var r;
        try {
            switch (cmd) {
                case 'setvalue':
                    p = { pnickname: nickname, pattname: attname, pvalue: readValue() };
                    r = callBank('setvalue', p);
                    break;
                case 'getvalue':
                    p = { pnickname: nickname, pattname: attname };
                    r = callBank('getvalue', p);
                    if (!p.error) {
                        show('preturnType: ' + p.preturnType + '\n' + describe(r, p.preturnType));
                        log('getvalue(' + nickname + ', ' + attname + ') → ' + p.preturnType + (r === null ? ' / null' : ''), true);
                        return;
                    }
                    break;
                case 'exists':
                    p = { pnickname: nickname };
                    r = callBank('exists', p);
                    break;
                case 'clearOne':
                    p = { pkey: nickname };
                    r = callBank('clear', p);
                    break;
                case 'clearAll':
                    p = {};
                    r = callBank('clear', p);
                    break;
                case 'getJsonString':
                    p = {};
                    r = callBank('getJsonString', p);
                    break;
            }
        } catch (e) {
            // readValue()의 JSON.parse 실패 등 — 저장소 밖(이 화면)에서 난 오류.
            show('오류: ' + e.message);
            log(cmd + ' 실패: ' + e.message, false);
            return;
        } finally {
            refreshJson();
        }

        if (p.error) {
            show('오류: ' + p.error);
            log(cmd + ' 실패: ' + p.error, false);
            return;
        }
        show(typeof r === 'string' ? r : JSON.stringify(r, null, 2));
        var arg = (cmd === 'clearAll' || cmd === 'getJsonString') ? '' : nickname;
        log(cmd + '(' + arg + ') → ' + (typeof r === 'string' ? '문자열' : JSON.stringify(r)), true);
    }

    for (var i = 0; i < buttons.length; i++) {
        buttons[i].addEventListener('click', function () {
            run(this.getAttribute('data-cmd'));
        });
    }

    if (host) {
        document.getElementById('vbMode').textContent = 'index 저장소 사용 (실제 앱 경로)';
        show('index의 variableBank에 연결됨 — 폼/컨트롤은 탭 제목 기준으로 저장됩니다.');
    } else {
        document.getElementById('vbMode').textContent = '단독 실행 (이 화면에 불러온 variableBank.js)';
        show('단독 실행 — 이전 값이 있으면 sessionStorage에서 복원됩니다.');
    }
    refreshJson();
})();
</script>
</body>
</html>
