/**
 * NumberKeypad — 숫자 입력 가상 키패드.
 *
 * 바탕화면 `매출/숫자키패드.png`를 재현할 목적의 독립 JS 모듈 —
 * `ChosungKeypad.js`와 마찬가지로 순수 DOM/이벤트만 다루고, 서버 통신이나
 * 입력한 값의 쓰임(가격/수량/전화번호 등)은 전혀 모른다.
 * [2026-09-11 추가 — 이 세션에 참고 이미지가 직접 전달되지 않아, 숫자
 * 키패드의 일반적인 배열(7·8·9/4·5·6/1·2·3/·(소수점)·0·←(지우기))과
 * ChosungKeypad의 기존 관례(디스플레이+Clear+Quit)를 따라 우선
 * 구현했다 — 실제 이미지와 다르면 이후 지시로 배열/버튼 구성을 조정한다.]
 *
 * `ChosungKeypad`와의 핵심 차이 — "입력 중"과 "확정"을 분리한다:
 * `ChosungKeypad`는 누를 때마다 즉시 `onInput`으로 통보한다(검색어처럼
 * "입력 중인 상태가 곧 결과"이기 때문). 이 모듈은 숫자 버튼을 누르면
 * 디스플레이에만 누적되고(`onInput`은 그 중간 상태를 알고 싶은 호출부를
 * 위한 선택적 통지일 뿐), 실제로 "이 값을 쓰겠다"는 확정은 "확인" 버튼을
 * 눌러야 한다(`confirm()`/`onConfirm`) — 금액/수량처럼 다 입력한 뒤에야
 * 의미가 있는 값이기 때문.
 *
 * [2026-09-11 추가] `Calendar.js`/`ChosungGrid.js`와 동일한 "여는 쪽이
 * callback 이름을 지어서 주입한다" 관례(md/2026-09-11-action-callback-
 * 이름부여-규칙.md 참고) — `sender`/`callback` 옵션을 주면 "확인" 시
 * `sender.action(callback, {value}, window)`로 보고한다(기본 callback
 * 이름은 `'callback_숫자키패드'`). "Quit"을 누르면
 * `{value: null, cancelled: true}`로 취소를 보고한다(ChosungGrid.open()의
 * 취소 보고 방식과 동일). 팝업을 닫는 것은 항상 여는 쪽 책임 — 이 위젯은
 * 자기가 Popup 안에 있는지도 모른다.
 *
 * 사용 예(화면에 직접 얹기 — sender/callback 없이 로컬 콜백만):
 *   new NumberKeypad({
 *       containerId: 'nk-keypad',
 *       onConfirm: (value) => console.log('입력값:', value),
 *   });
 *
 * 사용 예(팝업으로 값 하나만 받기 — NumberKeypad.open() 참고):
 *   NumberKeypad.open({ ptitle: '금액 입력', callback: 'callback_금액입력', sender: window });
 */
