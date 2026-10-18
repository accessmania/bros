/**
 * Resize — MS Access의 mResize.bas(Top/Left/Width/Height 절대좌표로 컨트롤을
 * 배치·리사이즈하는 VBA 모듈)를 웹으로 옮긴 유틸리티. "pform"은
 * position:relative인 컨테이너 엘리먼트, "pctl"은 position:absolute인 자식
 * 엘리먼트(이 프로젝트에서는 주로 iframe)에 대응한다 — Access 컨트롤의
 * 절대좌표 배치 방식과 원리가 같아서 1:1로 옮겼다(md/
 * 2026-09-06-resize-js-설계논의.md 참고).
 *
 * ## 함수명 — 영문이 정식, 한글은 별칭(절충안)
 * 함수 본체는 영문 이름(fillRect/dockBottom/rightOf 등) 하나로만 존재하고,
 * VBA 원본과 그대로 대조하고 싶을 때 쓸 수 있도록 한글 이름(사각형채우기/
 * 위치_아래쪽끝에위치/위치_컨트롤오른쪽에위치 등)을 같은 함수를 가리키는
 * 별칭으로 붙여뒀다 — `Resize.fillRect === Resize.사각형채우기`. 로직은
 * 한 곳에만 있고 이름만 둘이다.
 *
 * ## 모든 함수가 (container, el, ...) 시그니처로 통일돼 있다
 * `above`/`rightOf`/`setWidth`/`matchSize`처럼 실제로는 container를 안 쓰는
 * 함수도 첫 인자로 container를 받는다 — `Resize.apply()`가 규칙을 전부 같은
 * 방식(`fn(container, el, ...args)`)으로 호출할 수 있게 하기 위한 통일이다.
 * VBA 원본도 `p크기_너비와높이동일하게(pform, pctl, ...)`처럼 pform을 받고도
 * 안 쓰는 함수가 이미 있었으니, 이 통일이 원본과 완전히 동떨어진 결정은
 * 아니다.
 *
 * ## VBA와 다르게 뺀 것 — kmaxWidth/kmaxHeight 클램프
 * VBA는 `WindowWidth`/`WindowHeight`가 트윕 단위라 30000/17000으로 상한을
 * 씌웠지만, 웹은 `clientWidth`/`clientHeight`가 이미 컨테이너의 실측 px
 * 값이라 그런 인위적 상한이 필요 없다 — `getMaxWidth()`/`getMaxHeight()`는
 * 그대로 실측값을 반환한다.
 *
 * ## VBA 원본의 중복 함수 통합
 * 원본엔 완전히 같은 로직의 함수가 두 벌씩 있었다 — `p위치_위쪽에위치`와
 * `p위치_컨트롤위쪽에위치`(로직 100% 동일), `p아래로붙이기`와
 * `p위치_아래쪽끝에위치`(로직 동일, 기본 gap만 10/0으로 다름). 여기서는
 * `above()`/`dockBottom()` 하나씩만 구현하고 나머지 이름은 그 별칭(또는
 * 기본값만 다른 얇은 래퍼)으로 둔다.
 *
 * ## position:relative/absolute는 apply()가 알아서 건다
 * `Resize.apply(container, layout)`을 부르면, 아직 `position`이
 * `static`인 container/각 엘리먼트에 한해 자동으로 `relative`/`absolute`를
 * 걸어준다 — Access 컨트롤이 애초에 절대좌표 배치가 기본이라 이런 설정 자체가
 * 필요 없었던 것과 같은 경험을 주기 위함이다. 이미 CSS로 지정해뒀으면 그대로
 * 존중하고 안 건드린다.
 *
 * ## apply(container, layout) — VBA의 resize(presizecmd, ...)를 배열로 선언화
 * VBA의 `resize(presizecmd, pform, pnickname, pParameters, pformnames, ...)`
 * 는 실제 계산은 안 하고 `presizecmd.resize(...)`에 위임만 했다 — "닉네임 +
 * 파라미터 표를 순회하며 일괄 적용"하는 구조로 보고, 그 표를 배열 하나로
 * 선언화했다:
 *
 *   const layout = [
 *     { id: 'a', top: 10, left: 10, fn: 'fillWidthToMargin', args: [10] },
 *     { id: 'a', fn: 'setHeight', args: [60] },
 *     { id: 'b', fn: 'below', args: ['a', 10] },
 *   ];
 *   Resize.apply(document.getElementById('main'), layout);
 *
 * 각 규칙은 `{id, top?, left?, fn?, args?, visible?}` 형태다:
 *   - `id`: 대상 엘리먼트의 DOM id.
 *   - `top`/`left`(선택, px): 리터럴 위치를 직접 지정(VBA에서 `pctl.Top = 10`
 *     처럼 한 줄로 끝내던 것과 동일한 자리 — 이 프로젝트가 만든 확장이지
 *     VBA 원본 함수는 아니다).
 *   - `fn`(선택): `Resize` 위의 함수 이름(영문/한글 별칭 아무거나).
 *   - `args`(선택): 그 함수에 넘길 인자 배열. 문자열이 layout 안의 다른
 *     `id`와 같으면 그 엘리먼트로 자동 치환된다(예: `args:['iframe1', 10]`
 *     의 `'iframe1'`).
 *   - `visible`(선택, 기본 true): `boolean` 또는 `(container, el) => boolean`
*     함수. `false`(또는 함수의 반환값이 `false`)면 `setVisible()`로 실제
*     화면에서 숨기고(VBA의 `pctl.Visible`에 대응, `hidden` 속성 사용) 위치/
*     크기 계산도 건너뛴다 — 함수로 주면 매 apply()마다(리사이즈될 때마다)
*     다시 평가되므로 "컨테이너가 좁아지면 사이드바를 숨긴다"처럼 반응형
*     조건을 표현할 수 있다.
 * 배열 순서가 곧 의존관계다 — 뒤 규칙은 앞 규칙이 이미 정해둔 위치/크기를
 * 읽어서(`el.offsetTop`/`offsetLeft`/`offsetWidth`/`offsetHeight`) 참조할 수
 * 있다. 요소를 못 찾거나 함수 이름을 모르면 `console.warn`만 남기고 나머지
 * 규칙은 계속 진행한다(VBA의 `On Error Resume Next`와 같은 취지 — 규칙
 * 하나의 실수가 전체를 막지 않는다).
 *
 * ## observe(container, layout) — VBA의 Timer 폴링(ptimeinterval)을 대체
 * `apply()`를 한 번 실행한 뒤, container 자신의 크기가 바뀔 때마다(창 크기뿐
 * 아니라 좌측메뉴 폭이 바뀌거나 컨테이너가 다른 iframe 안에서 줄어드는
 * 경우도 포함) `ResizeObserver`로 자동 재적용한다 — VBA가 리사이즈 이벤트를
 * 못 믿어 Timer로 폴링하던 것을 웹의 네이티브 API로 대체한 것.
 *
 * ## 부모 iframe이 리사이즈됐다고 그 안 화면에 알려주는 것은 범위 밖
 * 이 파일은 컨테이너 "안"의 iframe들을 배치할 뿐, 크기가 바뀐 iframe의
 * "안쪽" 문서에 그 사실을 알리지는 않는다. 필요해지면 이 프로젝트가 이미
 * 확립한 `iframe.contentWindow.action('resize', {width, height})` 관용구
 * (md/2026-09-05-deco-부모자식-iframe-통신.md 참고)를 `apply()` 호출부에서
 * 직접 추가하면 된다.
 *
 * 사용 예:
 *   const layout = [
 *     { id: 'header', top: 10, left: 10, fn: 'fillWidthToMargin', args: [10] },
 *     { id: 'header', fn: 'setHeight', args: [60] },
 *     { id: 'sidebar', fn: 'below', args: ['header', 10] },
 *     { id: 'sidebar', left: 10, fn: 'setWidth', args: [200] },
 *     { id: 'sidebar', fn: 'fillHeightDown', args: [10] },
 *     { id: 'main', fn: 'below', args: ['header', 10] },
 *     { id: 'main', fn: 'rightOf', args: ['sidebar', 10] },
 *     { id: 'main', fn: 'fillWidthToMargin', args: [10] },
 *     { id: 'main', fn: 'fillHeightDown', args: [10] },
 *   ];
 *   Resize.observe(document.getElementById('app'), layout);
 */
