/**
 * TimePicker — 시/분을 고르는 팝업용 위젯.
 *
 * 바탕화면 `매출/시분초.png`(Access "시분초" 대화상자)를 재현할 목적의
 * 독립 JS 모듈 — `NumberKeypad`/`ChosungKeypad`와 마찬가지로 순수
 * DOM/이벤트만 다루고, 고른 시각을 어디에 쓸지(입실시각/퇴실시각 등)는
 * 전혀 모른다.
 *
 * `NumberKeypad`와 같은 "입력 중 vs 확정" 원칙 — 리스트박스에서 시/분을
 * 바꾸는 동안은 내부 상태만 갱신되고, 실제로 "이 시각을 쓰겠다"는 확정은
 * "확인" 버튼을 눌러야 한다(`confirm()`).
 *
 * `Calendar.js`/`NumberKeypad.js`/`ChosungGrid.js`와 동일한 "여는 쪽이
 * callback 이름을 지어서 주입한다" 관례(md/2026-09-11-action-callback-
 * 이름부여-규칙.md 참고) — `sender`/`callback` 옵션을 주면 "확인" 시
 * `sender.action(callback, {hour, minute}, window)`로 보고한다(기본
 * callback 이름은 `'callback_시분초'`). "취소"를 누르면
 * `sender.action(callback, {cancelled: true}, window)`로 취소를
 * 보고한다. 팝업을 닫는 것은 항상 여는 쪽 책임 — 이 위젯은 자기가
 * Popup 안에 있는지도 모른다.
 *
 * 초기값(지시 3) — `initial`을 안 주거나 `{hour, minute}` 형식이 아니면
 * 생성자가 곧바로 `new Date()`로 대체한다("선택 컨트롤에 값이 있으면
 * 그 값, 없으면 현재 시각"이라는 요구를 호출부가 매번 신경 쓸 필요 없이
 * 위젯 스스로 처리).
 *
 * 퀵버튼(현재시각/1시간전/30분전/10분전/5분전) — "현재시각"만 실제
 * 현재 시각으로 즉시 대치하고, 나머지는 **지금 화면에 선택돼 있는
 * 시각**에서 그만큼 뺀 값으로 이동한다(1440분 기준 순환 — 자정을
 * 넘나들어도 안전).
 *
 * 사용 예(화면에 직접 얹기 — sender/callback 없이 로컬 콜백만):
 *   new TimePicker({
 *       containerId: 'tp-picker',
 *       onConfirm: (value) => console.log('선택값:', value.hour, value.minute),
 *   });
 *
 * 사용 예(팝업으로 값 하나만 받기 — TimePicker.open() 참고):
 *   TimePicker.open({ ptitle: '시분초', callback: 'callback_시분초', sender: window });
 */
class TimePicker {
    /**
     * @param {{containerId: string, id?: string, initial?: {hour: number, minute: number},
     *   sender?: Window, callback?: string, onConfirm?: (value: {hour: number, minute: number}) => void,
     *   onCancel?: () => void}} options
     */
    constructor(options) {
        options = options || {};
        if (!options.containerId) {
            throw new Error('TimePicker: containerId는 필수입니다.');
        }
        this.containerId = options.containerId;
        this.id = options.id || this.containerId;
        this.sender = options.sender || null;
        // [여는 쪽이 콜백 이름을 지어서 넘기는 관례] 생략하면 기존
        // 이름(다른 위젯의 "callback_모듈명" 형태)으로 폴백.
        this.callback = typeof options.callback === 'string' && options.callback !== ''
            ? options.callback
            : 'callback_시분초';
        this.onConfirm = typeof options.onConfirm === 'function' ? options.onConfirm : null;
        this.onCancel = typeof options.onCancel === 'function' ? options.onCancel : null;

        const initial = options.initial;
        if (initial && typeof initial.hour === 'number' && typeof initial.minute === 'number') {
            this.hour = TimePicker._clampHour(initial.hour);
            this.minute = TimePicker._clampMinute(initial.minute);
        } else {
            // 선택 컨트롤에 값이 없으면 현재 시각을 선택한다(지시 3).
            const now = new Date();
            this.hour = now.getHours();
            this.minute = now.getMinutes();
        }

        TimePicker.instances[this.id] = this;
        TimePicker._injectDefaultStyles();
        this._render();
    }

    /** 지금 리스트박스에 선택돼 있는 값을 반환한다. */
    getValue() {
        return { hour: this.hour, minute: this.minute };
    }

    /** 외부에서 값을 강제로 맞춘다(콜백은 안 부름 — 순수 표시 동기화). */
    setValue(value) {
        if (value && typeof value.hour === 'number' && typeof value.minute === 'number') {
            this.hour = TimePicker._clampHour(value.hour);
            this.minute = TimePicker._clampMinute(value.minute);
        }
        this._syncSelectUI();
    }