class NumberKeypad {
    /**
     * @param {{containerId: string, caption?: string, initial?: (string|number),
     *   maxLength?: number, allowDecimal?: boolean, showQuitButton?: boolean,
     *   displayMode?: ('number'|'plain'|'password'),
     *   sender?: Window, callback?: string, onInput?: (value: string) => void,
     *   onConfirm?: (value: string) => void, onClear?: () => void,
     *   onQuit?: () => void}} options
     */
    constructor(options) {
        options = options || {};
        if (!options.containerId) {
            throw new Error('NumberKeypad: containerId는 필수입니다.');
        }
        this.containerId = options.containerId;
        // [2026-09-16 추가] "입력 매개변수에 따라 정렬/콤마/비밀번호
        // 표시를 조절 가능하게" 지시 — 디스플레이의 정렬/포맷을 3가지
        // 모드 중 하나로 고른다. 정보가 없을 때(옵션 생략, 또는 이
        // 셋 밖의 값) 기본값은 지시대로 `'number'`(우측정렬+3자리
        // 콤마, 지금까지의 유일했던 동작)로 떨어진다:
        //   - `'number'`(기본) — 우측정렬, 3자리마다 콤마.
        //   - `'plain'`         — 가운데정렬, 콤마 없음(입력한 숫자
        //     그대로만, 예: 전화번호/코드처럼 자릿수 묶음이 의미 없는 값).
        //   - `'password'`      — 가운데정렬, 입력한 문자 수만큼 마스킹
        //     문자(●)로만 표시(실제 값은 여전히 `this.value`/`getValue()`
        //     /`confirm()`에 평문으로 남음 — 마스킹은 순수 표시 전용).
        // 정렬/포맷 둘 다 이 하나의 옵션에서 함께 파생된다(`_buildHtml()`/
        // `_formatForDisplay()` 참고) — 사용자가 요청한 세 조합("우측+콤마",
        // "가운데+콤마없음", "가운데+비밀번호") 자체가 이미 정렬과 포맷이
        // 1:1로 묶여 있어, 정렬/포맷을 굳이 별도 옵션 두 개로 쪼갤 이유가
        // 없었다.
        this.displayMode = 'number';
        if (NumberKeypad._DISPLAY_MODES.indexOf(options.displayMode) !== -1) {
            this.displayMode = options.displayMode;
        }
        // [2026-09-11 추가] "팝업 띄울 때 캡션도 매개변수로" 지시 —
        // 디스플레이 위에 "무엇을 입력하는 중인지" 안내 문구를 보여줄
        // 자리. 화면에 직접 얹을 때도, `NumberKeypad.open()`으로 팝업을
        // 열 때도(→ `NumberKeypad_entry.php`가 `?caption=`을 그대로
        // 이 옵션에 넘김) 똑같이 쓸 수 있게 위젯 자신에 둔다(엔트리
        // 페이지 쪽에 별도 캡션 마크업을 만들지 않음 — 한곳에서만 관리).
        this.caption = typeof options.caption === 'string' ? options.caption : '';
        // 지나치게 긴 숫자 나열을 막는 방어적 상한 — 화면에 얹는 값이
        // 대개 금액/수량이라 12자리면 충분하고도 남는다.
        this.maxLength = typeof options.maxLength === 'number' ? options.maxLength : 12;
        this.allowDecimal = options.allowDecimal !== false;
        this.showQuitButton = options.showQuitButton === true;
        this.sender = options.sender || null;
        // [2026-09-11 추가] callback을 여는 쪽이 지어서 넘기는 관례 —
        // 생략하면 기존 이름(ChosungKeypad류와 동일한 "callback_모듈명" 형태)으로 폴백.
        this.callback = typeof options.callback === 'string' && options.callback !== ''
            ? options.callback
            : 'callback_숫자키패드';
        this.onInput = typeof options.onInput === 'function' ? options.onInput : null;
        this.onConfirm = typeof options.onConfirm === 'function' ? options.onConfirm : null;
        this.onClear = typeof options.onClear === 'function' ? options.onClear : null;
        this.onQuit = typeof options.onQuit === 'function' ? options.onQuit : null;
        // [2026-09-11 추가] "숫자는 항상 숫자형이 되게, 05 이런 것을
        // 허용 안 하게" — 초기값도 선행 0을 정리해서 받는다(`setValue()`
        // 와 동일한 이유 — `_normalizeNumericString()` 참고).
        this.value = options.initial != null ? this._normalizeNumericString(String(options.initial)) : '';

        NumberKeypad._injectDefaultStyles();
        this._render();
    }

    /** 지금까지 누적된(아직 확정 전인) 문자열을 반환한다. */
    getValue() {
        return this.value;
    }

    /**
     * 외부에서 표시값을 강제로 맞춘다(콜백은 안 부름 — 순수 표시 동기화).
     * [2026-09-11 추가] "숫자는 항상 숫자형이 되게, 05 이런 것을 허용
     * 안 하게" 지시 — 타이핑뿐 아니라 프로그램적으로 값을 넣을 때도
     * (여기, 그리고 생성자의 `initial`) 선행 0을 정리한다.
     */
    setValue(value) {
        this.value = value == null ? '' : this._normalizeNumericString(String(value));
        this._updateDisplay();
    }

