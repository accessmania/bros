/**
 * Popup — 재사용 가능한 모달/비모달 팝업 컴포넌트.
 *
 * `ScrollGrid.js`/`AGGrid.js`/`ChosungKeypad.js`/`Calendar.js`와 동일한
 * 원칙 — 순수 DOM/이벤트만 다루고 서버 통신은 전혀 모른다. 다른
 * 컴포넌트들은 기존 컨테이너 엘리먼트 안에 그려지지만, 팝업은 성격상
 * 페이지 전체 위에 떠야 하므로 생성자가 직접 `document.body`에 DOM을
 * 붙인다.
 *
 * ## "진짜 팝업창"이 아니다
 * `window.open()`으로 여는 별도 브라우저 창은 만들지 않는다 — 항상 같은
 * 문서 안의 오버레이(모달) 또는 그냥 떠 있는 박스(비모달)다. 그래서
 * 팝업 내용과 호출부는 항상 같은 JS 컨텍스트를 공유하고, `window.opener`/
 * `postMessage` 같은 창간 통신이 필요 없다.
 *
 * ## action(ptype, payload) — 팝업은 항상 같은 창구로 결과를 보고한다
 * 팝업 `html` 안의 스크립트는 무슨 일이 있어도 항상
 * `Popup.instance('그 id').action(ptype, payload)` 하나만 부르면 된다
 * (`ptype`으로 무슨 일이 있었는지 구분, `payload`는 이름 있는 값들을
 * 담은 평범한 객체 — `action(ptype, p1, p2, ...)`처럼 위치로 뜻이
 * 정해지는 매개변수 대신 이름으로 읽게 했다). 호출부는 생성자의
 * `onAction(ptype, payload)` 콜백으로 그 보고를 받는다 — 전역 함수
 * 이름을 하나 정해 공유하는 대신 인스턴스 메서드로 만들어서, 팝업을
 * 여러 개 동시에 띄워도 서로 안 꼬인다.
 *
 * ## 식별자(id)는 콜백에도 자동으로 함께 온다
 * `onAction(ptype, payload, id)`/`onClose(id)` — `id`는 이 팝업을 열 때
 * 넘긴(또는 생략 시 자동 생성된) `options.id`다. 화면에 같은 종류의
 * 팝업이 여러 이유로 동시에 여러 개 떠 있을 수 있는 경우(예: 같은
 * 달력 피커를 "체크인"/"체크아웃"에 각각 씀), `onAction`/`onClose`
 * 콜백 함수 하나를 여러 팝업이 공유해도 이 세 번째(또는 첫 번째)
 * 인자만 보고 "어느 팝업에서 온 보고인지"를 바로 구분할 수 있다 —
 * `payload` 안에 식별자를 직접 담아 보낼 필요가 없다.
 *
 * ## 레이블/드래그/위치는 서로 독립된 옵션
 * - `title`이 없으면 헤더에 글자가 없다("레이블 유무").
 * - `draggable`이 true면 헤더(손잡이)를 잡아 옮길 수 있다 — 헤더는
 *   `title`이 있거나 `draggable`이 true면 렌더링된다(레이블 없이
 *   손잡이만 있는 헤더도 가능). 닫기 버튼은 헤더 안에 항상 있다.
 *   **`title`도 없고 `draggable`도 false면 헤더 자체가 없다** — 그
 *   경우 닫기는 `modal`이면 오버레이 클릭으로, 아니면 팝업 내용
 *   쪽에서 `Popup.instance(id).close()`를 직접 불러야 한다.
 * - `position`으로 뜨는 지점을 미리 지정한다 — `'center'`(기본, 화면
 *   가운데) 또는 `{top, left}`(고정 좌표, 숫자면 px로 취급).
 *
 * ## 모달 vs 비모달
 * `modal:true`(기본)면 화면 전체를 덮는 반투명 오버레이가 함께 뜨고
 * 배경 클릭이 막힌다(오버레이 클릭 시 닫을지는 `closeOnOverlayClick`).
 * `modal:false`면 오버레이가 아예 없어 배경 페이지를 계속 조작할 수
 * 있다 — 팝업 박스 자체의 위치 계산 방식은 둘 다 동일하다(오버레이가
 * 있고 없고는 순전히 "배경을 막는가"의 차이일 뿐, 박스를 어디에
 * 놓을지는 같은 코드 경로를 쓴다).
 *
 * 드래그 구현은 `src/js/board-modal.js`의 `_initDrag()`(pointerdown/
 * move/up + setPointerCapture, 열릴 때는 중앙정렬 CSS를 쓰다가 첫
 * 드래그 시점에만 절대좌표로 전환)와 동일한 패턴을 그대로 재사용했다.
 *
 * ## caption(제목)을 열려 있는 도중에도 바꿀 수 있다
 * `title` 생성자 옵션은 열 때(외부에서) 주입하는 값이고, 이미 열려
 * 있는 팝업의 캡션을 나중에 바꾸고 싶으면 `setCaption(text)`를 부르면
 * 된다(헤더 자체가 없는 팝업 — title도 없고 draggable도 false — 는
 * 바꿀 자리가 없어 조용히 무시한다).
 *
 * ## 다른 경로의 파일을 보여주기 — iframeUrl
 * `html` 대신(또는 그것 없이) `iframeUrl`을 주면 그 경로의 파일을
 * `<iframe>`으로 그대로 띄운다 — 3_1(`calendar.html`)을 iframe으로
 * 얹었던 것과 같은 원리다. `html`은 "이 팝업이 보여줄 내용을 문자열로
 * 직접 조립한다"는 전제인 반면, `iframeUrl`은 완전히 독립된 문서
 * (다른 폴더의 정적 파일, 다른 PHP 화면 등 무엇이든)를 그대로 보여줄
 * 때 쓴다 — 그 문서 자신의 CSS/JS가 이 페이지와 안 섞인다는 것도
 * 차이. 크기는 `iframeWidth`/`iframeHeight`(기본 480×360, 숫자면 px)
 * 로 지정한다. `html`/`iframeUrl` 둘 다 없으면 생성자가 예외를 던진다.
 *
 * ## 뜰 때 애니메이션 — `animation: 'none'|'small'|'large'`
 * 세 가지 중 하나를 고른다(기본값은 **`'none'`** — 여러 종류를 두게
 * 되면서 "켜고 싶은 화면만 명시적으로 켠다"는 쪽으로 정함). 2026-09-04
 * 두 번째 지시로 방향/크기를 아래처럼 재정의했다(처음엔 `'small'`이
 * "살짝 확대되며 페이드인", `'large'`가 "왼쪽에서 오른쪽으로"였으나,
 * 전부 "오른쪽에서 왼쪽으로 이동"하는 방향으로 통일하고 크기만
 * 다르게 함):
 *   - `'none'` — 애니메이션 없음. 오버레이 페이드인도 함께 꺼진다(즉시
 *     나타남).
 *   - `'small'`(우좌일부이동) — 오른쪽에서 왼쪽(제자리)으로 살짝
 *     이동(`translateX(32px) → 0`) + 페이드인.
 *   - `'large'`(우좌전체이동) — 화면 오른쪽 바깥에서 왼쪽(제자리)으로
 *     전체 이동(`translateX(100vw) → 0`) + 페이드인. 전체화면 팝업(예:
 *     `RoomBoard`가 여는 "전표")처럼 화면 전체를 차지하는 팝업에 쓰면
 *     페이지 전환처럼 보인다.
 * 순수 CSS `@keyframes`로 구현해서 DOM에 붙는 순간 자동 재생된다(JS
 * 타이밍 트릭 불필요). 위치 계산에 쓰는 `box.style.transform`(중앙정렬/
 * 드래그)과 겹치지 않도록, 애니메이션은 `box` 바깥이 아니라 그 **안쪽**
 * 래퍼(`.wc-popup-anim`)에만 건다 — 그래서 팝업이 화면 어디에 뜨든
 * (가운데/고정좌표/드래그 후) 애니메이션이 위치 계산과 서로 안 부딪힌다.
 * 닫을 때는 애니메이션 없이 즉시 사라진다(요청 범위 밖, `'none'`/
 * `'small'`/`'large'` 모두 동일).
 *
 * 사용 예:
 *   new Popup({
 *       id: 'my-popup',
 *       title: '메모 입력',
 *       modal: true,
 *       draggable: true,
 *       position: 'center',
 *       animation: 'small', // 기본값은 'none' — 애니메이션을 원하면 명시.
 *       html: '<label>메모 <input id="mp-input"></label>' +
 *             '<button onclick="Popup.instance(\'my-popup\').action(\'save\', ' +
 *             '{ 메모: document.getElementById(\'mp-input\').value }); ' +
 *             'Popup.instance(\'my-popup\').close();">저장</button>',
 *       onAction: (ptype, payload) => {
 *           if (ptype === 'save') { ... }
 *       },
 *       onClose: () => { ... },
 *   });
 */
