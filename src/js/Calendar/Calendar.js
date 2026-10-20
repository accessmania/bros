/**
 * Calendar — 재사용 가능한 날짜/기간 선택 달력 컴포넌트.
 *
 * `ScrollGrid.js`/`AGGrid.js`/`ChosungKeypad.js`와 동일한 원칙 — 순수
 * DOM/이벤트만 다루고, 서버 통신은 `fetchUrl`을 준 경우의 `action('show')`
 * 와 메모 저장/삭제뿐이다(아래 "## action('show')" 참고). `containerId` 컨테이너
 * 안에 자기 스스로를 그리고, 여러 인스턴스가 한 페이지에 동시에 있어도
 * (`Calendar.instances`) 서로 안 건드린다.
 *
 * 3_1의 `calendar.html`(`CalendarWidget`, 제목/내용/입력자로 일정을
 * CRUD하는 이벤트 캘린더)과는 성격이 다른 컴포넌트다 — 이 클래스는
 * "날짜 하나 또는 기간(from~to) 하나를 고른다"는 것만 한다. 두 용도를
 * 한 클래스에 합치면 모드 분기가 복잡해지고 서로 다른 상호작용(일정
 * 입력 폼 vs 즉시 확정되는 선택)이 섞여서, 별도 컴포넌트로 분리했다
 * (md/calendar-widget.md "재사용 가능한 날짜/기간 선택 컴포넌트" 참고).
 *
 * ## setValue()는 이벤트를 쏘지 않는다
 * `ChosungKeypad::setValue()`와 동일한 원칙 — 프로그램적 대입(setValue)이
 * 콜백을 다시 호출하면, 외부 코드가 setValue → 콜백 → 다시 setValue 로
 * 순환할 위험이 있다. 그래서 콜백은 오직 사용자가 실제로 날짜를
 * 클릭했을 때만 호출된다.
 *
 * ## 선택 시 — sender.action(callback이름, {value, id}, window)
 * [2026-09-11 수정] 예전엔 `onSelectDate(value, id)`라는 함수 콜백을
 * 직접 받았으나, 이 프로젝트의 `action(pcmd, pparams, psender)` 관례
 * (`ChosungGrid.js`가 이미 쓰는 것과 동일)로 통일했다 — `sender`가
 * 주어졌고 `sender.action`이 함수이면
 *   sender.action(this.callback, { value: this.getValue(), id: this.id }, window)
 * 을 부른다. **콜백 이름(`this.callback`)은 기본값이 `callback_달력`
 * 이지만 `options.callback`으로 호출부가 직접 지정할 수 있다** — 이
 * 위젯도 여러 화면이 재사용하므로, "선택이 끝나면 무엇을 해야 하는지"
 * 까지 담긴 구체적인 pcmd는 위젯 자신이 미리 정해둘 수 없고, 그 이름이
 * 실제로 쓰일 화면(여는 쪽)이 지어서 넘긴다(md/2026-09-11-action-callback-
 * 이름부여-규칙.md 참고).
 *
 * ## 식별자(id)는 콜백에도 자동으로 함께 온다
 * `pparams.id` — 이 인스턴스를 만들 때 넘긴(또는 생략 시 `containerId`를
 * 그대로 쓴) `options.id`다. 화면에 같은 용도의 Calendar가 여러 이유로
 * 여러 개 떠 있을 수 있는 경우(예: Popup.js로 "체크인"/"체크아웃" 날짜
 * 피커를 각각 띄운 경우), 같은 `callback` 이름을 여러 인스턴스가
 * 공유해도 이 값만으로 "어느 달력에서 온 선택인지"를 바로 구분할 수
 * 있다(`Popup.action()`의 id 전달과 동일한 설계).
 *
 * ## URL 상태 유지 (wave Layout GET 파라미터 방식)
 * `syncUrl:true`(기본값)이면, 달을 넘기거나 날짜(기간)를 고를 때마다
 * 페이지 재요청 없이 `history.replaceState()`로 주소창의 쿼리스트링만
 * 갱신한다(`{paramPrefix}view`/`{paramPrefix}date` 또는
 * `{paramPrefix}from`/`{paramPrefix}to`). 다른 쿼리 파라미터(`top`/`left`
 * 등)는 건드리지 않는다 — `Board::appendQuery()`가 기존 쿼리를 보존한 채
 * 필요한 키만 갈아끼우는 것과 같은 원칙이다. 실제로 새로고침(F5)이
 * 일어나면 이 클래스가 아니라 **호출부(서버 쪽 PHP)**가 그 GET 값을
 * 읽어 `initialView`/`initialValue`로 다시 넘겨줘야 상태가 복원된다 —
 * 이 클래스는 URL을 "쓰기"만 하고 "읽기"는 하지 않는다(호출부 책임,
 * `Board`가 화면단더러 화이트리스트를 넘기라고 하는 것과 동일한 경계).
 *
 * 요일 순서(`weekStart`)/선택 모드(`selectionMode`)는 날짜 클릭만큼
 * 자주 바뀌는 값이 아니라고 보고 URL 자동 갱신 대상에 넣지 않았다 —
 * 이 값을 바꾸고 싶은 화면은 페이지를 다시 여는(링크 재요청) 방식으로
 * 새 옵션을 넘기면 된다(4_1의 config_직원.php처럼 생성자를 다시 부르는
 * 방식과 달리, 이 컴포넌트는 "설정을 바꾸는 것 = 다시 만드는 것"으로
 * 취급 — 실사용 예는 calendar.md 참고).
 *
 * 사용 예:
 *   new Calendar({
 *       containerId: 'b43-calendar',
 *       weekStart: 0,               // 0=일요일 시작(기본) | 1=월요일 시작
 *       selectionMode: 'single',    // 'single'(기본) | 'range'
 *       paramPrefix: 'cal_',
 *       initialView: { year: 2026, month: 8 },
 *       initialValue: '2026-08-19', // selectionMode:'range'면 {from, to}
 *       showYearMonthList: false,   // true면 좌측에 년/월 리스트박스 추가
 *       sender: window,             // 콜백을 받을 창(보통 이 페이지 자신)
 *       callback: 'callback_달력_값선택됨', // (선택) 생략 시 'callback_달력'
 *   });
 *   // sender.action('callback_달력_값선택됨', { value, id }, window)로 보고됨.
 *
 * ## showYearMonthList — 년/월 리스트박스 내비게이터
 * 바탕화면 `달력2.png`(MS Access "날짜를 선택하세요" 대화상자)를 보면
 * 이전/다음 화살표 내비게이션 왼쪽에 년(스크롤 가능한 목록)/월(1~12)
 * 리스트박스가 나란히 있다. `showYearMonthList:true`면 그 형태로,
 * 기본값(`false`)이면 지금까지의 형태(화살표만) 그대로 뜬다. 년/월을
 * 리스트에서 고르는 건 `showMonth()`와 같은 "화면(보이는 달) 이동"일
 * 뿐 날짜 선택이 아니다 — 실제 날짜 선택은 여전히 그리드를 클릭해야
 * 한다. 년도 목록의 범위(오늘 연도 ±15)는 생성 시점에 한 번만 고정된다
 * (화면을 넘길 때마다 다시 계산하지 않음 — 진짜 리스트박스처럼 목록
 * 자체는 안 흔들리고 강조된 항목만 바뀐다).
 *
 * ## action('show', {year, month}) — 서버의 메모를 그 달에 표시
 * [2026-10-18 추가] `fetchUrl`(예: `'Calendar_fetch.php'`)을
 * 주면, 그 달로 화면을 옮기고 `fetchUrl?cmd=show&year=YYYY&month=M`을 GET해
 * 응답(`[{date: 'YYYY-MM-DD', memo}, ...]`, tCalendar_schedule)의 메모를
 * 날짜 칸에 표시한다. 화살표/오늘/리스트박스 등으로 보이는 달이 바뀌면
 * `_render()`가 알아서 `action('show')`를 다시 부른다(생성 직후 첫
 * 렌더 포함). `fetchUrl`이 없으면 달만 옮기고 서버에는 안 간다.
 *
 * 메모 팝업의 저장/삭제도 `fetchUrl`이 있으면 같은 주소로 POST한다
 * (`cmd=save` {date, code, memo} / `cmd=delete` {code}) — 서버가 성공을
 * 돌려준 뒤에만 화면을 바꾸고, 실패하면 alert로 알린다.
 */