    /**
     * [2026-09-11 추가] 캡션(디스플레이 위 안내 문구)을 나중에 바꾼다 —
     * 캡션 유무에 따라 마크업 자체가 있다/없다로 갈리므로(생성자의
     * `if (this.caption)` 분기), 부분 갱신이 아니라 `_render()`로
     * 통째로 다시 그린다(버튼뿐인 위젯이라 포커스 등 잃을 상태가 없음).
     */
    setCaption(caption) {
        this.caption = typeof caption === 'string' ? caption : '';
        this._render();
    }

    /**
     * [2026-09-11 수정] "하단 액션행의 Clear 버튼을 없앤다" 지시로 그
     * 버튼 자체는 사라졌다 — 지금은 `allowDecimal:false`일 때 그리드
     * 안 `C` 키(0 왼쪽)만이 이 메서드를 호출하는 유일한 UI 진입점이다
     * (`allowDecimal:true`면 그 자리를 소수점(`.`)이 차지해 그리드에도
     * 지우기 버튼이 없다 — 이 상태에선 `clear()`를 코드로 직접 부르는
     * 것만 가능). 동작 자체(값 비움+`onClear` 호출)는 그대로다.
     */
    clear() {
        this.value = '';
        this._updateDisplay();
        if (this.onClear) {
            this.onClear();
        }
    }

    /**
     * "확인" 버튼과 동일한 동작을 코드로도 실행한다 — `onConfirm` 로컬
     * 콜백과, `sender`가 있으면 `sender.action(callback, {value}, window)`
     * 보고를 함께 수행한다.
     */
    confirm() {
        if (this.onConfirm) {
            this.onConfirm(this.value);
        }
        if (this.sender && typeof this.sender.action === 'function') {
            this.sender.action(this.callback, { value: this.value }, window);
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
            throw new Error('NumberKeypad: containerId 요소를 찾을 수 없습니다: ' + this.containerId);
        }
        container.classList.add('nk-keypad');
        container.innerHTML = this._buildHtml();
        this._bindEvents(container);
    }

    _buildHtml() {
        // 일반적인 숫자 키패드 배열 — 맨 아래 줄만 소수점/0/지우기.
        // [2026-09-11 추가] "0 왼쪽이 비어있군, 그곳에 C(클리어) 버튼
        // 추가" 지시 — allowDecimal이 false면 예전엔 그 자리가 빈 칸
        // (`nk-key-empty`)이었는데, 이제 `'C'`(전용 클리어 버튼)를 둔다.
        // allowDecimal이 true면 그 자리를 소수점(`.`)이 이미 쓰고 있어
        // 이번 변경의 영향이 없다(빈 칸 자체가 없던 경우).
        const rows = [
            ['7', '8', '9'],
            ['4', '5', '6'],
            ['1', '2', '3'],
            [this.allowDecimal ? '.' : 'C', '0', '←'],
        ];

        let html = '';
        if (this.caption) {
            html += '<div class="nk-caption">' + this._escape(this.caption) + '</div>';
        }
        // [2026-09-16 추가] displayMode에 따른 정렬 — 'number'만 우측정렬,
        // 나머지('plain'/'password')는 가운데정렬(위 생성자 주석 참고).
        let displayAlignClass = 'nk-display-center';
        if (this.displayMode === 'number') {
            displayAlignClass = 'nk-display-right';
        }
        html += '<div class="nk-display ' + displayAlignClass + '">' + this._escape(this._formatForDisplay(this.value)) + '</div>';
        html += '<div class="nk-keys">';
        rows.forEach((row) => {
            row.forEach((key) => {
                if (key === '') {
                    html += '<span class="nk-key-empty"></span>';
                } else if (key === 'C') {
                    // [2026-09-11 추가, 같은 날 재수정] 그리드 안
                    // 클리어 버튼 — `clear()`를 그대로 호출한다.
                    // 처음엔 `.nk-actions`(하단 액션행)에도 "Clear"
                    // 버튼이 따로 있었으나, "하단 액션행의 Clear를
                    // 없애고 Quit/확인만 남긴다" 지시로 그 버튼이
                    // 사라져, `allowDecimal:false`일 땐 이 그리드 안
                    // `C`가 지우기의 유일한 UI 진입점이 됐다. 다른
                    // 숫자 키와 구분되도록 `.nk-key-clear`로 스타일만
                    // 다르게 준다.
                    html += '<button type="button" class="nk-key-btn nk-key-clear" data-key="C">C</button>';
                } else {
                    html += '<button type="button" class="nk-key-btn" data-key="' + this._escape(key) + '" data-mode="append">' + this._escape(key) + '</button>';
                }
            });
        });
        html += '</div>';
        html += this._buildQuickKeysHtml();
        html += '<div class="nk-actions">';
        if (this.showQuitButton) {
            html += '<button type="button" class="nk-quit-btn">Quit</button>';
        }
        html += '<button type="button" class="nk-confirm-btn">확인</button>';
        html += '</div>';
        return html;
    }