class Popup {
    /**
     * @param {{id?: string, html?: string, iframeUrl?: string,
     *   iframeWidth?: (number|string), iframeHeight?: (number|string),
     *   title?: string, modal?: boolean,
     *   draggable?: boolean, position?: ('center'|{top: (number|string), left: (number|string)}),
     *   animation?: ('none'|'small'|'large'),
     *   closeOnOverlayClick?: boolean,
     *   onAction?: (ptype: string, payload: object, id: string) => void,
     *   onClose?: (id: string) => void}} options html/iframeUrl 중 하나는 필수.
     */
    constructor(options) {
        options = options || {};
        const hasHtml = typeof options.html === 'string';
        const hasIframeUrl = typeof options.iframeUrl === 'string' && options.iframeUrl !== '';
        if (!hasHtml && !hasIframeUrl) {
            throw new Error('Popup: html 또는 iframeUrl 중 하나는 필수입니다.');
        }
        this.id = options.id || Popup._generateId();
        this.title = typeof options.title === 'string' ? options.title : '';
        this.modal = options.modal !== false;
        this.draggable = options.draggable !== false;
        this.position = options.position || 'center';
        this.animation = (options.animation === 'small' || options.animation === 'large')
            ? options.animation : 'none';
        this.closeOnOverlayClick = options.closeOnOverlayClick !== false;
        this.onAction = typeof options.onAction === 'function' ? options.onAction : null;
        this.onClose = typeof options.onClose === 'function' ? options.onClose : null;
        this._html = hasHtml ? options.html : null;
        this._iframeUrl = hasIframeUrl ? options.iframeUrl : null;
        this._iframeWidth = options.iframeWidth !== undefined ? options.iframeWidth : 480;
        this._iframeHeight = options.iframeHeight !== undefined ? options.iframeHeight : 360;

        Popup.instances[this.id] = this;

        Popup._injectStyles();
        this._buildDom();
    }

