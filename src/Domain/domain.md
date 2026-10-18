# Domain (`src/Domain/Domain.php`)

MS Access의 도메인 집계 함수(DLookup/DMax/DMin)를 PHP 정적 유틸 클래스로
옮긴 것. Board/Crud처럼 스키마를 인스턴스에 들고 있는 구조가 아니라 "값
하나"를 즉석에서 조회하는 용도다.

이 문서는 시간순 일지가 아니라 **지금 시점의 스펙 요약**이다(작업 이력은
`md/domain-functions.md` 참고). API가 바뀌면 이 문서도 함께 갱신한다.

## Access 함수와의 대응

| Access | Domain | SQL |
|---|---|---|
| `DLookup(expr, domain, [criteria])` | `Domain::lookup($expr, $domain, $criteria, $db)` | `SELECT {expr} FROM {domain} [WHERE {criteria}] LIMIT 1`(MSSQL은 `TOP 1`) |
| (Access에 없음 — 아래 "여러 컬럼 한 번에" 참고) | `Domain::lookupRow($fields, $domain, $criteria, $db)` | `SELECT {field1}, {field2}, ... FROM {domain} [WHERE {criteria}] LIMIT 1`(MSSQL은 `TOP 1`) |
| `DMax(expr, domain, [criteria])` | `Domain::max($expr, $domain, $criteria, $db)` | `SELECT MAX({expr}) FROM {domain} [WHERE {criteria}]` |
| `DMin(expr, domain, [criteria])` | `Domain::min($expr, $domain, $criteria, $db)` | `SELECT MIN({expr}) FROM {domain} [WHERE {criteria}]` |

세 메서드 모두 static — 인스턴스를 만들 필요 없이 `Domain::max(...)` 처럼
바로 호출한다.

## 파라미터

- `$expr`(string, 필수) — 가져올 필드명 또는 SQL 표현식. **raw SQL로
  그대로 조립된다**(따옴표/인용 처리 없음) — Access의 expr이 필드명뿐
  아니라 계산식(`[단가]*[수량]`류)도 받는 것과 동일. 필드명 하나만 쓸
  거면 그냥 `'code'`처럼 넘기면 되고, 백틱/대괄호로 감쌀 필요 없다(라
  이 부분만 특별 취급되지 않음에 주의 — 아래 "주의사항" 참고).
- `$domain`(string, 필수) — 테이블명. **이것만** `SqlDialect::
  quoteIdentifier()`로 자동 인용된다(MySQL 백틱 / MSSQL 대괄호) —
  호출부는 DB 방언을 몰라도 된다. Access처럼 JOIN이 들어간 복합
  domain(예: `"[Orders] INNER JOIN ..."`)은 지원하지 않음 — 단일
  테이블명만 넘길 것.
- `$criteria`(?string, 생략 가능) — `WHERE` 없는 raw SQL 조건 문자열.
  `Crud::selectRecords($pWhere)`/`Board`의 조건 문자열과 동일한 관례 —
  **신뢰된 서버 코드에서만 조립**하고, 사용자 입력을 직접 이어붙이지
  않는다.
- `$db`(?DbContext, 생략 가능) — 생략하면 `new DbContext()`(config/db.php의
  `driver` 기본값)를 쓴다. 특정 접속(예: `mssql`)을 쓰려면
  페이지 상단에서 만든 `new DbContext('mssql')`를 넘긴다(src/db/DbContext.md).

## 반환값

- `lookup()` — 일치하는 첫 행의 `$expr` 값, 없으면 `null`.
- `max()`/`min()` — 집계 결과, 일치하는 행이 하나도 없으면 `null`
  (SQL `MAX()`/`MIN()`이 빈 집합에서 `NULL`을 주는 것 그대로).

## MySQL/MSSQL 겸용

`$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)`으로 직접 판별해 `lookup()`만
`LIMIT 1`(MySQL) vs `TOP 1`(MSSQL 계열: dblib/sqlsrv/odbc)을 분기한다.
`SqlDialect::limitClause()`(Board가 페이징에 쓰는 것)는 재사용하지
않는데, 그건 MSSQL `OFFSET/FETCH` 문법이 `ORDER BY`를 반드시 요구하기
때문이다(`md/sql-dialect-mysql-mssql.md` 참고) — `Domain::lookup()`은
정렬을 요구하지 않으므로 안 맞다. `max()`/`min()`은 집계함수라 결과가
항상 정확히 1행이므로 애초에 LIMIT/TOP 자체가 필요 없다.