    /**
     * [2026-09-11 추가] "0/00/000/0.1/0.5/1.5 버튼 추가" 지시 — POS
     * 화면에서 자주 쓰는 단축 버튼 두 줄. 큰 금액을 빨리 입력할 때
     * 쓰는 "0/00/000"(누른 개수만큼 0을 이어붙이는 계산기 관용구,
     * `mode: 'append'` — 일반 숫자 키와 완전히 같은 동작)과, 자주 쓰는
     * 소수량을 바로 선택하는 "0.1/0.5/1.5"(`mode: 'set'` — 이어붙이지
     * 않고 값 전체를 그 숫자로 교체. "12"에 "0.5"를 이어붙이면
     * "120.5"처럼 의미 없는 값이 될 수 있어, 프리셋은 항상 전체 교체로
     * 설계했다)로 나눴다. 소수량 프리셋은 `allowDecimal===false`면
     * 애초에 소수 입력 자체를 막은 화면이라 함께 감춘다.
     */
    _buildQuickKeysHtml() {
        const quickRows = [{ keys: ['0', '00', '000'], mode: 'append' }];
        if (this.allowDecimal) {
            quickRows.push({ keys: ['0.1', '0.5', '1.5'], mode: 'set' });
        }

        let html = '<div class="nk-quick-keys">';
        quickRows.forEach((rowDef) => {
            rowDef.keys.forEach((key) => {
                html += '<button type="button" class="nk-quick-btn" data-key="' + this._escape(key) +
                    '" data-mode="' + rowDef.mode + '">' + this._escape(key) + '</button>';
            });
        });
        html += '</div>';
        return html;
    }

    _bindEvents(container) {
        container.querySelectorAll('.nk-key-btn, .nk-quick-btn').forEach((btn) => {
            btn.addEventListener('click', () => this._onKeyClick(btn.getAttribute('data-key'), btn.getAttribute('data-mode')));
        });
        container.querySelector('.nk-confirm-btn').addEventListener('click', () => this.confirm());
        const quitBtn = container.querySelector('.nk-quit-btn');
        if (quitBtn) {
            quitBtn.addEventListener('click', () => {
                if (this.onQuit) {
                    this.onQuit();
                }
                if (this.sender && typeof this.sender.action === 'function') {
                    this.sender.action(this.callback, { value: null, cancelled: true }, window);
                }
            });
        }
    }

