/**
 * AGGrid — AG Grid(ag-grid-community, src/js/aggrid/vendor/에 로컬 저장된
 * 파일을 씀 — CDN 미사용)를 감싼 재사용 가능한 그리드 껍데기.
 * 호출부는 "테이블/필드정보 + fetch URL + 이벤트 규칙"만 주입하면 되고, 나머지
 * (툴바/조회/저장/삭제/검색/편집·읽기모드 전환/행선택/조건부서식/페이저)는
 * 이 클래스가 이미 갖추고 있다. `src/js/ScrollGrid.js`(직접 구현한 그리드)와
 * 같은 "껍데기는 한 곳, 화면은 config만 주입" 철학이지만, AGGrid는 AG Grid의
 * 내장 기능(정렬/필터/셀 편집기/컬럼 리사이즈 등)을 그대로 빌려 쓴다는 점이
 * 다르다 — 이 클래스가 새로 구현하는 건 "AG Grid를 웹 화면에 반복 없이
 * 붙이는 규칙"뿐이다.
 *
 * 전체 옵션/서버 계약/적용 가이드는 src/js/aggrid/aggrid.md 참고. 이 파일의
 * 주석은 "코드만 봐서는 알기 어려운 이유"만 남긴다(작업 이력은 md/Board_ai.md).
 *
 * 서버와 데이터를 주고받는 지점은 fetchUrl 하나뿐이다(md/Board_ai.md
 * "aggrid 모듈화" 논의에서 결정한 "select/save/delete를 한 페이지가 받는다"
 * 원칙):
 *  - GET(select) → 페이지네이션 모드('paged', 기본)면
 *    `{rows: [...], lastRow: number}`(AG Grid infinite row model 계약),
 *    전체조회 모드('all')면 `{rows: [...]}`.
 *  - POST(save/delete) → JSON body `{rows: [...], deletedCodes: [...]}` →
 *    `{results: [...], deleteResults: [...]}` — ScrollGrid의 bulkSaveUrl과
 *    **완전히 같은 계약**이라 서버는 ActionBulk::handle()을 그대로 재사용할
 *    수 있다(src/ActionBulk.php).
 *  - 네 요청 모두 쿼리스트링에 `action`(`'list_paged'`|`'list_all'`|
 *    `'save'`|`'delete'`)을 추가로 함께 보낸다(2026-08-25 추가) — GET/POST
 *    구분(읽기/쓰기)은 그대로 유지한 채, 그 안에서 정확히 어떤 요청인지를
 *    화면(fetch_<식별자>.php)이 값의 유무 대신 이름으로 읽고 분기할 수
 *    있게 하는 보조 신호. **순수 추가 정보**라 이 값을 안 읽는 기존
 *    화면(1_9/1_20의 fetch_회원.php)은 원래 하던 방식(GET의
 *    page/pageSize/sort/dir/q·mode='all', POST의 rows/deletedCodes
 *    유무)대로 여전히 정상 동작한다 — 서버가 강제로 이 값을 읽어야 하는
 *    건 아니다.
 *
 * 여러 그리드 식별 — 한 컨테이너 페이지에 그리드가 여러 개 있을 수 있어
 * (예: 회원/매출 나란히 배치), 생성 시 필수로 받는 `gridId`로 자기 자신을
 * `AGGrid.instances`에 등록한다. 외부(다른 그리드의 이벤트 핸들러든, 같은
 * 페이지의 다른 스크립트든)는 `AGGrid.instance('회원').requery()`처럼 특정
 * 인스턴스 하나만 콕 집어 갱신할 수 있다 — 그리드가 여럿이어도 서로 건드리지
 * 않는다.
 *
 * "행 추가"는 `gridApi.applyTransaction()`을 쓰지 않는다 — AG Grid의
 * Infinite Row Model(fetchMode:'paged')은 applyTransaction을 지원하지 않기
 * 때문(Client-Side/Server-Side Row Model 전용 기능). 대신 두 모드 모두에서
 * 동작하는 `pinnedTopRowData`(상단 고정 행, 행모델 종류와 무관하게 항상
 * 렌더링됨)에 신규 행 초안을 넣는다 — 저장 성공 후 비우고 requery()로
 * 서버의 실제 정렬 위치에 자연스럽게 반영한다.
 */

// 이 스크립트 자신이 실제로 로드된 URL을 기준으로 vendor/ 폴더 경로를
// 계산한다(하드코딩 대신) — document.currentScript는 <script> 태그가
// 동기적으로 실행되는 이 시점(모듈 최상단, 클래스 정의보다 먼저)에만
// 정확하므로 여기서 즉시 캡처해둔다. 이렇게 하면 이 프로젝트가 다른
// 경로/다른 프로젝트로 옮겨져도(APP_BASE_URL이 뭐든) AGGrid.js가 자기
// 위치를 스스로 찾아 vendor 파일을 정확히 참조한다(config/Path.php가
// 자기 위치를 스스로 찾는 것과 같은 원리를 JS 쪽에 적용).
const AGGRID_SCRIPT_DIR = (function () {
    const src = document.currentScript && document.currentScript.src;
    if (!src) {
        return './';
    }
    return src.replace(/AGGrid\.js(\?.*)?$/, '');
})();

class AGGrid {
    /**
     * @param {{gridId: string, containerId?: string, fetchUrl: string,
     *   primaryKey: string, columns: Array<Object>, fetchMode?: 'paged'|'all',
     *   pageSize?: number, height?: string, defaultMode?: 'view'|'edit',
     *   showGoToTool?: boolean, onRowDblClick?: (values: Object, key: string) => void,
     *   onReady?: (grid: AGGrid) => void,
     *   conditionalFormats?: Array<{when: (values: Object) => boolean,
     *     style: {bold?: boolean, color?: string, background?: string}}>}} options
     * 옵션별 상세 설명은 aggrid.md 참고.
     */
    constructor(options) {
        if (!options || !options.gridId) {
            throw new Error('AGGrid: gridId는 필수입니다.');
        }
        this.gridId = options.gridId;
        this.containerId = options.containerId || ('aggrid-' + this.gridId);
        this.fetchUrl = options.fetchUrl;
        this.primaryKey = options.primaryKey;
        this.injectedColumns = Array.isArray(options.columns) ? options.columns : [];
        this.fetchMode = options.fetchMode === 'all' ? 'all' : 'paged';
        this.pageSize = typeof options.pageSize === 'number' ? options.pageSize : 100;
        this.height = typeof options.height === 'string' ? options.height : '480px';
        // 그리드 영역을 화면(정확히는 가장 가까운 overflow-y:auto 조상)
        // 바닥까지 자동으로 채울지 — 컨테이너 페이지가 아니라 이 config
        // (생성자 옵션)가 결정한다. null(기본)이면 위 height 고정값을 쓰고,
        // 숫자를 주면 "그 조상의 바닥에서 이 값(px)만큼 띄운 높이"로 매번
        // 다시 계산한다(src/js/ScrollGrid.js의 autoFitBottomGap과 동일 원리).
        this.autoFitBottomGap = typeof options.autoFitBottomGap === 'number' ? options.autoFitBottomGap : null;
        this.minHeight = typeof options.minHeight === 'number' ? options.minHeight : 200;
        // 요청대로 기본은 읽기전용(view) — 편집은 툴바 버튼으로 명시적으로 켠다.
        this.mode = options.defaultMode === 'edit' ? 'edit' : 'view';
        this.showGoToTool = options.showGoToTool === true;
        // [2026-10-20 추가] "편집을 취소했습니다."/"저장 완료" 같은 안내
        // 문구는 기본형에서 안 보인다 — 필요한 화면만 true로 켠다. 실패/
        // 오류 문구는 이 옵션과 무관하게 항상 보인다(_setInfoStatus 참고).
        this.showInfoStatus = options.showInfoStatus === true;
        // [2026-08-26 추가] "+ 행 추가"는 기본은 edit 모드에서 표시지만,
        // 신규 레코드를 만들 일이 없는 화면(예: 아가씨단가/밴드단가 —
        // 행추가 제거 요청)은 이 옵션으로 꺼둘 수 있다.
        this.showAddRow = options.showAddRow !== false;
        // [2026-08-26 추가] 행 삭제도 마찬가지 — 기본은 항상 표시(기존
        // 동작 유지)지만, 레코드를 지울 일이 없는 화면(아가씨단가/밴드단가)
        // 은 이 옵션으로 편집모드의 "삭제" 컬럼 자체를 안 그리게 할 수 있다.
        this.showDeleteColumn = options.showDeleteColumn !== false;
        this.onRowDblClick = typeof options.onRowDblClick === 'function' ? options.onRowDblClick : null;
        this.conditionalFormats = Array.isArray(options.conditionalFormats) ? options.conditionalFormats : [];
        // 생성자는 AG Grid 라이브러리를 비동기로 로드한 뒤(_ensureLibraryLoaded의
        // Promise) 실제 DOM 구성(_init())을 한다 — 즉 `new AGGrid(...)` 바로
        // 다음 줄에서 툴바/그리드 DOM은 아직 없다(라이브러리가 이미 캐시돼
        // 있어도 .then()은 항상 마이크로태스크라 동기 실행 흐름보다 늦다).
        // 호출부가 툴바 DOM에 직접 손대야 하는 경우(예: 커스텀 버튼 삽입)를
        // 위해 DOM 구성이 끝난 시점에 부르는 콜백을 옵션으로 받는다.
        this.onReady = typeof options.onReady === 'function' ? options.onReady : null;
        // onReady와 달리 툴바가 (재)렌더될 때마다(최초 1회 + setMode()
        // 전환마다) 매번 호출된다 — 툴바 DOM에 직접 끼워 넣은 커스텀
        // 요소를 모드 전환 후에도 유지하고 싶은 화면이 쓴다(_renderToolbar() 참고).
        this.onToolbarRendered = typeof options.onToolbarRendered === 'function' ? options.onToolbarRendered : null;
        // [2026-08-26 추가] onReady는 그리드 DOM만 만들어진 시점(아직 행
        // 데이터가 없음)이라 컬럼 폭을 내용 기준(bestfit)으로 맞추기엔
        // 너무 이르다 — 첫 데이터 블록이 실제로 그려진 뒤에 불러야 하는
        // 콜백(예: gridApi.autoSizeAllColumns())을 위한 훅.
        this.onFirstDataRendered = typeof options.onFirstDataRendered === 'function' ? options.onFirstDataRendered : null;
        // [2026-10-20 추가] "+ 행 추가"로 만든 새 행에 미리 채울 값({필드: 값}).
        // 화면에 안 보이는 FK(예: 고객사 담당자의 parentcode)를 부모 키로
        // 자동으로 채울 때 쓴다 — 숨긴 컬럼은 사용자가 입력할 수 없으므로.
        this.newRowDefaults = options.newRowDefaults && typeof options.newRowDefaults === 'object' ? options.newRowDefaults : {};

        this.searchKeyword = '';
        // searchKeyword(자유 검색어) 외에 화면이 서버 요청에 추가로 실어
        // 보내고 싶은 값(예: 라디오버튼으로 고른 분류) — setExtraQuery()로
        // 채운다. 비워두면(기본값) 기존 화면과 동작이 완전히 같다.
        this.extraQuery = {};
        this.activeKey = null;
        // 기존 행 편집 → node.id로 추적(PK가 비어있는 신규 행과 달리 항상 안정적).
        this._dirtyNodeIds = new Set();
        // "+ 행 추가"로 만든 아직 저장 안 된 초안 — pinnedTopRowData로 렌더링됨.
        this._pendingNewRows = [];
        this._tempIdSeq = 0;
        this.gridApi = null;

        AGGrid.instances[this.gridId] = this;

        AGGrid._ensureLibraryLoaded()
            .then(() => this._init())
            .catch((err) => {
                const el = document.getElementById(this.containerId);
                if (el) {
                    el.innerHTML = '<p class="ag-shell-error">AG Grid 로드 실패: ' + this._escape(err.message) + '</p>';
                }
            });
    }

