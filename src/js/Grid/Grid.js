/**
 * Grid — n(열) x m(행) 모양의 "한 페이지 분량"을 정해두고, `addCell()`을
 * 호출할 때마다 셀이 하나씩 늘어나며(마치 게시판의 레코드처럼) 그 페이지
 * 분량을 채우면 다음 페이지로 넘어가는 재사용 컴포넌트. 바탕화면
 * `매출\매출1.png`(룸살롱 룸 배치도 — 5열x3행, 룸마다 칸 하나)를
 * 일반화한 것이 계기(md/2026-09-04-매출1-5-화면-설계논의.md,
 * md/2026-09-05-grid-js-신설.md 참고).
 *
 * `Calendar`/`Popup`/`ChosungKeypad`와 동일한 원칙 — `containerId` 안에
 * 스스로를 그리고, 서버 통신은 전혀 모른다(순수 DOM). 여러 인스턴스가
 * 동시에 있어도 `Grid.instances`로 서로 안 건드린다.
 *
 * ## init 시점에 n x m 설정 + clear가 함께 일어난다 — setNxM() 단독 호출은 안 지운다
 * `new Grid(...)`(내부적으로 `action('init', options, ...)`을 부름)는
 * 넘겨받은 `n`/`m`으로 모양을 정함과 **동시에 `clear()`도 함께 해서**,
 * 처음 만들어진 그리드는 항상 "모양은 정해져 있지만 셀은 하나도 없는"
 * 상태로 시작한다. 이후 `setNxM(n, m)`을 **단독으로** 다시 부르면 모양만
 * 바뀌고(페이지 크기가 달라져 현재 페이지 위치는 다시 계산됨) **이미
 * 쌓아둔 셀은 그대로 남는다** — "설정을 바꾸면 지운다"는 옛 동작이
 * 사라졌다. 완전히 새로 시작하고 싶으면(모양은 그대로 두고 데이터만
 * 지우거나, `init(n, m)`으로 모양+데이터를 한 번에 리셋) 아래 `clear()`/
 * `init()`을 쓴다.
 *
 * 실제로 셀이 하나씩 나타나는 건 `addCell()`을 부를 때다 — `Board`가
 * 레코드를 쌓듯, 호출할 때마다 셀 번호가 1씩 늘며 뒤에 이어붙는다. 지금
 * 보고 있는 페이지 분량(`n*m`개)을 넘겨서 추가된 셀은 화면엔 당장 안
 * 보이고, 페이지를 넘겨야(`moveNext()` 등) 보인다.
 *
 * ## 셀번호 체계
 * `addCell()`이 호출된 순서대로 1부터 매겨지는 **누적 일련번호**다(더 이상
 * "몇 행 몇 열"에서 역산하는 고정 좌표가 아니다). 그 번호가 속한 페이지는
 * `Math.ceil(셀번호 / (n*m))`로 정해진다. 한 페이지 안에서는 왼쪽→오른쪽,
 * 위→아래로 `n`개씩 채워진다(`매출1.png`의 "1T~15T" 순서와 동일).
 *
 * ## displayValue와 dataValue는 서로 다른 것을 저장한다
 * `addCell(displayValue, dataValue)`(신규 셀) / `setValue(cellNo,
 * displayValue, dataValue)`(이미 추가된 셀의 값을 나중에 바꿀 때) 모두
 * 같은 두 값을 받는다 — `displayValue`는 그 셀 안에 그대로 그려지는
 * **HTML 문자열**이다(그리드 자신은 이스케이프하지 않는다 — "스타일을
 * 입힌 문구"를 그대로 보여줘야 하기 때문, 예: `<span style="background:
 * #ffe58a;">손님3 아가씨0 (3부족)</span>`). `dataValue`는 화면에는 전혀
 * 안 나타나고 그 셀에 딸린 원본 값(코드값 등)을 저장해두는 자리 —
 * `getValue(cellNo, 'data')`로만 꺼낼 수 있다. 신뢰할 수 없는 값(사용자
 * 입력 등)을 `displayValue`로 넘길 때 이스케이프하는 것은 호출부 책임이다.
 *
 * ## addCell/setValue의 마지막 인수 keyValue + getCell(keyValue)
 * `addCell(displayValue, dataValue, keyValue)`/`setValue(cellNo,
 * displayValue, dataValue, keyValue)` 둘 다 마지막 인수로 `keyValue`를
 * 받을 수 있다 — 그 셀에 임의의
 * "키"(예: 방번호·직원코드처럼 애플리케이션이 아는 고유값)를 매겨두면,
 * 나중에 `getCell(keyValue)`로 **그 키를 가진 셀번호를 거꾸로 찾을 수
 * 있다**(`Crud`의 PK 조회와 비슷한 감각 — 다만 여기서는 셀번호 자체가
 * PK가 아니라 별도로 매긴 키다). `addCell()`은 셀을 만드는 시점에 곧바로
 * 키를 매기는 가장 흔한 경우를 위한 것이고(생략하면 키 없이 생성),
 * `setValue()`는 이미 있는 셀에 나중에 키를 매기거나 바꿀 때 쓴다 —
 * `keyValue`를 생략하면(`undefined`) 기존에 그 셀에 있던 키를 그대로
 * 두고 안 건드린다(예: `setValueAdd()`처럼 키와 무관한 갱신과 뒤섞여도
 * 안전). 같은 키를 다른 셀에 또 매기면 나중 것이 이긴다(먼저 매겨졌던
 * 셀의 매핑은 사라짐).
 *
 * ## 키값으로 셀번호를 대신하는 짝 함수들 — setValueKey/setValueKeyAdd/getValueKey/clearCellKey
 * `setValue(cellNo, ...)`/`setValueAdd(cellNo, ...)`/`getValue(cellNo,
 * ...)`/`clearCell(cellNo)`를 셀번호 대신 키값으로 부르고 싶을 때 쓰는
 * 얇은 래퍼다 — 내부적으로 `getCell(keyValue)`로 셀번호를 먼저 찾은
 * 뒤 그 cellNo 버전을 그대로 호출한다. 없는 키값을 주면(아직 아무
 * 셀에도 그 키가 안 매겨졌으면) `setValueKey()`/`setValueKeyAdd()`/
 * `clearCellKey()`는 `console.warn` 후 무시, `getValueKey()`는 `null`을
 * 반환한다(각각 `setValue`/`setValueAdd`/`getValue`가 없는 셀번호에 대해
 * 하던 동작과 동일). `setValueKey(keyValue, displayValue, dataValue)`는
 * `setValue()`처럼 덮어쓰고, `setValueKeyAdd(keyValue, displayValue,
 * dataValue)`는 `setValueAdd()`처럼 이어붙인다 — 둘 다 키 자체는 다시
 * 매기지 않는다(이미 그 키로 찾아낸 셀이라 키가 바뀔 이유가 없다).
 *
 * ## clearCell(cellNo)/clearCellKey(keyValue) — 셀 값만 비움(슬롯은 유지)
 * 그 셀의 `display`/`data`/`key`를 전부 비우고(키가 있었다면
 * `getCell()` 매핑도 함께 지운다) 화면에 보이는 중이면 빈 칸으로
 * 다시 그린다. **셀 자체(슬롯, 셀번호)는 없어지지 않는다** — 뒤에
 * `addCell()`로 계속 이어붙는 번호 체계를 깨지 않기 위해서다("셀 삭제"가
 * 아니라 "그 셀의 내용을 지우는 것"에 가깝다).
 *
 * ## 페이지 이동 — moveFirst/moveNext/movePrevious/moveLast/bof/eof
 * `Crud`/`ScrollGrid`가 레코드를 오가는 데 쓰던 이름을 그대로 가져오되,
 * 여기서는 레코드 한 건이 아니라 **페이지 하나**를 오간다(그리드 한
 * 화면 자체가 이미 여러 칸을 보여주므로 "현재 레코드" 개념이 없다).
 * `bof()`는 "지금이 첫 페이지인가", `eof()`는 "지금이 마지막 페이지인가"
 * — 경계를 넘는 이동(`moveNext()`를 마지막 페이지에서 또 부르는 등)은
 * 조용히 그 자리에 머문다(`ScrollGrid`의 경계 이동과 동일한 관례).
 * `getPager()`로 `{currentPage, totalPages, label: '2/10', ...}`를 얻는다.
 *
 * ## setScroll(true|false) — 페이징 대신 스크롤로 전체를 보여줄 수도 있다
 * 기본은 `false`(페이징) — `n*m`칸씩 나눠서 `moveNext()` 등으로 넘겨봐야
 * 한다. `setScroll(true)`면 페이지 구분 자체가 없어지고(`getPager()`의
 * `totalPages`는 항상 `1`, `bof()`/`eof()`도 항상 `true`), 지금까지
 * `addCell()`된 **모든** 셀을 한 번에 그린 뒤 컨테이너 자체가 세로로
 * 스크롤된다 — **셀 크기(행 높이)는 페이징 모드였을 때와 똑같이
 * 고정**된다(컨테이너 높이에서 `Grid.SCROLL_BOTTOM_GAP`(기본 12px, 맨
 * 마지막 행이 하단에 바로 붙어 가려 보이지 않도록 두는 여백)만큼 뺀
 * 값을 `m`으로 나눠 모든 행에 그대로 적용, 열 수(`n`)도 그대로 유지) —
 * 셀 크기는 안 바뀌고 행 개수만 늘어나 넘치는 만큼 스크롤로 보게 되는
 * 구조다. `clear()`/`setNxM()`/`init()`
 * 은 이 스크롤 여부를 안 건드린다(완전히 별개의 설정) — `setScroll()`을
 * 직접 불러야만 바뀐다.
 *
 * ## setStyle(null|'default'|'card') — 칸 외형을 기본형과 카드형 사이에서 토글
 * 바탕화면 `매출\카드.png`(지갑 앱 UI, 둥근 모서리+그림자+여백이 있는
 * 카드형 위젯들)를 참고해, `null`/`'default'`(기본형)는 지금까지의 표
 * 느낌(칸끼리 딱 붙어 테두리로만 구분)을 유지하고, `'card'`면 각 칸이
 * 서로 간격을 두고 떨어진 채(`gap`) 모서리가 둥글고(`border-radius`)
 * 그림자가 진(`box-shadow`) 개별 카드처럼 보이게 그려진다. 셀 안 내용
 * (`displayValue`)은 전혀 안 건드리는 순수 표면 스타일 전환이고,
 * `addCell`/`setValue` 등의 규칙(셀번호 체계, displayValue/dataValue
 * 저장 방식)도 기본형과 완전히 동일하다 — `n`/`m`/스크롤 여부/데이터
 * 어느 것과도 무관하게 독립적으로 켜고 끌 수 있다(`'card'`가 아닌 값은
 * 전부 기본형으로 취급). `clear()`/`setNxM()`/`init()`/`setScroll()`은
 * 이 값을 안 건드린다 — `setStyle()`을 직접 불러야만 바뀐다.
 *
 * ## getValueAll() — 그리드 전체를 JSON 문자열로
 * `{n, m, scroll, currentPage, totalPages, totalCells, cells: [{cellNo,
 * display, data}, ...]}` 형태를 `JSON.stringify()`한 문자열을 반환한다
 * (`Crud::toJson()`과 비슷한 감각 — "지금 이 인스턴스가 들고 있는 전부"를
 * 한 번에 꺼내는 것).
 *
 * ## setValueAdd(cellNo, displayValue, dataValue) — 덮어쓰지 않고 이어붙임
 * `setValue()`는 매번 값을 통째로 덮어쓴다. `setValueAdd()`는 **이미
 * 있는 값 뒤에 이어붙인다** — `displayValue`는 문자열로 이어붙이고(로그가
 * 쌓이는 느낌), `dataValue`는 배열에 계속 `push`한다(첫 호출이면 그
 * 값 하나짜리 배열이 되고, 기존 `data`가 배열이 아니었으면 그 값을
 * 배열 첫 항목으로 감싼 뒤 새 값을 이어붙인다). `setValue()`와 마찬가지로
 * **이미 `addCell()`된 셀에만** 적용된다.
 *
 * ## 내부 구조 — action()이 유일한 진입점
 * 이 클래스 안에서 일어나는 모든 일(초기화, n x m 설정, 초기화(지우기),
 * 셀 추가/설정(덮어쓰기·이어붙이기·키로 설정·키로 이어붙이기)/비우기
 * (셀번호·키)/조회(단일·전체·키로 역조회·키로 조회), 페이지 이동,
 * 스크롤 모드 전환, 셀 클릭)은 `action(pcmd, pparams, psender)` 하나를
 * 거쳐 `_setNxM()`/`_clear()`/`_addCell()`/`_setValue()`/`_setValueAdd()`/
 * `_setValueKey()`/`_setValueKeyAdd()`/`_clearCell()`/`_clearCellKey()`/
 * `_getValue()`/`_getValueKey()`/`_getValueAll()`/`_getCell()`/
 * `_moveToPage()`/`_setScroll()`/`_setStyle()` 같은 private 함수로 분기된다
 * (RoomBoard.js/SplitView.js가 이미 쓰고 있는 관용구를 그대로 재사용).
 * `_setValueKey()`/`_setValueKeyAdd()`/`_clearCellKey()`/`_getValueKey()`
 * 는 `_getCell()`로 셀번호를 먼저 찾은 뒤 그 cellNo 버전(`_setValue()`/
 * `_setValueAdd()`/`_clearCell()`/`_getValue()`)을 그대로 불러 위임한다
 * — 로직을 두 번 짜지 않기 위함. `init()`/`setNxM()`/`clear()`/
 * `addCell()`/`setValue()`/`setValueAdd()`/`setValueKey()`/
 * `setValueKeyAdd()`/`clearCell()`/`clearCellKey()`/`getValue()`/
 * `getValueKey()`/`getValueAll()`/`getCell()`/`moveFirst()`/`setScroll()`/
 * `setStyle()` 등 공개 메서드는 전부 이 `action()`을 부르는 얇은 래퍼일
 * 뿐이다.
 * `_render()`는 서버 fetch 없이 항상 동기로 끝나므로(다른 화면과 달리
 * 로드할 라이브러리가 없음), RoomBoard.js처럼 `Promise.resolve(...)`로
 * 감쌀 필요가 없다.
 *
 * 사용 예:
 *   const grid = new Grid({ containerId: 'my-grid', n: 5, m: 3 }); // init: 5x3 + clear
 *   const no1 = grid.addCell('<b>1T(5)</b><br>전솔아', { room: '1T', capacity: 5 }, '1T'); // 생성과 동시에 키 매기기
 *   grid.getValue(no1, 'display'); // '<b>1T(5)</b><br>전솔아'
 *   grid.getValue(no1, 'data');    // { room: '1T', capacity: 5 }
 *   grid.setValue(no1, '<b>1T(5)</b><br>퇴장', { room: '1T', capacity: 5 }); // 값만 갱신(덮어쓰기, 키 유지)
 *   grid.setValueAdd(no1, '<br>다시입장', null); // 기존 값 뒤에 이어붙임
 *   grid.getCell('1T'); // no1 (키 '1T'를 가진 셀번호)
 *   grid.setValueKey('1T', '<b>1T(5)</b><br>다시퇴장', null); // 키로 지정해 덮어쓰기(키 자체는 안 바뀜)
 *   grid.setValueKeyAdd('1T', '<br>추가문구', null); // 키로 지정해 이어붙이기
 *   grid.getValueKey('1T', 'display'); // '<b>1T(5)</b><br>다시퇴장<br>추가문구'
 *   grid.clearCellKey('1T'); // 그 셀의 display/data/key를 전부 비움(셀번호 자리는 남음)
 *   grid.clearCell(no1);     // 셀번호로 직접 비워도 동일
 *   grid.getPager(); // { currentPage: 1, totalPages: 1, totalCells: 1, pageSize: 15, label: '1/1' }
 *   grid.moveNext(); grid.bof(); grid.eof();
 *   grid.setNxM(3, 3);  // 모양만 3x3으로(데이터는 그대로 남음)
 *   grid.clear();       // 모양은 그대로, 데이터만 전부 지움
 *   grid.init(5, 3);    // 모양+데이터를 한 번에 리셋(생성 직후와 동일한 상태)
 *   grid.setScroll(true);  // 페이징 대신 스크롤로 전체를 한 번에
 *   grid.getValueAll(); // '{"n":5,"m":3,"scroll":true,...,"cells":[...]}'
 *   grid.setStyle('card'); // 칸 외형만 카드형으로(간격+둥근모서리+그림자, addCell/setValue 규칙은 그대로)
 *   grid.setStyle(null);   // 다시 기본형으로('default'를 줘도 동일)
 */