    _onKeyClick(key, mode) {
        if (key === 'C') {
            // [2026-09-11 추가] 그리드 안 클리어 버튼 — `clear()`(값
            // 비움 + `onClear` 호출 + 표시 갱신)만 하고 끝낸다. 아래 일반 흐름(값을
            // 이어붙이고 `onInput`을 부르는 것)과는 다른 종류의 동작
            // (지우기)이라 그 흐름을 타지 않고 바로 반환한다.
            this.clear();
            return;
        }
        if (key === '←') {
            // 지우기 — 마지막 한 글자만 뗀다.
            this.value = this.value.slice(0, -1);
        } else if (key === '.') {
            // 소수점은 값 안에 이미 하나 있으면 무시(중복 방지).
            if (this.value.indexOf('.') !== -1) {
                return;
            }
            // 맨 처음 누르는 키가 소수점이면 "0."으로 시작한다(자연스러운
            // 소수 입력 관례 — Access 원본에 없던 이번 구현의 보강).
            this.value = (this.value === '' ? '0' : this.value) + '.';
        } else if (mode === 'set') {
            // [2026-09-11 추가] 프리셋(0.1/0.5/1.5) — 지금까지 입력
            // 중이던 값은 버리고 이 값으로 바로 교체한다.
            this.value = key;
        } else if (/^0+$/.test(key) && (this.value === '' || this.value === '0')) {
            // [2026-09-11 수정 — "화면은 항상 숫자가 나와야 한다"] 값이
            // 비어있거나("") 이미 "0"뿐일 때 0만으로 이뤄진 키(메인
            // 키패드의 "0", 단축 버튼의 "0"/"00"/"000" 전부 포함)를
            // 누르면 아무 반응도 안 한다 — "1" 앞에 의미 없는 "0"이
            // 더 붙거나("00") "000" 같은 값이 그대로 표시되는 것을
            // 막는다. 디스플레이는 어차피 `_formatForDisplay()`가 항상
            // 최소 "0"을 보여주므로, 이 상태에서 0계열 키를 눌러도
            // "화면에 숫자가 안 보이는" 문제는 생기지 않는다 — 그냥
            // 무시할 뿐. "1" 다음에 누르면(this.value==='1') 이 분기에
            // 안 걸려 정상적으로 "10"/"100"/"1000"이 된다.
            return;
        } else if (this.value === '0' && /^[1-9]$/.test(key)) {
            // [2026-09-11 추가] "숫자는 항상 숫자형이 되게, 05 이런
            // 것을 허용 안 하게" — 지금 값이 정확히 "0"뿐인 상태에서
            // 1~9 숫자를 누르면 이어붙이지 않고("0"+"5"="05"가 되는
            // 걸 막음) 그 숫자로 통째로 교체한다. 이러면 "05"/"012"
            // 같은 선행 0이 남은 값이 만들어질 수 없다. "0." 상태
            // (소수점을 이미 입력한 뒤)는 값이 "0"이 아니라 "0."이라
            // 이 분기에 안 걸려 "0.5"처럼 정상적으로 이어붙는다.
            this.value = key;
        } else {
            // 일반 숫자 및 "00"/"000"(0을 여러 개 한 번에 이어붙이는
            // 단축 버튼, 값 전체가 아니라 자릿수만큼 이어붙인다는 점에서
            // 위 'set'과 다름) — 이어붙인 뒤 길이가 상한을 넘기면 무시.
            if (this.value.length + key.length > this.maxLength) {
                return;
            }
            this.value += key;
        }
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
        const display = container.querySelector('.nk-display');
        if (display) {
            display.textContent = this._formatForDisplay(this.value);
        }
    }

