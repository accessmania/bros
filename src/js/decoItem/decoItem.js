/**
 * DecoItem — 바탕화면 `매출/상품.png`("상품선택" 팝업)을 재현하는
 * 화면 전용 위젯.
 *
 * [2026-09-12 개명] 최초에는 폴더/파일/클래스명 전부 한글(`deco상품`)
 * 이었으나, `document.currentScript.src`가 한글 파일명을 항상 퍼센트
 * 인코딩해서 돌려주는 바람에 자기 폴더 경로 계산이 깨져 ag-grid 로딩이
 * 실패하는 버그를 겪었다(아래 `_SCRIPT_DIR` 계산부 docblock 참고) —
 * "한글파일명 대신 영문파일명으로" 지시로 `DecoItem`/`decoItem.js`로
 * 전면 개명(폴더/파일/클래스/CSS id 전부). 내부 로직·지시 원문 대응은
 * `deco상품` 시절과 동일하다.
 *
 * [지시 7] 이건 여러 프로젝트에 재사용 가능한 범용 모듈은 아니다 —
 * `ChosungGrid`(source 이름 → 테이블 간접 매핑)와 달리 `t상품`/
 * `t상품분류` 두 테이블을 이 파일이 직접 안다(서버 쪽도
 * `decoItem_fetch.php`가 같은 원칙).
 *
 * ## 화면 구성 — 좌우 모두 ag-grid (2026-09-12 구조 변경)
 *   - 좌측(좁은 열): `t상품분류` 전체를 보여주는 ag-grid 한 개 — "검색/
 *     편집 없는" 그리드(검색바 없음, `defaultColDef`로 정렬/필터/편집
 *     모두 끔, 지시 2). 컬럼 1개(`분류`). 행을 클릭하면(`onRowClicked`)
 *     `_selectCategory(code)`가 그 분류에 속한 상품만 우측 그리드에
 *     불러온다(지시 3) — 선택된 행은 ag-grid의 `rowSelection:'single'`
 *     기본 강조를 이 프로젝트 배지 배색(검은 배경+`#ffeb3b` 노란 글자)
 *     으로 덮어써 표시한다.
 *   - 우측: [상품 열] 검색바(2026-09-12 추가 지시 "검색은 바,초성
 *     모두가되게" — `DecoItem._chosungMatch()`로 상품명 **맨 앞부터**
 *     문자 그대로("바")와 초성("ㅂ")을 섞어 써도 매칭(이 프로젝트
 *     초성검색 전체의 관례 — 맨 앞부터 위치별 AND), 서버 재조회 없이
 *     지금 로드된 상품 안에서만 필터) + ag-grid 그리드 하나(컬럼은
 *     순서(회색)/상품명(진한 글자)/현금가(빨강)/카드가(파랑)(지시 5), 정렬은
 *     `decoItem_fetch.php`가 이미 순서 내림차순으로 응답하므로 그대로
 *     표시(지시 3의 SQL 그대로)) + **[키패드 열]** 바로 그 옆에
 *     `ChosungKeypad`(기존 모듈 재사용, 새로 안 만듦) — "초성검색이
 *     우측에 표시안됨... 기존 ChosungKeypad처럼 누르는 버튼식
 *     키패드를 기대함"(2026-09-12 추가 지시)에 따라, 타이핑 검색바
 *     만으로는 부족했던 "버튼을 눈으로 보고 클릭"하는 UI를 더했다 —
 *     `ChosungGrid.js`가 이미 하는 조합(그리드 좌측+키패드 우측)과
 *     같은 형태. 검색바와 키패드는 서로 독립적으로 값을 유지하고,
 *     둘 다 값이 있으면 AND로 함께 적용된다(`_applySearch()`).
 *
 * ## 좌측 분류도 결국 ag-grid — 마크업/데이터 분리 (2026-09-12)
 * 직전 라운드에서는 "js로 구성하지 말고 decoitem.php 페이지를 구성,
 * js가 호출하는 방식으로" 지시에 따라 좌측을 `decoItem.php`가 서버에서
 * `t상품분류`를 직접 조회해 `<div>` 버튼 목록으로 통째로 구워 보냈다.
 * 이번 지시("화면 좌측에도 aggrid(검색/편집 없는)를 배치")로 좌측도
 * 결국 ag-grid API로 데이터를 얹어야 하게 되면서, `decoItem.php`가 DB를
 * 미리 조회해둘 이유가 없어졌다 — 이제 `decoItem.php`는 **좌우 두
 * 그리드의 빈 컨테이너 div만 그리는 순수 정적 마크업**이고, 좌/우 둘 다
 * `decoItem_fetch.php`의 JSON을 `decoItem.js`가 직접 불러 각자의
 * ag-grid에 `setGridOption('rowData', ...)`로 채운다.
 *
 * 두 차례("글자만 뜨는데", "좌측 분류가 안 나옴") 고쳐도 좌측이 계속
 * 안 보였던 진짜 원인은 **JSON 왕복 자체의 실패 은폐**였다 —
 * `decoItem_fetch.php`가 SQL 실패 시 `http_response_code(500)` +
 * `{"error": ...}`를 반환해도 `fetch().then(res => res.json())`은
 * HTTP status를 보지 않고 그대로 파싱에 성공하므로, 실패가 "성공"으로
 * 오인돼 빈 배열로 조용히 끝나버렸다(에러 메시지조차 안 뜸). 지금은
 * 좌/우 두 조회(`_loadCategories()`/`_fetchProducts()`) 모두 `res.ok`와
 * 응답 바디의 `error` 필드를 함께 확인한 뒤에만 성공으로 처리하고,
 * 실패하면 해당 그리드 자리에 실패 사유를 그대로 표시한다 — 원인
 * 불명으로 조용히 비어 있는 경우가 구조적으로 없어졌다. 이번 지시에서
 * 사용자가 `t상품분류`의 실제 컬럼명(`code`/`분류`, 이전 추정
 * `분류명`은 틀렸었다)을 직접 알려줘 SQL도 함께 정정했다.
 *
 * ## 서버 계약 — `decoItem_fetch.php`
 *   - `?action=분류목록` → `[{code, 분류}, ...]`(code 오름차순)
 *   - `?action=상품목록&분류code=...` → `[{code, 상품명, 현금가, 카드가, 순서}, ...]`
 *     (순서 내림차순, 같은 순서면 상품명 오름차순 — 2026-09-12 추가 지시)
 *
 * ## 선택 시 — sender.action(callback이름, ...)
 * [지시 4] "좌측 aggrid 에서 선택하면 callback 이루어지게" — 좌측
 * 그리드의 "선택"(`onRowClicked`)이 실제로 하는 일은 우측 그리드
 * 재조회(지시 3)이고, 외부에 보고하는 `sender.action(callback, ...)`은
 * 이 위젯의 최종 산출물인 "상품 선택"에서만 의미가 있어(좌측 선택
 * 시점에 외부로 보고하면 여는 쪽이 곧바로 팝업을 닫아버릴 수 있어
 * 상품을 고르기도 전에 창이 닫히는 모순이 생긴다), **우측(상품) 그리드
 * 행 클릭에서만** `sender.action()`을 호출하는 기존 동작을 유지했다 —
 * 좌측 그리드의 "선택 콜백"은 ag-grid의 `onRowClicked`(그리드 자체의
 * 클릭 이벤트 콜백)를 의미하는 것으로 해석해 `_selectCategory()`를
 * 거기서 호출하도록 구현했다. 만약 좌측 분류 선택 시점에도 외부
 * `sender.action()` 보고가 필요하다면 알려주면 별도 콜백 이름(예:
 * `callback_상품_분류선택`)으로 추가하면 된다.
 *
 * 행을 1클릭하면 `onSelect(row)`(선택 사항, 로컬 콜백)를 부르고,
 * `sender`가 있고 `sender.action`이 함수이면
 *   sender.action(this.callback, row, window)
 * 도 함께 부른다(`row` = `{code, 상품명, 현금가, 카드가, 순서}`) — 이
 * 프로젝트의 `action(pcmd, pparams, psender)` 관례 그대로. [지시 8]
 * 콜백 이름 기본값은 `'callback_상품_선택'` — `Calendar`/`NumberKeypad`/
 * `ChosungGrid`와 동일하게 `options.callback`으로 여는 쪽이 직접
 * 지정할 수도 있다(md/2026-09-11-action-callback-이름부여-규칙.md 참고).
 *
 * **팝업을 닫는 것은 이 클래스의 책임이 아니다**(`ChosungGrid`/
 * `NumberKeypad`와 동일한 원칙) — `sender` 쪽이 콜백을 처리한 뒤
 * 스스로 닫아야 한다.
 *
 * ag-grid vendor 로딩(CSS 로드 대기 포함)/`ResizeObserver`+
 * `sizeColumnsToFit()`/가로 스크롤바 숨김은 `ChosungGrid.js`가 이미
 * 실전에서 겪고 고친 것과 완전히 동일한 방식을 그대로 재사용한다(같은
 * `src/js/aggrid/vendor/` 폴더, 두 번째 사본을 새로 안 받음).
 *
 * **`ChosungKeypad.js` 의존성** — `ag-grid`와 달리 `ChosungKeypad`는
 * 이 프로젝트의 다른 in-house 모듈이라 `decoItem.js`가 자동으로 불러오지
 * 않는다(`ChosungGrid.js`가 `ChosungKeypad.js`를 대하는 것과 동일한
 * 관례) — 이 클래스를 직접 쓰는 호스트 페이지가 `<script src=".../
 * ChosungKeypad/ChosungKeypad.js">`를 `decoItem.js`보다 먼저 로드해둬야
 * 한다(`decoItem_entry.php`가 그 예).
 *
 * ## 사용 예(화면에 직접 얹기)
 *   new DecoItem({
 *       containerId: 'dp-root',
 *       sender: window,
 *       onSelect: (row) => console.log('선택:', row),
 *   });
 *
 * ## DecoItem.open(options) — Popup+iframe으로 한 번에 열기
 *   DecoItem.open({
 *       sender: window,                    // 결과를 받을 창
 *       callback: 'callback_상품_선택',     // 생략 시 기본값 그대로
 *       ptitle: '상품선택',
 *   });
 * `ChosungGrid.open()`과 완전히 같은 형태 — 같은 폴더의
 * `decoItem_entry.php`를 iframe으로 연다.
 */