    /** [2026-10-20 추가] 그리드를 없앤다 — AG Grid 인스턴스를 정리하고
     * AGGrid.instances에서 뺀다. 팝업 안에 만든 그리드를 팝업이 닫힐 때
     * 치우는 용도(컨테이너 DOM 자체는 호출부가 지운다). 라이브러리 로드
     * 전에 불려도(gridApi 없음) 등록만 지운다. */
    destroy() {
        if (this.gridApi) {
            this.gridApi.destroy();
            this.gridApi = null;
        }
        if (AGGrid.instances[this.gridId] === this) {
            delete AGGrid.instances[this.gridId];
        }
    }

    /** gridId로 등록된 인스턴스를 찾는다(없으면 null). 외부에서 특정 그리드
     * 하나만 갱신/조작할 때 쓴다 — `AGGrid.instance('회원').requery()`. */
    static instance(gridId) {
        return AGGrid.instances[gridId] || null;
    }

    /** ag-grid-community CDN 스크립트/CSS를 문서에 한 번만 주입하고, 로드
     * 완료를 기다리는 Promise를 캐시해 재사용한다(페이지에 그리드가 여러 개
     * 있어도 CDN 요청/스크립트 실행은 한 번뿐). */
    static _ensureLibraryLoaded() {
        if (window.agGrid) {
            return Promise.resolve();
        }
        if (!AGGrid._loadPromise) {
            AGGrid._loadPromise = new Promise((resolve, reject) => {
                if (!document.getElementById('aggrid-vendor-css')) {
                    const gridCss = document.createElement('link');
                    gridCss.id = 'aggrid-vendor-css';
                    gridCss.rel = 'stylesheet';
                    gridCss.href = AGGRID_SCRIPT_DIR + 'vendor/ag-grid.css';
                    document.head.appendChild(gridCss);

                    const themeCss = document.createElement('link');
                    themeCss.rel = 'stylesheet';
                    themeCss.href = AGGRID_SCRIPT_DIR + 'vendor/ag-theme-alpine.css';
                    document.head.appendChild(themeCss);
                }
                const script = document.createElement('script');
                script.src = AGGRID_SCRIPT_DIR + 'vendor/ag-grid-community.min.js';
                script.onload = () => resolve();
                script.onerror = () => reject(new Error('vendor/ag-grid-community.min.js를 불러오지 못했습니다.'));
                document.head.appendChild(script);
            });
        }
        return AGGrid._loadPromise;
    }

    _init() {
        AGGrid._injectDefaultStyles();

        this.containerEl = document.getElementById(this.containerId);
        if (!this.containerEl) {
            throw new Error('컨테이너(#' + this.containerId + ')를 찾을 수 없습니다.');
        }

        this.toolbarId = this.containerId + '-toolbar';
        this.gridDivId = this.containerId + '-grid';
        // 초기 높이는 고정값으로 우선 잡아둔다 — autoFitBottomGap이 있으면
        // 아래 _fitHeight()가 실제 레이아웃(툴바 높이 포함)을 반영해 바로
        // 다시 계산해 덮어쓴다.
        this.containerEl.innerHTML =
            '<div id="' + this.toolbarId + '" class="ag-shell-toolbar"></div>' +
            '<div id="' + this.gridDivId + '" class="ag-theme-alpine ag-shell-grid" style="height:' + this._escape(this.height) + '"></div>';

        this._renderToolbar();
        this.gridApi = agGrid.createGrid(document.getElementById(this.gridDivId), this._buildGridOptions());

        if (this.fetchMode === 'all') {
            this._loadAll();
        }

        if (this.autoFitBottomGap !== null) {
            this._fitHeight();
            window.addEventListener('resize', () => this._fitHeight());
        }

        if (this.onReady) {
            this.onReady(this);
        }
    }

    /** autoFitBottomGap이 설정돼 있으면, 그리드 영역을 감싸는 가장 가까운
     * 스크롤 조상(overflow-y:auto/scroll)의 실제 바닥 기준으로 높이를 다시
     * 계산해 인라인 style에 반영한다(src/js/ScrollGrid.js의 _fitHeight()와
     * 동일 원리, 독립 구현 — Layout의 main.content처럼 그 자체가 스크롤
     * 영역인 조상을 자동으로 찾는다). 창 크기 조절 시에도 다시 계산된다. */
    _fitHeight() {
        if (this.autoFitBottomGap === null) {
            return;
        }
        const gridDiv = document.getElementById(this.gridDivId);
        if (!gridDiv) {
            return;
        }
        const container = this._scrollableAncestor(gridDiv);
        const containerBottom = container === document.documentElement
            ? window.innerHeight
            : container.getBoundingClientRect().bottom;
        const top = gridDiv.getBoundingClientRect().top;
        gridDiv.style.height = Math.max(this.minHeight, containerBottom - top - this.autoFitBottomGap) + 'px';
    }

    _scrollableAncestor(el) {
        let node = el.parentElement;
        while (node && node !== document.body && node !== document.documentElement) {
            const overflowY = window.getComputedStyle(node).overflowY;
            if (overflowY === 'auto' || overflowY === 'scroll') {
                return node;
            }
            node = node.parentElement;
        }
        return document.documentElement;
    }

    // ------------------------------------------------------------------
    // 툴바 — AGGrid가 스스로 그린다(호출부는 버튼 마크업을 안 둬도 된다).
    // 기본 읽기전용/이동도구 숨김/여백을 줄인 컴팩트 크기로 시작한다.
    // ------------------------------------------------------------------