    /**
     * 팝업 html 안의 스크립트가 항상 이 메서드 하나만 부르면 된다 —
     * ptype으로 무슨 일이 있었는지, payload(이름 있는 객체)로 그 값을
     * 전달한다. 내부적으로 생성자의 onAction 콜백에 그대로 위임하되,
     * **이 팝업 자신의 id를 세 번째 인자로 함께 넘긴다** — 화면에 같은
     * 종류의 팝업(또는 그 안의 Calendar 등)이 여러 이유로 여러 개
     * 떠 있을 때, onAction 콜백 하나를 여러 인스턴스가 공유해도 이
     * 인자만 보고 "어느 것에서 온 보고인지" 바로 구분할 수 있다 —
     * payload 안에 식별자를 직접 담아 보낼 필요가 없다.
     */
    action(ptype, payload) {
        if (this.onAction) {
            try {
                this.onAction(ptype, payload === undefined ? null : payload, this.id);
            } catch (err) {
                // 호출부 콜백 오류가 이 컴포넌트 동작(팝업 자체)에 영향을 주지 않게 한다.
            }
        }
    }

    /**
     * 이미 열려 있는 팝업의 캡션(제목)을 바꾼다. 헤더 자체가 없는
     * 팝업(title도 없고 draggable도 false로 만들어진 경우)은 캡션을
     * 표시할 자리가 없어 조용히 무시한다.
     */
    setCaption(text) {
        this.title = typeof text === 'string' ? text : '';
        if (this.titleEl) {
            this.titleEl.textContent = this.title;
        }
    }

