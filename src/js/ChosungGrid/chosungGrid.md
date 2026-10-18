# ChosungGrid.js

바탕화면 `초성검색.png` 화면 전체(좌측 목록 + 우측 초성검색 키패드)를
하나로 묶은 "선택 팝업" 조립 위젯. `ChosungKeypad.js`(키패드 자체)와
`4_1`(초성검색, `content4_1.php`)이 이미 각각 검증해둔 조각들을 재사용해,
여러 화면(웨이터 선택/영업 선택/지명 선택/상품 선택 등)이 매번 같은
글루 코드를 반복하지 않고 재사용할 수 있게 한 것이 이 모듈의 목적이다.

## 4_1과의 차이

| | 4_1(`content4_1.php`) | ChosungGrid.js |
|---|---|---|
| 좌우 배치 | 키패드-좌 / 그리드-우 | **그리드-좌 / 키패드-우**(이미지와 동일) |
| 그리드 | `AGGrid.js`(CRUD용, 정렬/필터/편집) | raw ag-grid를 가볍게(표시 컬럼 1개, 헤더 없음, `deco_amd_amd.php`와 동일한 접근) |
| 대상 테이블 | t직원 하나로 고정(하드코딩) | `source`(이름) 하나로 서버가 매핑 — 재사용 가능 |
| 결과 처리 | 이 화면 안에서 끝(선택 개념 없음) | 1클릭 = 선택, `sender.action(callback이름, ...)`로 호출부에 보고 |

## 사용법 — 같은 문서 안에(iframe 없이) 직접

```js
new ChosungGrid({
    containerId: 'cg-root',       // 필수 — 좌(그리드)/우(키패드) 둘 다 이 안에 그려짐
    fetchUrl: 'ChosungGrid_fetch.php?source=직원', // 필수 — 응답은 [{key,display}, ...]
    sender: window.parent,        // 없으면 null — 그때는 onSelect만 호출됨
    type: '웨이터선택',            // 콜백 안에서 다시 switch할 꼬리표
    callback: 'callback_초성검색_웨이터선택완료', // (선택) 생략 시 'callback_초성검색'
    onSelect: function (key, display) { ... }, // 선택 시(선택) 로컬 콜백
});
```

## 사용법 — Popup(iframe)으로 열기 (실사용 형태)

```js
// Popup.js가 먼저 로드돼 있어야 함
// [2026-09-09 수정] "fetch url을 openPicker 할 때 노출되게, 가독성있게"
// 지시로 source 대신 fetchUrl을 직접 조립해 넘긴다 — 호출부 코드만
// 봐도 정확히 어느 URL을 여는지 바로 보인다.
var fetchUrl = CHOSUNG_FETCH_URL + '?source=직원'; // ChosungGrid_fetch.php의 cg_source_map() 참고
var picker = ChosungGrid.open({
    fetchUrl: fetchUrl,    // (1) 실제로 조회할 URL(서버가 이 안의 source를 매핑)
    type: '웨이터선택',     // (3) 콜백 안에서 switch할 꼬리표
    callback: 'callback_초성검색_웨이터선택완료', // (선택, 2026-09-11 추가) 생략 시 'callback_초성검색'
    sender: window,        // (2) 문서화 목적 — 실제로는 항상 "이 Popup을 만든 window"가 sender가 됨
    ptitle: '웨이터 선택',  // (4) 팝업 제목
});

function action(pcmd, pparams, psender) {
    switch (pcmd) {
        case 'callback_초성검색_웨이터선택완료':
            if (!pparams.cancelled) { setValue('웨이터', pparams.display); }
            Popup.instance(picker.id).close(); // 팝업을 닫는 것은 항상 호출부 책임.
            break;
    }
}
```

## 공개 API

