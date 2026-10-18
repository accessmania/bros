# Resize.js — Access mResize.bas를 옮긴 iframe 배치/리사이즈 유틸리티

MS Access VBA의 `mResize.bas`(Top/Left/Width/Height 절대좌표로 컨트롤을
배치·리사이즈하는 모듈)를 웹으로 옮긴 것. 서버/DB와 무관한 순수 클라이언트
유틸리티 — `Calendar`/`Popup`/`Grid`처럼 인스턴스를 만드는 클래스가 아니라
상태 없는 함수 모음(namespace)이다.

## Access ↔ Resize.js 대응

| Access(mResize.bas) | Resize.js |
|---|---|
| `pform`(`WindowWidth`/`WindowHeight`) | `container`(`position:relative`, `clientWidth`/`clientHeight`) |
| `pctl`(`Top`/`Left`/`Width`/`Height`) | `el`(`position:absolute`, 보통 iframe) |
| `kmaxWidth`/`kmaxHeight`(트윕 상한 클램프) | 없음 — `clientWidth`/`clientHeight`가 이미 실측 px값이라 불필요 |
| `Form_Resize` + Timer 폴링(`ptimeinterval`) | `ResizeObserver`(`Resize.observe()`) |
| `On Error Resume Next` | `Resize.apply()`가 규칙 단위로 `try/catch` + `console.warn` |
| `resize(presizecmd, pform, pnickname, pParameters, pformnames, ...)` | `Resize.apply(container, layout)` — 표를 배열로 선언화 |

## 함수 목록

전부 `(container, el, ...)` 시그니처(container를 안 쓰는 함수도 통일을 위해
받는다). 왼쪽이 정식(영문) 이름, 오른쪽이 VBA와 대조용 한글 별칭 — 같은
함수를 가리킨다.

| 영문(정식) | 한글 별칭 | 인자(container, el 이후) | 설명 |
|---|---|---|---|
| `getMaxWidth` | — | — | container의 실제 가로 폭 |
| `getMaxHeight` | — | — | container의 실제 세로 높이 |
| `fillRect` | `사각형채우기` | `gap=10` | 좌상단에 gap 띄운 채 전체를 채움 |
| `fillWidthToMargin` | `너비_우측여백꽉채우기` | `gap=0, rate=1` | 지금 left에서 우측 여백까지 너비만 |
| `fillHeightDown` | `높이_현재위치에서아래로꽉채움` | `gap=0, rate=1` | 지금 top에서 아래 여백까지 높이만 |
| `fillRectFromCurrentTop` | `사각형채우기_현재Top에서` | `gap=10` | 위 둘의 합성 |
| `centerTop` | `위치_top가운데로` | — | 세로 중앙(가로 중앙 함수는 원본에도 없음) |
| `dockBottom` | `위치_아래쪽끝에위치` | `gap=0` | 하단 도킹 |
| `아래로붙이기`(별칭 전용) | — | `gap=10` | `dockBottom`과 동일, 기본 gap만 10 |
| `dockRight` | `위치_우측끝에위치` | `gap=0` | 우측 도킹 |
| `above` | `위치_위쪽에위치`/`위치_컨트롤위쪽에위치` | `otherEl, gap=0` | otherEl 바로 위 |
| `below` | `위치_컨트롤아래쪽에위치` | `otherEl, gap=0` | otherEl 바로 아래 |
| `rightOf` | `위치_컨트롤오른쪽에위치` | `otherEl, gap=0` | otherEl 바로 오른쪽 |
| `leftOf` | `위치_컨트롤왼쪽에위치` | `otherEl, gap=0` | otherEl 바로 왼쪽 |
| `fillWidthToControl` | `너비_우측컨트롤사이매꾸기` | `otherEl, gap=0, rate=1` | 지금 위치~otherEl 왼쪽 끝까지 너비 |
| `fillHeightToControl` | `높이_아래쪽컨트롤사이매꾸기` | `otherEl, gap=0, rate=1` | 지금 위치~otherEl 위쪽 끝까지 높이 |
| `setWidth` | `너비` | `w` | 너비 리터럴 지정 |
| `setHeight` | `높이` | `h` | 높이 리터럴 지정 |
| `matchSize` | `크기_너비와높이동일하게` | `otherEl` | otherEl과 너비/높이 동일하게 |
| `setVisible` | `보이기` | `visible`(boolean) | `hidden` 속성 + inline `style.display`로 실제 화면에서 보이기/숨기기(VBA `pctl.Visible` 대응) |