class Grid {
    /**
     * @param {{containerId: string, id?: string, n?: number, m?: number,
     *   onCellClick?: (cellNo: number, grid: Grid) => void}} options
     */
    constructor(options) {
        options = options || {};
        if (!options.containerId) {
            throw new Error('Grid: containerId는 필수입니다.');
        }
        this.containerId = options.containerId;
        this.id = options.id || options.containerId;
        this.onCellClick = typeof options.onCellClick === 'function' ? options.onCellClick : null;

        this.n = 1; // 한 페이지의 열 수
        this.m = 1; // 한 페이지의 행 수
        // addCell()된 순서대로 쌓이는 배열 — 인덱스(0부터)+1이 곧 셀번호.
        this._records = [];
        this._currentPage = 1; // 1부터
        // 현재 화면에 실제로 그려진 셀만 cellNo -> <div> 로 캐시된다(다른
        // 페이지의 셀은 DOM 자체가 없다 — 아래 _render() 참고).
        this._cellEls = {};
        // String(keyValue) -> cellNo. setValue()의 4번째 인수로만 채워지고
        // getCell()이 역조회에 쓴다 — clear()/init()에서 함께 비워진다.
        this._keyIndex = {};
        // false=페이징(기본), true=스크롤(전체를 한 번에) — setScroll()로만
        // 바뀐다(init()/clear()/setNxM()은 이 값을 안 건드림).
        this._scroll = false;
        // null=표 스타일(기본), 'card'=카드 스타일 — setStyle()로만 바뀐다
        // (다른 어떤 것도 이 값을 안 건드림).
        this._style = null;

        Grid.instances[this.id] = this;
        Grid._injectStyles();

        // n x m 설정 + clear는 전부 action('init')이 한다(아래 참고) —
        // 생성자는 그 요청만 넣을 뿐 직접 손대지 않는다.
        this.action('init', options, this);
    }