- `new ChosungGrid(options)` — `containerId`/`fetchUrl` 필수, `sender`/
  `type`/`callback`/`onSelect`/`onQuit` 선택. `onQuit`은 `sender` 유무와
  무관하게 항상 불린다(2026-09-09 추가 — 듀얼 화면처럼 `sender` 없이
  쓰는 인스턴스도 Quit에 반응할 수 있게). **`callback`(선택,
  2026-09-11 추가)** — 선택/취소 시 `sender.action(...)`에 쓸 pcmd
  이름을 호출부가 직접 지정한다(생략하면 `'callback_초성검색'`) — 이
  위젯은 여러 화면이 재사용하므로 "선택이 끝나면 무엇을 해야 하는지"
  까지 담긴 구체적인 이름은 위젯 자신이 미리 정해둘 수 없고, 그 이름이
  실제로 쓰일 화면(여는 쪽)이 지어서 넘긴다(`openDual()`의 `callback`
  옵션과 동일한 원칙 — md/2026-09-11-action-callback-이름부여-규칙.md
  참고).
  - `getChosungValue()` — 지금 필터로 걸려 있는 누적 초성 문자열.
  - `requery()` — 지금 필터 그대로 다시 조회.
  - `destroy()` — DOM/이벤트 정리(키패드까지 함께).
- `ChosungGrid.open(options)`(static) — `Popup.js`가 먼저 로드돼 있어야
  함. `fetchUrl`(권장 — 실제로 조회할 URL을 호출부가 직접 조립해 넘김,
  가독성을 위해 `source` 대신 이 이름으로 노출) 또는 `source`(생략용
  기본값, `ChosungGrid_fetch.php?source=...`를 대신 만들어줌) 중 하나와
  `type`/`callback`/`ptitle`/`id`/`entryUrl`/`iframeWidth`/`iframeHeight`/
  `modal`/`draggable`/`onClose`를 받아 `new Popup({...})`을 만들어 그
  인스턴스를 반환한다(팝업을 닫는 시점은 호출부가 정함). `callback`은
  `ChosungGrid_entry.php`가 `?callback=`으로 그대로 전달받아
  `new ChosungGrid({callback: ...})`에 넘긴다.
- `ChosungGrid.openDual(options)`(static, 2026-09-09 추가) — 바탕화면
  `매출/초성검색2.png`(초성검색 2개가 나란히 붙은 화면)를 위한 헬퍼.
  `left`/`right`(각각 `{fetchUrl, label?}`) 필수 + `type`/`callback`/
  `ptitle`/`id`/`entryUrl`/`iframeWidth`/`iframeHeight`/`modal`/
  `draggable`/`onClose`를 받아 `ChosungGridDual_entry.php`(같은 폴더)를
  Popup+iframe으로 연다. **`callback`(선택, 2026-09-11 추가)** — 선택
  완료 시 `sender.action(...)`에 쓸 pcmd 이름을 여는 쪽이 직접 지정한다
  (생략하면 `'callback_초성검색2'`). 이 위젯은 여러 화면이 재사용하므로
  "선택이 끝나면 무엇을 해야 하는지"까지 담긴 구체적인 이름(예:
  `callback_초성검색2_룸영업선택완료`)은 위젯 자신이 미리 정해둘 수
  없고, 그 이름이 실제로 쓰일 화면(여는 쪽)이 지어서 넘긴다 —
  `ChosungGrid`/`ChosungGridDual_entry.php`는 그 이름의 뜻을 몰라도
  그대로 돌려주기만 한다(md/2026-09-11-action-callback-이름부여-규칙.md
  참고).
  `left.initial`(선택 사항, `{key, display}`, 2026-09-09 추가)을 주면
  팝업이 열릴 때부터 왼쪽이 그 값으로 "최초 선택된 상태"에서 시작한다
  (왼쪽 그리드를 안 눌러도 오른쪽만 골라 그 초기값 그대로 확정 가능) —
  `key`는 `null`이어도 된다(2026-09-09 수정, "선택된 상태"인지는
  `display` 유무만으로 판단), `initial` 자체를 생략하거나 `key`/`display`
  둘 다 없으면 "초기값 없음"과 같다. `right`는 이 개념 자체가 없어
  항상 빈 값으로 시작한다. **왼쪽이 아직 한 번도 선택 안 된 상태에서
  오른쪽을 고르면(2026-09-09 추가) 확정되지 않는다** — `alert()`로
  왼쪽 label을 먼저 고르라고 안내만 하고 콜백 자체를 안 보내 팝업이
  안 닫힌다. 왼쪽을 고르면 "선택 된 {label}: 값" 문구만 갱신되고(팝업
  유지, 2026-09-09부터 이 문구는 큰 글씨+노란 배경의 눈에 띄는 배지로
  강조됨 — `ChosungGridDual_entry.php`의 `#cgd-left-label`), 그 다음
  오른쪽을 고르는 순간 최종 확정 —
  `sender.action(callback이름, { left: {key,display}, right:
  {key,display}, type }, window)`로 보고된다(취소=Quit이면 `left`/
  `right` 모두 `null` + `cancelled:true`). **`ChosungGrid` 클래스 자체는
  이 기능을 위해 전혀 안 바뀌었다** — `ChosungGridDual_entry.php`가
  `ChosungGrid`를 `sender` 없이 두 번 재사용하고, "오른쪽 선택 시 왼쪽
  값과 합쳐 부모에게 보고"하는 조율만 그 조립 화면 책임으로 둔 것.
  4_5("초성그리드") 데모의 "룸+영업 선택" 버튼이 실사용 예.