const Resize = {};

// ------------------------------------------------------------------
// 원시 함수(영문 정식 이름)
// ------------------------------------------------------------------

/** getMaxWidth — container의 실제 가로 폭(px). */
Resize.getMaxWidth = function (container) {
    return container.clientWidth;
};

/** getMaxHeight — container의 실제 세로 높이(px). */
Resize.getMaxHeight = function (container) {
    return container.clientHeight;
};

/** fillRect(사각형채우기) — 좌상단에 gap만큼 띄운 채 컨테이너 전체를 채운다. */
Resize.fillRect = function (container, el, gap) {
    if (gap === undefined) { gap = 10; }
    el.style.top = gap + 'px';
    el.style.left = gap + 'px';
    el.style.width = (Resize.getMaxWidth(container) - gap * 2) + 'px';
    el.style.height = (Resize.getMaxHeight(container) - gap * 2) + 'px';
};

/** fillWidthToMargin(너비_우측여백꽉채우기) — 지금 위치(left)에서 우측 여백까지 너비만 채운다. */
Resize.fillWidthToMargin = function (container, el, gap, rate) {
    if (gap === undefined) { gap = 0; }
    if (rate === undefined) { rate = 1; }
    el.style.width = ((Resize.getMaxWidth(container) - el.offsetLeft) * rate - gap) + 'px';
};

