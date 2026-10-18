/**
 * VariableBank — 앱 전역 값 저장소.
 *
 * Access에서 앱이 끝날 때까지 열어두던 숨은 저장소 폼(텍스트박스 ctlTxtXml에
 * XML로 값을 쌓던 getvalue/setvalue/exists/clear 모듈)을 웹으로 옮긴 것이다.
 * XML 대신 JSON으로 자료를 처리한다. 숨은 폼(iframe) 대신 셸(index) 창 안의
 * JS 개체로 둔다 — iframe은 수명이 index와 같아 얻는 게 없고 준비 시점/창
 * 사이 통신 문제만 늘렸기 때문(variableBank.md 참고).
 *
 * 셸(index)이 할 일:
 *  - 이 파일을 <script src>로 불러오기
 *  - 탭 iframe마다 data-tab-title="탭 제목" 속성(폼/컨트롤을 탭 제목으로 찾기 위함)
 *  - action()에서 VariableBank.handles(pcmd)인 pcmd를 VariableBank.action()으로 넘기기
 *
 * 탭 화면은 parent.action('variableBank', { cmd, ... }, window)로 부른다.
 * 셸 없이 한 페이지에서 직접 쓸 때는 VariableBank.call(cmd, pparams, psender).
 */
var VariableBank = (function () {
    'use strict';

    // 새로고침(F5)해도 값이 남도록 VBA의 텍스트박스 대신 sessionStorage에 쓴다.
    var STORAGE_KEY = 'variableBank';

    /** 별명 => { value, typename, isForm, isControl, ...추가 속성 } — VBA의 Lxml(cXml)에 해당. */
    var Ljson = null;

    // ── 내부: 준비/저장 (VBA readyXml / saveXml) ─────────────────────────

    /** 처음 쓸 때 한 번만 sessionStorage에서 읽어온다. */
    function readyJson() {
        if (Ljson !== null) {
            return;
        }
        var raw = '';
        try {
            raw = window.sessionStorage.getItem(STORAGE_KEY) || '';
        } catch (e) {
            raw = '';
        }
        try {
            var parsed = raw ? JSON.parse(raw) : {};
            Ljson = (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) ? parsed : {};
        } catch (e) {
            Ljson = {};
        }
    }

    /** 바뀔 때마다 sessionStorage에 전체 JSON을 다시 쓴다. */
    function saveJson() {
        try {
            window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(Ljson));
        } catch (e) {
            // 저장소 접근이 막힌 환경(사생활 보호 모드 등) — 메모리로만 유지.
        }
    }

    /** 창 경계를 넘는 값은 항상 JSON 복사본으로 주고받는다(참조 공유/끊김 방지). */
    function copy(v) {
        if (v === undefined) {
            return null;
        }
        return JSON.parse(JSON.stringify(v));
    }

    function isNullish(v) {
        return v === null || v === undefined;
    }

    // ── 내부: 폼/컨트롤 ↔ 문자열 (VBA cForm의 get_string_form 등) ─────────
    // 개체를 그대로 들고 있지 않고 "다시 찾는 방법"을 저장한다. Access의 폼
    // 이름처럼 **고정된 논리 이름**을 열쇠로 쓴다:
    //   폼     = { tab: 탭 제목, sub: 탭 안쪽 iframe 경로 }
    //   컨트롤 = { tab, sub, id: 요소 id }
    // 탭은 닫혔다 다시 열리면 새 iframe이 되지만 제목은 같으므로, 다시 찾으면
    // 새 창이 나온다(Access에서 폼을 다시 연 뒤 같은 이름으로 찾는 것과 같다).
    // 이 페이지에 탭 iframe이 없으면(셸 없이 단독 사용) tab은 null이고 sub는
    // 최상위 창부터의 경로다.

    function isWindowLike(v) {
        return !!v && typeof v === 'object' && v.window === v;
    }

    function isElementLike(v) {
        return !!v && typeof v === 'object' && v.nodeType === 1;
    }

    function tabFrames() {
        return document.querySelectorAll('iframe[data-tab-title]');
    }

    /** 창 → 그 창이 이 페이지의 탭 iframe이면 탭 제목, 아니면 null. */
    function tabTitleOf(win) {
        var frames = tabFrames();
        for (var i = 0; i < frames.length; i++) {
            if (frames[i].contentWindow === win) {
                return frames[i].getAttribute('data-tab-title');
            }
        }
        return null;
    }

    /** 탭 제목 → 지금 열려 있는 그 탭의 창. 닫혀 있으면 null(자동으로 열지 않는다). */
    function tabWindowOf(title) {
        var frames = tabFrames();
        for (var i = 0; i < frames.length; i++) {
            if (frames[i].getAttribute('data-tab-title') === title) {
                return frames[i].contentWindow || null;
            }
        }
        return null;
    }

    /**
     * base 창에서 win까지 내려가는 iframe 경로([{id}|{index}, ...]).
     * base 자신이면 빈 배열, win이 base 아래에 없으면 null.
     */
    function pathFrom(base, win) {
        var path = [];
        var w = win;
        while (w !== base) {
            if (w === w.parent) {
                return null;
            }
            var fe = w.frameElement;
            if (fe && fe.id) {
                path.unshift({ id: fe.id });
            } else {
                var frames = w.parent.frames;
                var idx = -1;
                for (var i = 0; i < frames.length; i++) {
                    if (frames[i] === w) { idx = i; break; }
                }
                path.unshift({ index: idx });
            }
            w = w.parent;
        }
        return path;
    }

    /** base 창에서 경로를 따라 내려간 창. 못 찾으면 null. */
    function descend(base, path) {
        var w = base;
        for (var i = 0; i < path.length && w; i++) {
            var step = path[i];
            if (step.id !== undefined) {
                var fe = w.document.getElementById(step.id);
                w = (fe && fe.contentWindow) ? fe.contentWindow : null;
            } else {
                w = (step.index >= 0 && step.index < w.frames.length) ? w.frames[step.index] : null;
            }
        }
        return w || null;
    }

    /** 창 → { tab, sub }. win 자신이나 그 위의 창 중 탭 창이 있으면 그 탭 기준. */
    function locateWindow(win) {
        var w = win;
        while (true) {
            var title = tabTitleOf(w);
            if (title) {
                return { tab: title, sub: pathFrom(w, win) };
            }
            if (w === w.parent) {
                break;
            }
            w = w.parent;
        }
        return { tab: null, sub: pathFrom(window.top, win) || [] };
    }

    /** { tab, sub } → 지금 열려 있는 창. 탭이 닫혀 있으면 null. */
    function resolveWindow(loc) {
        var base = isNullish(loc.tab) ? window.top : tabWindowOf(loc.tab);
        return base ? descend(base, loc.sub || []) : null;
    }

    function get_string_form(win) {
        var loc = locateWindow(win);
        loc.href = String(win.location.pathname);
        return JSON.stringify(loc);
    }

    function get_string_control(el) {
        if (!el.id) {
            throw new Error('컨트롤은 id가 있어야 저장할 수 있습니다.');
        }
        var loc = locateWindow(el.ownerDocument.defaultView);
        loc.id = el.id;
        return JSON.stringify(loc);
    }

    function get_form_string(str) {
        return resolveWindow(JSON.parse(str));
    }

    function get_control_string(str) {
        var loc = JSON.parse(str);
        var win = resolveWindow(loc);
        return win ? win.document.getElementById(loc.id) : null;
    }

    // ── 공개 함수 (VBA와 같은 이름/순서) ─────────────────────────────────

    /** getxmlstring에 해당 — 저장소 전체 JSON 문자열. */
    function getJsonString() {
        readyJson();
        return JSON.stringify(Ljson);
    }

    /** getxml에 해당 — 저장소 전체(복사본 개체). */
    function getJson() {
        readyJson();
        return copy(Ljson);
    }

    /** 별명에 value가 있는지. */
    function exists(pnickname) {
        readyJson();
        var rec = Ljson[pnickname];
        return !!rec && !isNullish(rec.value);
    }

    /**
     * 값을 꺼낸다. 폼/컨트롤로 저장된 값은 지금 열려 있는 창/요소를 다시
     * 찾아 돌려준다(못 찾으면 null). VBA의 ByRef preturn/preturnType처럼
     * pparams 개체에 preturn/preturnType('form'|'control'|'string')도 써준다.
     */
    function getvalue(pparams) {
        readyJson();
        var pnickname = pparams.pnickname;
        var pattname = pparams.pattname || 'value';
        var rec = Ljson[pnickname] || {};
        var raw = rec[pattname];
        var result;
        var type;

        if (String(rec.isForm) === '1') {
            result = isNullish(raw) || raw === '' ? null : get_form_string(raw);
            type = 'form';
        } else if (String(rec.isControl) === '1') {
            result = isNullish(raw) || raw === '' ? null : get_control_string(raw);
            type = 'control';
        } else {
            result = isNullish(raw) ? null : copy(raw);
            type = 'string';
        }

        pparams.preturn = result;
        pparams.preturnType = type;
        return result;
    }

    /**
     * 값을 저장한다. 창(폼)/요소(컨트롤)는 다시 찾는 문자열로 바꿔 저장하고
     * typename/isForm/isControl 표시를 남긴다. 그 밖의 값은 JSON 값 그대로
     * 저장한다(VBA는 CStr로 문자열화했지만 JSON은 숫자/불린/배열도 담을 수 있다).
     * pattname을 주면 value가 아닌 그 이름의 속성에 저장한다.
     */
    function setvalue(pparams) {
        readyJson();
        var pnickname = pparams.pnickname;
        var pattname = pparams.pattname || 'value';
        var p_value = pparams.pvalue;
        var rec = Ljson[pnickname] || {};

        if (isWindowLike(p_value)) {
            rec[pattname] = get_string_form(p_value);
            rec.typename = 'form';
            rec.isControl = '0';
            rec.isForm = '1';
        } else if (isElementLike(p_value)) {
            rec[pattname] = get_string_control(p_value);
            rec.typename = 'control';
            rec.isControl = '1';
            rec.isForm = '0';
        } else {
            rec[pattname] = copy(p_value);
            if (pattname === 'value') {
                rec.typename = 'string';
                rec.isControl = '0';
                rec.isForm = '0';
            }
        }

        Ljson[pnickname] = rec;
        saveJson();
        return true;
    }

    /** 별명을 주면 그 하나만, 안 주면 저장소 전체를 지운다. */
    function clear(pkey) {
        readyJson();
        if (!isNullish(pkey) && pkey !== '') {
            delete Ljson[pkey];
        } else {
            Ljson = {};
        }
        saveJson();
        return true;
    }

    // ── 창구 ────────────────────────────────────────────────────────────

    /**
     * 저장소 명령 하나를 실행한다. 실패하면 VBA의 On Error Resume Next처럼
     * 죽지 않고 null을 돌려주되, 원인은 pparams.error와 console.warn으로 남긴다.
     */
    function call(cmd, pparams, psender) {
        pparams = pparams || {};
        try {
            switch (cmd) {
                case 'getJsonString': return getJsonString();
                case 'getJson':       return getJson();
                case 'exists':        return exists(pparams.pnickname);
                case 'getvalue':      return getvalue(pparams);
                case 'setvalue':      return setvalue(pparams);
                case 'clear':         return clear(pparams.pkey);
                default:
                    throw new Error('variableBank가 모르는 명령입니다: ' + cmd);
            }
        } catch (e) {
            pparams.error = String(e && e.message ? e.message : e);
            console.warn('[variableBank]', cmd, pparams.error);
            return null;
        }
    }

    return {
        call: call,

        /** 셸 action()의 라우팅용 — 이 파일이 처리하는 pcmd인지. */
        handles: function (pcmd) {
            return pcmd === 'variableBank' || pcmd === 'variableBankAvailable';
        },

        /**
         * 셸 action()이 넘기는 창구.
         *  - variableBank          { cmd, ...인자 } → call(cmd, ...). pparams 개체를
         *    그대로 쓰므로 preturn/preturnType/error가 호출부에 보인다.
         *  - variableBankAvailable 이 셸이 저장소를 갖고 있는지 → true
         */
        action: function (pcmd, pparams, psender) {
            switch (pcmd) {
                case 'variableBank':          return call(pparams && pparams.cmd, pparams, psender);
                case 'variableBankAvailable': return true;
            }
            return undefined;
        },
    };
})();
