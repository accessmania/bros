# SP (`src/Model/SP/SP.php`)

저장 프로시저(Stored Procedure) 호출 전용 정적 유틸 클래스. `Board`/`Crud`처럼
스키마를 인스턴스에 들고 있는 구조가 아니라, "프로시저 이름 + 파라미터를
주면 결과셋 하나를 배열로 돌려준다"는 한 가지 일만 한다 — `Domain` 클래스와
같은 급의 상태 없는 정적 유틸리티다.

이 문서는 시간순 일지가 아니라 **지금 시점의 스펙 요약**이다. API가 바뀌면
이 문서도 함께 갱신한다.

## 왜 별도 클래스인가 (설계 배경)

- `Crud`는 테이블 하나의 스키마+CRUD를 전담하는데, 저장 프로시저는 성격이
  완전히 다르다 — 스키마도 모르고, PK도 없고, 쓰기 개념도 없다(그냥 실행하고
  결과를 받을 뿐). `Crud`에 억지로 붙이지 않고 분리했다.
- **SqlDialect에 4번째 메서드로 `callStatement()`를 추가**했다 — MySQL은
  `CALL name(?, ?)`, MSSQL은 `EXEC name ?, ?`로 호출 문법 자체가 달라서,
  `quoteIdentifier()`/`limitClause()`/`chosungCondition()`과 같은 이유로
  방언 인터페이스에 감쌌다.
- **다중 결과셋(`PDOStatement::nextRowset()`)은 의도적으로 지원하지 않는다.**
  이 프로젝트가 MSSQL 접속에 쓰는 `pdo_dblib`(FreeTDS)는 다중 result set
  순회가 불안정하다고 알려져 있다 — 이미 이 프로젝트가 `OFFSET/FETCH` 같은
  사소한 문법 차이에서도 한 번 걸린 전례(md/sql-dialect-mysql-mssql.md
  "MSSQL test DB의 t회원 조회 시 OFFSET/FETCH 오류")가 있어서, 검증되지
  않은 기능에 또 기대는 대신 **"프로시저 1개 호출 = 결과셋 1개"만 이
  클래스가 책임**지도록 좁혔다. 여러 프로시저를 조합해야 하면(예: 한 화면이
  4개 SP를 조합) `SP::execute()`를 여러 번 호출해 화면단(엔드포인트)이
  모은다.

## API

```php
public static function execute(string $procName, array $params = [], ?DbContext $db = null): array
```

- `$procName`(필수) — 프로시저 이름. **raw 문자열로 그대로 조립**된다(인용
  처리 없음, 스키마 접두어 `dbo.procName` 포함 가능) — `Domain::$expr`/
  `$domain`, `Crud`/`Board`의 조건 문자열과 동일한 관례로, **신뢰된 서버
  코드에서만 조립**하고 사용자 입력을 직접 연결하지 않는다.
- `$params`(선택, 기본 빈 배열) — 프로시저가 받는 순서 그대로의 **위치 지정**
  파라미터 배열(예: `[$date]`). named placeholder가 아니라 positional
  placeholder(`?`)를 쓴다 — `Crud::insert()`/`update()`가 한글 필드명 때문에
  positional을 쓰는 것과 같은 이유(PDO는 파라미터 이름에 비ASCII 문자를
  지원 안 함)이자, 애초에 프로시저 파라미터의 진짜 이름을 이 클래스가 알
  방법이 없다.
- `$db`(선택, DbContext) — 생략하면 `new DbContext()`(config/db.php의 `driver`
  기본값, 이 프로젝트는 현재 `'mssql'`). 특정 접속을 쓰려면
  페이지 상단에서 만든 `new DbContext('mssql')`를 넘긴다(src/db/DbContext.md).
- 반환값 — `fetchAll(PDO::FETCH_ASSOC)` 결과 그대로(연관배열의 배열). 결과가
  없으면 빈 배열.

## MySQL/MSSQL 겸용

`SqlDialectFactory::forPdo($pdo)`로 자동 판별해 `callStatement()`만
`CALL name(?, ?)`(MySQL) vs `EXEC name ?, ?`(MSSQL, 파라미터 0개면 `EXEC name`
만 — 괄호 없음)로 분기한다. 나머지 로직(실행, 인코딩 보정)은 DB와 무관하게
동일하다.

## 인코딩 보정 (Board와 동일한 이유)

