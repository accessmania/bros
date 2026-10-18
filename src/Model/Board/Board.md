# Board (`src/Model/Board/Board.php`)

MSSQL/Access→PHP 전환 프로젝트의 게시판(목록+페이저+검색+정렬) 클래스.
스키마 추출은 `Crud`를 내부에서 그대로 재사용한다.

이 문서는 시간순 일지가 아니라 **지금 시점의 스펙 요약**이다(작업 이력은
`md/Board_ai.md`, 최초 지시 원문은 `md/Board.md` 참고 — 이 문서는 그 두 곳에
흩어져 있던 참고서 성격의 내용을 코드 옆으로 모은 것). API가 바뀌면 이
문서도 함께 갱신한다.

## 진입점 3가지 (모드)

| | `init()` | `initFromQuery()` | `initFromArray()` |
|---|---|---|---|
| 대상 | 실제 테이블/뷰 이름 | 임의 SELECT 문 하나 | 이미 PHP 배열로 있는 결과셋 |
| 총건수 계산 | `SELECT COUNT(*) FROM 테이블` | `SELECT COUNT(*) FROM (쿼리) AS sub` | `count($rows)`(PHP) |
| 페이지 조회 | `SELECT * ... LIMIT/OFFSET` | `SELECT * FROM (쿼리) AS sub ... LIMIT/OFFSET` | `array_slice()`(PHP) |
| 검색(LIKE) | SQL `LIKE` | SQL `LIKE` | `mb_stripos()`(PHP) |
| 정렬 | SQL `ORDER BY` | SQL `ORDER BY` | `usort()`(PHP, 단일 컬럼만) |
| 키 컬럼 | `Crud::getPrimaryKey()`(DB가 앎) | 호출부가 지정(`$pKeyColumn`, 기본 `'code'`) | 호출부가 지정(`$pKeyColumn`, 기본 `'code'`) |
| 컬럼 목록 | `Crud::getSchema()` | `probeQueryColumns()`(0건 프로브 쿼리) | `array_keys($rows[0])` |
| 쓰기(저장/삭제) | 지원(`Crud` 경유) | **미지원** | **미지원** |
| `getDefaultBodyHTML()` 등 스키마 의존 렌더링 | 지원 | **미지원**(`$this->crud` 미초기화) | **미지원**(동일 이유) |
| 대표 실사용처 | 1_1~1_3, 2_1 등 대부분 | 4_2("AGGrid쿼리"), 6_1(JOIN 쿼리) | (연계 대상: `SP::execute()` 결과) |

세 모드 모두 `calcBoard()`(총건수/페이지 계산) → `loadBoard()`(실제 조회/
슬라이싱)를 내부에서 순서대로 호출하고, 그 이후의 커서 탐색·URL 헬퍼는
모드와 무관하게 동일하게 쓸 수 있다 — 다만 위 표의 "스키마 의존 렌더링"과
"쓰기"는 테이블 모드 전용이다.

### `init($pTableOrView, $pColumns, $pSearchFields, $pKeyword, $pOrderBy, $pPage, $pPageSize, $pPagerGroupSize, $pExtraCondition, $pDefaultOrderBy)`

가장 기본적인 진입점. `$pColumns`는 `"code:번호,이름,나이"`처럼 콤마로 구분한
"컬럼명" 또는 "컬럼명:라벨" 목록(화면 표시용). `$pSearchFields`/`$pKeyword`가
검색 조건을, `$pOrderBy`(명시적 raw SQL, 있으면 헤더 클릭 정렬 비활성화) 또는
`$pDefaultOrderBy`(기본값일 뿐 헤더 클릭 정렬은 계속 켜져 있음)가 정렬을
결정한다. `$pExtraCondition`은 검색어와 별개로 항상 AND로 붙는 raw SQL
조건(체크박스/라디오 필터 등).

### `initFromQuery($pRawQuery, ...)`

