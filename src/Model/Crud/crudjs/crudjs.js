/**
 * CrudJs — id=필드명 자동 바인딩 클라이언트 CRUD.
 *
 * md/2026-09-14-crudjs-구현.md ((0)~(5)) 설계를 그대로 구현한 것.
 *
 * [2026-09-14 후속 — 저장소를 DOM에서 내부 객체(_data)로 이전]
 * 값의 실제 저장소는 이제 id=필드명 DOM 노드가 아니라 이 인스턴스 내부의
 * `_data` 객체다(서버 `Crud::$data`와 같은 역할). 양방향 동기화는 이름이
 * 붙은 두 함수로 나뉜다:
 * - `transObj2Dom(field)` — 객체 -> DOM. `_data[field]`를 그 필드의 id
 *   노드에 쓴다. 노드가 화면에 이미 있으면 그대로 쓰고, 없으면
 *   **기본적으로 아무것도 만들지 않는다** — 그 필드는 DOM에 전혀 안
 *   나타나고 `_data` 안에만 존재한다(이전 버전은 없으면 무조건 hidden
 *   input을 만들었음 — 화이트리스트로 좁혀도 화면에 안 그린 필드가 전부
 *   hidden 노드로 페이지 소스/Elements 패널에 그대로 보이던 것을 없앰).
 *   `options.showHidden`(기본 `false`)이 `true`면 노드가 없을 때 hidden
 *   input을 만들어서까지 반영한다(디버깅용).
 * - `transDom2Obj(field)` — DOM -> 객체. 그 필드의 id 노드가 화면에
 *   있으면 지금 DOM에 있는 값을 읽어 `_data[field]`에 반영한다(사용자가
 *   화면 입력칸에 직접 타이핑한 값을 잡아내는 지점), 노드가 없으면
 *   `_data`는 그대로 둔다.
 * - `render()`는 스키마의 모든 필드에 대해 `transObj2Dom()`을 호출하는
 *   얇은 래퍼다 — 최초 로드/selectRecord·selectRecords 응답 반영/커서
 *   이동/clear() 등 "화면을 다시 그려야 하는" 시점에 이 함수 하나만
 *   부르면 된다. `showHidden`이면 "보이는 노드냐 hidden 노드냐"를
 *   구분하지 않고 "그 id의 DOM 노드가 있는가"만 본다.
 * - [2026-09-14 후속 — 화면 참조를 생성자 인자 + `setWindow()`로 분리]
 *   화면(입력칸이 있는 문서)은 이제 "이 스크립트가 도는 문서"로 고정돼
 *   있지 않다 — `options.window`(생성자 인자, 생략 가능)를 주면 그
 *   화면을 참조하고, **안 주면 표현 화면 없이 `_data`만 갖고 안 보이는
 *   곳에서 움직인다**(headless — `transObj2Dom()`/`transDom2Obj()`가
 *   둘 다 조용히 아무 일도 안 함). 실제 연결은 `setWindow(windowOrIframeEl)`
 *   가 전담한다(생성자는 `options.window`가 있으면 이 메서드를 그대로
 *   호출할 뿐) — 나중에 화면을 바꾸거나 늦게 연결하고 싶을 때 직접
 *   불러도 된다. `Window`를 바로 주면(이미 로드된 페이지, 보통 현재
 *   페이지의 `window`) 즉시 연결하고, **`<iframe>` 엘리먼트**를 주면
 *   그 iframe이 자기 문서를 다 로드할 때까지 `setWindow()` 안에서
 *   기다렸다가(이미 로드가 끝나 있으면 즉시) `contentWindow`로 연결한다
 *   — 같은 출처(same-origin) 전제, 다른 출처의 iframe은 브라우저가
 *   접근 자체를 막는다.
 * - `getValue(field)`는 먼저 `transDom2Obj(field)`로 화면 값을 `_data`에
 *   흡수한 뒤 그 값을 반환한다. `setValue(field, value)`는 `_data`에
 *   쓴 뒤 `transObj2Dom(field)`로 화면에 반영한다. `getAllValues()`(=
 *   `save()`가 보내는 payload)는 스키마의 모든 필드에 대해 `getValue()`
 *   를 호출해 모으므로, 결과적으로 "화면에 있는 값은 최신 DOM, 없는
 *   값은 객체" 조합이 항상 자동으로 이뤄진다. `save()`는 그 위에 더해,
 *   서버로 보내기 직전에 스키마의 모든 필드에 대해 `transDom2Obj()`를
 *   한 번 더 명시적으로 돌려 "화면에서 객체로 값을 흡수하는" 단계를
 *   코드로도 분명하게 드러낸다.
 * - `getJson()` — 지금 `_data`(단일 레코드)/`_recordSet`+`_cursor`(다중
 *   레코드 모드)에 실제로 들고 있는 내용을 서버 `Crud::toJson()`과 같은
 *   `{schema, cursor, data}` 모양의 JSON 문자열로 돌려준다(디버깅용 —
 *   화면에 안 보이는 값도 이 함수로 확인 가능).
 * - [2026-09-15 추가] 숫자형 필드(스키마 `type`이 `_isNumericType()`에
 *   걸리는 `int`/`decimal`/`numeric`/`float`/`real`/`money`/`bit` 계열)는
 *   `transObj2Dom()`(객체 -> DOM)이 천단위 콤마를 자동으로 붙이고,
 *   `transDom2Obj()`(DOM -> 객체)이 그 콤마를 다시 지운다 — 소비자
 *   화면은 서식을 신경 쓸 필요가 없고, `_data`/`save()` payload는 항상
 *   콤마 없는 순수 숫자 문자열이다.
 *
 * - save()는 화면(DOM에 있든 없든 `_data`에 있는 모든 필드)의 값을 모아
 *   그대로 서버에 보낸다 — 필드 단위 쓰기 화이트리스트는 이 클래스에는
 *   없다(서버가 전해준 스키마가 이미 화이트리스트로 좁혀져 있으므로,
 *   이 클래스는 그 스키마를 그대로 따라간다). 서버쪽
 *   crudjs_fetch.php의 화이트리스트는 그대로 유지 — 알려진 위험은
 *   crudjs_fetch.php 상단 docblock과 위 md 파일 참고.
 * - save/delete/selectRecord/clear 각각 _before/_after 훅을 갖고,
 *   소비자가 인스턴스에 직접 함수를 대입해서 주입한다(Popup.onAction과
 *   동일한 관용구) — validate()도 같은 방식으로 주입, save() 내부에서
 *   save_before 단계로 호출된다((3)/(4)). [2026-10-30] _before 훅은
 *   await로 기다린다 — 동기 함수도 되고, 서버에 물어봐야 하면 async
 *   함수(Promise)를 돌려줘도 된다. 결과가 `=== false`일 때만 취소다.
 *   그래서 save/deleteRecord/selectRecord/deleteBulk뿐 아니라 clear()와
 *   loadRecord()도 Promise<boolean>을 돌려준다. validate()는 여전히 동기.
 * - validateSchema()는 소비자가 안 만들어도 항상 도는 검사다 — 서버가
 *   전해준 스키마의 nullable/maxLength/type만 보고 "DB가 어차피 거부할
 *   값"을 미리 걸러낸다. 소비자의 validate()는 이 검사로는 못 잡는
 *   업무 규칙(다른 필드와의 관계, 날짜 범위 등)만 추가로 보면 된다 —
 *   둘은 서로 다른 층이라 save()가 순서대로(스키마 → validate) 둘 다
 *   돈다.
 */