## 서버 계약 — `ChosungGrid_fetch.php`

- `?source=<이름>` — `cg_source_map()`(파일 안의 작은 배열)에 등록된
  이름만 허용, 없으면 400. 실제 테이블/커넥션/key컬럼/display컬럼은
  전부 이 배열이 알고 있고, 브라우저는 절대 원본 SQL/테이블명을 보내지
  않는다(`deco_main_fetch.php`의 `getRs($name)`과 같은 신뢰 경계). 지금
  등록된 것: `'직원'`(t직원/별칭, 가나다순), `'룸'`(t룸/룸번호, `order`를
  `'순서 ASC'`로 지정 — display가 "1T(5)"/"10T(3)"처럼 문자열 정렬로는
  뜻이 안 맞는 경우의 예). `order`를 생략하면 `display ASC`가 기본값.
- `?chosung=<누적문자열>` — `Board/4_1/fetch_직원.php`와 동일한 방식으로
  `SqlDialect::chosungCondition()`을 한 글자씩 AND로 엮어 `display`
  컬럼을 필터링.
- 응답은 항상 `[{ "key": ..., "display": "..." }, ...]` — `Board::init()`
  을 거쳐 조회하므로 `Board::loadBoard()`의 `sanitizeUtf8()`이 자동으로
  적용된다(2026-09-09 "1T(5) 선택시 웨이터가 표시안됨" 버그와 같은
  CP949 인코딩 문제를 애초에 피함 — 원인 경위는
  `md/2026-09-08-deco-amd-전표화면-신설.md` 참고).
- 새 `source`가 필요하면 `cg_source_map()`에 한 줄만 추가하면 된다.

## 캐시버스팅

`ChosungGrid.open()`/`ChosungGrid.openDual()`이 여는 iframe URL
(`ChosungGrid_entry.php`/`ChosungGridDual_entry.php`)에는 2026-09-09부터
`&_=<Date.now()>`가 항상 붙는다 — 이 두 파일 자신은 그동안 캐시버스팅이
없어서(호출부의 `<script src>`만 `?v=filemtime`로 버스팅됨), `deco_main.php`
처럼 부모 문서가 새로고침 없이 오래 떠 있는 화면에서 같은 팝업을 반복해서
열면 브라우저가 예전 버전을 캐시해 최신 CSS/마크업이 안 보일 수 있었다
(예: `ChosungGridDual_entry.php`의 `.cgd-body { gap: 1cm; }`가 이미 있는데도
안 보이는 것처럼 느껴진 사례). 이제 열 때마다 새 요청이 되므로 이런 종류의
스테일 캐시 문제는 재발하지 않는다.