    /**
     * [2026-09-11 추가 → 2026-09-16 displayMode별로 분기] "3자리마다 ,
     * 표시"는 이제 `displayMode==='number'`(기본값)일 때만 적용된다 —
     * `deco_main.php`의 `dmFormatNumber()`(setStats() 전용으로 이미 쓰던
     * 천단위 콤마 로직)와 완전히 같은 정규식을 재사용한다. 어디까지나
     * **표시만** 이렇게 꾸미고, `this.value`(실제 값 — `getValue()`/
     * `confirm()`이 넘기는 값)는 콤마 없는 순수 숫자 문자열 그대로
     * 유지한다(값과 표시를 분리 — 값에 콤마가 섞이면 다시 파싱해야
     * 하는 번거로움과 소수점 위치 오판 위험이 생김). 소수점을 막 입력해
     * 값이 "12."처럼 끝나 있어도(정수부만 콤마 처리하고 나머지는 그대로
     * 이어붙이므로) 그대로 자연스럽게 표시된다.
     * `displayMode==='plain'`은 콤마 없이 입력한 그대로만 보여준다.
     * `displayMode==='password'`는 별도 헬퍼(`_formatForDisplayPassword()`)
     * 로 완전히 다른 규칙(마스킹, 빈 값은 "0"이 아니라 빈 문자열)을 쓴다
     * — 그 헬퍼 주석 참고.
     */
    _formatForDisplay(value) {
        if (this.displayMode === 'password') {
            return this._formatForDisplayPassword(value);
        }
        if (value === '' || value == null) {
            return '0';
        }
        const parts = String(value).split('.');
        if (this.displayMode === 'number') {
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
        return parts.join('.');
    }

    /**
     * [2026-09-16 추가] `displayMode==='password'` 전용 — 입력된 문자
     * 하나당 마스킹 문자(●) 하나로 바꿔서 보여준다(실제 자릿수는
     * 그대로 드러나지만 값 자체는 안 보임 — PIN/비밀번호류 입력에서
     * 흔한 절충). 값이 비어있으면 다른 모드처럼 "0"을 보여주지 않고
     * 빈 문자열을 반환한다 — 비밀번호 칸에서 "0"은 "이미 한 글자
     * 입력됨"으로 오해될 수 있는 값이라 다른 모드와 같은 플레이스홀더를
     * 쓰지 않는다.
     */
    _formatForDisplayPassword(value) {
        if (value === '' || value == null) {
            return '';
        }
        return '●'.repeat(String(value).length);
    }

    /**
     * [2026-09-11 추가] "숫자는 항상 숫자형이 되게, 05 이런 것을 허용
     * 안 하게" 지시 — 정수부의 의미 없는 선행 0을 제거한다("05"→"5",
     * "007"→"7", "0"→"0" 그대로, "0.5"→"0.5" 그대로 — 소수점 앞의
     * 단일 "0"은 정상적인 표기라 안 건드림). 빈 문자열/숫자가 아닌
     * 값은 건드리지 않고 그대로 돌려준다(초기값이 우연히 이상한
     * 문자열이어도 이 함수가 예외를 던지지 않게 방어).
     */
    _normalizeNumericString(value) {
        if (value === '' || value == null) {
            return '';
        }
        const str = String(value);
        if (!/^\d*\.?\d*$/.test(str)) {
            return str;
        }
        const parts = str.split('.');
        parts[0] = parts[0].replace(/^0+(?=\d)/, '');
        if (parts[0] === '') {
            parts[0] = '0';
        }
        return parts.join('.');
    }

    _escape(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    static _injectDefaultStyles() {
        if (document.getElementById('number-keypad-default-styles')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'number-keypad-default-styles';
        style.textContent = `
@layer number-keypad-defaults {
    .nk-keypad {
        display: inline-block; font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
    }
    .nk-caption {
        font-size: 0.85rem; font-weight: 600; color: #374151; margin-bottom: 6px;
    }
    /* [2026-09-11 수정] "검은바탕에 노란색 볼드로" 지시 — deco_main.php의
       get매출style()/setStats() 배지(검은 배경+노란 글자, #ffeb3b)와
       같은 배색으로 통일. [2026-09-16 수정] "displayMode에 따라 정렬을
       바꿀 수 있게" 지시로 text-align을 여기서 빼고 아래 두 모디파이어
       클래스(.nk-display-right/.nk-display-center)로 옮김 — _buildHtml()
       이 displayMode에 맞는 쪽을 함께 붙인다. */
    .nk-display {
        font-size: 1.6rem; font-weight: 700; min-height: 1.4em;
        margin-bottom: 8px; color: #ffeb3b;
        background: #000; border: 1px solid #d1d5db; border-radius: 4px;
        padding: 6px 10px; box-sizing: border-box;
    }
    .nk-display-right { text-align: right; }
    .nk-display-center { text-align: center; }
    .nk-keys {
        display: grid; grid-template-columns: repeat(3, 64px); gap: 6px; margin-bottom: 8px;
    }
    .nk-key-btn {
        width: 64px; height: 52px; border: 1px solid #d1d5db; border-radius: 4px;
        background: #fff; font-size: 1.15rem; font-weight: 600; color: #111; cursor: pointer;
        transition: background-color 0.15s ease;
    }
    .nk-key-btn:hover { background: #f3f4f6; }
    .nk-key-empty { display: inline-block; }
    /* [2026-09-11 추가] "0 왼쪽의 빈 칸에 C(클리어) 버튼" — 아래
       .nk-clear-btn(액션행 Clear)과 같은 톤(빨간 계열)으로, 다른
       숫자 키(.nk-key-btn 기본 흰 배경)와 구분되게 한다. */
    .nk-key-clear {
        background: #e0736c; color: #fff; border-color: #c85950;
    }
    .nk-key-clear:hover { background: #c85950; }
    /* [2026-09-11 추가] 0/00/000/0.1/0.5/1.5 단축 버튼 — 위 nk-keys
       (일반 숫자 키패드)와 시각적으로 구분되게 옅은 파란색 계열로
       분리. 열 수(3)/버튼 폭(64px)은 위 nk-keys와 맞춰 정렬이
       흐트러지지 않게 한다. */
    .nk-quick-keys {
        display: grid; grid-template-columns: repeat(3, 64px); gap: 6px; margin-bottom: 8px;
    }
    .nk-quick-btn {
        width: 64px; height: 40px; border: 1px solid #c7d2fe; border-radius: 4px;
        background: #eef2ff; font-size: 0.95rem; font-weight: 600; color: #1e3a8a; cursor: pointer;
        transition: background-color 0.15s ease;
    }
    .nk-quick-btn:hover { background: #dbe3fb; }
    .nk-actions { display: flex; gap: 8px; }
    .nk-quit-btn {
        flex: 1; padding: 8px 0; border: 1px solid #d1d5db; border-radius: 4px;
        background: #f3f4f6; color: #9ca3af; font-weight: 600; font-size: 0.9rem; cursor: pointer;
    }
    .nk-quit-btn:hover { background: #e5e7eb; }
    .nk-confirm-btn {
        flex: 1; padding: 8px 0; border: none; border-radius: 4px;
        background: #1565c0; color: #fff; font-weight: 600; font-size: 0.9rem; cursor: pointer;
    }
    .nk-confirm-btn:hover { background: #0d47a1; }
}
        `;
        document.head.appendChild(style);
    }

    // ------------------------------------------------------------------
    // 정적 헬퍼 — Popup으로 열기(ChosungGrid.open()과 동일한 관례)
    // ------------------------------------------------------------------

    /**
     * `Popup.js`의 iframeUrl로 `NumberKeypad_entry.php`를 띄우는 정적
     * 헬퍼 — `ChosungGrid.open()`과 동일한 형태.
     *
     * @param {{caption?: string, initial?: (string|number), maxLength?: number,
     *   allowDecimal?: boolean, displayMode?: ('number'|'plain'|'password'),
     *   callback?: string, sender?: Window,
     *   ptitle?: string, id?: string, entryUrl?: string,
     *   iframeWidth?: (number|string), iframeHeight?: (number|string),
     *   modal?: boolean, draggable?: boolean, onClose?: (id: string) => void}} options
     *   `callback`(선택) — 확인/취소 시 `sender.action(...)`에 쓸 pcmd
     *   이름. 생략하면 `'callback_숫자키패드'`. `caption`(선택,
     *   2026-09-11 추가) — 위젯 디스플레이 위에 보여줄 안내 문구
     *   (`ptitle`은 Popup 창 자체의 제목표시줄일 뿐 이것과 별개).
     * @return {Popup} 호출부가 나중에 직접 닫을 수 있도록 그 Popup 인스턴스를 그대로 반환.
     */
    static open(options) {
        options = options || {};
        if (typeof Popup === 'undefined') {
            throw new Error('NumberKeypad.open(): Popup.js가 먼저 로드돼 있어야 합니다.');
        }
        const entryUrl = options.entryUrl || NumberKeypad._defaultEntryUrl();
        const callbackName = typeof options.callback === 'string' && options.callback !== ''
            ? options.callback
            : 'callback_숫자키패드';
        // [2026-09-16 추가] displayMode도 그대로 실어 보낸다 —
        // 'number'(기본값)면 생략해도 entry 쪽 기본값과 같아 안 보내도
        // 그만이지만, 명시적으로 넘겨도 결과가 똑같아 굳이 예외 처리하지
        // 않는다(entry.php가 화이트리스트로 검증).
        let displayModeQueryPart = '';
        if (typeof options.displayMode === 'string' && options.displayMode !== '') {
            displayModeQueryPart = '&displayMode=' + encodeURIComponent(options.displayMode);
        }
        const query = 'callback=' + encodeURIComponent(callbackName) +
            // [2026-09-11 추가] "팝업 띄울 때 캡션도 매개변수로" 지시 —
            // NumberKeypad_entry.php가 이 값을 그대로 받아 위젯의
            // caption 옵션에 넘긴다(디스플레이 위 안내 문구, ptitle과는
            // 별개 — ptitle은 Popup 창 제목표시줄, caption은 위젯 내부).
            (typeof options.caption === 'string' && options.caption !== '' ? '&caption=' + encodeURIComponent(options.caption) : '') +
            (options.initial != null ? '&initial=' + encodeURIComponent(String(options.initial)) : '') +
            (typeof options.maxLength === 'number' ? '&maxLength=' + encodeURIComponent(String(options.maxLength)) : '') +
            (options.allowDecimal === false ? '&allowDecimal=0' : '') +
            displayModeQueryPart +
            // ChosungGrid_entry.php와 동일한 이유 — 오래 떠 있는 부모
            // 문서에서 반복해서 열어도 항상 새 요청이 되도록 캐시 버스팅.
            '&_=' + Date.now();
        const iframeUrl = entryUrl + (entryUrl.indexOf('?') === -1 ? '?' : '&') + query;

        return new Popup({
            id: options.id,
            title: typeof options.ptitle === 'string' ? options.ptitle : '숫자 입력',
            modal: options.modal,
            draggable: options.draggable,
            iframeUrl: iframeUrl,
            // 키패드(3×64px+gap) + 디스플레이 + 버튼행 실측을 어림잡은
            // 값. [2026-09-11 수정] 0/00/000/0.1/0.5/1.5 단축 버튼 두
            // 줄(각 40px+gap6 ≈ 92px)이 늘어난 만큼 높이를 420→520으로 키움.
            iframeWidth: options.iframeWidth || 260,
            iframeHeight: options.iframeHeight || 520,
            onClose: options.onClose,
        });
    }

    /** NumberKeypad.js와 같은 폴더의 NumberKeypad_entry.php를 기본 대상으로 삼는다. */
    static _defaultEntryUrl() {
        return NumberKeypad._SCRIPT_DIR + 'NumberKeypad_entry.php';
    }
}

/** `displayMode` 옵션이 받아들이는 값 — 이 밖의 값(또는 생략)은 생성자가 `'number'`로 되돌린다. */
NumberKeypad._DISPLAY_MODES = ['number', 'plain', 'password'];

/**
 * 이 스크립트 자신이 로드된 폴더의 절대경로 — `_defaultEntryUrl()`이
 * 같은 폴더의 `NumberKeypad_entry.php`를 항상 정확히 가리키게 한다
 * (`ChosungGrid.js`의 `_SCRIPT_DIR`과 완전히 동일한 계산 방식 —
 * `document.currentScript.src`에서 역산, 호출부가 어느 경로에서 이
 * 파일을 불렀는지 몰라도 됨).
 */
NumberKeypad._SCRIPT_DIR = (function () {
    const src = document.currentScript && document.currentScript.src;
    if (!src) {
        return './';
    }
    return src.replace(/NumberKeypad\.js(\?.*)?$/, '');
})();