class CrudJs {
    /**
     * @param {Object} options
     * @param {string} options.fetchUrl 서버 crudjs_fetch.php의 URL
     * @param {Object} options.schema Crud::getSchema()가 반환하는 형태
     *   ({tableName, primaryKey, fields: {필드명: {...}}})
     * @param {Window|HTMLIFrameElement} [options.window] 화면(입력칸이
     *   있는 문서)의 참조값. 생략하면 화면 없이(headless) `_data`만
     *   갖고 동작한다. 주면 생성자가 그대로 `setWindow()`에 위임한다 —
     *   자세한 동작은 `setWindow()` 참고.
     * @param {string} [options.containerId] hidden 노드를 붙일 컨테이너
     *   id. 생략하면 화면 문서의 `body`.
     * @param {boolean} [options.showHidden=false] true면 화면에 없는
     *   필드마다 hidden input을 만들어 붙인다(디버깅용). 기본은 false —
     *   화면에 안 그린 필드는 DOM에 전혀 안 나타나고 `_data` 안에만
     *   존재한다.
     * @param {Object<string,string>} [options.actionNames] [2026-09-14
     *   신설] `_actionUrl()`이 조립하는 `action=` 값을 이 맵으로
     *   갈아끼운다 — 예: `{schema: 'getSchema매출', save: 'save매출'}`.
     *   여러 테이블이 fetch 파일 하나를 공유하되(`deco_amd_amd_fetch.php`)
     *   각 테이블마다 완전히 다른 액션 이름을 쓰는 경우(제네릭
     *   `?target=` 구분자 대신)를 위함 — 매핑에 없는 키는 그대로(예:
     *   `save`) 쓴다.
     * @param {string} [options.selectRecordParam='pk'] [2026-09-15 신설]
     *   `selectRecord(key)`가 값을 실어 보낼 때 쓰는 쿼리스트링 파라미터
     *   이름. 기본은 PK 단건 조회를 뜻하는 `'pk'`.
     * @param {string} [options.selectRecordsParam] [2026-09-15 신설,
     *   selectRecordParam에서 분리] `selectRecords(filterValue)`가 값을
     *   실어 보낼 때 쓰는 쿼리스트링 파라미터 이름. 생략하면
     *   `options.selectRecordParam`을 그대로 물려받는다(한 인스턴스가
     *   selectRecord/selectRecords 중 하나만 쓸 때는 옵션 하나로 충분해
     *   하위 호환). 같은 인스턴스가 **둘 다** 쓰되 파라미터 이름이 서로
     *   달라야 하는 경우(예: obj매출내용 — PK 단건 조회는 `pk`, 같은
     *   매출code로 거르는 다건 조회는 `매출code`)를 위해 독립 지정을
     *   허용한다.
     */
    constructor(options) {
        options = options || {};

        this.fetchUrl = options.fetchUrl;
        this.schema = options.schema || { tableName: null, primaryKey: null, fields: {} };
        this.showHidden = Boolean(options.showHidden);
        this._containerId = options.containerId || null;
        this._actionNames = options.actionNames || null;
        this._selectRecordParam = options.selectRecordParam || 'pk';
        this._selectRecordsParam = options.selectRecordsParam || this._selectRecordParam;

        // 화면 참조 — setWindow()가 실제로 연결하기 전까지는 전부 null
        // (headless). transObj2Dom()/transDom2Obj()는 이 값이 null이면
        // 조용히 아무 일도 안 한다.
        this.screenWindow = null;
        this.screenDocument = null;
        this.containerEl = null;

        // 값의 실제 저장소 — 서버 Crud::$data와 같은 역할.
        this._data = this._blankData();

        // (3) 이벤트 훅 — 전부 소비자가 나중에 직접 대입해서 주입한다.
        this.save_before = null;
        this.save_after = null;
        this.delete_before = null;
        this.delete_after = null;
        this.selectRecord_before = null;
        this.selectRecord_after = null;
        this.clear_before = null;
        this.clear_after = null;

        // (4) validate — save_before 단계에서 이 함수가 있으면 먼저 호출된다.
        // 실패로 판단되면(false 반환) this._validationMessage를 getError()가 돌려준다.
        this.validate = null;
        this._validationMessage = null;

        this._lastError = null;

        // 다중 레코드(커서) 상태 — Crud.php의 ADO Recordset 방식과
        // 동일한 의미: _recordSet(배열) + _cursor(-1=BOF, length=EOF).
        // selectRecord()도 내부적으로 "레코드셋 크기가 1인 경우"로
        // 다뤄진다.
        this._recordSet = [];
        this._cursor = -1;
        // [2026-09-14 후속] _dirtyKeys는 PK가 아니라 _recordSet 안에서의
        // "배열 인덱스"를 문자열화한 키로 쓴다 — PK가 아직 없는(=서버에
        // 한 번도 저장 안 된) 신규 행도 dirty로 표시할 수 있어야
        // saveBulk()가 그 행을 새로 INSERT할 수 있기 때문(PK를 키로
        // 쓰면 PK가 null인 신규 행은 애초에 식별 불가능했음). 반면
        // _deletedKeys(markDeleted())는 여전히 PK를 키로 쓴다 — 서버에
        // 이미 존재하는(=PK가 있는) 행만 "삭제"라는 개념이 성립하기
        // 때문(저장된 적 없는 행은 그냥 _recordSet에서 빼면 될 뿐 서버에
        // 지울 대상이 없음).
        this._dirtyKeys = {};
        this._deletedKeys = {};

        if (options.window) {
            this.setWindow(options.window);
        }
        // options.window가 없으면 여기서 끝 — headless, _data만으로 동작.
    }

    /**
     * setWindow(windowOrIframeEl) — 화면(입력칸이 있는 문서)을 참조시킨다.
     * 이후 render()/transObj2Dom()/transDom2Obj()가 이 화면을 대상으로
     * 동작한다.
     * - `Window`를 바로 주면(이미 로드가 끝난 페이지, 보통 현재 페이지의
     *   `window`) 즉시 연결한다.
     * - `<iframe>` 엘리먼트를 주면, 그 iframe이 자기 문서 로드를 끝낼
     *   때까지(`load` 이벤트) 기다렸다가 연결한다 — 이미 로드가 끝나
     *   있는 상태(부모가 `load` 이벤트보다 늦게 이 메서드를 부른 경우)도
     *   함께 처리해 곧바로 연결한다. 같은 출처(same-origin) 전제 — 다른
     *   출처의 iframe은 브라우저가 `contentWindow`/`contentDocument`
     *   접근 자체를 막는다.
     * 연결이 끝나면(즉시든, iframe 로드를 기다린 뒤든) render()를 한 번
     * 불러 그 시점까지 `_data`에 쌓인 값을 화면에 반영한다 — 연결을
     * 기다리는 동안 setValue()로 값을 미리 채워둬도 데이터가 새지 않고,
     * 연결되는 순간 한꺼번에 화면에 나타난다.
     * @param {Window|HTMLIFrameElement} windowOrIframeEl
     */
    setWindow(windowOrIframeEl) {
        var self = this;

        if (!this._isIframeElement(windowOrIframeEl)) {
            this._bindScreen(windowOrIframeEl);
            return;
        }

        var iframeEl = windowOrIframeEl;
        var existingDoc = iframeEl.contentDocument
            || (iframeEl.contentWindow && iframeEl.contentWindow.document);
        if (existingDoc && existingDoc.readyState === 'complete') {
            this._bindScreen(iframeEl.contentWindow);
            return;
        }

        iframeEl.addEventListener(
            'load',
            function () {
                self._bindScreen(iframeEl.contentWindow);
            },
            { once: true }
        );
    }

    /**
     * @param {*} ref
     * @returns {boolean}
     */
    _isIframeElement(ref) {
        return Boolean(ref) && ref.tagName === 'IFRAME';
    }

    /**
     * 이미 사용 가능한 window로 실제 연결을 수행한다(setWindow() 내부
     * 헬퍼) — screenWindow/screenDocument/containerEl을 채우고 render()
     * 한 번으로 지금까지의 _data를 화면에 반영한다.
     * @param {Window} win
     */
    _bindScreen(win) {
        this.screenWindow = win;
        this.screenDocument = win.document;
        if (this._containerId) {
            this.containerEl = this.screenDocument.getElementById(this._containerId);
        } else {
            this.containerEl = this.screenDocument.body;
        }
        this.render();
    }

    /**
     * 스키마의 모든 필드를 null로 채운 빈 데이터 객체를 만든다(서버
     * Crud::clear()와 같은 역할).
     * @returns {Object<string, null>}
     */
    _blankData() {
        var data = {};
        var fields = this.schema.fields || {};
        Object.keys(fields).forEach(function (field) {
            data[field] = null;
        });
        return data;
    }

    /**
     * @param {string} field
     */
    _assertField(field) {
        if (!Object.prototype.hasOwnProperty.call(this.schema.fields || {}, field)) {
            throw new Error('존재하지 않는 필드입니다: ' + field);
        }
    }

    /**
     * [2026-09-14 후속] save()/selectRecord() 등 서버를 부르는 모든
     * 메서드가 URL을 조립할 때 이 함수를 거친다 — `this.fetchUrl`이
     * `crudjs_fetch.php`처럼 쿼리스트링이 아예 없는 경우뿐 아니라,
     * 이미 쿼리스트링을 물고 있는 경우에도 안전하게 `action=` 파라미터를
     * 이어붙인다 — `?`가 이미 있으면 `&`로, 없으면 `?`로 잇는다.
     * [2026-09-14 추가 후속] `this._actionNames`(생성자 `options.
     * actionNames`)에 이 논리 이름(`action`, 예: `'save'`)이 있으면
     * 실제 URL엔 그 매핑값(예: `'save매출'`)을 쓴다 — `deco_amd_amd_fetch.php`
     * 처럼 테이블별로 액션 이름이 완전히 분리된 엔드포인트를 여러
     * `CrudJs` 인스턴스가 공유할 때를 위함(제네릭 `?target=` 구분자 대신).
     * @param {string} action
     * @returns {string}
     */
    _actionUrl(action) {
        var actualAction = action;
        if (this._actionNames && Object.prototype.hasOwnProperty.call(this._actionNames, action)) {
            actualAction = this._actionNames[action];
        }
        var separator = this.fetchUrl.indexOf('?') === -1 ? '?' : '&';
        return this.fetchUrl + separator + 'action=' + actualAction;
    }

    /**
     * render() — _data에 있는 모든 필드 값을 화면의 id 노드에 뿌린다
     * (객체 -> DOM, transObj2Dom()에 위임). 최초 로드/selectRecord·
     * selectRecords 응답 반영/커서 이동/clear() 등 "화면을 다시
     * 그려야 하는" 모든 시점에 이 함수 하나만 부르면 된다.
     */
    render() {
        var self = this;
        Object.keys(this.schema.fields || {}).forEach(function (field) {
            self.transObj2Dom(field);
        });
    }

