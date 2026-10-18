# Popup.js

재사용 가능한 **모달/비모달 팝업** 컴포넌트. `AGGrid.js`/`ScrollGrid.js`/
`ChosungKeypad.js`/`Calendar.js`와 마찬가지로 순수 DOM/이벤트만 다루고,
서버 통신은 전혀 모른다.

**"진짜 팝업창"(`window.open()`)이 아니다** — 항상 같은 문서 안의 오버레이
(모달) 또는 그냥 떠 있는 박스(비모달)다. 팝업 내용과 호출부는 항상 같은
JS 컨텍스트를 공유하므로 `window.opener`/`postMessage` 같은 창간 통신이
필요 없다.

## 핵심 동작

- **`action(ptype, payload)`** — 팝업 `html` 안의 스크립트는 무슨 일이
  있어도 항상 `Popup.instance('그 id').action(ptype, payload)` 하나만
  부르면 된다. `ptype`으로 무슨 일이 있었는지 구분하고, `payload`는
  이름 있는 값들을 담은 평범한 객체다(`action(ptype, p1, p2, ...)`처럼
  위치로 뜻이 정해지는 매개변수는 쓰지 않는다). 호출부는 생성자의
  `onAction(ptype, payload, id)` 콜백으로 그 보고를 받는다 — **세 번째
  인자 `id`는 이 팝업의 식별자**(생성자에 준 `options.id`, 생략 시
  자동 생성값)로, 같은 `onAction` 함수를 여러 팝업이 공유해도 이
  인자만 보고 "어느 팝업에서 왔는지" 구분할 수 있다(`payload`에 직접
  식별자를 담을 필요 없음). `close()`가 호출하는 `onClose(id)`도
  동일한 원칙.
- **레이블/드래그/위치는 독립된 옵션** — `title`이 없으면 레이블이 없다.
  `draggable`이 true면 헤더(손잡이)로 옮길 수 있다. 헤더는 `title`이
  있거나 `draggable`이 true면 렌더링되고(레이블 없이 손잡이만 있는
  헤더도 가능), 닫기 버튼은 헤더 안에 항상 있다. **`title`도 없고
  `draggable`도 false면 헤더 자체가 없다** — 그 경우 닫기는 `modal`이면
  오버레이 클릭으로, 아니면 팝업 내용 쪽에서
  `Popup.instance(id).close()`를 직접 불러야 한다.
- **`position`** — `'center'`(기본, 화면 가운데) 또는 `{top, left}`
  (고정 좌표, 숫자면 px). 특정 엘리먼트에 앵커링하는 기능은 없다.
- **모달 vs 비모달** — `modal:true`(기본)면 화면 전체를 덮는 반투명
  오버레이가 함께 뜨고 배경 클릭이 막힌다(오버레이 클릭 시 닫을지는
  `closeOnOverlayClick`). `modal:false`면 오버레이가 아예 없어 배경
  페이지를 계속 조작할 수 있다.
- **캡션은 열려 있는 도중에도 바꿀 수 있다** — `title`은 열 때(생성자)
  주입하는 값이고, `setCaption(text)`로 이미 열려 있는 팝업의 캡션을
  나중에 바꿀 수 있다(헤더 자체가 없는 팝업은 표시할 자리가 없어
  조용히 무시).
- **다른 경로의 파일 보여주기 — `iframeUrl`** — `html` 대신(또는 그것
  없이) `iframeUrl`을 주면 그 경로의 파일을 `<iframe>`으로 그대로
  띄운다(3_1의 `calendar.html`을 iframe으로 얹었던 것과 같은 원리) —
  완전히 독립된 문서(다른 폴더의 정적 파일, 다른 PHP 화면 등)를 그대로
  보여줄 때 쓴다. 크기는 `iframeWidth`/`iframeHeight`(기본 480×360,
  숫자면 px)로 지정. `html`/`iframeUrl` 둘 다 없으면 생성자가 예외를
  던진다.
- **뜰 때 애니메이션 — `animation: 'none'|'small'|'large'`**(기본
  **`'none'`**) — 셋 다 방향은 오른쪽→왼쪽(제자리)으로 통일, 크기만
  다르다: `'small'`(우좌일부이동)은 살짝 이동(`translateX(32px) → 0`)
  + 페이드인, `'large'`(우좌전체이동)는 화면 오른쪽 바깥에서 전체
  이동(`translateX(100vw) → 0`) + 페이드인 — 전체화면 팝업에 쓰면 페이지
  전환처럼 보인다. `'none'`은 오버레이도 페이드인 없이 즉시 나타난다.
  순수 CSS `@keyframes`라 DOM에 붙는 순간 자동 재생되고, 위치 계산에
  쓰는 `box.style.transform`과 안 겹치도록 내부 래퍼(`.wc-popup-anim`)에만
  건다. 닫을 때는 세 종류 모두 애니메이션 없이 즉시 사라진다.

## 사용법

```js
new Popup({
    id: 'my-popup',                 // 선택, 생략 시 자동 생성 — Popup.instances 키
    title: '메모 입력',              // 선택, 없으면 레이블 없음
    modal: true,                    // 기본 true — false면 배경 오버레이 없음
    draggable: true,                // 기본 true — 헤더로 드래그 이동
    position: 'center',             // 'center'(기본) | { top, left }
    animation: 'small',             // 'none'(기본) | 'small' | 'large'
    closeOnOverlayClick: true,      // 기본 true(modal일 때만 의미)
    html: '<label>메모 <input id="mp-input"></label>' +
          '<button onclick="' +
              "Popup.instance('my-popup').action('save', " +
              "{ 메모: document.getElementById('mp-input').value }); " +
              "Popup.instance('my-popup').close();" +
          '">저장</button>',
    onAction: (ptype, payload, id) => {
        if (ptype === 'save') { /* payload.메모 사용, id로 어느 팝업인지 구분 */ }
    },
    onClose: (id) => { /* 팝업이 닫힌 뒤(어떤 방식으로 닫혔든) 호출됨 */ },
});
```