    /**
     * "확인" 버튼과 동일한 동작을 코드로도 실행한다 — `onConfirm` 로컬
     * 콜백과, `sender`가 있으면 `sender.action(callback, {hour, minute},
     * window)` 보고를 함께 수행한다.
     */
    confirm() {
        const value = this.getValue();
        if (this.onConfirm) {
            this.onConfirm(value);
        }
        if (this.sender && typeof this.sender.action === 'function') {
            this.sender.action(this.callback, value, window);
        }
    }

    /** "취소" 버튼과 동일한 동작 — 값 없이 취소만 보고한다. */
    cancel() {
        if (this.onCancel) {
            this.onCancel();
        }
        if (this.sender && typeof this.sender.action === 'function') {
            this.sender.action(this.callback, { cancelled: true }, window);
        }
    }

    /** DOM/이벤트를 정리한다 — 팝업으로 열고 닫는 화면에서 필요. */
    destroy() {
        const container = document.getElementById(this.containerId);
        if (container) {
            container.innerHTML = '';
        }
        delete TimePicker.instances[this.id];
    }

    // ------------------------------------------------------------------
    // 내부
    // ------------------------------------------------------------------

    _render() {
        const container = document.getElementById(this.containerId);
        if (!container) {
            throw new Error('TimePicker: containerId 요소를 찾을 수 없습니다: ' + this.containerId);
        }
        container.classList.add('tp-picker');
        container.innerHTML = this._buildHtml();
        this._bindEvents(container);
        this._syncSelectUI();
    }

    _buildHtml() {
        let html = '<div class="tp-columns">';
        html += this._buildColumnHtml('시', 'tp-hour-select', 0, 23);
        html += this._buildColumnHtml('분', 'tp-minute-select', 0, 59);
        html += '</div>';

        html += '<div class="tp-quick-row">';
        TimePicker.QUICK_BUTTONS.forEach((btn) => {
            html += '<button type="button" class="tp-quick-btn" data-offset="' + btn.offset + '">' + btn.label + '</button>';
        });
        html += '</div>';

        html += '<div class="tp-actions">';
        html += '<button type="button" class="tp-cancel-btn">취소</button>';
        html += '<button type="button" class="tp-confirm-btn">확인</button>';
        html += '</div>';
        return html;
    }

    _buildColumnHtml(label, className, min, max) {
        let html = '<div class="tp-column">';
        html += '<div class="tp-column-header">' + this._escape(label) + '</div>';
        html += '<select class="' + className + '" size="10">';
        for (let i = min; i <= max; i++) {
            const text = (i < 10 ? '0' : '') + i;
            html += '<option value="' + i + '">' + text + '</option>';
        }
        html += '</select>';
        html += '</div>';
        return html;
    }

    _bindEvents(container) {
        const hourSelect = container.querySelector('.tp-hour-select');
        const minuteSelect = container.querySelector('.tp-minute-select');
        hourSelect.addEventListener('change', () => {
            this.hour = parseInt(hourSelect.value, 10);
        });
        minuteSelect.addEventListener('change', () => {
            this.minute = parseInt(minuteSelect.value, 10);
        });
        container.querySelectorAll('.tp-quick-btn').forEach((btn) => {
            btn.addEventListener('click', () => this._applyQuickButton(btn.getAttribute('data-offset')));
        });
        container.querySelector('.tp-cancel-btn').addEventListener('click', () => this.cancel());
        container.querySelector('.tp-confirm-btn').addEventListener('click', () => this.confirm());
    }

    /**
     * 퀵버튼 처리 — `'now'`(현재시각)만 실제 현재 시각으로 즉시
     * 대치하고, 그 외(분 단위 오프셋 문자열)는 "지금 선택된 시각 -
     * N분"을 1440분(24시간) 기준으로 순환 계산한다(자정을 넘나들어도
     * 항상 0~1439 범위 안으로 떨어지게).
     */
    _applyQuickButton(offsetAttr) {
        if (offsetAttr === 'now') {
            const now = new Date();
            this.hour = now.getHours();
            this.minute = now.getMinutes();
        } else {
            const offsetMinutes = parseInt(offsetAttr, 10);
            const total = this.hour * 60 + this.minute;
            const next = ((total - offsetMinutes) % 1440 + 1440) % 1440;
            this.hour = Math.floor(next / 60);
            this.minute = next % 60;
        }
        this._syncSelectUI();
    }

    _syncSelectUI() {
        const container = document.getElementById(this.containerId);
        if (!container) {
            return;
        }
        const hourSelect = container.querySelector('.tp-hour-select');
        const minuteSelect = container.querySelector('.tp-minute-select');
        if (hourSelect) {
            hourSelect.value = String(this.hour);
            this._centerSelectedOption(hourSelect);
        }
        if (minuteSelect) {
            minuteSelect.value = String(this.minute);
            this._centerSelectedOption(minuteSelect);
        }
    }