**VBA 원본의 중복 통합**: `p위치_위쪽에위치`≡`p위치_컨트롤위쪽에위치`(완전
동일 로직)는 `above()` 하나로, `p아래로붙이기`≡`p위치_아래쪽끝에위치`(로직
동일, 기본 gap만 10/0으로 다름)는 `dockBottom()` + 기본값만 다른 얇은 래퍼로
합쳤다.

## apply(container, layout) / observe(container, layout)

```js
const layout = [
  { id: 'header', top: 10, left: 10, fn: 'fillWidthToMargin', args: [10] },
  { id: 'header', fn: 'setHeight', args: [60] },
  { id: 'sidebar', fn: 'below', args: ['header', 10] },
  { id: 'sidebar', left: 10, fn: 'setWidth', args: [200] },
  { id: 'sidebar', fn: 'fillHeightDown', args: [10] },
  { id: 'main', fn: 'below', args: ['header', 10] },
  { id: 'main', fn: 'rightOf', args: ['sidebar', 10] },
  { id: 'main', fn: 'fillWidthToMargin', args: [10] },
  { id: 'main', fn: 'fillHeightDown', args: [10] },
];

Resize.observe(document.getElementById('app'), layout); // 즉시 1회 적용 + 이후 자동 재적용
// 또는: Resize.apply(document.getElementById('app'), layout); // 1회만
```

- 규칙은 `{id, top?, left?, fn?, args?, visible?}` — `top`/`left`는 리터럴
  px 좌표(VBA로 치면 `pctl.Top = 10` 한 줄), `fn`은 위 표의 함수 이름(영문/
  한글 아무거나), `args`의 문자열이 layout 안 다른 `id`와 같으면 그
  엘리먼트로 자동 치환된다.
- `visible`(선택, 기본 `true`)은 `boolean` 또는 `(container, el) => boolean`
  함수다. `false`(또는 함수 반환값이 `false`)면 `setVisible()`로 **실제로
  화면에서 숨기고**(`hidden` 속성 + inline `style.display:none`, VBA의
  `pctl.Visible`에 대응), 그 규칙의 위치/크기 계산(`fn`)도 건너뛴다 —
  숨겨지기 직전 마지막 위치/크기는 그대로 남아있어 다시 보일 때 그 자리에
  나타난다. `hidden` 속성만으로는 부족한 경우(호출부 CSS가 그 요소에
  ID/클래스 셀렉터로 `display:block` 등을 이미 지정해뒀으면 특이도상 그게
  브라우저 기본 `[hidden]{display:none}`을 덮어써버려 하나도 안 사라짐 —
  1_7의 `#b17-modal`이 겪었던 것과 같은 함정)에도 `setVisible()`이 inline
  `style.display`까지 같이 정하므로(어떤 CSS 셀렉터보다 항상 우선)
  호출부가 이 특이도 문제를 신경 쓸 필요가 없다. 함수로 주면 매
  `apply()`마다(리사이즈될 때마다) 다시 평가되므로 "컨테이너가 좁아지면
  사이드바를 숨긴다" 같은 반응형 규칙을 표현할 수 있다:
  ```js
  { id: 'sidebar', fn: 'setWidth', args: [200],
    visible: function (container) { return container.clientWidth > 600; } }
  ```
- **배열 순서 = 의존관계** — 뒤 규칙은 앞 규칙이 이미 정한 위치/크기를
  `el.offsetTop`/`offsetLeft`/`offsetWidth`/`offsetHeight`로 읽어 참조한다.
