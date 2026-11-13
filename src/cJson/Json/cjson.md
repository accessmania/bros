# cJson

JSON DOM 하나를 유일한 저장소로 쓰는 레코드셋. VBA `cXml`을 JSON으로 옮긴 것이고, Crud의 body다.
cXml이 DOMDocument를 직접 읽고 썼듯이, cJson은 `JsonObject`(System.Text.Json.Nodes)를 직접 읽고 쓴다. 별도의 내부 자료구조가 없으므로 `ToJson()`은 언제 불러도 현재 상태 그대로다.
(목표·진행 기록과 테스트 화면은 `VibeWinform/Class/CJson/cJson.md`)

## 파일

| 파일 | 내용 |
|---|---|
| `cJson.cs` | 클래스 본체 (namespace `CrudLib`). UI 의존 없음. DB는 주입된 `DbContext`로만 읽는다 |
| `cJson.Paging.cs` | 같은 클래스의 페이징 읽기 부분 (`partial class`로 파일만 나눔). 아래 "페이징" 절 |
| `cJsonWhere.cs` | where 문자열 조건식 해석기 (internal) |

**역할 경계**: 읽기(전체·페이징·임의 쿼리)와 열람은 cJson, 쓰기(INSERT·UPDATE·DELETE, 변경 추적)는 Crud, WHERE·ORDER BY 조각 만들기는 `Sql/SqlWhere`·`Sql/SqlOrderBy`.

- 의존: `System.Text.Json` (netstandard2.0은 NuGet 8.0.5, net8.0은 내장).
- DB 없이도 `SetValue` / `GetValue`만으로 쓸 수 있다. 스키마는 `LoadSchema`로 넣거나 `LoadSql`로 DB에서 가져온다.
- Crud가 쓰는 `internal CrudData()` 하나가 있다 (data 배열 원본, 읽기 전용으로만 사용).

## JSON 구조

```json
{
  "isLoadedSchema": false,
  "schema": { "tableName": "", "primaryKey": "code", "fields": {} },
  "cursor": -1,
  "where": "",
  "data": [
    { "code": "A1", "name": "사과", "price": 1000 }
  ]
}
```

- PHP `Crud::toJson()`의 `{schema, cursor, data}`에 `isLoadedSchema`, `where`를 더한 형태. PHP JSON도 그대로 `Load`된다 (`where`가 없으면 `""`).
- 저장소는 `JsonObject` 하나뿐이다 (`System.Text.Json.Nodes`, 추가 패키지 없음). `ToJson()`은 언제 불러도 현재 상태다.
- `where`는 문자열 조건식으로 JSON에 저장한다 (cXml이 루트 속성 `where`에 저장한 것과 같음). 직렬화 → `Load` 하면 필터도 다시 걸린다.
- 페이징으로 읽으면 `"pager": {page, pageSize, totalRows, totalPages}`가, 쿼리 페이징이면 `"columns": [...]`도 붙는다 (아래 "페이징"). 모르는 최상위 키는 보존되므로 `ToJson` → `Load` / `Crud.AttachBody`을 거쳐도 남는다. 단 `ToJsonWhere`에는 들어가지 않는다.

## 규칙

| 항목 | 규칙 |
|---|---|
| 키 필드 | `schema.primaryKey`, 비어 있으면 `"code"` |
| 커서 | data 안의 **인덱스**. `-1` = BOF, data 개수 = EOF (cXml은 키 값으로 기억해서 키가 바뀌면 커서를 잃었음) |
| `SetValue(key, field, value)` | 키 레코드가 없으면 끝에 새로 만들고 커서를 그 레코드로 옮긴다 |
| `SetValue(field, value)` | 현재 커서 레코드에 쓴다. BOF/EOF면 **예외** (cXml은 빈 키 레코드가 생겼음) |
| `AddNew()` | 새 행을 끝에 만들고 커서를 옮긴다. 키에 **임시 키 `Pseudo0_n`**을 넣고 돌려준다 (키 없는 행은 허용하지 않으므로) |
| 스키마 탑재 (`isLoadedSchema = true`) | `schema.fields`에 없는 필드를 쓰면 **예외** |
| 스키마 없음 | 필드를 자유롭게 추가 |
| 쓰기 실패 | 예외 (빈 키, 빈 필드 이름, 키 중복, 키를 빈 값으로 변경) |
| 읽기 실패 | `""` 반환 (없는 키/필드, BOF/EOF). cXml과 같음 |
| JSON null | `GetValue`는 `""`. null과 "없음"의 구분은 `IsNullValue` |
| 한글 | 이스케이프하지 않음 (PHP `JSON_UNESCAPED_UNICODE`와 같음) |

