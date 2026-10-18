/**
 * Talk — ttalk 테이블 기반 카톡형 채팅 위젯.
 *
 * 특정 외부 데이터(type + parentcode)에 달리는 채팅을 보여주고 입력받는다.
 * 화면은 위쪽 채팅영역(내것=오른쪽 노란바탕 / 남것=왼쪽 흰바탕)과 아래쪽
 * 입력영역(텍스트입력란 + 전송/새로고침 버튼)으로 나뉜다.
 *
 * 외부에서는 이 프로젝트의 `action(pcmd, pparams, psender)` 관례대로
 * `action()` 하나로만 호출한다:
 *
 *   const talk = new Talk('talk-root');
 *   talk.action('init', {
 *       type: '견적', parentcode: '123',
 *       logincode: 'A001', loginname: '홍길동',
 *       pollSeconds: 0,            // 0/생략 = 자동갱신 안 함
 *   });
 *
 * pcmd 목록:
 *   init        화면 틀을 그리고(_initDisplay) 바로 show
 *   show        talk_fetch.php에서 rows를 받아(_getrs) 내것/남것 버블로 다시 그린다(_showrs)
 *   getmystyle  {name, time, comment} → 내것 버블 html 문자열 반환
 *   getyourstyle{name, time, comment} → 남것 버블 html 문자열 반환
 *   setvalue    {whocode, whoname, datetime, comment} 한 건을 채팅영역 끝에 추가
 *   setdate     {datetime} 날짜 구분선('2026년 10월 1일 목요일')을 채팅영역 끝에 추가
 *   clear       채팅영역을 비운다
 *
 * 버튼: 전송 = 입력내용을 ttalk에 추가(talk_fetch.php mode=insert) 후 show,
 *       새로고침 = show. Enter는 줄바꿈(전송은 버튼으로만).
 * polling은 다음 단계.
 */
class Talk {
    /** @param {string} containerId 위젯을 그릴 요소의 id */
    constructor(containerId) {
        if (!containerId) {
            throw new Error('Talk: containerId는 필수입니다.');
        }
        this.containerId = containerId;
        this.type = '';
        this.parentcode = '';
        this.logincode = '';
        this.loginname = '';
        this.pollSeconds = 0;
        /** 마지막 getrs 결과 */
        this.rs = [];
    }

    /**
     * 외부 호출 창구.
     * @param {string} pcmd   위 docblock의 pcmd 목록 참고
     * @param {object} pparams
     * @param {Window} [psender]
     */
    action(pcmd, pparams, psender) {
        const p = pparams || {};
        switch (pcmd) {
            case 'init':
                return this.init(p.type, p.parentcode, p.logincode, p.loginname, p.pollSeconds);
            case 'show':
                return this.show();
            case 'getmystyle':
                return this.getmystyle(p.name, p.time, p.comment);
            case 'getyourstyle':
                return this.getyourstyle(p.name, p.time, p.comment);
            case 'setvalue':
                return this._setvalue(p);
            case 'setdate':
                return this.setdate(p.datetime);
            case 'clear':
                return this._clear();
            default:
                throw new Error('Talk: 알 수 없는 pcmd입니다: ' + pcmd);
        }
    }

    /**
     * @param {string} type        채팅 종류(ttalk.type)
     * @param {string} parentcode  관련 외부데이터 code(ttalk.parentcode)
     * @param {string} logincode   로그인 code — whocode와 같으면 "내것"
     * @param {string} loginname   로그인 이름
     * @param {number} [pollSeconds] 자동갱신 주기(초). 0/생략이면 안 함.
     */
    init(type, parentcode, logincode, loginname, pollSeconds) {
        this.type = type == null ? '' : String(type);
        this.parentcode = parentcode == null ? '' : String(parentcode);
        this.logincode = logincode == null ? '' : String(logincode);
        this.loginname = loginname == null ? '' : String(loginname);
        this.pollSeconds = Number(pollSeconds) > 0 ? Number(pollSeconds) : 0;

        Talk._injectDefaultStyles();
        this._initDisplay();
        return this.show();
    }

    /** 조회해서 채팅영역을 다시 그린다. @returns {Promise<object[]>} rows */
    async show() {
        const rows = await this._getrs();
        this._showrs();
        return rows;
    }

    /**
     * 내것 버블 html. 그림처럼 이름은 표시하지 않고, 시간은 버블 왼쪽 아래.
     * @param {string} name @param {string} time @param {string} comment
     */
    getmystyle(name, time, comment) {
        return '<div class="talk-row talk-my">' +
                   '<div class="talk-line">' +
                       '<span class="talk-time">' + this._escape(this._date(time)) + '</span>' +
                       '<div class="talk-bubble">' + this._escape(comment) + '</div>' +
                   '</div>' +
               '</div>';
    }