## 그리드가 좁게 뜨다가 상호작용(드래그 등) 후에야 바르게 나오는 문제

`ChosungGrid`는 항상 `Popup`(iframe) 안에서 만들어지는데, 그 iframe/부모
박스가 최종 폭으로 자리잡기 **전** 시점에 `agGrid.createGrid()`가 먼저
실행되면 `flex:1` 컬럼이 그 순간의(좁은/틀린) 컨테이너 폭 기준으로
계산돼버릴 수 있다(2026-09-09 실제로 발견됨 — "팝업이 뜨고 좁은 너비로
뜨는데 드래그드롭으로 움직이면 너비가 바르게 나옴"). 드래그가 폭을
"고쳐준" 게 아니라, 드래그 중 강제 리플로우(`getBoundingClientRect()`)와
재배치가 브라우저에게 레이아웃을 다시 계산시키면서 우연히 올바른 폭이
드러난 것뿐이었다.

**1차 시도(실패)** — 그리드 생성 직후 `requestAnimationFrame` 2회 뒤에
`window`에 `resize` 이벤트를 인위적으로 쏴서 재계산을 유도해봤으나 효과
없었음 — 이 ag-grid 버전(`theme:'legacy'`)은 `window` resize가 아니라
**그리드 DOM 엘리먼트 자신의 크기 변화**를 기준으로 다시 재는 것으로
판단됨.

**2차 시도(효과 없음)** — `_initGrid()`가 그리드가 그려진 div 엘리먼트를
`ResizeObserver`로 직접 관찰하다가(관찰 시작 시 최초 1회는 항상 콜백이
온다) `gridApi.sizeColumnsToFit()`을 불러봤으나 여전히 안 고쳐짐 —
"컨테이너 크기를 못 재는" 문제가 아니었다는 뜻. (`destroy()`에
`ResizeObserver.disconnect()`는 남겨둠, 해롭지 않고 향후 실제로 컨테이너
크기가 바뀌는 경우엔 여전히 유용.)

**3차 시도(부분 개선, 근본 원인 아니었음)** — `_ensureLibraryLoaded()`를
다시 보니 지금까지 `<script>`(ag-grid 본체) 로드만 기다리고, `<link
rel="stylesheet">`(`ag-grid.css`/`ag-theme-alpine.css`) 두 개는 로드
완료를 전혀 안 기다리고 있었다 — CSS `<link>`도 각각 `onload`를 추적해
JS+CSS가 전부 끝난 뒤에야 그리드를 만들도록 고쳤다(안전상 유지할 가치는
있으나, 아래 4차에서 밝혀진 진짜 원인은 아니었다).

**4차 시도(부분 개선, 근본 원인 아니었음)** — 사용자가 "스크롤바까지
생긴다"는 단서를 줌 — 이건 타이밍 문제가 아니라 **레이아웃 폭
부족(overflow)**이라는 뜻으로 해석. `.cg-grid-col { flex: 0 0 60%; }`
와 `.cg-keypad-col { flex: 0 0 auto; }` 둘 다 flex-shrink:0이라 절대 안
줄어드는 것을 발견해 `.cg-grid-col`을 `flex: 1 1 60%`(shrink 허용)로
바꾸고 `ChosungGridDual_entry.php`의 `.cgd-col-root`에도 `min-width: 0`
을 추가함(폭이 부족하면 키패드 대신 그리드가 먼저 양보하게, 안전상
유지할 가치는 있으나 아래 5차에서 밝혀진 진짜 원인은 아니었음).

**5차 시도(진짜 원인, claude-in-chrome으로 실제 브라우저에서 확인 후
적용)** — "AI가 직접 해보고 원인을 찾아 해결"이라는 지시로 처음으로
NAS를 실제 브라우저(claude-in-chrome)로 열어 그리드 클릭→팝업까지
재현하고, `iframe.contentDocument`를 타고 들어가 각 레이어의
`offsetWidth`/`getComputedStyle()`를 직접 측정함. 발견한 진짜 원인은
`Popup.js`(이 클래스가 아니라 팝업 자체를 만드는 공용 모듈)의 중앙정렬
방식 — `.wc-popup-box`가 `position:fixed; left:50%; top:50%;
transform:translate(-50%,-50%);`로 중앙정렬하는데, `width`가
`auto`(내용에 맞춘 자동폭)인 상태에서 `right`는 안 주고 `left`만 주면
CSS 스펙상 "사용 가능한 폭"을 `containing block 폭 - left`로 계산한다
(`transform`은 페인트 단계 연산이라 이 레이아웃 계산에 전혀 반영 안 됨)
— `left:50%`라서 사용 가능한 폭이 **항상 뷰포트의 정확히 절반**으로
잘렸다(실측: 뷰포트 1644px에서 박스가 정확히 822px=1644/2로 클램프,
오차 없이 일치). `iframeWidth`를 아무리 정밀하게 계산해도 팝업 내용이
뷰포트 절반보다 넓으면 무조건 잘렸고, 드래그하면 바로잡히는 이유도
이걸로 설명됨(드래그 시작 시 `transform:none`+절대 픽셀 `left`로
바뀌면서 이 계산 자체가 사라짐). `Popup.js`의 중앙정렬을
`position:fixed+left:50%+transform` 대신 **부모(overlay 또는 새
`.wc-popup-position-root`)를 flex(`align-items/justify-content:
center`)로 만드는 방식**으로 바꿔 이 클램프를 원천적으로 없앰(상세는
`Popup.js`의 `_applyPosition()` 독스블록 참고).

이 수정 이후에도 각 그리드 밑에 가로 스크롤바(`.ag-body-horizontal-
scroll`)가 남아있는 게 실측으로 추가 확인됨 — 컬럼 폭이 컨테이너와
정확히 같아(0px 오차) 실제로는 안 넘치는데도 ag-grid가 "스크롤 필요"로
내부적으로 판단해둔 상태가 (컨테이너가 좁았던 시점에 한 번 정해진 채)
`sizeColumnsToFit()`/resize 재시도로도 안 풀리는 것으로 확인됨 — 이
위젯은 컬럼이 항상 정확히 1개(flex:1, 컨테이너 폭을 그대로 채움)라
애초에 가로 스크롤이 필요할 일이 없으므로, `.cg-grid
.ag-body-horizontal-scroll { display: none !important; }`로 이 스크롤바
자체를 항상 숨긴다(라이브로 주입해 실제로 스크롤바가 사라지고 빈 공간이
안 남는 것을 확인 후 소스에 반영).

## 알려진 한계

- `ChosungGrid.open()`의 `sender` 옵션은 실제로 쓰이지 않는다(문서화
  목적) — iframe으로 진짜 페이지 전환이 일어나는 구조라, 살아있는 JS
  참조를 그대로 넘길 방법이 없다. 대신 `ChosungGrid_entry.php`가 자기
  `window.parent`를 스스로 판별해 sender로 삼는다(있고, 자기 자신이
  아니고, `action()`이 있을 때만).
- 초성검색 대상(`display`)이 `NULL`인 행은 애초에 후보에서 제외된다
  (4_1과 동일한 이유 — `WHERE display IS NOT NULL`).
- 결과 건수는 `ChosungGrid_fetch.php`의 `LIST_LIMIT`(500)으로 상한을
  둔다 — 페이징 UI가 없는 단순 목록이라 전체를 한 번에 보여주는 방식.