    /** 이 인스턴스가 다루는 모든 일의 유일한 진입점. */
    action(pcmd, pparams, psender) {
        pparams = pparams || {};
        switch (pcmd) {
            case 'init':
                // 넘겨받은 n x m으로 모양을 정하고(pparams.n/m이 없으면 지금
                // 값 유지), 곧바로 clear()까지 해서 "모양은 정해졌지만 셀은
                // 하나도 없는" 상태로 만든다 — setNxM() 단독 호출과 달리
                // clear()가 항상 함께 온다(위 클래스 docblock 참고).
                this._setNxM(pparams.n || this.n, pparams.m || this.m);
                this._clear();
                break;
            case 'setNxM':
                this._setNxM(pparams.n, pparams.m);
                break;
            case 'clear':
                this._clear();
                break;
            case 'addCell':
                return this._addCell(pparams.displayValue, pparams.dataValue, pparams.keyValue);
            case 'setValue':
                this._setValue(pparams.cellNo, pparams.displayValue, pparams.dataValue, pparams.keyValue);
                break;
            case 'setValueAdd':
                this._setValueAdd(pparams.cellNo, pparams.displayValue, pparams.dataValue);
                break;
            case 'setValueKey':
                this._setValueKey(pparams.keyValue, pparams.displayValue, pparams.dataValue);
                break;
            case 'setValueKeyAdd':
                this._setValueKeyAdd(pparams.keyValue, pparams.displayValue, pparams.dataValue);
                break;
            case 'clearCell':
                this._clearCell(pparams.cellNo);
                break;
            case 'clearCellKey':
                this._clearCellKey(pparams.keyValue);
                break;
            case 'getValue':
                return this._getValue(pparams.cellNo, pparams.kind);
            case 'getValueKey':
                return this._getValueKey(pparams.keyValue, pparams.kind);
            case 'getValueAll':
                return this._getValueAll();
            case 'getCell':
                return this._getCell(pparams.keyValue);
            case 'setScroll':
                this._setScroll(pparams.scroll);
                break;
            case 'setStyle':
                this._setStyle(pparams.style);
                break;
            case 'moveFirst':
                this._moveToPage(1);
                break;
            case 'moveLast':
                this._moveToPage(this._totalPages());
                break;
            case 'moveNext':
                this._moveToPage(this._currentPage + 1);
                break;
            case 'movePrevious':
                this._moveToPage(this._currentPage - 1);
                break;
            case 'cellClick':
                if (this.onCellClick) {
                    this.onCellClick(pparams.cellNo, psender);
                }
                break;
            default:
                console.warn('Grid.action: 알 수 없는 pcmd', pcmd, psender);
        }
        return undefined;
    }