    /**
     * transObj2Dom(field) — 객체(_data) -> DOM, 필드 하나만 반영한다.
     * setWindow()로 화면이 연결돼 있지 않으면(headless) 조용히 아무
     * 일도 안 한다. 화면이 있으면: 이미 그 id 노드가 있으면 그대로 쓰고,
     * 없으면 showHidden이 켜져 있을 때만 hidden input을 새로 만들어
     * 붙인다(꺼져 있으면 그 필드는 DOM에 전혀 안 나타나고 _data 안에만
     * 존재한다). render()가 스키마의 모든 필드에 대해 이 함수를 호출한다.
     * @param {string} field
     */
    transObj2Dom(field) {
        if (!this.screenDocument) {
            return;
        }
        var el = this.screenDocument.getElementById(field);
        if (!el) {
            if (!this.showHidden) {
                return;
            }
            el = this.screenDocument.createElement('input');
            el.type = 'hidden';
            el.id = field;
            this.containerEl.appendChild(el);
        }
        this._writeValueToEl(el, this._data[field], field);
    }

    /**
     * transDom2Obj(field) — DOM -> 객체(_data), 필드 하나만 반영한다.
     * 그 필드에 해당하는 id 노드가 화면에 있으면 지금 DOM에 있는 값을
     * 읽어 _data[field]에 반영한다(사용자가 화면 입력칸에 직접 타이핑한
     * 값을 잡아내는 지점). 노드가 없으면 _data는 손대지 않는다(가져올
     * DOM 값이 없으므로). transObj2Dom()과 짝을 이루는 반대 방향 함수 —
     * getValue()가 이 함수로 "읽기 전에 먼저 화면 값을 흡수"하고,
     * save()도 서버에 보내기 직전에 스키마 전체 필드에 대해 이 함수를
     * 돌려 화면의 최신 값을 명시적으로 흡수한다.
     * headless(화면이 setWindow()로 연결돼 있지 않음)일 때도 노드가
     * 없는 것과 같은 이유로 _data를 그대로 둔다.
     * @param {string} field
     */
    transDom2Obj(field) {
        if (!this.screenDocument) {
            return;
        }
        var el = this.screenDocument.getElementById(field);
        if (!el) {
            return;
        }
        this._data[field] = this._readValueFromEl(el, field);
    }

    /**
     * [2026-09-15 추가] "crudjs 에 숫자형 필드를 render할 때는 기본적으로
     * 천단위 콤마가 찍히게" 지시 — 이전엔 화면(소비자 코드)이
     * `_setValueX(_getValueX())`처럼 조회한 값을 스스로 다시 넣어
     * 포맷하는 우회를 써야 했다(비효율+ 화면마다 반복). 이제
     * `_writeValueToEl()`/`_readValueFromEl()`가 `field`의 스키마
     * `type`을 보고 숫자형(`_isNumericType()`, `validateSchema()`가
     * 이미 쓰던 판정 재사용)이면 자동으로 포맷/역포맷한다 — 소비자는
     * 아무것도 안 해도 된다.
     * @param {string|number|null|undefined} value
     * @returns {string|number|null|undefined}
     */
    _formatNumberForDisplay(value) {
        if (value === null || value === undefined || value === '') {
            return value;
        }
        var digits = String(value).replace(/,/g, '');
        if (isNaN(Number(digits))) {
            return value;
        }
        var parts = digits.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return parts.join('.');
    }

    /**
     * [2026-09-15 추가] 위 포맷의 역방향 — 화면에 찍힌 콤마를 지워 서버로
     * 보낼 값(그리고 `Number(value)`로 검사하는 `validateSchema()`)이
     * 다시 순수 숫자 문자열이 되게 한다.
     * @param {string|number|null|undefined} value
     * @returns {string|number|null|undefined}
     */
    _unformatNumber(value) {
        if (value === null || value === undefined) {
            return value;
        }
        return String(value).replace(/,/g, '');
    }

    /**
     * @param {HTMLElement} el
     * @param {*} value
     * @param {string} [field] 주어지면(스키마에 있으면) 숫자형 타입일 때
     *   천단위 콤마를 붙인다 — 생략(또는 스키마에 없는 필드)하면 포맷 없이
     *   그대로.
     */
    _writeValueToEl(el, value, field) {
        var displayValue;
        if (value === null || value === undefined) {
            displayValue = '';
        } else {
            displayValue = value;
        }

        var meta = field ? (this.schema.fields || {})[field] : null;
        if (meta && this._isNumericType(meta.type)) {
            displayValue = this._formatNumberForDisplay(displayValue);
        }
        // [2026-10-28 추가] 날짜/시각 필드는 'YYYY-MM-DD'(시각 있으면 ' HH:MM')로 보인다.
        if (meta && this._dateKind(meta.type) !== null) {
            displayValue = this._formatDateValue(field, displayValue);
            // <input type="date">는 날짜만, type="datetime-local"은 'YYYY-MM-DDTHH:MM'만 받는다.
            if (el.tagName === 'INPUT' && el.type === 'date') {
                displayValue = String(displayValue).substring(0, 10);
            } else if (el.tagName === 'INPUT' && el.type === 'datetime-local' && displayValue !== '') {
                displayValue = String(displayValue).length === 10
                    ? displayValue + 'T00:00'
                    : String(displayValue).replace(' ', 'T');
            }
        }

        if (el.tagName === 'INPUT' && el.type === 'checkbox') {
            // [2026-10-28 수정] Boolean(value)는 서버의 '0'(글자)도 참으로 봐서 늘 체크됐다.
            el.checked = this._toCheckboxState(value);
        } else if ('value' in el) {
            el.value = displayValue;
        } else {
            el.textContent = displayValue;
        }
    }

    /**
     * @param {HTMLElement} el
     * @param {string} [field] 주어지면(스키마에 있으면) 숫자형 타입일 때
     *   화면의 콤마를 지우고 반환한다(_writeValueToEl의 반대 방향).
     * @returns {*}
     */
    _readValueFromEl(el, field) {
        var meta = field ? (this.schema.fields || {})[field] : null;

        if (el.tagName === 'INPUT' && el.type === 'checkbox') {
            // [2026-10-28 수정] 숫자형(bit 등) 필드면 1/0으로 — true/false로 보내면
            // 서버 bit 변환이 DB마다 다르고, 서버에서 온 '1'/'0'과 비교도 안 맞는다.
            if (meta && this._isNumericType(meta.type)) {
                return el.checked ? 1 : 0;
            }
            return el.checked;
        }
        var raw;
        if ('value' in el) {
            raw = el.value;
        } else {
            raw = el.textContent;
        }

        if (meta && this._isNumericType(meta.type)) {
            return this._unformatNumber(raw);
        }
        // [2026-10-28 추가] 날짜/시각은 표준 글자로 — datetime-local의 'T'도 공백으로 바뀐다.
        if (meta && this._dateKind(meta.type) !== null) {
            return this._formatDateValue(field, raw);
        }
        return raw;
    }

    /**
     * setValue(field, value) — 내부 _data에 값을 쓰고,
     * transObj2Dom(field)으로 그 필드에 해당하는 id 노드(원래 있던
     * 것이든 showHidden으로 만들어진 것이든)에도 즉시 반영한다.
     */
    setValue(field, value) {
        this._assertField(field);
        this._data[field] = value;
        this.transObj2Dom(field);
    }

    /**
     * getValue(field) — transDom2Obj(field)로 화면 값을 먼저 _data에
     * 흡수한 뒤(그 필드에 해당하는 id 노드가 있으면 사용자가 직접
     * 타이핑한 값을 잡아냄, 없으면 _data는 그대로) 그 결과를 반환한다.
     */
    getValue(field) {
        this._assertField(field);
        this.transDom2Obj(field);
        return this._data[field];
    }

    /**
     * getError() — 가장 최근에 실패한 작업(save/delete/selectRecord/
     * selectRecords/saveBulk/clear/loadSchema)의 오류 메시지를 돌려준다.
     * 각 메서드가 시작할 때 `this._lastError`를 먼저 `null`로 리셋하므로,
     * 성공한 뒤에는 다시 `null`이 된다.
     * [2026-09-14, 버그 발견/수정] `crudjs_entry.php`가 이미 6곳에서
     * `crudjs.getError()`를 호출하고 있었고, 코드 안 주석 2곳(115행/1039행
     * 근처)도 이 메서드가 있다고 전제하고 쓰여 있었는데, 정작 이 메서드
     * 자체가 정의돼 있지 않았다(호출 시 `TypeError: crudjs.getError is
     * not a function`으로 죽었을 자리) — 이번에 `init()`/`loadSchema()`
     * 작업 중 필요해져 발견, 뒤늦게 추가.
     * @returns {string|null}
     */
    getError() {
        return this._lastError;
    }

    /**
     * 스키마의 모든 필드(화이트리스트로 좁혀진 그 목록) 현재 값을
     * {필드명: 값} 객체로 모은다 — 화면에 입력칸이 있는 필드는
     * getValue()가 그 자리에서 DOM 값을 읽어와(사용자가 방금 타이핑한
     * 값 포함) _data에도 동기화한다. save()가 서버로 그대로 보내는
     * payload가 이것이다.
     */
    getAllValues() {
        var self = this;
        var result = {};
        Object.keys(this.schema.fields || {}).forEach(function (field) {
            result[field] = self.getValue(field);
        });
        return result;
    }

