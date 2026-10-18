# decoItem.js

바탕화면 `매출/상품.png`(Access "상품선택" 팝업)를 재현할 목적의 화면
전용 위젯. **여러 프로젝트에 재사용 가능한 범용 모듈은 아니다**(지시
7) — `ChosungGrid`(source 이름 → 테이블 간접 매핑)와 달리 `t상품`/
`t상품분류` 두 테이블을 이 파일과 `decoItem_fetch.php`가 직접 안다.

> **개명 이력(2026-09-12)** — 최초엔 폴더/파일/클래스명 전부 한글
> (`deco상품`/`Deco상품`)이었으나, "한글파일명 대신 영문파일명으로"
> 지시로 `decoItem`/`DecoItem`으로 전면 개명했다(아래 "자기 폴더 경로
> 계산" 절 참고 — 한글 파일명이 실제로 버그의 원인이었다).

## 핵심 동작

- 좌측: `t상품분류` 전체를 보여주는 ag-grid 한 개 — 검색바/정렬/필터/
  편집 전부 없는 "선택 전용" 그리드(지시 2). 행을 클릭하면(ag-grid의
  `onRowClicked`) 그 분류에 속한 상품만 우측 그리드에 표시된다(지시
  3/6). 선택된 행은 ag-grid 기본 강조색 대신 이 프로젝트 배지 배색
  (검은 배경+`#ffeb3b` 노란 글자)으로 표시된다.
- 우측: 검색바(지시 4) + ag-grid 그리드 하나(이미지처럼 두 열로
  나누지 않는다). **2026-09-12 추가 — "검색은 바,초성 모두가되게"**:
  ag-grid 기본 `quickFilterText`(리터럴 부분일치만 지원) 대신
  `DecoItem._chosungMatch()`로 자체 필터링해, 문자 그대로("바")와
  초성("ㅂ")을 섞어 써도("바ㅋ"처럼) 매칭된다 — 서버 재조회 없이 지금
  로드된 상품(`this._allProducts`) 안에서만 필터하고, 분류를 바꿔도
  검색어(`this._searchText`)는 유지된다(아래 "우측 검색" 절 참고).
- 컬럼: 순서(회색, 우측 정렬)/상품명(진한 글자)/현금가(빨강)/카드가
  (파랑) — 지시 5. `decoItem_fetch.php`가 이미 `순서` 내림차순으로
  정렬해 응답하므로 그리드는 받은 순서 그대로 보여주기만 한다(지시 3
  — "순서필드의 값이 클수록 위에 나오게").
- 색상은 이 프로젝트의 deco 화면 전체가 이미 쓰는 배색을 그대로
  따랐다 — 현금 `#d32f2f`(빨강)/카드 `#1565c0`(파랑)는
  `deco_amd_amd.php`의 현금매출/카드매출과 동일, 분류 선택 강조(검은
  배경+`#ffeb3b` 노란 글자)는 이 프로젝트 배지 배색과 통일.

## 자기 폴더 경로 계산 — 한글 파일명이었을 때 겪은 버그 (2026-09-12)

`_SCRIPT_DIR`(자기 자신이 로드된 폴더, `_defaultFetchUrl()`/
`_defaultEntryUrl()`/vendor 경로 계산의 기준)을 최초엔 `ChosungGrid.js`
처럼 `src.replace(/deco상품\.js(\?.*)?$/, '')`(파일명을 문자로 매칭)로
계산했으나, 브라우저는 `document.currentScript.src`의 한글을 항상
퍼센트 인코딩(UTF-8 바이트, 예: `deco%EC%83%81%ED%92%88.js`)해서
돌려주므로 이 리터럴 한글 정규식은 **절대 매칭되지 않았다** — 그
결과 ag-grid vendor 경로가 깨져 그리드 로드가 실패하고, 팝업에
그리드 대신 에러 문구만 텍스트로 보이는 버그로 이어졌다.

지금은 **①영문 파일명(`decoItem.js`)으로 개명**해 애초에 이 인코딩
문제 자체가 없고, **②`_SCRIPT_DIR` 계산 방식도 "마지막 `/`(쿼리스트링
이전)까지만 잘라 폴더를 구하는" 인코딩 무관 방식**으로 유지한다(혹시
모를 재발 방지 — 파일명을 문자로 매칭하지 않으므로 인코딩과 무관하게
항상 정확).

## 좌측 분류도 결국 ag-grid — `decoItem.php`는 순수 마크업 뼈대로 회귀 (2026-09-12 재구조)