## 사용 예시

```php
// 최댓값(가장 큰 code) 하나
$maxCode = Domain::max('code', 't회원');

// 조건 있는 최솟값
$minAge = Domain::min('나이', 't회원', "등록 = 'Y'");

// 특정 행의 필드 하나만 조회
$name = Domain::lookup('이름', 't회원', 'code = 12');

// 1_10 시나리오 — "t회원 상위 1개 rec"의 code를 구해 그대로 ViewCrud에 넘김
$topCode = Domain::max('code', 't회원');
// new ViewCrud($crud, ..., (string) $topCode, ...);
```

## 여러 컬럼 한 번에 — `lookupRow()`

[2026-09-19 추가] `lookup()`은 Access `DLookup()`과 동일하게 한 번에
값 하나만 가져온다 — 같은 테이블/조건에서 여러 컬럼이 함께 필요하면
`lookup()`을 여러 번 불러야 해서 그만큼 SELECT도 여러 번 나간다.
`lookupRow($fields, $domain, $criteria, $db)`는 `$fields`(필드명/
표현식 배열)를 한 SELECT에 모두 담아 **한 번의 쿼리로** 여러 값을
가져온다. Access에는 없는 확장이지만, 반복 조회 부담을 줄이기 위해
추가했다.

- `$fields`(string[], 필수) — 가져올 필드명/표현식 목록. 각 원소는
  `lookup()`의 `$expr`과 동일하게 raw SQL로 그대로 조립된다.
- 반환값 — 일치하는 첫 번째 행을 필드명을 키로 하는 연관배열로 반환,
  없으면 `null`(`lookup()`의 "없으면 null"과 동일한 관례).

```php
// 현금가/카드가를 한 번에 — 예전 방식(lookup() 두 번)보다 SELECT 1회로 줄임
$row = Domain::lookupRow(['[현금가]', '[카드가]'], 't아가씨단가', '[사용] = 1');
if ($row !== null) {
    $price = $row['현금가'];
    $priceCard = $row['카드가'];
}
```

## 확장 여지 (DCount/DSum/DAvg)

`max()`/`min()`이 위임하는 private `aggregate($fn, $expr, $domain,
$criteria, $db)` 헬퍼가 `SELECT {fn}({expr}) FROM ...` 형태를 이미
일반화해뒀다 — `DCount`/`DSum`/`DAvg`가 필요해지면 `Domain::count()`/
`sum()`/`avg()`를 `aggregate('COUNT'|'SUM'|'AVG', ...)` 호출로 추가하면
된다(이번엔 지시 범위 밖이라 구현 안 함).

## 알아둘 제약/함정

- **`lookup()`의 `$criteria`가 넓으면(=많은 행이 매칭되면) 비효율적일 수
  있다** — MySQL PDO는 기본이 버퍼드 쿼리라, `LIMIT 1`을 붙여도 서버가
  실제로는 조건에 맞는 만큼 스캔한 뒤 결과를 돌려주는 것은 동일하다(다만
  네트워크로 전송되는 행은 1개뿐이라 "전체를 PHP로 끌어오는 것"보다는
  항상 낫다). `criteria`를 최대한 좁혀서(가급적 인덱스 걸린 컬럼 등가
  조건으로) 쓰는 게 좋다.
- **`max()`/`min()`은 항상 가볍다** — 집계함수라 DB 엔진이 자기 안에서
  계산해 1행만 돌려준다(인덱스가 있으면 더 빠름). `src/Domain/
  Domain_entry.php`의 성능 비교가 이걸 "전체 로드 후 PHP 계산" 대비
  실측으로 보여준다.
- **`$expr`/`$domain`이 raw SQL이라는 걸 다시 강조** — 이 클래스는
  SQL 인젝션 방어를 하지 않는다(Access의 expr/domain도 원래 신뢰된
  값이라는 전제와 동일). 외부 입력을 그대로 넘기지 말 것.
- **`$domain`은 단일 테이블명만** — JOIN 등 복합 domain은 지원 안 함.

## 관련 파일

- `src/Domain/Domain.php` — 클래스 본체.
- `src/Domain/Domain_entry.php` — 기능 시연 + 성능/정확성 시험 페이지
  (`public/test/`가 아니라 이 폴더 안에 자기완결로 둠).
- `src/Model/SqlDialect/SqlDialect.php`/`SqlDialectFactory.php` — 테이블명
  인용/드라이버 판별을 여기서 빌려 씀(재사용, 새 로직 없음).