임의 SELECT 문을 `(원본) AS sub` 서브쿼리로 감싸 페이징/검색/정렬을 SQL에서
그대로 처리한다. **MSSQL은 `EXEC`(저장 프로시저 호출)를 서브쿼리 안에 넣을
수 없다** — 그래서 프로시저 결과에는 이 진입점을 쓸 수 없다(`initFromArray()`
를 쓴다). `$pKeyColumn`을 생략하면 `'code'`로 간주.

### `initFromArray($pRows, $pSearchFields, $pKeyword, $pOrderBy, $pPage, $pPageSize, $pPagerGroupSize, $pExtraFilter, $pDefaultOrderBy, $pKeyColumn)` [2026-09-07 추가]

이미 PHP 배열로 갖고 있는 결과셋(대표적으로 `SP::execute()`의 반환값 — 저장
프로시저 호출 결과)을 대상으로 한다. `initFromQuery()`와의 결정적 차이 —
**SQL을 전혀 쓰지 않는다**:

- 총건수는 `SELECT COUNT(*)`를 다시 묻지 않고 `count($rows)`로 안다 — 이미
  전량이 PHP 메모리에 있으므로 DB에 재질의할 이유가 없다는 것이 이 진입점의
  핵심 설계 동기(`src/Model/SP/sp.md` 참고).
- 검색은 SQL `LIKE` 대신 `mb_stripos()` 기반 PHP 부분일치.
- 정렬은 SQL `ORDER BY` 대신 `usort()` — `$pOrderBy`/`$pDefaultOrderBy`는
  내부적으로 `resolveOrderBy()`를 그대로 재사용해 `$sortField`/`$sortDir`을
  계산하지만(헤더 클릭 정렬과 완전히 동일하게 동작), 그 메서드가 만드는 SQL
  문자열 자체는 버린다. **다중 컬럼 raw SQL 정렬은 지원하지 않는다** —
  `$pDefaultOrderBy`에 `"세트메뉴 DESC, 상품명 ASC"`처럼 여러 컬럼을 섞으면
  `$sortField`가 `null`로 남아(`resolveOrderBy()`의 기존 규칙) 정렬 자체를
  건너뛴다.
- `$pExtraFilter`는 `init()`의 `$pExtraCondition`(raw SQL)에 대응하지만 여기선
  **PHP 콜러블** `function (array $row): bool`을 받는다.
- 컬럼 목록은 `probeQueryColumns()`(0건 프로브 쿼리) 없이
  `array_keys($rows[0] ?? [])`로 즉시 구한다 — 오히려 query 모드보다 단순하다.
- `getWhereString()`이 반환하는 문자열은 **실행되는 SQL이 아니다** —
  `'(array mode) ' . 검색어` 형태의 참고용 표기일 뿐이다.

**트레이드오프**: 페이지를 넘길 때마다 SQL이 필요한 구간만 다시 조회하는 게
아니라, 이미 받은 배열 전체를 매번(검색/정렬은 `calcBoard()` 시점에 한 번,
페이지 슬라이싱은 `loadBoard()`에서) PHP로 처리한다. 저장 프로시저 결과가
수백 건 이하 규모(하루치 매출/직원 수 등)면 무시할 만하지만, 규모가 커지면
재검토가 필요하다. 또한 페이지를 다른 요청(다음 HTTP 요청)에서 넘기려면
`SP::execute()`를 다시 호출해 배열을 다시 받아야 한다 — `Board` 인스턴스
자체는 요청 하나짜리 수명이라는 점은 다른 모드와 동일하다.

## `calcBoard()` / `loadBoard()` 내부 흐름

`init*()` 계열이 마지막에 이 둘을 순서대로 호출한다.