    /** 외부 공개 API — 모양(n x m)과 데이터를 한 번에 리셋한다("새로 만든 것"과 동일한 상태로). */
    init(n, m) {
        this.action('init', { n: n, m: m }, this);
    }

    /** 외부 공개 API — 한 페이지의 열(n)x행(m)만 바꾼다. 이미 쌓아둔 셀은 지워지지 않는다. */
    setNxM(n, m) {
        this.action('setNxM', { n: n, m: m }, this);
    }

    /** 외부 공개 API — 모양은 그대로 두고 쌓아둔 셀만 전부 지운다(화면도 비워짐). */
    clear() {
        this.action('clear', {}, this);
    }

    /**
     * 외부 공개 API — 셀 하나를 끝에 추가하며 값을 함께 설정한다.
     * keyValue(선택)를 주면 그 자리에서 바로 키도 매긴다. 반환값은 그
     * 셀의 번호(1부터).
     */
    addCell(displayValue, dataValue, keyValue) {
        return this.action('addCell', { displayValue: displayValue, dataValue: dataValue, keyValue: keyValue }, this);
    }

    /**
     * 외부 공개 API — 이미 addCell()된 셀의 값을 나중에 바꾼다(통째로
     * 덮어씀). keyValue를 생략하면 그 셀의 기존 키는 안 건드린다.
     */
    setValue(cellNo, displayValue, dataValue, keyValue) {
        this.action('setValue', { cellNo: cellNo, displayValue: displayValue, dataValue: dataValue, keyValue: keyValue }, this);
    }