### 생성자와 Load

- `new cJson()`은 빈 구조로 만들어지므로 바로 쓸 수 있다. "로드 안 된 상태"는 없다 (cXml의 `isLoad` 불필요).
- `new cJson(json)`은 생성하면서 로드한다.
- `new cJson(pSchema: schema)`처럼 이름 있는 매개변수로 `CrudSchema`를 주면 `LoadSchema(CrudSchema)`까지 한다. `LoadSchema`는 그대로 따로 쓸 수 있다.
- `Load`는 같은 인스턴스의 내용을 바꿀 때 쓴다 (다른 곳이 참조하고 있어도 객체는 그대로).

### Load 규칙

- 빈 문자열 → 빈 구조.
- 배열 `[ {...} ]` → data로 로드.
- cJson 객체 → 빠진 키는 기본값으로 채움.
  - `isLoadedSchema`가 없으면 `schema.fields`가 비어 있지 않을 때 `true` (PHP Crud JSON 호환).
  - `cursor`가 없으면 레코드가 있으면 `0`, 없으면 `-1`.
- 키 누락·중복, 스키마 밖 필드(스키마 탑재 시), 형식 오류 → 예외.
- 모르는 최상위 키는 버리지 않고 뒤에 보존한다.
- `where`가 있으면 다시 해석해서 필터를 건다. 문법이 틀리거나, 스키마 탑재 시 스키마에 없는 필드를 쓰면 예외.

## 함수 목록

**생성 / 로드 / 출력**: `new cJson(json = "", pSchema = null, pDbContext = null)`, `Load(json = "")`, `Load(cJson)`, `ToJson(indented)`, `ToJsonWhere(indented)`, `CurrentToJson(indented)`

**스키마**: `LoadSchema(schemaJson)`, `LoadSchema(CrudSchema)`, `GetSchema()`, `UnloadSchema()`, `IsLoadedSchema()`, `GetSchemaJson(indented)`, `KeyName` (속성), `GetFieldNames()`, `ExistsField(field)`

- `LoadSchema`: `{tableName, primaryKey, fields}` 또는 `{"schema": {...}}`를 받는다. 기존 data가 새 스키마에 맞지 않으면 예외이고, 상태는 바뀌지 않는다.
- `LoadSchema(CrudSchema)`: 방언(`ISqlDialect.LoadSchema`)이 만든 `CrudSchema`를 PHP 형식 JSON으로 바꿔 탑재한다. `Fields`(List)는 필드 이름을 키로 하는 객체가 되고, `DefaultValue`는 `default`가 된다. `kind`는 `"Integer"` 같은 이름 문자열로 추가된다 (PHP·JS는 모르는 키라 무시). PK가 없는 테이블은 예외.
  - 변환은 `SqlDialectUtil.SchemaToJsonObject()`가 한다. 방언의 `ToJson(pDb, table)`도 같은 함수를 쓰므로 형식이 항상 같다. 방언 `ToJson` 결과는 `LoadSchema(string)`에 그대로 넣을 수 있다.
- `GetSchema()`: 탑재된 스키마를 `CrudSchema`로 돌려준다 (반대 방향, Crud가 SQL 만들 때 사용). `kind`가 없으면 `Other`.
- `UnloadSchema`: 스키마를 비우되 `primaryKey`는 유지한다 (data의 키가 깨지지 않도록).
- `KeyName` 설정은 스키마가 없고 data가 비어 있을 때만 가능하다.

