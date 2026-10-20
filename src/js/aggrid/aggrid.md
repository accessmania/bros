# AGGrid — 참고서

`src/js/aggrid/AGGrid.js`가 하는 일과 사용법을 지금 시점 기준으로 정리한
문서. **시간순 작업 일지가 아니라 증류된 현재 상태 참고서**다 — 이 모듈이
왜 이런 모양인지의 설계 논의 과정은 `md/Board_ai.md`에 있다(검색: "aggrid
모듈화"). 이 문서와 `AGGrid.js` 소스만 읽으면(맥락 없는 새 세션이라도) 새
화면에 그리드를 붙이는 데 필요한 걸 전부 알 수 있게 하는 게 목적.

## 한 줄 요약

AG Grid(ag-grid-community, `src/js/aggrid/vendor/`에 로컬로 내려받아 둠 —
CDN 미사용)를 감싼 재사용 가능한 그리드 껍데기.
"테이블/필드정보 + fetch URL + 이벤트 규칙"만 주입하면, 툴바·조회·저장·
삭제·검색·편집/읽기모드 전환·행선택·조건부서식·페이저가 이미 갖춰진 채로
동작한다. `src/js/ScrollGrid.js`(직접 구현한 그리드)와 철학은 같지만,
AGGrid는 AG Grid의 내장 기능(정렬/필터/셀 편집기 등)을 그대로 빌려 쓴다 —
이 클래스가 새로 만드는 건 "AG Grid를 화면에 반복 없이 붙이는 규칙"뿐이다.

## 새 화면에 그리드를 추가하는 법 (적용 가이드)

한 화면(컨테이너 페이지)에 그리드를 하나 추가하려면 파일 3개가 필요하다.
`public/test/Board/1_9/`(그리드 식별자 `회원`)가 실제 예시.

1. **`fetch_<식별자>.php`** — select(GET)/save·delete(POST)를 처리하는
   CRUD 엔드포인트. `Board`/`Crud`/`ActionBulk`를 그대로 재사용해서 만든다
   (아래 "서버 구현 패턴" 참고). 파일명의 `<식별자>`는 그리드 식별자와
   반드시 같아야 한다(예: `fetch_회원.php`).
2. **`config_<식별자>.php`** — 프론트엔드 config. `APP_BASE_URL`로
   `fetch_<식별자>.php`의 절대 URL을 계산하고, `<script>` 블록으로
   `new AGGrid({...})`를 호출하는 코드를 echo한다. 컬럼 정의(1번 요소:
   테이블/필드정보)와 이벤트 규칙(더블클릭 등)이 전부 이 파일 안에 있다 —
   컨테이너 페이지는 이 파일의 존재를 몰라도 된다(`require`만 하면 끝).
3. **컨테이너 페이지**(예: `content1_9.php`) — `AGGrid.js` 스크립트 태그
   한 줄(그리드가 여러 개면 한 번만) + `<div id="aggrid-<식별자>"></div>`
   + `config_<식별자>.php`를 `require`. 그리드가 4~5개면 이 세 줄짜리
   블록을 그만큼 반복하면 된다 — 각 그리드의 정보주입/이벤트정의는 서로
   다른 `config_*.php` 파일에 있어서 컨테이너 코드량이 늘지 않는다.

```php
<!-- 컨테이너 페이지 -->
<?php
// AGGrid.js를 자주 수정하는 동안 브라우저 캐시 때문에 옛 버전이 보이는
// 문제를 막으려면 파일 수정시각을 쿼리스트링에 붙인다(board-modal.js와
// 동일한 관례, content1_9.php가 실제 예시).
$aggridJsPath = APP_BASE_DIR . '/src/js/aggrid/AGGrid.js';
$aggridJsVersion = is_file($aggridJsPath) ? filemtime($aggridJsPath) : time();
?>
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>

<div id="aggrid-회원"></div>
<?php require __DIR__ . '/../../Board/1_9/config_회원.php'; ?>

<div id="aggrid-매출"></div>
<?php require __DIR__ . '/../../Board/1_9/config_매출.php'; ?>
```

## 기본설정(baseline) — 1_9가 표준 예시다

`public/test/Board/1_9/{config_회원.php, fetch_회원.php}` +
`public/test/Layout/test1/content1_9.php`는 단순한 데모 하나가 아니라,
**AGGrid의 공식 기본설정(baseline)**으로 명명한다 — 여러 차례에 걸친
UI 조정(툴바 색상/구분선/높이 등, `md/Board_ai.md` "AGGrid 툴바"로 시작하는
연속된 항목들 참고)을 거쳐 최종적으로 확정된 모양이 이 세 파일에 그대로
반영돼 있다. 앞으로:

- **`AGGrid.js`가 스스로 갖고 있는 기본 외형/동작**(툴바 버튼 배색 — 저장/
  "+ 행 추가" 둘 다 옅은 파랑+짙은 글자(2026-08-26 변경 — "+ 행 추가"는
  원래 초록이었음)/삭제·편집취소 둘 다 옅은 빨강+흰 글자(2026-08-26
  변경 — "편집취소"는 원래 무채색이었음)/나머지 무채색, **툴바 모드
  버튼(2026-10-20 변경) — 모드 전환 버튼은 항상 툴바 맨 앞이고 문구는
  읽기전용일 때 "편집", 편집모드일 때 "잠금"(원래 "읽기모드")이다.
  "+ 행 추가"는 편집모드에서만 보인다(원래 모드 무관 항상 보였고 읽기전용에서
  누르면 편집모드로 자동 전환됐음). "잠금"을 누르면 저장 안 한 편집(dirty)이
  있을 때 "편집취소"도 자동으로 함께 실행돼 서버 원본으로 되돌아간다**,
  **안내 문구 숨김(2026-10-20 추가) — "편집을 취소했습니다."/"취소할 편집
  내용이 없습니다."/"저장할 변경 사항이 없습니다."/"저장 중..."/"저장 완료"/
  "삭제 중..."/"삭제 완료" 같은 안내 문구는 툴바에 안 나온다(`showInfoStatus:
  true`로 켤 수 있음). "목록을 불러오지 못했습니다."/"저장 실패"/"저장 오류"/
  "삭제 실패"/"삭제 오류"와 "이동" 도구의 "찾을 수 없습니다" 문구는 사용자가
  알아야 하는 결과라 항상 나온다**,
  검색창 안쪽 "×" 지우기 버튼, 테두리
  없이 그리드 바로 위 배치, 그리드 컬럼 사이 세로 구분선, 숫자처럼 보이는
  값의 천단위 콤마 자동 표시, 기본 읽기전용 시작, **컬럼 너비는 화면
  폭에 맞춰 고르게 늘리지 않고(flex 없음) 첫 데이터가 그려진 직후
  `gridApi.autoSizeAllColumns()`로 내용 기준(bestfit) 너비를 계산 —
  2026-08-26 변경, 원래 기본값은 `flex:1`이었음**, **"메모"/`memo`
  필드(대소문자 무관)는 config가 `columns`에 어느 위치에 넣었든 항상
  맨 뒤(다른 데이터 컬럼 전부보다 뒤, "삭제" 컬럼보다는 앞)로 재배치
  — 2026-08-26 추가**, **셀 정렬 기본값(2026-08-26 추가) — 체크박스
  컬럼(`cellRenderer: 'agCheckboxCellRenderer'`)은 중앙 정렬(단순
  `textAlign`만으로는 체크박스가 flex 배치라 안 먹을 수 있어
  `display:flex`+`justifyContent`+`alignItems`까지 함께 강제), 숫자처럼
  보이는 값(천단위 콤마와 동일한 판정, `AGGrid._looksNumeric()`)은
  우측 정렬. `columns`의 각 컬럼에 `align: 'left'|'center'|'right'`를
  주면(AG Grid 정식 속성이 아니라 이 모듈만 해석하는 편의 표기, 이것도
  `textAlign`+`justifyContent`를 함께 줌) 이 기본값보다 우선해 그
  정렬을 강제할 수 있음(예: 숫자라도 가운데 정렬하고 싶을 때). config가
  이미 자기 `cellStyle`을 지정한 컬럼은 이 정렬 기본값도, `align`도
  전부 무시하고 그 cellStyle을 그대로 씀**, **`dataType`(2026-09-01
  추가) — DB 스키마 등으로 필드 타입을 이미 아는 경우, `columns`의 각
  컬럼에 `dataType: 'string'|'number'`를 주면(이것도 AG Grid 정식
  속성이 아니라 이 모듈만 해석하는 편의 표기) 값 기준 추측
  (`_looksNumeric`) 대신 그 타입을 그대로 신뢰해 정렬/콤마 포맷을
  **강제**한다 — `'string'`이면 항상 좌측 정렬이고 값이 숫자처럼
  보여도(전화번호/우편번호 등) 절대 천단위 콤마를 안 붙이며, `'number'`
  이면 항상 우측 정렬이고 천단위 콤마가 붙는다. `align`이 있으면
  `align`이 여전히 우선(정렬만 원하는 값으로 덮어쓰고 싶을 때), `cellStyle`을
  이미 지정한 컬럼에는 `dataType`도 적용 안 됨(콤마 포맷 쪽은 `align`과
  무관하게 항상 `dataType`이 우선). 타입 정보가 없는 컬럼은 기존처럼
  값 기준 추측을 그대로 씀**)은 이 모듈을 쓰는 **모든**
  화면에 자동으로 적용되는 기본값이다 — 화면마다 다시 지정할 필요 없다.
- **1_9의 `config_회원.php`가 고르는 값들**(`fetchMode:'paged'`,
  `pageSize:100`, `autoFitBottomGap:40` 등)은 모듈의 기본값이 아니라
  **이 화면이 선택한 예시값**이다 — 다른 화면은 다른 값을 얼마든지 골라도
  된다. 다만 "표준 예시"라는 지위 때문에, 새 화면을 만들 때 특별한 이유가
  없으면 이 값들을 그대로 복사해 시작하는 것을 권장한다.
- `AGGrid.js`의 기본 외형 자체를 바꾸는 작업(예: 팔레트를 다시 바꾼다)을
  할 때는, 이 baseline(1_9)이 그 변경의 실제 검증 대상이 된다 — "1_9에서
  보이는 모습"이 곧 "이 모듈을 쓰는 모든 화면의 기본 모습"이라는 뜻이므로.

## 기초자료형 — `basicdata/login/login_entry.php`가 표준 예시다

**"기초자료형"**은 DB 테이블 하나를 AGGrid로 **검색·조회·추가·수정·삭제**하는
화면을 만드는 정해진 틀이다(2026-10-20 명명). 표준 예시는
`basicdata/login/{login_entry.php, login_fetch.php}`(tLogin 테이블)이다.
### 요청 형식 — 매개변수 2개

```
name: login
tablename: tLogin
aggrid 기초자료형으로 해줘
```

| 매개변수 | 뜻 | 예 |
|---|---|---|
| `name` | 폴더/파일 이름에 쓰는 영문 소문자 식별자 | `login` |
| `tablename` | 대상 DB 테이블 이름 | `tLogin` |

이렇게 요청받으면 아래 두 파일을 만들고, **login_entry와 같은 방식으로
제작한다**(아래 규칙 전부 적용):

- `basicdata/<name>/<name>_entry.php` — 화면(html 골격 + 프론트 config).
  다른 페이지에 include하는 조각이 아니라 **혼자 열리는 완결된 페이지**다.
- `basicdata/<name>/<name>_fetch.php` — CRUD 엔드포인트(이 문서의 "서버
  계약"/"서버 구현 패턴" 그대로). entry의 `$fetchUrl`이 이 파일을 가리킨다.

### 파일/이름/메뉴

- 폴더와 파일 이름은 `name` 하나로 통일한다 — 한글 폴더/파일명은 쓰지
  않는다(tLogin → `login`, tAuth → `auth`).
- `src/menu/menu.js`의 해당 메뉴 항목에 연결한다:
  `{ title: '<메뉴명>', ribbon: 'button기초자료', src: 'basicdata/<name>/<name>_entry.php', themeParam: false }`.
- 그리드 ID(`gridId`, `#aggrid-<gridId>`)는 메뉴명과 같게 둬도 된다
  (예: `'로그인'`) — CSS 선택자가 이 ID에 묶이므로 한 번 정하면 바꾸지 않는다.

### `<name>_entry.php` 구성(위에서 아래 순서)

1. 부트스트랩 — `config/Path.php`를 찾아 올라가는 공통 for 루프 →
   `include_custom('DbContext', 'Crud', 'Str', 'Login')`.
2. `Login::isAlive();` — 로그인 안 됐으면 로그인 화면으로 보내고 끝.
3. `$connectionName = null`(config/db.php 기본 접속) → `new DbContext()` →
   `Crud::init('<tablename>')`로 **스키마를 직접 조회**한다. 컬럼을 코드에
   하드코딩하지 않는다 — PK(`getPrimaryKey()`), 필드 목록, 필드별 SQL 타입을
   여기서 얻는다.
4. 컬럼 구성 규칙:
   - PK 컬럼은 `columns`에 넣지 않는다(행 데이터엔 항상 있어 저장/삭제엔 지장 없음).
   - **필드명에 `code`가 들어간 컬럼(대소문자 무관, `stripos`)도 `columns`에
     넣지 않는다**(2026-10-20 추가). 화면에서만 숨기고 fetch 응답에서는 빼지
     않는다 — PK/FK 값이 행 데이터에 그대로 실려 저장·삭제가 정상 동작해야
     하므로. 단, 콤보처럼 화면에서 그 값을 직접 다뤄야 하는 컬럼은 예외로
     둔다(예: login_entry의 `권한code` — 권한 콤보).
   - 각 컬럼에 스키마 타입으로 `dataType`을 준다 —
     `int/bigint/smallint/tinyint/decimal/numeric/float/real/money/smallmoney`
     → `'number'`, 나머지 → `'string'`(login_entry의 `b21_data_type_for()`).
     값 기준 추측으로 id 같은 문자 필드에 콤마가 붙는 오탐을 막는다.
   - 기본은 스키마 순서 그대로, `editable: true`, `headerName`은 필드명.
     "메모"는 AGGrid.js가 자동으로 맨 뒤로 보낸다. 요청이 있으면 순서를
     고정하거나 특정 컬럼을 숨긴다(숨긴 컬럼은 fetch에서도 같이 뺀다).
5. `$fetchUrl = APP_BASE_URL . '/basicdata/<name>/<name>_fetch.php'`,
   AGGrid.js 캐시 방지 버전(`filemtime`)을 구한다.
6. html — `<head>`에 AGGrid.js를 **동기 로드**(async/defer 금지 —
   `document.currentScript`로 vendor 경로를 찾음), 공통 body 스타일
   (`padding:16px`, `h1 1.1rem`)과 이 화면 전용 CSS(`#aggrid-<gridId>`로
   범위 제한). `<body>`에 `<h1>메뉴명</h1>` + `<div id="aggrid-<gridId>"></div>`.
7. `new AGGrid({...})` — baseline 값 그대로:
   `fetchMode: 'paged'`, `pageSize: 100`, `autoFitBottomGap: 40`,
   `primaryKey`/`fetchUrl`은 PHP가 `json_encode`로 심는다.

### `<name>_fetch.php` 구성

- 부트스트랩 → `include_custom('DbContext', 'Board', 'Crud', 'ActionBulk', 'BoardConfig')`.
- `Crud::init()`으로 스키마 조회 → 표시 필드(숨김 컬럼 제외)로
  `BoardConfig`(`tableName`/`primaryKey`/`columns`/`searchFields`/`fields`)를 채운다.
  민감 필드(비밀번호 등)는 표시는 하더라도 `searchFields`에서는 뺀다.
- `$_GET['action']`(`list_paged`|`list_all`|`save`|`delete`)으로 확정 후
  `switch` — 없으면 `REQUEST_METHOD`/`mode`로 추정.
  - `save`/`delete` → `(new ActionBulk($crud, $config))->handle()`.
  - `list_all`/`list_paged` → `Board`로 조회. `Board::getRows()`는 `SELECT *`라
    숨긴 컬럼이 새지 않도록 `getArrayColumns()`로 한 번 더 걸러 응답한다.

### 특수 컬럼(필요할 때만 — login_entry에 구현 예시가 있음)

| 종류 | 판정(필드명) | 처리 |
|---|---|---|
| 비밀번호 | `/pw\|pass\|암호\|비밀번호/i` | `PasswordCellEditor`(type=password), 목록은 `••••••••` 마스킹 |
| 참조 콤보 | 요청 시(예: `권한code` → tAuth) | 페이지 로드 때 참조 테이블을 한 번 읽어 `<script>`에 심고, `agSelectCellEditor` + `valueFormatter`로 "값은 code, 표시는 이름" |
| 사용여부 체크박스 | `/사용여부\|사용유무/i` | `agCheckboxCellRenderer/Editor`, `valueGetter`는 `Str::TRUE_STRINGS` 규칙으로 bool, `valueSetter`는 1/0 |
| 조건부 강조 | 요청 시 | 컬럼별 `cellStyle` 함수(행 단위 서식은 `conditionalFormats`) |

### 여러 테이블을 한 화면에 — `basicdata/status/`가 예시

테이블 여러 개를 한 entry에 그리드 여러 개로 보여줄 때(2026-10-20, 상태
화면 — tb상태_문의/문의내역/견적/수주/발주 5개):

- `<name>_tables.php` — 키 → `{table, title}` 목록을 돌려주는 파일 하나.
  entry와 fetch가 같이 쓴다.
- fetch는 파일 하나로 두고 `?t=<키>`로 테이블을 고른다. **목록에 없는 키는
  거부**(임의 테이블 접근 차단). entry는 그리드마다
  `fetchUrl: '.../<name>_fetch.php?t=<키>'`를 준다 — AGGrid.js가 fetchUrl에
  `?`가 있으면 `&`로 잇는다(`_fetchUrlWith`, 조회/저장/삭제 모두).
- 그리드가 여럿이라 `autoFitBottomGap` 대신 고정 `height`를 쓴다.
- 테이블 하나가 없어도 화면 전체가 죽지 않게 `Crud::init()`을 try/catch로
  감싸고, 그 칸에만 원인을 표시한다.

참조 테이블이 DB에 없을 수 있으면 `Crud::init()`을 try/catch로 감싸 화면
전체가 죽지 않게 한다(`basicdata/auth/auth_entry.php`의 `$loadAuthCandidates` 참고).

## 서버 계약

- **GET(select)** — 두 모드:
  - `fetchMode:'paged'`(기본): 요청 쿼리 `page`/`pageSize`/`sort`/`dir`/`q`
    — 이름이 `Board::init()`이 `$_GET`에서 직접 읽는 이름과 **완전히
    같다**(`src/Model/Board/Board.php`의 `resolveIntParam()`/`resolveOrderBy()`/
    `resolveCondition()`). 서버는 `$board->init($table, $columns,
    $searchFields, $_GET['q']??'', '', null, null, 1);`처럼 그대로
    호출하면 페이지/정렬/검색을 직접 파싱할 필요가 없다. 화면을 처음 열
    때(헤더 클릭으로 아직 아무 정렬도 안 고른 상태) 기본으로 어떤 순서로
    보일지 정하고 싶으면(2026-08-26 추가) 10번째 인수 `$pDefaultOrderBy`에
    raw SQL ORDER BY 내용을 넘긴다(예: `'세트메뉴 DESC, 상품명 ASC'`) —
    5번째 인수(`$pOrderBy`)와 달리 헤더 클릭 정렬을 막지 않고, 사용자가
    아직 헤더를 안 눌렀을 때만 이 기본값이 쓰인다. 응답은
    `{rows: [...], lastRow: number}`(AG Grid Infinite Row Model 계약 —
    `lastRow`는 이 응답이 마지막 페이지면 총 건수, 아니면 `-1`).
  - `fetchMode:'all'`: 요청 쿼리 `mode=all`/`q`. 응답은 전체 매칭 레코드를
    한 번에 담은 `{rows: [...]}`(정렬/열별 필터는 AG Grid가 브라우저 안에서
    처리 — 서버 왕복 없음).
  - 두 모드 모두 `rows`의 각 행은 config가 선언한 컬럼뿐 아니라
    **`primaryKey` 필드도 항상 포함**해야 한다(화면에 안 보여도 저장/삭제/
    행선택 식별에 필요) — `Board::init()`의 `$pColumns`에 PK를 포함시켜서
    `getRows()`를 쓰면 자동으로 충족된다.
- **POST(save/delete)** — JSON body `{rows: [...], deletedCodes: [...]}` →
  `{results: [...], deleteResults: [...]}`. **`ScrollGrid`의 `bulkSaveUrl`
  계약과 완전히 같다** — 서버는 `ActionBulk::handle()`(`src/Action/ActionBulk.php`)
  을 그대로 재사용하면 된다. "저장" 클릭 시엔 `rows`만, 행 "삭제" 클릭
  시엔 `deletedCodes`만 채워 보낸다(반대쪽은 빈 배열). `results`는 보낸
  `rows`와 인덱스로 1:1 대응한다(신규 행은 저장 전 PK가 없어 인덱스
  매칭이 필수).
- **`action` 쿼리 파라미터**(2026-08-25 추가) — 위 네 가지 요청 전부
  쿼리스트링에 `action=list_paged`|`list_all`|`save`|`delete`를 추가로
  함께 보낸다. GET/POST 구분(읽기/쓰기)은 그대로 유지한 채, 그 안에서
  정확히 어떤 요청인지 값의 유무(예: `mode` 파라미터 존재, `rows`/
  `deletedCodes` 중 어느 쪽이 채워졌는지)로 추론하는 대신 이름으로 바로
  읽을 수 있게 하는 보조 신호다. **완전히 선택적**이라, 이 값을 안 읽는
  화면(예: 1_9/1_20의 `fetch_회원.php`)은 원래 방식(GET의
  page/pageSize/sort/dir/q·mode=all, POST의 rows/deletedCodes 유무) 그대로
  정상 동작한다 — 서버가 이 값을 반드시 읽어야 하는 건 아니다. 명시적
  분기가 필요한 화면(`public/test/Board/2_1/fetch_로그인.php`가 실제
  예시)에서만 `$_GET['action']`을 읽어 `switch`로 나누면 된다.

### 서버 구현 패턴 (`fetch_<식별자>.php`)

```php
<?php
declare(strict_types=1);
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) { throw new RuntimeException('...'); }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Board', 'Crud', 'ActionBulk', 'BoardConfig');

$config = new BoardConfig();
$config->tableName = 't회원';
$config->primaryKey = 'code';
$config->columns = 'code:번호,이름,나이,신장,몸무게,등록'; // PK 포함 필수
$config->searchFields = '이름';
$db = new DbContext($config->connectionName); // 이 요청의 DB 접속(처음 쓸 때 연결)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $crud = new Crud($db);
    (new ActionBulk($crud, $config))->handle(); // {results, deleteResults} 응답 후 exit
}

header('Content-Type: application/json; charset=utf-8');
$board = new Board($db);

if (isset($_GET['mode']) && $_GET['mode'] === 'all') {
    $board->init($config->tableName, $config->columns, $config->searchFields, $_GET['q'] ?? '', '', 1, 1000000, 1);
    echo json_encode(['rows' => $board->getRows()], JSON_UNESCAPED_UNICODE);
} else {
    $board->init($config->tableName, $config->columns, $config->searchFields, $_GET['q'] ?? '', '', null, null, 1);
    $totalRows = $board->getTotalRows();
    $lastRow = ($board->getPage() * $board->getPageSize() >= $totalRows) ? $totalRows : -1;
    echo json_encode(['rows' => $board->getRows(), 'lastRow' => $lastRow], JSON_UNESCAPED_UNICODE);
}
```

이 `BoardConfig`는 `config_<식별자>.php`(프론트엔드 config, 컬럼 라벨/AG
Grid columnDefs를 담음)와 테이블/컬럼 정보가 일부 겹친다 — 의도된 중복이다
("파일 하나 = 자기완결" 원칙 우선, `md/Board_ai.md` 2026-08-13 "config를
화면별 자기완결로 재분리" 항목과 같은 트레이드오프).

## 생성자 옵션

```js
new AGGrid({
  gridId: '회원',                   // 필수 — 이 그리드 인스턴스의 식별자(AGGrid.instances 등록 키)
  containerId: 'aggrid-회원',       // 생략 시 'aggrid-' + gridId
  fetchUrl: '...',                  // 필수 — select(GET)/save·delete(POST) 공용 엔드포인트
  primaryKey: 'code',               // 필수 — PK 필드명(테이블에 실제 PK가 없어 null/빈 문자열이면
                                     // getRowId를 아예 안 씀 — AG Grid 기본 식별자로 조회는 되지만
                                     // 편집/저장은 Crud::update()가 예외를 던져 여전히 불가능,
                                     // 조회 전용으로만 씀)
  columns: [                        // 필수 — AG Grid columnDefs를 거의 그대로 (테이블/필드정보)
    { field: '이름', headerName: '이름', editable: true, filter: true },
    { field: '나이', headerName: '나이', editable: true, valueParser: (p) => Number(p.newValue) },
  ],
  fetchMode: 'paged',                // 'paged'(기본) | 'all'
  pageSize: 100,                     // paged 모드 페이지 크기
  height: '480px',                   // 그리드 영역 고정 높이(autoFitBottomGap 없을 때만 씀)
  autoFitBottomGap: 40,               // 주면 height 대신 화면(스크롤 조상) 바닥에서 이 값(px)만큼 띄운 높이로 자동 계산(반응형, resize 시 재계산)
  minHeight: 200,                     // autoFitBottomGap 적용 시 최소 높이
  defaultMode: 'view',               // 'view'(기본, 읽기전용) | 'edit'
  showGoToTool: false,                // 기본 숨김. true면 "키값으로 이동" 도구 표시
  newRowDefaults: {},                 // (2026-10-20 추가) "+ 행 추가"한 새 행에 미리 채울 값 {필드: 값}. 화면에 안 보이는 FK를 부모 키로 채울 때 사용(예: 고객사 팝업의 담당자 그리드 { parentcode: 고객사키 })
  showInfoStatus: false,              // 기본 숨김(2026-10-20 추가). true면 "편집을 취소했습니다."/"저장 완료" 등 안내 문구 표시(실패/오류 문구는 옵션과 무관하게 항상 표시)
  showAddRow: true,                   // 기본 표시("+ 행 추가", 편집모드에서만 노출). false면(2026-08-26 추가) 이 버튼 자체를 안 그림 — 신규 레코드를 만들 일이 없는 화면(예: 아가씨단가/밴드단가)에서 사용
  showDeleteColumn: true,             // 기본 표시(edit 모드에서 행별 "삭제" 컬럼). false면(2026-08-26 추가) 그 컬럼 자체를 안 그림 — 레코드를 지울 일이 없는 화면(예: 아가씨단가/밴드단가)에서 사용
  onRowDblClick: (values, key) => {}, // view 모드에서 행 더블클릭 시(이벤트 규칙 주입)
  onReady: (grid) => {},              // 툴바/그리드 DOM 구성이 끝난 뒤 1회 호출(2026-08-25 추가) — 아래 "onReady" 항목 참고
  onToolbarRendered: (grid) => {},    // 툴바가 (재)렌더될 때마다 매번 호출(2026-08-26 추가) — 아래 "onToolbarRendered" 항목 참고
  onFirstDataRendered: (grid) => {},  // 첫 데이터 블록이 실제로 그려진 뒤 1회 호출(2026-08-26 추가) — bestfit(autoSizeAllColumns)은 이제 기본 동작이라 이 콜백을 안 줘도 자동으로 실행됨(내부적으로 먼저 실행된 뒤 이 콜백이 이어서 호출됨); 컬럼 폭 계산 "이후"에 추가로 할 일이 있는 화면만 이 옵션을 쓰면 됨
  conditionalFormats: [               // MS Access "조건부 서식" — 규칙은 항상 호출부가 주입
    { when: (values) => Number(values['나이']) > 60, style: { background: '#e6f4ea' } },
  ],
});
```

- `columns`는 AG Grid의 `columnDefs`를 **거의 그대로** 받는다 — `field`/
  `headerName`/`editable`/`sortable`/`filter`/`valueFormatter`/
  `valueParser`/`cellEditor`/`cellEditorParams` 등 AG Grid가 원래 지원하는
  속성을 그대로 쓸 수 있다(ScrollGrid처럼 별도 선언형 스타일 체계를 다시
  만들지 않았다 — AG Grid가 이미 훨씬 풍부한 기능을 갖고 있어서, 이 모듈이
  대신 관여하는 건 "언제 편집 가능한가"(모드에 따라)뿐이다). `editable`을
  `true`/함수 어느 쪽으로 줘도 AGGrid가 자동으로 "그리고 지금 edit
  모드인가"를 덧씌운다 — 화면단이 모드 분기를 따로 안 적어도 된다.
- **숫자처럼 보이는 값은 기본으로 천단위 콤마가 붙는다**(`valueFormatter`를
  자동으로 감쌈, config가 별도 표시를 안 해도 적용됨 — ScrollGrid의
  `numberFormat`이 opt-in이었던 것과 달리 여기서는 기본값). 값이 비어있거나
  숫자로 못 바꾸는 문자열이면 원본 그대로 보여준다(NaN 표시 방지). AG
  Grid의 `valueFormatter`는 표시 전용이라 실제 셀 편집 시작 시엔 원본 값이
  그대로 입력칸에 들어가므로 "표시만 포맷, 저장값은 원본"이 자동으로
  보장된다. 컬럼이 자기 `valueFormatter`를 이미 지정했으면 그걸 존중해
  안 건드린다. **주의**: 텍스트 필드에 숫자만 있는 값(예: 테스트 데이터로
  들어간 "1213")이 섞여 있으면 그 값도 콤마가 붙는다 — `ScrollGrid.js`가
  이 자동감지를 opt-in으로 남겨둔 이유이기도 하다(md/Board_ai.md "SG
  columnStyles.numberFormat" 항목 참고). AGGrid는 사용자 요청으로 기본값을
  반대로(자동 적용) 택했다 — 원치 않으면 그 컬럼에
  `valueFormatter: (params) => params.value`처럼 항등 포맷터를 직접
  지정해 끄거나, 필드 타입을 이미 안다면 `dataType: 'string'`을 주는
  편이 더 정확하다(위 "셀 정렬 기본값" 항목 참고 — `dataType: 'string'`
  이면 콤마 포맷도 함께 강제로 꺼진다).
- **`onReady`**(2026-08-25 추가): `new AGGrid(...)` 생성자는 AG Grid
  라이브러리(vendor 스크립트)를 **비동기**로 로드한 뒤에야 툴바/그리드
  DOM을 실제로 만든다 — 그래서 `new AGGrid({...})` 호출 바로 다음 줄에서
  `document.getElementById(그 그리드의 toolbarId)` 같은 DOM 접근을 하면
  아직 없다(라이브러리가 이미 다른 그리드에서 로드돼 캐시돼 있어도
  `.then()`은 항상 마이크로태스크라 한 박자 늦다 — "생성자가 동기적으로
  DOM을 그린다"고 가정하면 안 됨). 호출부가 툴바 DOM에 직접 손대야 하는
  경우(예: 커스텀 버튼을 `.ag-shell-toolbar-left`에 꽂아 넣기 — 2_2의
  "권한추가" 버튼)엔 `onReady: (grid) => {...}` 콜백에서 하면 안전하다 —
  이 콜백은 툴바/그리드 DOM 구성과 최초 데이터 요청 시작까지 끝난
  시점(`_init()` 맨 끝)에 한 번만 불린다.
- **`onToolbarRendered`**(2026-08-26 추가): `onReady`와 달리 툴바가
  (재)렌더될 때마다 — 최초 1회뿐 아니라 `setMode()`로 edit↔view를
  전환할 때마다도 — 매번 호출된다. `_renderToolbar()`는 그때마다
  `toolbarEl.innerHTML`을 통째로 새로 만들기 때문에, `onReady`에서
  툴바에 끼워 넣은 커스텀 요소(예: 라디오버튼 필터)가 있으면 모드를
  전환하는 순간 사라진다 — 이런 화면은 `onReady` 대신
  `onToolbarRendered: (grid) => {...}`를 써서 렌더될 때마다 다시
  끼워 넣어야 한다(2_5의 "직원 종류" 라디오필터가 검색창 앞에
  끼워지는 것이 실사용 예). 옵션을 안 쓰면(기본값 `null`) 기존
  화면엔 전혀 영향 없다.
- `primaryKey` 컬럼은 `columns`에 안 넣는 게 기본(ScrollGrid의 PK 숨김
  기본값과 동일 원칙) — 값은 행 데이터에 항상 있으므로 저장/삭제/선택
  식별에는 지장 없다. 화면에 보여주고 싶으면 `columns`에 그 필드를
  `editable: false`로 직접 추가하면 된다.
- `fetchMode: 'paged'`는 AG Grid의 **Infinite Row Model**을,
  `'all'`은 **Client-Side Row Model**을 그대로 켠다(이 모듈이 새로 구현한
  로직이 아니라 AG Grid 내장 기능을 스위치만 하는 것) — `'paged'`는 검색/
  정렬을 서버가 처리(요청마다 재조회), `'all'`은 최초 1회만 전체를 받아온
  뒤 AG Grid가 브라우저 안에서 정렬/필터를 처리한다.
- 그리드 영역의 높이는 **컨테이너 페이지가 아니라 이 config(생성자 옵션)가
  결정**한다 — `autoFitBottomGap`을 안 주면(기본) `height` 고정값을 쓰고,
  숫자를 주면 화면 하단(정확히는 가장 가까운 `overflow-y:auto` 조상의
  바닥)까지 자동으로 채운다(`src/js/ScrollGrid.js`의 `autoFitBottomGap`과
  같은 원리 — Layout의 `main.content`처럼 그 자체가 스크롤 영역인 조상을
  자동으로 찾는다). 창 크기 조절 시에도 다시 계산된다.

## 공개 메서드 / 정적 API

| 메서드 | 설명 |
|---|---|
| `AGGrid.instance(gridId)` | (static) 식별자로 등록된 인스턴스를 찾는다. 없으면 `null`. |
| `requery()` | 목록을 처음부터 다시 불러온다(검색 확정 시 내부적으로도 호출). 이 인스턴스만 갱신되고 같은 페이지의 다른 그리드는 전혀 안 건드린다. |
| `setExtraQuery(obj)` (2026-08-26 추가) | `searchKeyword` 외에 서버 요청(GET 쿼리)에 추가로 실어 보낼 키/값을 설정하고 즉시 `requery()`한다. 예: 라디오버튼 같은 화면 고유 필터를 `AGGrid.instance('직원').setExtraQuery({type: '팀장'})`처럼 전달 — 서버(fetch 엔드포인트)가 이 값을 어떻게 해석할지는 화면마다 다르다(`fetch_직원.php` 참고). `{}`를 넘기면 필터 해제. |
| `destroy()` (2026-10-20 추가) | AG Grid 인스턴스를 정리하고 `AGGrid.instances`에서 뺀다. 팝업 안에 만든 그리드를 팝업이 닫힐 때 치우는 용도(컨테이너 DOM은 호출부가 지운다). 예: `basicdata/customer/customer_entry.php`의 고객사 팝업. |
| `setMode(isEdit: boolean)` | edit ↔ view 전환. 저장 안 한 dirty 상태는 유지된다. |
| `selectRow(key: string\|number\|null)` | key에 해당하는 행을 "현재 행"으로 표시(파란 강조)하고 화면에 스크롤한다. **외부에서 호출 가능** — `AGGrid.instance('회원').selectRow('8')`. `null`이면 해제. |
| `addRow()` | 빈 신규 행을 상단 고정(pinnedTopRowData)으로 추가한다(edit 모드에서 씀). |
| `cancelEdits()` | dirty 행(기존 편집분 + 신규 초안) 전부를 서버로 안 보내고 되돌린다(확인창 1회, 취소할 게 없으면 상태 문구만). 내부적으로 `requery()`를 재사용해 서버 원본으로 되돌린다. |
| `save()` | 편집한(dirty) 기존 행 + 아직 저장 안 한 신규 행을 한 번에 fetchUrl(POST)로 저장한다. 수집 직전 `gridApi.stopEditing()`으로 진행 중인 편집을 먼저 커밋시킨다. |

## 여러 그리드 식별 / 부분 갱신

한 컨테이너에 그리드가 여러 개(예: `config_회원.php` + `config_매출.php`)
있어도, 각 인스턴스는 자기 `gridId`로만 `AGGrid.instances`에 등록되고
자기 `containerId`(기본 `'aggrid-' + gridId`) 안에서만 렌더링/이벤트가
일어난다. 그래서 `AGGrid.instance('매출').requery()`를 호출해도 `회원`
그리드는 전혀 움직이지 않는다 — 예: 회원 그리드의 `onRowDblClick`
콜백 안에서 관련된 매출 그리드만 새로고침하고 싶으면
`AGGrid.instance('매출').requery();` 한 줄이면 된다.

## 상호작용 요약

- **편집/읽기모드**: 기본은 **읽기전용**(요청에 따른 기본값)이고, 이때
  툴바 왼쪽엔 "편집" 버튼만 있다. "편집" 버튼으로 전환하면 그 버튼은
  "잠금"(누르면 다시 읽기전용 — 저장 안 한 편집이 있으면 "편집취소"도
  자동으로 함께 실행, 2026-10-20 추가)으로 바뀌고, 뒤에 "+ 행 추가"(저장 버튼과
  동일한 옅은 파랑, 2026-08-26 변경 — 원래 초록)/"편집취소"/"저장" 버튼
  (이 순서)과 행별 "삭제" 컬럼이 나타난다. 모드 전환 버튼은 항상 맨 앞이다
  (2026-10-20 변경 — 원래 "+ 행 추가"가 모드 무관하게 맨 앞에 항상 떠
  있었고 view 모드에서 누르면 자동으로 edit로 전환됐음, edit 모드의 전환
  버튼 문구는 "읽기모드"였음).
- **편집취소**: dirty 행(편집한 기존 행 + 아직 저장 안 한 신규 행 초안)
  전부를 서버로 안 보내고 원래 값으로 되돌린다. dirty 플래그만 지우는
  게 아니라 `requery()`(서버 원본 재조회)를 그대로 써서, 이미
  `node.data`에 반영돼 있는 편집값 자체도 확실하게 되돌린다 — 별도
  스냅샷/undo 로직을 관리할 필요가 없다. **버튼 자체가 dirty가 있을
  때만 보인다**(2026-08-26 추가 기본값 — `_updateSaveButtonState()`가
  저장 버튼 문구/깜빡임과 같은 시점에 `display:none`을 붙였다 뗐다
  함, dirty 0건이면 편집모드로 막 전환한 직후에도 곧바로 숨겨짐).
  실행되면 **확인창 없이 곧바로**(dirty가 있을 때만 눌릴 수 있으므로
  "취소할 게 없는" 경우 자체가 없음). 보이는 동안은 저장 버튼과 함께
  깜빡인다(2026-08-26 추가 — `ag-shell-cancel-btn-dirty`, 저장 버튼의
  `ag-shell-save-btn-dirty`와 같은 `ag-shell-blink` 애니메이션 재사용).
- **행 선택(색 반전)**: 아무 셀이나 클릭하면 그 행이 파란색(`#cfe3fb`)으로
  강조된다(모드 무관, 삭제 컬럼 클릭은 삭제로 처리되고 선택은 안 바뀜).
  `selectRow()`로 외부에서도 선택 가능.
- **체크박스 클릭 = 즉시 토글**(2026-08-26 추가): AG Grid 기본값대로면
  체크박스도 다른 셀처럼 더블클릭해야 편집(=토글)이 시작되는데, 체크박스는
  관례상 한 번 클릭으로 토글되는 게 자연스러워서 edit 모드에서 체크박스
  컬럼(`cellEditor: 'agCheckboxCellEditor'`)을 한 번 클릭하면 바로
  `startEditingCell()`을 호출해 즉시 토글시킨다 — 뒤이어
  `onCellValueChanged`가 그대로 호출돼 dirty 처리(저장/편집취소 버튼
  갱신 포함)까지 한 번에 이어진다. 새로 추가한 행(pinned top)의
  체크박스도 동일하게 동작한다.
- **검색**: 타이핑만으로는 안 걸러짐 — Enter 또는 "검색" 버튼을 눌러야
  `q`로 서버에 다시 물어본다(`paged`)/전체를 다시 받아온다(`all`). 검색창에
  포커스가 들어오면(이전 검색어가 있을 때만) 입력값과 `searchKeyword`를
  바로 비운다 — 아직 Enter/버튼을 안 눌렀으므로 서버 재조회는 일으키지
  않고, 빈 채로 바로 검색하면 전체 목록이 나오게 상태만 맞춰둔다. 검색창
  안쪽 오른편의 **"×"(지우기) 버튼**은 이와 달리 클릭 즉시 입력값을 비우고
  **바로 재조회**(빈 키워드로 검색 = 전체 목록)까지 실행한다 — 포커스
  초기화는 "아직 지우는 중"이고 ×는 "확정된 지우기 동작"이라는 차이.
- **행 추가/저장/삭제**: "+ 행 추가"는 편집모드에서만 보이는 버튼이다
  (2026-10-20 변경 — 원래는 모드 무관하게 보였고 view 모드에서 누르면
  자동으로 edit로 전환됐음). 화면 맨 위 고정행에만 초안을 만든다
  (서버 미접촉). "저장"은 dirty 행 + 고정행 초안을 한 번에 POST, 성공하면
  전부 `requery()`로 서버 최신 상태를 다시 받아온다(실패분이 있으면
  `requery()`를 안 해서 값이 그대로 남는다 — 고쳐서 재시도 가능). 행별
  "삭제"는 즉시 개별 POST하고 성공 시 `requery()`.
- **dirty 알림(저장 버튼 + No 컬럼)**: dirty 행(편집했지만 저장 안 함,
  신규 행 포함)이 하나라도 있으면 "저장" 버튼 문구가 "저장 (클릭하셔야
  저장됩니다.)"로 바뀌고 깜빡인다(`_updateSaveButtonState()`, dirty가
  0이면 원래 "저장"으로 복귀). 동시에 그 행의 "No"(순번) 컬럼도 숫자 대신
  연필(✏️)을 보여준다(`_buildColumnDefs()`의 `noColumn.valueGetter`) —
  둘 다 별도 옵션 없이 항상 켜져 있는 기본 동작.
- **상태 메시지 위치**: "저장 중...", "저장 완료", "저장할 변경 사항이
  없습니다", "삭제 중...", "삭제 완료" 같은 `_setStatus()` 문구는 edit
  모드에서는 **저장 버튼 바로 옆**(왼쪽 그룹)에 표시된다. view 모드(저장
  버튼이 없는 상태)에서는 "이동" 도구의 결과 메시지 등을 위해 오른쪽
  그룹(검색창 옆, 원래 위치)에 표시된다 — `_renderToolbar()`가 모드에
  따라 `.ag-shell-status` 위치를 다시 그린다. "저장할 게 없다"는 경우는
  `window.alert()` 대신 이 상태 메시지로만 알린다(팝업 없음) — 저장/삭제
  성공·실패의 나머지 결과도 같은 채널을 쓰되, 여러 행의 실패 상세는
  여전히 `window.alert()`로 한 번에 보여준다(줄바꿈 포함 긴 메시지라
  상태 문구 한 줄로는 부적합해서).
- **행 선택 강조와 더블클릭 편집의 충돌 방지**: 셀 클릭 시 활성 행 강조는
  즉시 반영된다 — `redrawRows({rowNodes: [...]})`로 **딱 두 행(이전
  활성 행 + 새로 클릭한 행)만** 범위를 좁혀 다시 그린다. 원래 버그(매
  클릭마다 그리드 *전체*를 `redrawRows()`하던 것)가 더블클릭 두 번째
  클릭이 그 사이 다시 그려진 셀에 도달하지 못해 편집이 깨지는 원인이었는데,
  범위를 이 두 행으로 좁히는 것만으로 해결됐다(300ms 지연이나 DOM을 직접
  건드리는 시도는 각각 "느리다"/"강조가 아예 안 뜬다"는 회귀를 내서
  폐기 — 지금은 표준 API인 `redrawRows({rowNodes})` 하나로 단순화됨).
- **저장 전 진행 중인 편집을 먼저 커밋**: `save()`는 dirty 행을 모으기
  직전에 `gridApi.stopEditing()`(취소 아님, 확정)을 호출한다 — AG Grid는
  셀 편집이 "끝나야"(포커스 이동/Enter 등) 실제 데이터에 반영되고
  `onCellValueChanged`가 발생하므로, 타이핑 직후 바로 "저장"을 누르면
  아직 편집기가 열려있어 dirty로 안 잡히던(그래서 "저장할 변경 사항이
  없습니다"가 나오던) 문제를 해결한다.
- **정렬/필터**: `paged`는 AG Grid 헤더 클릭이 서버 재조회를 일으킨다(정렬
  1개만 지원 — `sortModel[0]`). 열별 필터(`filter: true`)는 `paged`에서는
  **서버에 안 보내진다**(효과 없음) — 열별 필터가 필요하면
  `fetchMode:'all'`을 쓸 것(AG Grid가 브라우저 안에서 처리).
- **조건부 서식**: `conditionalFormats`(그리드 레벨, 행 전체에 적용) —
  처음 매칭되는 규칙 하나만 적용된다. 우선순위(높은 게 낮은 걸 덮어씀):
  **dirty(편집 중, 호박색) > 선택된 행(파랑) > conditionalFormats**.

## CSS — 이 클래스가 툴바 모양을 스스로 소유한다

`AGGrid`는 `_injectDefaultStyles()`로 `.ag-shell-*`(툴바) CSS 전체를
`<style id="aggrid-shell-styles">`로 문서에 직접 주입한다(그리드 자체의
모양은 `vendor/ag-theme-alpine.css`가 담당, 이 모듈은 관여 안
함). 툴바는 별도 박스(테두리/배경/그림자)로 감싸지 않고 그리드 바로 위에
버튼만 붙는 모양이다(margin-bottom만으로 간격 확보) — "버튼이 그리드 위
공간을 많이 차지한다"/"테두리 없이 그리드 바로 위에"라는 피드백을 반영한
의도적 선택. 버튼 색상은 무채색(회색 계열) 위주로 통일하되 "저장"과
"+ 행 추가"는 둘 다 옅은 파란색 배경(`#bfdbfe`) + 짙은 남색에 가까운
글자(`#1e293b`)로 같은 톤을 쓰고(2026-08-26 변경 — "+ 행 추가"는
원래 초록(`#22c55e`)이었으나 "저장 버튼처럼 파란색으로" 지시로 통일),
행별 "삭제"와 "편집취소"는 둘 다 1_8(`public/test/Board/1_8/aggrid.html`)
의 `.delete-btn`과 똑같이 산호빛 빨강(`#e0736c`, 흰 글자,
`border-radius:6px`, 호버 `#c85950`)으로 항상 눈에 띄게 한다
(2026-08-26 변경 — "편집취소"는 원래 무채색 보조버튼이었으나 "빨간색으로"
지시로 삭제와 같은 빨강 계열로 통일, "값이 사라지는 되돌릴 수 없는
방향"이라는 공통점을 색으로도 표시). "+ 행 추가"는 편집모드의 주된
동작이라 여전히 강조색을 유지한다(2026-08-25 추가 지시 근거는
그대로), "이동"
입력창은 연한 회색(`#d1d5db`) 박스 테두리를 갖고 포커스 시 진한 회색
테두리+box-shadow로 강조되는 반면, **검색창은 박스 테두리 대신
밑줄(언더바)만** 준다(`border-bottom` 1px, 포커스 시 더 진한 회색)—
검색 전용으로 구분되는 모양. placeholder("검색어") 글자 크기는 "검색"
버튼 글자 크기와 같다(`0.82rem`, `::placeholder`로 명시). 검색창은
`.ag-shell-search-wrap`(position:relative)으로 감싸 안쪽 오른편에 "×"
지우기 버튼을 절대위치로 겹쳐 배치한다(입력창 자체의 오른쪽 padding으로
자리를 미리 비워둠). 그리드 셀 사이에는
옅은 회색(`#e5e7eb`) 세로 구분선을 명시적으로 그린다(`ag-theme-alpine`
기본값은 세로 경계가 잘 안 보여서). 제목행/순번(No)열은 1_8과 동일하게
옅은 회색(`#fafafa`)으로 그리드의 "틀"처럼 구분되게 하고, 글꼴도 1_8과
동일한 `'Segoe UI', Inter, system-ui, -apple-system, sans-serif`를
기본값으로 쓴다(툴바는 CSS `font-family`, 그리드는 AG Grid의
`--ag-font-family` 커스텀 프로퍼티로 각각 적용).

특정 화면만 툴바 모양을 바꾸고 싶으면, `ScrollGrid`와 동일한 원리로 그
화면의 `<style>`에 같은 선택자를 레이어 없이 다시 적으면 자동으로 이긴다
(`@layer aggrid-defaults` 안에 있어 CSS Cascade Layers 스펙상 항상 짐):

```html
<style>
  .ag-shell-mode-btn { background: #16324f; }
</style>
```

## 알아둘 제약 / 함정

- **그리드 DOM(`.ag-cell`/`.ag-header` 등) 안쪽 요소의 CSS는 `!important`가
  필요할 수 있다.** `_injectDefaultStyles()`가 주입하는 `.ag-shell-*` CSS는
  `@layer aggrid-defaults` 안에 있는데, CSS Cascade Layers 스펙상 **레이어
  없는 규칙은 명시도와 무관하게 레이어 있는 규칙을 항상 이긴다.**
  `vendor/ag-grid.css`/`ag-theme-alpine.css`(AG Grid 자신의 CSS)는
  레이어가 없으므로, 그리드 셀 안에서 렌더링되는 우리 요소(예: 행별
  "삭제" 버튼의 글자색)는 AG Grid 쪽 CSS에 덮여씌워질 수 있다 — 실제로
  삭제 버튼 흰 글자가 안 먹히던 버그의 원인이었다(`color: #fff
  !important`로 수정). `!important`는 이 레이어 우선순위를 뒤집어 레이어
  안 선언이 이기게 한다. 반대로 **툴바(`.ag-shell-toolbar` 하위)는 그리드
  DOM 바깥**이라 이 문제에서 자유롭다 — 지금까지 대부분의 `.ag-shell-*`
  규칙이 `!important` 없이도 잘 먹혔던 이유.
- **`vendor/` 경로는 `document.currentScript`로 자기 위치를 스스로
  찾는다.** 컨테이너 페이지가 `AGGrid.js`를 **평범한 동기 `<script
  src="...">` 태그**(`async`/`defer`/`type="module"` 없이)로 불러와야
  한다 — `document.currentScript`는 그런 태그가 파싱되는 동안에만 정확한
  값을 주기 때문(모듈 스크립트나 async/defer는 실행 시점이 달라 이 값이
  깨질 수 있음). 지금 `content1_9.php`가 쓰는 방식이 정확히 이 형태다.
- **`applyTransaction()`을 안 쓴다.** AG Grid의 Infinite Row Model
  (`fetchMode:'paged'`)은 `applyTransaction()`을 지원하지 않는다(Client-Side/
  Server-Side Row Model 전용) — 그래서 "행 추가"는 두 모드 모두에서
  동작하는 `pinnedTopRowData`(상단 고정 행)로 구현했다.
- **`paged` 모드의 열별 필터는 서버에 전달되지 않는다.** 전역 검색(`q`)만
  서버 왕복을 일으킨다. 열별 필터를 실제로 걸고 싶은 화면은
  `fetchMode:'all'`을 쓸 것 — 서버 쪽 구현 부담(필터 파싱)을 늘리는 대신
  AG Grid의 기존 클라이언트 필터를 그대로 활용하는 절충.
- **`paged` 모드의 dirty 추적(node.id)은 캐시가 살아있는 동안만 정확하다.**
  Infinite Row Model은 `maxBlocksInCache`를 넘으면 오래된 블록을 버리고
  다시 조회할 수 있다 — 스크롤을 아주 많이 오간 뒤라면 그사이 편집한 값이
  화면엔 남아있어도 다음 로드에서 노드가 재생성돼 dirty 추적이 끊길
  가능성이 있다(문서화된 한계, 이 버전에서는 해결하지 않음).
- **`selectRow()`/"키값으로 이동"은 현재 로드된(캐시에 있는) 행만
  찾는다.** `paged` 모드에서 아직 안 불러온 페이지의 행은 못 찾는다
  (`all` 모드는 전체가 항상 로드돼 있어 이 제약이 없다).
- **더블클릭 이벤트는 view 모드에서만 호출된다.** edit 모드의 더블클릭은
  AG Grid 자신의 "셀 편집 시작" 제스처와 겹치므로 `onRowDblClick`을 일부러
  안 부른다.

## 파일 구성

- `src/js/aggrid/AGGrid.js` — 이 클래스 본체.
- `src/js/aggrid/aggrid.md` — 이 문서.
- `src/js/aggrid/vendor/{ag-grid-community.min.js, ag-grid.css,
  ag-theme-alpine.css}` — AG Grid 라이브러리 본체(로컬 저장, CDN 미사용).
  jsdelivr(`cdn.jsdelivr.net/npm/ag-grid-community`)에서 받아온 파일
  그대로다. **새 버전으로 갱신하려면** 이 세 파일을 같은 이름으로 다시
  받아 덮어쓰면 된다(자동 갱신 없음 — CDN을 안 쓰기로 한 트레이드오프,
  인터넷이 안 되는 NAS/사내망에서도 그리드가 동작하는 대신 버전은 수동
  관리).
- `public/test/Board/1_9/{config_회원.php, fetch_회원.php}` — 실제 사용
  예시(프론트엔드 config + 서버 CRUD 엔드포인트).
- `public/test/Layout/test1/content1_9.php` — 컨테이너 페이지 예시.
- `basicdata/login/{login_entry.php, login_fetch.php}` — **기초자료형**
  표준 예시(위 "기초자료형" 참고).