    /** 외부 공개 API — 덮어쓰지 않고 이미 있는 값 뒤에 이어붙인다(display는 문자열 결합, data는 배열에 push). */
    setValueAdd(cellNo, displayValue, dataValue) {
        this.action('setValueAdd', { cellNo: cellNo, displayValue: displayValue, dataValue: dataValue }, this);
    }

    /** 외부 공개 API — 셀번호 대신 키값으로 지정해 setValue()와 동일하게 값을 덮어쓴다(키 자체는 안 바뀜). 없는 키면 경고. */
    setValueKey(keyValue, displayValue, dataValue) {
        this.action('setValueKey', { keyValue: keyValue, displayValue: displayValue, dataValue: dataValue }, this);
    }

    /** 외부 공개 API — 셀번호 대신 키값으로 지정해 setValueAdd()와 동일하게 이어붙인다(키 자체는 안 바뀜). 없는 키면 경고. */
    setValueKeyAdd(keyValue, displayValue, dataValue) {
        this.action('setValueKeyAdd', { keyValue: keyValue, displayValue: displayValue, dataValue: dataValue }, this);
    }

    /** 외부 공개 API — 그 셀번호의 값(display/data/key)만 비운다. 슬롯(셀번호) 자체는 안 없어진다. */
    clearCell(cellNo) {
        this.action('clearCell', { cellNo: cellNo }, this);
    }

    /** 외부 공개 API — 키값으로 셀을 찾아 clearCell()과 동일하게 비운다. 없는 키면 경고. */
    clearCellKey(keyValue) {
        this.action('clearCellKey', { keyValue: keyValue }, this);
    }

    /** 외부 공개 API — kind는 'display'(기본)/'data'/'key'. */
    getValue(cellNo, kind) {
        return this.action('getValue', { cellNo: cellNo, kind: kind }, this);
    }

    /** 외부 공개 API — 셀번호 대신 키값으로 지정해 getValue()와 동일하게 조회한다. 없는 키면 null. */
    getValueKey(keyValue, kind) {
        return this.action('getValueKey', { keyValue: keyValue, kind: kind }, this);
    }

    /** 외부 공개 API — 그리드 전체 정보를 JSON 문자열로 반환한다. */
    getValueAll() {
        return this.action('getValueAll', {}, this);
    }

    /** 외부 공개 API — setValue()의 keyValue로 매겨둔 셀번호를 거꾸로 찾는다. 없으면 null. */
    getCell(keyValue) {
        return this.action('getCell', { keyValue: keyValue }, this);
    }

    /** 외부 공개 API — true면 페이징 대신 스크롤로 전체를 한 번에 보여준다. */
    setScroll(scroll) {
        this.action('setScroll', { scroll: scroll }, this);
    }

    /** 외부 공개 API — null/'default'(기본형, 표 느낌) 또는 'card'(카드형, 간격+둥근모서리+그림자)로 칸 외형을 바꾼다. */
    setStyle(style) {
        this.action('setStyle', { style: style }, this);
    }

    /** 첫 페이지로 이동한다. */
    moveFirst() {
        this.action('moveFirst', {}, this);
    }

    /** 다음 페이지로 이동한다(이미 마지막 페이지면 그대로 머문다). */
    moveNext() {
        this.action('moveNext', {}, this);
    }

    /** 이전 페이지로 이동한다(이미 첫 페이지면 그대로 머문다). */
    movePrevious() {
        this.action('movePrevious', {}, this);
    }

    /** 마지막 페이지로 이동한다. */
    moveLast() {
        this.action('moveLast', {}, this);
    }

    /** 지금이 첫 페이지인가. */
    bof() {
        return this._currentPage <= 1;
    }

    /** 지금이 마지막 페이지인가. */
    eof() {
        return this._currentPage >= this._totalPages();
    }

