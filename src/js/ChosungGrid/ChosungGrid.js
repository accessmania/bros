/**
 * ChosungGrid — 바탕화면 `초성검색.png` 전체(좌측 목록 + 우측
 * ChosungKeypad)를 하나로 묶은 "선택 팝업" 조립 위젯.
 *
 * ## 재사용 원칙
 * - 키패드는 `ChosungKeypad.js`를 그대로 재사용한다(새로 안 만듦) — 이
 *   클래스는 그 `onInput`/`onClear`가 넘기는 누적 문자열을 그대로
 *   `chosung` 쿼리스트링 파라미터로 실어 재조회할 뿐이다.
 * - 목록은 `AGGrid.js`(정렬/필터/편집/조건부서식까지 갖춘 CRUD용 무거운
 *   모듈)를 쓰지 않고, `deco_amd_amd.php`의 매출내용 목록과 동일하게
 *   ag-grid Community를 **가볍게**(표시 컬럼 하나, 헤더 없음, 편집/정렬/
 *   필터 없음, 클릭=선택) 직접 쓴다. `AGGrid.js`(`src/js/aggrid/
 *   AGGrid.js`)가 이미 vendor(`src/js/aggrid/vendor/`)에 로컬로 받아둔
 *   ag-grid-community를 그대로 재사용한다 — 두 번째 사본을 새로 받지
 *   않는다.
 *
 * ## 서버 계약 — "이름 하나로 서버가 매핑"
 * `fetchUrl`은 호출부가 이미 `?source=이름` 등 서버가 알아들을 값을 담아
 * 넘긴 URL이다(이 클래스는 `source`의 뜻을 모른다 — 실제 테이블/쿼리/
 * 컬럼 매핑은 그 서버 엔드포인트(`deco_amd_fetch.php`/
 * `ChosungGrid_fetch.php` 등) 책임). 이 클래스는 그 URL 뒤에
 * `chosung=<지금까지 누적된 문자열>`만 덧붙여 GET 요청하고, 응답은 항상
 * 아래 형태의 JSON **배열**이어야 한다 — 실제 DB 컬럼명이 무엇이든 이
 * 모양으로 맞추는 것도 서버 쪽 책임:
 *   [{ "key": ..., "display": "표시할 문자열", ...(선택 사항, 아래 참고) }, ...]
 *
 * ## 선택 시 — sender.action(callback이름, ...)
 * 행을 1클릭하면 `onSelect(key, display)`를 부르고(선택 사항, 로컬 콜백),
 * `sender`가 주어졌고 `sender.action`이 함수이면
 *   sender.action(this.callback, Object.assign({}, row, { key, display, type }), window)
 * 도 함께 부른다 — 이 프로젝트의 `action(pcmd, pparams, psender)` 관례
 * 그대로(`psender`는 이 그리드 자신의 window). `type`은 이 클래스가 뜻을
 * 모르는 순수 꼬리표 — 호출부가 `type`으로 switch해 "웨이터 선택인지
 * 영업 선택인지"를 구분하는 데 쓴다.
 *
 * [2026-09-13 추가] "code, 출석code" — 서버가 `row`에 `key`/`display`
 * 말고 다른 필드(예: `출석code`)를 더 실어 보내면, 그 필드들도
 * `Object.assign()`으로 그대로 함께 전달된다(`key`/`display`/`type`은
 * 항상 이 클래스가 보장하는 이름으로 덮어씀 — 서버가 실수로 그 이름과
 * 겹치는 필드를 보내도 안전). 이 전에는 `{key, display, type}` 셋만
 * 명시적으로 재구성해 다른 필드는 전부 버렸다 — 하위호환: 이 필드들을
 * 모르는 기존 호출부는 그냥 무시하면 된다.
 *
 * [2026-09-11 추가] **콜백 이름(`this.callback`)은 기본값이
 * `callback_초성검색`이지만 `options.callback`으로 호출부가 직접
 * 지정할 수 있다**(`openDual()`과 같은 이유 — 이 위젯은 여러 화면이
 * 재사용하므로, "선택이 끝나면 무엇을 해야 하는지"까지 담긴 구체적인
 * pcmd는 위젯 자신이 미리 정해둘 수 없고, 그 이름이 실제로 쓰일
 * 화면(여는 쪽)이 열 때 지어서 넘긴다 — 위젯은 그 이름을 그대로
 * 돌려줄 뿐 뜻을 모른다).
 *
 * **팝업을 닫는 것은 이 클래스의 책임이 아니다** — 이 클래스는 자신이
 * `Popup`(`src/js/Popup/Popup.js`) 안의 iframe으로 떠 있는지조차 모른다.
 * `sender` 쪽(팝업을 직접 만든 쪽만 그 Popup id를 안다)이 그 콜백을
 * 처리한 뒤 스스로 닫아야 한다.
 *
 * Quit(showQuitButton) — ChosungKeypad의 Quit 버튼을 누르면 취소로 보고
 * `sender.action(this.callback, { key: null, display: null, type,
 * cancelled: true }, window)`를 부른다(선택 없이 닫힌 경우도 sender가
 * 항상 같은 창구로 알 수 있게 — 별도 pcmd를 안 늘림). `onQuit`(선택
 * 사항)이 주어지면 `sender` 유무와 무관하게 그것도 함께 부른다 —
 * `sender` 없이 쓰는 조합 화면(예: 아래 `ChosungGridDual_entry.php`)이
 * "Quit을 눌렀을 때 무엇을 할지"를 자기 방식대로 정할 수 있게 하기
 * 위함(`onSelect`가 이미 `sender` 유무와 무관하게 항상 불리는 것과
 * 같은 이유).
 *
 * ## 2개를 나란히 — ChosungGrid.openDual(options)
 * 바탕화면 `매출/초성검색2.png`처럼 두 목록(예: 룸번호/영업)을 한
 * 팝업에 나란히 띄우고, **오른쪽을 고르는 순간 왼쪽의 마지막 선택과
 * 함께** 최종 확정하는 화면은 `ChosungGrid` 인스턴스 자체를 고치지
 * 않고 **`sender` 없이 두 번 재사용**해서 만든다 — 왼쪽은 `onSelect`로
 * "지금 고른 값"만 로컬로 기록/표시하고(팝업이 안 닫힘), 오른쪽은
 * `onSelect`에서 왼쪽 값 + 자기 값을 합쳐 조립 화면이 직접
 * `sender.action(...)`을 부른다 — "누가 최종인지, 무엇과 합칠지"는
 * 전부 조립하는 화면(`ChosungGridDual_entry.php`) 책임이고,
 * `ChosungGrid` 자신은 여전히 "그리드 하나 + 선택 콜백 하나"만 안다.
 * `ChosungGrid.openDual()`은 그 조립 화면을 Popup+iframe으로 여는
 * 정적 헬퍼일 뿐이다 — 아래 참고.
 *
 * 사용 예 — 직접(같은 문서 안에, iframe 없이):
 *   new ChosungGrid({
 *       containerId: 'cg-root',
 *       fetchUrl: 'ChosungGrid_fetch.php?source=직원',
 *       sender: window.parent,   // 없으면 null — 그때는 onSelect만 호출됨
 *       type: '웨이터선택',
 *       onSelect: function (key, display) { ... },
 *   });
 *
 * ## ChosungGrid.open(options) — Popup+iframe으로 한 번에 열기
 * 지시(1)의 "iframe으로 Popup.js를 이용하여 연다"를 실제로 매번 호출부가
 * (iframe URL 조립 + `new Popup(...)`) 두 단계를 반복하지 않도록 묶어주는
 * 정적 헬퍼. **`Popup`(`src/js/Popup/Popup.js`) 클래스가 먼저 로드돼
 * 있어야 한다** — `ChosungGrid.js`는 이 메서드를 부를 때만 `Popup`을
 * 참조하고, 인스턴스 메서드들은 여전히 `Popup`을 전혀 몰라도 동작한다
 * (iframe 안에서 직접 `new ChosungGrid(...)`로 쓸 때는 `Popup`이 필요
 * 없음 — 위 사용 예 참고).
 *
 *   ChosungGrid.open({
 *       source: '직원',            // (1) 서버가 매핑할 이름
 *       type: '웨이터선택',        // (3) 콜백 안에서 switch할 꼬리표
 *       callback: 'callback_초성검색_웨이터선택완료', // (선택) 콜백 pcmd 이름, 생략 시 'callback_초성검색'
 *       sender: window,            // (2) 콜백을 받을 창(보통 이 페이지 자신)
 *       ptitle: '웨이터 선택',      // (4) 팝업 제목
 *   });
 *
 * 내부적으로 `iframeUrl`을 `ChosungGrid_entry.php?source=...&type=...
 * &callback=...`로 조립해(같은 폴더의 `ChosungGrid_entry.php`를 기본
 * 대상으로 삼음 — `entryUrl` 옵션으로 다른 경로도 지정 가능)
 * `new Popup({title: ptitle, iframeUrl, ...})`를 연다. **팝업을 닫는
 * 것은 여전히 호출부 책임**이다 — `ChosungGrid.open()`이 돌려주는
 * `Popup` 인스턴스(또는 `Popup.instance(id)`)를 그 콜백을 받는 `case`
 * 안에서 직접 `.close()`하면 된다(위 "팝업을 닫는 것은 이 클래스의
 * 책임이 아니다" 원칙을 여기서도 그대로 지킴 — `open()`은 여는 것만
 * 대신해줄 뿐, 닫는 시점은 콜백 처리가 끝난 뒤로 호출부가 정한다).
 *
 * ## ChosungGrid.openDual(options) — 2개를 나란히 열기
 *   ChosungGrid.openDual({
 *       left:  { fetchUrl: 'ChosungGrid_fetch.php?source=룸', label: '룸번호',
 *                initial: { key: 7, display: '7T(5)' } },  // 선택 사항
 *       right: { fetchUrl: 'ChosungGrid_fetch.php?source=직원', label: '영업' },
 *       type: '룸영업선택',
 *       ptitle: '룸/영업 선택',
 *   });
 * `left`/`right` 각각 `fetchUrl`(필수)/`label`(선택, 위쪽 안내 문구)을
 * 받는다. `left.initial`(선택 사항, `{key, display}`)을 주면 팝업이 열릴
 * 때부터 왼쪽이 "최초 선택된 상태"로 시작한다 — 그 값이 그대로
 * `leftSelection`이 되어 "선택 된 {label}: {display}" 문구가 처음부터
 * 표시되고, 왼쪽 그리드를 한 번도 안 눌러도 오른쪽만 골라 바로 확정할
 * 수 있다(예: 이미 알고 있는 현재 룸을 바꾸지 않고 영업만 다시 고를
 * 때). **`key`는 `null`이어도 된다**(2026-09-09 수정) — "선택된
 * 상태"인지는 `display` 유무만으로 판단하고, `key`는 몰라도(또는
 * 아직 없어도) `display`만 있으면 충분한 경우를 위한 것(예: 룸번호는
 * 알지만 그 룸의 PK를 못 구한 경우). `initial` 자체를 생략하거나
 * `{key: null, display: null}`처럼 둘 다 없으면 "초기값 없음"과 완전히
 * 같다 — 이땐 **왼쪽 그리드에서 실제로 고르기 전까지는 오른쪽을 골라도
 * 확정되지 않는다**(2026-09-09 추가 — 아래 참고). `right`는 이 옵션
 * 자체가 없다 — 항상 빈 값으로 시작한다("오른쪽을 고르는 순간이 최종
 * 확정"이라는 규칙상 오른쪽에 미리 값을 넣어둘 이유가 없음).
 * `open()`과 마찬가지로 `ChosungGridDual_entry.php`(같은 폴더)
 * 를 Popup+iframe으로 연다 — 왼쪽을 고르면 위 "선택 된 {label}: 값"
 * 문구만 갱신되고(팝업 유지), **오른쪽을 고르는 순간** 왼쪽의 마지막
 * 선택(초기값을 그대로 안 바꿨다면 그 초기값) + 오른쪽 선택이 함께
 *   sender.action(options.callback || 'callback_초성검색2',
 *       { left: {key,display}, right: {key,display}, type }, window)
 * 로 전달된다(취소=Quit이면 `cancelled:true`와 함께 `left`/`right`
 * 모두 `null`). 콜백 이름은 기본값이 `callback_초성검색2`이지만
 * [2026-09-11 추가] **호출부가 `options.callback`으로 직접 지정할 수
 * 있다** — 이 위젯은 여러 화면이 재사용하므로, "선택이 끝나면 무엇을
 * 해야 하는지"까지 이름에 담긴 구체적인 pcmd(예:
 * `callback_초성검색2_룸영업선택완료`)는 위젯 자신이 미리 정해둘 수
 * 없고, 그 화면에서만 의미가 있는 이름은 그 화면(여는 쪽)이 열 때
 * 지어서 넘겨야 한다 — 위젯은 그 이름을 그대로 돌려줄 뿐 뜻을 모른다.
 *
 * **왼쪽 미선택 방어(2026-09-09 추가)**: 왼쪽이 아직 한 번도 선택되지
 * 않은 상태(=`left.initial`도 안 줬고, 왼쪽 그리드도 안 눌렀음)에서
 * 오른쪽을 고르면, `ChosungGridDual_entry.php`가 `sender.action(...)`
 * 을 아예 안 부르고(=팝업이 안 닫힘) `alert()`로 왼쪽 label을 먼저
 * 고르라고 안내한다 — "오른쪽을 고르는 순간이 최종 확정"인데 왼쪽
 * 값이 없는 채로 확정돼버리는 것을 막기 위함.
 */