두 차례("글자만 뜨는데", "좌측 분류가 안 나옴") 수정에 이어, 한 번은
좌측을 `decoItem.php`가 서버에서 `t상품분류`를 직접 조회해 `<div>`
버튼 목록으로 굽는 방식으로도 바꿔봤다. 그런데도 "지금 안되고있음"이
계속됐고, 이번 지시("화면 좌측에도 aggrid(검색/편집 없는)를 배치")로
좌측도 결국 ag-grid API로 데이터를 얹어야 하게 됐다 — 그러면
`decoItem.php`가 DB를 미리 조회해둘 이유가 없어져, **이 파일은 다시
두 그리드의 빈 컨테이너 div만 그리는 순수 정적 마크업**으로
단순해졌다(DB 접근 없음, `config/Path.php` 부트스트랩도 불필요).
좌/우 데이터는 둘 다 `decoItem_fetch.php`의 JSON을 `decoItem.js`가
직접 불러 각자의 ag-grid에 채운다.

"좌측 분류가 안 나옴"이 반복된 진짜 원인은 **JSON 왕복 자체의 실패
은폐**였다 — `decoItem_fetch.php`가 SQL 실패 시
`http_response_code(500)` + `{"error": ...}`를 반환해도
`fetch().then(res => res.json())`은 HTTP status를 보지 않고 그대로
파싱에 성공하므로, 실패가 "성공"으로 오인돼 빈 배열로 조용히
끝나버렸다. 지금은 좌/우 두 조회(`_loadCategories()`/
`_fetchProducts()`) 모두 `res.ok`와 응답 바디의 `error` 필드를 함께
확인한 뒤에만 성공으로 처리하고, 실패하면 해당 그리드 자리에
`.dp-error-text`로 실패 사유를 그대로 표시한다 — 원인 불명으로
조용히 비어 있는 경우가 구조적으로 없어졌다. 이번 지시에서 사용자가
`t상품분류`의 실제 컬럼명(`code`/`분류`, 이전 추정 `분류명`은
틀렸었다)을 직접 확인해줘 SQL도 정정했다.

좌측 그리드 선택("콜백") 처리: 클릭 시 ag-grid의 `onRowClicked`
콜백에서 `_selectCategory(code)`를 호출해 우측 그리드를 재조회한다
(지시 4). 다만 이 위젯의 외부 보고용 `sender.action(callback, ...)`은
여전히 **우측(상품) 그리드 클릭에서만** 호출한다 — 좌측 분류 선택
시점에 외부로 보고하면 여는 쪽이 그 시점에 팝업을 닫아버릴 수 있어
상품을 고르기도 전에 창이 닫히는 모순이 생기기 때문("좌측 aggrid에서
선택하면 callback"을 "ag-grid의 onRowClicked 콜백"으로 해석한 것 —
좌측 선택 시점에도 외부 보고가 필요하면 별도 콜백 이름을 추가하면
된다).

## 우측 검색 — 문자("바")+초성("ㅂ") 혼합 매칭 (2026-09-12 추가)

지시("화면 오른쪽에 초성검색을 추가... 검색은 바,초성 모두가되게")로
검색바를 ag-grid 기본 `quickFilterText`(리터럴 부분일치만) 대신 자체
매칭 함수로 바꿨다:

- `DecoItem._chosungMatch(text, query)` — `text`(상품명) 안에서
  `query`가 연속으로 나타나는 자리를 찾되, `query`의 각 글자를
  `DecoItem._charMatches()`로 비교한다.
- `_charMatches(textChar, queryChar)` — `queryChar`가 초성 자음
  (`ㄱ`~`ㅎ`, 겹자음 포함 19종)이면 `_chosungOf(textChar)`(완성형
  한글 음절의 초성, `SqlDialectMssql::chosungCondition()`과 같은
  유니코드 코드포인트 산술)와 비교하고, 그 외(보통 한글 음절 "바",
  영문/숫자 등)면 `textChar`와 대소문자 무시 비교한다.
- 그래서 검색어 하나에 문자와 초성을 섞어 써도(`"바ㅋ"`처럼) 그대로
  동작한다 — 서버(`SqlDialectMssql::chosungCondition()`)는 초성 자음
  아니면 alnum만 받고 완성형 한글 음절 자체와의 일치는 지원하지
  않는데(그 경우 `1=0`), 이 화면의 요구사항은 그 확장까지 필요해서
  별도로 구현했다(서버 쪽 `chosungCondition()`을 고치지 않음 — 그건
  `ChosungGrid`/`ChosungKeypad` 등 다른 화면과 공유하는 코드라 영향
  범위가 다르다).
