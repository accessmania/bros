/**
 * Memo — 큰 텍스트영역 하나로 메모를 입력/수정하는 팝업 전용 위젯.
 *
 * `ChosungKeypad.js`/`NumberKeypad.js`와 마찬가지로 순수 DOM/이벤트만
 * 다루고, 입력한 값의 쓰임(어느 레코드의 메모인지 등)은 전혀 모른다.
 * 구조는 `NumberKeypad`보다 단순하다 — 키패드 없이 `<textarea>` 하나 +
 * 하단 버튼 두 개(취소/저장)뿐.
 *
 * [2026-09-11 신설] `Calendar.js`/`ChosungGrid.js`/`NumberKeypad.js`와
 * 동일한 "여는 쪽이 callback 이름을 지어서 주입한다" 관례
 * (md/2026-09-11-action-callback-이름부여-규칙.md 참고) — `sender`/
 * `callback` 옵션을 주면 "저장" 시 `sender.action(callback, {value},
 * window)`로 보고한다(기본 callback 이름은 `'callback_Memo_메모'`,
 * 지시 원문 그대로). "취소"도 **같은 callback**으로
 * `{value: null, cancelled: true}`를 보고한다 — 지시엔 "저장을 누르면
 * 콜백"만 있었지만, 팝업을 닫는 것은 항상 여는 쪽 책임이라는 이
 * 계열 위젯 전체의 원칙상 취소 경로도 같은 창구로 알려줘야 여는 쪽이
 * 팝업을 닫을 수 있다(`ChosungGrid`의 Quit/`NumberKeypad`의 Quit과
 * 동일한 이유).
 *
 * 사용 예(화면에 직접 얹기 — sender/callback 없이 로컬 콜백만):
 *   new Memo({
 *       containerId: 'memo-box',
 *       initial: '기존 메모',
 *       onSave: (value) => console.log('저장값:', value),
 *   });
 *
 * 사용 예(팝업으로 메모 하나만 받기 — Memo.open() 참고):
 *   Memo.open({ ptitle: '메모 입력', initial: row.메모, callback: 'callback_Memo_메모', sender: window });
 */
class Memo {
    /**
     * @param {{containerId: string, caption?: string, initial?: string,
     *   sender?: Window, callback?: string, onSave?: (value: string) => void,
     *   onCancel?: () => void}} options
     */
    constructor(options) {
        options = options || {};
        if (!options.containerId) {
            throw new Error('Memo: containerId는 필수입니다.');
        }
        this.containerId = options.containerId;
        // 텍스트영역 위에 "무엇에 대한 메모인지" 보여줄 안내 문구 —
        // NumberKeypad의 caption과 동일한 자리/역할.
        this.caption = typeof options.caption === 'string' ? options.caption : '';
        this.sender = options.sender || null;
        this.callback = typeof options.callback === 'string' && options.callback !== ''
            ? options.callback
            : 'callback_Memo_메모';
        this.onSave = typeof options.onSave === 'function' ? options.onSave : null;
        this.onCancel = typeof options.onCancel === 'function' ? options.onCancel : null;
        // "그 지점의 값을 창이 띄워질 때 화면에 보여지고" — 초기값을
        // 텍스트영역에 그대로 채운다.
        this.value = options.initial != null ? String(options.initial) : '';

        Memo._injectDefaultStyles();
        this._render();
    }

    /** 지금 텍스트영역에 있는(아직 저장 전인) 값을 반환한다. */
    getValue() {
        return this.value;
    }

    /** 외부에서 표시값을 강제로 맞춘다(콜백은 안 부름 — 순수 표시 동기화). */
    setValue(value) {
        this.value = value == null ? '' : String(value);
        this._updateTextarea();
    }

    /** 캡션(텍스트영역 위 안내 문구)을 나중에 바꾼다. */
    setCaption(caption) {
        this.caption = typeof caption === 'string' ? caption : '';
        this._render();
    }