    /** 현재 페이지/전체 페이지 수 등 페이저 정보를 반환한다. */
    getPager() {
        const totalPages = this._totalPages();
        return {
            currentPage: this._currentPage,
            totalPages: totalPages,
            totalCells: this._records.length,
            pageSize: this.n * this.m,
            label: this._currentPage + '/' + totalPages,
        };
    }

    /** 이 그리드를 컨테이너에서 지우고 레지스트리에서도 뺀다. */
    destroy() {
        const container = document.getElementById(this.containerId);
        if (container) {
            container.innerHTML = '';
        }
        delete Grid.instances[this.id];
    }

    /** 등록된 인스턴스를 id로 조회한다(Calendar.instance()와 동일 패턴). */
    static instance(id) {
        return Grid.instances[id] || null;
    }

    // ------------------------------------------------------------------
    // 내부 — action()이 위임하는 private 함수들
    // ------------------------------------------------------------------

    _totalPages() {
        if (this._scroll) {
            return 1; // 스크롤 모드는 전부 한 번에 보여주므로 "페이지"가 하나뿐이다.
        }
        const pageSize = this.n * this.m;
        if (pageSize <= 0 || this._records.length === 0) {
            return 1; // 빈 그리드도 "1페이지 중 1페이지"로 취급(0으로 나누기 방지).
        }
        return Math.ceil(this._records.length / pageSize);
    }

    _setNxM(n, m) {
        this.n = Grid._positiveInt(n, this.n);
        this.m = Grid._positiveInt(m, this.m);
        // 데이터(this._records)는 그대로 둔다 — 지우는 건 clear()의 몫이지
        // setNxM() 단독 호출의 몫이 아니다(위 클래스 docblock 참고). 다만
        // 페이지 크기(n*m)가 달라졌으니 지금 페이지 번호가 범위를 벗어나면
        // 안으로 당기고, 새 모양대로 다시 그린다.
        this._currentPage = Math.max(1, Math.min(this._currentPage, this._totalPages()));
        this._render();
    }

    _clear() {
        this._records = [];
        this._currentPage = 1;
        this._cellEls = {};
        this._keyIndex = {}; // 셀이 전부 사라지니 키 매핑도 같이 비운다.
        const container = document.getElementById(this.containerId);
        if (container) {
            // 격자 프레임(.wc-grid)조차 안 그린다 — addCell()이 다시 불릴
            // 때 _render()가 그린다. 지금은 정말 "아무것도 안 보이는" 상태.
            container.innerHTML = '';
            container.classList.remove('wc-grid');
        }
    }

    _addCell(displayValue, dataValue, keyValue) {
        const record = { display: displayValue, data: dataValue };
        this._records.push(record);
        const cellNo = this._records.length;
        if (keyValue !== undefined) {
            // 새로 생긴 셀이라 이전 매핑을 지울 것도 없이 바로 매긴다
            // (setValue()의 "이미 있던 키부터 지운다" 분기가 여기선
            // 불필요 — 방금 만든 레코드는 key 필드 자체가 없었으므로).
            record.key = keyValue;
            this._keyIndex[String(keyValue)] = cellNo;
        }
        if (this._scroll) {
            // 스크롤 모드는 항상 전부 다 보여주므로, 몇 번째로 추가됐든
            // 무조건 다시 그린다(아래 "페이지" 계산 자체가 의미 없음).
            this._render();
        } else {
            const pageSize = this.n * this.m;
            const pageOfNewCell = pageSize > 0 ? Math.ceil(cellNo / pageSize) : 1;
            if (pageOfNewCell === this._currentPage) {
                // 지금 보고 있는 페이지에 떨어지는 셀만 즉시 화면에 반영한다
                // — 다른 페이지 몫으로 추가된 셀은 페이지를 넘겨야 보인다.
                this._render();
            }
        }
        return cellNo;
    }

    _setValue(cellNo, displayValue, dataValue, keyValue) {
        const record = this._records[cellNo - 1];
        if (!record) {
            console.warn('Grid.setValue: 존재하지 않는 셀번호(먼저 addCell()로 추가해야 함)', cellNo);
            return;
        }
        record.display = displayValue;
        record.data = dataValue;
        if (keyValue !== undefined) {
            // 이 셀이 이미 다른 키를 갖고 있었다면 그 매핑부터 지운다
            // (아니면 옛 키로 조회했을 때 여전히 이 셀번호가 나오는 유령
            // 매핑이 남는다).
            if (record.key !== undefined && this._keyIndex[String(record.key)] === cellNo) {
                delete this._keyIndex[String(record.key)];
            }
            record.key = keyValue;
            this._keyIndex[String(keyValue)] = cellNo; // 같은 키를 또 매기면 나중 것이 이긴다.
        }
        const el = this._cellEls[cellNo];
        if (el) {
            // 지금 화면에 그 셀이 보이는 중일 때만 DOM도 갱신한다 — 다른
            // 페이지에 있는 셀이면 값만 바뀌고 화면은 다음에 그 페이지로
            // 넘어올 때 자연히 반영된다.
            el.innerHTML = displayValue === null || displayValue === undefined ? '' : String(displayValue);
        }
    }