    /**
     * [2026-09-12 추가] "선택된 것들이 되도록 가운데 위치하게" 지시 —
     * `scrollIntoView({block:'nearest'})`는 "보이기만 하면" 그치는
     * 최소 이동이라, 화면 가장자리(맨 위/맨 아래 줄)에 선택 항목이 붙어
     * 보이는 경우가 흔했다. 대신 각 옵션의 실측 높이(`scrollHeight /
     * 옵션개수` — 모든 옵션이 같은 padding으로 높이가 균일하다는 전제)를
     * 근거로 "선택된 옵션이 보이는 영역(size=10)의 정중앙에 오도록" 필요한
     * `scrollTop`을 직접 계산해 대입한다. 목록의 맨 앞/맨 끝 근처라 다
     * 채울 수 없으면 브라우저가 `scrollTop`을 0~최대값 범위로 알아서
     * 잘라내(clamp) 자연스럽게 그 끝에 붙는다.
     */
    _centerSelectedOption(selectEl) {
        if (!selectEl || !selectEl.options || selectEl.options.length === 0) {
            return;
        }
        const index = selectEl.selectedIndex;
        if (index < 0) {
            return;
        }
        const optionHeight = selectEl.scrollHeight / selectEl.options.length;
        const visibleCount = selectEl.size || selectEl.options.length;
        const target = (index + 0.5) * optionHeight - (visibleCount * optionHeight) / 2;
        selectEl.scrollTop = Math.max(0, target);
    }