    /**
     * "저장" 버튼과 동일한 동작을 코드로도 실행한다 — `onSave` 로컬
     * 콜백과, `sender`가 있으면 `sender.action(callback, {value}, window)`
     * 보고를 함께 수행한다(지시 원문 "저장을 누르면 callback_Memo_메모가
     * 콜백으로 실행되어지게"가 가리키는 그 경로).
     */
    save() {
        if (this.onSave) {
            this.onSave(this.value);
        }
        if (this.sender && typeof this.sender.action === 'function') {
            this.sender.action(this.callback, { value: this.value }, window);
        }
    }

    /**
     * "취소" 버튼과 동일한 동작 — 위 클래스 docblock 참고, 같은
     * callback으로 `cancelled: true`를 보고한다(여는 쪽이 팝업을 닫을
     * 수 있게).
     */
    cancel() {
        if (this.onCancel) {
            this.onCancel();
        }
        if (this.sender && typeof this.sender.action === 'function') {
            this.sender.action(this.callback, { value: null, cancelled: true }, window);
        }
    }

    /** DOM/이벤트를 정리한다 — 팝업으로 열고 닫는 화면에서 필요. */
    destroy() {
        const container = document.getElementById(this.containerId);
        if (container) {
            container.innerHTML = '';
        }
    }

    // ------------------------------------------------------------------
    // 내부
    // ------------------------------------------------------------------

    _render() {
        const container = document.getElementById(this.containerId);
        if (!container) {
            throw new Error('Memo: containerId 요소를 찾을 수 없습니다: ' + this.containerId);
        }
        container.classList.add('memo-widget');
        container.innerHTML = this._buildHtml();
        this._bindEvents(container);
    }

    _buildHtml() {
        let html = '';
        if (this.caption) {
            html += '<div class="memo-caption">' + this._escape(this.caption) + '</div>';
        }
        // "메모를 입력하는 란을 크게 보여주고" — flex:1로 남는 세로
        // 공간을 텍스트영역이 전부 차지하게 한다(레이아웃은 이 위젯을
        // 담는 컨테이너의 높이에 따름 — Memo.open()이 팝업 높이를
        // 넉넉하게 잡아준다).
        html += '<textarea class="memo-textarea">' + this._escape(this.value) + '</textarea>';
        html += '<div class="memo-actions">';
        html += '<button type="button" class="memo-cancel-btn">취소</button>';
        html += '<button type="button" class="memo-save-btn">저장</button>';
        html += '</div>';
        return html;
    }

    _bindEvents(container) {
        const textarea = container.querySelector('.memo-textarea');
        textarea.addEventListener('input', () => {
            this.value = textarea.value;
        });
        container.querySelector('.memo-cancel-btn').addEventListener('click', () => this.cancel());
        container.querySelector('.memo-save-btn').addEventListener('click', () => this.save());
    }

    _updateTextarea() {
        const container = document.getElementById(this.containerId);
        if (!container) {
            return;
        }
        const textarea = container.querySelector('.memo-textarea');
        if (textarea) {
            textarea.value = this.value;
        }
    }