- `this._allProducts`(서버가 준 이번 분류의 전체 상품, 필터 적용
  전)를 들고 있다가 검색어가 바뀔 때마다 그 배열 위에서만 다시
  걸러(`_applySearch()`) `rowData`를 갱신 — 서버 재조회 없음. 분류를
  바꿔도 검색어(`this._searchText`)는 유지되고, 새 분류의 상품
  목록에도 곧바로 다시 적용된다.

### 버튼식 초성 키패드 추가 (2026-09-12 — "기존 ChosungKeypad처럼 누르는 버튼식 키패드를 기대함")

위 매칭 로직은 처음엔 타이핑 검색바 하나에만 붙였는데, 실사용처가
키보드 없이 터치로 조작하는 POS 단말이라("다 되는데 초성검색이
우측에 표시안됨" — 눈에 보이는 UI가 없었다) 클릭으로 입력하는
`ChosungKeypad`(새로 안 만들고 `ChosungGrid.js`가 이미 쓰는 모듈을
그대로 재사용)를 검색바 옆(`dp-keypad-col`)에 추가했다:

- `_initKeypad()` — `dp-keypad-col`에 `new ChosungKeypad({...})`를
  얹는다. `onInput(value)`(지금까지 클릭으로 누적된 문자열, 예:
  `"ㄱㄷ"`)를 `this._chosungKeypadValue`에 저장하고 `_applySearch()`를
  다시 부른다. `onClear`(키패드의 Clear 버튼)는 그 값을 비우고 다시
  적용.
- `_applySearch()`는 이제 타이핑 검색어(`_searchText`)와 키패드
  누적값(`_chosungKeypadValue`)을 **순서대로(AND)** 적용한다 — 둘 중
  하나가 비어 있으면 그 단계는 그냥 통과(필터 없음)라서, 보통은 둘 중
  하나만 쓰지만 같이 써도 자연스럽게 동작한다.
- **의존성** — `ChosungKeypad`는 `ag-grid`와 달리 이 프로젝트의 다른
  in-house 모듈이라 `decoItem.js`가 자동으로 안 불러온다(같은 이유로
  `ChosungGrid.js`도 그렇다) — 이 클래스를 쓰는 호스트 페이지가
  `<script src=".../ChosungKeypad/ChosungKeypad.js">`를 `decoItem.js`
  보다 먼저 로드해둬야 한다. `decoItem_entry.php`는 이미 그렇게
  갱신해뒀다 — `new DecoItem(...)`을 직접 쓰는 다른 페이지를 만들면
  그 페이지에서도 똑같이 챙겨야 한다.
- `DecoItem.open()`의 기본 `iframeWidth`를 640→840으로 늘렸다(키패드
  자기 폭 188px + gap 12px 만큼, `ChosungGrid.js`가 이미 확인해둔
  같은 값).

## 서버 계약

- `decoItem.php?containerId=...` → 좌측 분류 그리드+검색바+우측 상품
  그리드+우측 키패드 자리, 4개의 빈 컨테이너 div만 있는 마크업(HTML,
  DB 접근 없음).
- `decoItem_fetch.php?action=분류목록` → `[{code, 분류}, ...]`(좌측
  그리드 rowData, code 오름차순).
- `decoItem_fetch.php?action=상품목록&분류code=...` → `[{code, 상품명,
  현금가, 카드가, 순서}, ...]`(우측 그리드 rowData, **순서 내림차순,
  같은 순서면 상품명 오름차순** — 2026-09-12 추가 지시 "상품정렬을
  순서 desc, 상품asc로") — `ORDER BY [순서] DESC, [상품명] ASC`.

`t상품분류`의 컬럼명(`code`/`분류`)은 사용자가 직접 확인해준 값.
`t상품` 쪽(`분류code`/`상품명`/`현금가`/`카드가`/`순서`)은 지시 3
원문 그대로 유지 — 혹시 이 중 하나라도 다시 다르면, 이제 조회
실패가 항상 해당 그리드 자리에 `.dp-error-text`로 그대로 보인다.

## `sender`/`callback` — 여는 쪽이 이름을 지어서 주입

`Calendar`/`NumberKeypad`/`ChosungGrid`와 동일한 관례
(`md/2026-09-11-action-callback-이름부여-규칙.md` 참고) — 행을 클릭하면
`onSelect(row)`(로컬 콜백) + `sender.action(callback, row, window)`을
부른다. **콜백 이름 기본값은 지시(8)의 `'callback_상품_선택'`**,
`options.callback`으로 다른 이름을 줄 수도 있다. `row`는 `{code,
상품명, 현금가, 카드가, 순서}` 그대로다. **팝업을 닫는 것은 이 클래스의
책임이 아니다** — 여는 쪽이 콜백을 처리한 뒤 스스로 닫아야 한다.

**[2026-09-13 추가] Quit(취소)** — 우측 키패드의 `ChosungKeypad`를
`showQuitButton:true`로 켜뒀다(이 위젯은 항상 `DecoItem.open()`으로
띄우는 팝업 전용이라 `ChosungKeypad.js` 자신의 docblock이 요구하는
조건에 정확히 해당). Quit을 누르면 `ChosungGrid.js`의 `onQuit`과 동일한
형태로 `sender.action(callback, { cancelled: true }, window)`을 부른다 —
`row` 대신 `cancelled:true` 하나뿐인 객체이므로, 호출부의 `action()`은
반드시 `pparams.cancelled`를 먼저 확인해야 한다(확인 안 하면
`pparams.상품명` 등이 `undefined`가 됨). 이 경우도 팝업을 닫는 것은
호출부 책임 그대로.

## 사용법 — 화면에 직접 얹기

**이 클래스보다 먼저 `<script src=".../ChosungKeypad/ChosungKeypad.js">`
를 로드해둬야 한다**(2026-09-12 추가 — 우측 키패드 의존성, 위
"버튼식 초성 키패드 추가" 절 참고).

```js
new DecoItem({
    containerId: 'dp-root',   // 필수
    pageUrl: '...',            // 선택, 기본값은 같은 폴더의 decoItem.php(마크업+분류 목록)
    fetchUrl: '...',           // 선택, 기본값은 같은 폴더의 decoItem_fetch.php(상품 목록 JSON)
    sender: window,            // 선택
    callback: 'callback_상품_선택', // 선택, 생략 시 기본값 그대로
    onSelect: (row) => { ... },
});
```

## 공개 API

- `requery()` — 지금 선택된 분류로 다시 조회
- `destroy()` — DOM/이벤트 정리(팝업 닫을 때 등)

## `DecoItem.open(options)` — 팝업으로 상품 하나만 받기

`ChosungGrid.open()`과 완전히 같은 형태 — `Popup.js`의 `iframeUrl`로
같은 폴더의 `decoItem_entry.php`를 연다.

```js
const popup = DecoItem.open({
    sender: window,                    // 필수 — 결과를 받을 창
    callback: 'callback_상품_선택',     // 선택 — 생략 시 기본값 그대로
    ptitle: '상품선택',                  // 선택 — Popup 제목
});
```

여는 쪽의 전역 `action(pcmd, pparams, psender)`가 `case
'callback_상품_선택':` 안에서 `pparams`(선택된 상품 행)를 받아 처리한
뒤, `popup.close()`로 직접 닫는다(`content4_9.php`가 이 조합을 데모로
보여준다).

## `decoItem_entry.php` — 모듈 자기 검증 페이지

다른 위젯들과 동일한 "두 가지 방식으로 다 열어볼 수 있게" 관례 —
직접 열기(로컬 로그)/Popup으로 열기(부모에 보고). `?fetchUrl=`/
`?callback=` 쿼리스트링으로 바꿔볼 수 있다.

## `4_9`(상품선택) 실사용 예

`public/test/Layout/test1/content4_9.php` — 팝업으로 상품 하나를 받는
데모. `menu.json`의 `leftMenu["4"]`에 `{"key":"9","label":"상품선택"}`을
추가해 상단메뉴 4(프로그램) 안에서 좌측메뉴로 노출된다.

## ag-grid vendor 재사용

`ChosungGrid.js::_ensureLibraryLoaded()`가 이미 겪고 고친 것과 완전히
동일한 방식(CSS `<link>` 로드 완료까지 기다린 뒤에야 그리드를 만드는
것, 컨테이너 크기 변화를 `ResizeObserver`로 지켜보며
`sizeColumnsToFit()`을 다시 부르는 것, 불필요한 가로 스크롤바를 항상
숨기는 것)을 그대로 재사용한다 — `src/js/aggrid/vendor/`의 ag-grid
Community 번들을 두 번째로 새로 받지 않는다.

## 알려진 한계

- `t상품`의 컬럼명(`분류code`/`상품명`/`현금가`/`카드가`/`순서`)은
  지시 3 원문 SQL 그대로 유지한 것 — `t상품분류`(`code`/`분류`)와
  달리 아직 사용자가 직접 확인해준 값은 아니다. 다르면 조회가
  실패(500)하거나 빈 배열만 돌아올 수 있지만, 이제 실패 사유가 우측
  그리드 자리에 그대로 보인다.
- 분류 목록/상품 목록 어디에도 페이지네이션이 없다(전체를 한 번에
  불러온다) — 상품 개수가 매우 많아지면 재검토 필요.