1. `calcBoard()`(private) — 총건수 → 총페이지 → 현재 페이지 clamp → 페이저
   그룹(시작/끝 페이지) → offset을 계산해 인스턴스 필드에 저장한다. array
   모드는 이 시점에 검색+정렬까지 끝내 `$arrayFilteredRows`에 캐시해둔다
   (`loadBoard()`가 페이지 슬라이싱만 하면 되도록, 검색/정렬을 두 번 계산하지
   않기 위함).
2. `loadBoard()`(public) — `calcBoard()`가 계산한 값을 근거로 실제 이 페이지의
   레코드를 가져와 `$data = {records, pager}`에 담고, 레코드 커서/페이지 커서를
   초기화한다.

## 커서 탐색 (레코드)

`getData(?$index)`/`moveFirst()`/`moveNext()`/`movePrevious()`/`moveLast()`/
`bof()`/`eof()` — 고전적인 ADO/DAO Recordset 스타일로 `$data['records']`
(현재 페이지 레코드)를 옮겨다닌다. `getRows()`는 이 커서를 내부에서 처음부터
끝까지 돌려 배열 하나로 모아 반환한다(호출 후 커서는 EOF에 남음).

## 커서 탐색 (페이저)

`getPager(?$index)`/`moveFirstPage()`/`moveNextPage()`/`movePreviousPage()`/
`moveLastPage()`/`bofPage()`/`eofPage()` — 위와 동일한 방식으로
`$data['pager']['pages']`(현재 페이저 그룹의 페이지 번호 목록)를 옮겨다닌다.
`getDefaultPagerHTML()`이 이 커서로 페이지 번호 링크를 그린다.

## URL 헬퍼

- `getSortUrl($field, $dir)` — 현재 `$_GET`을 유지한 채 sort/dir/page(1로
  리셋)만 바꾼 쿼리스트링.
- `getPageUrl($page)` — 현재 `$_GET`을 유지한 채 page만 바꾼 쿼리스트링.
- `Board::makeUrl($source, $baseUrl, $whitelist, $paramPrefix)`(static) —
  예전 `getReturnQuery()`/`buildReturnUrl()`을 통합한 것. `$source`가
  `null`이면 `$_GET`을, 문자열이면 그 쿼리스트링을 화이트리스트
  (`DEFAULT_QUERY_WHITELIST = ['q','page','pageSize','groupSize','sort','dir']`)
  로 걸러 `$baseUrl`에 붙이거나(주어졌으면) 쿼리스트링만 반환한다.
- `Board::appendQuery($baseUrl, $params)`(static) — `$baseUrl`에 이미
  `?...`가 있으면 `&`로, 없으면 `?`로 파라미터를 이어붙인다(Layout 메뉴에
  붙은 게시판처럼 `listUrl` 자체가 쿼리스트링을 포함하는 경우 대비).

## 정렬 상태 조회

`getSortField()`/`getSortDir()`/`isSortable()` [2026-09-07 추가] — 헤더 클릭
정렬의 현재 상태를 그대로 노출한다. `getDefaultBodyHTML()`/
`getDefaultScrollGridHTML()`이 헤더 화살표를 그릴 때 내부적으로 쓰는 값과
동일 — array/query 모드처럼 그 메서드들을 못 쓰는 화면이 직접 `<table>`을
그리면서 헤더 화살표를 재현하려면 이 getter로 값을 읽어야 한다
(`src/Model/SP/SP_Board_entry.php` 참고).

## 렌더링 (테이블 모드 전용 — `$this->crud` 스키마에 의존)

- `getDefaultInfoHTML()` — "전체 N건 / 전체 N페이지 중 N페이지 ..." 요약 문구.
- `getDefaultBodyHTML(?$rowLinkUrl)` — `<table>` 통째로. 헤더 클릭 정렬 링크,
  `$rowLinkUrl`(`"view.php?code={pk}"`처럼 `{pk}` 치환)로 행 클릭 이동 지원.