    /**
     * 팝업을 닫는다 — DOM 제거 + onClose 호출(이 팝업의 id를 인자로
     * 함께 넘김, action()과 동일한 이유) + 인스턴스 등록 해제.
     */
    close() {
        const id = this.id;
        if (this.overlayEl && this.overlayEl.parentNode) {
            this.overlayEl.parentNode.removeChild(this.overlayEl);
        } else if (this.boxEl && this.boxEl.parentNode) {
            this.boxEl.parentNode.removeChild(this.boxEl);
        }
        delete Popup.instances[this.id];
        if (this.onClose) {
            try {
                this.onClose(id);
            } catch (err) {
                // onClose 콜백 오류도 마찬가지로 무시한다.
            }
        }
    }

    // ------------------------------------------------------------------
    // 내부 — DOM 구성
    // ------------------------------------------------------------------

    _buildDom() {
        const hasHeader = this.title !== '' || this.draggable;
        const zIndex = Popup._nextZIndex();

        const box = document.createElement('div');
        box.className = 'wc-popup-box';
        box.style.zIndex = String(zIndex + 1);

        // 헤더+본문을 .wc-popup-anim 래퍼 하나로 감싼다 — 뜰 때 애니메이션을
        // 이 래퍼에만 걸어서, box 자신의 style.transform(위치 계산/드래그가
        // 쓰는 값)과 서로 안 부딪히게 한다.
        let animHtml = '';
        if (hasHeader) {
            const headerCls = 'wc-popup-header' + (this.draggable ? ' wc-popup-header-draggable' : '');
            animHtml += '<div class="' + headerCls + '"' + (this.draggable ? ' data-popup-drag' : '') + '>' +
                '<span class="wc-popup-title">' + Popup._escape(this.title) + '</span>' +
                '<button type="button" class="wc-popup-close-btn" data-popup-close>&times;</button>' +
                '</div>';
        }
        let bodyInnerHtml;
        if (this._iframeUrl) {
            const w = typeof this._iframeWidth === 'number' ? this._iframeWidth + 'px' : String(this._iframeWidth);
            const h = typeof this._iframeHeight === 'number' ? this._iframeHeight + 'px' : String(this._iframeHeight);
            bodyInnerHtml = '<iframe class="wc-popup-iframe" src="' + Popup._escape(this._iframeUrl) + '" ' +
                'style="width:' + Popup._escape(w) + '; height:' + Popup._escape(h) + ';" ' +
                'title="popup content"></iframe>';
        } else {
            bodyInnerHtml = this._html;
        }
        animHtml += '<div class="wc-popup-body">' + bodyInnerHtml + '</div>';
        // animation:'none'(기본)이면 추가 클래스가 없어 .wc-popup-anim에
        // 아무 @keyframes도 안 걸린다 — 'small'/'large'만 각각의 클래스로
        // 자기 keyframes를 켠다(_injectStyles() 참고).
        const animWrapperCls = 'wc-popup-anim' + (this.animation !== 'none' ? ' wc-popup-anim-' + this.animation : '');
        box.innerHTML = '<div class="' + animWrapperCls + '">' + animHtml + '</div>';

        this.titleEl = box.querySelector('.wc-popup-title');
        box.querySelector('[data-popup-close]')?.addEventListener('click', () => this.close());

        this._applyPosition(box);

        // [2026-09-09 수정 — 진짜 버그 발견, 아래 _applyPosition() 참고]
        // 'center' 위치는 더 이상 position:fixed+left:50%+transform으로
        // 박스 스스로 중앙에 자리잡지 않는다 — 대신 이 박스를 담는 부모
        // (모달이면 overlay, 아니면 새로 만드는 position-root)를
        // flex(justify-content/align-items:center)로 만들어 그 부모가
        // 중앙정렬을 대신 해준다. 그래야 박스의 "내용에 맞춘 폭" 계산이
        // 뷰포트 절반으로 잘리는 버그를 피할 수 있다.
        const isCentered = this.position === 'center' || !this.position;

        if (this.modal) {
            const overlay = document.createElement('div');
            // animation:'none'이면 오버레이 페이드인도 함께 꺼서 즉시
            // 나타나게 한다(박스만 무애니메이션이고 오버레이만 남아
            // 페이드인하면 어색하다).
            overlay.className = 'wc-popup-overlay' + (this.animation !== 'none' ? ' wc-popup-overlay-animated' : '');
            overlay.style.zIndex = String(zIndex);
            overlay.appendChild(box);
            if (this.closeOnOverlayClick) {
                overlay.addEventListener('click', (event) => {
                    if (event.target === overlay) {
                        this.close();
                    }
                });
            }
            document.body.appendChild(overlay);
            this.overlayEl = overlay;
        } else if (isCentered) {
            // [2026-09-09 추가] 비모달(배경 안 막음)이면서 중앙정렬일
            // 때도 모달과 동일한 flex 중앙정렬 래퍼가 필요하다 — 다만
            // 오버레이처럼 배경을 막으면 안 되므로 배경색/클릭 차단
            // 없이 `pointer-events: none`으로 클릭을 투과시키고, 박스
            // 자신에서만 `pointer-events: auto`로 되살린다.
            const posRoot = document.createElement('div');
            posRoot.className = 'wc-popup-position-root';
            posRoot.style.zIndex = String(zIndex);
            posRoot.appendChild(box);
            document.body.appendChild(posRoot);
            this.overlayEl = posRoot;
        } else {
            document.body.appendChild(box);
            this.overlayEl = null;
        }

        this.boxEl = box;

        if (this.draggable && hasHeader) {
            this._initDrag(box.querySelector('[data-popup-drag]'), box);
        }
    }