    /**
     * getRecordSet() — [2026-09-15 신설] 지금 커서(다중 레코드) 모드로
     * 들고 있는 행 전체를 배열로 돌려준다(원본을 직접 건드리지 못하게
     * 얕은 복사). `selectRecords()`/`selectRecord()` 응답을 그대로
     * 보관하는 `_recordSet`을 소비자(화면)가 자기만의 방식으로 그려야
     * 할 때(예: id=필드명 자동 바인딩이 아니라 ag-grid처럼 여러 행을
     * 한 번에 그리는 화면) 쓴다 — id=필드명 렌더링(`render()`)과는
     * 별개의, "값만 필요하다" 용도.
     * @returns {Array<Object>}
     */
    getRecordSet() {
        return this._recordSet.slice();
    }

    /**
     * getJson() — 지금 이 인스턴스가 실제로 들고 있는 내용을 서버
     * Crud::toJson()과 같은 {schema, cursor, data} 모양의 JSON 문자열로
     * 돌려준다(디버깅용 — 화면에 안 보이는 값도 이 함수로 확인 가능).
     * 다중 레코드(커서) 모드로 여러 행을 들고 있으면(_recordSet이
     * 비어있지 않으면) 그 전체를, 아니면 현재 단일 레코드(_data, 화면
     * 값 포함해 최신화한 getAllValues())를 1개짜리 배열로 담는다.
     * @returns {string}
     */
    getJson() {
        var records;
        if (this._recordSet.length > 0) {
            records = this._recordSet;
        } else {
            records = [this.getAllValues()];
        }
        var payload = {
            schema: this.schema,
            cursor: this._cursor,
            data: records,
        };
        return JSON.stringify(payload, null, 2);
    }

    /**
     * clear() — 모든 필드를 빈 값으로 되돌린다(스키마 정보(`this.schema`)
     * 는 전혀 안 건드림 — 서버 `Crud::clear()`와 같은 원칙). 서버 왕복이
     * 없지만 clear_before(async 가능)를 기다려야 해서 Promise를 돌려준다.
     * clear_before가 false를 반환하면 save_before/delete_before/selectRecord_before와
     * 동일하게 그 자리에서 멈추고 실제로는 아무 것도 안 지운다.
     * [2026-09-14 후속] 단일 레코드(_data)뿐 아니라 다중 레코드(커서)
     * 모드 상태(_recordSet/_cursor/_dirtyKeys/_deletedKeys)도 함께
     * 비운다 — clear()는 이제 "지금까지 조회해둔 것을 전부 잊고 완전히
     * 처음 상태로 돌아간다"는 뜻이다(서버 왕복 없는 순수 클라이언트
     * 초기화). 커서 위치/건수를 화면에 별도로 표시하는 소비자(예:
     * crudjs_entry.php의 다중 레코드 모드 UI)는 clear() 이후 그 표시도
     * 직접 갱신해야 한다(crudjs 자신은 그 UI를 모른다).
     * @returns {Promise<boolean>} 실제로 초기화됐으면 true, clear_before가 막았으면 false
     */
    async clear() {
        this._lastError = null;

        if (typeof this.clear_before === 'function') {
            if ((await this.clear_before()) === false) {
                if (!this._lastError) {
                    this._lastError = '화면 초기화가 취소되었습니다(clear_before).';
                }
                return false;
            }
        }

        this._data = this._blankData();
        this._recordSet = [];
        this._cursor = -1;
        this._dirtyKeys = {};
        this._deletedKeys = {};
        this.render();

        if (typeof this.clear_after === 'function') {
            this.clear_after();
        }
        return true;
    }

    /**
     * 스키마의 제약조건(nullable/maxLength/type)만으로 현재 화면 값을
     * 검사한다. 특정 화면의 업무 규칙이 아니라 "DB가 어차피 거부할 값"을
     * 미리 걸러내는 최소한의 구조적 검사 — save()가 매번 자동으로 이걸
     * 먼저 돌리고, 필요하면 소비자가 직접 불러도 된다(예: 입력 중 실시간
     * 안내).
     * @returns {string[]} 위반 사항 메시지 배열(문제 없으면 빈 배열)
     */
    validateSchema() {
        var self = this;
        var errors = [];
        var fields = this.schema.fields || {};

        Object.keys(fields).forEach(function (field) {
            var meta = fields[field] || {};
            var value = self.getValue(field);
            var isEmpty = value === null || value === undefined || value === '';

            // PK가 비어있는 건 "아직 저장 안 된 새 레코드"의 정상
            // 상태라 필수값 검사 대상에서 뺀다(서버 Crud::insert()도
            // 이 경우를 그렇게 다룬다 — autoIncrement PK가 비어있으면
            // 컬럼 자체를 SQL에서 뺌).
            if (meta.isPrimaryKey && isEmpty) {
                return;
            }

            // NOT NULL인데 DB 기본값도 없고 비어있으면 필수 입력 누락.
            if (meta.nullable === false && isEmpty && meta.default === null) {
                errors.push(field + ': 값을 입력해야 합니다.');
                return;
            }

            if (isEmpty) {
                // nullable 필드가 비어있는 건 정상 — 길이/타입 검사는
                // 값이 있을 때만 의미가 있다.
                return;
            }

            // [2026-09-17 수정, 버그 발견] `meta.maxLength`가 0 이하(특히
            // -1)면 "제한 없음"으로 다룬다 — MSSQL의 NVARCHAR(MAX) 계열
            // 컬럼은 CHARACTER_MAXIMUM_LENGTH가 -1인데(SqlDialectMssql
            // 이 이제 이걸 null로 정규화해 보내지만, 방어적으로 여기서도
            // 한 번 더 막는다), 이전엔 `meta.maxLength &&`만 보고
            // -1도 참으로 취급해 어떤 값을 넣어도(빈 문자열조차) 항상
            // "길이를 넘었다"고 오판했다(deco_amd_income_popup.php의
            // 메모란에 값을 넣으면 매번 "메모: -1자를 넘었습니다"로
            // 저장이 막히던 문제로 발견됨).
            if (meta.maxLength && meta.maxLength > 0 && String(value).length > meta.maxLength) {
                errors.push(field + ': ' + meta.maxLength + '자를 넘었습니다.');
            }

            // [2026-10-28 수정] 숫자 판정을 `isNaN(Number(value))`에서 타입별
            // 형식 검사로 바꿨다 — Number()는 '1e3'/'0x1A'/'Infinity'/' '도
            // 숫자로 받아줘 DB에서야 오류가 났다. 정수형은 정수만, bit는
            // 0/1(체크박스의 true/false 포함)만 받는다(_isValidNumberValue()).
            var numericKind = self._numericKind(meta.type);
            if (numericKind !== null && !self._isValidNumberValue(numericKind, value)) {
                var numericMessage = {
                    bit: '0 또는 1이어야 합니다.',
                    integer: '정수여야 합니다.',
                };
                errors.push(field + ': ' + (numericMessage[numericKind] || '숫자여야 합니다.'));
            }

            // [2026-10-28 추가] 날짜/시각 형식 검사 — 전에는 아예 없어
            // '2026-13-45'/'abc'도 서버까지 갔다(_isValidDateValue()).
            var dateKind = self._dateKind(meta.type);
            if (dateKind !== null && !self._isValidDateValue(dateKind, value)) {
                errors.push(field + ': ' + (dateKind === 'time' ? '시각 형식(HH:MM)이 아닙니다.' : '날짜 형식(YYYY-MM-DD)이 아니거나 없는 날짜입니다.'));
            }
        });

        return errors;
    }

    /**
     * [2026-10-28 신설] 숫자형 타입을 검사 종류로 나눈다 — 'bit'(0/1),
     * 'integer'(int 계열), 'float'(float/real — 지수표기 허용), 'decimal'
     * (decimal/numeric/money). 숫자형이 아니면 null. 판정 범위는
     * _isNumericType()과 같다(그 함수가 true인 타입은 여기서도 null이 아니다).
     * @param {string|null|undefined} type
     * @returns {('bit'|'integer'|'float'|'decimal'|null)}
     */
    _numericKind(type) {
        if (!this._isNumericType(type)) {
            return null;
        }
        var lower = String(type).toLowerCase();
        if (lower.indexOf('bit') !== -1) {
            return 'bit';
        }
        if (lower.indexOf('int') !== -1) {
            return 'integer';
        }
        if (lower.indexOf('float') !== -1 || lower.indexOf('real') !== -1) {
            return 'float';
        }
        return 'decimal';
    }

    /**
     * [2026-10-28 신설] 숫자 값이 그 종류의 형식에 맞는지. 콤마는 이미
     * _readValueFromEl()이 지운 뒤라 여기선 보지 않는다. 앞뒤 공백은 허용.
     * @param {string} kind _numericKind()의 결과
     * @param {*} value 비어 있지 않은 값
     * @returns {boolean}
     */
    _isValidNumberValue(kind, value) {
        var text = String(value).trim();
        switch (kind) {
            case 'bit':
                return /^(0|1|true|false)$/i.test(text);
            case 'integer':
                return /^[-+]?\d+$/.test(text);
            case 'float':
                return /^[-+]?(\d+(\.\d*)?|\.\d+)([eE][-+]?\d+)?$/.test(text);
            default:
                return /^[-+]?(\d+(\.\d*)?|\.\d+)$/.test(text);
        }
    }

