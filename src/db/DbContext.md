# DbContext (`src/db/DbContext.php`)

DB 접속 하나(PDO + 방언 개체)를 들고 다니는 개체. 페이지 상단에서 한 번
만들고, DB를 쓰는 클래스들에 **접속 이름 대신 이 개체를** 넘긴다.

이 문서는 시간순 일지가 아니라 **지금 시점의 스펙 요약**이다. API가 바뀌면
이 문서도 함께 갱신한다.

## 왜 만들었나

- 예전엔 각 클래스/파일이 `Database::connect('mssql')`처럼 접속 이름을
  직접 들고 있었다 — 같은 문자열이 여러 파일에 흩어져 있어, 접속을 바꾸려면
  전부 찾아 고쳐야 했다.
- 이제 접속은 **페이지 상단 한 곳**에서 정하고, 그 아래 클래스들은 받은
  DbContext만 쓴다(접속 이름을 모른다).
- 같은 DbContext를 나눠 쓰므로 한 요청 안의 클래스들이 **같은 연결 하나**를
  쓴다 — 이후 트랜잭션으로 여러 작업을 묶을 수 있는 기반이 된다.
- 연결을 받는 클래스 대부분이 바로 `SqlDialectFactory::forPdo($pdo)`를
  부르던 것을, DbContext가 `dialect()`로 함께 제공한다.

## 구조

| 항목 | 내용 |
|---|---|
| 생성자 | `new DbContext(?string $name = null)` — config/db.php의 **접속 키**(`'mssql'`, `'mssql'` 등). DB 종류(`type`)가 아니다. 생략하면 config의 `'driver'` 기본값. |
| `pdo(): PDO` | 연결. 처음 불릴 때만 `Database::connect($name)`으로 만들고 이후 재사용. |
| `dialect(): SqlDialect` | 이 연결의 SQL 방언(MySQL/MSSQL). 처음 불릴 때 `pdo()`를 거쳐 만들고 이후 재사용. |
| `name(): ?string` | 생성자에 넘긴 접속 이름. |
| `isConnected(): bool` | 이미 연결됐는지. 확인만 하고 연결을 만들지 않는다. |

## 지연 초기화 — 언제 연결되나

생성자는 **접속 이름만 기억하고 연결하지 않는다.** 실제 연결은 누군가
처음 `pdo()`(또는 `dialect()`)를 부를 때 한 번 일어나고, 그 뒤로는 누가
부르든 같은 연결을 돌려준다.

```php
$db = new DbContext('mssql');  // DbContext만 생성 — 아직 연결 없음

Login::isAlive();                   // 세션만 봄 — 연결 없음
                                    //   (여기서 페이지가 끝나면 DB에 한 번도 안 붙음)

$crud = new Crud($db);
$crud->init('tLogin');               // 내부에서 pdo() 첫 호출 → 여기서 connect 1회
$sql  = $db->dialect()->quoteIdentifier('code'); // 같은 연결 재사용, connect 안 함
```

- `Database::connect()`는 **DbContext 내부(`pdo()`)에서만** 부른다. 호출부는
  `$db->pdo()`/`$db->dialect()`만 쓴다.
- DSN 조립·TDS 버전 처리 등 실제 연결 생성은 계속 `Database`의 몫이다 —
  DbContext는 그 위의 얇은 껍데기다.

## 범위 — 한 요청 안에서만

- PHP는 요청이 끝나면 모든 개체가 사라진다. 다음 요청(페이지 재실행)에서는
  새 DbContext가 만들어지고, 그 요청 안에서 처음 쓸 때 다시 연결한다.
- 실수로 같은 이름의 DbContext를 두 번 만들어도 연결이 둘 생기지는 않는다
  (`Database::connect()`가 접속 이름별로 캐시) — 그래도 원칙은 **상단에서
  하나 만들어 넘겨 쓰기**.
- 한 페이지가 DB 두 개를 쓰면(예: talk는 `'mssql'`, 로그인은 `'mssql'`)
  DbContext를 두 개 만든다. 하나의 DbContext는 접속 하나만 담는다 — 여러
  접속을 담는 통으로 키우면 `Database`와 역할이 겹친다.

## 사용 예시

```php
// 페이지 상단
include_custom('DbContext', 'Crud', 'Board');
$db = new DbContext('mssql');

// DB를 쓰는 개체에는 같은 $db를 넘긴다
$crud = new Crud($db);
$crud->init('tLogin');

$board = new Board($db);                       // 내부 Crud도 같은 $db를 공유
$max   = Domain::max('code', 't회원', null, $db);
$rows  = SP::execute('qGrid_3_매출최종', [$date], $db);

// raw SQL이 필요하면 pdo()/dialect()를 직접 쓴다
$where = $db->dialect()->quoteIdentifier('id') . ' = ' . $db->pdo()->quote($id);
```

## 적용 상태

DB를 쓰는 개체는 전부 DbContext를 받는다(PDO를 받는 시그니처는 없앴다).

| 개체 | 받는 방법 |
|---|---|
| `Crud` | `new Crud(DbContext $db)`, `Crud::fromJson(DbContext $db, $json)` |
| `Board` | `new Board(DbContext $db, $prefix = '')`, `Board::fromJson(DbContext $db, $json)` — 내부 Crud에 같은 $db 전달 |
| `Domain` | `lookup/lookupRow/max/min(..., ?DbContext $db = null)` — 생략 시 `new DbContext()` |
| `SP` | `execute($procName, $params = [], ?DbContext $db = null)` — 생략 시 `new DbContext()` |
| `Login` | static이라 생성자 대신 `Login::init(DbContext $db)` |
| `Auth` | `Auth::loadByCode($code, DbContext $db)` — Login이 같은 $db를 넘김 |

- Crud/Board는 `$db`만 들고 있다가 쿼리할 때 `$db->pdo()`/`$db->dialect()`를
  부른다 — 그래서 `new Crud($db)`나 `fromJson()` 복원만으로는 DB에 접속하지
  않는다.
- 호출부(basicdata의 fetch_/config_ 화면, test/fetch_tLogin.php, 각 *_entry.php,
  ChosungGrid/decoItem/talk/crudjs fetch)는 요청 상단에서 DbContext를 만들어
  넘긴다. 컨테이너 페이지에 `require`되는 `config_*.php`는 컨테이너의 변수와
  겹치지 않도록 `$loginGridDb`/`$authGridDb`처럼 고유 이름을 쓴다.
- `Database::connect()`를 부르는 곳은 이제 `DbContext::pdo()` 하나뿐이다
  (`_backup/`은 제외 — 옛 코드 보관용이라 손대지 않았다).

## 관련 파일

- `src/db/DbContext.php` — 이 클래스
- `src/db/Database.php` — 실제 PDO 생성(접속 이름별 캐시)
- `src/Model/SqlDialect/SqlDialectFactory.php` — PDO 드라이버로 방언 선택
- `config/db.php` — 접속 키별 접속 정보