    /**
     * position:'center'|{top,left}를 박스에 적용한다.
     *
     * [2026-09-09 수정 — 진짜 원인 발견] "팝업이 좁게 뜨고 스크롤바까지
     * 생기는데 드래그하면 바로잡힘" 문제를 실제로 브라우저에서 열어
     * DOM을 직접 측정해 원인을 찾음 — 예전엔 `position:fixed +
     * left:50%/top:50% + transform:translate(-50%,-50%)`로 중앙정렬
     * 했는데, `width`가 `auto`(내용에 맞춘 자동 폭)인 상태에서 `right`
     * 는 안 주고 `left`만 주면, CSS 스펙상 "사용 가능한 폭"을
     * `containing block 폭 - left`로 계산한다(`transform`은 페인트
     * 단계 연산이라 이 레이아웃 계산에는 전혀 반영되지 않는다) —
     * `left:50%`라서 사용 가능한 폭이 **항상 뷰포트의 절반**으로
     * 잘려버렸다(실측: 뷰포트 1644px에서 박스가 정확히 822px=1644/2로
     * 클램프됨, 오차 없이 정확히 일치). `iframeWidth`를 아무리 정확히
     * 계산해도 팝업 내용이 뷰포트 절반보다 넓으면 무조건 잘렸고, 그
     * 잘린 폭 안에서 내용(ag-grid 등)이 가로 스크롤을 만들었다. 드래그
     * 하면 바로잡히는 이유도 이걸로 설명됨 — 드래그 시작 시
     * `_initDrag()`가 `transform:none` + 절대 픽셀 `left`로 바꿔버려서
     * 이 계산 자체가 사라지기 때문.
     *
     * 고친 방법: `position:fixed+left:50%+transform`으로 박스 스스로
     * 중앙에 자리잡는 방식을 버리고, 박스를 감싼 부모(모달이면
     * overlay, 아니면 `_buildDom()`이 새로 만드는
     * `.wc-popup-position-root`)를 `display:flex;
     * align-items:center; justify-content:center;`로 만들어 그
     * 플렉스박스가 중앙정렬을 대신하게 한다(`_injectStyles()` 참고) —
     * flex item의 폭 계산은 이런 클램프가 없다. 그래서 'center'일 때는
     * 이 함수가 박스에 위치 스타일을 아예 안 준다(부모의 flex 배치에
     * 전부 맡김). `{top,left}` 같은 명시적 좌표 지정은 원래도 이 버그와
     * 무관해(양쪽 다 절대값이라 transform이 안 끼어듦) 그대로 유지.
     */
    _applyPosition(box) {
        if (this.position === 'center' || !this.position) {
            return;
        }
        box.style.position = 'fixed';
        const top = this.position.top;
        const left = this.position.left;
        box.style.top = (typeof top === 'number' ? top + 'px' : String(top));
        box.style.left = (typeof left === 'number' ? left + 'px' : String(left));
    }