/** fillHeightDown(높이_현재위치에서아래로꽉채움) — 지금 위치(top)에서 아래 여백까지 높이만 채운다. */
Resize.fillHeightDown = function (container, el, gap, rate) {
    if (gap === undefined) { gap = 0; }
    if (rate === undefined) { rate = 1; }
    el.style.height = ((Resize.getMaxHeight(container) - el.offsetTop) * rate - gap) + 'px';
};

/** fillRectFromCurrentTop(사각형채우기_현재Top에서) — 너비는 우측 여백까지, 높이는 지금 위치에서 아래로. */
Resize.fillRectFromCurrentTop = function (container, el, gap) {
    if (gap === undefined) { gap = 10; }
    Resize.fillWidthToMargin(container, el, gap);
    Resize.fillHeightDown(container, el, gap);
};

/** centerTop(위치_top가운데로) — 세로 중앙(가로 위치는 안 건드림, VBA 원본에도 가로 중앙 함수는 없다). */
Resize.centerTop = function (container, el) {
    el.style.top = ((container.clientHeight / 2) - (el.offsetHeight / 2)) + 'px';
};

/** dockBottom(위치_아래쪽끝에위치) — 하단에 도킹. */
Resize.dockBottom = function (container, el, gap) {
    if (gap === undefined) { gap = 0; }
    el.style.top = (Resize.getMaxHeight(container) - el.offsetHeight - gap) + 'px';
};

/** dockRight(위치_우측끝에위치) — 우측에 도킹. */
Resize.dockRight = function (container, el, gap) {
    if (gap === undefined) { gap = 0; }
    el.style.left = (Resize.getMaxWidth(container) - el.offsetWidth - gap) + 'px';
};

/** above(위치_컨트롤위쪽에위치, 원본 중복 통합) — otherEl 바로 위에 붙인다. */
Resize.above = function (container, el, otherEl, gap) {
    if (gap === undefined) { gap = 0; }
    el.style.top = (otherEl.offsetTop - el.offsetHeight - gap) + 'px';
};

/** below(위치_컨트롤아래쪽에위치) — otherEl 바로 아래에 붙인다. */
Resize.below = function (container, el, otherEl, gap) {
    if (gap === undefined) { gap = 0; }
    el.style.top = (otherEl.offsetTop + otherEl.offsetHeight + gap) + 'px';
};

/** rightOf(위치_컨트롤오른쪽에위치) — otherEl 바로 오른쪽에 붙인다. */
Resize.rightOf = function (container, el, otherEl, gap) {
    if (gap === undefined) { gap = 0; }
    el.style.left = (otherEl.offsetLeft + otherEl.offsetWidth + gap) + 'px';
};

