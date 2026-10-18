# Crud (`src/Model/Crud/Crud.php`)

MSSQL/Access→PHP 전환 프로젝트의 범용 CRUD 클래스. `init()`으로 테이블
스키마(PK/필드/타입/제약조건)를 information_schema에서 읽어와 인스턴스에
저장해두고, `selectRecord()`/`setValue()`/`getValue()`/`loadPost()`로 레코드
한 건의 값을 다루는 방식으로 동작한다. `Board`가 스키마 추출을 이 클래스에
그대로 위임한다("crud 처럼 가지고있는다").

이 문서는 시간순 일지가 아니라 **지금 시점의 스펙 요약**이다(최초 지시
원문은 `md/CRUD.md`, 작업 이력은 `md/CRUD_ai.md` 참고 — 이 문서는 두 곳에
흩어져 있던 참고서 성격의 내용을 코드 옆으로 모은 것). API가 바뀌면 이
문서도 함께 갱신한다.

## 명세와 실제 구현의 차이

`md/CRUD.md` 명세는 5개 메서드(`init`/`selectRecord`/`setValue`/`getValue`/
`loadPost`)만 정의하는데, "CRUD"라는 이름을 완성하려면 실제 저장/삭제가
필요해 명세 외로 `insert()`/`update()`/`save()`/`deleteRecord()`를 추가
구현했다. 그 외에도 실사용 과정에서 여러 편의 메서드가 붙었다(아래 API
전체 참고).

## API

### 스키마 초기화

- `init(string $pTableName, ?array $pWhitelist = null): void` —
  `SqlDialect::loadSchema()`(테이블/뷰의 실제 information_schema 조회)로
  PK/필드/타입/nullable/길이/기본값/자동증가 여부를 읽어와 저장한다. 모든
  필드값을 `null`로 초기화(`clear()`).
- `initModelClass(string $modelClass, ?array $pWhitelist = null): void` —
  DB 조회 없이 모델 클래스(`TABLE_NAME`/`FIELDS` 상수를 가진 값 객체)의
  상수로부터 스키마를 구성한다 — `FIELDS`는 `init()`이 만드는 필드 배열과
  동일한 형식(`type`/`nullable`/`maxLength`/`default`/`autoIncrement`/
  `isPrimaryKey`)이어야 한다. 이후 모든 메서드는 `init()`을 썼을 때와
  완전히 동일하게 동작한다(전부 `$schema`/`$tableName`/`$primaryKey`만
  보고 동작).
- `clear(): void` — [2026-09-14 신설, `resetData()`를 public으로 승격 +
  개명] 스키마는 그대로 둔 채 `$this->data`/`$this->touchedFields`만
  빈 상태로 되돌린다(원래 `init()`이 자체적으로도 마지막에 이 메서드를
  부른다). "스키마는 안 바뀌었고 다음 레코드를 다루기 전에 상태만 깨끗이
  하고 싶다"는 경우(예: 같은 테이블에 여러 행을 순서대로 저장/삭제하는
  반복문) `init()`을 다시 부르면 `information_schema`/`sys.*`를 매번
  재조회하는 낭비가 생긴다 — 그 재조회 없이 `clear()`만 부르면 스키마는
  살아있는 채로 다음 행을 위한 빈 상태가 된다(`crudjs_fetch.php`의
  `saveBulk`가 실제로 이렇게 바꿔 쓴다).

**화이트리스트**(2026-09-14 신설): `init()`/`initModelClass()`의
`$pWhitelist` 인자에 필드명 배열을 넘기면, 그 목록에 있는 필드만
스키마에 남고 나머지는 전부 없어진다 — 이후 `getSchema()`/
`selectRecord()`/`selectRecords()`/`getAllValues()`/`toJson()`(읽기)과
`insert()`/`update()`(쓰기) 전부 이 좁혀진 스키마만 보고 동작하므로,
**스키마 노출·읽기 값·쓰기 가능 필드가 한 번의 필터링으로 동시에 그
목록 안으로 제한된다**(`applyWhitelist()`, private). PK는 목록에 빠져
있어도 항상 자동으로 포함된다 — `selectRecord()`/`update()`/
`deleteRecord()`가 PK 없이는 동작할 수 없어서다. 생략하면(기본 `null`)
기존과 동일하게 `EXCLUDED_FIELDS`(아래)만 빠진 전체 필드가 그대로
노출된다 — 하위 호환 깨짐 없음. `Crud`를 그대로 클라이언트에 노출하는
소비자(예: `crudjs.js`)에서 특히 중요 — `md/2026-09-14-crudjs-구현.md`의
mass assignment/스키마 전체 노출 논의 참고.