    /**
     * [2026-10-28 신설] 날짜/시각 타입이면 검사 종류를, 아니면 null.
     * 'time'(time) / 'date'(date, datetime, smalldatetime, datetime2,
     * datetimeoffset, MySQL timestamp). MSSQL의 timestamp(rowversion)는
     * Crud가 스키마에서 이미 뺀다(EXCLUDED_FIELDS).
     * @param {string|null|undefined} type
     * @returns {('date'|'time'|null)}
     */
    _dateKind(type) {
        if (!type) {
            return null;
        }
        var lower = String(type).toLowerCase();
        if (lower === 'time' || lower.indexOf('time(') === 0) {
            return 'time';
        }
        if (lower.indexOf('date') !== -1 || lower === 'timestamp') {
            return 'date';
        }
        return null;
    }

    /**
     * [2026-10-28 신설] 날짜/시각 값이 형식에 맞고 실제로 있는 날짜인지.
     * 받는 형식:
     * - 'YYYY-MM-DD'(구분자 - / . 허용) + 선택적으로 ' HH:MM[:SS[.fff]]'
     *   (T 구분, 끝의 Z/+09:00도 허용) — 화면에서 입력하는 형식.
     * - 'Sep 30 2026 12:00:00:000AM' — Crud가 MSSQL(pdo_dblib)에서 읽은 값을
     *   그대로 내려보내는 형식. 이걸 막으면 날짜 필드가 있는 기존 레코드를
     *   손대지 않고 다시 저장해도 걸린다.
     * time 타입은 'HH:MM[:SS[.fff]]'(dblib의 끝 AM/PM 포함)만 받는다.
     * @param {string} kind _dateKind()의 결과
     * @param {*} value 비어 있지 않은 값
     * @returns {boolean}
     */
    _isValidDateValue(kind, value) {
        return this._parseDateValue(kind, value) !== null;
    }

    /**
     * [2026-10-28 신설] 서버에서 받은 행들을 화면이 쓰는 표준 값으로 바꾼다(제자리에서).
     * _loadRecordSet()이 서버 응답을 받자마자 부른다.
     * - 날짜/시각 필드 → _formatDateValue()의 표준 글자('YYYY-MM-DD' 등)
     * - bit 필드의 true/false → 1/0(체크박스가 읽어 오는 값과 같게)
     * @param {object[]} rows
     */
    _normalizeLoadedRows(rows) {
        var fields = this.schema.fields || {};
        var names = Object.keys(fields);
        var row;
        var name;
        var i;
        var j;

        for (i = 0; i < rows.length; i++) {
            row = rows[i];
            for (j = 0; j < names.length; j++) {
                name = names[j];
                if (!Object.prototype.hasOwnProperty.call(row, name)) {
                    continue;
                }
                if (this._dateKind(fields[name].type) !== null) {
                    row[name] = this._formatDateValue(name, row[name]);
                } else if (this._numericKind(fields[name].type) === 'bit') {
                    if (row[name] === true) {
                        row[name] = 1;
                    } else if (row[name] === false) {
                        row[name] = 0;
                    }
                }
            }
        }
    }