/** leftOf(위치_컨트롤왼쪽에위치) — otherEl 바로 왼쪽에 붙인다. */
Resize.leftOf = function (container, el, otherEl, gap) {
    if (gap === undefined) { gap = 0; }
    el.style.left = (otherEl.offsetLeft - el.offsetWidth - gap) + 'px';
};

/** fillWidthToControl(너비_우측컨트롤사이매꾸기) — 지금 위치부터 otherEl 왼쪽 끝까지 너비를 채운다. */
Resize.fillWidthToControl = function (container, el, otherEl, gap, rate) {
    if (gap === undefined) { gap = 0; }
    if (rate === undefined) { rate = 1; }
    el.style.width = ((otherEl.offsetLeft - el.offsetLeft) * rate - gap) + 'px';
};

/** fillHeightToControl(높이_아래쪽컨트롤사이매꾸기) — 지금 위치부터 otherEl 위쪽 끝까지 높이를 채운다. */
Resize.fillHeightToControl = function (container, el, otherEl, gap, rate) {
    if (gap === undefined) { gap = 0; }
    if (rate === undefined) { rate = 1; }
    el.style.height = ((otherEl.offsetTop - el.offsetTop) * rate - gap) + 'px';
};

/** setWidth(너비) — 너비를 리터럴 값(px)으로 지정한다. */
Resize.setWidth = function (container, el, w) {
    el.style.width = w + 'px';
};

/** setHeight(높이) — 높이를 리터럴 값(px)으로 지정한다. */
Resize.setHeight = function (container, el, h) {
    el.style.height = h + 'px';
};

/** matchSize(크기_너비와높이동일하게) — otherEl과 같은 너비/높이로 맞춘다. */
Resize.matchSize = function (container, el, otherEl) {
    el.style.width = otherEl.offsetWidth + 'px';
    el.style.height = otherEl.offsetHeight + 'px';
};

/**
 * setVisible(보이기) — VBA의 pctl.Visible에 대응. visible이 false면
 * hidden 속성을 걸어 화면에서 완전히 지운다(요소는 그대로 DOM에 남아있고,
 * 위치/크기 값도 안 지워진다 — 다시 true가 되면 마지막 자리 그대로
 * 나타난다). apply()가 규칙마다 자동으로 부르므로 보통 layout 배열의
 * `visible` 필드로만 다루면 되고, 이 함수를 직접 부를 일은 드물다.
 */
Resize.setVisible = function (container, el, visible) {
    el.hidden = !visible;
    // hidden 속성만으로는 부족하다 — 브라우저 기본 스타일시트의
    // [hidden]{display:none}은 속성 셀렉터라 특이도가 낮아서, 호출부
    // CSS가 그 요소에 ID/클래스 셀렉터로 display를 지정해뒀으면(흔한
    // 경우 — position:absolute 배치 대상은 보통 display:block을
    // 같이 준다) hidden이 걸려도 화면에서 안 사라진다(1_7의 #b17-modal
    // 이 겪었던 것과 같은 함정, resize.md 참고). inline style은 그
    // 특이도 문제 자체가 없어(어떤 셀렉터보다도 우선) 항상 이긴다 —
    // hidden 속성은 시맨틱/스크린리더용으로 계속 걸어두고, 실제 화면
    // 표시는 inline display로 확정한다. visible이 true면 빈 문자열로
    // 되돌려 호출부 CSS(display:block 등)가 다시 정상 적용되게 한다.
    if (visible) {
        el.style.display = '';
    } else {
        el.style.display = 'none';
    }
};