class Calendar {
    /**
     * @param {{containerId: string, id?: string, weekStart?: 0|1,
     *   selectionMode?: 'single'|'range', paramPrefix?: string,
     *   syncUrl?: boolean, initialView?: {year: number, month: number},
     *   initialValue?: (string|{from: ?string, to: ?string}|null),
     *   showYearMonthList?: boolean, memoPopup?: boolean, sender?: Window,
     *   callback?: string, fetchUrl?: string}} options
     *   `memoPopup`(선택, 기본 false) — true면 날짜 셀 클릭 시 그 셀 옆에
     *   메모 팝업(메모 입력 + 저장/삭제/창닫기)을 띄운다.
     *   `fetchUrl`(선택) — 주면 보이는 달의 메모를 서버에서 가져와 표시한다
     *   (클래스 docblock "## action('show')" 참고).
     *   `sender`(선택) — 콜백을 받을 창(주로 이 페이지 자신). `callback`
     *   (선택) — 선택 시 `sender.action(...)`에 쓸 pcmd 이름. 생략하면
     *   `'callback_달력'`(호출부가 sender를 안 주면 콜백 자체가 안 감).
     */
    constructor(options) {
        options = options || {};
        if (!options.containerId) {
            throw new Error('Calendar: containerId는 필수입니다.');
        }
        this.containerId = options.containerId;
        this.id = options.id || options.containerId;
        this.weekStart = options.weekStart === 1 ? 1 : 0;
        this.selectionMode = options.selectionMode === 'range' ? 'range' : 'single';
        this.paramPrefix = typeof options.paramPrefix === 'string' ? options.paramPrefix : '';
        this.syncUrl = options.syncUrl !== false;
        this.showYearMonthList = options.showYearMonthList === true;
        // true인 화면에서만 셀 클릭 시 메모 팝업을 띄운다 — 기본(false)은
        // 날짜 선택만 하는 기존 동작(Calendar.open() 날짜 피커 등)을 그대로 둔다.
        this.memoPopup = options.memoPopup === true;
        this.sender = options.sender || null;
        // [2026-09-11 추가] "창이 뜰 때 callback 이름을 부여해서 띄우고,
        // 일을 마칠 때 그 이름으로 보고한다" 규칙 — ChosungGrid.js와 동일
        // (생략하면 'callback_달력').
        this.callback = typeof options.callback === 'string' && options.callback !== ''
            ? options.callback
            : 'callback_달력';

        const today = new Date();
        this.viewYear = today.getFullYear();
        this.viewMonth = today.getMonth() + 1; // 1-12로 다룬다(Date 객체의 0-11과 헷갈리지 않기 위해 경계에서만 변환)

        // 선택값 — single: 문자열|null, range: {from, to}(각각 문자열|null)
        this.value = this.selectionMode === 'range' ? { from: null, to: null } : null;
        // range 모드에서 두 번째 클릭(to)을 기다리는 중인 첫 클릭(from)값
        this._pendingRangeFrom = null;
        // 날짜별 메모 { 'YYYY-MM-DD': '내용' } — 지금은 화면 메모리에만 둔다
        // (서버 저장은 Calendar_fetch.php에 cmd가 생기면 saveMemo/deleteMemo에서 연결).
        this.memos = {};
        // 날짜별 tCalendar_schedule PK { 'YYYY-MM-DD': code } — show로 받은
        // 값을 렌더 때 셀의 data-code에 임시로 실어 둔다(저장/삭제 때 행 지정용).
        this.codes = {};
        // 메모 팝업이 지금 가리키는 날짜/그 날짜의 code(null=닫힘 또는 DB 행 없음)
        this._popupDate = null;
        this._popupCode = null;
        // 서버에서 메모를 가져올 URL(null이면 서버 조회 안 함), 마지막으로
        // show한 달('YYYY-M'), 늦게 온 이전 달 응답을 버리기 위한 토큰.
        this.fetchUrl = typeof options.fetchUrl === 'string' && options.fetchUrl !== '' ? options.fetchUrl : null;
        this._shownYm = null;
        this._showToken = 0;
        // 저장/삭제 요청이 서버로 가는 중인지(연타로 중복 INSERT 방지)
        this._posting = false;

        if (options.initialView) {
            this.viewYear = options.initialView.year;
            this.viewMonth = options.initialView.month;
        }

        Calendar.instances[this.id] = this;

        Calendar._injectStyles();
        this._buildDom();

        if (options.initialValue !== undefined && options.initialValue !== null) {
            this._applyValue(options.initialValue, false);
        } else {
            this._render();
        }
    }

    /** 단일모드: 문자열('YYYY-MM-DD')|null. 기간모드: {from, to}(각각 문자열|null). */
    getValue() {
        if (this.selectionMode === 'range') {
            return { from: this.value.from, to: this.value.to };
        }
        return this.value;
    }

    /**
     * 값을 강제로 대입하고, 그 값이 보이는 달로 화면을 옮긴다("달력이
     * 뜰 때 가리키는 날짜가 주입되면 그 화면이 표시되고"). URL도
     * 갱신하지만 콜백(`sender.action(...)`)은 호출하지 않는다(클래스
     * 상단 docblock "setValue()는 이벤트를 쏘지 않는다" 참고).
     */
    setValue(value) {
        this._applyValue(value, false);
    }

    /** 선택값은 그대로 둔 채 화면(보이는 달)만 옮긴다. */
    showMonth(year, month1to12) {
        this.viewYear = year;
        this.viewMonth = month1to12;
        this._pendingRangeFrom = null;
        this._syncUrlState();
        this._render();
    }

    /** DOM/이벤트를 정리하고 인스턴스 등록을 해제한다. */
    destroy() {
        const container = document.getElementById(this.containerId);
        if (container) {
            container.innerHTML = '';
        }
        document.removeEventListener('mousedown', this._onDocMouseDown);
        delete Calendar.instances[this.id];
    }

    /** 이 인스턴스가 다루는 일의 진입점(Grid.js의 action()과 동일 관례). */
    action(pcmd, pparams, psender) {
        pparams = pparams || {};
        switch (pcmd) {
            case 'show':
                this._show(pparams.year, pparams.month);
                break;
            case 'clickCell':
                this._clickCell(pparams.date);
                break;
            case 'saveMemo':
                this._saveMemo(pparams.date, pparams.code, pparams.memo);
                break;
            case 'deleteMemo':
                this._deleteMemo(pparams.date, pparams.code);
                break;
            case 'closeMemoPopup':
                this._closeMemoPopup();
                break;
            default:
                console.warn('Calendar.action: 알 수 없는 pcmd', pcmd, psender);
        }
        return undefined;
    }

    /**
     * 날짜 셀 클릭 — 기존 선택 처리(_onDayClick)를 먼저 탄 뒤, 그 셀 옆에
     * 메모 팝업을 연다(구글 캘린더의 일정 팝업 형태). _onDayClick이
     * _render()로 셀을 새로 그리므로 셀 엘리먼트는 그 뒤에 다시 찾는다.
     */
    _clickCell(iso) {
        // 메모 팝업 모드에서는 이전/다음 달 칸을 눌러도 그 달로 넘어가지
        // 않고 지금 화면의 그 칸 옆에 팝업을 띄운다(keepView).
        this._onDayClick(iso, this.memoPopup);
        if (this.memoPopup) {
            this._openMemoPopup(iso);
        }
    }

    /**
     * 메모 저장 — fetchUrl이 있으면 서버(cmd=save)에 먼저 저장하고, 성공한
     * 뒤에만 화면(memos/codes)을 바꾼다. 응답의 code(새로 INSERT된 행이면
     * 새 PK)를 그 날짜에 기억한다. 빈 내용으로 저장하면 삭제로 처리한다.
     */
    _saveMemo(iso, code, memo) {
        const text = (memo || '').trim();
        if (text === '') {
            this._deleteMemo(iso, code);
            return;
        }
        if (!this.fetchUrl) {
            this.memos[iso] = text;
            this._render();
            return;
        }
        this._post('save', { date: iso, code: code == null ? '' : code, memo: text })
            .then((data) => {
                this.memos[iso] = text;
                if (data.code !== null && data.code !== undefined) {
                    this.codes[iso] = data.code;
                }
                this._render();
            })
            .catch((err) => Calendar._reportError('메모 저장 실패', err));
    }