`selectRecords()`(다건 조회)는 `SELECT *`로 읽어온 원본 행을
`filterRowToSchema()`(private, `array_intersect_key($row,
$this->schema['fields'])`)로 걸러낸 뒤에만 `$recordSet`에 담는다 —
[2026-09-14 후속 수정] 원래는 이 필터링이 없어서, `toJson()`이
`$recordSet`을 그대로 응답에 실어보내는 다건 조회 경로에서만 화이트리스트
밖 컬럼이 새는 구멍이 있었다(스키마는 좁혀져 있어도 `data`는 원본 전체
컬럼). 단건 `selectRecord()`/`loadCursorRecord()`는 값을 `$this->data`
(스키마로 이미 좁혀진 저장소)에 옮겨 담을 때 `array_key_exists()`로
걸러지므로 원래부터 이 구멍이 없었다.

### 레코드 조회 (단건)

- `selectRecord($pKeyValue): bool` — PK 값으로 레코드 1건 조회. 없으면
  `false`(값은 비움).

### 레코드 조회 (커서 — ADO/DAO Recordset 스타일)

- `selectRecords(string $pWhere = ''): int` — 조건에 맞는 레코드를 모두
  읽어(화이트리스트로 좁혀진 필드만, 위 "화이트리스트" 절 참고)
  `$recordSet`에 적재하고 커서를 첫 레코드로. `$pWhere`는 **raw SQL**
  (값 바인딩 없음 — 신뢰된 서버 코드에서만 조립, 사용자 입력 직접 연결 금지).
- `bof()`/`eof()`/`recordCount()`/`getCursorPosition()` — 커서 상태 조회.
  `getCursorPosition()`은 HTTP 요청 간 인스턴스가 유지되지 않는다는 것을
  전제로, 다음 요청에서 `moveNext()` 등을 반복 호출해 같은 위치를
  재현하는 용도(`public/test/CRUD/test_cursor/` 데모가 이 패턴).
- `moveFirst()`/`moveLast()`/`moveNext()`/`movePrevious()` — 커서 이동
  (경계를 넘으면 조용히 BOF/EOF로 clamp, 예외 없음).

### UTF-8 정규화 (읽기, 2026-09-14 신설)

`selectRecord()`/`selectRecords()`가 PDO에서 받은 각 문자열 필드를
`sanitizeUtf8Row()`(private)로 한 번 걸러낸다 — MSSQL의 CP949 계열
컬레이션 컬럼은 `pdo_dblib`을 거치면 유효하지 않은 UTF-8 바이트열로
넘어올 수 있는데, 이 상태로 `toJson()`(`json_encode()`)을 호출하면
`json_encode()`가 조용히 `false`를 반환해 `RuntimeException`("JSON
직렬화에 실패했습니다: Malformed UTF-8 characters...")이 던져진다(실제로
`crudjs.js`의 `selectRecords()` 다건 조회에서 이 예외로 발생한 버그).
`mb_check_encoding($value, 'UTF-8')`로 판정해 유효하면 그대로 두고,
아니면 `iconv('CP949', 'UTF-8//IGNORE', $value)`(실패 시
`mb_convert_encoding()` 폴백)로 변환한다 — `SP::sanitizeUtf8()`/
`Board::sanitizeUtf8()`와 완전히 동일한 로직이며, 서로 의존하지 않고
각자 중복 구현한다(이 프로젝트가 반복해서 겪어온 패턴 — `Board.php`와
`SP.php`도 이미 각자 같은 코드를 갖고 있음, 잘못된 방향의 모듈 의존을
피하기 위한 의도적 중복). 읽기 경로에만 적용되고 `insert()`/`update()`
등 쓰기 경로는 건드리지 않는다.

### 값 읽기/쓰기 (현재 레코드)

- `setValue(string $pFieldName, $pValue): void` / `getValue(string $pFieldName)`
  — 스키마에 없는 필드명이면 `InvalidArgumentException`.
- `loadPost()`/`loadGet()` — `$_POST`/`$_GET` 중 스키마에 존재하는 필드만
  골라 반영.
- `loadClient()` — `$_SERVER['REQUEST_METHOD']`로 POST/GET을 자동 판별해
  `loadPost()`/`loadGet()` 중 하나를 호출(판별 불가 시 `$_POST`가 비어있지
  않은 쪽 우선).
- `loadArray(array $row): void` — 슈퍼글로벌이 아니라 **임의의 연관배열**에
  같은 필터링을 적용한다(2026-08-15 "진짜 인라인 편집 + 일괄저장" SG 추가
  — 브라우저가 JSON body로 보낸 여러 행 중 한 행을 이걸로 반영, `ActionBulk`
  참고).