    /** board-modal.js::_initDrag()와 동일한 패턴(pointerdown/move/up + setPointerCapture). */
    _initDrag(handle, box) {
        if (!handle) {
            return;
        }

        let dragging = false;
        let startX = 0;
        let startY = 0;
        let startLeft = 0;
        let startTop = 0;

        handle.addEventListener('pointerdown', (event) => {
            if (event.target.closest('[data-popup-close]')) {
                return;
            }
            const rect = box.getBoundingClientRect();
            startX = event.clientX;
            startY = event.clientY;
            startLeft = rect.left;
            startTop = rect.top;
            // 처음 끌 때만 중앙정렬(부모 flex, 또는 예전 방식의 transform)
            // 대신 절대 좌표로 전환한다 — 그 전까지는 _applyPosition()이
            // 이미 계산해둔 값(또는 'center'면 부모 flex 배치)이 그대로
            // 유지된다. [2026-09-09 추가] 'center' 위치는 이제 박스 자신에
            // position:fixed가 안 걸려 있으므로(_applyPosition() 참고,
            // 부모 flex 컨테이너가 중앙정렬을 대신함) 여기서 처음으로
            // position:fixed를 걸어야 절대좌표 드래그가 실제로 먹는다 —
            // {top,left} 명시적 위치였던 경우는 이미 fixed라 무해한 재지정.
            box.style.position = 'fixed';
            box.style.transform = 'none';
            box.style.left = startLeft + 'px';
            box.style.top = startTop + 'px';
            dragging = true;
            handle.setPointerCapture(event.pointerId);
        });

        handle.addEventListener('pointermove', (event) => {
            if (!dragging) {
                return;
            }
            box.style.left = (startLeft + (event.clientX - startX)) + 'px';
            box.style.top = (startTop + (event.clientY - startY)) + 'px';
        });

        const stopDrag = () => { dragging = false; };
        handle.addEventListener('pointerup', stopDrag);
        handle.addEventListener('pointercancel', stopDrag);
    }

    // ------------------------------------------------------------------
    // 정적 유틸/등록부
    // ------------------------------------------------------------------

    static instance(id) {
        return Popup.instances[id] || null;
    }

    static _generateId() {
        return 'popup' + (++Popup._idSeq);
    }

    static _nextZIndex() {
        Popup._zIndexSeq += 2; // 오버레이/박스 한 쌍마다 2씩 — 나중에 연 팝업이 항상 위로 온다.
        return Popup._zIndexSeq;
    }