    /**
     * 남것 버블 html. 이름은 버블 위, 시간은 버블 오른쪽 아래.
     * @param {string} name @param {string} time @param {string} comment
     */
    getyourstyle(name, time, comment) {
        return '<div class="talk-row talk-your">' +
                   '<div class="talk-name">' + this._escape(name) + '</div>' +
                   '<div class="talk-line">' +
                       '<div class="talk-bubble">' + this._escape(comment) + '</div>' +
                       '<span class="talk-time">' + this._escape(this._date(time)) + '</span>' +
                   '</div>' +
               '</div>';
    }

    // ------------------------------------------------------------------
    // 내부
    // ------------------------------------------------------------------

    /** type + parentcode의 ttalk rows를 code 순으로 받아 this.rs에 담는다. */
    async _getrs() {
        const url = Talk._baseUrl() + 'talk_fetch.php' +
            '?mode=select' +
            '&type=' + encodeURIComponent(this.type) +
            '&parentcode=' + encodeURIComponent(this.parentcode);
        const res = await fetch(url, { cache: 'no-store' });
        const data = await res.json();
        if (!res.ok || !Array.isArray(data)) {
            throw new Error('Talk: 조회 실패 — ' + (data && data.error ? data.error : res.status));
        }
        this.rs = data;
        return data;
    }