    _setValueAdd(cellNo, displayValue, dataValue) {
        const record = this._records[cellNo - 1];
        if (!record) {
            console.warn('Grid.setValueAdd: 존재하지 않는 셀번호(먼저 addCell()로 추가해야 함)', cellNo);
            return;
        }
        // display: 문자열로 이어붙인다(기존 값이 없으면 새 값 그대로).
        const prevDisplay = record.display === null || record.display === undefined ? '' : String(record.display);
        const nextDisplay = displayValue === null || displayValue === undefined ? '' : String(displayValue);
        record.display = prevDisplay + nextDisplay;
        // data: 배열에 계속 쌓는다 — 기존 값이 배열이 아니면 먼저 배열로
        // 감싼 뒤(첫 이어붙이기라면 빈 배열에서 시작) 새 값을 push한다.
        if (!Array.isArray(record.data)) {
            record.data = record.data === null || record.data === undefined ? [] : [record.data];
        }
        record.data.push(dataValue);
        const el = this._cellEls[cellNo];
        if (el) {
            el.innerHTML = record.display;
        }
    }

    _setValueKey(keyValue, displayValue, dataValue) {
        const cellNo = this._getCell(keyValue);
        if (cellNo === null) {
            console.warn('Grid.setValueKey: 존재하지 않는 키값', keyValue);
            return;
        }
        // 이미 이 키로 찾아낸 셀이니 keyValue를 다시 안 넘긴다(키 자체는
        // 그대로 유지 — setValue()의 "keyValue 생략 시 안 건드림" 규칙).
        this._setValue(cellNo, displayValue, dataValue);
    }

    _setValueKeyAdd(keyValue, displayValue, dataValue) {
        const cellNo = this._getCell(keyValue);
        if (cellNo === null) {
            console.warn('Grid.setValueKeyAdd: 존재하지 않는 키값', keyValue);
            return;
        }
        this._setValueAdd(cellNo, displayValue, dataValue);
    }

    _clearCell(cellNo) {
        const record = this._records[cellNo - 1];
        if (!record) {
            console.warn('Grid.clearCell: 존재하지 않는 셀번호', cellNo);
            return;
        }
        if (record.key !== undefined && this._keyIndex[String(record.key)] === cellNo) {
            delete this._keyIndex[String(record.key)];
        }
        record.display = null;
        record.data = null;
        delete record.key;
        const el = this._cellEls[cellNo];
        if (el) {
            el.innerHTML = '';
        }
    }

    _clearCellKey(keyValue) {
        const cellNo = this._getCell(keyValue);
        if (cellNo === null) {
            console.warn('Grid.clearCellKey: 존재하지 않는 키값', keyValue);
            return;
        }
        this._clearCell(cellNo);
    }

    _getValue(cellNo, kind) {
        const record = this._records[cellNo - 1];
        if (!record) {
            return null;
        }
        if (kind === 'data') {
            return record.data;
        }
        if (kind === 'key') {
            return record.key !== undefined ? record.key : null;
        }
        return record.display;
    }

    _getValueKey(keyValue, kind) {
        const cellNo = this._getCell(keyValue);
        if (cellNo === null) {
            return null;
        }
        return this._getValue(cellNo, kind);
    }

    _getCell(keyValue) {
        const cellNo = this._keyIndex[String(keyValue)];
        return cellNo !== undefined ? cellNo : null;
    }

    _getValueAll() {
        const cells = this._records.map((record, index) => ({
            cellNo: index + 1,
            display: record.display,
            data: record.data,
            key: record.key !== undefined ? record.key : null,
        }));
        return JSON.stringify({
            n: this.n,
            m: this.m,
            scroll: this._scroll,
            currentPage: this._currentPage,
            totalPages: this._totalPages(),
            totalCells: this._records.length,
            cells: cells,
        });
    }

    _setScroll(scroll) {
        this._scroll = !!scroll;
        this._currentPage = Math.max(1, Math.min(this._currentPage, this._totalPages()));
        this._render();
    }

    _setStyle(style) {
        // 'card'가 아닌 값은 전부 기본형(null)으로 취급한다 — 'default',
        // undefined, 오타 등 무엇을 넘겨도 안전하게 기본형으로 떨어진다.
        this._style = style === 'card' ? 'card' : null;
        this._render();
    }

    _moveToPage(page) {
        const totalPages = this._totalPages();
        this._currentPage = Math.max(1, Math.min(page, totalPages));
        this._render();
    }