class DecoItem {
    /**
     * @param {{containerId: string, pageUrl?: string, fetchUrl?: string,
     *   sender?: Window, callback?: string, onSelect?: (row: object) => void}} options
     */
    constructor(options) {
        options = options || {};
        if (!options.containerId) {
            throw new Error('DecoItem: containerId는 필수입니다.');
        }
        this.containerId = options.containerId;
        this.pageUrl = options.pageUrl || DecoItem._defaultPageUrl();
        this.fetchUrl = options.fetchUrl || DecoItem._defaultFetchUrl();
        this.sender = options.sender || null;
        // [지시 8] "callback_상품_선택 이라는 용어사용" — 여는 쪽이
        // options.callback으로 다른 이름을 줄 수도 있다(다른 위젯과
        // 동일한 관례), 생략하면 이 이름 그대로.
        this.callback = typeof options.callback === 'string' && options.callback !== ''
            ? options.callback
            : 'callback_상품_선택';
        this.onSelect = typeof options.onSelect === 'function' ? options.onSelect : null;

        this.currentCategoryCode = null;
        // _fetchProducts()의 응답 도착 순서 뒤바뀜 방어용 요청 번호
        // (ChosungGrid.js의 _fetchToken과 동일한 이유).
        this._fetchToken = 0;
        // [2026-09-12 추가] "검색은 바,초성 모두가되게" — 서버가 보내준
        // 이번 분류의 전체 상품(필터 적용 전)을 들고 있다가, 검색어가
        // 바뀔 때마다 다시 서버를 부르지 않고 이 배열에서 직접 걸러
        // rowData를 다시 채운다(아래 "우측 검색 — 문자+초성 혼합 매칭"
        // 절 참고). `_searchText`는 분류를 바꿔도 유지된다.
        this._allProducts = [];
        this._searchText = '';
        // [2026-09-12 추가] "버튼식 키패드를 기대함" — 타이핑 검색바와는
        // 별도로 `ChosungKeypad`가 누적해 넘기는 문자열. 둘 다 비어있지
        // 않으면 AND로 함께 적용된다(`_applySearch()` 참고) — 보통은
        // 둘 중 하나만 쓰겠지만 굳이 막을 이유가 없어 자연스럽게 열어둠.
        this._chosungKeypadValue = '';
        // 분류 목록이 그리드보다 먼저 로딩을 끝냈을 때, 그리드가 아직
        // 없어(gridApi undefined) 못 반영한 상품 목록을 잠시 보관한다
        // (위 "분류 목록과 그리드의 로딩 순서 분리" 절 참고).
        this._pendingRows = null;

        DecoItem._injectDefaultStyles();

        const container = document.getElementById(this.containerId);
        if (!container) {
            throw new Error('DecoItem: containerId 요소를 찾을 수 없습니다: ' + this.containerId);
        }
        container.classList.add('dp-root');
        this.categoryGridDivId = this.containerId + '-categories';
        this.searchInputId = this.containerId + '-search';
        this.gridDivId = this.containerId + '-grid';
        this.keypadDivId = this.containerId + '-keypad';

        // [2026-09-12] decoItem.php는 이제 두 그리드의 빈 컨테이너 div만
        // 그리는 순수 마크업이라, 그리드를 만들려면 "마크업이 꽂혔음"과
        // "ag-grid 라이브러리가 로드됐음" 둘 다 끝나야 한다 — 어느 쪽이
        // 먼저 끝나든 항상 Promise.all로 합쳐서 기다린다(마크업이 먼저
        // 끝나도 라이브러리가 없으면 createGrid가 없고, 라이브러리가
        // 먼저 끝나도 아직 마크업이 안 꽂혔으면 그리드 div 자체가 없다).
        // `_loadMarkup()`은 실패해도 절대 reject하지 않고(컨테이너 자체에
        // 이미 실패 문구를 찍어둔 뒤) `false`로 resolve한다.
        Promise.all([this._loadMarkup(), DecoItem._ensureLibraryLoaded()])
            .then(([markupOk]) => {
                if (markupOk === false) {
                    return; // _loadMarkup()이 이미 컨테이너에 실패 사유를 표시했다.
                }
                this._initCategoryGrid();
                this._initGrid();
            })
            .catch((err) => {
                // ag-grid 라이브러리 로드 실패 — 마크업은 있을 수 있으니
                // (없으면 getElementById가 null이라 아무 일도 안 함) 두
                // 그리드 자리 각각에 실패 사유를 표시한다.
                [this.categoryGridDivId, this.gridDivId].forEach((id) => {
                    const el = document.getElementById(id);
                    if (el) {
                        el.textContent = 'AG Grid 로드 실패: ' + err.message;
                        el.classList.add('dp-error-text');
                    }
                });
            });
    }