    /** 입력란 내용을 ttalk에 추가하고 다시 show. 빈 내용이면 아무것도 안 함. */
    async _send() {
        const comment = this.textEl.value;
        if (comment.trim() === '') {
            this.textEl.focus();
            return;
        }
        // 응답 오기 전 연타로 같은 글이 두 번 들어가지 않게.
        if (this._sending) {
            return;
        }
        this._sending = true;
        try {
            const body = new URLSearchParams({
                mode: 'insert',
                type: this.type,
                parentcode: this.parentcode,
                whocode: this.logincode,
                whoname: this.loginname,
                comment: comment,
            });
            const res = await fetch(Talk._baseUrl() + 'talk_fetch.php', { method: 'POST', body: body });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error('Talk: 전송 실패 — ' + (data && data.error ? data.error : res.status));
            }
            this.textEl.value = '';
            this._syncSendBtn();
            this.textEl.focus();
        } finally {
            this._sending = false;
        }
        await this.show();
    }

    /**
     * this.rs 전체를 순서대로 그린다(기존 내용은 지우고). 앞 rec와 날짜가
     * 달라지는 지점마다(첫 rec 포함) setdate로 날짜 구분선을 넣는다.
     */
    _showrs() {
        this._clear();
        this.rs.forEach((row) => this._appendWithDate(row));
        this._scrollBottom();
    }

    /** row 한 건을 채팅영역 끝에 추가한다(날짜가 바뀌었으면 구분선 먼저). */
    _setvalue(row) {
        this._appendWithDate(row || {});
        this._scrollBottom();
    }

    /**
     * 날짜 구분선을 채팅영역 끝에 추가한다 — 카톡처럼 가운데 회색 알약에
     * 달력 아이콘 + '2026년 10월 1일 목요일'.
     * @param {string} p 'yyyy-mm-dd ...'
     */
    setdate(p) {
        if (!this.listEl) {
            throw new Error('Talk: init 전에는 그릴 수 없습니다.');
        }
        const ymd = Talk._ymd(p);
        this._lastDay = ymd ? ymd.join('-') : null;
        let text = String(p == null ? '' : p);
        if (ymd) {
            const day = '일월화수목금토'.charAt(new Date(ymd[0], ymd[1] - 1, ymd[2]).getDay());
            text = ymd[0] + '년 ' + ymd[1] + '월 ' + ymd[2] + '일 ' + day + '요일';
        }
        this.listEl.insertAdjacentHTML('beforeend',
            '<div class="talk-date"><span>' +
                '<svg viewBox="0 0 16 16" width="13" height="13" aria-hidden="true">' +
                    '<rect x="2" y="3" width="12" height="11" rx="1.5" fill="none" stroke="currentColor"/>' +
                    '<path d="M2 6.5h12M5.5 1.5v3M10.5 1.5v3" stroke="currentColor"/>' +
                '</svg>' +
                this._escape(text) +
            '</span></div>');
    }

    _clear() {
        this._lastDay = null;
        if (this.listEl) {
            this.listEl.innerHTML = '';
        }
    }

    _appendWithDate(row) {
        const ymd = Talk._ymd(row.datetime);
        if (ymd && ymd.join('-') !== this._lastDay) {
            this.setdate(row.datetime);
        }
        this._append(row);
    }

    /** 'yyyy-mm-dd ...' → [년, 월, 일](숫자). 형식이 다르면 null. */
    static _ymd(p) {
        const m = /^(\d{4})-(\d{1,2})-(\d{1,2})/.exec(String(p == null ? '' : p));
        return m ? [Number(m[1]), Number(m[2]), Number(m[3])] : null;
    }

    /** row.whocode가 로그인 code와 같으면 내것. */
    _ismy(row) {
        return String(row.whocode == null ? '' : row.whocode) === this.logincode;
    }

    _append(row) {
        if (!this.listEl) {
            throw new Error('Talk: init 전에는 그릴 수 없습니다.');
        }
        const html = this._ismy(row)
            ? this.getmystyle(row.whoname, row.datetime, row.comment)
            : this.getyourstyle(row.whoname, row.datetime, row.comment);
        this.listEl.insertAdjacentHTML('beforeend', html);
    }

    _scrollBottom() {
        if (this.listEl) {
            this.listEl.scrollTop = this.listEl.scrollHeight;
        }
    }

    /**
     * 말풍선 시간 — 'yyyy-mm-dd hh:mi:ss' → '오전 10:30'(날짜는 setdate
     * 구분선이 맡는다). 형식이 다르면 받은 그대로.
     */
    _date(p) {
        const s = String(p == null ? '' : p);
        const m = /\s(\d{1,2}):(\d{2})/.exec(s);
        if (!m) {
            return s;
        }
        const h = Number(m[1]);
        const h12 = h % 12 === 0 ? 12 : h % 12;
        return (h < 12 ? '오전 ' : '오후 ') + h12 + ':' + m[2];
    }

    /** talk.js가 있는 폴더 url — talk_fetch.php를 같은 폴더에서 찾기 위함. */
    static _baseUrl() {
        if (Talk._base == null) {
            const src = Talk._scriptSrc || '';
            Talk._base = src ? src.replace(/[^/]*(\?.*)?$/, '') : '';
        }
        return Talk._base;
    }

    /** 채팅영역 + 입력영역의 빈 틀을 그린다. */
    _initDisplay() {
        const container = document.getElementById(this.containerId);
        if (!container) {
            throw new Error('Talk: containerId 요소를 찾을 수 없습니다: ' + this.containerId);
        }
        container.classList.add('talk-widget');
        container.innerHTML =
            '<div class="talk-list"></div>' +
            '<div class="talk-input">' +
                // Enter는 textarea 기본 동작(줄바꿈) 그대로 — 전송은 버튼으로만.
                '<textarea class="talk-text" placeholder="메시지 입력"></textarea>' +
                // 카톡 PC처럼 하단 바: 새로고침=왼쪽 앞, 전송=오른쪽 끝.
                '<div class="talk-buttons">' +
                    '<button type="button" class="talk-refresh-btn">새로고침</button>' +
                    '<button type="button" class="talk-send-btn" disabled>전송</button>' +
                '</div>' +
            '</div>';

        this.listEl = container.querySelector('.talk-list');
        this.textEl = container.querySelector('.talk-text');
        this.sendBtn = container.querySelector('.talk-send-btn');
        // 글자가 있을 때만 전송 버튼을 노랗게 활성화.
        this.textEl.addEventListener('input', () => this._syncSendBtn());

        // 버튼 오류는 화면을 깨지 않고 콘솔에만 남긴다.
        const run = (fn) => () => fn().catch((e) => console.error(e));
        container.querySelector('.talk-send-btn').addEventListener('click', run(() => this._send()));
        container.querySelector('.talk-refresh-btn').addEventListener('click', run(() => this.show()));
    }

    _syncSendBtn() {
        this.sendBtn.disabled = this.textEl.value.trim() === '';
    }

    _escape(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    static _injectDefaultStyles() {
        if (document.getElementById('talk-default-styles')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'talk-default-styles';
        style.textContent = `
@layer talk-defaults {
    .talk-widget {
        display: flex; flex-direction: column; height: 100%; box-sizing: border-box;
        border: 1px solid #8fa9bf; background: #fff;
        font-family: '맑은 고딕', 'Malgun Gothic', sans-serif; font-size: 13px; color: #000;
    }
    .talk-list {
        flex: 1 1 auto; min-height: 0; overflow-y: auto;
        background: #9bbbd4; padding: 10px 12px; box-sizing: border-box;
    }
    /* 스크롤바: 카톡처럼 화살표/트랙 없이 회색 막대(thumb)만.
       Chrome/Edge는 ::-webkit-scrollbar, Firefox는 scrollbar-color로 처리한다.
       Chrome도 scrollbar-color를 지원하는데, 둘 다 있으면 그쪽이 우선이라
       webkit 방식이 무시된다. 그래서 표준 속성은 webkit을 모르는 브라우저에만 준다. */
    .talk-list::-webkit-scrollbar { width: 8px; }
    .talk-list::-webkit-scrollbar-track { background: transparent; }
    .talk-list::-webkit-scrollbar-thumb { background: rgba(0, 0, 0, 0.2); border-radius: 4px; }
    .talk-list::-webkit-scrollbar-thumb:hover { background: rgba(0, 0, 0, 0.35); }
    .talk-list::-webkit-scrollbar-button { display: none; }
    @supports not selector(::-webkit-scrollbar) {
        .talk-list { scrollbar-width: thin; scrollbar-color: rgba(0, 0, 0, 0.2) transparent; }
    }
    .talk-row { margin-bottom: 10px; }
    .talk-name { font-size: 11px; color: #333; margin: 0 0 4px 2px; }
    .talk-line { display: flex; align-items: flex-end; }
    .talk-my .talk-line { justify-content: flex-end; }
    .talk-bubble {
        max-width: 75%; white-space: pre-wrap; word-break: break-all; line-height: 1.4;
        padding: 7px; border-radius: 7px; font-size: 13px; font-family: '맑은 고딕', 'Malgun Gothic', sans-serif;
    }
    /* 내것(.me) */
    .talk-my .talk-bubble { margin: 5px; background-color: #fef01b; }
    /* 남것(.you) */
    .talk-your .talk-bubble { margin-left: 5px; background-color: #ffffff; }
    /* 말풍선 꼬리 — 버블 윗부분 바깥쪽에 작은 삼각형(내것=오른쪽, 남것=왼쪽).
       margin(5px) 자리에 들어가도록 폭 6px. */
    .talk-bubble { position: relative; }
    .talk-bubble::before {
        content: ''; position: absolute; top: 7px; width: 0; height: 0;
        border-top: 5px solid transparent; border-bottom: 5px solid transparent;
    }
    .talk-my .talk-bubble::before { right: -6px; border-left: 7px solid #fef01b; }
    .talk-your .talk-bubble::before { left: -6px; border-right: 7px solid #ffffff; }
    .talk-date { text-align: center; margin: 6px 0 12px; }
    .talk-date span {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 6px 16px; border-radius: 16px;
        background: rgba(255, 255, 255, 0.35); color: #333; font-size: 12px;
    }
    .talk-time { flex: none; font-size: 11px; color: #333333; }
    .talk-your .talk-time { margin-left: 5px; }
    .talk-input {
        flex: none; display: flex; flex-direction: column; background: #fff;
        border-top: 1px solid #d8dde2;
    }
    .talk-text {
        height: 72px; resize: none; box-sizing: border-box; width: 100%;
        border: none; padding: 10px 12px; font: inherit; background: #fff;
    }
    .talk-text:focus { outline: none; }
    .talk-text::placeholder { color: #a0a0a0; }
    .talk-buttons {
        display: flex; justify-content: space-between; align-items: center;
        padding: 0 8px 8px;
    }
    .talk-buttons button {
        width: 70px; height: 30px; padding: 0; white-space: nowrap;
        border-radius: 3px; font: inherit; font-size: 12px; cursor: pointer;
    }
    .talk-refresh-btn { border: 1px solid #d0d0d0; background: #fff; color: #555; }
    .talk-refresh-btn:hover { background: #f3f3f3; }
    /* 전송: 글자 없으면 회색 비활성, 있으면 노랑 */
    .talk-send-btn { border: 1px solid #fee500; background: #fee500; color: #000; }
    .talk-send-btn:hover { background: #f5dc00; }
    .talk-send-btn:disabled {
        border-color: #e5e5e5; background: #f0f0f0; color: #b4b4b4; cursor: default;
    }
}`;
        document.head.appendChild(style);
    }
}

// 스크립트 로드 시점의 자기 경로를 기억 — 다른 폴더의 화면에서 불러도
// talk_fetch.php를 talk.js 옆에서 찾는다.
Talk._scriptSrc = document.currentScript ? document.currentScript.src : '';