**값**: `AddNew()`, `SetValue(key, field, value)`, `SetValue(field, value)`, `GetValue(key, field)`, `GetValue(field)`, `GetDouble`, `GetBool`, `IsNullValue` (각각 키 있음·없음), `ExistsKey(key)`, `GetCurrentFields(sep)`, `Merge(cJson, addIfMissing)`, `AppendRecords(json)`

- `AddNew()`: 새 행 + 커서 이동, 임시 키(`NewKeyPrefix` = `"Pseudo0_"` + 번호)를 돌려준다. 이미 있는 키와 겹치지 않는 번호를 고른다. 번호는 인스턴스 안에서만 이어지고 JSON에는 들어가지 않는다.
  - 이 cJson을 Crud에 붙이면(`Crud.AttachBody`) 임시 키 행을 **새 레코드(INSERT)**로 본다. `Crud.AddNew()`도 이 함수를 부른다.
  ```csharp
  key = j.AddNew();             // "Pseudo0_1", 커서가 새 행에
  j.SetValue("이름", "새사람");  // 커서 행에 쓴다
  ```
- `GetValue`는 항상 문자열을 반환한다 (cXml과 같음). 숫자·bool은 JSON 표기 그대로 (`"1000"`, `"0.5"`, `"true"`), 없거나 null이면 `""`.
- `IsNullValue`: 필드가 있고 값이 JSON null이면 true, 레코드나 필드가 없으면 false. 나중에 Crud가 DB NULL을 판단할 때 쓴다.
- `GetDouble`: 문자열의 천단위 콤마를 지우고 변환한다. 실패하면 0.
- `GetBool`: `true` / 0이 아닌 숫자 / `"true","1","-1","y","yes"`는 true.
- `Merge`, `AppendRecords`: 전부 검증한 뒤 반영한다. 하나라도 틀리면 아무것도 바꾸지 않는다.

**커서**: `MoveFirst()`, `MoveLast()`, `MoveNext()`, `MovePrevious()`, `MoveToKey(key)`, `MoveTo(index)`, `EOF()`, `BOF()`, `GetCursor()`, `GetCurrentKey()`, `Count()`, `IsEmpty()`

- where가 있으면 조건에 맞는 레코드 안에서만 이동한다.
- `MoveTo(index)`는 where 결과 안에서의 순번이고, `GetCursor()`는 data 전체에서의 인덱스다.

**필터 / 삭제**: `SetWhere(조건식)`, `ClearWhere()`, `HasWhere()`, `GetWhere()`, `Delete(key)`, `DeleteWhere()`, `Clear()`

- 예: `j.SetWhere("kind = '과일' and price >= 1000");`
- `SetWhere`는 조건식을 JSON `where`에 저장하고 자동으로 `MoveFirst()`한다. 빈 문자열이면 `ClearWhere()`와 같다.
- `ClearWhere`는 `where`를 `""`로 바꾸고 커서는 그대로 둔다. `GetWhere`는 현재 조건식.
- 문법이 틀리거나, 스키마 탑재 시 스키마에 없는 필드를 쓰면 예외이고, 기존 where는 그대로다.
- `ToJsonWhere`에도 조건식이 함께 담긴다 (걸러진 data에 다시 걸려도 결과는 같다).

### where 조건식 문법 (`cJsonWhere.cs`)

| 구분 | 문법 |
|---|---|
| 비교 | `=` `<>` `!=` `>` `<` `>=` `<=` |
| 논리 | `and` `or` `not`, 괄호. 우선순위 not > and > or. 키워드는 대소문자 무시 |
| 패턴 | `필드 [not] like '패턴'` (`%` 여러 글자, `_` 한 글자) |
| 목록 | `필드 [not] in ('A1', 'B2', 3)` |
| null | `필드 is null`, `필드 is not null` (없는 필드도 null) |
| 함수 | `contains(필드, '글자')`, `startswith(...)`, `endswith(...)` |
| bool 필드 | `품절`, `not 품절` (true, 0이 아닌 숫자, `"y"` 등이면 참) |
| 값 | `'문자열'`, `"문자열"`, 숫자, `true`, `false`. 따옴표는 두 번: `'홍''길동'` |
| 필드 이름 | `이름`(한글 가능), `@이름`(cXml 호환), `[공백 있는 이름]`, 키워드와 같은 이름은 `[in]` |