- `getDefaultPagerHTML()` — 처음/이전/(페이지 번호들)/다음/마지막 페이저.
- `getDefaultScrollGridHTML(?$rowLinkUrl, $rowNumberLabel, $autoFitBottomGap)`
  — **SG(스크롤그리드) 패턴**(아래 참고)의 마크업 통째로.
- `getScrollGridData()` — `ScrollGrid.js`(1_7)가 JSON으로 받아 직접 그리는
  배열 형태(`{primaryKey, columns, rows}`, PK 컬럼은 화면 노출 목록에서 제외).
- `toJson()`/`static fromJson($db, $json)` — 스키마+컬럼/라벨+조건/정렬+
  커서 위치+data 전체를 JSON 문자열로 직렬화/복원(`Crud::fromJson()`을 내부
  재사용, DB 접속 없이 복원 가능).

query/array 모드는 `$this->crud`를 초기화하지 않으므로 위 메서드들을 호출하면
깨진다 — 이 두 모드의 실사용처(4_2, 6_1)는 전부 `getRows()`/`toJson()`이
아니라 AGGrid의 `fetchUrl` JSON 계약(`{list_paged: rows+pager}`류)으로
직접 응답을 짜는 방식을 쓴다.

## SVW 패턴 (2026-08-11 명명, `md/Board.md`에서 이관)

`public/test/Board/second/`처럼 **search.php(목록+검색) / view.php(조회+편집)
/ write.php(저장·삭제 처리 전용, 화면 없음)** 3개 파일로 구성되는 게시판
유형을 **"SVW 패턴"**이라 부른다(Search-View-Write). `Board`/`Crud`의 저수준
메서드만 쓰고 마크업은 개발자가 직접 짜는 구조.

핵심은 **`return` 파라미터 릴레이**로 목록 상태(검색어/페이지/정렬)를
유지하는 것 — search → view → write → (성공 시 search / 실패 시 view)로
순환하며 `Board::makeUrl()`이 압축한 `return` 문자열을 계속 실어 나른다.
정렬/페이징 자체 링크(`getSortUrl()`/`getPageUrl()`)는 이 압축 경로를 안
쓰고 `$_GET`을 그대로 복사해서 만든다는 점이 다르다.

## SG(스크롤그리드) 패턴 (2026-08-15 명명, `md/Board.md`에서 이관)

`public/test/Board/1_6/`처럼 **페이저 대신 스크롤로 목록을 넘기는** 목록
화면 유형. 계기는 Access "연속 폼" 스타일(페이지 번호 없이 스크롤바로 넘기는
데이터시트 화면) 재현 요청.

핵심 특징 3가지: (1) **페이저 없음** — `page=1`, `pageSize`를 아주 크게
고정해서 페이지 나누기 자체를 안 함. (2) **스크롤 컨테이너 + 고정 헤더** —
`.sg-wrap`(`overflow-y: auto`)로 `<table class="sg-table">`를 감싸고
`<thead th>`에 `position: sticky; top: 0;`. (3) **행번호 + 행 호버** — DB
값이 아니라 표시 순서 기준 순번 열(`.sg-rownum`)을 맨 앞에 두고, 행 전체가
클릭 가능함을 호버로 알림.

`getDefaultScrollGridHTML()`이 이 마크업 자체를 만들어주지만 **CSS는 안
담는다**(스크롤 높이/호버 색은 화면마다 다를 수 있어서) — 화면단이
`.sg-wrap`/`.sg-table`/`.sg-rownum` 클래스에 맞춰 `<style>`을 직접 둬야 한다.
"진짜 인라인 편집 + 일괄저장"(Access 데이터시트처럼 셀 직접 수정)은 SG
패턴에 포함되지 않는다 — `getScrollGridData()` + `ScrollGrid.js` +
`ActionBulk`가 그 역할이다.

## SVW 패턴 상세 흐름 (`public/test/Board/second/`, `md/Board.md`에서 이관)

### search.php (목록+검색)