## 공개 API

- `action(ptype, payload)` — 팝업 내용이 이 인스턴스 메서드로 결과를
  보고한다(생성자의 `onAction(ptype, payload, id)` 콜백으로 위임 —
  `id`는 이 팝업의 식별자)
- `setCaption(text)` — 이미 열려 있는 팝업의 캡션(제목)을 바꾼다(헤더
  없는 팝업은 무시)
- `close()` — DOM 제거 + `onClose(id)` 호출 + `Popup.instances`에서 제거
- `Popup.instance(id)` — 정적 메서드, 등록된 인스턴스를 id로 조회
  (`AGGrid.instance(gridId)`/`Calendar.instance(id)`와 동일 패턴)

`open()`은 따로 없다 — `new Popup(...)` 생성자가 즉시 DOM을 만들어
`document.body`에 붙이고 연다.

## 콘텐츠 소스

`html`(완성된 HTML 문자열) 또는 `iframeUrl`(다른 경로의 파일을 그대로
iframe으로 띄움) 중 하나를 쓴다. `board-modal.js`처럼 서버에서
fragment를 fetch해와 DOM에 삽입하는 방식(같은 출처의 조각 HTML만
가능, 그 문서의 CSS/JS가 이 페이지와 한 컨텍스트에 섞임)은 아직 없음 —
필요해지면 `fetchUrl` 옵션으로 추가 가능(내부적으로 `_buildDom()`의
본문 채우는 지점에 조건 분기 하나만 더 늘리면 되는 구조).

## 4_4(팝업연습) 실사용 예

`public/test/Layout/test1/content4_4.php` — 4가지 조합(모달+레이블+
드래그, 모달+레이블없음+드래그불가, 비모달+레이블+드래그, 비모달+
명시적 좌표)을 각각 버튼으로 열어보는 데모. 각 팝업의 `html` 안에는
"저장" 버튼이 있고, 클릭하면 `Popup.instance('그 id').action('save',
{...})` 후 `.close()`를 호출한다. `onAction` 콜백은 화면 하단 읽기전용
문구에 `ptype`/`payload`를 그대로 반영한다.

## 알려진 버그와 수정 이력 — 넓은 auto-width 팝업이 뷰포트 절반으로 잘리던 문제

`position:'center'`(기본값) 팝업이 내용에 비해 좁게 뜨고, 안의 콘텐츠
(예: ag-grid)에 불필요한 가로 스크롤바까지 생기는 버그가 있었다
(2026-09-09, claude-in-chrome으로 실제 NAS를 열어 `iframe.
contentDocument`를 타고 들어가며 각 레이어의 `offsetWidth`/
`getComputedStyle()`를 직접 측정해 원인을 확인함).

**원인**: `.wc-popup-box`가 `position:fixed; left:50%; top:50%;
transform:translate(-50%,-50%);`로 중앙정렬했는데, `width`가
`auto`(내용에 맞춘 자동폭)인 상태에서 `right`는 안 주고 `left`만 주면
CSS 스펙상 "사용 가능한 폭"을 `containing block 폭 - left`로 계산한다
— `transform`은 페인트 단계 연산이라 이 레이아웃 계산에 전혀 반영되지
않는다. `left:50%`라서 사용 가능한 폭이 **항상 뷰포트의 정확히 절반**
으로 잘렸다(실측: 뷰포트 1644px에서 박스가 정확히 822px=1644/2로
클램프, 오차 없이 일치). `max-width`를 아무리 풀어줘도 이 클램프와는
무관해 안 통했다. 드래그하면 바로잡히는 것도 이걸로 설명됨 — 드래그
시작 시(`_initDrag()`) `transform:none`+절대 픽셀 `left`로 바뀌면서 이
계산 자체가 사라지기 때문.

**수정**: `position:fixed+left:50%+transform`으로 박스 스스로 중앙에
자리잡는 방식을 버리고, 박스를 감싼 부모(모달이면 `.wc-popup-overlay`,
비모달이면 새로 만드는 `.wc-popup-position-root`)를
`display:flex; align-items:center; justify-content:center;`로 만들어
그 부모가 중앙정렬을 대신하게 했다 — flex item의 폭 계산에는 이런
클램프가 없다. `_applyPosition()`은 이제 `'center'`일 때 박스에 아무
위치 스타일도 안 준다(부모의 flex 배치에 전부 맡김). `{top,left}` 같은
명시적 좌표 지정은 이 버그와 무관해(둘 다 절대값, transform 불필요)
`position:fixed`+픽셀 좌표 그대로 유지. 드래그(`_initDrag()`)는 시작
시점에 `box.style.position = 'fixed';`를 명시적으로 걸어 자유 이동으로
전환한다(예전엔 `_applyPosition()`이 항상 `position:fixed`를 걸어줬어서
이 줄이 필요 없었음).

## 여기서 다루지 않은 것

- 진짜 팝업창(`window.open()`), `postMessage` 기반 창간 통신.
- 여러 팝업을 스택으로 관리(순서대로 닫기, ESC로 최상단만 닫기 등) —
  z-index만 인스턴스마다 증가해 나중에 연 팝업이 위로 오게는 해뒀지만,
  그 이상의 스택 관리는 없다([[deco]]의 "모달 위에 모달" 논의가 필요해지면
  별도 지시로 확장 가능).
- 특정 엘리먼트에 앵커링하는 위치 지정(버튼 옆에 붙는 팝오버 등).