비교 규칙:
- 한쪽이 숫자이고 다른 쪽도 숫자로 읽히면 숫자로 비교한다 (`price > 1000`, `price = '1000'`).
- 한쪽이 숫자인데 다른 쪽이 숫자가 아니면 `=`는 거짓, `<>`는 참, 크기 비교는 거짓.
- 그 외는 문자열 비교 (대소문자 구분, cXml의 XPath와 같음).
- 없는 필드나 null은 문자열 비교에서 `""`로 본다 (`분류 = ''`는 없는 것도 포함).

예:
```
kind = '과일' and price >= 1000
(분류 = '안주' or 분류 = '주류') and not 품절
name like '%사과%'
code in ('A1', 'B2')
memo is null
contains(@분류, '안주')
```
- 현재 레코드를 지우면 커서는 다음 레코드(없으면 EOF)로 간다. 따라서 순회하면서 지울 때는 지우면 그대로, 안 지우면 `MoveNext()`.

**Pseudo**: `LoadPseudo(type = "1")`, `IsCurrentPseudo(type = "1")`, `DeletePseudo(type = "1")`

- 키 앞에 `Pseudo{type}_`를 붙인다. 이미 붙은 키는 건너뛴다.
- `IsCurrentPseudo("")`는 종류와 관계없이 `Pseudo`로 시작하면 true.
- **type `"0"`은 새 행 임시 키(`AddNew`) 전용**이다. `LoadPseudo("0")`은 예외 — 기존 행 키가 `Pseudo0_…`가 되면 Crud가 새 행으로 보고 한 번 더 INSERT하기 때문이다.
  `IsCurrentPseudo("0")`(현재 행이 새 행인가), `DeletePseudo("0")`(저장 안 한 새 행 버리기)은 쓸 수 있다.
- 2026-11-12 기본 type을 `"0"` → `"1"`로 바꿨다 (cXml에서 옮긴 코드가 기본값에 기대고 있으면 확인할 것).

**DbContext 연계 (읽기 전용)**: `SetDbContext(ctx)`, `GetDbContext()`, `LoadSql(table, where, orderBy, pParams, pWhitelist)`, `LoadSqlSchema(table, pWhitelist)`, `LoadSqlQuery(sql, pParams, pKeyName)`, `GetLastSql()`

- 주입: `new cJson(pDbContext: ctx)` 또는 `SetDbContext(ctx)`. JSON에는 들어가지 않고, 연결을 닫거나 Dispose하지 않는다 (주인은 주입한 쪽).
- `LoadSql`: `DbContext.GetSchema`(캐시)로 스키마를 탑재하고, SELECT 결과로 data를 채운다. 읽은 행 수를 반환한다.
  - 컬럼을 명시한다 (`SELECT *` 아님). `ts`, `__ts__`, `Kind = Binary` 필드는 방언 `LoadSchema`에서 이미 빠져 있어 읽지 않는다 (규칙: `SqlDialectUtil.ExcludedFields`).
  - `pWhitelist`: 이 필드들과 PK만 남긴다. PK는 항상 포함하고, 테이블에 없는 이름이 있으면 예외.
  - WHERE 값은 `{0}`, `{1}` 자리로 쓰고 `pParams`로 넘긴다. `{n}`은 방언의 파라미터 이름(`@p0`)으로 바뀌어 바인딩된다. 값을 문자열로 이어 붙이지 말 것.
    예: `j.LoadSql("tLogin", "나이 > {0} and 이름 like {1}", pParams: new object[] { 20, "홍%" })`
  - `pOrderBy`가 비면 PK 순서.
- `LoadSqlSchema`: 스키마만 탑재하고 data는 비운다 (신규 입력 화면용).
- `LoadSqlQuery`: 임의의 SELECT 문 (JOIN, 집계 등, cXml의 `load_from_sql`). 스키마는 없다 (`isLoadedSchema = false`).
  - `pKeyName`(비면 현재 `KeyName`) 컬럼이 모든 행에 있어야 하고 중복이 없어야 한다.
  - 같은 이름의 컬럼이 여럿이면 뒤의 것이 남으므로 `AS` 별칭을 쓴다.