DB 컬럼이 varchar + 비UTF8 콜레이션(한글 환경에서 흔한 CP949 계열)이면
`pdo_dblib`가 돌려주는 문자열이 유효한 UTF-8이 아닐 수 있다 — 이 상태로
`json_encode()`에 넘기면 조용히 `false`가 되어 응답이 빈 문자열이 된다.
`Board::loadBoard()`가 자기 SQL 결과에 이미 적용하고 있는 것과 같은 보정
(`mb_check_encoding()`으로 걸러 아닌 것만 CP949→UTF-8 변환)을 `SP::execute()`
결과에도 그대로 적용한다. `Board` 쪽 코드를 재사용하지 않고 `SP` 안에 따로
두는 이유는 의존 방향 때문이다 — `Board`가 `SP`의 결과를 받는 경우는 있어도
(아래 "Board 연계" 참고), `SP`가 `Board`를 참조할 이유는 없다.

## 사용 예시

```php
$db = new DbContext('mssql');
$rows = SP::execute('qGrid_3_매출최종', ['2026-09-07'], $db);
// $rows = [ ['룸'=>'1T', '매출'=>120000, ...], ... ]

// 여러 프로시저를 조합해 하나의 JSON으로 (엔드포인트가 담당)
$json = json_encode([
    '매출최종'   => SP::execute('qGrid_3_매출최종', [$date], $db),
    '가용아가씨' => SP::execute('getSQL가용아가씨', [$date], $db),
    '투입아가씨' => SP::execute('getSQL투입아가씨', [$date], $db),
    'stats'      => SP::execute('getStats', [$date], $db),
], JSON_UNESCAPED_UNICODE);
```

## Board 연계 — `Board::initFromArray()`

`SP::execute()`가 반환한 배열은 `Board::initFromArray()`에 그대로 넘겨
페이지/검색/정렬이 있는 목록으로 표현할 수 있다(자세한 내용은
`src/Model/Board/Board.md`의 "배열 모드" 참고):

```php
$rows = SP::execute('qGrid_3_매출최종', [$date], $db);
$board = new Board($db);
$board->initFromArray($rows, '', '', '', null, null, null, null, '', 'code');
```

`initFromQuery()`(임의 SELECT 문)와 달리 `initFromArray()`는 SQL을 전혀 안
쓴다 — 총건수는 `count($rows)`, 페이징/검색/정렬은 전부 PHP 메모리에서
처리한다(SP 결과는 이미 전량이 PHP 배열로 넘어와 있으므로 SQL로 다시 셀
필요가 없다는 것이 이 설계의 핵심). 다만 이 방식은 페이지를 넘길 때마다
프로시저를 다시 실행하지 않는다 — `initFromArray()` 호출 시점에 이미 받은
배열 하나를 그대로 재사용하며, 그 배열 자체를 다시 채우려면(다음 HTTP
요청에서) `SP::execute()`를 다시 호출해야 한다(일반적인 요청-응답 주기와
동일 — Crud/Board도 매 요청마다 새로 만드는 것과 같은 특성).

## 연습 화면

- `src/Model/SP/SP_entry.php` — 프로시저 이름/파라미터(콤마 구분)/접속 이름을
  입력해 직접 호출해보는 도구(`Grid_entry.php`/`Domain_entry.php`와 동일한
  "코드 옆 자기완결 시험 페이지" 관례). 이 프로젝트가 실제로 갖고 있는 4개
  프로시저(`qGrid_3_매출최종`/`getSQL가용아가씨`/`getSQL투입아가씨`/
  `getStats`, 전부 날짜 파라미터 1개)를 날짜 입력 하나로 바로 호출해보는
  버튼도 함께 둔다. `public/test/Layout/test1/content5_6.php`가 이 파일을
  iframe으로 얹어 Layout의 "커뮤니티 > SP 연습"(top=5, left=6) 메뉴에 연결한다.
- `src/Model/SP/SP_Board_entry.php` [2026-09-07 추가] — `SP::execute()`
  결과를 `Board::initFromArray()`에 주입해 검색/정렬/페이징이 있는
  목록으로 표시하는 **조합** 예제(`SP_entry.php`는 SP 하나만 시험, 이
  파일은 SP+Board 연계를 시험). array 모드는 `getDefaultBodyHTML()`을
  못 써서(`Board.md` "렌더링" 절 참고) `getRows()`/`getDefaultInfoHTML()`/
  `getDefaultPagerHTML()`/`getSortField()`/`getSortDir()`/`getSortUrl()`
  만으로 헤더 클릭 정렬 화살표를 화면이 직접 그린다. 페이지 이동/정렬을
  눌러도 SP는 다시 실행되지 않는다(최초 요청에서 받은 배열 안에서
  `Board`가 전부 처리) — `Board.md` "배열 모드" 절의 트레이드오프를
  그대로 보여주는 실증이기도 하다. `content5_7.php`가 iframe으로 얹어
  "커뮤니티 > SP+Board 연습"(top=5, left=7) 메뉴에 연결한다.