// ------------------------------------------------------------------
// 한글 별칭(절충안) — 실제 구현은 위 영문 함수 하나뿐이다.
// ------------------------------------------------------------------
Object.assign(Resize, {
    보이기: Resize.setVisible,
    사각형채우기: Resize.fillRect,
    사각형채우기_현재Top에서: Resize.fillRectFromCurrentTop,
    높이_현재위치에서아래로꽉채움: Resize.fillHeightDown,
    위치_top가운데로: Resize.centerTop,
    위치_아래쪽끝에위치: Resize.dockBottom,
    // VBA 원본은 p아래로붙이기(기본 gap=10)와 p위치_아래쪽끝에위치(기본
    // gap=0)가 로직은 완전히 같고 기본 gap만 달랐다 — dockBottom을 그대로
    // 부르되 기본값만 다르게 감싼다.
    아래로붙이기: function (container, el, gap) {
        if (gap === undefined) { gap = 10; }
        return Resize.dockBottom(container, el, gap);
    },
    위치_우측끝에위치: Resize.dockRight,
    // VBA 원본은 p위치_위쪽에위치와 p위치_컨트롤위쪽에위치가 완전히 같은
    // 로직의 중복이었다 — 하나(above)로 통합.
    위치_위쪽에위치: Resize.above,
    위치_컨트롤위쪽에위치: Resize.above,
    위치_컨트롤아래쪽에위치: Resize.below,
    위치_컨트롤오른쪽에위치: Resize.rightOf,
    위치_컨트롤왼쪽에위치: Resize.leftOf,
    너비_우측컨트롤사이매꾸기: Resize.fillWidthToControl,
    높이_아래쪽컨트롤사이매꾸기: Resize.fillHeightToControl,
    너비_우측여백꽉채우기: Resize.fillWidthToMargin,
    너비: Resize.setWidth,
    높이: Resize.setHeight,
    크기_너비와높이동일하게: Resize.matchSize,
});

// ------------------------------------------------------------------
// 오케스트레이터
// ------------------------------------------------------------------

/** 위 클래스 docblock의 "apply(container, layout)" 절 참고. */
Resize.apply = function (container, layout) {
    if (!container || !Array.isArray(layout)) { return; }
    if (getComputedStyle(container).position === 'static') {
        container.style.position = 'relative';
    }
    const byId = {};
    layout.forEach(function (rule) {
        if (byId[rule.id]) { return; }
        const el = document.getElementById(rule.id);
        byId[rule.id] = el;
        if (el && getComputedStyle(el).position === 'static') {
            el.style.position = 'absolute';
        }
    });
    layout.forEach(function (rule) {
        const el = byId[rule.id];
        if (!el) {
            console.warn('Resize.apply: 요소를 찾을 수 없습니다 — id=' + rule.id);
            return;
        }
        // visible은 boolean 리터럴(true/false, 기본 true)뿐 아니라
        // (container, el) => boolean 함수도 받는다 — 매 apply()마다(즉
        // 리사이즈될 때마다) 다시 평가되므로 "컨테이너가 좁아지면 이
        // 패널을 숨긴다" 같은 반응형 조건을 표현할 수 있다. 결과는
        // setVisible()로 실제 화면에도(hidden 속성) 반영된다 — 숨겨진
        // 요소는 위치/크기 계산(fn) 자체를 건너뛴다(하위 규칙이 그
        // 요소를 참조할 때 크기가 0인 값을 얻지 않도록 마지막으로
        // 있었던 위치/크기를 그대로 보존).
        let visible = true;
        if (typeof rule.visible === 'function') {
            visible = rule.visible(container, el);
        } else if (rule.visible === false) {
            visible = false;
        }
        Resize.setVisible(container, el, visible);
        if (!visible) { return; }
        if (rule.top !== undefined) { el.style.top = rule.top + 'px'; }
        if (rule.left !== undefined) { el.style.left = rule.left + 'px'; }
        if (!rule.fn) { return; }
        const fn = Resize[rule.fn];
        if (typeof fn !== 'function') {
            console.warn('Resize.apply: 알 수 없는 함수 — ' + rule.fn);
            return;
        }
        const args = (rule.args || []).map(function (a) {
            if (typeof a === 'string' && byId[a]) { return byId[a]; }
            return a;
        });
        try {
            fn.apply(null, [container, el].concat(args));
        } catch (e) {
            console.warn('Resize.apply: 규칙 적용 실패 — id=' + rule.id + ', fn=' + rule.fn, e);
        }
    });
};
Resize.레이아웃적용 = Resize.apply;

/** 위 클래스 docblock의 "observe(container, layout)" 절 참고. */
Resize.observe = function (container, layout) {
    Resize.apply(container, layout);
    if (typeof ResizeObserver === 'undefined') { return null; }
    const observer = new ResizeObserver(function () {
        Resize.apply(container, layout);
    });
    observer.observe(container);
    return observer;
};