- 세 함수 모두 기존 스키마, data, where를 바꾸고 커서는 첫 레코드(없으면 -1)로 간다. 실패하면 예외이고 cJson은 원래 상태 그대로다.
- `GetLastSql()`: 마지막으로 실행한 SQL (확인·로그용).
- INSERT, UPDATE, DELETE는 cJson이 아니라 Crud가 맡는다.

DB 값 → JSON 변환:

| DB 값 | JSON |
|---|---|
| `DBNull` | `null` |
| 정수, 실수, decimal | 숫자 |
| bool, 또는 `Kind = Bool`인 숫자 (MySQL `tinyint(1)`) | `true` / `false` |
| DateTime | `"yyyy-MM-dd HH:mm:ss"` (밀리초가 있으면 `.fff`까지) |
| DateTimeOffset | `"yyyy-MM-dd HH:mm:ss+09:00"` |
| byte[] | 넣지 않음 |
| 그 밖 (Guid, TimeSpan 등) | 문자열 |

**공용 변환 (static)**: `ToText(node)`, `ToDouble(node)`, `ToBool(node)`

## 페이징 (`cJson.Paging.cs`)

DB에서 한 페이지만 읽는다. 검색·정렬 조건은 `Sql/SqlWhere`, `Sql/SqlOrderBy`로 만들어 넘긴다 (`Class/CRUD/Sql/*.md`).

| 함수 | 내용 |
|---|---|
| `LoadSqlTablePage(table, where, orderBy, pParams, page, pageSize, pWhitelist = null)` | 테이블 한 페이지. 스키마 탑재. 읽은 행 수 반환 |
| `LoadSqlQueryPage(sql, keyName, where, orderBy, pParams, page, pageSize)` | 임의 SELECT 한 페이지. 스키마 없음. JSON에 `columns` 추가. 읽은 행 수 반환 |
| `GetTableColumns(table)` | 테이블 컬럼 목록 (`List<CrudField>`, 스키마 캐시 사본). SqlWhere·SqlOrderBy에 넘긴다 |
| `GetQueryColumns(sql, pParams = null)` | 쿼리 결과 컬럼 목록을 0건 탐색(`SELECT * FROM (sql) AS sub WHERE 1 = 0`)으로. 이름·DB 타입·Kind(.NET 타입으로 판단)만 알 수 있다 |
| `IsPaged()` | 페이징으로 읽은 상태인지 (`pager`가 있는지) |
| `GetPage()`, `GetPageSize()`, `GetTotalRows()`, `GetTotalPages()` | 페이지 정보. 페이징이 아니면 1, `Count()`, `Count()`, (레코드 있으면 1 / 없으면 0) |
| `MaxPageSize` (상수 1000) | pageSize 상한 |

실행되는 SQL (`GetLastSql()`은 두 문장을 줄바꿈으로 이어서 돌려준다):

```sql
-- 테이블
SELECT COUNT(*) FROM [t회원] WHERE …
SELECT [code], [이름], … FROM [t회원] WHERE … ORDER BY … OFFSET n ROWS FETCH NEXT m ROWS ONLY
-- 쿼리
SELECT COUNT(*) FROM (원본쿼리) AS sub WHERE …
SELECT * FROM (원본쿼리) AS sub WHERE … ORDER BY … OFFSET n ROWS FETCH NEXT m ROWS ONLY
```

규칙:

- **page·pageSize는 보정한다** (URL에서 오는 값이라 예외 대신). pageSize는 1 ~ 1000, page는 1 ~ 마지막 페이지 (0건이면 1). 마지막 페이지를 넘기면 마지막 페이지를 읽는다 (마지막 페이지 글을 지운 직후 빈 화면 방지). 실제로 쓴 값은 `pager`에 남는다.
- **ORDER BY는 항상 붙는다** (MSSQL OFFSET 조건). 테이블은 orderBy가 비면 PK, 쿼리는 키 컬럼 오름차순.
- 값이 겹치는 필드로만 정렬하면 페이지를 넘길 때 행이 겹치거나 빠질 수 있다. orderBy는 `SqlOrderBy`로 만들거나(키를 자동으로 붙임), 직접 쓸 때는 끝에 키를 넣는다.
- COUNT와 SELECT는 별개 문장이라, 그 사이에 다른 사용자가 행을 추가하면 건수가 조금 어긋날 수 있다 (목록 화면에서 허용하는 수준).
- `Count()`는 **메모리에 있는 행 수(이 페이지)**, `GetTotalRows()`는 **DB 전체 건수(조건에 맞는 것)**.
- `LoadSql` / `LoadSqlQuery` / `LoadSqlSchema`는 새로 채우므로 `pager`, `columns`가 남지 않는다.
- 실패하면 예외이고 cJson은 원래 상태 그대로다.

쿼리 페이징(`LoadSqlQueryPage`, `GetQueryColumns`)의 규칙:

- 원본 쿼리는 **서버 코드에 고정**한다. 사용자 값은 `{n}` 자리표시로만 넣는다.
- 원본 쿼리 안에 **ORDER BY를 쓰지 않는다** (MSSQL은 서브쿼리 안 ORDER BY가 오류). 정렬은 `orderBy` 인자로.
- 계산 컬럼에는 **별칭(`AS`)을 붙인다**. 이름 없는 컬럼·겹치는 이름은 서브쿼리에서 오류.
- **유일한 키 컬럼**이 있어야 한다 (cJson 규칙). 없으면 `ROW_NUMBER() OVER (ORDER BY …) AS rn`을 넣어 키로 쓴다.
- 저장 프로시저(`EXEC`)는 서브쿼리에 넣을 수 없어 안 된다.
- 끝의 `;`는 떼어 낸다.
- 검색·정렬은 바깥(`sub`)에서 걸리므로 집계 결과 컬럼(`합계금액`)으로도 검색·정렬할 수 있다.
- **자리표시 번호는 하나로 이어진다**: 원본 쿼리가 `{0}`, `{1}`을 쓰면 SqlWhere는 `pParamStart = 2`로 만들고, 값은 `w.GetParams(new object[] { 앞값0, 앞값1 })`로 합쳐 넘긴다.
- 스키마가 없으므로 쓰기 불가. `Crud.AttachBody`에 넘기면 예외.

예 (테이블):

```csharp
using (DbContext ctx = DbContextFactory.Create())
{
    cJson list;
    List<CrudField> cols;
    SqlWhere w;
    SqlOrderBy order;

    list = new cJson(pDbContext: ctx);
    cols = list.GetTableColumns("t회원");

    w = new SqlWhere(ctx.Dialect(), cols);
    w.AddEqual("팀", pTeam);
    w.AddKeyword("이름,메모", pQ);

    order = new SqlOrderBy(ctx.Dialect(), cols, "code");
    order.SetDefault("가입일", "DESC");
    order.Apply(pSort, pDir);

    list.LoadSqlTablePage("t회원", w.GetWhere(), order.GetOrderBy(), w.GetParams(), pPage, 20);
}
```

예 (쿼리, 원본 쿼리가 `{0}`을 씀):

```csharp
sql = "SELECT m.code, m.이름, t.팀명, SUM(s.금액) AS 합계금액 "
    + "FROM t회원 m JOIN t팀 t ON m.팀 = t.code JOIN t매출 s ON s.회원 = m.code "
    + "WHERE s.일자 >= {0} GROUP BY m.code, m.이름, t.팀명";

cols = list.GetQueryColumns(sql, new object[] { pFrom });
w = new SqlWhere(ctx.Dialect(), cols, 1);                 // {1}부터
w.AddKeyword("이름,팀명", pQ);
order = new SqlOrderBy(ctx.Dialect(), cols, "code");
order.Apply(pSort, pDir);

list.LoadSqlQueryPage(sql, "code", w.GetWhere(), order.GetOrderBy(),
                      w.GetParams(new object[] { pFrom }), pPage, 20);
```