    /**
     * 메모 삭제 — fetchUrl과 code(DB 행)가 있으면 서버(cmd=delete)에서
     * 먼저 지우고, 성공한 뒤에만 화면에서 지운다. DB 행이 없는 날짜는
     * 화면에서만 지운다.
     */
    _deleteMemo(iso, code) {
        if (!this.fetchUrl || code == null || code === '') {
            delete this.memos[iso];
            this._render();
            return;
        }
        this._post('delete', { code: code })
            .then(() => {
                delete this.memos[iso];
                delete this.codes[iso];
                this._render();
            })
            .catch((err) => Calendar._reportError('메모 삭제 실패', err));
    }

    /**
     * fetchUrl에 cmd를 붙여 POST(URLSearchParams 본문 — talk.js와 동일
     * 관례)하고 {ok: true, ...} 응답을 돌려준다. 실패면 reject. 응답이
     * 오기 전에 다시 누르면(연타) 두 번째 요청은 보내지 않는다.
     */
    _post(cmd, params) {
        if (this._posting) {
            return Promise.reject(new Error('이전 요청을 처리하는 중입니다.'));
        }
        this._posting = true;
        const body = new URLSearchParams(Object.assign({ cmd: cmd }, params));
        return fetch(this.fetchUrl, { method: 'POST', body: body })
            .then((res) => res.json().then((data) => {
                if (!res.ok || !data || !data.ok) {
                    throw new Error(data && data.error ? data.error : 'HTTP ' + res.status);
                }
                return data;
            }))
            .finally(() => {
                this._posting = false;
            });
    }

    /** 저장/삭제 실패를 사용자에게 알린다(콘솔에도 남김). */
    static _reportError(message, err) {
        console.error('Calendar: ' + message, err);
        window.alert(message + ': ' + (err && err.message ? err.message : err));
    }

    /**
     * 그 달로 화면을 옮기고(이미 그 달이면 그대로), fetchUrl이 있으면
     * 서버에서 그 달의 메모를 가져와 this.memos를 통째로 바꾼 뒤 다시
     * 그린다. _shownYm을 먼저 기록해 두므로 안에서 부르는 _render()가
     * 다시 show를 부르지 않는다.
     */
    _show(year, month1to12) {
        year = parseInt(year, 10);
        month1to12 = parseInt(month1to12, 10);
        if (!(year > 0) || !(month1to12 >= 1 && month1to12 <= 12)) {
            console.warn('Calendar.show: year/month 값이 올바르지 않습니다', year, month1to12);
            return;
        }
        this._shownYm = year + '-' + month1to12;
        if (this.viewYear !== year || this.viewMonth !== month1to12) {
            this.showMonth(year, month1to12);
        }
        if (!this.fetchUrl) {
            return;
        }

        const separator = this.fetchUrl.indexOf('?') === -1 ? '?' : '&';
        // 그 달만이 아니라 화면에 보이는 6주(앞뒤 달 칸 포함) 전체를 조회한다
        // — 앞뒤 달 칸도 메모가 보이고, 그 칸에서 연 팝업에도 code가 실린다.
        const cells = this._buildGridDates(year, month1to12);
        const first = cells[0];
        const last = cells[cells.length - 1];
        const url = this.fetchUrl + separator + 'cmd=show&year=' + year + '&month=' + month1to12 +
            '&from=' + Calendar._toIso(first.getFullYear(), first.getMonth() + 1, first.getDate()) +
            '&to=' + Calendar._toIso(last.getFullYear(), last.getMonth() + 1, last.getDate());
        const token = ++this._showToken;
        fetch(url)
            .then((res) => res.json())
            .then((rows) => {
                if (token !== this._showToken) {
                    return; // 그 사이 다른 달로 넘어감 — 이 응답은 버린다.
                }
                if (!Array.isArray(rows)) {
                    throw new Error(rows && rows.error ? rows.error : '응답 형식이 배열이 아닙니다.');
                }
                const memos = {};
                const codes = {};
                rows.forEach((row) => {
                    if (!row.date) {
                        return;
                    }
                    // 메모가 비어 보여도 DB 행은 있으므로 code는 그대로 기억한다.
                    if (row.code !== null && row.code !== undefined) {
                        codes[row.date] = row.code;
                    }
                    // DB 메모는 HTML(에디터 저장값)이라 순수 문장으로 바꿔 둔다.
                    const text = row.memo ? Calendar._htmlToText(row.memo) : '';
                    if (text) {
                        memos[row.date] = text;
                    }
                });
                this.memos = memos;
                this.codes = codes;
                this._render();
            })
            .catch((err) => console.error('Calendar.show: 메모 조회 실패', err));
    }

    // ------------------------------------------------------------------
    // 내부 — 메모 팝업
    // ------------------------------------------------------------------

    /**
     * 클릭한 셀의 오른쪽에 팝업을 붙이고, 오른쪽 공간이 모자라면 왼쪽에,
     * 아래가 모자라면 위로 끌어올린다 — 달력 컨테이너(.wc-cal) 밖으로는
     * 나가지 않는다(Popup iframe 안에서 열려도 잘리지 않게).
     */
    _openMemoPopup(iso) {
        const cellEl = this.gridEl.querySelector('[data-date="' + iso + '"]');
        if (!cellEl) {
            return;
        }
        const container = document.getElementById(this.containerId);
        const pop = this.popupEl;

        this._popupDate = iso;
        // 셀에 실어 둔 PK를 팝업에도 넘긴다 — DB 행이 없는 날짜면 null.
        this._popupCode = cellEl.getAttribute('data-code');
        if (this._popupCode === null) {
            pop.removeAttribute('data-code');
        } else {
            pop.setAttribute('data-code', this._popupCode);
        }
        const parsed = Calendar._parseIso(iso);
        const dow = new Date(parsed.year, parsed.month - 1, parsed.day).getDay();
        pop.querySelector('.wc-cal-pop-date').textContent =
            parsed.year + '년 ' + parsed.month + '월 ' + parsed.day + '일 (' + Calendar.WEEKDAY_LABEL[dow] + '요일)';
        this.popupMemoEl.value = this.memos[iso] || '';
        pop.querySelector('[data-pop="delete"]').disabled = !this.memos[iso];
        pop.hidden = false;

        const gap = 6;
        const cRect = container.getBoundingClientRect();
        const cell = cellEl.getBoundingClientRect();
        const pw = pop.offsetWidth;
        const ph = pop.offsetHeight;

        let left = cell.right - cRect.left + gap;
        if (left + pw > container.clientWidth) {
            left = cell.left - cRect.left - pw - gap;
        }
        if (left < 0) {
            left = Math.max(0, container.clientWidth - pw);
        }
        let top = cell.top - cRect.top;
        if (top + ph > container.clientHeight) {
            top = Math.max(0, container.clientHeight - ph);
        }
        pop.style.left = left + 'px';
        pop.style.top = top + 'px';

        this.popupMemoEl.focus();
    }

    _closeMemoPopup() {
        if (this.popupEl) {
            this.popupEl.hidden = true;
        }
        this._popupDate = null;
        this._popupCode = null;
    }

    /** 등록된 인스턴스를 id로 조회한다(AGGrid.instance()와 동일 패턴). */
    static instance(id) {
        return Calendar.instances[id] || null;
    }

    // ------------------------------------------------------------------
    // 내부 — 값 반영
    // ------------------------------------------------------------------