    _renderToolbar() {
        const isEdit = this.mode === 'edit';
        const html = [];
        html.push('<div class="ag-shell-toolbar-left">');
        // 모드 전환 버튼은 맨 앞 — view에선 "편집", edit에선 "잠금"(다시
        // view로). 2026-10-20 변경: 원래 "+ 행 추가" 뒤에 있었고 edit 모드
        // 문구는 "읽기모드"였음.
        html.push('<button type="button" class="ag-shell-mode-btn">' + (isEdit ? '잠금' : '편집') + '</button>');
        // "+ 행 추가"는 edit 모드에서만 보인다 — 2026-10-20 변경: 원래 모드
        // 무관하게 항상 보였으나, 기본형(view)에서는 빼고 "편집"으로
        // 들어갔을 때만 나타나게 함. showAddRow:false를 주면(2026-08-26
        // 추가) edit 모드에서도 이 버튼 자체를 안 그린다.
        if (isEdit && this.showAddRow) {
            html.push('<button type="button" class="ag-shell-add-btn">+ 행 추가</button>');
        }
        if (isEdit) {
            // [2026-08-26 추가] DOM엔 항상 넣어두되(그래야 클릭 핸들러
            // 바인딩/버튼 개수 계산이 단순함), dirty가 없으면(막 편집모드로
            // 들어온 직후 등) _updateSaveButtonState()가 곧바로
            // display:none으로 감춘다 — "되돌릴 게 없는데 버튼만 떠
            // 있는" 상태를 기본값으로 방지.
            html.push('<button type="button" class="ag-shell-cancel-btn">편집취소</button>');
            html.push('<button type="button" class="ag-shell-save-btn">저장</button>');
            // 저장 관련 상태 문구("저장 중...", "저장할 변경 사항이
            // 없습니다" 등)는 저장 버튼 바로 옆에 보이는 게 자연스러워서
            // edit 모드에선 여기(왼쪽 그룹)에 둔다.
            html.push('<span class="ag-shell-status"></span>');
        }
        html.push('</div>');
        html.push('<div class="ag-shell-toolbar-right">');
        if (this.showGoToTool) {
            html.push('<input type="text" class="ag-shell-goto" placeholder="키값으로 이동">');
            html.push('<button type="button" class="ag-shell-goto-btn">이동</button>');
        }
        // 검색창을 감싸 오른쪽 안쪽에 "×"(지우기) 버튼을 겹쳐 배치한다 —
        // 입력값과 검색 상태를 비우고 곧바로 재조회한다(_bindToolbarEvents).
        html.push('<span class="ag-shell-search-wrap">');
        html.push('<input type="text" class="ag-shell-search" placeholder="검색어" value="' + this._escape(this.searchKeyword) + '">');
        html.push('<button type="button" class="ag-shell-search-clear-btn" title="검색어 지우기" aria-label="검색어 지우기">&times;</button>');
        html.push('</span>');
        html.push('<button type="button" class="ag-shell-search-btn">검색</button>');
        // view 모드(저장 버튼이 없음)에서는 "이동" 도구의 결과 메시지 등을
        // 보여줄 자리가 필요하므로 여기(오른쪽 그룹)에 둔다.
        if (!isEdit) {
            html.push('<span class="ag-shell-status"></span>');
        }
        html.push('</div>');

        const toolbarEl = document.getElementById(this.toolbarId);
        toolbarEl.innerHTML = html.join('');
        this._bindToolbarEvents(toolbarEl);
        this._updateSaveButtonState();
        // _renderToolbar()는 최초 1회(_init())뿐 아니라 setMode() 전환마다도
        // innerHTML을 통째로 새로 만든다 — 화면이 onReady로 툴바에 직접
        // 끼워 넣은 요소(예: 커스텀 필터)가 있다면 모드를 전환할 때마다
        // 사라진다. 그런 화면은 onReady 대신 이 콜백을 써서 렌더될 때마다
        // 다시 끼워 넣으면 된다(기존 화면은 옵션을 안 쓰면 영향 없음).
        if (this.onToolbarRendered) {
            this.onToolbarRendered(this);
        }
    }

    /** dirty(편집했지만 저장 안 한) 행이 하나라도 있으면 "저장" 버튼
     * 문구를 "저장 (클릭하셔야 저장됩니다.)"로 바꾸고 깜빡이게 해서
     * 눈에 띄게 한다. edit 모드가 아니거나 dirty가 없으면 원래 문구로
     * 되돌린다. 편집/추가/삭제/저장/새로고침 등 dirty 상태가 바뀔 수 있는
     * 모든 지점에서 호출한다. [2026-08-26 추가] 같은 지점에서 "편집취소"
     * 버튼의 표시 여부도 함께 갱신한다 — 되돌릴 dirty가 없으면(기본값)
     * 그 버튼 자체를 숨긴다("취소할 게 없는데 버튼만 떠 있는" 상태 방지). */
    _updateSaveButtonState() {
        if (!this.containerEl) {
            return;
        }
        const dirty = this._dirtyCount();

        const btn = this.containerEl.querySelector('.ag-shell-save-btn');
        if (btn) {
            if (dirty > 0) {
                // [2026-08-26 변경] 원래 dirty일 때 문구를 "저장 (클릭하셔야
                // 저장됩니다.)"로 길게 바꿨었는데, 깜빡임(ag-shell-save-btn-dirty)
                // 만으로 이미 "저장이 필요하다"는 신호가 충분하다는 지시로
                // 문구는 그냥 "저장"으로 되돌림(깜빡임은 그대로 유지).
                btn.textContent = '저장';
                btn.classList.add('ag-shell-save-btn-dirty');
            } else {
                btn.textContent = '저장';
                btn.classList.remove('ag-shell-save-btn-dirty');
            }
        }

        const cancelBtn = this.containerEl.querySelector('.ag-shell-cancel-btn');
        if (cancelBtn) {
            if (dirty > 0) {
                cancelBtn.style.display = '';
                cancelBtn.classList.add('ag-shell-cancel-btn-dirty');
            } else {
                cancelBtn.style.display = 'none';
                cancelBtn.classList.remove('ag-shell-cancel-btn-dirty');
            }
        }
    }

    /** 저장 안 된 행 수(기존 dirty 행 + 아직 저장 안 한 신규 행 초안).
     * 저장 버튼 문구/깜빡임과 "No" 컬럼의 연필 표시가 이 값을 근거로
     * 판단한다. */
    _dirtyCount() {
        let count = this._pendingNewRows.length;
        if (this.gridApi) {
            this.gridApi.forEachNode((node) => {
                if (node.rowPinned !== 'top' && this._dirtyNodeIds.has(node.id)) {
                    count++;
                }
            });
        }
        return count;
    }