**스키마 자체에서 배제되는 필드**: `EXCLUDED_FIELDS = ['ts', '__ts__']`
(2026-08-26 `WRITE_EXCLUDED_FIELDS`로 신설, 2026-09-14 읽기까지 포함하도록
확장) — DB가 자동 관리하는 타임스탬프성 컬럼(MSSQL의 rowversion/timestamp
계열)은 `init()`/`initModelClass()`가 스키마를 확정하는 시점에
`stripExcludedFields()`로 `$schema['fields']`에서 통째로 제거된다. 그
결과 `getSchema()`/`selectRecord()`/`getAllValues()`/`toJson()`은 이
필드들을 아예 안 보여주고(읽기 배제), `setValue()`/`getValue()`는 "존재하지
않는 필드"로 예외를 던지며, `insert()`/`update()`도 스키마에 없으니 자연히
SQL에 안 실린다(쓰기 배제) — 필터링 지점이 한 곳(스키마 로딩 시점)뿐이라
`insert()`/`update()`/`loadFrom()` 안에 있던 개별 차단 로직은 필요 없어져
제거했다. 원래 계기는 AGGrid.js가 조회로 받아온 행 객체(`ts` 필드 포함)를
그대로 다시 서버로 보내면 rowversion 컬럼은 MSSQL이 직접 쓰는 걸 거부해
저장이 실패하던 실제 버그(쓰기 차단만으로 충분했음) — 이후 `crudjs.js`처럼
`getSchema()`를 그대로 클라이언트에 내려보내는 소비자가 생기며 읽기 배제도
필요해져 확장했다. 확장 전에는 `public/test/Board/2_6`~`2_18` 등 여러
AGGrid `config_*.php`가 `Crud::init()` 직후 각자 같은 배열로 직접 걸러내고
있었다(중복 로직, 이번 변경 이후로는 불필요하지만 해가 없어 그대로 둠).

### 저장/삭제 (명세 외 — "CRUD" 완성을 위해 추가)

- `insert()` — 현재 값으로 INSERT. `touchedFields`에 없는(클라이언트가 안
  보낸) 필드는 SQL에서 아예 제외(DB 기본값/NULL 규칙을 그대로 따름, 강제로
  NULL을 넣지 않음). auto_increment PK는 값이 비어있으면 제외. 성공 시 PK
  값을 반환.
- `update()` — 현재 값으로 PK 기준 UPDATE. 마찬가지로 안 보낸 필드는 SET
  절에서 제외(기존 값 유지). `touchedFields`가 하나도 없으면(수정할 필드
  없음) `RuntimeException`.
- `save()` — PK 값 유무로 `insert()`/`update()`를 자동 분기하는 편의
  메서드.
- `deleteRecord($pKeyValue = null): int` — PK로 삭제. 인자를 생략하면 현재
  로드된 레코드의 PK를 사용.
- `saveBulk_crudjs(array $rows, array $dirtyIndexes, array $deletedPks): array`
  — [2026-09-14 신설] crudjs.js의 `saveBulk()` 다중 레코드 payload를 한
  번에 반영하는 재사용 헬퍼. 원래 `crudjs_fetch.php`의 `saveBulk` case
  안에 직접 박혀 있던 반복문을 뽑아낸 것 — `t매출_내용`처럼 다중 레코드
  쓰기가 필요한 다른 화면의 fetch 엔드포인트도 자신의 `Crud` 인스턴스로
  이 메서드 하나만 부르면 된다. `$dirtyIndexes`는 PK가 아니라 `$rows`
  안에서의 배열 인덱스(PK 없는 신규 행도 인덱스로는 항상 식별 가능 — PK
  기반이면 `null` PK를 가진 신규 행을 찾을 방법이 없음), `$deletedPks`는
  여전히 PK 배열(삭제는 이미 저장된 행에만 의미가 있음). 행마다
  `clear()+loadArray()+save()`(`save()`가 PK 유무로 insert/update 자동
  분기)/`deleteRecord()`를 개별 try/catch로 감싸 한 행의 실패가 나머지를
  막지 않는다. 반환값 `{results:[{index,success,newPk|message}],
  deleteResults:[{pk,success,message?}]}`.

**필드명이 한글이어도 안전한 이유**: `insert()`/`update()`는 named
placeholder(`:필드명`)가 아니라 **positional placeholder(`?`)**를 쓴다 —
PDO는 파라미터 이름에 비ASCII 문자를 지원하지 않아서, `t회원`처럼 필드명이
한글인 테이블에서 named placeholder를 쓰면 `SQLSTATE[42000] ... near
':이름, :나이...'` 같은 파싱 오류가 난다(실제로 이 프로젝트 최초 구현
때 겪은 버그, `md/CRUD_ai.md` 참고). 컬럼명 자체는 `SqlDialect::
quoteIdentifier()`로 인용해 SQL에 직접 삽입하지만, 값은 전부 `?` 바인드라
인젝션 안전.