1. 부트스트랩 + `Database`/`Board`/`Str` 로드.
2. `h()` 이스케이프 헬퍼 + 이 게시판 전용 상수(`TABLE_NAME`/`COLUMNS_STRING`/
   `SEARCH_FIELDS`).
3. `$searchKeyword`를 GET(`q`)에서.
4. `$board->init(TABLE_NAME, COLUMNS_STRING, SEARCH_FIELDS, $searchKeyword)`
   (`try/catch`, 실패 시 `$error`만 저장하고 목록/페이저 렌더링을 통째로
   건너뜀).
5. `$returnQuery` 조립 — `$_GET` 전체가 아니라 `q`/`page`/`pageSize`/
   `groupSize`/`sort`/`dir` **6개로 한정된 화이트리스트**만 값이 있을 때
   골라 압축(`http_build_query()`).
6. 목록 렌더링 + view.php 링크 — 각 행마다 `code`(PK) + `return`(5번 압축
   문자열) **딱 2개**로 `view.php?code=..&return=..` 생성.

보충: 정렬/페이징 자체 링크(`getSortUrl()`/`getPageUrl()`)는 `$returnQuery`를
안 쓰고 `$_GET`을 그대로 복사(6단계의 "6개 화이트리스트 압축"과는 별개
경로). 검색폼은 `q`는 `<input>` 값, `pageSize`/`groupSize`만 `hidden`으로
유지, 검색 제출 시 `sort`/`dir`은 버려짐(정렬 리셋).

### view.php (조회+편집)

1. 부트스트랩 + `session_start()`(플래시용) + `Database`/`Crud`/`Board`/`Str`.
2. 이 화면 전용 상수(`TABLE_NAME`/`FIELDS`/`PRIMARY_KEY`/`LIST_URL`/
   `WRITE_URL`/`FLASH_ERROR_KEY`/`FLASH_OLD_KEY` — 뒤 두 개는 write.php와
   반드시 값이 같아야 하는 결합).
3. `$returnRaw`를 GET(`return`)에서 — 내용을 해석하지 않고 다음 단계로 다시
   실어 보내기만 함.
4. 플래시 메시지 꺼내기(write.php가 저장/삭제 실패 시 세션에 남긴 에러+
   입력값, 1회성으로 읽고 즉시 `unset()` — PRG 패턴).
5. 레코드 조회 — `$flashError === null && $code !== ''`일 때만
   `Crud::selectRecord()`(=write.php에서 실패로 돌아온 경우엔 DB를 다시 안
   읽음). `$flashOld`가 있으면 그 값으로 화면을 복원.
6. 폼 렌더링 + write.php 전달 — `code`는 각 `<input>` 값 자체, `return`은
   `hidden` 필드. 저장/삭제/신규 3버튼이 같은 폼을 공유, `Board::makeUrl()`
   (예전 `buildReturnUrl()`)로 "게시판으로 돌아가기" 링크 조립.

### write.php (저장/삭제 처리 전용, 화면 없음)

1. 부트스트랩 + `session_start()` + `Database`/`Crud`/`Board`(`Str` 없음 —
   출력이 없어 이스케이프 헬퍼 불필요).
2. 상수(`TABLE_NAME`/`PRIMARY_KEY`/`LIST_URL`/`VIEW_URL`/플래시 키 2개).
3. POST에서 `action`(기본 `'save'`)/`return`/`code` 읽기.
4. "신규"(`action === 'new'`) 먼저 처리 — DB 안 건드리고 `return`만 유지한
   채 곧장 view.php로(빈 폼).
5. 저장/삭제 — `action === 'delete'`면 `deleteRecord($code)`, 아니면
   `loadPost()` + `save()`(PK 유무로 insert/update 자동 분기).
6. 성공 시 `Board::makeUrl($returnRaw, LIST_URL)`로 search.php(목록 상태
   복원)로.
7. 실패 시(`catch`) — 세션에 에러+`$_POST` 전체 저장 후
   `view.php?code=..&return=..`로.