    _escape(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    static _clampHour(value) {
        let h = Math.trunc(value);
        if (h < 0) { h = 0; }
        if (h > 23) { h = 23; }
        return h;
    }

    static _clampMinute(value) {
        let m = Math.trunc(value);
        if (m < 0) { m = 0; }
        if (m > 59) { m = 59; }
        return m;
    }

    static _injectDefaultStyles() {
        if (document.getElementById('time-picker-default-styles')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'time-picker-default-styles';
        style.textContent = `
@layer time-picker-defaults {
    .tp-picker {
        display: inline-block; font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
    }
    /* [2026-09-12 추가] "리스트박스를 중앙정렬" 지시 — .tp-quick-row가
       flex-wrap으로 줄바꿈되더라도 그 "줄바꿈 없다고 가정한" 최대폭
       (max-content) 기준으로 .tp-picker(inline-block)의 shrink-to-fit
       너비가 잡혀, 결국 팝업 안 가용 너비를 거의 그대로 차지하게 된다
       (버튼 5~6개를 한 줄로 늘어놓은 폭이 리스트박스 2열(178px)보다
       훨씬 넓어서). 그 결과 고정폭인 .tp-columns가 그 넓은 박스 안에서
       기본값(flex-start)대로 왼쪽에 붙어버렸던 것 — justify-content:
       center로 이 박스 안에서 리스트박스 2열을 실제로 가운데 오게 한다. */
    .tp-columns { display: flex; gap: 10px; margin-bottom: 10px; justify-content: center; }
    .tp-column { display: flex; flex-direction: column; }
    .tp-column-header {
        background: #e5e7eb; border: 1px solid #d1d5db; border-bottom: none;
        text-align: center; font-weight: 600; font-size: 0.85rem; color: #374151;
        padding: 4px 0;
    }
    .tp-hour-select, .tp-minute-select {
        width: 84px; font-size: 1.05rem; border: 1px solid #d1d5db; border-radius: 0 0 4px 4px;
        padding: 2px 0;
    }
    .tp-hour-select option, .tp-minute-select option { padding: 3px 10px; }
    .tp-quick-row { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
    .tp-quick-btn {
        flex: 1 1 30%; min-width: 70px; padding: 6px 0; border: 1px solid #d1d5db; border-radius: 4px;
        background: #f3f4f6; color: #1f2937; font-size: 0.82rem; font-weight: 600; cursor: pointer;
        transition: background-color 0.15s ease;
    }
    .tp-quick-btn:hover { background: #e5e7eb; }
    .tp-actions { display: flex; gap: 8px; }
    .tp-cancel-btn {
        flex: 1; padding: 8px 0; border: 1px solid #d1d5db; border-radius: 4px;
        background: #f3f4f6; color: #374151; font-weight: 600; font-size: 0.9rem; cursor: pointer;
    }
    .tp-cancel-btn:hover { background: #e5e7eb; }
    .tp-confirm-btn {
        flex: 1; padding: 8px 0; border: none; border-radius: 4px;
        background: #1565c0; color: #fff; font-weight: 600; font-size: 0.9rem; cursor: pointer;
    }
    .tp-confirm-btn:hover { background: #0d47a1; }
}
        `;
        document.head.appendChild(style);
    }

    // ------------------------------------------------------------------
    // 정적 헬퍼 — Popup으로 열기(ChosungGrid.open()/NumberKeypad.open()과 동일한 관례)
    // ------------------------------------------------------------------

    /**
     * `Popup.js`의 iframeUrl로 `TimePicker_entry.php`를 띄우는 정적
     * 헬퍼 — `NumberKeypad.open()`과 동일한 형태.
     *
     * @param {{initial?: {hour: number, minute: number}, callback?: string,
     *   sender?: Window, ptitle?: string, id?: string, entryUrl?: string,
     *   iframeWidth?: (number|string), iframeHeight?: (number|string),
     *   modal?: boolean, draggable?: boolean, onClose?: (id: string) => void}} options
     *   `callback`(선택) — 확인/취소 시 `sender.action(...)`에 쓸 pcmd
     *   이름. 생략하면 `'callback_시분초'`. `initial`(선택) — 팝업을 열
     *   때 미리 선택해둘 시/분(없으면 위젯이 현재 시각으로 대체).
     * @return {Popup} 호출부가 나중에 직접 닫을 수 있도록 그 Popup 인스턴스를 그대로 반환.
     */
    static open(options) {
        options = options || {};
        if (typeof Popup === 'undefined') {
            throw new Error('TimePicker.open(): Popup.js가 먼저 로드돼 있어야 합니다.');
        }
        const entryUrl = options.entryUrl || TimePicker._defaultEntryUrl();
        const callbackName = typeof options.callback === 'string' && options.callback !== ''
            ? options.callback
            : 'callback_시분초';
        const initial = options.initial;
        let query = 'callback=' + encodeURIComponent(callbackName);
        if (initial && typeof initial.hour === 'number') {
            query += '&initialHour=' + encodeURIComponent(String(initial.hour));
        }
        if (initial && typeof initial.minute === 'number') {
            query += '&initialMinute=' + encodeURIComponent(String(initial.minute));
        }
        // ChosungGrid_entry.php/NumberKeypad_entry.php와 동일한 이유 —
        // 오래 떠 있는 부모 문서에서 반복해서 열어도 항상 새 요청이
        // 되도록 캐시 버스팅.
        query += '&_=' + Date.now();
        const iframeUrl = entryUrl + (entryUrl.indexOf('?') === -1 ? '?' : '&') + query;

        return new Popup({
            id: options.id,
            title: typeof options.ptitle === 'string' ? options.ptitle : '시분초',
            modal: options.modal,
            draggable: options.draggable,
            iframeUrl: iframeUrl,
            // 리스트박스 2열(84px×2+gap10) + 퀵버튼 2줄(6개, 3+3) + 액션행
            // 실측을 어림잡은 값 — TimePicker_entry.php가 overflow:hidden
            // 으로 스크롤바 자체를 막으므로, 내용이 이 크기를 넘치면
            // 스크롤바 대신 잘려 보인다("남은 이슈" 참고). 실제로 보고
            // 과부족 있으면 이 두 숫자만 조정.
            iframeWidth: options.iframeWidth || 300,
            iframeHeight: options.iframeHeight || 480,
            onClose: options.onClose,
        });
    }

    /** TimePicker.js와 같은 폴더의 TimePicker_entry.php를 기본 대상으로 삼는다. */
    static _defaultEntryUrl() {
        return TimePicker._SCRIPT_DIR + 'TimePicker_entry.php';
    }
}

/** `ChosungGrid`/`NumberKeypad`와 동일한 정적 레지스트리 패턴. */
TimePicker.instances = {};
TimePicker.instance = function (id) {
    return TimePicker.instances[id];
};

/** 퀵버튼 정의 — label(화면 표시)/offset('now' 또는 분 단위 숫자 문자열). */
TimePicker.QUICK_BUTTONS = [
    { label: '현재시각', offset: 'now' },
    { label: '1시간전', offset: '60' },
    { label: '30분전', offset: '30' },
    { label: '10분전', offset: '10' },
    { label: '5분전', offset: '5' },
    // [2026-09-12 추가] "5분전 옆에 1분전 버튼도 추가" 지시.
    { label: '1분전', offset: '1' },
];

/**
 * 이 스크립트 자신이 로드된 폴더의 절대경로 — `_defaultEntryUrl()`이
 * 같은 폴더의 `TimePicker_entry.php`를 항상 정확히 가리키게 한다
 * (`ChosungGrid.js`/`NumberKeypad.js`의 `_SCRIPT_DIR`과 완전히 동일한
 * 계산 방식 — `document.currentScript.src`에서 역산).
 */
TimePicker._SCRIPT_DIR = (function () {
    const src = document.currentScript && document.currentScript.src;
    if (!src) {
        return './';
    }
    return src.replace(/TimePicker\.js(\?.*)?$/, '');
})();