검증 (2026-11-11, MSSQL `deco.tLogin` 7건, 읽기 전용 콘솔 시험 25항목 통과): 페이지 읽기·건수, page/pageSize 보정, 겹치는 값으로 정렬해도 전 페이지가 모든 행을 한 번씩만 포함, 쿼리 페이징(안쪽 `{0}` + 바깥 `{1}` 번호 연결, 끝 `;` 제거), 0건일 때 `columns` 유지, `LoadSql` 후 pager 제거, Crud.SelectPage·AttachBody 왕복, 쿼리 JSON의 Crud 거부. MySQL은 빌드만 확인.

## cXml과 대응

| cXml | cJson |
|---|---|
| `load`, `load_from_xml`, `loadVariant` | `Load` |
| `GET_XML` / `GET_WHERE_XML` / `GET_XML_CurrentNode`, `GET_string_currentnode` | `ToJson` / `ToJsonWhere` / `CurrentToJson` |
| `SET_value`, `SET_Value_Attribute(_Cursor)`, `SET_Value_CDataSection(_Cursor)` | `SetValue` |
| `GET_Value`, `GET_Value_Attribute(_Cursor)`, `GET_Value_CDataSection(_Cursor)` | `GetValue`, `GetDouble`, `GetBool` |
| `exists_key` | `ExistsKey` |
| `GET_attributes_currentnode`, `GET_Array_Attribute_Cursor` | `GetCurrentFields` |
| `modify_xml` | `Merge` |
| `insert_node_from_string` | `AppendRecords` |
| `moveFirst/Last/Next/Previous`, `EOF`, `bof` | `MoveFirst/Last/Next/Previous`, `EOF`, `BOF` |
| `move_cursor`, `setCursor`, `SET_Cursor` | `MoveToKey` |
| `MOVE_Cursor_ItemNumber` | `MoveTo` |
| `get_cursor` | `GetCurrentKey` (인덱스는 `GetCursor`) |
| `get_count`, `IsNull` | `Count`, `IsEmpty` |
| `SET_Value_Where` (XPath 문자열, 루트 속성 `where`) | `SetWhere` (조건식 문자열, JSON `where`), `GetWhere` |
| `delete`, `DELETE_WHERE` | `Delete`, `DeleteWhere` |
| `load_from_sql` (DAO) | `LoadSqlQuery` (주입된 DbContext), 테이블 단위는 `LoadSql` |
| `LOAD_Pseudo`, `CONVERT_Pseudo` / `IS_CurrentRecord_Pseudo` / `DELETE_Pseudo` | `LoadPseudo` / `IsCurrentPseudo` / `DeletePseudo` |

**뺀 것**
- CDATA 모드: JSON에서는 구분할 필요가 없다.
- `unload`, `ADD_New`, `test`, `GET_Element_`, `GET_Value_Head`: 미완성 코드이거나 XML 전용.
- 유틸 `cdata`, `nz_`, `isnull_`, `getTimestamps` 등: 필요하면 별도 클래스로 만든다.

**cXml에서 고친 버그**
- 커서를 키 값으로 기억해서, 키를 바꾸면(`LOAD_Pseudo` 등) 커서를 잃던 문제 → 인덱스 커서로 해결.
- 키와 커서가 모두 비었을 때 빈 키 레코드가 생기던 문제 → 예외.
- `insert_node_from_string`이 노드를 하나씩 건너뛰던 문제.
- `SET_Value_CDataSection`에서 `p_fieldvalue`와 `p_fieldname`을 혼동한 문제.
- `SET_Value_Attribute_Cursor`의 `get_cursor() < 0` 검사가 동작하지 않던 문제.
- `SET_Value_Where`가 `(`로 시작하는 조건에 `@`를 붙이던 문제.
- `modify_xml`에 `"code"`가 하드코딩되어 있던 문제.
- 따옴표가 든 키에서 XPath가 깨지던 문제.
- `On Error Resume Next`로 실패를 숨기던 문제 → 쓰기는 예외로 알린다.
