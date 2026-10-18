/**
 * ChosungKeypad — 초성/숫자/영어 가상 키패드.
 *
 * 바탕화면 `초성검색.png`(Access "초성검색" 화면 좌측 하단)의 키패드를
 * 재현한 독립 JS 모듈 — AGGrid.js/ScrollGrid.js와 마찬가지로 순수
 * DOM/이벤트만 다루고, 서버 통신이나 그리드 재조회는 전혀 모른다.
 *
 * [핵심 동작 — 2026-08-26 최종 확정] 버튼을 연속으로 누르면 "ㄱㄷ"처럼
 * 문자열이 계속 이어붙는다(레거시 Access 원본 `fFirstSoundSearch_select`
 * 의 `getWhereFirstSoundSearch`/`getNextChar`가 검색어를 한 글자씩
 * 순회하며 "N번째 글자가 이 문자로 시작하는가"를 AND로 엮던 것과 동일한
 * 모델 — 처음엔 "매번 마지막 키로 교체"로 잘못 설계했다가 원본 VBA
 * 코드를 확인한 뒤 누적 방식으로 바로잡음). "Clear"를 누르면 그 문자열을
 * 통째로 비운다. 실제 검색/재조회는 이 모듈의 책임이 아니다 — `onInput`
 * 콜백에 "지금까지 누적된 전체 문자열"을 그대로 넘기고, 그걸로 무엇을
 * 할지(AGGrid 재조회 등)는 호출부가 정한다("그리드 쪽 책임").
 *
 * 카운터("0/287(0%)" 같은 검색 결과 건수 표시)도 이 모듈이 다루지
 * 않는다 — 검색을 실제로 수행하는 화면(그리드) 쪽 몫이다.
 *
 * "Quit" 버튼은 기본 숨김(`showQuitButton: false`) — 화면에 고정
 * 임베드된 경우 의미가 없고, 팝업으로 띄우는 화면에서만
 * `showQuitButton: true`로 켠다.
 *
 * 사용 예:
 *   new ChosungKeypad({
 *       containerId: 'ck-keypad',
 *       onInput: (value) => search(value),  // value = 지금까지 누적된 전체 문자열(예: "ㄱㄷ")
 *       onClear: () => search(''),
 *   });
 */
class ChosungKeypad {
    /**
     * @param {{containerId: string, initialTab?: 'korean'|'english',
     *   showQuitButton?: boolean, onInput?: (value: string) => void,
     *   onClear?: () => void, onQuit?: () => void}} options
     */
    constructor(options) {
        if (!options || !options.containerId) {
            throw new Error('ChosungKeypad: containerId는 필수입니다.');
        }
        this.containerId = options.containerId;
        this.tab = options.initialTab === 'english' ? 'english' : 'korean';
        this.showQuitButton = options.showQuitButton === true;
        this.onInput = typeof options.onInput === 'function' ? options.onInput : null;
        this.onClear = typeof options.onClear === 'function' ? options.onClear : null;
        this.onQuit = typeof options.onQuit === 'function' ? options.onQuit : null;
        // 지금까지 눌린 키를 이어붙인 문자열(빈 문자열 = 아무것도 안
        // 눌렸거나 Clear된 상태).
        this.value = '';

        ChosungKeypad._injectDefaultStyles();
        this._render();
    }

    /** 지금까지 누적된 검색 문자열을 반환한다. */
    getValue() {
        return this.value;
    }

    /** 외부에서 표시값을 강제로 맞춘다(호출부가 다른 경로로 상태를 바꾼 경우) —
     * onInput/onClear 콜백은 부르지 않는다(순수 표시 동기화). */
    setValue(value) {
        this.value = value == null ? '' : String(value);
        this._updateDisplay();
    }

    /** "Clear" 버튼과 동일한 동작을 코드로도 실행한다. */
    clear() {
        this.value = '';
        this._updateDisplay();
        if (this.onClear) {
            this.onClear();
        }
    }