    _escape(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    static _injectDefaultStyles() {
        if (document.getElementById('memo-default-styles')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'memo-default-styles';
        style.textContent = `
@layer memo-defaults {
    .memo-widget {
        display: flex; flex-direction: column; height: 100%; box-sizing: border-box;
        font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
    }
    .memo-caption {
        font-size: 0.85rem; font-weight: 600; color: #374151; margin-bottom: 6px; flex: none;
    }
    .memo-textarea {
        flex: 1 1 auto; min-height: 120px; width: 100%; box-sizing: border-box;
        resize: none; border: 1px solid #d1d5db; border-radius: 6px;
        padding: 10px 12px; font: inherit; font-size: 0.95rem; color: #1f2d3d;
        background: #fff;
    }
    .memo-textarea:focus { outline: none; border-color: #1565c0; box-shadow: 0 0 0 2px rgba(21,101,192,0.15); }
    .memo-actions { display: flex; gap: 8px; margin-top: 10px; flex: none; }
    .memo-cancel-btn {
        flex: 1; padding: 8px 0; border: 1px solid #d1d5db; border-radius: 4px;
        background: #f3f4f6; color: #374151; font-weight: 600; font-size: 0.9rem; cursor: pointer;
    }
    .memo-cancel-btn:hover { background: #e5e7eb; }
    .memo-save-btn {
        flex: 1; padding: 8px 0; border: none; border-radius: 4px;
        background: #1565c0; color: #fff; font-weight: 600; font-size: 0.9rem; cursor: pointer;
    }
    .memo-save-btn:hover { background: #0d47a1; }
}
        `;
        document.head.appendChild(style);
    }

    // ------------------------------------------------------------------
    // 정적 헬퍼 — Popup으로 열기(ChosungGrid.open()/NumberKeypad.open()과 동일한 관례)
    // ------------------------------------------------------------------

    /**
     * `Popup.js`의 iframeUrl로 `Memo_entry.php`를 띄우는 정적 헬퍼 —
     * "어떤 지점을 클릭하면 팝업으로 창이 뜬다"는 지시가 가리키는 진입점.
     *
     * @param {{caption?: string, initial?: string, callback?: string,
     *   sender?: Window, ptitle?: string, id?: string, entryUrl?: string,
     *   iframeWidth?: (number|string), iframeHeight?: (number|string),
     *   modal?: boolean, draggable?: boolean, onClose?: (id: string) => void}} options
     *   `callback`(선택) — 저장/취소 시 `sender.action(...)`에 쓸 pcmd
     *   이름. 생략하면 `'callback_Memo_메모'`(지시 원문 그대로). "callback은
     *   띄우기전에 주입하게" — 이 옵션이 정확히 그 자리.
     * @return {Popup} 호출부가 나중에 직접 닫을 수 있도록 그 Popup 인스턴스를 그대로 반환.
     */
    static open(options) {
        options = options || {};
        if (typeof Popup === 'undefined') {
            throw new Error('Memo.open(): Popup.js가 먼저 로드돼 있어야 합니다.');
        }
        const entryUrl = options.entryUrl || Memo._defaultEntryUrl();
        const callbackName = typeof options.callback === 'string' && options.callback !== ''
            ? options.callback
            : 'callback_Memo_메모';
        const query = 'callback=' + encodeURIComponent(callbackName) +
            (typeof options.caption === 'string' && options.caption !== '' ? '&caption=' + encodeURIComponent(options.caption) : '') +
            (options.initial != null ? '&initial=' + encodeURIComponent(String(options.initial)) : '') +
            // ChosungGrid_entry.php/NumberKeypad_entry.php와 동일한 이유 —
            // 오래 떠 있는 부모 문서에서 반복해서 열어도 항상 새 요청이
            // 되도록 캐시 버스팅.
            '&_=' + Date.now();
        const iframeUrl = entryUrl + (entryUrl.indexOf('?') === -1 ? '?' : '&') + query;

        return new Popup({
            id: options.id,
            title: typeof options.ptitle === 'string' ? options.ptitle : '메모 입력',
            modal: options.modal,
            draggable: options.draggable,
            iframeUrl: iframeUrl,
            // 큰 텍스트영역 + 버튼행이 넉넉히 들어갈 어림값.
            iframeWidth: options.iframeWidth || 420,
            iframeHeight: options.iframeHeight || 360,
            onClose: options.onClose,
        });
    }

    /** Memo.js와 같은 폴더의 Memo_entry.php를 기본 대상으로 삼는다. */
    static _defaultEntryUrl() {
        return Memo._SCRIPT_DIR + 'Memo_entry.php';
    }
}

/**
 * 이 스크립트 자신이 로드된 폴더의 절대경로 — `_defaultEntryUrl()`이
 * 같은 폴더의 `Memo_entry.php`를 항상 정확히 가리키게 한다
 * (`ChosungGrid.js`/`NumberKeypad.js`의 `_SCRIPT_DIR`과 완전히 동일한
 * 계산 방식 — `document.currentScript.src`에서 역산).
 */
Memo._SCRIPT_DIR = (function () {
    const src = document.currentScript && document.currentScript.src;
    if (!src) {
        return './';
    }
    return src.replace(/Memo\.js(\?.*)?$/, '');
})();