## 알아둘 제약/함정

- **`$procName`/파라미터 순서가 실제 프로시저 정의와 안 맞으면 SQL 오류로
  바로 드러난다** — 이 클래스는 프로시저의 파라미터 개수/타입을 검증하지
  않는다(DB가 알려줄 표준적인 방법이 마땅치 않아서, `Crud::init()`의
  information_schema 조회 같은 사전 검증 단계가 없다).
- **다중 결과셋 미지원**은 기능 누락이 아니라 의도된 설계 제약이다(위
  "왜 별도 클래스인가" 참고) — 결과셋이 여러 개 필요한 프로시저는 호출부가
  프로시저를 여러 개로 나누거나, 여러 번 실행하는 방식으로 우회해야 한다.
- **쓰기(INSERT/UPDATE/DELETE)를 수행하는 프로시저도 호출은 된다**(이 클래스는
  SELECT 전용 제약을 두지 않는다) — 다만 `fetchAll()`이 반환할 결과셋이
  없는 프로시저(순수 실행형)를 호출하면 빈 배열이 돌아올 뿐 에러는 아니다.
  `Board::initFromArray()`와의 연계는 조회용 프로시저를 전제로 한 것이라,
  쓰기 프로시저에 그대로 엮을 이유는 없다.
- **`$procName`은 raw SQL이라는 걸 다시 강조** — 이 클래스는 SQL 인젝션
  방어를 하지 않는다. 외부 입력을 그대로 넘기지 말 것.

## 관련 파일

- `src/Model/SP/SP.php` — 클래스 본체.
- `src/Model/SP/SP_entry.php` — 호출 연습 페이지(5_6 "SP 연습"에서 iframe으로 연결).
- `src/Model/SP/SP_Board_entry.php` — `Board::initFromArray()` 조합 연습
  페이지(5_7 "SP+Board 연습"에서 iframe으로 연결).
- `src/Model/SqlDialect/SqlDialect.php`/`SqlDialectMysql.php`/`SqlDialectMssql.php`
  — `callStatement()`(CALL/EXEC 문법 분기)를 여기서 빌려 씀.
- `src/Model/Board/Board.php` — `initFromArray()`로 이 클래스의 결과를 받는
  쪽(자세한 내용은 `src/Model/Board/Board.md`).
- `public/test/deco/deco_main_fetch.php`(5_4 "매출화면"의 조회 엔드포인트)
  [2026-09-07 추가, 같은 날 `getRs()` 시그니처 변경 → `show()` 래퍼는
  제거] — 이 클래스의 첫 실전 소비처. 이 파일이 노출하는 함수는
  `getRs(?string $name, string $date)` 하나뿐 — `$name`이 조회 이름이면
  그 하나만, `null`이면 5개 전부를 재귀로 모아 반환한다. 진입점도
  `getRs(null, $date)`를 직접 부른다(예전엔 `show($date)`라는 이름만
  있는 래퍼를 거쳤으나 제거함). 항상 JSON만 응답한다(이 프로젝트의
  `fetch_*.php` 관례와 동일하게 화면 렌더링 파일 `deco_main.php`와
  분리된 전용 엔드포인트). `deco_main.php`(JS)는 이 fetch를
  `dmGetRs(pparams, rparams)` 함수로 감싸고, `action()`에 `case 'getRs':
  return dmGetRs(pparams, rparams);`로 노출한다 — `rparams`는
  `action(pcmd, pparams, psender, rparams)`의 4번째 인수(호출하는 쪽이
  빈 객체를 만들어 넘기면 `dmGetRs()`가 `rparams.json`에 값을 채워
  넣는다, VBA `ByRef` 출력 매개변수와 같은 발상 — `action()`이 지금까지
  지켜온 "fire-and-forget, 반환값 없음" 원칙의 유일한 예외). fetch가
  비동기라 `dmGetRs()`는 Promise도 함께 반환한다. `'show'`/`'requery'`/
  `'display'`(셋 다 동일 동작, `dmShowAll()`)는 `'getRs'`와 별개 pcmd로,
  내부적으로 `dmGetRs()`를 재사용해 받은 JSON을 `Popup`에 그대로 띄운다
  — `deco_main.php` 최상단 docblock 중 "show"/`action()` 절 참고.
  `deco_left.php`의 "getRs" 버튼(`dlRequestGetRs()`)은 이 `'show'`류가
  아니라 `action('getRs', pparams, psender, rparams)`를 **직접** 호출해
  값을 돌려받고, Popup도 `deco_main.php`가 아니라 **`deco_left.php`
  자신이** 띄운다 — "결과를 돌려받은 쪽이 표시를 책임진다"는 실증 예제.