    _bindToolbarEvents(toolbarEl) {
        toolbarEl.querySelector('.ag-shell-mode-btn').addEventListener('click', () => {
            if (this.mode !== 'edit') {
                this.setMode(true);
                return;
            }
            // "잠금" — 저장 안 한 편집이 있으면 편집취소도 함께 한다
            // (2026-10-20 추가). 모드를 먼저 바꿔 툴바를 다시 그린 뒤
            // 취소해야, showInfoStatus를 켠 화면에서 "편집을 취소했습니다."
            // 문구가 view 툴바에 남는다.
            const hasDirty = this._dirtyCount() > 0;
            this.setMode(false);
            if (hasDirty) {
                this.cancelEdits();
            }
        });

        // "+ 행 추가"는 view 모드이거나 showAddRow가 false면 애초에 안
        // 그려지므로(위 _renderToolbar() 참고) 버튼이 없을 수 있다 — null
        // 체크 필요. 그려졌다면 이미 edit 모드이므로 바로 addRow()만 실행한다.
        const addBtn = toolbarEl.querySelector('.ag-shell-add-btn');
        if (addBtn) {
            addBtn.addEventListener('click', () => {
                this.addRow();
            });
        }

        const searchInput = toolbarEl.querySelector('.ag-shell-search');
        const runSearch = () => {
            this.searchKeyword = searchInput.value;
            this.requery();
        };
        toolbarEl.querySelector('.ag-shell-search-btn').addEventListener('click', runSearch);
        searchInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.isComposing) {
                runSearch();
            }
        });
        // 포커스가 들어오면 이전 검색어를 지운다(입력창 표시만 — 아직
        // Enter/버튼을 안 눌렀으므로 서버 재조회는 안 하고 searchKeyword
        // 상태만 함께 비워, 빈 채로 바로 검색해도 전체 목록이 나오게 한다.
        searchInput.addEventListener('focus', () => {
            if (searchInput.value !== '') {
                searchInput.value = '';
                this.searchKeyword = '';
            }
        });
        // "×" 버튼은 focus와 달리 명시적인 클릭 동작이라 서버 재조회까지
        // 확실히 실행한다(runSearch 재사용 — 빈 키워드로 재조회하면
        // 전체 목록으로 돌아간다).
        toolbarEl.querySelector('.ag-shell-search-clear-btn').addEventListener('click', () => {
            searchInput.value = '';
            runSearch();
        });

        if (this.mode === 'edit') {
            toolbarEl.querySelector('.ag-shell-cancel-btn').addEventListener('click', () => this.cancelEdits());
            toolbarEl.querySelector('.ag-shell-save-btn').addEventListener('click', () => this.save());
        }

        if (this.showGoToTool) {
            const gotoInput = toolbarEl.querySelector('.ag-shell-goto');
            const runGoTo = () => {
                if (gotoInput.value !== '') {
                    this.selectRow(gotoInput.value);
                }
            };
            toolbarEl.querySelector('.ag-shell-goto-btn').addEventListener('click', runGoTo);
            gotoInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && !event.isComposing) {
                    runGoTo();
                }
            });
        }
    }

    _setStatus(message) {
        const el = this.containerEl && this.containerEl.querySelector('.ag-shell-status');
        if (el) {
            el.textContent = message || '';
        }
    }

    /** 안내(성공/진행) 문구 — showInfoStatus가 꺼져 있으면(기본형) 문구
     * 대신 빈 값으로 지운다. 그냥 무시하지 않고 지우는 이유는, 이전에
     * 떠 있던 "저장 실패" 같은 오류 문구가 재시도 성공 뒤에도 남아 있지
     * 않게 하기 위함. 실패/오류 문구는 이 함수가 아니라 _setStatus()로
     * 직접 띄운다. */
    _setInfoStatus(message) {
        this._setStatus(this.showInfoStatus ? message : '');
    }

    // ------------------------------------------------------------------
    // 컬럼/그리드 옵션 조립
    // ------------------------------------------------------------------

    /** 주입받은 컬럼 정의(거의 그대로 AG Grid columnDefs) 앞에 순번(No) 컬럼을,
     * edit 모드에서만 "삭제" 컬럼을 덧붙인다. `editable`은 원래 값과 무관하게
     * "그리고 지금 edit 모드인가"를 함께 체크하도록 감싼다 — 그래야 config가
     * `editable: true`처럼 정적으로 선언해도 읽기모드에서는 실제로 편집이
     * 안 된다(모드 판단을 컬럼마다 다시 안 적어도 되게).
     */
    _buildColumnDefs() {
        const noColumn = {
            headerName: 'No', width: 50, minWidth: 50, pinned: 'left',
            sortable: false, filter: false, editable: false,
            // bestfit(autoSizeAllColumns, 2026-08-26 기본값 변경)이 이
            // 고정 폭 컬럼까지 내용 기준으로 넓히지 않도록 제외한다.
            suppressAutoSize: true,
            cellClass: 'ag-shell-rownum-cell',
            // dirty(편집했지만 저장 안 함)한 행은 순번 대신 연필(✏️)을
            // 보여준다 — "저장이 필요한 행"을 행 번호 열만 봐도 바로 알 수
            // 있게. 신규 행(상단 고정)은 항상 dirty로 취급.
            valueGetter: (params) => {
                if (!params.node) {
                    return '';
                }
                const isDirty = params.node.rowPinned === 'top' || this._dirtyNodeIds.has(params.node.id);
                if (isDirty) {
                    return '✏️';
                }
                return params.node.rowIndex != null ? params.node.rowIndex + 1 : '';
            },
        };

        // [2026-08-26 추가] "메모"/memo 필드는 화면마다 따로 순서를
        // 안 맞춰도 항상 맨 뒤(다른 데이터 컬럼 전부보다 뒤, "삭제"
        // 컬럼보다는 앞)로 가도록 AGGrid 기본값에 편입 — 2_1(config_
        // 로그인.php)이 수동으로 하던 걸 모듈 레벨로 끌어올림. 나머지
        // 컬럼들의 상대 순서는 그대로 유지(안정 정렬).
        const orderedInjectedColumns = this._moveMemoFieldsToEnd(this.injectedColumns);

        const dataColumns = orderedInjectedColumns.map((col) => {
            const wrapped = Object.assign({}, col);
            const originalEditable = col.editable;
            wrapped.editable = (params) => {
                if (this.mode !== 'edit') {
                    return false;
                }
                return typeof originalEditable === 'function' ? originalEditable(params) : (originalEditable !== false);
            };
            // 숫자처럼 보이는 값은 기본으로 천단위 콤마를 붙인다(기본값 —
            // config가 별도 표시를 안 해도 적용됨). valueFormatter는 표시
            // 전용이라 실제 편집 입력값(셀 편집 시작 시 AG Grid가 원본
            // 값을 그대로 넣어줌)에는 영향이 없다 — ScrollGrid의
            // numberFormat처럼 "표시만 포맷, 저장값은 원본"이 자동으로
            // 보장된다. config가 이미 자기 valueFormatter를 지정했으면
            // 그걸 존중해 안 건드린다. `dataType: 'string'`(아래 참고)로
            // 문자열임이 확정된 컬럼은, 값이 우연히 숫자처럼 보여도(전화번호/
            // 우편번호 등) 절대 콤마를 붙이지 않는다 — 값 기준 추측
            // (_looksNumeric)의 오탐을 타입 정보로 원천 차단한다.
            if (typeof col.valueFormatter !== 'function' && col.dataType !== 'string') {
                wrapped.valueFormatter = (params) => AGGrid._formatNumberIfNumeric(params.value);
            }
            // 정렬 기본값(2026-08-26 추가, `dataType`은 이후 추가) — config가
            // 이미 자기 cellStyle을 지정했으면(함수든 정적 객체든) 그걸
            // 존중해 아래 어떤 기본값도 안 씌운다. 우선순위: ①config가
            // 명시한 `align`('left'/'center'/'right', AG Grid 정식 속성이
            // 아니라 이 모듈이 해석하는 간단 표기 — 정렬만 바꾸고 싶을 때
            // cellStyle 함수를 직접 안 써도 되게 하는 편의 옵션) > ②체크박스
            // 컬럼(cellRenderer가 agCheckboxCellRenderer)은 중앙 정렬 >
            // ③`dataType: 'string'|'number'`(스키마 등 외부에서 필드
            // 타입을 이미 알고 있을 때, config가 명시적으로 확정해 주는
            // 값 — 문자열은 좌측, 숫자는 우측으로 **행마다 흔들리지 않고
            // 항상 강제**됨) > ④타입 정보가 없을 때만, 값이 숫자처럼
            // 보이는지(천단위 콤마 자동 표시와 같은 판정, _looksNumeric)로
            // 우측 정렬을 추측한다. 이 값 기준 추측은 셀 단위 판정이라 같은
            // 컬럼이라도 행마다 다를 수 있고, 텍스트 필드에 숫자만 있는 값이
            // 섞이면 오탐할 수 있다 — 타입을 안다면 ③(`dataType`)을 쓰는
            // 편이 더 정확하다.
            if (col.cellStyle === undefined) {
                if (col.align === 'left' || col.align === 'center' || col.align === 'right') {
                    // textAlign만으로는 셀 내용이 flex 배치(체크박스
                    // 렌더러 등)일 때 안 먹을 수 있어 justifyContent도
                    // 함께 준다(둘 다 줘도 일반 텍스트 셀엔 해가 없음).
                    wrapped.cellStyle = { textAlign: col.align, justifyContent: col.align };
                } else if (col.cellRenderer === 'agCheckboxCellRenderer') {
                    // [2026-08-26 보정] textAlign만으로는 체크박스가 실제로
                    // 안 가운데로 안 갈 수 있어(agCheckboxCellRenderer는
                    // 내부적으로 flex 배치를 쓰는 경우가 있음) display:flex +
                    // justifyContent를 함께 강제한다.
                    wrapped.cellStyle = { textAlign: 'center', display: 'flex', justifyContent: 'center', alignItems: 'center' };
                } else if (col.dataType === 'string') {
                    wrapped.cellStyle = { textAlign: 'left' };
                } else if (col.dataType === 'number') {
                    wrapped.cellStyle = { textAlign: 'right' };
                } else {
                    wrapped.cellStyle = (params) => {
                        if (AGGrid._looksNumeric(params.value)) {
                            return { textAlign: 'right' };
                        }
                        return null;
                    };
                }
            }
            // `align`/`dataType`은 AG Grid의 정식 colDef 속성이 아니라 이
            // 모듈만 해석하는 편의 표기라, 그대로 두면 불필요한 값이
            // 남으므로 소비한 뒤 지운다.
            delete wrapped.align;
            delete wrapped.dataType;
            return wrapped;
        });

        const columns = [noColumn].concat(dataColumns);

        if (this.mode === 'edit' && this.showDeleteColumn) {
            columns.push({
                headerName: '삭제', colId: 'delete', width: 70, minWidth: 70,
                sortable: false, filter: false, editable: false,
                suppressAutoSize: true,
                cellRenderer: (params) => params.data ? '<button type="button" class="ag-shell-row-delete-btn">삭제</button>' : '',
            });
        }

        return columns;
    }

    /** "메모"/memo(대소문자 무관) 필드를 배열 맨 뒤로 보낸다 — 그 외 컬럼의
     * 상대 순서는 그대로 유지(안정 정렬). config가 이미 메모를 맨 뒤에
     * 뒀어도(예: 2_1) 결과가 똑같아 이중으로 손해볼 일은 없다. */
    _moveMemoFieldsToEnd(columns) {
        const normal = [];
        const memoLike = [];
        columns.forEach((col) => {
            if (this._isMemoField(col.field)) {
                memoLike.push(col);
            } else {
                normal.push(col);
            }
        });
        return normal.concat(memoLike);
    }

    _isMemoField(field) {
        if (typeof field !== 'string') {
            return false;
        }
        if (field === '메모') {
            return true;
        }
        return field.toLowerCase() === 'memo';
    }

    _buildGridOptions() {
        const gridOptions = {
            columnDefs: this._buildColumnDefs(),
            // [2026-08-26 변경] 이전 기본값은 flex:1(모든 컬럼을 화면 폭에
            // 맞춰 고르게 늘림)이었다 — "2_x 전부 bestfit(내용 기준 너비)으로
            // 바꾸고 그걸 AGGrid 기본값에 포함시켜달라"는 지시로, flex를
            // 아예 빼고(컬럼마다 개별로 flex:null을 줄 필요가 없어짐) 아래
            // onFirstDataRendered에서 항상 autoSizeAllColumns()를 부르는
            // 쪽으로 기본 동작 자체를 바꿨다 — 1_9/1_20/2_1/2_2/2_5 등
            // 기존 화면 전부에도 그대로 적용된다(화면 코드는 안 건드림,
            // 이 공용 모듈 하나만 고치면 전체에 반영되는 게 baseline의 취지).
            defaultColDef: { minWidth: 90, resizable: true, sortable: true },
            animateRows: true,
            getRowStyle: (params) => this._resolveRowStyle(params),
            onCellValueChanged: (params) => this._onCellValueChanged(params),
            onCellClicked: (params) => this._onCellClicked(params),
            onRowDoubleClicked: (params) => this._onRowDoubleClicked(params),
        };

        gridOptions.onFirstDataRendered = () => {
            this.gridApi.autoSizeAllColumns();
            if (this.onFirstDataRendered) {
                this.onFirstDataRendered(this);
            }
        };

        // primaryKey가 있는 테이블만 그 값으로 행 식별자(getRowId)를 만든다.
        // primaryKey가 없으면(this.primaryKey가 null/빈 문자열) _keyOf()가
        // 모든 행에 대해 항상 ''를 반환해 모든 행의 id가 '_new_undefined'로
        // 겹치고, AG Grid가 같은 id의 행을 하나로 취급해 화면에 첫 행 말고는
        // 아무것도 안 보이는 문제가 있었다 — getRowId 자체를 아예 안 주면
        // AG Grid가 내부 기본 식별자를 쓰므로 이 문제가 생기지 않는다
        // (다만 PK 없는 테이블은 어차피 Crud::update()가 예외를 던지므로
        // 편집/저장은 여전히 불가능 — 조회 전용으로만 정상 동작한다).
        if (this.primaryKey) {
            gridOptions.getRowId = (params) => this._keyOf(params.data) || ('_new_' + (params.data._tempId != null ? params.data._tempId : ''));
        }

        if (this.fetchMode === 'all') {
            gridOptions.rowModelType = 'clientSide';
            gridOptions.rowData = [];
        } else {
            gridOptions.rowModelType = 'infinite';
            gridOptions.cacheBlockSize = this.pageSize;
            gridOptions.paginationPageSize = this.pageSize;
            gridOptions.maxBlocksInCache = 10;
            gridOptions.pagination = true;
            gridOptions.datasource = this._createDatasource();
        }

        return gridOptions;
    }

    // ------------------------------------------------------------------
    // 조회 — paged(infinite row model 데이터소스) / all(한 번에 전체) 두 모드.
    // 둘 다 같은 fetchUrl(GET)을 쓰되 쿼리 파라미터만 다르다. page/pageSize/
    // sort/dir/q는 Board::init()이 이미 $_GET에서 읽는 이름 그대로라
    // (src/Board.php resolveIntParam/resolveOrderBy/resolveCondition 참고)
    // 서버는 Board를 그대로 쓰면 되고 별도 파싱 코드가 필요 없다.
    //
    // 2026-08-25 추가 — 네 요청(list_paged/list_all/save/delete) 각각에
    // `action` 쿼리 파라미터를 명시적으로 함께 보낸다(GET/POST 구분은
    // 그대로 유지, action은 그 안에서 "정확히 무슨 요청인지"를 이름으로
    // 드러내는 보조 신호). 서버가 그 이름을 실제로 읽어서 분기하느냐는
    // 화면(fetch_<식별자>.php)의 선택이다 — 기존 화면(1_9/1_20의
    // fetch_회원.php)은 이 값을 안 읽어도 원래대로 정상 동작한다(GET의
    // page/pageSize/sort/dir/q, mode='all', POST의 rows/deletedCodes
    // 유무로 여전히 판단 가능) — 순수 추가 정보이지 기존 계약을 대체하지
    // 않는다.
    // ------------------------------------------------------------------

    _createDatasource() {
        return {
            getRows: (params) => {
                const page = Math.floor(params.startRow / this.pageSize) + 1;
                const query = Object.assign(
                    { action: 'list_paged', page: page, pageSize: this.pageSize, q: this.searchKeyword },
                    this.extraQuery
                );
                const sortEntry = params.sortModel && params.sortModel[0];
                if (sortEntry) {
                    query.sort = sortEntry.colId;
                    query.dir = sortEntry.sort;
                }
                fetch(this._fetchUrlWith(AGGrid._toQueryString(query)), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then((res) => res.json())
                    .then((data) => {
                        params.successCallback(data.rows || [], typeof data.lastRow === 'number' ? data.lastRow : -1);
                    })
                    .catch(() => params.failCallback());
            },
        };
    }

    _loadAll() {
        const query = Object.assign({ action: 'list_all', mode: 'all', q: this.searchKeyword }, this.extraQuery);
        return fetch(this._fetchUrlWith(AGGrid._toQueryString(query)), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then((res) => res.json())
            .then((data) => {
                this.gridApi.setGridOption('rowData', data.rows || []);
            })
            .catch(() => {
                this._setStatus('목록을 불러오지 못했습니다.');
            });
    }

    /** searchKeyword(자유 검색어) 외에 서버 요청에 실어 보낼 추가 값을
     * 설정하고 즉시 재조회한다 — 예: 라디오버튼으로 고른 분류를
     * `AGGrid.instance('직원').setExtraQuery({type: '팀장'})`처럼 넘기면
     * fetch URL에 `&type=팀장`이 실린다(서버가 그 값을 어떻게 해석할지는
     * 화면별 fetch 엔드포인트의 몫 — 이 클래스는 값을 그대로 전달만 한다).
     * `{}`를 넘기면 필터 해제(전체) 상태로 돌아간다. */
    setExtraQuery(extraQuery) {
        //alert (extraQuery);
        this.extraQuery = extraQuery || {};
        return this.requery();
    }

    /** 목록을 (다시) 불러온다 — 검색 확정 시에도 내부적으로 호출됨. 외부에서도
     * 호출 가능(`AGGrid.instance('회원').requery()`) — 이게 "특정 그리드만
     * 갱신, 나머지는 그대로"를 만족시키는 지점이다(다른 인스턴스는 아예
     * 안 건드리므로). */
    requery() {
        this._dirtyNodeIds.clear();
        this._pendingNewRows = [];
        if (this.gridApi) {
            this.gridApi.setGridOption('pinnedTopRowData', []);
        }
        this._updateSaveButtonState();
        if (this.fetchMode === 'all') {
            return this._loadAll();
        }
        this.gridApi.setGridOption('datasource', this._createDatasource());
        return Promise.resolve();
    }

    // ------------------------------------------------------------------
    // 편집 모드 전환 / 행 선택(외부에서도 가능) / 조건부 서식·선택·dirty 병합
    // ------------------------------------------------------------------

    /** edit ↔ view 전환. 컬럼 정의(삭제 컬럼 유무)가 모드에 따라 달라지므로
     * columnDefs를 다시 세팅하고, 툴바도 다시 그린다. */
    setMode(isEdit) {
        this.mode = isEdit ? 'edit' : 'view';
        this._renderToolbar();
        if (this.gridApi) {
            this.gridApi.setGridOption('columnDefs', this._buildColumnDefs());
        }
    }

    /** key(PK 값)에 해당하는 행을 "현재 행"으로 표시(색 반전)하고 화면에
     * 보이게 스크롤한다. 클릭으로도, 외부 코드에서도 호출 가능 —
     * `AGGrid.instance('회원').selectRow('8')`. null이면 선택 해제. 현재
     * 로드돼 있는(paged면 캐시에 있는) 행 중에서만 찾는다. */
    selectRow(key) {
        this.activeKey = key == null ? null : String(key);
        if (!this.gridApi) {
            return;
        }
        if (this.activeKey !== null) {
            let found = false;
            this.gridApi.forEachNode((node) => {
                if (!found && node.data && this._keyOf(node.data) === this.activeKey) {
                    found = true;
                    this.gridApi.ensureIndexVisible(node.rowIndex, 'middle');
                }
            });
            if (!found) {
                this._setStatus('키 "' + this.activeKey + '"에 해당하는 행을 현재 로드된 목록에서 찾을 수 없습니다.');
            } else {
                this._setStatus('');
            }
        }
        this.gridApi.redrawRows();
    }

    _keyOf(data) {
        if (!data || data[this.primaryKey] == null) {
            return '';
        }
        return String(data[this.primaryKey]);
    }

    /** 우선순위(높은 것이 낮은 것을 덮어씀): 편집했지만 저장 안 한(dirty) 행 >
     * 선택된(active) 행 > 조건부 서식(conditionalFormats, 첫 매칭 규칙만).
     * dirty/선택이 "지금 사용자가 다루고 있다"는 임시 신호라 업무 규칙에
     * 해당하는 조건부 서식보다 눈에 먼저 띄어야 한다고 판단했다(원본
     * 데모의 rowClassRules 선언 순서 의도를 그대로 따름). */
    _resolveRowStyle(params) {
        if (!params.data) {
            return undefined;
        }
        const nodeId = params.node.id;
        if (this._dirtyNodeIds.has(nodeId) || params.node.rowPinned === 'top') {
            return { backgroundColor: '#fef3c7' };
        }
        if (this.activeKey !== null && this._keyOf(params.data) === this.activeKey) {
            return { backgroundColor: '#cfe3fb' };
        }
        for (let i = 0; i < this.conditionalFormats.length; i++) {
            const rule = this.conditionalFormats[i];
            if (typeof rule.when !== 'function') {
                continue;
            }
            let matched = false;
            try {
                matched = !!rule.when(params.data);
            } catch (err) {
                matched = false;
            }
            if (matched && rule.style) {
                const style = {};
                if (rule.style.background) { style.backgroundColor = rule.style.background; }
                if (rule.style.color) { style.color = rule.style.color; }
                if (rule.style.bold) { style.fontWeight = 'bold'; }
                return style;
            }
        }
        return undefined;
    }

    _onCellValueChanged(params) {
        if (params.node.rowPinned === 'top') {
            // pinnedTopRowData는 이미 _pendingNewRows의 같은 객체 참조라
            // AG Grid가 직접 값을 반영해준다 — 별도 동기화 불필요.
            this.gridApi.redrawRows({ rowNodes: [params.node] });
            this._updateSaveButtonState();
            return;
        }
        this._dirtyNodeIds.add(params.node.id);
        this.gridApi.redrawRows({ rowNodes: [params.node] });
        // "No" 컬럼(연필 표시)도 이 행 안에 있으므로 위 redrawRows()로
        // 함께 다시 그려진다 — valueGetter가 dirty 여부를 매번 새로
        // 판단하기 때문에 별도 처리가 필요 없다.
        this._updateSaveButtonState();
    }

    /** 행 클릭 시 활성 강조를 "즉시" 보여주되(느리다는 피드백), 클릭된
     * 그 행은 AG Grid의 redrawRows()를 절대 거치지 않는다 — redraw는 셀
     * DOM을 통째로 다시 만들어서, 더블클릭의 두 번째 클릭이 원래 셀에
     * 도달하지 못해 "클릭 지점에 커서 위치" 편집이 깨지는 버그가 실제로
     * 있었다(이전 수정에서 300ms 지연으로 피해갔지만, 그러면 느리다는
     * 피드백이 다시 나옴). 그래서 지금은: (1) 새로 클릭한 행은 클릭
     * 이벤트가 이미 갖고 있는 실제 DOM 요소에 강조 클래스를 직접
     * 붙여서(redraw 없이) 즉시 반영하고, (2) 예전에 활성이던 행은 지금
     * 클릭 대상이 아니므로 안전하게 redrawRows()로 강조를 지운다(그
     * 행에서 더블클릭이 동시에 일어날 수 없으므로 안전). 이후 정렬/검색/
     * 새로고침 등 어떤 이유로든 이 행이 다시 그려지면 getRowStyle()이
     * activeKey를 보고 항상 정확한 색을 다시 매기므로, 이 수동 클래스는
     * "다음 정식 렌더까지의 임시 표시"일 뿐이다. */
    /** 활성 행 강조를 즉시 반영하되, 원래 버그(매 클릭마다 그리드
     * *전체*를 redrawRows()하던 것 — 그래서 더블클릭 두 번째 클릭이
     * 도중에 다시 그려진 셀에 못 도달해 편집이 깨졌었다)와 달리, 이번엔
     * 딱 두 행(이전 활성 행 + 새로 클릭한 행)만 범위를 좁혀 redraw한다.
     * DOM을 직접 조작하는 시도(클래스 수동 부착)는 AG Grid의 실제 행
     * DOM 구조에 대한 가정이 틀려 강조가 아예 안 뜨는 회귀를 냈던 적이
     * 있어 폐기 — `redrawRows({rowNodes})`라는 검증된 공식 API로
     * 단순화했다. */
    _onCellClicked(params) {
        if (!params.data) {
            return;
        }
        if (params.colDef && params.colDef.colId === 'delete') {
            this._deleteRow(params.node);
            return;
        }
        // [2026-08-26 추가] 체크박스 컬럼은 AG Grid 기본값대로면 더블클릭
        // 해야 편집(=토글) 모드로 들어가는데, 체크박스는 관례상 "한 번
        // 클릭하면 바로 토글"이 자연스럽다 — 클릭 한 번에 바로
        // startEditingCell()을 불러 즉시 토글시킨다(체크박스 에디터는
        // 값을 뒤집고 스스로 편집을 끝내므로 별도 커밋 처리가 필요 없고,
        // 뒤이어 onCellValueChanged가 그대로 호출돼 dirty 처리로 이어짐 —
        // "체크박스를 클릭하면 바로 dirty 상태가 되게" 요청을 이걸로 충족).
        // 새로 추가한 행(pinned top)도 대상에 포함한다.
        if (this._toggleCheckboxIfApplicable(params)) {
            return;
        }
        if (params.node.rowPinned === 'top') {
            return;
        }
        const key = this._keyOf(params.data);
        if (key === '' || key === this.activeKey) {
            return;
        }
        const previousKey = this.activeKey;
        this.activeKey = key;

        const rowNodesToRedraw = [params.node];
        if (previousKey !== null && this.gridApi) {
            this.gridApi.forEachNode((node) => {
                if (node.data && this._keyOf(node.data) === previousKey) {
                    rowNodesToRedraw.push(node);
                }
            });
        }
        if (this.gridApi) {
            this.gridApi.redrawRows({ rowNodes: rowNodesToRedraw });
        }
    }

    /** 클릭한 셀이 편집 가능한 체크박스 컬럼이면 즉시 편집을 시작해(=바로
     * 토글) true를 반환한다. 그 외(체크박스가 아니거나, view 모드거나,
     * 그 행/컬럼이 편집 불가)는 아무것도 안 하고 false. */
    _toggleCheckboxIfApplicable(params) {
        if (this.mode !== 'edit') {
            return false;
        }
        const colDef = params.colDef;
        if (!colDef || colDef.cellEditor !== 'agCheckboxCellEditor') {
            return false;
        }
        if (typeof colDef.editable === 'function' && !colDef.editable(params)) {
            return false;
        }
        if (colDef.editable === false) {
            return false;
        }
        params.api.startEditingCell({
            rowIndex: params.rowIndex,
            colKey: params.column.getColId(),
            rowPinned: params.node.rowPinned,
        });
        return true;
    }

    _onRowDoubleClicked(params) {
        // 더블클릭(=상세 열기 등 외부 이벤트 규칙)은 편집 중 오발동을 막기
        // 위해 view 모드에서만 호출한다 — edit 모드에서 더블클릭은 AG Grid
        // 자신의 "셀 편집 시작" 제스처와 자리가 겹친다.
        if (this.mode !== 'view' || !this.onRowDblClick || !params.data || params.node.rowPinned === 'top') {
            return;
        }
        this.onRowDblClick(params.data, this._keyOf(params.data));
    }

    // ------------------------------------------------------------------
    // 행 추가 / 저장(일괄) / 삭제(즉시)
    // ------------------------------------------------------------------

    /** 빈 신규 행을 pinnedTopRowData(상단 고정)에 추가한다 — infinite row
     * model은 applyTransaction을 지원하지 않아 두 fetchMode 모두에서
     * 동작하는 이 방식을 쓴다(클래스 상단 docblock 참고). */
    addRow() {
        const draft = { _tempId: ++this._tempIdSeq };
        this.injectedColumns.forEach((col) => {
            if (col.field) {
                draft[col.field] = '';
            }
        });
        Object.assign(draft, this.newRowDefaults);
        this._pendingNewRows.push(draft);
        this.gridApi.setGridOption('pinnedTopRowData', this._pendingNewRows.slice());
        this._updateSaveButtonState();
    }

    /** dirty 행(편집한 기존 행 + 아직 저장 안 한 신규 행 초안) 전부를
     * 서버로 보내지 않고 되돌린다 — 편집 중인 값을 지운(값 자체는 이미
     * node.data에 반영돼 있어 dirty 플래그만 지워선 안 됨) 뒤 서버 원본을
     * 다시 받아오는 `requery()`를 그대로 재사용한다(가장 확실하게 "원래
     * 값으로 되돌아감"을 보장하는 방법 — 별도 스냅샷/undo를 관리하지
     * 않아도 된다). 취소할 게 없으면(dirty 0건) 상태 문구만 알리고 끝,
     * 있으면 확인창 없이 곧바로 실행한다(요청에 따라 확인 절차 없음). */
    cancelEdits() {
        if (this._dirtyCount() === 0) {
            this._setInfoStatus('취소할 편집 내용이 없습니다.');
            return Promise.resolve();
        }
        if (this.gridApi) {
            // 취소이므로 열려있는 편집기는 확정(commit)이 아니라 폐기한다.
            this.gridApi.stopEditing(true);
        }
        this._setInfoStatus('편집을 취소했습니다.');
        return this.requery();
    }

    /** 편집한(dirty) 기존 행 + 아직 저장 안 한 신규 행(pinnedTopRowData)을
     * 한 번에 fetchUrl(POST)로 보낸다. ActionBulk::handle()과 계약이
     * 같다 — `rows`는 보낸 순서 그대로 `results`와 인덱스 1:1 대응. 하나라도
     * 실패하면 전체를 그대로 두고(성공분까지 requery()로 날리지 않음)
     * 실패 메시지만 알린다 — 사용자가 실패한 값을 보고 고쳐 재시도할 수
     * 있게 하기 위함. */
    save() {
        // AG Grid는 셀 편집이 "끝나야"(포커스 이동/Enter/Tab 등) 실제
        // 데이터에 반영되고 onCellValueChanged가 발생한다 — 타이핑 직후
        // 바로 저장을 누르면 아직 편집기가 열려있는 상태라 그 값이 아직
        // row 데이터에 안 실려 dirty로 안 잡히는(그래서 "저장할 게 없다"고
        // 나오는) 문제가 있었다. stopEditing()(취소가 아니라 확정)을 먼저
        // 호출해 진행 중인 편집을 커밋시키면, 그 즉시 onCellValueChanged가
        // 발생해 dirty 처리까지 끝난 뒤 아래에서 수집된다.
        if (this.gridApi) {
            this.gridApi.stopEditing();
        }
        const rows = [];
        this.gridApi.forEachNode((node) => {
            if (this._dirtyNodeIds.has(node.id) && node.data && node.rowPinned !== 'top') {
                rows.push(node.data);
            }
        });
        this._pendingNewRows.forEach((draft) => {
            const copy = Object.assign({}, draft);
            delete copy._tempId;
            rows.push(copy);
        });

        if (rows.length === 0) {
            this._setInfoStatus('저장할 변경 사항이 없습니다.');
            return Promise.resolve();
        }

        this._setInfoStatus('저장 중...');
        // action=save는 쿼리스트링(URL)에 실린다 — POST 본문(JSON body)과는
        // 별개 채널이라 body 스키마({rows, deletedCodes})는 그대로 유지된다.
        return fetch(this._fetchUrlWith('action=save'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ rows: rows, deletedCodes: [] }),
        })
            .then((res) => res.json())
            .then((data) => {
                const results = data.results || [];
                const failures = [];
                results.forEach((result, index) => {
                    if (!result.success) {
                        failures.push((index + 1) + '번째 행: ' + (result.message || '저장 실패'));
                    }
                });
                if (failures.length > 0) {
                    this._setStatus('저장 실패');
                    window.alert('일부 저장에 실패했습니다:\n' + failures.join('\n'));
                    return;
                }
                this._setInfoStatus('저장 완료');
                this.requery();
            })
            .catch(() => {
                this._setStatus('저장 오류');
                window.alert('저장 중 오류가 발생했습니다.');
            });
    }

    /** 신규(미저장) 행은 서버에 아무 것도 안 보내고 pinnedTopRowData에서
     * 바로 뺀다. 기존 행은 확인 후 그 자리에서 즉시 delete 요청을 보내고,
     * 성공하면 requery()로 전체를 새로고침한다(부분 반영 대신 항상
     * 서버 최신 상태로 맞춤 — save()/deleteRow() 둘 다 같은 원칙). */
    _deleteRow(node) {
        if (node.rowPinned === 'top') {
            const idx = this._pendingNewRows.findIndex((row) => row._tempId === node.data._tempId);
            if (idx !== -1) {
                this._pendingNewRows.splice(idx, 1);
                this.gridApi.setGridOption('pinnedTopRowData', this._pendingNewRows.slice());
                this._updateSaveButtonState();
            }
            return;
        }
        const key = this._keyOf(node.data);
        if (key === '' || !window.confirm('이 행을 삭제하시겠습니까?')) {
            return;
        }
        this._setInfoStatus('삭제 중...');
        fetch(this._fetchUrlWith('action=delete'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ rows: [], deletedCodes: [key] }),
        })
            .then((res) => res.json())
            .then((data) => {
                const result = (data.deleteResults || [])[0];
                if (result && result.success) {
                    this._setInfoStatus('삭제 완료');
                    this.requery();
                } else {
                    this._setStatus('삭제 실패');
                    window.alert('삭제 실패: ' + (result && result.message ? result.message : '알 수 없는 오류'));
                }
            })
            .catch(() => {
                this._setStatus('삭제 오류');
                window.alert('삭제 중 오류가 발생했습니다.');
            });
    }

    // ------------------------------------------------------------------
    // 보조
    // ------------------------------------------------------------------

    /** 값이 비어있지 않고 실제 숫자로 취급 가능한지(문자열 "1213"도 포함).
     * 천단위 콤마 포맷/숫자 컬럼 우측정렬(2026-08-26 추가, 아래
     * _buildColumnDefs() 참고)이 공통으로 쓰는 판정. boolean은 `Number()`로
     * 0/1이 되어 숫자처럼 보이지만(체크박스 컬럼의 valueGetter가 돌려주는
     * true/false 등) 사람이 보는 "숫자"가 아니므로 명시적으로 제외한다. */
    static _looksNumeric(value) {
        if (value === '' || value == null || typeof value === 'boolean') {
            return false;
        }
        return !isNaN(Number(value));
    }

    /** 값이 비어있지 않고 실제 숫자면(문자열 "1213"도 포함) 천단위 콤마를
     * 붙여 반환한다(`Number(value).toLocaleString('ko-KR')`) — 빈 값/숫자가
     * 아닌 문자열은 원본 그대로 반환해 "NaN" 표시를 막는다. src/js/ScrollGrid.js
     * `_formatCellValue()`와 동일한 판정 로직(다만 그쪽은 opt-in, 여기는
     * 기본값). */
    static _formatNumberIfNumeric(value) {
        if (!AGGrid._looksNumeric(value)) {
            return value == null ? '' : value;
        }
        return Number(value).toLocaleString('ko-KR');
    }

    /** fetchUrl 뒤에 쿼리스트링을 붙인다 — fetchUrl에 이미 '?'가 있으면
     * (예: 한 fetch 파일이 여러 테이블을 맡아 `status_fetch.php?t=inquiry`
     * 처럼 고정 파라미터를 싣는 경우, 2026-10-20 추가) '&'로 잇는다. 조회/
     * 저장/삭제 요청 모두 이 함수로 주소를 만든다. */
    _fetchUrlWith(queryString) {
        return this.fetchUrl + (this.fetchUrl.indexOf('?') === -1 ? '?' : '&') + queryString;
    }

    static _toQueryString(obj) {
        const params = new URLSearchParams();
        Object.keys(obj).forEach((key) => {
            const value = obj[key];
            if (value !== '' && value != null) {
                params.set(key, String(value));
            }
        });
        return params.toString();
    }

    _escape(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /** .ag-shell-* 툴바 CSS를 <style id="aggrid-shell-styles">로 문서에 한 번만
     * 주입한다(ScrollGrid의 _injectDefaultStyles()와 동일 원리). `@layer
     * aggrid-defaults` 안에 있어 호출부가 레이어 없는 평범한 CSS로 같은
     * 선택자를 다시 적으면 그게 항상 이긴다(CSS Cascade Layers 스펙).
     * 툴바 자체엔 테두리/배경/그림자를 안 준다 — 별도 박스로 감싸지 않고
     * 그리드 바로 위에 버튼만 붙는 모양(margin-bottom만으로 간격 확보).
     * 버튼 색상은 무채색(회색 계열) 위주로 통일하고, "저장"은 가장 어두운
     * 톤, "+ 행 추가"는 초록으로 각각 강조해 주된 동작임을 표시한다. */
    static _injectDefaultStyles() {
        if (document.getElementById('aggrid-shell-styles')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'aggrid-shell-styles';
        style.textContent = `
@layer aggrid-defaults {
    .ag-shell-toolbar {
        display: flex; justify-content: space-between; align-items: center;
        gap: 6px; flex-wrap: wrap; margin-bottom: 6px;
        font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
    }
    .ag-shell-toolbar-left, .ag-shell-toolbar-right { display: flex; gap: 6px; align-items: center; }
    .ag-shell-toolbar button {
        padding: 4px 12px; border: none; border-radius: 4px;
        font-size: 0.82rem; font-weight: 500; cursor: pointer;
        transition: background-color 0.15s ease;
    }
    .ag-shell-mode-btn { background: #e5e7eb; color: #374151; }
    .ag-shell-mode-btn:hover { background: #d1d5db; }
    /* [2026-08-26 변경] "+ 행 추가"는 원래 나머지 무채색 보조버튼과
       구분하려고 초록이었는데, "저장 버튼처럼 파란색으로" 지시에 따라
       저장 버튼과 완전히 같은 배색으로 통일(같은 계열=같은 의미라는
       신호). */
    .ag-shell-add-btn {
        background: #bfdbfe; color: #1e293b; font-weight: 600;
    }
    .ag-shell-add-btn:hover { background: #93c5fd; }
    .ag-shell-search-btn, .ag-shell-goto-btn {
        background: #f3f4f6; color: #374151;
    }
    .ag-shell-search-btn:hover, .ag-shell-goto-btn:hover { background: #e5e7eb; }
    /* [2026-08-26 변경] "편집취소"는 원래 검색/이동과 같은 무채색
       보조버튼이었는데, "빨간색으로" 지시에 따라 행 삭제 버튼
       (.ag-shell-row-delete-btn)과 같은 빨강 계열로 통일(취소=되돌림,
       삭제=제거 — 둘 다 "값이 사라지는 되돌릴 수 없는 방향"이라는
       공통점을 색으로도 표시). */
    .ag-shell-cancel-btn {
        background: #e0736c; color: #fff; font-weight: 600;
    }
    .ag-shell-cancel-btn:hover { background: #c85950; }
    .ag-shell-save-btn {
        background: #bfdbfe; color: #1e293b; font-weight: 600;
    }
    .ag-shell-save-btn:hover { background: #93c5fd; }
    /* dirty 행이 하나라도 있으면 저장 버튼이 깜빡여서 "클릭해야 저장된다"는
       걸 눈에 띄게 알린다(_updateSaveButtonState()가 이 클래스를 붙였다 뗐다 함). */
    @keyframes ag-shell-blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
    .ag-shell-save-btn-dirty { animation: ag-shell-blink 1.1s ease-in-out infinite; }
    /* [2026-08-26 추가] "편집취소"도 dirty일 때 저장 버튼과 함께 깜빡이게
       — 어차피 dirty가 없으면 이 버튼 자체가 안 보이므로(위 참고), 보이는
       동안은 항상 이 클래스가 붙어있는 상태(깜빡임 자체가 "저장할 것도,
       취소할 것도 있다"는 신호가 됨). */
    .ag-shell-cancel-btn-dirty { animation: ag-shell-blink 1.1s ease-in-out infinite; }
    .ag-shell-row-delete-btn {
        /* color만 !important — 이 버튼은 .ag-cell 안(AG Grid 자신의 DOM)에서
           렌더링돼, 레이어 없는(AG Grid vendor CSS) 규칙이 이 레이어(@layer
           aggrid-defaults) 규칙보다 항상 이겨버린다(CSS Cascade Layers
           스펙 — 레이어 유무만으로 명시도와 무관하게 승패가 갈림). !important
           는 이 우선순위를 뒤집어 레이어 안 선언이 이기게 한다 — 실제로
           흰 글자가 안 먹히던 버그의 원인. */
        background: #e0736c; color: #fff !important; border: none; border-radius: 6px;
        padding: 4px 10px; font-size: 0.78rem; cursor: pointer; transition: background-color 0.15s ease;
    }
    .ag-shell-row-delete-btn:hover { background: #c85950; }
    .ag-shell-goto {
        padding: 4px 10px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.82rem;
        outline: none; transition: border-color 0.15s ease, box-shadow 0.15s ease; width: 100px;
    }
    .ag-shell-goto:focus {
        border-color: #9ca3af; box-shadow: 0 0 0 2px rgba(107, 114, 128, 0.15);
    }
    /* 검색창은 박스 테두리 대신 밑줄(언더바)만 준다 — 나머지 입력창과
       구분되는 검색 전용 모양. */
    .ag-shell-search {
        padding: 4px 20px 4px 6px; border: none; border-bottom: 1px solid #d1d5db;
        border-radius: 0; font-size: 0.82rem; outline: none;
        transition: border-color 0.15s ease;
    }
    .ag-shell-search:focus {
        border-bottom-color: #6b7280;
    }
    /* placeholder("검색어") 글자 크기를 "검색" 버튼 글자 크기와 맞춘다
       (버튼도 0.82rem — .ag-shell-toolbar button 규칙 참고). */
    .ag-shell-search::placeholder {
        font-size: 0.82rem;
    }
    /* 검색창 안쪽 오른편에 "×"(지우기) 버튼을 겹쳐 배치 — 오른쪽 padding
       (위 .ag-shell-search)만큼 자리를 비워둔 자리에 얹는다. 선택자를
       ".ag-shell-search-wrap .ag-shell-search-clear-btn"(클래스 2개)로
       묶어 특이도를 ".ag-shell-toolbar button"(클래스1+타입1)보다 높여야,
       그 규칙의 기본 padding/font-size(버튼 전체 공통값)에 안 덮인다. */
    .ag-shell-search-wrap { position: relative; display: inline-flex; align-items: center; }
    .ag-shell-search-wrap .ag-shell-search-clear-btn {
        position: absolute; right: 2px; top: 50%; transform: translateY(-50%);
        border: none; background: transparent; color: #9ca3af; cursor: pointer;
        font-size: 0.95rem; line-height: 1; padding: 2px 4px; border-radius: 3px;
    }
    .ag-shell-search-wrap .ag-shell-search-clear-btn:hover { color: #4b5563; background: #f3f4f6; }
    .ag-shell-status { font-size: 0.78rem; color: #6b7280; }
    .ag-shell-grid {
        width: 100%;
        --ag-font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
    }
    /* ag-theme-alpine 기본값은 세로 셀 경계가 옅어서(또는 안 보여서),
       컬럼 사이 구분이 잘 안 보인다는 피드백을 반영해 명시적으로 그린다. */
    .ag-shell-grid .ag-cell, .ag-shell-grid .ag-header-cell {
        border-right: 1px solid #e5e7eb;
    }
    /* 제목행/순번(No)열을 옅은 회색으로(1_8의 aggrid.html과 동일 톤) —
       그리드의 "틀"처럼 보이게 데이터 셀과 시각적으로 구분한다. */
    .ag-shell-grid .ag-header {
        background-color: #fafafa;
    }
    .ag-shell-grid .ag-header-cell, .ag-shell-grid .ag-header-group-cell {
        background-color: #fafafa; font-weight: 500; color: #6b7280;
    }
    .ag-shell-grid .ag-header-cell:hover { background-color: #f1f2f4; }
    .ag-shell-grid .ag-row:hover { background-color: #f8fafc; }
    .ag-shell-grid .ag-shell-rownum-cell {
        background-color: #fafafa !important; color: #9ca3af; font-weight: 500; text-align: center;
    }
    .ag-shell-error { color: #c0392b; }
}
`;
        document.head.appendChild(style);
    }
}

AGGrid.instances = {};
AGGrid._loadPromise = null;