class ChosungGrid {
    /**
     * @param {{containerId: string, fetchUrl: string, sender?: Window,
     *   type?: string, callback?: string,
     *   onSelect?: (key: *, display: string) => void,
     *   onQuit?: () => void}} options
     *   `callback`(선택) — 선택/취소 시 `sender.action(...)`에 쓸 pcmd
     *   이름. 생략하면 `'callback_초성검색'`(기존과 동일, 하위호환).
     */
    constructor(options) {
        options = options || {};
        if (!options.containerId) {
            throw new Error('ChosungGrid: containerId는 필수입니다.');
        }
        if (!options.fetchUrl) {
            throw new Error('ChosungGrid: fetchUrl은 필수입니다.');
        }
        this.containerId = options.containerId;
        this.fetchUrl = options.fetchUrl;
        this.sender = options.sender || null;
        this.type = typeof options.type === 'string' ? options.type : '';
        // [2026-09-11 추가] "창이 뜰 때 callback 이름을 부여해서 띄우고,
        // 일을 마칠 때 그 이름으로 보고한다" 규칙 — openDual()과 동일
        // (생략 시 기존 이름을 그대로 씀, 하위호환).
        this.callback = typeof options.callback === 'string' && options.callback !== ''
            ? options.callback
            : 'callback_초성검색';
        this.onSelect = typeof options.onSelect === 'function' ? options.onSelect : null;
        this.onQuit = typeof options.onQuit === 'function' ? options.onQuit : null;
        // 지금까지 키패드로 누적된 초성 검색 문자열(빈 문자열 = 필터 없음).
        this.chosungValue = '';
        // _fetchRows()의 응답 도착 순서 뒤바뀜 방어용 요청 번호(아래 참고).
        this._fetchToken = 0;

        ChosungGrid._injectDefaultStyles();
        this._render();

        ChosungGrid._ensureLibraryLoaded()
            .then(() => this._initGrid())
            .catch((err) => {
                const el = document.getElementById(this.gridDivId);
                if (el) {
                    el.textContent = 'AG Grid 로드 실패: ' + err.message;
                }
            });
    }