    /** keepView가 true면 선택값만 바꾸고 보이는 달은 그대로 둔다. */
    _applyValue(value, fireEvent, keepView) {
        let jumpIso = null;

        if (this.selectionMode === 'range') {
            const from = value && value.from ? value.from : null;
            const to = value && value.to ? value.to : null;
            this.value = { from, to };
            this._pendingRangeFrom = (from && !to) ? from : null;
            jumpIso = from || to;
        } else {
            this.value = value || null;
            this._pendingRangeFrom = null;
            jumpIso = this.value;
        }

        const parsed = keepView ? null : Calendar._parseIso(jumpIso);
        if (parsed) {
            this.viewYear = parsed.year;
            this.viewMonth = parsed.month;
        }

        this._syncUrlState();
        this._render();

        if (fireEvent && this.sender && typeof this.sender.action === 'function') {
            // id(이 인스턴스를 열 때 넘긴 값, 기본은 containerId)를 함께
            // 실어 보낸다 — 화면에 같은 용도의 Calendar가 여러 개 떠
            // 있어도(예: Popup.js로 여러 이유의 날짜 피커를 띄운 경우)
            // 같은 callback 이름을 공유하면서 이 값만으로 "어느 달력에서
            // 온 선택인지" 구분할 수 있다.
            this.sender.action(this.callback, { value: this.getValue(), id: this.id }, window);
        }
    }

    _onDayClick(iso, keepView) {
        if (this.selectionMode === 'range') {
            if (this._pendingRangeFrom === null) {
                // 첫 클릭 — from만 잠정 확정, 아직 완성된 선택이 아니라 이벤트는 안 쏜다.
                this._applyValue({ from: iso, to: null }, false, keepView);
            } else {
                let from = this._pendingRangeFrom;
                let to = iso;
                if (to < from) {
                    const swap = from;
                    from = to;
                    to = swap;
                }
                // 두 번째 클릭으로 기간이 완성됐으니 여기서만 이벤트를 쏜다.
                this._applyValue({ from, to }, true, keepView);
            }
        } else {
            this._applyValue(iso, true, keepView);
        }
    }

    // ------------------------------------------------------------------
    // 내부 — URL 상태 동기화
    // ------------------------------------------------------------------

    _syncUrlState() {
        if (!this.syncUrl) {
            return;
        }
        if (typeof window === 'undefined' || !window.history || typeof window.history.replaceState !== 'function') {
            return;
        }

        const params = new URLSearchParams(window.location.search);
        const p = this.paramPrefix;
        params.set(p + 'view', this.viewYear + '-' + Calendar._pad2(this.viewMonth));

        if (this.selectionMode === 'range') {
            if (this.value.from) {
                params.set(p + 'from', this.value.from);
            } else {
                params.delete(p + 'from');
            }
            if (this.value.to) {
                params.set(p + 'to', this.value.to);
            } else {
                params.delete(p + 'to');
            }
            params.delete(p + 'date');
        } else {
            if (this.value) {
                params.set(p + 'date', this.value);
            } else {
                params.delete(p + 'date');
            }
            params.delete(p + 'from');
            params.delete(p + 'to');
        }

        const query = params.toString();
        const newUrl = window.location.pathname + (query ? '?' + query : '') + window.location.hash;
        window.history.replaceState(null, '', newUrl);
    }

    // ------------------------------------------------------------------
    // 내부 — DOM 구성/렌더링
    // ------------------------------------------------------------------

    _buildDom() {
        const container = document.getElementById(this.containerId);
        if (!container) {
            throw new Error('Calendar: containerId 요소를 찾을 수 없습니다: ' + this.containerId);
        }
        container.classList.add('wc-cal');

        // 상단 툴바(화살표+월 라벨+오늘)는 리스트박스 열과 달력 열을
        // 아우르는 한 줄(전체 폭)을 차지하고, 그 아래에서만 좌(년/월
        // 리스트박스)/우(요일행+그리드)로 나뉜다. "년"/"월" 라벨은
        // 요일행("일월화수목금토")과 같은 줄에서 시작해야 하므로,
        // 툴바가 아니라 요일행 높이에 맞춘다(_syncYearMonthList() 참고).
        let yearMonthHtml = '';
        if (this.showYearMonthList) {
            yearMonthHtml = '<div class="wc-cal-yearmonth-list">' +
                '<div class="wc-cal-yearmonth-col">' +
                    '<div class="wc-cal-yearmonth-label">년</div>' +
                    '<select class="wc-cal-year-select" size="8"></select>' +
                '</div>' +
                '<div class="wc-cal-yearmonth-col">' +
                    '<div class="wc-cal-yearmonth-label">월</div>' +
                    '<select class="wc-cal-month-select" size="8"></select>' +
                '</div>' +
            '</div>';
        }

        container.innerHTML =
            // 구글 캘린더 헤더 형태 — [오늘] [‹][›] 2026년 10월 순서로 왼쪽 정렬.
            '<div class="wc-cal-toolbar">' +
                '<button type="button" class="wc-cal-btn wc-cal-btn-today" data-action="today">오늘</button>' +
                '<button type="button" class="wc-cal-btn-nav" data-action="prev" title="이전 달">' +
                    '<svg viewBox="0 0 24 24" width="20" height="20"><path d="M15.4 7.4 14 6l-6 6 6 6 1.4-1.4L10.8 12z" fill="currentColor"/></svg>' +
                '</button>' +
                '<button type="button" class="wc-cal-btn-nav" data-action="next" title="다음 달">' +
                    '<svg viewBox="0 0 24 24" width="20" height="20"><path d="M8.6 16.6 10 18l6-6-6-6-1.4 1.4 4.6 4.6z" fill="currentColor"/></svg>' +
                '</button>' +
                '<h3 class="wc-cal-month-label"></h3>' +
            '</div>' +
            '<div class="wc-cal-columns">' +
                yearMonthHtml +
                '<div class="wc-cal-main">' +
                    '<div class="wc-cal-days">' +
                        '<div class="wc-cal-weekday-row"></div>' +
                        '<div class="wc-cal-grid"></div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            (this.memoPopup ? this._memoPopupHtml() : '');

        this.mainEl = container.querySelector('.wc-cal-main');
        this.monthLabelEl = container.querySelector('.wc-cal-month-label');
        this.weekdayRowEl = container.querySelector('.wc-cal-weekday-row');
        this.gridEl = container.querySelector('.wc-cal-grid');
        if (this.memoPopup) {
            this._bindMemoPopup(container);
        }

        container.querySelector('[data-action="prev"]').addEventListener('click', () => this._shiftMonth(-1));
        container.querySelector('[data-action="next"]').addEventListener('click', () => this._shiftMonth(1));
        container.querySelector('[data-action="today"]').addEventListener('click', () => {
            const today = new Date();
            // "오늘"을 누르면 리스트박스의 선택 항목이 화면 중앙으로
            // 오게 한다(그 외 경로 — 화살표/리스트박스 자체 change —
            // 는 기존처럼 "보일 정도로만" 스크롤한다).
            this._nextListScrollAlign = 'center';
            // [2026-09-03 추가] 예전엔 화면(보이는 달)만 오늘로 옮기고
            // 실제 선택값은 안 바꿨다 — "오늘 날짜 칸을 직접 클릭한 것"과
            // 동일하게 취급해 선택값도 갱신하고 콜백도 쏘도록
            // _onDayClick()을 그대로 재사용한다(showMonth()는
            // _applyValue()가 선택된 날짜의 연/월로 뷰까지 함께 옮겨줘서
            // 별도로 안 불러도 됨 — range 모드에서도 그리드의 날짜 칸을
            // 직접 클릭한 것과 완전히 동일한 결과가 되도록 동일 경로 사용).
            const todayIso = Calendar._toIso(today.getFullYear(), today.getMonth() + 1, today.getDate());
            this._onDayClick(todayIso);
        });

        // 그리드 셀은 매 렌더마다 새로 만들어지므로, 리스너를 셀마다
        // 다시 바인딩하는 대신 그리드 컨테이너 하나에 위임한다.
        // 셀 클릭은 직접 처리하지 않고 action('clickCell')로 넘긴다 —
        // Grid.js의 action('cellClick')과 같은 "모든 일은 action()을 거친다" 관례.
        this.gridEl.addEventListener('click', (event) => {
            const dayEl = event.target.closest('[data-date]');
            if (dayEl) {
                this.action('clickCell', { date: dayEl.getAttribute('data-date') }, this);
            }
        });

        // CSS(user-select/none, -webkit-user-drag:none)만으로 막히지
        // 않는 브라우저/엘리먼트 대비 — 드래그 자체를 이벤트 단계에서도 막는다.
        container.addEventListener('dragstart', (event) => event.preventDefault());
        container.addEventListener('selectstart', (event) => {
            // 메모 팝업의 입력칸 안에서는 글자 선택을 허용한다.
            if (this.popupEl && this.popupEl.contains(event.target)) {
                return;
            }
            event.preventDefault();
        });

        if (this.showYearMonthList) {
            this._buildYearMonthList(container);
        }
    }