보충: 삭제도 `loadPost()`/`save()` 안 타고 `deleteRecord($code)` 직행(`code`
비면 예외 → `catch`로 자연스럽게 흘러감). `return` 릴레이가 여기서 두
갈래 — **성공** → `Board::makeUrl()`로 화이트리스트 재조립해 search.php로,
**실패** → `$returnRaw`를 가공 없이 그대로 다시 view.php로.

## 알아둘 제약/함정

- **MSSQL `OFFSET/FETCH`는 `ORDER BY`가 필수**다(`SqlDialectMssql::limitClause()`)
  — PK가 없는 테이블에 기본 정렬(PK DESC)조차 못 채우면 페이저가 깨진다
  (`md/sql-dialect-mysql-mssql.md` "MSSQL test DB의 t회원 조회 시 OFFSET/FETCH
  오류" 참고, 실제로 PK 미설정이 원인이었다).
- **`sanitizeUtf8()`** — `pdo_dblib`(FreeTDS)가 비UTF8 콜레이션(CP949 계열)
  컬럼을 유효하지 않은 UTF-8로 돌려줄 수 있어, `loadBoard()`의 테이블/query
  모드 조회 결과에 자동으로 적용된다. array 모드는 이미 `SP::execute()`가
  같은 보정을 자체적으로 하고 넘어온 값이라는 전제라 다시 적용하지 않는다.
- **query/array 모드는 쓰기와 스키마 의존 렌더링을 지원하지 않는다** — 위
  표 참고, 화면이 이 두 기능이 필요하면 애초에 `init()`(테이블 모드)을 써야
  한다.
- **array 모드의 정렬은 단일 컬럼만** — 위 `initFromArray()` 절 참고.

## 함께 쓰이는 클래스 (이 문서의 범위 밖)

`Board`/`Crud`를 감싸는 상위 계층(목록/조회 화면 라우팅, 저장/삭제 처리)은
별도 클래스로 분리돼 있고, 그 설계 근거는 각 클래스 자신의 소스 docblock에
있다 — 이 문서(Board 클래스 자체의 참고서)에는 중복해서 옮기지 않는다:

- `BoardConfig`(`src/Model/Board/BoardConfig.php`) — 화면별 설정(테이블명/
  컬럼/URL/파라미터 접두어 등)을 담는 값 객체.
- `View`/`ViewBoard`/`ViewCrud`/`ViewEmpty`(`src/View/`) — 목록/조회 화면
  렌더링 상속 구조.
- `Action`/`ActionCrud`/`ActionBulk`/`ActionResponder`/
  `RedirectActionResponder`/`JsonActionResponder`(`src/Action/`) — 저장/
  삭제 처리 + 응답 방식(리다이렉트/JSON) 분리.

## 관련 파일

- `src/Model/Board/Board.php` — 클래스 본체.
- `src/Model/Board/Board_entry.php` — 폴더 이동 후에도 의존성을 정상적으로
  찾는지 확인하는 스모크 테스트(t회원 기준).
- `src/Model/Crud/Crud.php`(`src/Model/Crud/Crud.md` 참고) — 테이블 모드가
  스키마 추출/쓰기를 위임하는 대상.
- `src/Model/SqlDialect/*` — 테이블/컬럼 인용, LIMIT/OFFSET, 초성검색 조건을
  여기서 빌려 씀.
- `src/Model/SP/SP.php`(`src/Model/SP/sp.md` 참고) — `initFromArray()`와
  연계하는 저장 프로시저 호출 클래스. `src/Model/SP/SP_Board_entry.php`가
  이 연계의 실전 조합 예제(5_7 "SP+Board 연습").
- `md/Board.md` — 최초 지시 원문(스펙 확정 시점의 기록).
- `md/Board_ai.md` — 시간순 작업 일지(모든 세부 변경 이력).