    _render() {
        const container = document.getElementById(this.containerId);
        if (!container) {
            return;
        }
        container.innerHTML = '';
        container.classList.add('wc-grid');
        // 카드형(setStyle('card'))일 때만 카드 전용 CSS(간격/둥근모서리/
        // 그림자)가 걸리는 클래스 — 기본형이면 제거해 표 느낌으로 복귀.
        container.classList.toggle('wc-grid-style-card', this._style === 'card');
        container.style.gridTemplateColumns = 'repeat(' + this.n + ', 1fr)';

        let startIndex;
        let endIndex;
        if (this._scroll) {
            // 스크롤 모드 — 페이지 구분 없이 전부 그리되, 셀 크기는
            // 페이징 모드와 똑같이 고정한다. `1fr`을 그대로 쓰면
            // 브라우저가 "컨테이너 높이 안에 다 욱여넣기" 위해 행 수가
            // 늘어날수록 각 행을 계속 줄여버려(1fr은 남는 공간을
            // n등분할 뿐 넘치질 않는다) 스크롤 자체가 안 생긴다 — 그래서
            // 페이징 모드였다면 각 행이 가졌을 실제 픽셀 높이
            // (컨테이너 높이 ÷ m)를 한 번 재서 `grid-auto-rows`로
            // 모든 행에 고정으로 적용한다. 행 개수(records 전체)가
            // m을 넘으면 총 높이가 컨테이너를 넘어서고, 그만큼을
            // `.wc-grid`의 `overflow-y:auto`(아래 CSS)가 스크롤로 보여준다.
            container.style.gridTemplateRows = '';
            // 맨 마지막 행이 컨테이너 하단 테두리에 바로 붙어(스크롤을
            // 끝까지 내려도) 살짝 가려 보이던 문제 — 행 높이 계산에 쓸
            // 높이에서 이 여백만큼 미리 빼두고, 그 빼둔 만큼을
            // padding-bottom으로 돌려줘 마지막 행 밑에 숨 쉴 공간을
            // 남긴다(행 높이 자체는 여전히 페이징 모드와 동일하게
            // 고정 — 이 여백은 계산에서 완전히 분리된 별도 값).
            const bottomGap = Grid.SCROLL_BOTTOM_GAP;
            const availableHeight = Math.max(container.clientHeight - bottomGap, 0);
            const fixedRowHeight = this.m > 0 ? availableHeight / this.m : 0;
            container.style.gridAutoRows = fixedRowHeight > 0 ? fixedRowHeight + 'px' : 'auto';
            container.style.paddingBottom = bottomGap + 'px';
            startIndex = 0;
            endIndex = this._records.length;
        } else {
            container.style.gridTemplateRows = 'repeat(' + this.m + ', 1fr)';
            container.style.gridAutoRows = ''; // 스크롤 모드 전용 값 — 페이징 복귀 시 지운다.
            container.style.paddingBottom = ''; // 스크롤 모드 전용 값 — 페이징 복귀 시 지운다.
            const pageSize = this.n * this.m;
            startIndex = (this._currentPage - 1) * pageSize; // _records의 0-based 시작점
            endIndex = Math.min(startIndex + pageSize, this._records.length);
        }

        this._cellEls = {};
        for (let i = startIndex; i < endIndex; i++) {
            const cellNo = i + 1;
            const record = this._records[i];
            const cellEl = document.createElement('div');
            cellEl.className = 'wc-grid-cell';
            cellEl.setAttribute('data-cell-no', String(cellNo));
            cellEl.innerHTML = record.display === null || record.display === undefined ? '' : String(record.display);
            container.appendChild(cellEl);
            this._cellEls[cellNo] = cellEl;
        }

        // 클릭은 셀마다 리스너를 붙이지 않고 컨테이너 하나에 위임한다 —
        // 페이지 이동으로 그리드가 통째로 다시 그려져도(이 함수가 매번
        // container.innerHTML을 비우고 새로 만듦) 위임 리스너 자체는
        // container에 붙어있어 다시 걸 필요가 없다(리스너 중복 방지를
        // 위해 한 번만 건다).
        if (!container.dataset.gridClickBound) {
            container.addEventListener('click', (event) => {
                const cellEl = event.target.closest('.wc-grid-cell');
                if (!cellEl || !container.contains(cellEl)) {
                    return;
                }
                this.action('cellClick', { cellNo: parseInt(cellEl.getAttribute('data-cell-no'), 10) }, this);
            });
            container.dataset.gridClickBound = '1';
        }
    }

    static _positiveInt(value, fallback) {
        const n = parseInt(value, 10);
        return Number.isFinite(n) && n > 0 ? n : fallback;
    }

    static _injectStyles() {
        if (document.getElementById('grid-default-styles')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'grid-default-styles';
        style.textContent = `
@layer grid-defaults {
    .wc-grid {
        display: grid;
        gap: 0;
        border-top: 1px solid #ccc;
        border-left: 1px solid #ccc;
        width: 100%;
        height: 100%;
        box-sizing: border-box;
        font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
        font-size: 0.85rem;
        color: #1f2d3d;
        /* 스크롤 모드(setScroll(true))에서 전체 셀이 컨테이너 높이를
           넘으면 여기서 세로 스크롤이 생긴다 — 페이징 모드에서는 항상
           딱 맞게 채워지므로(m행 고정) 넘칠 일이 없어 무해하다. */
        overflow-y: auto;
    }
    .wc-grid-cell {
        border-right: 1px solid #ccc;
        border-bottom: 1px solid #ccc;
        box-sizing: border-box;
        padding: 8px;
        overflow: auto;
        cursor: pointer;
    }
    .wc-grid-cell:hover {
        background: #f5f8ff;
    }
    /* 카드형(setStyle('card')) — 칸끼리 간격을 두고 각각 둥근 모서리+
       그림자를 가진 개별 카드처럼 보인다(매출\\카드.png의 지갑 앱 UI
       참고). addCell/setValue 규칙(셀번호 체계, displayValue/dataValue
       저장 방식)은 기본형과 완전히 동일 — 겉모습만 바뀐다. */
    .wc-grid.wc-grid-style-card {
        gap: 12px;
        border-top: none;
        border-left: none;
    }
    .wc-grid.wc-grid-style-card .wc-grid-cell {
        border: none;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.12);
    }
    .wc-grid.wc-grid-style-card .wc-grid-cell:hover {
        background: #fff;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.18);
    }
}
`;
        document.head.appendChild(style);
    }
}

Grid.instances = {};
// 스크롤 모드(setScroll(true))에서 마지막 행 아래에 남기는 여백(px) —
// 이 값만큼 행 높이 계산용 높이에서 미리 빼고 padding-bottom으로
// 돌려준다(위 _render() 참고).
Grid.SCROLL_BOTTOM_GAP = 12;