    /** 지금 필터로 걸려 있는 누적 초성 문자열. */
    getChosungValue() {
        return this.chosungValue;
    }

    /** 지금 필터 그대로 서버에서 다시 조회한다(외부에서 강제 새로고침하고 싶을 때). */
    requery() {
        this._fetchRows();
    }

    /** DOM/이벤트 정리 — 팝업을 닫을 때 등. */
    destroy() {
        if (this._resizeObserver) {
            this._resizeObserver.disconnect();
            this._resizeObserver = null;
        }
        if (this.keypad) {
            this.keypad.destroy();
        }
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
            throw new Error('ChosungGrid: containerId 요소를 찾을 수 없습니다: ' + this.containerId);
        }
        container.classList.add('cg-root');
        this.gridDivId = this.containerId + '-grid';
        this.keypadDivId = this.containerId + '-keypad';
        // 지시(1) — 좌측 aggrid, 우측 초성검색(키패드).
        container.innerHTML =
            '<div class="cg-grid-col"><div id="' + this._escape(this.gridDivId) + '" class="ag-theme-alpine cg-grid"></div></div>' +
            '<div class="cg-keypad-col" id="' + this._escape(this.keypadDivId) + '"></div>';

        this.keypad = new ChosungKeypad({
            containerId: this.keypadDivId,
            showQuitButton: true,
            onInput: (value) => {
                this.chosungValue = value;
                this._fetchRows();
            },
            onClear: () => {
                this.chosungValue = '';
                this._fetchRows();
            },
            onQuit: () => {
                if (this.sender && typeof this.sender.action === 'function') {
                    this.sender.action(
                        this.callback,
                        { key: null, display: null, type: this.type, cancelled: true },
                        window
                    );
                }
                if (this.onQuit) {
                    this.onQuit();
                }
            },
        });
    }

    _initGrid() {
        this.gridApi = agGrid.createGrid(document.getElementById(this.gridDivId), {
            // deco_amd_amd.php가 이미 확인해둔 이 vendor 버전(v36.1.0)의
            // 제약(CSS 변수 기반 커스터마이징이 실제로 안 먹음) 때문에
            // theme:'legacy'를 그대로 재사용한다 — 자세한 경위는
            // deco_amd_amd.php 상단 주석/md 문서 참고.
            theme: 'legacy',
            // flex:1 — 표시 컬럼 하나뿐이라 그리드 폭 전체를 채운다(안
            // 그러면 ag-grid 기본 폭(약 200px)만 쓰고 나머지는 빈칸으로
            // 남는다 — 초성검색.png처럼 목록 행 전체가 폭을 채워야 함).
            columnDefs: [{ field: 'display', headerName: '', flex: 1 }],
            rowData: [],
            headerHeight: 0,
            defaultColDef: { resizable: false, sortable: false, filter: false, editable: false },
            suppressCellFocus: true,
            onRowClicked: (event) => this._onRowClicked(event.data),
        });
        this._fetchRows();

        // [2026-09-09 수정 — window resize 이벤트로는 효과가 없었음]
        // "팝업이 뜨고 좁은 너비로 뜨는데 드래그드롭으로 움직이면 너비가
        // 바르게 나옴" — 이 그리드는 Popup(iframe) 안에서 만들어지는데,
        // 그 iframe/부모 박스가 아직 최종 폭으로 자리잡기 전(브라우저가
        // 레이아웃을 한 번 더 정리하기 전) 시점에 `agGrid.createGrid()`
        // 가 먼저 실행돼, flex:1 컬럼이 그 순간의(좁은/틀린) 컨테이너 폭
        // 기준으로 계산돼버리는 경우가 있다 — 드래그가 폭을 "고쳐준" 게
        // 아니라, 드래그 중 `getBoundingClientRect()` 호출(강제 리플로우)
        // 과 이후 재배치가 브라우저에게 레이아웃을 다시 계산하게 만들면서
        // 우연히 올바른 폭이 드러난 것뿐이라는 판단이었음.
        //
        // 처음엔 `window`에 `resize` 이벤트를 인위적으로 쏘는 방식으로
        // 고쳐보려 했으나 효과가 없었다 — 이 ag-grid 버전(v36,
        // theme:'legacy')이 자기 폭을 다시 재는 기준은 `window`의
        // resize가 아니라 **그리드가 실제로 그려진 DOM 엘리먼트 자신의
        // 크기 변화**(`ResizeObserver`)로 보인다. 그래서 이 클래스가
        // 직접 그 엘리먼트를 `ResizeObserver`로 지켜보다가, 관찰이
        // 시작되는 즉시(스펙상 최초 1회는 항상 콜백이 온다) 또는 크기가
        // 실제로 바뀔 때마다 `gridApi.sizeColumnsToFit()`을 불러 그
        // 시점의 실측 폭으로 flex 컬럼을 다시 채운다 — "폭이 안정될
        // 때까지 기다렸다가 한 번 쏘는" 대신 "폭이 바뀔 때마다 계속
        // 맞춘다"는 더 근본적인 방식이라, 이 컨테이너 크기가 나중에 또
        // 바뀌어도(예: 팝업 크기 조정 기능이 생기면) 계속 정확하다.
        this._resizeObserver = new ResizeObserver(() => {
            if (this.gridApi) {
                this.gridApi.sizeColumnsToFit();
            }
        });
        this._resizeObserver.observe(document.getElementById(this.gridDivId));
    }

    /**
     * 키패드를 빠르게 연속으로 누르면(예: "ㄴ" 다음 바로 Clear) 매번
     * 새 요청이 나가는데, 응답은 네트워크 상황에 따라 보낸 순서와
     * 다르게 도착할 수 있다 — 그대로 두면 나중에 보낸(=더 최신 필터의)
     * 요청의 응답이 먼저 도착해 그려진 뒤, 먼저 보낸(=오래된 필터의)
     * 응답이 뒤늦게 도착해 화면을 덮어써버리는 경쟁 상태가 생긴다.
     * `_fetchToken`을 요청마다 증가시켜, 응답이 왔을 때 "이게 지금도
     * 가장 최근에 보낸 요청인가"를 확인하고 아니면 조용히 버린다.
     */
    _fetchRows() {
        const separator = this.fetchUrl.indexOf('?') === -1 ? '?' : '&';
        const url = this.fetchUrl + separator + 'chosung=' + encodeURIComponent(this.chosungValue);
        const token = ++this._fetchToken;
        fetch(url)
            .then((res) => res.json())
            .then((rows) => {
                if (token !== this._fetchToken) {
                    return; // 그 사이 더 최신 요청이 나감 — 이 응답은 버린다.
                }
                this.gridApi.setGridOption('rowData', Array.isArray(rows) ? rows : []);
            })
            .catch((err) => {
                console.warn('ChosungGrid: 조회 실패', err);
            });
    }

    _onRowClicked(row) {
        if (!row) {
            return;
        }
        if (this.onSelect) {
            this.onSelect(row.key, row.display);
        }
        if (this.sender && typeof this.sender.action === 'function') {
            // [2026-09-13 추가] "code, 출석code" — row 전체를 베이스로
            // 삼아 서버가 얹어둔 다른 필드(예: 출석code)도 그대로 딸려
            // 가게 하고, key/display/type만 이 클래스가 보장하는 값으로
            // 덮어쓴다(파일 상단 "선택 시" 절 참고).
            this.sender.action(
                this.callback,
                Object.assign({}, row, { key: row.key, display: row.display, type: this.type }),
                window
            );
        }
    }

    _escape(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ------------------------------------------------------------------
    // 정적 유틸 — Popup+iframe으로 열기 / ag-grid vendor 로딩 / 기본 스타일
    // ------------------------------------------------------------------

    /**
     * `Popup.js`의 iframeUrl로 이 화면을 띄우는 정적 헬퍼. 클래스 상단
     * docblock "ChosungGrid.open(options)" 절 참고.
     *
     * [2026-09-09 수정] "fetch url을 openPicker 할 때 노출되게, 가독성
     * 있게" 지시로 `fetchUrl`을 정식 옵션으로 받는다 — 그동안 실제
     * 데이터 엔드포인트(`ChosungGrid_fetch.php?source=...`)가
     * `ChosungGrid_entry.php` 안에 숨어 있어, 호출부(`openPicker()` 등)
     * 코드만 봐서는 어느 URL을 조회하는지 알 수 없었다. 이제
     * `options.fetchUrl`을 그대로 iframe 쿼리스트링에 실어 보내고,
     * `ChosungGrid_entry.php`는 그 값이 있으면 그대로 쓴다 — 호출부가
     * 정확히 무엇을 여는지 자기 코드 안에서 바로 읽을 수 있다.
     * `fetchUrl`을 생략하면(기존 호출부와의 호환) `source`로부터 예전
     * 방식 그대로 `ChosungGrid_fetch.php?source=...`를 만든다.
     *
     * @param {{source?: string, fetchUrl?: string, type?: string,
     *   callback?: string, sender?: Window,
     *   ptitle?: string, id?: string, entryUrl?: string,
     *   iframeWidth?: (number|string), iframeHeight?: (number|string),
     *   modal?: boolean, draggable?: boolean, onClose?: (id: string) => void}} options
     *   `source`/`fetchUrl` 중 하나는 있어야 한다. `callback`(선택) —
     *   선택/취소 시 `sender.action(...)`에 쓸 pcmd 이름. 생략하면
     *   `'callback_초성검색'`(기존과 동일, 하위호환).
     * @return {Popup} 호출부가 나중에 직접 닫을 수 있도록 그 Popup 인스턴스를 그대로 반환.
     */
    static open(options) {
        options = options || {};
        if (typeof Popup === 'undefined') {
            throw new Error('ChosungGrid.open(): Popup.js가 먼저 로드돼 있어야 합니다.');
        }
        const fetchUrl = options.fetchUrl || ChosungGrid._defaultFetchUrl(options.source || '');
        const entryUrl = options.entryUrl || ChosungGrid._defaultEntryUrl();
        // [2026-09-11 추가] "창이 뜰 때 callback 이름을 부여해서 띄우고,
        // 일을 마칠 때 그 이름으로 보고한다" 규칙 — openDual()과 동일
        // (생략하면 기존 이름 callback_초성검색을 그대로 씀, 하위호환).
        const callbackName = typeof options.callback === 'string' && options.callback !== ''
            ? options.callback
            : 'callback_초성검색';
        const query = 'fetchUrl=' + encodeURIComponent(fetchUrl) +
            '&type=' + encodeURIComponent(options.type || '') +
            '&callback=' + encodeURIComponent(callbackName) +
            // [2026-09-09 추가] 이 iframe 문서(ChosungGrid_entry.php) 자신은
            // 지금까지 캐시버스팅이 없었다(호출부의 <script src>만
            // filemtime로 버스팅됨) — deco_main.php처럼 부모 문서가
            // 새로고침 안 되고 오래 떠 있는 화면에서 반복해서 열면 브라우저가
            // 이 문서를 캐시해 최신 CSS/마크업이 안 보일 수 있어, 매번 새
            // 요청이 되도록 타임스탬프를 붙인다.
            '&_=' + Date.now();
        const iframeUrl = entryUrl + (entryUrl.indexOf('?') === -1 ? '?' : '&') + query;

        // `sender`는 iframe 쪽(ChosungGrid_entry.php)이 자기 스스로
        // window.parent로 판단하므로 여기서 iframe에 전달할 방법은 없다
        // (다른 문서로 진짜 이동하는 iframe이라 살아있는 JS 참조를 그대로
        // 못 넘김) — options.sender는 지금은 참고용 문서화 목적일 뿐,
        // 실제로는 항상 "이 Popup을 만든 window"가 sender가 된다.
        return new Popup({
            id: options.id,
            title: typeof options.ptitle === 'string' ? options.ptitle : '초성검색',
            modal: options.modal,
            draggable: options.draggable,
            iframeUrl: iframeUrl,
            // [2026-09-09 수정] "그리드 60%/우측 여백 1cm/Clear로부터
            // 1cm 여백만" 지시로 역산한 값(548×422) — ChosungKeypad의
            // 실제 폭(188px, .ck-keys 4×44px 그리드)/높이(약 374px,
            // 탭+6행+Clear·Quit행 실측)를 기준으로:
            //   폭: 그리드(60%) : 나머지(40%=gap12+키패드188+1cm여백) 비율이
            //       정확히 맞도록 콘텐츠 폭 500px → +좌우 body padding(10+1cm) = 548
            //   높이: 키패드 실제 높이(374px)+아래 1cm 여백 → +상하 body
            //       padding(10+1cm) = 422 (ChosungGrid_entry.php의 body
            //       padding이 10px 10px 1cm 10px — 위 .cg-grid의 min-height
            //       설명 참고, 그리드도 height:100%로 이 값을 그대로 물려받음).
            iframeWidth: options.iframeWidth || 548,
            iframeHeight: options.iframeHeight || 422,
            // 주면 entry가 부모 전역 action() 대신 이 콜백으로 보고한다(Popup.senderFor() 참고).
            onAction: options.onAction,
            onClose: options.onClose,
        });
    }

    /**
     * `fetchUrl`을 안 준(옛 방식) 호출부를 위한 기본값 — 같은 폴더의
     * `ChosungGrid_fetch.php?source=...`를 그대로 만든다.
     */
    static _defaultFetchUrl(source) {
        return ChosungGrid._SCRIPT_DIR + 'ChosungGrid_fetch.php?source=' + encodeURIComponent(source);
    }

    /** ChosungGrid.js와 같은 폴더의 ChosungGrid_entry.php를 기본 대상으로 삼는다. */
    static _defaultEntryUrl() {
        return ChosungGrid._SCRIPT_DIR + 'ChosungGrid_entry.php';
    }

    /**
     * `Popup.js`의 iframeUrl로 `ChosungGridDual_entry.php`(2개를 나란히
     * 보여주는 조립 화면)를 띄우는 정적 헬퍼. 클래스 상단 docblock
     * "ChosungGrid.openDual(options)" 절 참고.
     *
     * @param {{left: {fetchUrl: string, label?: string,
     *     initial?: {key: *, display: string}},
     *   right: {fetchUrl: string, label?: string}, type?: string,
     *   callback?: string, sender?: Window, ptitle?: string, id?: string,
     *   entryUrl?: string, iframeWidth?: (number|string),
     *   iframeHeight?: (number|string), modal?: boolean,
     *   draggable?: boolean, onClose?: (id: string) => void}} options
     *   `callback`(선택) — 선택 완료 시 `sender.action(...)`에 쓸 pcmd
     *   이름. 생략하면 `'callback_초성검색2'`(기존과 동일, 하위호환).
     * @return {Popup} 호출부가 나중에 직접 닫을 수 있도록 그 Popup 인스턴스를 그대로 반환.
     */
    static openDual(options) {
        options = options || {};
        if (typeof Popup === 'undefined') {
            throw new Error('ChosungGrid.openDual(): Popup.js가 먼저 로드돼 있어야 합니다.');
        }
        if (!options.left || !options.left.fetchUrl || !options.right || !options.right.fetchUrl) {
            throw new Error('ChosungGrid.openDual(): left.fetchUrl/right.fetchUrl은 필수입니다.');
        }
        const entryUrl = options.entryUrl || ChosungGrid._defaultDualEntryUrl();
        // left.initial — "왼쪽이 최초 선택된 상태로 시작"(위 docblock
        // 참고). display만 있으면 "선택된 상태"로 치고, key는 null/
        // undefined여도 된다(2026-09-09 수정 — "key값은 null이어도 된다,
        // 둘 다 null이어도 된다" 지시) — key가 없을 땐 leftInitialKey
        // 쿼리스트링 자체를 안 보낸다(그냥 encodeURIComponent(null)을
        // 보내면 문자열 "null"이 실제 key처럼 전달돼버리는 문제 방지).
        // display까지 null/undefined면(둘 다 null) "초기값 없음"과
        // 완전히 동일하게 취급된다.
        const leftInitial = options.left.initial || null;
        const hasLeftInitialDisplay = leftInitial != null && leftInitial.display != null;
        let leftInitialQuery = '';
        if (hasLeftInitialDisplay) {
            leftInitialQuery = '&leftInitialDisplay=' + encodeURIComponent(leftInitial.display);
            if (leftInitial.key !== null && leftInitial.key !== undefined) {
                leftInitialQuery += '&leftInitialKey=' + encodeURIComponent(leftInitial.key);
            }
        }
        // [2026-09-11 추가] "창이 뜰 때 callback 이름을 부여해서 띄우고,
        // 일을 마칠 때 그 이름으로 보고한다" 지시 — 생략하면 기존
        // 이름(callback_초성검색2)을 그대로 씀(하위호환).
        const callbackName = typeof options.callback === 'string' && options.callback !== ''
            ? options.callback
            : 'callback_초성검색2';
        const query = 'leftFetchUrl=' + encodeURIComponent(options.left.fetchUrl) +
            '&leftLabel=' + encodeURIComponent(options.left.label || '') +
            '&rightFetchUrl=' + encodeURIComponent(options.right.fetchUrl) +
            '&rightLabel=' + encodeURIComponent(options.right.label || '') +
            '&type=' + encodeURIComponent(options.type || '') +
            '&callback=' + encodeURIComponent(callbackName) +
            leftInitialQuery +
            // static open()과 동일한 이유(캐시버스팅) — 아래 참고.
            '&_=' + Date.now();
        const iframeUrl = entryUrl + (entryUrl.indexOf('?') === -1 ? '?' : '&') + query;

        return new Popup({
            id: options.id,
            title: typeof options.ptitle === 'string' ? options.ptitle : '초성검색',
            modal: options.modal,
            draggable: options.draggable,
            iframeUrl: iframeUrl,
            // [2026-09-09 수정] "좌우 객체 사이에 1cm 여백" 지시로 두
            // 그리드 사이 gap을 24px → 1cm(≈37.8px)로 키우면서
            // iframeWidth도 다시 역산했다 — 그리드/키패드 한 쌍의 필요
            // 콘텐츠폭(500, open()의 548×422 계산과 동일)×2 + 그 사이
            // 여백 1cm(37.8) + body 좌우 padding(10+1cm=47.8) = 1085.6
            // → 1086.
            // [2026-09-09 추가 수정] "선택된 룸번호가 크고 찐하게
            // 배경색이 눈에 띄게" 지시로 `#cgd-left-label`이 작은 텍스트
            // (0.82rem, 여백만 6px)에서 큰 배지(1.5rem+padding 6px×2)로
            // 커졌다 — 이 라벨은 `.cgd-col-root`(그리드+키패드) *위쪽*에
            // 있어, 세로 공간을 그만큼 그리드/키패드 쪽에서 빼앗는다.
            // 기존 422는 이 라벨의 높이를 아예 안 뺀 값이었으므로(작을
            // 땐 눈에 덜 띄었을 뿐 원래도 정확친 않았음), 배지 높이(약
            // font-size 24px×line-height~1.2=28.8 + padding 12 +
            // margin-bottom 6 ≈ 47px)만큼 iframeHeight를 그대로
            // 늘려(422→470) 키패드 쪽 세로 여유가 줄어들지 않게 했다
            // (Clear 버튼이 다시 가려지는 예전 버그 재발 방지 — 실측
            // 기반이 아니라 CSS 값 기준 추정이라 사용자 확인 필요).
            iframeWidth: options.iframeWidth || 1056,
            iframeHeight: options.iframeHeight || 470,
            // 주면 entry가 부모 전역 action() 대신 이 콜백으로 보고한다(Popup.senderFor() 참고).
            onAction: options.onAction,
            onClose: options.onClose,
        });
    }

    /** ChosungGrid.js와 같은 폴더의 ChosungGridDual_entry.php를 기본 대상으로 삼는다. */
    static _defaultDualEntryUrl() {
        return ChosungGrid._SCRIPT_DIR + 'ChosungGridDual_entry.php';
    }

    /**
     * ag-grid-community를 문서에 한 번만 주입하고 로드 완료를 기다리는
     * Promise를 캐시해 재사용한다(`AGGrid.js::_ensureLibraryLoaded()`와
     * 동일한 패턴). 이미 다른 화면이 `AGGrid.js`를 통해 로드해뒀으면
     * `window.agGrid`가 이미 있어 즉시 resolve — 같은 vendor 파일을
     * 두 번 받지 않는다.
     *
     * [2026-09-09 수정 — 진짜 원인 발견] "팝업이 좁게 뜨다가 드래그해야
     * 바르게 나옴" 문제를 `window` resize 디스패치, `ResizeObserver`+
     * `sizeColumnsToFit()` 순으로 시도했으나 둘 다 효과가 없었다 —
     * "컨테이너 크기를 못 재는" 문제가 아니라는 뜻이었다. 다시 보니
     * 이 함수는 지금까지 **`<script>`(JS 본체)의 로드만 기다리고
     * `<link rel="stylesheet">`(ag-grid.css/ag-theme-alpine.css) 두 개는
     * 로드 완료를 전혀 안 기다리고** 있었다 — `_initGrid()`가
     * `agGrid.createGrid()`를 부르는 시점에 ag-grid 자신의 내부 레이아웃
     * CSS(`.ag-root-wrapper` 등의 `display:flex` 규칙 등)가 아직 적용
     * 안 된 상태일 수 있고, 그 상태에서 그리드가 만들어지면 내부 DOM
     * 구조/치수가 잘못 굳어(초기화되어) 버려서 이후 `sizeColumnsToFit()`
     * 을 다시 불러도 컬럼 폭만 재계산될 뿐 이미 잘못 굳어진 내부 구조
     * 자체는 안 고쳐졌던 것으로 추정 — 드래그처럼 "우연히 뭔가를 건드려"
     * 강제 재레이아웃이 걸릴 때만 (CSS가 그 사이엔 이미 다 로드돼 있어서)
     * 마침내 바르게 나온 것으로 판단된다. 이제 두 CSS `<link>`도
     * 각각 `onload`(실패해도 무한 대기하지 않도록 `onerror`도 resolve로
     * 취급)를 추적해, **JS 본체 + CSS 두 개가 전부 끝난 뒤에야**
     * `_initGrid()`가 실행되도록 한다 — CSS가 이미 다른 화면에서 로드돼
     * 있던 경우(`document.getElementById('aggrid-vendor-css')`가 이미
     * 있음)는 그 시점에 이미 적용 완료 상태라고 보고 기존처럼 스킵한다.
     */
    static _ensureLibraryLoaded() {
        if (window.agGrid) {
            return Promise.resolve();
        }
        if (!ChosungGrid._loadPromise) {
            ChosungGrid._loadPromise = new Promise((resolve, reject) => {
                const cssReadyPromises = [];
                if (!document.getElementById('aggrid-vendor-css')) {
                    const gridCss = document.createElement('link');
                    gridCss.id = 'aggrid-vendor-css';
                    gridCss.rel = 'stylesheet';
                    gridCss.href = ChosungGrid._AGGRID_VENDOR_DIR + 'ag-grid.css';
                    cssReadyPromises.push(new Promise((res) => {
                        gridCss.onload = res;
                        gridCss.onerror = res; // CSS 로드 실패해도 JS 초기화 자체는 막지 않는다.
                    }));
                    document.head.appendChild(gridCss);

                    const themeCss = document.createElement('link');
                    themeCss.rel = 'stylesheet';
                    themeCss.href = ChosungGrid._AGGRID_VENDOR_DIR + 'ag-theme-alpine.css';
                    cssReadyPromises.push(new Promise((res) => {
                        themeCss.onload = res;
                        themeCss.onerror = res;
                    }));
                    document.head.appendChild(themeCss);
                }
                const script = document.createElement('script');
                script.src = ChosungGrid._AGGRID_VENDOR_DIR + 'ag-grid-community.min.js';
                const scriptReady = new Promise((res, rej) => {
                    script.onload = () => res();
                    script.onerror = () => rej(new Error('ag-grid-community.min.js를 불러오지 못했습니다.'));
                });
                document.head.appendChild(script);

                Promise.all([scriptReady, ...cssReadyPromises]).then(resolve, reject);
            });
        }
        return ChosungGrid._loadPromise;
    }

    static _injectDefaultStyles() {
        if (document.getElementById('chosung-grid-default-styles')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'chosung-grid-default-styles';
        style.textContent = `
@layer chosung-grid-defaults {
    .cg-root {
        display: flex; gap: 12px; align-items: stretch;
        font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
    }
    /* [2026-09-09 수정] "그리드 너비를 60% 수준으로" 지시로 50% → 60%.
       나머지 40%(=ChosungGrid.open()의 iframeWidth 기본값과 함께
       계산된 값)는 키패드 자기 폭(188px, ChosungKeypad.js의
       .ck-keys 4×44px 그리드가 결정) + gap(12px) + 우측 여백 1cm
       (ChosungGrid_entry.php의 body padding-right)로 정확히 맞아
       떨어지도록 iframeWidth 기본값을 역산해뒀다 — 키패드 오른쪽에
       불필요한 빈 공간이 안 남는다.
       [2026-09-09 수정 — "팝업이 좁게 뜨고 스크롤바까지 생김" 진짜 원인]
       이전엔 flex: 0 0 60%(flex-shrink:0)라 이 칼럼이 절대 안
       줄어들었다 — 옆의 .cg-keypad-col도 flex: 0 0 auto(shrink:0)
       라 마찬가지로 안 줄어드는데, 이러면 iframeWidth 매직넘버(위 계산,
       1072→1086→1056으로 여러 번 손으로 재역산해온 값)가 픽셀 단위로
       정확히 안 맞으면 완충 없이 바로 넘쳐(overflow) iframe에 가로
       스크롤바가 생긴다 — ChosungGridDual(좌우 2벌 + 1cm gap까지 겹쳐
       오차가 배로 커짐)에서 특히 잘 드러났다. flex-shrink를 0→1로
       바꿔(flex: 1 1 60%) 폭이 부족할 때 그리드 칼럼이 먼저 양보하게
       한다(키패드는 버튼 터치 크기 때문에 계속 안 줄어드는 게 맞음) —
       매직넘버가 조금 어긋나도 "그리드가 약간 좁아질 뿐" 스크롤바로
       터지지 않는다. */
    .cg-grid-col { flex: 1 1 60%; max-width: 60%; min-width: 0; }
    /* [2026-09-09 수정] "Clear 버튼으로부터 1cm 여백만" 지시로, 그리드
       높이를 더 이상 고정 min-height로 강제하지 않는다 — 이 행(row)의
       실제 높이는 이제 ChosungGrid_entry.php의 body 높이(=iframeHeight
       - 상하 padding, 키패드의 실제 필요 높이 + 1cm에 맞춰 역산됨)가
       그대로 정해주고, 그리드는 height:100%로 그 값을 그대로 물려받는다
       (키패드보다 그리드가 억지로 더 커지면 키패드 쪽에 빈 여백이
       생기므로, 두 컬럼의 높이가 서로 자연스럽게 맞아떨어지게 함).
       200px는 극단적으로 작은 컨테이너에 얹혔을 때의 최소 방어선일 뿐
       실제 레이아웃을 좌우하지 않는다. */
    .cg-grid { width: 100%; height: 100%; min-height: 200px; }
    /* 지시(3) — 초성검색.png 그대로 헤더 없는 단순 목록. headerHeight:0
       (JS 옵션)만으로는 이 vendor 버전(v36.1.0)에서 얇은 빈 헤더 줄이
       남을 수 있어(deco_amd_amd.php가 이미 겪은 CSS 변수 무시 문제와
       같은 종류) 헤더 자체를 CSS로도 감춘다. */
    .cg-grid .ag-header { display: none; }
    .cg-grid .ag-row { cursor: grab; }
    .cg-grid .ag-row:hover { filter: invert(8%); }
    /* [2026-09-09 추가 — "팝업이 좁게 뜨고 스크롤바까지 생김" 마지막
       조각] Popup.js의 중앙정렬 버그(뷰포트 절반으로 폭이 잘리던 것,
       Popup.js의 _applyPosition() 독스블록 참고)를 고친 뒤에도, 브라우저에서
       직접 열어보니 각 그리드 밑에 가로 스크롤바(.ag-body-horizontal-
       scroll)가 여전히 남아 있었다 — 실측 결과 컬럼 폭(.ag-cell)이
       컨테이너 폭과 정확히 같아(0px 오차) 실제로는 전혀 넘치지 않는데도
       ag-grid가 "스크롤이 필요하다"고 내부적으로 판단해둔 상태가
       (컨테이너가 아직 좁았던 시점에 한 번 정해진 채) 그대로 안 풀리는
       것으로 확인됨(sizeColumnsToFit()/resize 이벤트 재시도로도 안
       풀림). 이 위젯은 컬럼이 항상 정확히 1개(flex:1, 컨테이너 폭을
       그대로 채움)라 애초에 가로 스크롤이 필요할 일이 없으므로, ag-grid
       내부 판단과 무관하게 이 스크롤바 자체를 CSS로 항상 숨긴다(실제로
       숨겨도 빈 공간이 안 남는 것을 확인함 — 이 엘리먼트는 자기 높이만큼
       레이아웃에서 자리를 차지하지 않는 절대/플로팅 요소이거나, 숨겨도
       그 자리를 그리드 본문이 그대로 채운다). */
    .cg-grid .ag-body-horizontal-scroll { display: none !important; }
    .cg-keypad-col { flex: 0 0 auto; }
}
        `;
        document.head.appendChild(style);
    }
}

// 이 스크립트 자신이 로드된 URL 기준으로 자기 폴더 경로를 계산한다
// (AGGRID_SCRIPT_DIR과 동일한 원리, AGGrid.js 참고) — 프로젝트가 다른
// 경로로 옮겨져도 상대 위치만 유지되면 그대로 동작한다. aggrid vendor
// 폴더(형제 폴더)와 ChosungGrid_entry.php(같은 폴더) 둘 다 이 값에서
// 파생한다.
ChosungGrid._SCRIPT_DIR = (function () {
    const src = document.currentScript && document.currentScript.src;
    if (!src) {
        return './';
    }
    return src.replace(/ChosungGrid\.js(\?.*)?$/, '');
})();
ChosungGrid._AGGRID_VENDOR_DIR = ChosungGrid._SCRIPT_DIR + '../aggrid/vendor/';
ChosungGrid._loadPromise = null;