    /** 지금 선택된 분류로 다시 조회한다(외부에서 강제 새로고침하고 싶을 때). */
    requery() {
        this._fetchProducts();
    }

    /** DOM/이벤트 정리 — 팝업을 닫을 때 등. */
    destroy() {
        if (this._resizeObserver) {
            this._resizeObserver.disconnect();
            this._resizeObserver = null;
        }
        if (this._categoryResizeObserver) {
            this._categoryResizeObserver.disconnect();
            this._categoryResizeObserver = null;
        }
        if (this.keypad) {
            this.keypad.destroy();
            this.keypad = null;
        }
        const container = document.getElementById(this.containerId);
        if (container) {
            container.innerHTML = '';
        }
    }

    // ------------------------------------------------------------------
    // 내부
    // ------------------------------------------------------------------

    /**
     * decoItem.php(두 그리드의 빈 컨테이너 div만 있는 순수 마크업)를
     * fetch해 컨테이너에 꽂는다. 실패해도 절대 reject하지 않고(호출부의
     * `Promise.all`이 계속 진행되도록) 컨테이너에 실패 사유를 직접 쓴
     * 뒤 `false`로 resolve한다 — 성공하면 `true`.
     */
    _loadMarkup() {
        const container = document.getElementById(this.containerId);
        const url = this.pageUrl + (this.pageUrl.indexOf('?') === -1 ? '?' : '&') +
            'containerId=' + encodeURIComponent(this.containerId);
        return fetch(url)
            .then((res) => {
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }
                return res.text();
            })
            .then((html) => {
                container.innerHTML = html;
                this._wireSearchInput();
                this._initKeypad();
                return true;
            })
            .catch((err) => {
                container.textContent = 'decoItem.php 로드 실패: ' + err.message;
                container.classList.add('dp-error-text');
                return false;
            });
    }

    /**
     * [지시 4의 검색바 + 2026-09-12 추가 "초성검색"] 서버 재조회 없이
     * 지금 로드된(=선택된 분류의) 상품 안에서만, 문자 그대로("바")와
     * 초성("ㅂ")을 섞어 써도 되는 자체 매칭(`DecoItem._chosungMatch()`)
     * 으로 필터링한다 — ag-grid 기본 `quickFilterText`(리터럴 부분
     * 일치만, 초성 개념이 없음)를 대체.
     */
    _wireSearchInput() {
        const searchEl = document.getElementById(this.searchInputId);
        if (searchEl) {
            searchEl.addEventListener('input', (e) => {
                this._searchText = e.target.value;
                this._applySearch();
            });
        }
    }

    /**
     * [2026-09-12 추가] "기존 ChosungKeypad처럼 누르는 버튼식 키패드를
     * 기대함" — 타이핑 검색바(`_wireSearchInput()`) 옆에, 클릭으로
     * 초성을 입력하는 `ChosungKeypad`를 얹는다(`ChosungGrid.js`가 이미
     * 하는 것과 동일한 조립 — 이 위젯은 그 모듈을 새로 안 만들고
     * 그대로 재사용). 호스트 페이지가 `ChosungKeypad.js`를
     * `decoItem.js`보다 먼저 `<script>`로 로드해둬야 한다(다른 in-house
     * 형제 모듈과 동일한 관례 — ag-grid처럼 자동으로 불러오지 않음,
     * `decoItem_entry.php` 참고).
     */
    /**
     * [2026-09-13 수정] "초성검색에 quit 버튼이 나타나게" — 이 위젯은
     * 항상 `DecoItem.open()`(Popup)으로 띄워지는 화면(고정 임베드가
     * 아님)이라, `ChosungKeypad.js` 자신의 docblock이 이미 명시한 조건
     * ("팝업으로 띄우는 화면에서만 showQuitButton:true로 켠다")에
     * 정확히 해당한다 — `false`였던 건 만들 당시의 누락. Quit을
     * 누르면 `ChosungGrid.js`의 `onQuit`(선택 취소를 `cancelled:true`로
     * 보고)와 동일한 방식으로 `sender.action(callback, {cancelled:true},
     * window)`를 부른다 — 팝업을 닫는 것은 항상 호출부 책임이라는 이
     * 위젯의 기존 원칙 그대로, 여기서 직접 `Popup.close()`는 안 부른다.
     */
    _initKeypad() {
        this.keypad = new ChosungKeypad({
            containerId: this.keypadDivId,
            showQuitButton: true,
            onInput: (value) => {
                this._chosungKeypadValue = value;
                this._applySearch();
            },
            onClear: () => {
                this._chosungKeypadValue = '';
                this._applySearch();
            },
            onQuit: () => {
                if (this.sender && typeof this.sender.action === 'function') {
                    this.sender.action(this.callback, { cancelled: true }, window);
                }
            },
        });
    }

    /**
     * `this._allProducts`에 타이핑 검색어(`_searchText`)와 키패드
     * 누적값(`_chosungKeypadValue`)을 순서대로 적용(둘 다 있으면 AND)
     * 한 결과로 우측 그리드를 다시 채운다. 둘 중 하나가 빈 문자열이면
     * `DecoItem._filterProducts()`가 그 단계에서 그대로 통과시킨다.
     */
    _applySearch() {
        let filtered = DecoItem._filterProducts(this._allProducts, this._searchText);
        filtered = DecoItem._filterProducts(filtered, this._chosungKeypadValue);
        if (this.gridApi) {
            this.gridApi.setGridOption('rowData', filtered);
        } else {
            this._pendingRows = filtered;
        }
    }

    /** [지시 2] "화면 좌측 aggrid(검색,편집없는)를 배치" — t상품분류 전체를 보여주는 좌측 그리드. */
    _initCategoryGrid() {
        this.categoryGridApi = agGrid.createGrid(document.getElementById(this.categoryGridDivId), {
            theme: 'legacy',
            columnDefs: [
                // [2026-09-17 수정 → 같은 날 재조정] "분류의 폰트는 현재
                // 2배로" → "분류라는 제목도 2배로, 분류내용은 지금보다
                // 80% 선으로 줄여줘" 지시 — 헤더("분류" 제목)는 별도 CSS
                // (`.dp-category-grid .ag-header-cell-text`, 아래 스타일
                // 블록 참고)로 28px(기본 14px의 2배), 셀 내용(분류명)은
                // 직전의 28px에서 80%인 22px로 줄였다.
                { field: '분류', headerName: '분류', flex: 1, cellStyle: { fontWeight: '700', color: '#3730a3', fontSize: '22px' } },
            ],
            rowData: [],
            rowSelection: 'single',
            defaultColDef: { resizable: false, sortable: false, filter: false, editable: false },
            suppressCellFocus: true,
            // [지시 4] "좌측 aggrid에서 선택하면" — ag-grid의 클릭 콜백
            // (onRowClicked)에서 우측 상품 그리드를 재조회한다(지시 3).
            onRowClicked: (event) => {
                if (event.data) {
                    this._selectCategory(event.data.code);
                }
            },
        });

        this._categoryResizeObserver = new ResizeObserver(() => {
            if (this.categoryGridApi) {
                this.categoryGridApi.sizeColumnsToFit();
            }
        });
        this._categoryResizeObserver.observe(document.getElementById(this.categoryGridDivId));

        this._loadCategories();
    }

    /** t상품분류 전체를 좌측 그리드에 채우고, 첫 분류를 자동 선택한다. */
    _loadCategories() {
        const url = this.fetchUrl + (this.fetchUrl.indexOf('?') === -1 ? '?' : '&') +
            'action=' + encodeURIComponent('분류목록');
        fetch(url)
            .then((res) => res.json().then((body) => ({ httpStatus: res.status, httpOk: res.ok, body })))
            .then(({ httpStatus, httpOk, body }) => {
                // [2026-09-12] decoItem_fetch.php가 SQL 실패 시
                // http_response_code(500) + {"error": ...}를 반환해도
                // res.json()만 보면 "성공"으로 오인한다 — res.ok와 error
                // 필드를 함께 확인해야 진짜 실패를 놓치지 않는다(이 은폐
                // 버그가 "지금도 안나옮"이 계속된 실제 원인이었다).
                if (!httpOk || (body && typeof body === 'object' && !Array.isArray(body) && body.error)) {
                    throw new Error(body && body.error ? body.error : 'HTTP ' + httpStatus);
                }
                const rows = Array.isArray(body) ? body : [];
                this.categoryGridApi.setGridOption('rowData', rows);
                if (rows.length > 0) {
                    this.categoryGridApi.forEachNode((node, index) => {
                        if (index === 0) {
                            node.setSelected(true);
                        }
                    });
                    this._selectCategory(rows[0].code);
                }
            })
            .catch((err) => {
                console.warn('DecoItem: 분류목록 조회 실패', err);
                const el = document.getElementById(this.categoryGridDivId);
                if (el) {
                    el.textContent = '분류 조회 실패: ' + err.message;
                    el.classList.add('dp-error-text');
                }
            });
    }

    _initGrid() {
        this.gridApi = agGrid.createGrid(document.getElementById(this.gridDivId), {
            // ChosungGrid.js/deco_amd_amd.php가 이미 확인해둔 이 vendor
            // 버전(v36.1.0)의 제약(CSS 변수 기반 커스터마이징이 실제로
            // 안 먹음) 때문에 theme:'legacy'를 그대로 재사용한다.
            theme: 'legacy',
            // [지시 5] 상품명/현금가/카드가 각각 색상 + 순서필드 표시.
            // 현금가=빨강(#d32f2f)/카드가=파랑(#1565c0)은 이 프로젝트의
            // deco 화면 전체가 이미 쓰는 배색(deco_amd_amd.php의
            // dal-red/dal-blue)과 통일했다.
            columnDefs: [
                { field: '순서', headerName: '순서', width: 64, cellStyle: { color: '#9ca3af', textAlign: 'right' } },
                // [2026-09-17 수정 — "aggrid 부분은 원래대로 복귀, 상품명은
                // 기존 사이즈보다 약간더 크게" → "금액도 상품명만하게
                // 키워줘" 지시로 최종 정리] 한때 분류/상품명/현금가/카드가
                // 전부 28px(2배)+행높이까지 키웠다가 전부 되돌리고,
                // 상품명/현금가/카드가는 기존 14px보다 살짝 큰 17px로,
                // 행높이는 원래 값 그대로 남겼다.
                { field: '상품명', headerName: '상품명', flex: 1, cellStyle: { color: '#1f2937', fontWeight: '700', fontSize: '17px' } },
                // [2026-09-17 수정] "그리드의 금액사이즈를 80% 선으로
                // 줄여줘" 지시 — 상품명과 맞췄던 17px에서 80%인 14px로.
                {
                    field: '현금가', headerName: '현금가', width: 110,
                    cellStyle: { color: '#d32f2f', fontWeight: '700', textAlign: 'right', fontSize: '14px' },
                    valueFormatter: DecoItem._formatNumber,
                },
                {
                    field: '카드가', headerName: '카드가', width: 110,
                    cellStyle: { color: '#1565c0', fontWeight: '700', textAlign: 'right', fontSize: '14px' },
                    valueFormatter: DecoItem._formatNumber,
                },
            ],
            rowData: [],
            defaultColDef: { resizable: false, sortable: false, filter: false, editable: false },
            suppressCellFocus: true,
            onRowClicked: (event) => this._onRowClicked(event.data),
        });

        // [ChosungGrid.js와 동일한 이유 — "팝업이 좁게 뜨다가 드래그해야
        // 바르게 나옴" 버그의 근본 해법] 컨테이너 크기가 바뀔 때마다
        // sizeColumnsToFit()을 다시 불러 flex 컬럼(상품명)이 항상 실측
        // 폭을 반영하게 한다.
        this._resizeObserver = new ResizeObserver(() => {
            if (this.gridApi) {
                this.gridApi.sizeColumnsToFit();
            }
        });
        this._resizeObserver.observe(document.getElementById(this.gridDivId));

        // [2026-09-12 추가] 분류 목록 조회가 그리드보다 먼저 끝나
        // _fetchProducts()가 gridApi 없이 결과를 _pendingRows에
        // 보관해뒀을 수 있다 — 그리드가 이제 막 준비됐으니 반영한다.
        if (this._pendingRows) {
            this.gridApi.setGridOption('rowData', this._pendingRows);
            this._pendingRows = null;
        }
    }

    /**
     * [지시 6] "분류를 클릭하면 해당 상품명 표시되게" — 선택 강조 자체는
     * ag-grid의 `rowSelection:'single'` 기본 동작(클릭한 행이 자동으로
     * 선택 표시됨, CSS는 `_injectDefaultStyles()`의 `.ag-row-selected`
     * 오버라이드로 이 프로젝트 배지 배색을 입힘)에 맡기고, 여기서는
     * 우측 그리드 재조회만 담당한다.
     */
    _selectCategory(code) {
        this.currentCategoryCode = code;
        this._fetchProducts();
    }

    _fetchProducts() {
        if (this.currentCategoryCode == null) {
            return;
        }
        const url = this.fetchUrl + (this.fetchUrl.indexOf('?') === -1 ? '?' : '&') +
            'action=' + encodeURIComponent('상품목록') +
            '&분류code=' + encodeURIComponent(this.currentCategoryCode);
        const token = ++this._fetchToken;
        fetch(url)
            .then((res) => res.json().then((body) => ({ httpStatus: res.status, httpOk: res.ok, body })))
            .then(({ httpStatus, httpOk, body }) => {
                if (token !== this._fetchToken) {
                    return; // 그 사이 더 최신 분류 클릭이 있었음 — 이 응답은 버린다.
                }
                // [2026-09-12] decoItem_fetch.php는 SQL 실패 시
                // http_response_code(500) + {"error": ...}를 반환하는데,
                // res.json()만 보면 이것도 "성공"으로 오인한다(좌측 분류
                // 목록이 겪었던 것과 동일한 함정) — res.ok와 error 필드를
                // 함께 확인해야 진짜 실패를 놓치지 않는다.
                if (!httpOk || (body && typeof body === 'object' && !Array.isArray(body) && body.error)) {
                    throw new Error(body && body.error ? body.error : 'HTTP ' + httpStatus);
                }
                // [2026-09-12] 분류를 바꿔도 검색어(this._searchText)는
                // 유지되므로, 서버가 준 전체 목록을 _allProducts에 저장해
                // 두고 그 위에 지금 검색어를 다시 적용한다 — _applySearch()
                // 가 그리드 미준비 시 _pendingRows 보관까지 함께 처리한다.
                this._allProducts = Array.isArray(body) ? body : [];
                this._applySearch();
            })
            .catch((err) => {
                console.warn('DecoItem: 상품목록 조회 실패', err);
                const el = document.getElementById(this.gridDivId);
                if (el && !this.gridApi) {
                    el.textContent = '상품 조회 실패: ' + err.message;
                    el.classList.add('dp-error-text');
                }
            });
    }

    _onRowClicked(row) {
        if (!row) {
            return;
        }
        if (this.onSelect) {
            this.onSelect(row);
        }
        if (this.sender && typeof this.sender.action === 'function') {
            this.sender.action(this.callback, row, window);
        }
    }

    /** 천단위 콤마 표시 — deco_main.php의 dmFormatNumber()와 동일한 정규식. */
    static _formatNumber(params) {
        const value = params.value;
        if (value === null || value === undefined || value === '') {
            return '';
        }
        return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // ------------------------------------------------------------------
    // 우측 검색 — 문자("바")+초성("ㅂ") 혼합 매칭 (2026-09-12 추가)
    // ------------------------------------------------------------------

    /**
     * `list`(서버가 준 이번 분류의 전체 상품)에서 상품명이 `query`로
     * **시작하는**(이 프로젝트 초성검색 전체의 관례 — 맨 앞부터 위치별
     * AND, 아래 `_chosungMatch()` 참고) 것만 남긴다. `query`가 비어
     * 있으면 그대로 다 돌려준다.
     */
    static _filterProducts(list, query) {
        const trimmed = (query || '').trim();
        if (trimmed === '') {
            return list;
        }
        return list.filter((row) => DecoItem._chosungMatch(String(row.상품명 == null ? '' : row.상품명), trimmed));
    }

    /**
     * `text`의 **맨 앞부터** `query`의 각 글자가 순서대로 일치하는지
     * 본다("N번째 글자가 이 문자로 시작하는가"를 AND로 엮는 — 이
     * 프로젝트의 초성검색 전체가 따르는 레거시 Access VBA 모델 그대로,
     * `ChosungKeypad.js`/`ChosungGrid_fetch.php`의
     * `SqlDialectMssql::chosungCondition($display, $i+1, $char)`가
     * 정확히 이 "위치 앵커" 방식이다).
     *
     * [2026-09-13 수정 — "초성검색이 옳바르게 안되는구만"] 처음엔
     * "문자열 어디에서든 연속으로 나오면 매칭"(예: "맥주"가 "주" 하나
     * 로도 걸림)으로 잘못 만들었는데, 이 프로젝트의 다른 모든 초성검색
     * 화면(`ChosungGrid`)은 전부 **맨 앞부터** 맞아 들어가야 하는
     * 모델이라(`chosungCondition()`이 위치 1,2,3...을 순서대로
     * 요구하는 것과 동일) 기대와 다르게 동작했다 — 앵커를 맨 앞
     * 하나로 고정해 이 프로젝트 관례에 맞췄다.
     *
     * 한 글자씩 비교할 때 `query`의 그 글자가 초성 자음(예: `ㅂ`)이면
     * "완성형 한글 음절이면서 그 초성이 일치"로, 그 외(보통 한글 음절
     * "바", 영문/숫자 등)면 "그 글자 자체가 일치"로 판정한다 — 그래서
     * 검색어 안에 문자와 초성을 섞어 써도(`서비스`+`ㅈ` 등) 그대로
     * 동작한다. `SqlDialectMssql::chosungCondition()`과 같은 유니코드
     * 산술을 쓰지만, 그 함수는 완성형 한글 음절 자체와의 일치는
     * 지원하지 않는 반면(그 자리에서 `1=0`) 이 함수는 지원한다 —
     * 지시("검색은 바,초성 모두가되게")가 그 확장을 요구했기 때문.
     */
    static _chosungMatch(text, query) {
        const textChars = Array.from(text);
        const queryChars = Array.from(query);
        if (queryChars.length === 0) {
            return true;
        }
        if (queryChars.length > textChars.length) {
            return false;
        }
        for (let i = 0; i < queryChars.length; i++) {
            if (!DecoItem._charMatches(textChars[i], queryChars[i])) {
                return false;
            }
        }
        return true;
    }

    /** `queryChar`가 초성 자음이면 `textChar`의 초성과 비교, 아니면 대소문자 무시하고 그대로 비교. */
    static _charMatches(textChar, queryChar) {
        if (DecoItem._CHOSUNG_LIST.indexOf(queryChar) !== -1) {
            return DecoItem._chosungOf(textChar) === queryChar;
        }
        return textChar.toLowerCase() === queryChar.toLowerCase();
    }

    /**
     * 완성형 한글 음절 한 글자의 초성을 돌려준다(겹자음 5개 포함 19종 —
     * `ChosungKeypad`의 14키 제약과 달리 이 검색창은 일반 텍스트
     * 입력이라 키보드로 `ㄲ`/`ㄸ`/`ㅃ`/`ㅆ`/`ㅉ`도 그대로 칠 수 있어
     * 전부 구분해준다). 완성형 한글 음절이 아니면 `null`.
     */
    static _chosungOf(char) {
        const code = char.codePointAt(0);
        if (code < 0xAC00 || code > 0xD7A3) {
            return null;
        }
        const chosungIndex = Math.floor((code - 0xAC00) / (21 * 28));
        return DecoItem._CHOSUNG_LIST[chosungIndex];
    }

    // ------------------------------------------------------------------
    // 정적 유틸 — Popup+iframe으로 열기 / ag-grid vendor 로딩 / 기본 스타일
    // ------------------------------------------------------------------

    /**
     * `Popup.js`의 iframeUrl로 이 화면을 띄우는 정적 헬퍼 —
     * `ChosungGrid.open()`과 완전히 같은 형태.
     *
     * @param {{pageUrl?: string, fetchUrl?: string, callback?: string, sender?: Window,
     *   ptitle?: string, id?: string, entryUrl?: string,
     *   iframeWidth?: (number|string), iframeHeight?: (number|string),
     *   modal?: boolean, draggable?: boolean, onClose?: (id: string) => void}} options
     * @return {Popup} 호출부가 나중에 직접 닫을 수 있도록 그 Popup 인스턴스를 그대로 반환.
     */
    static open(options) {
        options = options || {};
        if (typeof Popup === 'undefined') {
            throw new Error('DecoItem.open(): Popup.js가 먼저 로드돼 있어야 합니다.');
        }
        const entryUrl = options.entryUrl || DecoItem._defaultEntryUrl();
        const callbackName = typeof options.callback === 'string' && options.callback !== ''
            ? options.callback
            : 'callback_상품_선택';
        const query = 'pageUrl=' + encodeURIComponent(options.pageUrl || DecoItem._defaultPageUrl()) +
            '&fetchUrl=' + encodeURIComponent(options.fetchUrl || DecoItem._defaultFetchUrl()) +
            '&callback=' + encodeURIComponent(callbackName) +
            // ChosungGrid_entry.php와 동일한 이유 — 오래 떠 있는 부모
            // 문서에서 반복해서 열어도 항상 새 요청이 되도록 캐시 버스팅.
            '&_=' + Date.now();
        const iframeUrl = entryUrl + (entryUrl.indexOf('?') === -1 ? '?' : '&') + query;

        return new Popup({
            id: options.id,
            title: typeof options.ptitle === 'string' ? options.ptitle : '상품선택',
            modal: options.modal,
            draggable: options.draggable,
            iframeUrl: iframeUrl,
            // 좌측 분류열(140px) + 상품 그리드(4열, 상품명 flex) +
            // 2026-09-12 추가된 우측 ChosungKeypad(자기 폭 188px,
            // ChosungGrid.js가 이미 확인해둔 값) 실측을 어림잡은 값 —
            // 실제로 보고 과부족 있으면 이 두 숫자만 조정.
            iframeWidth: options.iframeWidth || 840,
            iframeHeight: options.iframeHeight || 520,
            onClose: options.onClose,
        });
    }

    /** DecoItem.js와 같은 폴더의 decoItem.php(마크업+분류 목록)를 기본 대상으로 삼는다. */
    static _defaultPageUrl() {
        return DecoItem._SCRIPT_DIR + 'decoItem.php';
    }

    /** DecoItem.js와 같은 폴더의 decoItem_fetch.php(상품 목록 JSON)를 기본 대상으로 삼는다. */
    static _defaultFetchUrl() {
        return DecoItem._SCRIPT_DIR + 'decoItem_fetch.php';
    }

    /** DecoItem.js와 같은 폴더의 decoItem_entry.php를 기본 대상으로 삼는다. */
    static _defaultEntryUrl() {
        return DecoItem._SCRIPT_DIR + 'decoItem_entry.php';
    }

    /**
     * ag-grid-community를 문서에 한 번만 주입하고 로드 완료를 기다리는
     * Promise를 캐시해 재사용한다 — `ChosungGrid.js::_ensureLibraryLoaded()`
     * 와 완전히 동일한 방식(CSS `<link>` 2개의 로드까지 기다린 뒤에만
     * `_initGrid()`가 실행되게 하는 것 포함, 그 파일 상단 docblock에
     * 적힌 "진짜 원인" 설명 참고). 이미 다른 화면이 `AGGrid.js`/
     * `ChosungGrid.js`를 통해 로드해뒀으면 `window.agGrid`가 이미 있어
     * 즉시 resolve — 같은 vendor 파일을 세 번째로 받지 않는다.
     */
    static _ensureLibraryLoaded() {
        if (window.agGrid) {
            return Promise.resolve();
        }
        if (!DecoItem._loadPromise) {
            DecoItem._loadPromise = new Promise((resolve, reject) => {
                const cssReadyPromises = [];
                if (!document.getElementById('aggrid-vendor-css')) {
                    const gridCss = document.createElement('link');
                    gridCss.id = 'aggrid-vendor-css';
                    gridCss.rel = 'stylesheet';
                    gridCss.href = DecoItem._AGGRID_VENDOR_DIR + 'ag-grid.css';
                    cssReadyPromises.push(new Promise((res) => {
                        gridCss.onload = res;
                        gridCss.onerror = res; // CSS 로드 실패해도 JS 초기화 자체는 막지 않는다.
                    }));
                    document.head.appendChild(gridCss);

                    const themeCss = document.createElement('link');
                    themeCss.rel = 'stylesheet';
                    themeCss.href = DecoItem._AGGRID_VENDOR_DIR + 'ag-theme-alpine.css';
                    cssReadyPromises.push(new Promise((res) => {
                        themeCss.onload = res;
                        themeCss.onerror = res;
                    }));
                    document.head.appendChild(themeCss);
                }
                const script = document.createElement('script');
                script.src = DecoItem._AGGRID_VENDOR_DIR + 'ag-grid-community.min.js';
                const scriptReady = new Promise((res, rej) => {
                    script.onload = () => res();
                    script.onerror = () => rej(new Error('ag-grid-community.min.js를 불러오지 못했습니다.'));
                });
                document.head.appendChild(script);

                Promise.all([scriptReady, ...cssReadyPromises]).then(resolve, reject);
            });
        }
        return DecoItem._loadPromise;
    }

    static _injectDefaultStyles() {
        if (document.getElementById('deco-item-default-styles')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'deco-item-default-styles';
        style.textContent = `
@layer deco-item-defaults {
    .dp-root {
        display: flex; gap: 12px; align-items: stretch; height: 100%;
        font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
    }
    .dp-category-col {
        flex: 0 0 140px; border-right: 1px solid #e5e7eb;
        display: flex; flex-direction: column;
    }
    .dp-category-grid { flex: 1; min-height: 150px; width: 100%; }
    /* [2026-09-17 추가 → 같은 날 재조정] "분류라는 제목도 2배로" →
       "분류제목크기는 분류내용과 동일하게" 지시 — 헤더 텍스트는 colDef의
       cellStyle(본문 셀 전용)이 안 먹혀 CSS로 직접 키운다(deco_amd_amd.php
       의 #dal-products-grid .ag-header-cell-text 규칙과 동일한 방식) —
       셀 내용(분류명, 아래 columnDefs의 cellStyle)과 같은 22px. */
    .dp-category-grid .ag-header-cell-text { font-size: 22px; }
    .dp-category-grid .ag-row { cursor: pointer; }
    .dp-category-grid .ag-row:hover { filter: invert(6%); }
    /* 바탕화면 매출/상품.png의 선택된 분류("양주") 표시 그대로 —
       검은 배경 + 노란 글자(이 프로젝트 배지 배색, #ffeb3b와 통일)로
       ag-grid의 기본 선택 강조색을 덮어쓴다. */
    .dp-category-grid .ag-row-selected { background: #000 !important; }
    .dp-category-grid .ag-row-selected .ag-cell { color: #ffeb3b !important; }
    .dp-category-grid .ag-body-horizontal-scroll { display: none !important; }
    /* 조회/로딩 실패 시 그리드 대신 이 문구를 그 자리에 바로 보여준다
       (좌/우 그리드 자리, 컨테이너 전체 어디든 재사용). */
    .dp-error-text { padding: 8px 10px; font-size: 0.82rem; color: #b91c1c; }
    /* [2026-09-12 추가] "초성검색이 우측에 표시안됨... 버튼식
       키패드를 기대함" — dp-main-col을 세로 스택(검색바+그리드)에서
       가로 배치(상품 열+키패드 열)로 바꾸고, 원래 dp-main-col이 하던
       역할(검색바+그리드 세로 스택)은 그 안의 dp-product-col이
       맡는다 — ChosungGrid.js의 .cg-grid-col+.cg-keypad-col과 같은
       형태(그리드 좌측, 키패드 우측). */
    .dp-main-col { flex: 1; min-width: 0; display: flex; flex-direction: row; gap: 12px; }
    .dp-product-col { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 8px; }
    .dp-keypad-col { flex: 0 0 auto; }
    .dp-search {
        padding: 6px 10px; font-size: 0.9rem; border: 1px solid #d1d5db;
        border-radius: 4px; box-sizing: border-box;
    }
    .dp-grid { flex: 1; min-height: 200px; width: 100%; }
    .dp-grid .ag-row { cursor: pointer; }
    .dp-grid .ag-row:hover { filter: invert(6%); }
    /* ChosungGrid.js가 이미 겪은 것과 동일한 이유 — 실제로는 안 넘치는데
       ag-grid가 컨테이너가 좁았던 시점에 한 번 "스크롤 필요"로 판단해둔
       채 안 풀리는 가로 스크롤바를 항상 숨긴다. */
    .dp-grid .ag-body-horizontal-scroll { display: none !important; }
}
        `;
        document.head.appendChild(style);
    }
}

/**
 * 이 스크립트 자신이 로드된 폴더의 절대경로 — `_defaultFetchUrl()`/
 * `_defaultEntryUrl()`이 같은 폴더의 파일을 항상 정확히 가리키게 한다.
 *
 * [2026-09-12 — `deco상품.js` 시절 겪은 버그의 교훈] 파일명이 한글이던
 * 시절엔 `src.replace(/deco상품\.js(\?.*)?$/, '')`처럼 파일명을 문자로
 * 매칭했다가, 브라우저가 `document.currentScript.src`의 한글을 항상
 * 퍼센트 인코딩(UTF-8 바이트)해서 돌려주는 바람에 이 정규식이 절대
 * 매칭되지 않아 ag-grid 로딩이 통째로 깨지는 버그를 겪었다 — 지금은
 * 영문 파일명(`decoItem.js`)으로 개명해 이 문제 자체가 없어졌지만,
 * 혹시 모를 재발을 막기 위해 계산 방식은 "파일명 문자 매칭"이 아니라
 * **마지막 `/`(쿼리스트링 이전)까지만 잘라 폴더를 구하는 방식**을 그대로
 * 유지한다(인코딩과 무관하게 항상 안전).
 */
DecoItem._SCRIPT_DIR = (function () {
    const src = document.currentScript && document.currentScript.src;
    if (!src) {
        return './';
    }
    const withoutQuery = src.split('?')[0];
    const lastSlash = withoutQuery.lastIndexOf('/');
    return lastSlash === -1 ? './' : src.substring(0, lastSlash + 1);
})();
DecoItem._AGGRID_VENDOR_DIR = DecoItem._SCRIPT_DIR + '../aggrid/vendor/';
DecoItem._loadPromise = null;

/**
 * 완성형 한글 음절의 19가지 초성을 유니코드 순서 그대로 나열한 것 —
 * `_chosungOf()`가 `Math.floor((code-0xAC00)/(21*28))`로 구한 인덱스
 * (0~18)를 이 배열에 그대로 대입한다. `SqlDialectMssql::chosungIndexMap()`
 * (서버 쪽, 겹자음 5개를 뺀 14개만 다룸 — `ChosungKeypad`의 실제 키
 * 구성에 맞춘 것)와 달리, 이 검색창은 일반 텍스트 입력이라 19개 전부
 * 구분한다.
 */
DecoItem._CHOSUNG_LIST = ['ㄱ', 'ㄲ', 'ㄴ', 'ㄷ', 'ㄸ', 'ㄹ', 'ㅁ', 'ㅂ', 'ㅃ', 'ㅅ', 'ㅆ', 'ㅇ', 'ㅈ', 'ㅉ', 'ㅊ', 'ㅋ', 'ㅌ', 'ㅍ', 'ㅎ'];