    /** 한글/숫자 ↔ 영어 탭을 코드로도 전환한다. */
    setMode(tab) {
        this.tab = tab === 'english' ? 'english' : 'korean';
        this._render();
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
            throw new Error('ChosungKeypad: containerId 요소를 찾을 수 없습니다: ' + this.containerId);
        }
        container.classList.add('ck-keypad');
        container.innerHTML = this._buildHtml();
        this._bindEvents(container);
    }

    _buildHtml() {
        // 바탕화면 초성검색.png의 실제 배열 그대로(표준 자음 순서가 아니라
        // 이미지에 보이는 순서 — ㅋ/ㅎ이 뒤로 밀려있음).
        const koreanRows = [
            ['ㄱ', 'ㄴ', 'ㄷ', 'ㄹ'],
            ['ㅁ', 'ㅂ', 'ㅅ', 'ㅇ'],
            ['ㅈ', 'ㅊ', 'ㅌ', 'ㅍ'],
            ['ㅋ', 'ㅎ', '1', '2'],
            ['3', '4', '5', '6'],
            ['7', '8', '9', '0'],
        ];
        const englishRows = [
            ['A', 'B', 'C', 'D'],
            ['E', 'F', 'G', 'H'],
            ['I', 'J', 'K', 'L'],
            ['M', 'N', 'O', 'P'],
            ['Q', 'R', 'S', 'T'],
            ['U', 'V', 'W', 'X'],
            ['Y', 'Z', '', ''],
        ];
        const rows = this.tab === 'english' ? englishRows : koreanRows;

        let html = '';
        html += '<div class="ck-display">' + this._escape(this.value) + '</div>';
        html += '<div class="ck-tabs">';
        html += '<button type="button" class="ck-tab-btn' + (this.tab === 'korean' ? ' ck-tab-active' : '') + '" data-tab="korean">한글/숫자</button>';
        html += '<button type="button" class="ck-tab-btn' + (this.tab === 'english' ? ' ck-tab-active' : '') + '" data-tab="english">영어</button>';
        html += '</div>';
        html += '<div class="ck-keys">';
        rows.forEach((row) => {
            row.forEach((key) => {
                if (key === '') {
                    html += '<span class="ck-key-empty"></span>';
                } else {
                    // 값이 누적 문자열이라(예: "ㄱㄷ") 특정 버튼 하나를
                    // "선택됨"으로 강조할 기준이 없다 — 원본 VBA에도 그런
                    // 개념은 없었다. 눌림 피드백은 위 ck-display(누적
                    // 문자열 표시)만으로 충분하다고 보고 버튼 강조는 생략.
                    html += '<button type="button" class="ck-key-btn" data-key="' + this._escape(key) + '">' + this._escape(key) + '</button>';
                }
            });
        });
        html += '</div>';
        html += '<div class="ck-actions">';
        html += '<button type="button" class="ck-clear-btn">Clear</button>';
        if (this.showQuitButton) {
            // [2026-09-16 수정] "quit 말고 Quit (창닫기)라고 바꾸고" 지시.
            html += '<button type="button" class="ck-quit-btn">Quit (창닫기)</button>';
        }
        html += '</div>';
        return html;
    }

    _bindEvents(container) {
        container.querySelectorAll('.ck-tab-btn').forEach((btn) => {
            btn.addEventListener('click', () => this.setMode(btn.getAttribute('data-tab')));
        });
        container.querySelectorAll('.ck-key-btn').forEach((btn) => {
            btn.addEventListener('click', () => this._onKeyClick(btn.getAttribute('data-key')));
        });
        container.querySelector('.ck-clear-btn').addEventListener('click', () => this.clear());
        const quitBtn = container.querySelector('.ck-quit-btn');
        if (quitBtn) {
            quitBtn.addEventListener('click', () => {
                if (this.onQuit) {
                    this.onQuit();
                }
            });
        }
    }

    _onKeyClick(key) {
        // 교체가 아니라 누적 — "ㄱ" 다음 "ㄷ"을 누르면 "ㄱㄷ"이 된다.
        this.value += key;
        this._updateDisplay();
        if (this.onInput) {
            this.onInput(this.value);
        }
    }

    _updateDisplay() {
        const container = document.getElementById(this.containerId);
        if (!container) {
            return;
        }
        const display = container.querySelector('.ck-display');
        if (display) {
            display.textContent = this.value;
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
        if (document.getElementById('chosung-keypad-default-styles')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'chosung-keypad-default-styles';
        style.textContent = `
@layer chosung-keypad-defaults {
    .ck-keypad {
        display: inline-block; font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
    }
    /* [2026-09-09 수정] "초성 자음을 누르면 상단에 뜨는 글자를
       중앙정렬" 지시 — text-align:center 추가. */
    .ck-display {
        font-size: 1.4rem; font-weight: 700; min-height: 1.6em;
        margin-bottom: 6px; color: #111; text-align: center;
    }
    .ck-tabs { display: flex; gap: 4px; margin-bottom: 6px; }
    .ck-tab-btn {
        padding: 4px 10px; border: 1px solid #d1d5db; border-radius: 4px;
        background: #f9fafb; color: #374151; font-size: 0.82rem; cursor: pointer;
    }
    .ck-tab-btn.ck-tab-active { background: #bfdbfe; color: #1e293b; border-color: #93c5fd; font-weight: 600; }
    .ck-keys {
        display: grid; grid-template-columns: repeat(4, 44px); gap: 4px; margin-bottom: 8px;
    }
    .ck-key-btn {
        width: 44px; height: 40px; border: 1px solid #d1d5db; border-radius: 4px;
        background: #fff; font-size: 1rem; font-weight: 600; color: #111; cursor: pointer;
        transition: background-color 0.15s ease;
    }
    .ck-key-btn:hover { background: #f3f4f6; }
    .ck-key-empty { display: inline-block; }
    .ck-actions { display: flex; gap: 8px; }
    .ck-clear-btn {
        padding: 6px 16px; border: none; border-radius: 4px;
        background: #e0736c; color: #fff; font-weight: 600; font-size: 0.85rem; cursor: pointer;
    }
    .ck-clear-btn:hover { background: #c85950; }
    /* [2026-09-16 수정] "버튼너비를 키패드 끝까지" 지시 — flex:1로
       .ck-clear-btn(고정폭 그대로) 옆 남는 폭을 전부 흡수해, 키패드
       전체 폭(.ck-keys 기준)의 오른쪽 끝까지 닿게 한다. */
    /* [2026-09-16 수정 → 재수정] "빨간색 계열로 3d 스럽게 변경" 지시로
       회색 평면 버튼을 deco_amd_tempitems.php의 .dt-row-btn-delete와
       같은 "그라디언트 + 옅은 그림자" 3D 스타일(빨간 계열)로 바꿨다가,
       "녹색계열로 바꾸지" 지시로 같은 3D 스타일을 유지한 채 색만
       초록 계열로 교체. */
    .ck-quit-btn {
        flex: 1;
        padding: 6px 16px; border: none; border-radius: 4px;
        background: linear-gradient(180deg, #66bb6a, #2e7d32);
        color: #fff; font-weight: 600; font-size: 0.85rem; cursor: pointer;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.25);
    }
    .ck-quit-btn:hover { background: linear-gradient(180deg, #4caf50, #1b5e20); }
}
        `;
        document.head.appendChild(style);
    }
}