    /**
     * [2026-10-28 신설, _isValidDateValue()에서 분리] 날짜/시각 글자를 나눠 읽는다 —
     * 검사(_isValidDateValue)와 표시 서식(_formatDateValue)이 같이 쓴다.
     * 받는 형식은 _isValidDateValue() 설명과 같다. 형식이 틀리거나 없는 날짜면 null.
     * @param {string} kind _dateKind()의 결과('date'|'time')
     * @param {*} value
     * @returns {{year: number, month: number, day: number, hour: number, minute: number,
     *   second: number, hasTime: boolean}|null} time 종류면 year/month/day는 0.
     */
    _parseDateValue(kind, value) {
        var text = String(value).trim();
        var months = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
        // 시각 부분 — ampm이 있으면 12시간제(1~12), 없으면 24시간제(0~23). 틀리면 null.
        var readTime = function (hour, minute, second, ampm) {
            var h = Number(hour);
            var mi = Number(minute);
            var s = second === undefined ? 0 : Number(second);
            if (ampm) {
                if (h < 1 || h > 12) {
                    return null;
                }
                h = h % 12;
                if (ampm.toLowerCase() === 'pm') {
                    h = h + 12;
                }
            } else if (h > 23) {
                return null;
            }
            if (mi > 59 || s > 59) {
                return null;
            }
            return { hour: h, minute: mi, second: s };
        };
        // 날짜 부분 — 실제로 있는 날짜인지(2월 30일 등은 null).
        var isRealDate = function (y, mo, d) {
            var date = new Date(Date.UTC(y, mo - 1, d));
            return mo >= 1 && mo <= 12 && date.getUTCFullYear() === y && date.getUTCMonth() === mo - 1 && date.getUTCDate() === d;
        };
        var m;
        var t;
        var y;
        var mo;
        var d;

        if (kind === 'time') {
            m = /^(\d{1,2}):(\d{2})(?::(\d{2})(?:[.:]\d{1,7})?)?\s*([AaPp][Mm])?$/.exec(text);
            if (!m) {
                return null;
            }
            t = readTime(m[1], m[2], m[3], m[4]);
            if (t === null) {
                return null;
            }
            return { year: 0, month: 0, day: 0, hour: t.hour, minute: t.minute, second: t.second, hasTime: true };
        }

        // 'YYYY-MM-DD[ HH:MM[:SS[.fff]]]'
        m = /^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2})(?:\.\d{1,7})?)?)?(?:\s*(?:Z|[+-]\d{2}:?\d{2}))?$/.exec(text);
        if (m) {
            y = Number(m[1]);
            mo = Number(m[2]);
            d = Number(m[3]);
            t = m[4] === undefined ? { hour: 0, minute: 0, second: 0 } : readTime(m[4], m[5], m[6], null);
        } else {
            // 'Sep 30 2026 12:00:00:000AM'(dblib)
            m = /^([A-Za-z]{3})\s+(\d{1,2})\s+(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2})(?:[.:]\d{1,7})?)?\s*([AaPp][Mm]))?$/.exec(text);
            if (!m) {
                return null;
            }
            y = Number(m[3]);
            mo = months.indexOf(m[1].toLowerCase()) + 1;
            d = Number(m[2]);
            t = m[4] === undefined ? { hour: 0, minute: 0, second: 0 } : readTime(m[4], m[5], m[6], m[7]);
        }

        if (t === null || !isRealDate(y, mo, d)) {
            return null;
        }
        return { year: y, month: mo, day: d, hour: t.hour, minute: t.minute, second: t.second, hasTime: m[4] !== undefined };
    }

    /**
     * [2026-10-28 신설] 날짜/시각 필드 값을 화면용 표준 글자로 바꾼다 — 서버가 dblib
     * 형식('Sep 30 2026 12:00:00:000AM')으로 보내도 화면에는 'YYYY-MM-DD'로 보인다.
     * - date 타입, 또는 시각이 00:00:00인 datetime → 'YYYY-MM-DD'
     * - 시각이 있는 datetime → 'YYYY-MM-DD HH:MM'(초가 0이 아니면 ':SS'까지)
     * - time 타입 → 'HH:MM'(초가 0이 아니면 ':SS'까지)
     * 이 글자는 MSSQL/MySQL이 그대로 받는 형식이라 저장에도 그대로 쓴다.
     * 날짜 필드가 아니거나, 비었거나, 읽을 수 없는 값이면 손대지 않고 돌려준다(그런
     * 값은 validateSchema()가 저장 때 걸러낸다).
     * @param {string} field
     * @param {*} value
     * @returns {*}
     */
    _formatDateValue(field, value) {
        var meta = (this.schema.fields || {})[field];
        var kind;
        var p;
        var two = function (n) {
            return n < 10 ? '0' + n : String(n);
        };
        var timeText;

        if (!meta || value === null || value === undefined || value === '') {
            return value;
        }
        kind = this._dateKind(meta.type);
        if (kind === null) {
            return value;
        }
        p = this._parseDateValue(kind, value);
        if (p === null) {
            return value;
        }

        timeText = two(p.hour) + ':' + two(p.minute);
        if (p.second !== 0) {
            timeText = timeText + ':' + two(p.second);
        }

        if (kind === 'time') {
            return timeText;
        }

        var dateText = p.year + '-' + two(p.month) + '-' + two(p.day);
        if (String(meta.type).toLowerCase() === 'date' || (p.hour === 0 && p.minute === 0 && p.second === 0)) {
            return dateText;
        }
        return dateText + ' ' + timeText;
    }

    /**
     * [2026-10-28 신설] 체크박스에 쓸 참/거짓 — 서버 bit 값은 '0'/'1' 글자로 오기도 해서
     * Boolean('0')(=true)로 보면 안 된다. 1/'1'/true/'true'/-1(Access의 참)/'y'는 참,
     * 그 밖(0/'0'/''/null/'false'/'n' …)은 거짓.
     * @param {*} value
     * @returns {boolean}
     */
    _toCheckboxState(value) {
        var text;

        if (value === true) {
            return true;
        }
        if (value === false || value === null || value === undefined) {
            return false;
        }
        text = String(value).trim().toLowerCase();
        return text === '1' || text === '-1' || text === 'true' || text === 'y' || text === 'yes';
    }

    /**
     * @param {string|null|undefined} type Crud::getSchema()가 돌려주는
     *   필드의 type(DB 원본 타입 문자열, 예: 'int'/'decimal(10,2)'/
     *   'varchar'). 'int'/'bigint'/'smallint'/'tinyint'는 전부 'int'
     *   부분일치로, 'money'/'smallmoney'는 'money' 부분일치로 잡힌다.
     * @returns {boolean}
     */
    _isNumericType(type) {
        if (!type) {
            return false;
        }
        var numericTypes = ['int', 'decimal', 'numeric', 'float', 'real', 'money', 'bit'];
        var lower = String(type).toLowerCase();
        for (var i = 0; i < numericTypes.length; i++) {
            if (lower.indexOf(numericTypes[i]) !== -1) {
                return true;
            }
        }
        return false;
    }

    /**
     * save() — (5) 그대로: 화면(DOM에 있든 없든 _data에 있는 모든 필드)의
     * 값 전체를 서버 crud에 넘기고, 서버가 PK 유무로 insert/update를
     * 알아서 정한다.
     * @returns {Promise<boolean>} 저장 성공 여부
     */
    async save() {
        var self = this;
        this._lastError = null;
        this._validationMessage = null;

        // 서버로 보내기 전, 화면(id 노드)에 있는 값을 전부 _data로
        // 흡수한다 — transDom2Obj()가 이 방향의 동기화를 전담한다.
        // validateSchema()/getAllValues()도 각자 getValue() 호출로 결국
        // 같은 흡수를 하지만, "저장 직전에 화면 값을 객체로 끌어들인다"는
        // 단계를 여기서 명시적으로 한 번 더 둔다.
        Object.keys(this.schema.fields || {}).forEach(function (field) {
            self.transDom2Obj(field);
        });

        // 스키마 제약(NOT NULL/길이/타입)을 가장 먼저 검사한다 — 소비자가
        // validate()를 안 정의해도 이 검사는 항상 돈다.
        var schemaErrors = this.validateSchema();
        if (schemaErrors.length > 0) {
            this._lastError = schemaErrors.join(' / ');
            return Promise.resolve(false);
        }

        // (4) validate는 save_before 단계에서 실행된다 — 스키마 제약을
        // 넘어서는 업무 규칙(다른 필드와의 관계, 날짜 범위 등)은 여기서.
        if (typeof this.validate === 'function') {
            if (this.validate() === false) {
                var message = this._validationMessage;
                if (!message) {
                    message = '유효성 검사에 실패했습니다.';
                }
                this._lastError = message;
                return Promise.resolve(false);
            }
        }

        if (typeof this.save_before === 'function') {
            if ((await this.save_before()) === false) {
                if (!this._lastError) {
                    this._lastError = '저장이 취소되었습니다(save_before).';
                }
                return Promise.resolve(false);
            }
        }

        var payload = this.getAllValues();

        return fetch(this._actionUrl('save'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.data || result.data.success !== true) {
                    var errorMessage = '저장에 실패했습니다.';
                    if (result.data && result.data.error) {
                        errorMessage = result.data.error;
                    }
                    self._lastError = errorMessage;
                    return false;
                }

                if (result.data.pk !== undefined && self.schema.primaryKey) {
                    self.setValue(self.schema.primaryKey, result.data.pk);
                }

                if (typeof self.save_after === 'function') {
                    self.save_after();
                }
                return true;
            })
            .catch(function (err) {
                self._lastError = String(err);
                return false;
            });
    }

    /**
     * deleteRecord() — 현재 화면의 PK 필드값으로 서버 레코드를 삭제한다.
     * @returns {Promise<boolean>}
     */
    async deleteRecord() {
        var self = this;
        this._lastError = null;

        if (typeof this.delete_before === 'function') {
            if ((await this.delete_before()) === false) {
                if (!this._lastError) {
                    this._lastError = '삭제가 취소되었습니다(delete_before).';
                }
                return Promise.resolve(false);
            }
        }

        if (!this.schema.primaryKey) {
            this._lastError = '이 스키마엔 프라이머리 키가 없습니다.';
            return Promise.resolve(false);
        }

        var pk = this.getValue(this.schema.primaryKey);
        if (!pk) {
            this._lastError = '삭제할 레코드의 PK 값이 없습니다.';
            return Promise.resolve(false);
        }

        return fetch(this._actionUrl('delete') + '&pk=' + encodeURIComponent(pk), {
            method: 'POST',
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.data || result.data.success !== true) {
                    var errorMessage = '삭제에 실패했습니다.';
                    if (result.data && result.data.error) {
                        errorMessage = result.data.error;
                    }
                    self._lastError = errorMessage;
                    return false;
                }

                if (typeof self.delete_after === 'function') {
                    self.delete_after();
                }
                return true;
            })
            .catch(function (err) {
                self._lastError = String(err);
                return false;
            });
    }

    /**
     * loadSchema() — [2026-09-14 신설] 서버에서 스키마만 가져와
     * `this.schema`를 채운다(값/레코드셋은 안 건드림). 지금까지는
     * `crudjs_entry.php`처럼 서버가 페이지를 그릴 때 스키마를 생성자
     * `options.schema`로 직접 심어주는 방식만 있었는데, `deco_amd_amd.php`
     * 처럼 화면 스크립트가 로드된 뒤 스스로 스키마를 물어와야 하는
     * 경우(초기화 시점에 스키마 인자를 안 넘기고 이 메서드로 채움)를
     * 위해 추가했다. 스키마가 바뀌면 화면도 그 스키마 기준으로 다시
     * 그려야 하므로 성공 시 `render()`도 함께 호출한다(이 시점엔
     * `_data`가 비어있어 전부 빈 값으로 그려질 뿐이지만, `showHidden`이
     * 켜져 있으면 새 스키마의 필드만큼 hidden 노드가 이때 만들어진다).
     * @returns {Promise<boolean>}
     */
    loadSchema() {
        var self = this;
        this._lastError = null;

        return fetch(this._actionUrl('schema'))
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.data || result.data.success !== true || !result.data.schema) {
                    var errorMessage = '스키마 조회에 실패했습니다.';
                    if (result.data && result.data.error) {
                        errorMessage = result.data.error;
                    }
                    self._lastError = errorMessage;
                    return false;
                }

                self.schema = result.data.schema;
                self.render();
                return true;
            })
            .catch(function (err) {
                self._lastError = String(err);
                return false;
            });
    }

    /**
     * selectRecord(key) — 서버 crud의 selectRecord를 호출하고, 응답받은
     * 레코드를 id=필드명 규칙대로 화면(있는 노드만, showHidden이면
     * hidden도)에 뿌린다. "레코드셋 크기가 1인 경우"로 다뤄진다 —
     * 내부적으로 this._recordSet=[그 행], this._cursor=0으로 저장하고,
     * 여러 행 조회(selectRecords())/커서 이동(moveNext() 등)과 같은
     * 렌더 단계(_renderCursorRow())를 그대로 탄다. 이전에 dirty/삭제
     * 표시가 남아있었다면(다른 레코드셋을 보던 중이었다면) 전부 비운다 —
     * 새 selectRecord()는 항상 새 작업 단위의 시작이다.
     * [2026-09-15 수정] 쿼리스트링 파라미터 이름은 `options.selectRecordParam`
     * (기본 `'pk'`)을 따른다 — PK 단건 조회뿐 아니라 FK로 거르는 조회도
     * 같은 계약으로 재사용할 수 있다.
     * @param {string|number} key
     * @returns {Promise<boolean>}
     */
    async selectRecord(key) {
        var self = this;
        this._lastError = null;

        if (typeof this.selectRecord_before === 'function') {
            if ((await this.selectRecord_before()) === false) {
                if (!this._lastError) {
                    this._lastError = 'selectRecord가 취소되었습니다(selectRecord_before).';
                }
                return Promise.resolve(false);
            }
        }

        return fetch(this._actionUrl('selectRecord') + '&' + this._selectRecordParam + '=' + encodeURIComponent(key))
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.data || result.data.success !== true) {
                    var errorMessage = '조회에 실패했습니다.';
                    if (result.data && result.data.error) {
                        errorMessage = result.data.error;
                    }
                    self._lastError = errorMessage;
                    return false;
                }

                self._loadRecordSet(result.data.data, result.data.cursor);

                if (typeof self.selectRecord_after === 'function') {
                    self.selectRecord_after();
                }
                return true;
            })
            .catch(function (err) {
                self._lastError = String(err);
                return false;
            });
    }

    /**
     * loadRecord(row) — [2026-09-18 신설] `selectRecord(key)`와 결과는
     * 동일하지만(레코드셋 크기 1로 화면에 즉시 반영) **서버에 다시 묻지
     * 않는다** — 호출부가 이미(예: 그리드의 `SELECT *` 결과) 그 행 전체를
     * 들고 있어 네트워크 왕복이 낭비인 경우를 위한 버전이다
     * (`selectRecord()`에서 `fetch()` 왕복만 빼고 나머지는 동일 — 훅도
     * 같은 이름(`selectRecord_before`/`_after`)을 그대로 존중해, 소비자
     * 입장에서 "네트워크로 왔는지 이미 갖고 있던 값인지"를 구분할 필요가
     * 없다). 네트워크는 없지만 `clear()`와 마찬가지로 selectRecord_before
     * (async 가능)를 기다려야 해서 Promise를 돌려준다.
     * @param {object} row
     * @returns {Promise<boolean>}
     */
    async loadRecord(row) {
        this._lastError = null;

        if (typeof this.selectRecord_before === 'function') {
            if ((await this.selectRecord_before()) === false) {
                if (!this._lastError) {
                    this._lastError = 'selectRecord가 취소되었습니다(selectRecord_before).';
                }
                return false;
            }
        }

        this._loadRecordSet([row || {}], 0);

        if (typeof this.selectRecord_after === 'function') {
            this.selectRecord_after();
        }
        return true;
    }

    // ------------------------------------------------------------------
    // 다중 레코드(커서) — Crud.php의 selectRecords()/bof()/eof()/
    // moveFirst()~movePrevious()와 동일한 개념을 클라이언트에 옮긴 것.
    // moveXxx()류는 지시에 따라 별도 훅(_before/_after)을 두지 않는다.
    // ------------------------------------------------------------------

    /**
     * selectRecords(filterValue) — 여러 건을 커서 방식으로 가져온다.
     * `filterValue`를 생략하면 조건 없이 서버 `Crud::selectRecords('')`
     * 와 동일한 전체 조회다(기존 동작 그대로, 하위 호환). 사용자 입력을
     * 그대로 SQL WHERE 조각으로 넘기는 인젝션 위험 때문에 "조건 자체를
     * 안 받는다"는 원칙은 여전히 지킨다(crudjs_fetch.php 상단 주석
     * 참고) — 응답의 첫 행이 화면에 자동 반영되고, 이후
     * moveNext()/movePrevious() 등으로 옮겨다닐 수 있다.
     * [2026-09-15 추가] "매출_내용처럼 여러 건을 매출code(FK)로 걸러
     * 가져오는 것도 selectRecords가 맞다(selectRecord는 이름부터
     * '한 건'이라 안 맞는다)" 지시로 `filterValue`를 받게 확장 —
     * `selectRecord(key)`와 완전히 같은 원칙(스칼라 값 하나만 실어
     * 보내고, 그 값으로 어느 컬럼을 어떻게 비교할지는 전적으로 서버
     * 액션이 결정)이라 "조건 자체를 안 받는다"는 인젝션 방어는 그대로
     * 유지된다 — 클라이언트가 보내는 건 여전히 값 하나(`매출code=58425`
     * 같은 것)일 뿐 `WHERE ...` 같은 SQL 조각이 아니다. 쿼리스트링
     * 파라미터 이름은 `options.selectRecordsParam`(생략 시
     * `selectRecordParam`을 물려받음)을 쓴다. [2026-09-15 후속 —
     * selectRecordParam에서 분리] 같은 인스턴스가 selectRecord(PK 단건)
     * 와 selectRecords(FK 다건)를 **둘 다** 쓰면서 파라미터 이름이
     * 서로 달라야 하는 경우(obj매출내용: `pk` vs `매출code`)가 생겨
     * 두 옵션으로 나뉘었다.
     * @param {string|number} [filterValue]
     * @returns {Promise<boolean>}
     */
    selectRecords(filterValue) {
        var self = this;
        this._lastError = null;

        var url = this._actionUrl('selectRecords');
        if (filterValue !== undefined && filterValue !== null && filterValue !== '') {
            url += '&' + this._selectRecordsParam + '=' + encodeURIComponent(filterValue);
        }

        return fetch(url)
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.data || result.data.success !== true) {
                    var errorMessage = '조회에 실패했습니다.';
                    if (result.data && result.data.error) {
                        errorMessage = result.data.error;
                    }
                    self._lastError = errorMessage;
                    return false;
                }

                self._loadRecordSet(result.data.data, result.data.cursor);
                return true;
            })
            .catch(function (err) {
                self._lastError = String(err);
                return false;
            });
    }

    /**
     * 서버 응답의 data(행 배열)/cursor를 그대로 받아 내부 상태를
     * 채우고 화면을 다시 그린다. dirty/삭제 표시는 전부 초기화한다(새
     * 작업 단위 시작).
     * @param {object[]|undefined} rows
     * @param {number|undefined} cursor
     */
    _loadRecordSet(rows, cursor) {
        this._recordSet = rows || [];
        // [2026-10-28 추가] 서버의 날짜(dblib 'Sep 30 2026 12:00:00:000AM' 등)와 bit(true/false)를
        // 받자마자 화면과 같은 표준 값('YYYY-MM-DD', 1/0)으로 바꿔 둔다 — 안 그러면 화면에서
        // 읽어 온 값과 글자가 달라, 손대지 않은 행도 커서를 옮기면 "바뀜"(dirty)으로 잡힌다.
        this._normalizeLoadedRows(this._recordSet);
        this._dirtyKeys = {};
        this._deletedKeys = {};
        if (typeof cursor === 'number' && cursor >= 0 && cursor < this._recordSet.length) {
            this._cursor = cursor;
        } else if (this._recordSet.length > 0) {
            this._cursor = 0;
        } else {
            this._cursor = -1;
        }
        this._renderCursorRow();
    }

    /**
     * 지금 커서가 가리키는 행의 값을 _data에 반영하고 render()로 화면에
     * 뿌린다(객체 -> DOM). bof()/eof() 상태(레코드가 없거나 경계를
     * 넘음)면 모든 필드를 빈 값으로 되돌린다.
     */
    _renderCursorRow() {
        if (this.bof() || this.eof()) {
            this._data = this._blankData();
            this.render();
            return;
        }

        var row = this._recordSet[this._cursor];
        var data = {};
        Object.keys(this.schema.fields || {}).forEach(function (field) {
            if (Object.prototype.hasOwnProperty.call(row, field)) {
                data[field] = row[field];
            } else {
                data[field] = null;
            }
        });
        this._data = data;
        this.render();
    }

    /**
     * dirty 비교 전용 정규화 — null/undefined/''는 전부 "비어있음"으로
     * 같은 값('')으로 취급한다. 서버(JSON)의 NULL은 그대로 null로
     * 오지만, 화면(setValue())은 null을 ''로 바꿔서 보여주므로, 이
     * 정규화 없이 String() 비교만 하면(예전 코드) NULL 필드가 있는
     * 행은 커서만 옮겨도 항상 "변경됨"으로 오판된다.
     * @param {*} value
     * @returns {string}
     */
    _normalizeForCompare(value) {
        if (value === null || value === undefined || value === '') {
            return '';
        }
        return String(value);
    }

    /**
     * 커서를 옮기기(또는 saveBulk() 하기) 직전에 부른다 — 지금 화면
     * 값이 이 행의 마지막 스냅샷과 다르면 그 행의 배열 인덱스를 dirty로
     * 표시하고, 화면 값을 레코드셋에 다시 써넣는다(안 그러면 다른
     * 행으로 옮기는 순간 방금 고친 값이 사라진다). PK 존재 여부와
     * 무관하게 표시한다 — PK가 아직 없는 신규 행도 인덱스로는 항상
     * 식별 가능하기 때문([2026-09-14 후속] 인덱스 기반으로 전환).
     */
    _syncCursorRowBeforeMove() {
        if (this.bof() || this.eof()) {
            return;
        }
        var current = this.getAllValues();
        var snapshot = this._recordSet[this._cursor];
        var changed = false;
        for (var field in current) {
            if (Object.prototype.hasOwnProperty.call(current, field)) {
                if (this._normalizeForCompare(current[field]) !== this._normalizeForCompare(snapshot[field])) {
                    changed = true;
                    break;
                }
            }
        }

        this._recordSet[this._cursor] = current;

        if (changed) {
            this._dirtyKeys[String(this._cursor)] = true;
        }
    }

    /**
     * 커서가 첫 레코드 이전(=유효한 현재 레코드 없음)인지. 레코드셋이
     * 비어있으면 항상 true.
     */
    bof() {
        return this._recordSet.length === 0 || this._cursor < 0;
    }

    /**
     * 커서가 마지막 레코드 이후(=유효한 현재 레코드 없음)인지. 레코드셋이
     * 비어있으면 항상 true.
     */
    eof() {
        return this._recordSet.length === 0 || this._cursor >= this._recordSet.length;
    }

    /**
     * 지금 들고 있는 레코드 수.
     */
    recordCount() {
        return this._recordSet.length;
    }

    /**
     * 현재 커서 위치(-1=BOF, recordCount()=EOF, 그 사이=유효한 인덱스).
     */
    getCursorPosition() {
        return this._cursor;
    }

    moveFirst() {
        if (this._recordSet.length === 0) {
            return;
        }
        this._syncCursorRowBeforeMove();
        this._cursor = 0;
        this._renderCursorRow();
    }

    moveLast() {
        if (this._recordSet.length === 0) {
            return;
        }
        this._syncCursorRowBeforeMove();
        this._cursor = this._recordSet.length - 1;
        this._renderCursorRow();
    }

    moveNext() {
        if (this._recordSet.length === 0) {
            return;
        }
        this._syncCursorRowBeforeMove();
        if (this._cursor < this._recordSet.length) {
            this._cursor++;
        }
        this._renderCursorRow();
    }

    movePrevious() {
        if (this._recordSet.length === 0) {
            return;
        }
        this._syncCursorRowBeforeMove();
        if (this._cursor >= 0) {
            this._cursor--;
        }
        this._renderCursorRow();
    }

    /**
     * 지금 커서가 가리키는 행을 "삭제 대상"으로 표시한다(서버에는 아직
     * 안 지운다 — saveBulk()가 dirty와 함께 한꺼번에 반영한다). 다시
     * false를 주면 표시를 해제한다.
     * @param {boolean} [flag=true]
     */
    markDeleted(flag) {
        if (this.bof() || this.eof() || !this.schema.primaryKey) {
            return;
        }
        var effectiveFlag = flag;
        if (effectiveFlag === undefined) {
            effectiveFlag = true;
        }

        var row = this._recordSet[this._cursor];
        var pk = row[this.schema.primaryKey];
        if (pk === null || pk === undefined || pk === '') {
            return;
        }

        if (effectiveFlag) {
            this._deletedKeys[String(pk)] = true;
        } else {
            delete this._deletedKeys[String(pk)];
        }
    }

    /**
     * 지금 커서가 가리키는 행이 (아직 저장 안 된) dirty 상태인지.
     * [2026-09-14 후속] PK가 아니라 배열 인덱스로 판정 — PK 없는 신규
     * 행도 dirty 여부를 물어볼 수 있어야 하기 때문.
     */
    isDirty() {
        if (this.bof() || this.eof()) {
            return false;
        }
        return Boolean(this._dirtyKeys[String(this._cursor)]);
    }

    /**
     * 지금 커서가 가리키는 행이 삭제 표시(markDeleted) 상태인지.
     */
    isDeleted() {
        if (this.bof() || this.eof() || !this.schema.primaryKey) {
            return false;
        }
        var pk = this._recordSet[this._cursor][this.schema.primaryKey];
        return Boolean(this._deletedKeys[String(pk)]);
    }

    /**
     * saveBulk() — 커서 이동/markDeleted()로 표시된 dirty·삭제 행들을
     * 한 번에 서버로 보낸다. selectRecords()/selectRecord() 응답과
     * 정확히 같은 봉투 모양({schema, cursor, data, dirty, deleted})을
     * 그대로 다시 보낸다. [2026-09-14 후속] `dirty`는 이제 PK가 아니라
     * `data` 배열 안에서의 인덱스 목록이다 — PK가 없는(=한 번도 저장 안
     * 된) 신규 행도 `data`에 그대로 실어 보내고 그 인덱스를 dirty로
     * 표시하면 서버가 INSERT로 처리해준다(`crudjs_fetch.php`의
     * `saveBulk` 참고). `deleted`는 여전히 PK 배열 그대로 — 삭제는
     * "서버에 이미 있는 행"에만 의미가 있어 PK가 항상 존재한다.
     * 성공한 행만 dirty/삭제 표시를 지운다(실패한 행은 남아있어 다시
     * 시도 가능).
     * @returns {Promise<{results: object[], deleteResults: object[]}|null>}
     *   실패(또는 보낼 변경사항 없음)면 null(getError() 참고).
     */
    saveBulk() {
        var self = this;
        this._lastError = null;

        // 지금 화면에 남아있는 값(커서가 가리키던 마지막 행)도 반영해야
        // 하므로, 보내기 직전에 한 번 더 동기화한다.
        this._syncCursorRowBeforeMove();

        var dirtyIndexes = Object.keys(this._dirtyKeys).map(Number);
        var deletedKeys = Object.keys(this._deletedKeys);

        if (dirtyIndexes.length === 0 && deletedKeys.length === 0) {
            this._lastError = '저장할 변경 사항이 없습니다.';
            return Promise.resolve(null);
        }

        var payload = {
            schema: this.schema,
            cursor: this._cursor,
            data: this._recordSet,
            dirty: dirtyIndexes,
            deleted: deletedKeys,
        };

        return fetch(this._actionUrl('saveBulk'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.data || result.data.success !== true) {
                    var errorMessage = '일괄 저장에 실패했습니다.';
                    if (result.data && result.data.error) {
                        errorMessage = result.data.error;
                    }
                    self._lastError = errorMessage;
                    return null;
                }

                var results = result.data.results || [];
                var deleteResults = result.data.deleteResults || [];

                results.forEach(function (r) {
                    if (r.success) {
                        delete self._dirtyKeys[String(r.index)];
                    }
                });

                self._removeDeletedFromRecordSet(deleteResults);
                self._renderCursorRow();

                return { results: results, deleteResults: deleteResults };
            })
            .catch(function (err) {
                self._lastError = String(err);
                return null;
            });
    }

    /**
     * _removeDeletedFromRecordSet(deleteResults) — [2026-09-15 신설,
     * saveBulk()에 있던 로직을 deleteBulk()와 공유하기 위해 private
     * 메서드로 추출] 서버가 돌려준 `deleteResults`(`{pk, success,
     * message?}[]`) 중 성공한 항목만 `_recordSet`/`_dirtyKeys`/
     * `_deletedKeys`에서 실제로 제거한다. 화면 반영(`_renderCursorRow()`)
     * 은 호출부 책임 — saveBulk()/deleteBulk() 둘 다 이 호출 뒤 각자
     * 필요한 시점에 부른다.
     * @param {object[]} deleteResults
     */
    _removeDeletedFromRecordSet(deleteResults) {
        var self = this;

        // 삭제 성공한 행들을 _recordSet에서 실제로 잘라내기 전에, 지금
        // 시점의 인덱스를 먼저 전부 찾아둔다 — 스플라이스가 한 번
        // 일어날 때마다 그 뒤 인덱스가 전부 하나씩 당겨져, dirty
        // 인덱스도 같이 보정해주지 않으면 엉뚱한 행을 가리키게 되기
        // 때문이다.
        var removedIndexes = [];
        deleteResults.forEach(function (r) {
            if (!r.success) {
                return;
            }
            delete self._deletedKeys[String(r.pk)];
            for (var i = 0; i < self._recordSet.length; i++) {
                var rowPk = self._recordSet[i][self.schema.primaryKey];
                if (String(rowPk) === String(r.pk)) {
                    removedIndexes.push(i);
                    break;
                }
            }
        });

        // 큰 인덱스부터 잘라내야 아직 처리 안 한 나머지 인덱스가 안
        // 틀어진다.
        removedIndexes.sort(function (a, b) { return b - a; });
        removedIndexes.forEach(function (removedIndex) {
            self._recordSet.splice(removedIndex, 1);
            delete self._dirtyKeys[String(removedIndex)];
            var shifted = {};
            Object.keys(self._dirtyKeys).forEach(function (key) {
                var n = Number(key);
                if (n > removedIndex) {
                    shifted[String(n - 1)] = true;
                } else {
                    shifted[key] = true;
                }
            });
            self._dirtyKeys = shifted;
            if (self._cursor >= self._recordSet.length) {
                self._cursor = self._recordSet.length - 1;
            }
        });
    }

    /**
     * deleteBulk(pks) — [2026-09-15 신설] "obj매출내용에 selectRecord/
     * selectRecords/save/delete/saveBulk/deleteBulk 여섯 액션이 다 있어야
     * 단건·다건 조회/쓰기가 각각 가능해진다" 지시로 saveBulk()와 짝을
     * 이루는 순수 일괄 삭제 메서드를 추가했다. `saveBulk()`의 `deleted`
     * 배열로도 같은 일을 할 수 있지만, 그건 "지금 커서에 로드된
     * `_recordSet` 전체 + dirty 표시된 행까지 함께 보내는 저장+삭제
     * 조합 연산"이다 — 이 메서드는 그런 상태와 무관하게 "이 PK들만
     * 지운다"만 하는 순수 삭제 전용 연산이 필요할 때(예: 목록에서 고른
     * 몇 건만 바로 삭제) 쓴다. 서버 액션은 새 로직 없이 기존
     * `Crud::saveBulk_crudjs()`를 `data`/`dirty`는 빈 값으로 호출해
     * 재사용한다(`deco_amd_amd_fetch.php`의 `deleteBulk매출내용` 참고).
     * 삭제 성공한 PK가 마침 지금 `_recordSet`에 로드돼 있다면 그 행도
     * 잘라내고 화면을 다시 그린다(saveBulk()와 동일한
     * `_removeDeletedFromRecordSet()` 재사용).
     * @param {Array<string|number>} pks
     * @returns {Promise<{deleteResults: object[]}|null>} 실패(또는 pks가
     *   빈 배열)면 null(getError() 참고).
     */
    async deleteBulk(pks) {
        var self = this;
        this._lastError = null;

        if (!Array.isArray(pks) || pks.length === 0) {
            this._lastError = '삭제할 PK가 없습니다.';
            return Promise.resolve(null);
        }

        if (typeof this.delete_before === 'function') {
            if ((await this.delete_before()) === false) {
                if (!this._lastError) {
                    this._lastError = '삭제가 취소되었습니다(delete_before).';
                }
                return Promise.resolve(null);
            }
        }

        return fetch(this._actionUrl('deleteBulk'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ deleted: pks }),
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.data || result.data.success !== true) {
                    var errorMessage = '일괄 삭제에 실패했습니다.';
                    if (result.data && result.data.error) {
                        errorMessage = result.data.error;
                    }
                    self._lastError = errorMessage;
                    return null;
                }

                var deleteResults = result.data.deleteResults || [];
                self._removeDeletedFromRecordSet(deleteResults);
                self._renderCursorRow();

                if (typeof self.delete_after === 'function') {
                    self.delete_after();
                }

                return { deleteResults: deleteResults };
            })
            .catch(function (err) {
                self._lastError = String(err);
                return null;
            });
    }
}