    _memoPopupHtml() {
        return '<div class="wc-cal-pop" hidden>' +
                '<div class="wc-cal-pop-caption">' +
                    '<span class="wc-cal-pop-date"></span>' +
                    '<button type="button" class="wc-cal-pop-x" data-pop="close" title="닫기" aria-label="닫기">' +
                        '<svg viewBox="0 0 24 24" width="18" height="18"><path d="M19 6.4 17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12z" fill="currentColor"/></svg>' +
                    '</button>' +
                '</div>' +
                '<textarea class="wc-cal-pop-memo" rows="8" placeholder="메모를 입력하세요"></textarea>' +
                '<div class="wc-cal-pop-btns">' +
                    '<button type="button" class="wc-cal-btn wc-cal-btn-primary" data-pop="save">저장</button>' +
                    '<button type="button" class="wc-cal-btn" data-pop="delete">삭제</button>' +
                    '<button type="button" class="wc-cal-btn" data-pop="close">창닫기</button>' +
                '</div>' +
            '</div>';
    }

    /** 메모 팝업 엘리먼트를 잡고 버튼/Esc/바깥 클릭 이벤트를 건다(memoPopup:true일 때만). */
    _bindMemoPopup(container) {
        this.popupEl = container.querySelector('.wc-cal-pop');
        this.popupMemoEl = container.querySelector('.wc-cal-pop-memo');

        // 팝업 버튼도 직접 처리하지 않고 action()으로 넘긴다.
        this.popupEl.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-pop]');
            if (!btn) {
                return;
            }
            const iso = this._popupDate;
            const code = this._popupCode;
            const kind = btn.getAttribute('data-pop');
            if (kind === 'save') {
                this.action('saveMemo', { date: iso, code: code, memo: this.popupMemoEl.value }, this);
            } else if (kind === 'delete') {
                this.action('deleteMemo', { date: iso, code: code }, this);
            } else {
                this.action('closeMemoPopup', {}, this);
            }
        });
        this._bindPopupDrag(container);
        this.popupEl.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                this.action('closeMemoPopup', {}, this);
            }
        });
        // 팝업/날짜 셀 바깥을 누르면 닫는다(셀을 누른 경우는 그 셀 옆에 다시 열리므로 제외).
        this._onDocMouseDown = (event) => {
            if (this._popupDate === null) {
                return;
            }
            if (this.popupEl.contains(event.target) || this.gridEl.contains(event.target)) {
                return;
            }
            this.action('closeMemoPopup', {}, this);
        };
        document.addEventListener('mousedown', this._onDocMouseDown);
    }

    /**
     * 캡션바를 잡고 끌면 팝업을 옮긴다 — _openMemoPopup()의 배치 규칙과
     * 같이 달력 컨테이너(.wc-cal) 밖으로는 못 나가게 가둔다. pointer
     * capture로 커서가 캡션바를 벗어나도 계속 따라온다.
     */
    _bindPopupDrag(container) {
        const caption = this.popupEl.querySelector('.wc-cal-pop-caption');
        caption.addEventListener('pointerdown', (event) => {
            // X 버튼 위에서는 끌기를 시작하지 않는다 — pointer capture가
            // click 대상을 캡션바로 바꿔 버려 닫기가 안 먹기 때문.
            if (event.button !== 0 || event.target.closest('[data-pop]')) {
                return;
            }
            event.preventDefault(); // 끄는 동안 글자 선택/포커스 이동 방지
            const pop = this.popupEl;
            const startX = event.clientX;
            const startY = event.clientY;
            const startLeft = pop.offsetLeft;
            const startTop = pop.offsetTop;
            caption.setPointerCapture(event.pointerId);

            const onMove = (ev) => {
                const maxLeft = Math.max(0, container.clientWidth - pop.offsetWidth);
                const maxTop = Math.max(0, container.clientHeight - pop.offsetHeight);
                const left = Math.min(maxLeft, Math.max(0, startLeft + ev.clientX - startX));
                const top = Math.min(maxTop, Math.max(0, startTop + ev.clientY - startY));
                pop.style.left = left + 'px';
                pop.style.top = top + 'px';
            };
            const onEnd = () => {
                caption.removeEventListener('pointermove', onMove);
                caption.removeEventListener('pointerup', onEnd);
                caption.removeEventListener('pointercancel', onEnd);
            };
            caption.addEventListener('pointermove', onMove);
            caption.addEventListener('pointerup', onEnd);
            caption.addEventListener('pointercancel', onEnd);
        });
    }

    /**
     * 년/월 리스트박스를 채우고 change 이벤트를 건다. 범위(1900년 ~
     * 오늘 연도+15)는 여기서 한 번만 계산 — viewYear가 바뀔 때마다
     * 다시 계산하지 않아서, 화면을 넘겨도 목록 자체는 흔들리지 않는다.
     */
    _buildYearMonthList(container) {
        this.yearListLabelEl = container.querySelector('.wc-cal-yearmonth-col:nth-child(1) .wc-cal-yearmonth-label');
        this.monthListLabelEl = container.querySelector('.wc-cal-yearmonth-col:nth-child(2) .wc-cal-yearmonth-label');
        this.yearSelectEl = container.querySelector('.wc-cal-year-select');
        this.monthSelectEl = container.querySelector('.wc-cal-month-select');

        const todayYear = new Date().getFullYear();
        let yearOptionsHtml = '';
        for (let y = Calendar.YEAR_LIST_START; y <= todayYear + 15; y++) {
            yearOptionsHtml += '<option value="' + y + '">' + y + '</option>';
        }
        this.yearSelectEl.innerHTML = yearOptionsHtml;

        let monthOptionsHtml = '';
        for (let m = 1; m <= 12; m++) {
            monthOptionsHtml += '<option value="' + m + '">' + m + '</option>';
        }
        this.monthSelectEl.innerHTML = monthOptionsHtml;

        // 리스트에서 고르는 건 화면(보이는 달) 이동일 뿐 — 선택값은 안 건드린다.
        this.yearSelectEl.addEventListener('change', () => {
            this.showMonth(parseInt(this.yearSelectEl.value, 10), this.viewMonth);
        });
        this.monthSelectEl.addEventListener('change', () => {
            this.showMonth(this.viewYear, parseInt(this.monthSelectEl.value, 10));
        });
    }

    _shiftMonth(delta) {
        this.viewMonth += delta;
        if (this.viewMonth < 1) {
            this.viewMonth = 12;
            this.viewYear -= 1;
        } else if (this.viewMonth > 12) {
            this.viewMonth = 1;
            this.viewYear += 1;
        }
        this._pendingRangeFrom = null;
        this._syncUrlState();
        this._render();
    }

    _render() {
        // 셀을 새로 그리면 팝업이 붙어 있던 셀이 사라지므로 함께 닫는다
        // (셀 클릭 시엔 _clickCell()이 렌더 뒤에 다시 연다).
        this._closeMemoPopup();
        this.monthLabelEl.textContent = this.viewYear + '년 ' + this.viewMonth + '월';
        this._renderWeekdayRow();
        this._renderGrid();
        this._syncYearMonthList();

        // 보이는 달이 바뀌었으면(생성 직후 첫 렌더 포함) 그 달의 메모를
        // 서버에서 다시 가져온다 — 그리기가 끝난 뒤에 불러야 _show()가
        // 같은 달에 대해 렌더를 건너뛰어도 화면이 비지 않는다.
        if (this.fetchUrl && this._shownYm !== this.viewYear + '-' + this.viewMonth) {
            this.action('show', { year: this.viewYear, month: this.viewMonth }, this);
        }
    }

    /**
     * showYearMonthList가 켜져 있으면 (1) 두 리스트박스의 강조 항목을
     * 현재 viewYear/viewMonth로 맞추고, (2) "년"/"월" 라벨/리스트박스의
     * 높이를 요일행/그리드 높이에 맞춘다.
     *
     * [높이는 최초 1회만 측정해서 고정한다] 처음엔 매 렌더마다 다시
     * 재계산했는데, 그러다 보니 날짜를 클릭하거나 달을 넘길 때마다
     * (레이아웃 서브픽셀 오차 등으로) 리스트박스 크기가 미세하게
     * 흔들리는 문제가 있었다 — 6주(42칸) 그리드라 높이는 사실상 항상
     * 같아야 하므로, 한 번 정해지면 다시 재지 않고 그 값을 계속 쓴다.
     */
    _syncYearMonthList() {
        if (!this.showYearMonthList) {
            return;
        }
        this.yearSelectEl.value = String(this.viewYear);
        this.monthSelectEl.value = String(this.viewMonth);

        // "오늘" 버튼이 예약해둔 정렬 방식(center)이 있으면 이번 한 번만
        // 쓰고 원래대로(nearest) 되돌린다 — 화살표/리스트박스 자체
        // change 등 다른 경로는 계속 "보일 정도로만" 스크롤한다.
        const scrollAlign = this._nextListScrollAlign || 'nearest';
        this._nextListScrollAlign = null;

        const yearOpt = this.yearSelectEl.querySelector('option[value="' + this.viewYear + '"]');
        if (yearOpt) {
            yearOpt.scrollIntoView({ block: scrollAlign });
        }
        const monthOpt = this.monthSelectEl.querySelector('option[value="' + this.viewMonth + '"]');
        if (monthOpt) {
            monthOpt.scrollIntoView({ block: scrollAlign });
        }

        if (this._yearMonthListHeightsFixed) {
            return;
        }
        const labelHeight = this.weekdayRowEl.getBoundingClientRect().height;
        const selectHeight = this.gridEl.getBoundingClientRect().height;
        if (labelHeight > 0 && selectHeight > 0) {
            this.yearListLabelEl.style.height = labelHeight + 'px';
            this.monthListLabelEl.style.height = labelHeight + 'px';
            this.yearSelectEl.style.height = selectHeight + 'px';
            this.monthSelectEl.style.height = selectHeight + 'px';
            this._yearMonthListHeightsFixed = true;
        }
    }

    _renderWeekdayRow() {
        const order = this._weekdayOrder();
        let html = '';
        for (let i = 0; i < order.length; i++) {
            const dow = order[i];
            let cls = 'wc-cal-weekday';
            if (dow === 0) { cls += ' wc-cal-weekday-sun'; }
            if (dow === 6) { cls += ' wc-cal-weekday-sat'; }
            html += '<div class="' + cls + '">' + Calendar.WEEKDAY_LABEL[dow] + '</div>';
        }
        this.weekdayRowEl.innerHTML = html;
    }

    /** weekStart(0=일요일, 1=월요일)를 기준으로 한 주의 요일 인덱스 순서(0=일~6=토 기준값)를 만든다. */
    _weekdayOrder() {
        const order = [];
        for (let i = 0; i < 7; i++) {
            order.push((this.weekStart + i) % 7);
        }
        return order;
    }

    _renderGrid() {
        const today = new Date();
        const cells = this._buildGridDates(this.viewYear, this.viewMonth);

        let html = '';
        for (let i = 0; i < cells.length; i++) {
            const d = cells[i];
            const iso = Calendar._toIso(d.getFullYear(), d.getMonth() + 1, d.getDate());
            let cls = 'wc-cal-day';
            // 이전/다음 달 칸은 회색 처리 대신 '9/27'처럼 월/일로 표시해 구분한다.
            // 매달 1일도 달이 바뀌는 지점이라 이번 달이어도 항상 월/일로 표시한다.
            const isOtherMonth = d.getMonth() + 1 !== this.viewMonth;
            let dayLabel = String(d.getDate());
            let numCls = 'wc-cal-day-num';
            if (isOtherMonth) { cls += ' wc-cal-day-other-month'; }
            if (isOtherMonth || d.getDate() === 1) {
                dayLabel = (d.getMonth() + 1) + '/' + d.getDate();
                numCls += ' wc-cal-day-num-md';
            }
            if (Calendar._sameDate(d, today)) { cls += ' wc-cal-day-today'; }

            const selectionCls = this._selectionClassFor(iso);
            if (selectionCls) { cls += ' ' + selectionCls; }

            let memoHtml = '';
            if (this.memos[iso]) {
                memoHtml = '<div class="wc-cal-day-memo">' + Calendar._escapeHtml(this.memos[iso]) + '</div>';
            }

            // DB 행이 있는 날짜만 data-code(PK)를 단다 — 메모 팝업이 열 때 읽어 간다.
            let codeAttr = '';
            if (this.codes[iso] !== undefined) {
                codeAttr = ' data-code="' + Calendar._escapeHtml(this.codes[iso]) + '"';
            }

            html += '<div class="' + cls + '" data-date="' + iso + '"' + codeAttr + '>' +
                '<span class="' + numCls + '">' + dayLabel + '</span>' +
                memoHtml +
                '</div>';
        }
        this.gridEl.innerHTML = html;
    }

    _selectionClassFor(iso) {
        if (this.selectionMode === 'range') {
            const from = this.value.from;
            const to = this.value.to;
            // from/to 둘 다 있을 때 이전엔 from쪽을 놓치던 버그가 있었다 —
            // "둘 중 하나와 같은가"를 먼저 보는 형태로 단순화해서 고쳤다.
            if ((from && iso === from) || (to && iso === to)) { return 'wc-cal-day-range-endpoint'; }
            if (from && to && iso > from && iso < to) { return 'wc-cal-day-range-between'; }
            return '';
        }
        if (this.value && iso === this.value) { return 'wc-cal-day-selected'; }
        return '';
    }

    /** 항상 6주(42칸)를 채운다 — 3_1(calendar.html)의 buildGridDates()와 동일한 방식. */
    _buildGridDates(year, month1to12) {
        const firstOfMonth = new Date(year, month1to12 - 1, 1);
        const order = this._weekdayOrder();
        const offset = order.indexOf(firstOfMonth.getDay());
        const gridStart = new Date(year, month1to12 - 1, 1 - offset);
        const cells = [];
        for (let i = 0; i < 42; i++) {
            cells.push(new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + i));
        }
        return cells;
    }

    // ------------------------------------------------------------------
    // 정적 유틸/등록부
    // ------------------------------------------------------------------

    static _escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /**
     * HTML 문자열을 순수 문장으로 바꾼다 — <br>과 문단/블록 끝은 줄바꿈,
     * 엔티티(&nbsp; &amp; 등)는 글자로 풀고, script/style 내용은 버린다.
     * DOMParser로 만든 문서는 화면에 붙지 않아 스크립트가 실행되거나
     * 이미지를 불러오지 않는다. 태그가 없으면 원문 그대로 둔다.
     */
    static _htmlToText(html) {
        const s = String(html);
        if (!/<[a-z!\/]/i.test(s)) {
            return s.indexOf('&') === -1 ? s.trim() : Calendar._decodeEntities(s).trim();
        }
        // HTML 원문 속 줄바꿈은 화면상 공백일 뿐이라 먼저 공백으로 접는다.
        const doc = new DOMParser().parseFromString(s.replace(/\r?\n/g, ' '), 'text/html');
        doc.querySelectorAll('script, style').forEach((el) => el.remove());
        doc.querySelectorAll('br').forEach((el) => el.replaceWith('\n'));
        doc.querySelectorAll('p, div, li, tr, h1, h2, h3, h4, h5, h6').forEach((el) => el.append('\n'));
        return doc.body.textContent
            .replace(/ /g, ' ')
            .replace(/[ \t]+\n/g, '\n')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    static _decodeEntities(s) {
        return new DOMParser().parseFromString(s, 'text/html').body.textContent.replace(/ /g, ' ');
    }

    static _pad2(n) {
        return (n < 10 ? '0' : '') + n;
    }

    static _toIso(year, month1to12, day) {
        return year + '-' + Calendar._pad2(month1to12) + '-' + Calendar._pad2(day);
    }

    static _parseIso(iso) {
        if (typeof iso !== 'string') { return null; }
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
        if (!m) { return null; }
        return { year: parseInt(m[1], 10), month: parseInt(m[2], 10), day: parseInt(m[3], 10) };
    }

    static _sameDate(a, b) {
        return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    static _injectStyles() {
        if (document.getElementById('calendar-default-styles')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'calendar-default-styles';
        style.textContent = `
@layer calendar-defaults {
    .wc-cal {
        /* 메모 팝업(.wc-cal-pop)을 셀 기준으로 absolute 배치하기 위한 기준점 */
        position: relative;
        font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
        color: #1f2d3d;
        /* 날짜 셀을 마우스로 눌러 옆 칸까지 끌면 브라우저가 텍스트를
           드래그 선택(파란 하이라이트)하거나, 이미지/링크가 섞여 있으면
           네이티브 drag&drop 고스트가 뜬다 — 달력은 클릭으로만 선택하는
           위젯이라 둘 다 막는다. */
        user-select: none;
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
    }
    .wc-cal * {
        -webkit-user-drag: none;
    }
    .wc-cal-columns { display: flex; gap: 10px; align-items: flex-start; }
    .wc-cal-main { flex: 1; min-width: 0; }
    .wc-cal-yearmonth-list { display: flex; gap: 4px; flex-shrink: 0; }
    .wc-cal-yearmonth-col { display: flex; flex-direction: column; }
    .wc-cal-yearmonth-label {
        display: flex; align-items: center; justify-content: center;
        font-size: 0.8rem; font-weight: 700; color: #3c4043;
    }
    .wc-cal-year-select, .wc-cal-month-select {
        width: 62px; font-size: 0.8rem; border: 1px solid #dadce0; border-radius: 4px;
        padding: 2px;
        /* 평시엔 스크롤바를 숨기고, 커서가 올라갔을 때만 보이게 한다. */
        scrollbar-width: none; /* Firefox */
    }
    .wc-cal-year-select:focus, .wc-cal-month-select:focus {
        /* 브라우저 기본 포커스 테두리(검정/짙은 색 계열)를 옅은
           파란색으로 바꾼다. */
        outline: none;
        border-color: #7fb3f5;
        box-shadow: 0 0 0 2px rgba(66, 133, 244, 0.2);
    }
    .wc-cal-year-select::-webkit-scrollbar, .wc-cal-month-select::-webkit-scrollbar {
        width: 0px; background: transparent; /* Chrome/Edge/Safari */
    }
    .wc-cal-year-select:hover, .wc-cal-month-select:hover {
        scrollbar-width: thin; /* Firefox */
    }
    .wc-cal-year-select:hover::-webkit-scrollbar, .wc-cal-month-select:hover::-webkit-scrollbar {
        width: 8px;
    }
    .wc-cal-year-select:hover::-webkit-scrollbar-thumb, .wc-cal-month-select:hover::-webkit-scrollbar-thumb {
        background: #c1c1c1; border-radius: 4px;
    }
    .wc-cal-toolbar {
        display: flex; align-items: center; justify-content: flex-start;
        gap: 4px; margin-bottom: 8px;
    }
    .wc-cal-month-label {
        font-size: 1.375rem; font-weight: 400; color: #1f1f1f; margin: 0 0 0 12px;
        white-space: nowrap;
    }
    .wc-cal-btn {
        border: 1px solid #dadce0; background: #fff; color: #3c4043;
        border-radius: 6px; padding: 4px 10px; font-size: 0.82rem; cursor: pointer;
    }
    .wc-cal-btn:hover { background: #f1f3f4; }
    /* 오늘 — 테두리만 있는 알약 모양 버튼 */
    .wc-cal-btn-today {
        border: 1px solid #747775; border-radius: 999px; background: transparent;
        color: #1f1f1f; padding: 6px 20px; font-size: 0.875rem; margin-right: 12px;
    }
    .wc-cal-btn-today:hover { background: #f1f3f4; }
    /* 이전/다음 — 테두리 없는 아이콘, 호버 시 원형 배경 */
    .wc-cal-btn-nav {
        width: 32px; height: 32px; padding: 0; border: none; border-radius: 50%;
        background: transparent; color: #444746; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center;
    }
    .wc-cal-btn-nav:hover { background: #e8eaed; }
    .wc-cal-weekday-row, .wc-cal-grid {
        /* minmax(0, 1fr) — 셀 안 메모가 길어도 칸 폭이 늘어나지 않게 */
        display: grid; grid-template-columns: repeat(7, minmax(0, 1fr));
    }
    /* 구글 캘린더 월간 보기 형태 — 요일 이름이 별도 구분선 없이 첫 주
       칸의 맨 위에 붙어 보이게 한다: 요일 칸은 그리드 칸과 같은 좌/우/위
       테두리를 갖고, 첫 주 칸(nth-child 1~7)은 위 테두리를 숨겨 둘이
       한 칸처럼 이어진다. 요일은 작은 회색 글씨로 가운데 정렬. */
    .wc-cal-weekday {
        text-align: center; font-size: 0.6875rem; font-weight: 500; color: #444746;
        padding: 6px 0 2px; border: 1px solid #eeeeee; border-bottom: none; margin-left: -1px;
    }
    .wc-cal-grid .wc-cal-day:nth-child(-n+7) { border-top-color: transparent; }
    .wc-cal-day {
        border: 1px solid #eeeeee; margin: -1px 0 0 -1px; padding: 4px;
        /* 높이 고정 — 메모가 길어도 칸(=주 행)이 늘어나지 않고, 넘치는
           메모는 .wc-cal-day-memo 안에서 스크롤된다. */
        height: 44px; cursor: pointer; display: flex; flex-direction: column; align-items: center;
        min-width: 0;
    }
    .wc-cal-day-memo {
        align-self: stretch; margin-top: 2px; padding: 1px 6px; border-radius: 4px;
        background: #7986cb; color: #fff; font-size: 0.9rem; line-height: 1.5;
        /* 메모의 줄바꿈(\\n)을 셀에 그대로 보여준다 — 긴 줄은 칸 폭에서
           접고(공백 없는 긴 단어도), 칸 높이를 넘치면 세로 스크롤바를
           띄운다(min-height:0 — 칸 안에서 줄어들 수 있어야 스크롤이 생김). */
        white-space: pre-wrap; overflow-wrap: anywhere;
        flex: 0 1 auto; min-height: 0; overflow-x: hidden; overflow-y: auto;
    }
    .wc-cal-pop {
        /* 폭 520px(예전 260px의 2배) — 메모칸도 rows 8(예전 4)로 높이 2배. */
        position: absolute; z-index: 10; width: 520px; max-width: 100%; box-sizing: border-box;
        display: flex; flex-direction: column; gap: 10px; padding: 0 16px 16px;
        background: #f0f4f9; border-radius: 16px; overflow: hidden;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15), 0 8px 24px rgba(0, 0, 0, 0.18);
        user-select: text; -webkit-user-select: text;
    }
    .wc-cal-pop[hidden] { display: none; }
    /* 캡션바 — 날짜/요일 표시, 잡고 끌면 팝업이 이동한다(_bindMemoPopup()). */
    .wc-cal-pop-caption {
        display: flex; align-items: center; justify-content: space-between; gap: 8px;
        margin: 0 -16px; padding: 6px 8px 6px 16px; background: #dbe4f0;
        cursor: move; user-select: none; -webkit-user-select: none; touch-action: none;
    }
    /* 캡션바 오른쪽 X 닫기 — "창닫기" 버튼과 같은 data-pop="close" */
    .wc-cal-pop-x {
        width: 28px; height: 28px; padding: 0; border: none; border-radius: 50%;
        background: transparent; color: #444746; cursor: pointer; flex-shrink: 0;
        display: inline-flex; align-items: center; justify-content: center;
    }
    .wc-cal-pop-x:hover { background: rgba(0, 0, 0, 0.08); }
    .wc-cal-pop-date { font-size: 0.95rem; font-weight: 700; color: #1f2d3d; }
    .wc-cal-pop-memo {
        width: 100%; box-sizing: border-box; resize: vertical; padding: 8px;
        border: 1px solid #dadce0; border-radius: 8px; font: inherit; font-size: 0.85rem;
        background: #fff;
    }
    .wc-cal-pop-memo:focus { outline: none; border-color: #7fb3f5; box-shadow: 0 0 0 2px rgba(66, 133, 244, 0.2); }
    .wc-cal-pop-btns { display: flex; justify-content: flex-end; gap: 6px; }
    .wc-cal-btn:disabled { opacity: 0.45; cursor: default; background: #fff; }
    .wc-cal-btn-primary { background: #1a73e8; border-color: #1a73e8; color: #fff; }
    .wc-cal-btn-primary:hover { background: #1765cc; }
    .wc-cal-day:hover { background: #f8f9fa; }
    .wc-cal-day-num {
        font-size: 0.9rem; width: 24px; height: 24px; line-height: 24px;
        text-align: center; border-radius: 50%;
    }
    /* 월/일 표시(이전/다음 달 칸, 매달 1일) — '9/27'처럼 글자가 길어 원 대신
       가로로 늘어나는 알약 모양 */
    .wc-cal-day-num-md {
        width: auto; min-width: 24px; padding: 0 6px; box-sizing: border-box; border-radius: 12px;
    }
    /* 이전/다음 달 칸만 이탤릭 — 이번 달 1일은 똑바로 */
    .wc-cal-day-other-month .wc-cal-day-num { font-style: italic; }
    .wc-cal-day-today .wc-cal-day-num { background: #e8eaed; font-weight: 700; }
    .wc-cal-day-selected .wc-cal-day-num,
    .wc-cal-day-range-endpoint .wc-cal-day-num {
        background: #1a73e8; color: #fff; font-weight: 700;
    }
    .wc-cal-day-range-endpoint,
    .wc-cal-day-range-between { background: #e8f0fe; }
}
        `;
        document.head.appendChild(style);
    }

    // ------------------------------------------------------------------
    // [2026-09-17 신설] 정적 헬퍼 — Popup으로 열기(ChosungGrid.open()/
    // NumberKeypad.open()/Memo.open()과 동일한 관례). "팝업 안에서 연
    // 날짜피커가 그 팝업 iframe 박스를 못 벗어난다" 문제 — 이 클래스는
    // 원래 containerId 직접 임베드만 지원했으나(iframe 안에 임베드하면
    // 그 iframe 밖으로 절대 못 나감, 우회 불가능한 브라우저 렌더링
    // 경계), 이 static open()으로 `Calendar_entry.php`를 Popup.js의
    // iframeUrl로 띄우면 그 Popup을 만든 창(deco_amd.php처럼 전체
    // 화면을 덮을 수 있는 창)의 document.body에 오버레이가 붙는다.
    // ------------------------------------------------------------------

    /**
     * `Popup.js`의 iframeUrl로 `Calendar_entry.php`를 띄우는 정적
     * 헬퍼 — `Memo.open()`과 동일한 형태.
     *
     * @param {{selectionMode?: ('single'|'range'), initialValue?: (string|{from: ?string, to: ?string}),
     *   showYearMonthList?: boolean, memoPopup?: boolean, callback?: string, sender?: Window,
     *   ptitle?: string, id?: string, entryUrl?: string,
     *   iframeWidth?: (number|string), iframeHeight?: (number|string),
     *   modal?: boolean, draggable?: boolean, onClose?: (id: string) => void}} options
     *   `callback`(선택) — 선택 시 `sender.action(...)`에 쓸 pcmd 이름.
     *   생략하면 `'callback_달력'`(생성자 기본값과 동일).
     * @return {Popup} 호출부가 나중에 직접 닫을 수 있도록 그 Popup 인스턴스를 그대로 반환.
     */
    static open(options) {
        options = options || {};
        if (typeof Popup === 'undefined') {
            throw new Error('Calendar.open(): Popup.js가 먼저 로드돼 있어야 합니다.');
        }
        const entryUrl = options.entryUrl || Calendar._defaultEntryUrl();
        const callbackName = typeof options.callback === 'string' && options.callback !== ''
            ? options.callback
            : 'callback_달력';
        // range 모드의 initialValue({from, to})는 JSON으로, single 모드
        // (문자열)는 그대로 실어 보낸다 — Calendar_entry.php가 selectionMode
        // 값을 보고 어느 쪽으로 해석할지 안다.
        let initialValuePart = '';
        if (options.initialValue != null) {
            const raw = options.selectionMode === 'range'
                ? JSON.stringify(options.initialValue)
                : String(options.initialValue);
            initialValuePart = '&initialValue=' + encodeURIComponent(raw);
        }
        const query = 'callback=' + encodeURIComponent(callbackName) +
            (options.selectionMode === 'range' ? '&selectionMode=range' : '') +
            (options.showYearMonthList ? '&showYearMonthList=1' : '') +
            // Calendar_entry.php는 메모 팝업이 기본으로 켜져 있어,
            // 명시적으로 false를 준 경우에만 끄도록 실어 보낸다.
            (options.memoPopup === false ? '&memoPopup=0' : '') +
            initialValuePart +
            // ChosungGrid_entry.php/NumberKeypad_entry.php/Memo_entry.php와
            // 동일한 이유 — 오래 떠 있는 부모 문서에서 반복해서 열어도
            // 항상 새 요청이 되도록 캐시 버스팅.
            '&_=' + Date.now();
        const iframeUrl = entryUrl + (entryUrl.indexOf('?') === -1 ? '?' : '&') + query;

        return new Popup({
            id: options.id,
            title: typeof options.ptitle === 'string' ? options.ptitle : '날짜 선택',
            modal: options.modal,
            draggable: options.draggable,
            iframeUrl: iframeUrl,
            // 6주 그리드 + (showYearMonthList면 년/월 리스트박스까지)
            // 넉넉히 들어갈 어림값 — showYearMonthList일 때 더 넓게.
            iframeWidth: options.iframeWidth || (options.showYearMonthList ? 420 : 300),
            // [2026-09-17 수정] "달력 팝업이 세로 스크롤이 안 생기게" 지시 —
            // 기존 360px는 실제 필요 높이보다 작아 세로 스크롤이 생겼다.
            // `.wc-cal`에 box-sizing:border-box 지정이 없어(content-box
            // 기본값) 각 요소의 실제 렌더링 높이가 min-height/height보다
            // padding+border만큼 더 크다는 점을 감안해 CSS 값 그대로
            // 계산: 툴바(네비버튼 28+테두리2=30, margin-bottom 8) 38px +
            // 요일행(텍스트~14+상하패딩6=20, 하단border1+패딩4=5,
            // margin-bottom 2) 27px + 6주 그리드(칸당 min-height44+
            // 상하패딩8+상하테두리2=54px, 칸끼리 margin:-1px로 겹치는 5개
            // 경계만큼 뺀 6*54-5) 319px ≈ 위젯 자체 384px + Calendar_entry.php
            // body 상하 패딩(12px×2) 24px ≈ 408px. 브라우저별 폰트
            // 렌더링 오차를 감안해 440px로 올림(로컬 브라우저 없어 실측
            // 불가 — NAS에서 여전히 스크롤이 보이면 이 숫자만 더 키우면
            // 됨).
            iframeHeight: options.iframeHeight || 440,
            onClose: options.onClose,
        });
    }

    /** Calendar.js와 같은 폴더의 Calendar_entry.php를 기본 대상으로 삼는다. */
    static _defaultEntryUrl() {
        return Calendar._SCRIPT_DIR + 'Calendar_entry.php';
    }
}

/**
 * 이 스크립트 자신이 로드된 폴더의 절대경로 — `_defaultEntryUrl()`이
 * 같은 폴더의 `Calendar_entry.php`를 항상 정확히 가리키게 한다
 * (`Memo._SCRIPT_DIR`/`ChosungGrid._SCRIPT_DIR`과 완전히 동일한 계산
 * 방식 — `document.currentScript.src`에서 역산).
 */
Calendar._SCRIPT_DIR = (function () {
    const src = document.currentScript && document.currentScript.src;
    if (!src) {
        return './';
    }
    return src.substring(0, src.lastIndexOf('/') + 1);
})();

Calendar.WEEKDAY_LABEL = ['일', '월', '화', '수', '목', '금', '토'];
/** showYearMonthList의 년도 리스트박스 시작값. */
Calendar.YEAR_LIST_START = 1900;
Calendar.instances = {};