- 요소를 못 찾거나 함수 이름이 틀리면 `console.warn`만 남기고 나머지 규칙은
  계속 진행한다.
- `observe()`는 `apply()`를 1회 실행 후 `ResizeObserver`로 container 자신의
  크기 변화를 감지해 자동 재적용한다(반환값은 `ResizeObserver` 인스턴스 —
  `.disconnect()`로 멈출 수 있다). `ResizeObserver` 미지원 브라우저에서는
  1회 적용만 하고 `null`을 반환한다.

## position:relative/absolute는 자동으로 걸린다

`apply()`가 container/각 대상 엘리먼트의 `getComputedStyle().position`이
`static`이면 자동으로 `relative`/`absolute`를 걸어준다. 이미 CSS로
지정해뒀으면 그대로 둔다.

## 알려진 한계

- **부모→자식 iframe 알림 미구현** — 이 파일은 iframe들을 "배치"만 할 뿐,
  크기가 바뀐 iframe 안쪽 문서에 그 사실을 알리진 않는다. 필요해지면
  `iframe.contentWindow.action('resize', {width, height})`(md/
  2026-09-05-deco-부모자식-iframe-통신.md의 `action()`/`childReady` 관용구)를
  `apply()` 호출부에서 직접 얹으면 된다.
- **가로 중앙 정렬 함수 없음** — VBA 원본도 `p위치_top가운데로`(세로만)만
  있고 가로 중앙 함수는 없었다. 가로 중앙이 필요하면 `top`/`left` 리터럴
  규칙으로 직접 계산해 넣는다.
- **숨겨진(`visible:false`) 요소를 다른 규칙이 `args`로 참조하면** —
  `hidden` 속성이 걸린 요소는 `offsetTop`/`offsetLeft`/`offsetWidth`/
  `offsetHeight`가 전부 `0`으로 읽힌다(렌더링 자체가 안 되므로). 숨긴
  요소를 기준점으로 삼는 규칙(`below`/`rightOf` 등)을 쓰면 값이
  틀어질 수 있다 — 숨김/보임에 따라 레이아웃 기준점 자체가 바뀌는
  구성이면 layout 배열을 상태별로 분기해서 만드는 편이 안전하다.
- **`fillWidthToMargin`/`fillHeightDown` 등은 `el`의 현재 `offsetLeft`/
  `offsetTop`에 의존** — 그 규칙 전에 `top`/`left`가 정해져 있어야 정확하다
  (첫 규칙에서 리터럴 `top`/`left`를 함께 주는 패턴을 권장).
- **`observe()`는 최초 1회 `apply()`가 사실상 두 번 실행된다** —
  `observe()` 자신이 먼저 `apply()`를 한 번 직접 부르고, 그 직후
  `ResizeObserver`가 "관찰을 막 시작했다"는 이유만으로(스펙상 비교할
  이전 값이 없으면 크기가 실제로 안 바뀌었어도 최초 1회는 반드시
  콜백을 쏜다) 비동기로 한 번 더 콜백을 쏜다. 계산 결과가 같은 값이라
  멱등하므로 눈에 보이는 문제는 없지만, "왜 로드 직후 두 번 도나"
  싶을 때를 위해 기록해둔다. `observe()`는 `container` 자신만
  관찰한다 — 안의 iframe들은 전부 `position:absolute`라 자기 크기
  변화가 container의 고유 크기에 영향을 안 주므로(정상 흐름 밖이라
  부모 크기 계산에 안 들어감), `apply()`가 자식만 건드리는 이 설계에서
  콜백이 스스로를 다시 트리거하는 무한루프 위험도 없다.

## 관련 파일

- `Resize_entry.php`/`Resize_form1~5.html` — iframe 5개를 배치해보는 연습
  화면(`content5_5.php`가 iframe으로 얹는다).
- `md/2026-09-06-resize-js-설계논의.md` — 설계 논의/구현 기록.