    static _escape(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    static _injectStyles() {
        if (document.getElementById('popup-default-styles')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'popup-default-styles';
        style.textContent = `
@layer popup-defaults {
    .wc-popup-overlay {
        position: fixed; inset: 0; background: rgba(0, 0, 0, 0.4);
        /* [2026-09-09 수정] 중앙정렬을 여기(부모)의 flex로 옮겼다 —
           _applyPosition()의 독스블록에 적힌 이유(position:fixed+
           left:50%+transform으로 박스 스스로 중앙정렬하면 auto-width
           박스의 shrink-to-fit 폭이 뷰포트의 절반으로 잘리는 CSS 버그)
           참고. */
        display: flex; align-items: center; justify-content: center;
    }
    .wc-popup-overlay-animated {
        animation: wc-popup-overlay-in 0.16s ease-out;
    }
    /* [2026-09-09 추가] 비모달(배경 안 막음) + 중앙정렬 조합을 위한
       래퍼 — .wc-popup-overlay와 같은 flex 중앙정렬이지만, 배경색도
       없고 클릭도 막지 않는다(자신은 pointer-events:none으로 클릭을
       투과시키고, 박스 자신에서만 도로 auto로 되살림). */
    .wc-popup-position-root {
        position: fixed; inset: 0; pointer-events: none;
        display: flex; align-items: center; justify-content: center;
    }
    .wc-popup-position-root .wc-popup-box {
        pointer-events: auto;
    }
    .wc-popup-box {
        background: #fff; border-radius: 8px; min-width: 280px; max-width: 90vw;
        box-shadow: 0 8px 28px rgba(0, 0, 0, 0.28);
        font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif;
        color: #1f2d3d; overflow: hidden;
    }
    /* animation:'none'(기본)이면 이 클래스만 붙고 keyframes는 없다 —
       'small'/'large'는 각각 .wc-popup-anim-small/-large가 추가로 붙어
       자기 keyframes를 켠다(3가지 종류, Popup.js 상단 docblock 참고). */
    .wc-popup-anim-small {
        animation: wc-popup-in-small 0.16s ease-out;
    }
    .wc-popup-anim-large {
        animation: wc-popup-in-large 0.32s ease-out;
    }
    @keyframes wc-popup-overlay-in {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    @keyframes wc-popup-in-small {
        from { opacity: 0; transform: translateX(32px); }
        to { opacity: 1; transform: translateX(0); }
    }
    @keyframes wc-popup-in-large {
        from { opacity: 0; transform: translateX(100vw); }
        to { opacity: 1; transform: translateX(0); }
    }
    .wc-popup-header {
        display: flex; align-items: stretch; justify-content: space-between;
        gap: 8px; padding: 0 0 0 12px; min-height: 40px; background: #f5f6f7;
        border-bottom: 1px solid #e5e7eb;
    }
    .wc-popup-header-draggable { cursor: move; }
    .wc-popup-title {
        display: flex; align-items: center;
        font-size: 0.92rem; font-weight: 700;
    }
    .wc-popup-close-btn {
        border: none; background: transparent; font-size: 1.1rem; line-height: 1;
        color: #6b7280; cursor: pointer; padding: 0 14px;
        display: flex; align-items: center; justify-content: center;
    }
    /* 커서가 올라가면 배경색을 반전시키고, 그 배경이 헤더(레이블) 전체
       높이만큼 채워지게 한다(버튼이 header의 align-items:stretch로
       이미 전체 높이만큼 늘어나 있어, 배경색만 주면 자연히 그렇게 된다). */
    .wc-popup-close-btn:hover { background: #6b7280; color: #fff; }
    .wc-popup-body { padding: 16px; }
    .wc-popup-iframe { border: none; display: block; max-width: 100%; }
}
        `;
        document.head.appendChild(style);
    }
}

Popup.instances = {};
Popup._idSeq = 0;
Popup._zIndexSeq = 1000;