**빈 문자열 → NULL 정규화**: `normalizeValueForField()` — INT/DATETIME
등 문자열이 아닌 nullable 컬럼에 빈 문자열(`''`, 폼에서 비워둔 경우)이
그대로 들어가면 DB가 타입 오류(예: MySQL 1366 Incorrect integer value)를
낸다. nullable 필드면 `''`를 `null`로 바꿔 저장한다(NOT NULL 필드의 빈
문자열은 그대로 둬 DB가 정상적으로 오류를 내게 함 — 필수값 검증을 여기서
대신하지 않는다).

### 보조

- `getSchema()`/`getPrimaryKey()`/`getAllValues()` — 조회용 getter.
- `toJson(): string` / `static fromJson(DbContext $db, string $json): Crud` —
  스키마+커서 위치+적재된 레코드(들)를 JSON 문자열로 직렬화/DB 접속 없이
  복원. `{"schema":{...}, "cursor": -1, "data": [...]}` 형태(schema →
  cursor → data 순). 복원 직후에도 `bof()`/`eof()`/`moveNext()`/
  `getAllValues()`를 그대로 이어서 쓸 수 있다 — `initModelClass()`와 같은
  원리로 information_schema를 다시 조회하지 않는다.

## MySQL/MSSQL 겸용

테이블/컬럼 인용, 스키마 조회는 `SqlDialect`로 위임한다(생성자에 넘긴
`DbContext`의 `dialect()`가 PDO의 `ATTR_DRIVER_NAME`으로 자동 판별) — 이 클래스의
나머지 코드는 어떤 DB에 붙어 있는지 몰라도 된다. `LIMIT`/`TOP` 같은 페이징
문법 차이는 `Crud` 자체엔 없다(단건/커서 조회 모두 페이징을 안 씀 — 페이징이
필요한 목록 조회는 `Board`의 역할).

## 사용 예시

```php
$db = new DbContext();
$crud = new Crud($db);
$crud->init('t회원');

// 단건 조회 → 수정 → 저장
$crud->selectRecord(12);
$crud->setValue('이름', '홍길동');
$crud->save(); // PK 있으면 update()

// 신규
$crud->init('t회원'); // 값 초기화
$crud->loadPost();    // $_POST 중 스키마 필드만 반영
$newId = $crud->save(); // PK 없으면 insert()

// 커서 순회
$crud->selectRecords("나이 > 20");
$crud->moveFirst();
while (!$crud->eof()) {
    echo $crud->getValue('이름');
    $crud->moveNext();
}

// DB 접속 없이 왕복
$json = $crud->toJson();
$restored = Crud::fromJson($db, $json); // 스키마 재조회 없음

// 화이트리스트 — 이 목록에 있는 필드(+PK는 자동으로 항상 포함)만
// 스키마/읽기/쓰기 전부에서 노출된다.
$crud->init('t매출', ['룸번호', '손님이름', '입실시각']);
```

## 알아둘 제약/함정

- **`selectRecords($pWhere)`는 raw SQL** — `Board`의 조건 문자열과 동일한
  관례로 사용자 입력을 직접 연결하지 말 것.
- **한글 등 비ASCII 필드명**은 `insert()`/`update()`에서만 특별 취급된다
  (positional placeholder) — `selectRecord()`/`deleteRecord()`는 PK 값
  하나만 바인딩하므로 named placeholder(`:pk`)를 그대로 써도 안전하다
  (PK 컬럼명 자체가 파라미터 이름이 아니라 SQL 텍스트에 직접 인용되기
  때문).
- **PHP 7 호환 문법만 사용** — union type/`mixed`/non-capturing catch 등
  8.0 전용 문법 미사용(NAS의 실제 PHP 버전이 7.x였던 전례가 있음).
- **`initModelClass()`는 DB 스키마 변경에 자동 추종하지 않는다** — 모델
  클래스의 `FIELDS` 상수가 실제 DB와 어긋나면(컬럼 추가/삭제 등) 수동으로
  맞춰야 한다.

## 관련 파일

- `src/Model/Crud/Crud.php` — 클래스 본체.
- `src/Model/Crud/Crud_entry.php` — 폴더 이동 후에도 의존성(`SqlDialect*`)을
  정상적으로 찾는지 확인하는 스모크 테스트(t회원 기준, `toJson()`/
  `fromJson()` 왕복 검증 포함).
- `src/Model/SqlDialect/*` — 테이블/컬럼 인용, 스키마 조회를 여기서 위임.
- `src/Model/Board/Board.php`(`src/Model/Board/Board.md` 참고) — 이 클래스를
  내부에서 재사용해 목록/페이저를 만드는 상위 클래스.
- `md/CRUD.md` — 최초 지시 원문.
- `md/CRUD_ai.md` — 시간순 작업 일지(모든 세부 변경 이력, 실제 버그들의
  경위 포함).
