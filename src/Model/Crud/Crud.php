<?php

declare(strict_types=1);

require_once __DIR__ . '/../SqlDialect/SqlDialect.php';
require_once __DIR__ . '/../../db/DbContext.php';

/**
 * md/CRUD.md 명세에 맞춘 범용 CRUD 클래스.
 *
 * init() 으로 테이블 스키마(PK, 필드, 제약조건)를 information_schema 에서 읽어와
 * 클래스 내부에 저장해두고, selectRecord / setValue / getValue / loadPost 로
 * 한 레코드의 값을 다루는 방식으로 동작한다.
 *
 * CRUD.md 명세에는 실제 저장을 수행하는 함수가 없어서(select/get/set/loadPost 뿐),
 * "CRUD"라는 이름에 맞게 insert / update / save / deleteRecord 를 추가로 구현했다.
 *
 * MySQL/MSSQL 겸용: 테이블/컬럼 인용, LIMIT/OFFSET, 스키마 조회처럼 DB마다
 * 문법이 다른 부분은 전부 SqlDialect로 위임한다. 연결과 방언은 생성자에 넘긴
 * DbContext(src/db/DbContext.php)가 제공하므로, 이 클래스의 나머지 코드는
 * 어떤 DB에 붙어 있는지 몰라도 된다. DbContext는 처음 쓸 때 연결하므로
 * 생성/fromJson() 복원만으로는 DB에 접속하지 않는다.
 *
 * PHP 7 호환 문법만 사용 (union type / mixed / non-capturing catch 등 8.0 전용 문법 미사용).
 */
class Crud
{
    /** @var DbContext 연결(pdo())과 방언(dialect())의 공급원 */
    private $db;

    /** @var string|null */
    private $tableName = null;

    /** @var string|null */
    private $primaryKey = null;

    /** @var array{tableName: string, primaryKey: ?string, fields: array<string, array>} */
    private $schema = [];

    /** @var array<string, mixed> 현재 레코드의 필드=>값 (명세의 "json") */
    private $data = [];

    /**
     * setValue()/selectRecord() 등으로 실제 값이 채워진 필드만 기록.
     * insert()/update()가 "클라이언트가 보내지 않은 필드"와 "일부러 null로 설정한 필드"를
     * 구분해서, 안 보낸 필드는 SQL에서 아예 건너뛸 수 있게 하기 위함.
     *
     * @var array<string, bool>
     */
    private $touchedFields = [];

    /**
     * select(pWhere)로 적재한 레코드들. 각 원소는 필드=>값 배열(한 행).
     * bof()/eof()/moveFirst()/moveNext()/moveLast()/movePrevious()로 옮겨다니는
     * 고전적인 ADO Recordset 스타일 커서 탐색을 지원하기 위한 저장소.
     *
     * @var array<int, array<string, mixed>>
     */
    private $recordSet = [];

    /**
     * $recordSet 안에서 현재 위치를 가리키는 커서.
     * -1 = BOF(첫 레코드 이전), count($recordSet) = EOF(마지막 레코드 이후),
     * 그 사이 값 = 유효한 레코드 인덱스.
     *
     * @var int
     */
    private $cursor = -1;

    public function __construct(DbContext $db)
    {
        $this->db = $db;
    }

    /**
     * 1. init(pTableName)
     * 테이블 스키마 정보(PK, 필드, 타입, not null, 길이 등 제약조건)를 읽어와 저장한다.
     *
     * @param array<int, string>|null $pWhitelist 주어지면(null이 아니면)
     *   이 목록에 있는 필드만 스키마에 남긴다 — applyWhitelist() 참고.
     *   생략하면(기본 null) 화이트리스트 없이 전체 필드(EXCLUDED_FIELDS
     *   제외) 그대로 동작한다(기존과 동일).
     */
    public function init(string $pTableName, ?array $pWhitelist = null): void
    {
        $schema = $this->db->dialect()->loadSchema($this->db->pdo(), $pTableName);
        $schema['fields'] = $this->stripExcludedFields($schema['fields']);
        $schema['fields'] = $this->applyWhitelist($schema['fields'], $schema['primaryKey'], $pWhitelist);

        $this->tableName = $schema['tableName'];
        $this->primaryKey = $schema['primaryKey'];
        $this->schema = $schema;

        $this->recordSet = [];
        $this->cursor = -1;
        $this->clear();
    }

    /**
     * init()과 동일한 결과(스키마 로드 + 데이터 초기화)를 만들지만, DB의 information_schema를
     * 조회하는 대신 모델 클래스(테이블 하나를 표현하는 값 객체)의 TABLE_NAME/FIELDS
     * 상수로부터 스키마를 가져온다. FIELDS는 init()이 만드는 필드 배열과 동일한 형식
     * (type/nullable/maxLength/default/autoIncrement/isPrimaryKey)이어야 한다. 그 외
     * selectRecord()/setValue()/getValue()/loadPost()/insert()/update()/save()/
     * deleteRecord() 등은 init()을 썼을 때와 완전히 똑같이 동작한다 (전부
     * $schema/$tableName/$primaryKey만 보고 동작하기 때문).
     *
     * @param string $modelClass TABLE_NAME/FIELDS 상수를 가진 모델 클래스명(예: SomeModel::class)
     * @param array<int, string>|null $pWhitelist init()과 동일 — 주어지면
     *   이 목록에 있는 필드만 스키마에 남긴다.
     */
    public function initModelClass(string $modelClass, ?array $pWhitelist = null): void
    {
        if (!class_exists($modelClass)) {
            throw new RuntimeException("모델 클래스를 찾을 수 없습니다: {$modelClass}");
        }

        if (!defined($modelClass . '::TABLE_NAME') || !defined($modelClass . '::FIELDS')) {
            throw new RuntimeException("{$modelClass} 에 TABLE_NAME/FIELDS 상수가 없습니다.");
        }

        $tableName = $modelClass::TABLE_NAME;
        $fields = $this->stripExcludedFields($modelClass::FIELDS);

        $primaryKey = null;
        foreach ($fields as $name => $meta) {
            if (!empty($meta['isPrimaryKey'])) {
                $primaryKey = $name;
            }
        }

        $fields = $this->applyWhitelist($fields, $primaryKey, $pWhitelist);

        $this->tableName = $tableName;
        $this->primaryKey = $primaryKey;
        $this->schema = [
            'tableName'  => $tableName,
            'primaryKey' => $primaryKey,
            'fields'     => $fields,
        ];

        $this->recordSet = [];
        $this->cursor = -1;
        $this->clear();
    }

    /**
     * 2. selectRecord(pKeyValue)
     * PK 값으로 레코드 한 건을 조회해 필드값들을 저장한다. 없으면 false 반환하고 값은 비운다.
     *
     * @param mixed $pKeyValue
     */
    public function selectRecord($pKeyValue): bool
    {
        $this->assertInitialized();

        if ($this->primaryKey === null) {
            throw new RuntimeException("테이블에 프라이머리 키가 없습니다: {$this->tableName}");
        }

        // PK 등가조건이라 결과가 최대 1건임이 이미 보장돼서, MySQL/MSSQL마다
        // 문법이 다른 LIMIT/TOP 절을 별도 방언 분기 없이 아예 뺐다.
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = :pk',
            $this->db->dialect()->quoteIdentifier($this->tableName),
            $this->db->dialect()->quoteIdentifier($this->primaryKey)
        );
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['pk' => $pKeyValue]);
        $row = $stmt->fetch();

        $this->clear();

        if ($row === false) {
            return false;
        }

        $row = $this->sanitizeUtf8Row($row);

        foreach ($row as $field => $value) {
            if (array_key_exists($field, $this->data)) {
                $this->data[$field] = $value;
                $this->touchedFields[$field] = true;
            }
        }

        return true;
    }

    /**
     * selectRecords(pWhere)
     * 조건에 맞는 레코드를 모두 읽어 내부 레코드셋에 적재하고 커서를 첫 레코드로 위치시킨다.
     * bof()/eof()/moveFirst()/moveNext()/moveLast()/movePrevious()로 커서를 옮기며
     * 레코드들을 하나씩 조회하는, 고전적인 ADO/DAO Recordset 스타일 탐색을 지원한다.
     * 커서가 가리키는 레코드의 값은 selectRecord()처럼 getValue()/setValue()로 다룬다.
     *
     * $pWhere는 SQL WHERE 절 내용을 그대로 문자열로 이어붙인다(예: "코드 > 10 AND 상품 = '우유'").
     * 값 바인딩을 하지 않으므로 사용자 입력을 그대로 넘기지 말고, 호출하는 쪽에서 안전하게
     * 조립한 조건 문자열만 넘겨야 한다. 빈 문자열이면 조건 없이 전체 레코드를 읽는다.
     *
     * @return int 로드된 레코드 수
     */
    public function selectRecords(string $pWhere = ''): int
    {
        $this->assertInitialized();

        $sql = sprintf('SELECT * FROM %s', $this->db->dialect()->quoteIdentifier($this->tableName));
        if ($pWhere !== '') {
            $sql .= ' WHERE ' . $pWhere;
        }

        $stmt = $this->db->pdo()->query($sql);
        $rows = $stmt !== false ? $stmt->fetchAll() : [];
        // SELECT *로 읽어온 원본 행은 스키마(화이트리스트로 좁혀졌을 수
        // 있음)보다 컬럼이 더 많을 수 있다 — filterRowToSchema()로 먼저
        // 걸러낸 뒤에만 recordSet에 담는다(아래 필터링 이유 참고).
        $this->recordSet = array_map(function (array $row): array {
            return $this->sanitizeUtf8Row($this->filterRowToSchema($row));
        }, $rows);
        $this->cursor = empty($this->recordSet) ? -1 : 0;

        if ($this->cursor >= 0) {
            $this->loadCursorRecord();
        } else {
            $this->clear();
        }

        return count($this->recordSet);
    }

    /**
     * 커서가 첫 레코드 이전(= 유효한 현재 레코드 없음)인지. 레코드셋이 비어있으면 항상 true.
     */
    public function bof(): bool
    {
        if (empty($this->recordSet)) {
            return true;
        }
        return $this->cursor < 0;
    }

    /**
     * 커서가 마지막 레코드 이후(= 유효한 현재 레코드 없음)인지. 레코드셋이 비어있으면 항상 true.
     */
    public function eof(): bool
    {
        if (empty($this->recordSet)) {
            return true;
        }
        return $this->cursor >= count($this->recordSet);
    }

    /**
     * selectRecords()로 적재된 레코드 수.
     */
    public function recordCount(): int
    {
        return count($this->recordSet);
    }

    /**
     * 현재 커서 위치를 그대로 반환한다 (-1=BOF, count=EOF, 그 사이=유효한 인덱스).
     * HTTP 요청 간에는 Crud 인스턴스가 유지되지 않으므로, 화면단에서 이 값을 세션 등에
     * 저장해뒀다가 다음 요청에서 moveNext() 등을 반복 호출해 같은 위치로 재현하는 용도로 쓴다.
     */
    public function getCursorPosition(): int
    {
        return $this->cursor;
    }

    public function moveFirst(): void
    {
        if (empty($this->recordSet)) {
            return;
        }
        $this->cursor = 0;
        $this->loadCursorRecord();
    }

    public function moveLast(): void
    {
        if (empty($this->recordSet)) {
            return;
        }
        $this->cursor = count($this->recordSet) - 1;
        $this->loadCursorRecord();
    }

    public function moveNext(): void
    {
        if (empty($this->recordSet)) {
            return;
        }
        if ($this->cursor < count($this->recordSet)) {
            $this->cursor++;
        }
        if ($this->cursor >= 0 && $this->cursor < count($this->recordSet)) {
            $this->loadCursorRecord();
        } else {
            $this->clear();
        }
    }

    public function movePrevious(): void
    {
        if (empty($this->recordSet)) {
            return;
        }
        if ($this->cursor >= 0) {
            $this->cursor--;
        }
        if ($this->cursor >= 0 && $this->cursor < count($this->recordSet)) {
            $this->loadCursorRecord();
        } else {
            $this->clear();
        }
    }

    /**
     * $recordSet[$cursor]의 값을 $data(현재 레코드)에 반영한다. moveXxx() 계열이 공통으로 사용.
     */
    private function loadCursorRecord(): void
    {
        $this->clear();

        $row = $this->recordSet[$this->cursor];
        foreach ($row as $field => $value) {
            if (array_key_exists($field, $this->data)) {
                $this->data[$field] = $value;
                $this->touchedFields[$field] = true;
            }
        }
    }

    /**
     * 3. setValue(pFieldName, pValue)
     *
     * @param mixed $pValue
     */
    public function setValue(string $pFieldName, $pValue): void
    {
        $this->assertField($pFieldName);
        $this->data[$pFieldName] = $pValue;
        $this->touchedFields[$pFieldName] = true;
    }

    /**
     * 4. getValue(pFieldName)
     *
     * @return mixed
     */
    public function getValue(string $pFieldName)
    {
        $this->assertField($pFieldName);
        return $this->data[$pFieldName];
    }

    /**
     * 5. loadPost()
     * $_POST 로 들어온 값 중 스키마에 존재하는 필드만 골라 저장한다.
     */
    public function loadPost(): void
    {
        $this->loadFrom($_POST);
    }

    /**
     * loadPost()와 동일하되 $_GET 을 사용한다 (명세엔 없지만 loadPost()와 짝을 맞추기 위해 추가).
     */
    public function loadGet(): void
    {
        $this->loadFrom($_GET);
    }

    /**
     * 현재 요청이 POST인지 GET인지에 따라 loadPost()/loadGet() 중 유효한 쪽을 알아서 호출한다.
     * REQUEST_METHOD 를 판단할 수 없는 경우(CLI 실행 등)에는 값이 채워져 있는 쪽을 사용한다.
     */
    public function loadClient(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';

        if ($method === 'POST') {
            $this->loadPost();
            return;
        }

        if ($method === 'GET') {
            $this->loadGet();
            return;
        }

        if (!empty($_POST)) {
            $this->loadPost();
            return;
        }

        $this->loadGet();
    }

    /**
     * loadPost()/loadGet()와 동일한 "스키마에 존재하는 필드만 골라 반영"
     * 로직을 슈퍼글로벌($_POST/$_GET)이 아니라 **임의의 연관배열**에
     * 적용한다(2026-08-15 "진짜 인라인 편집 + 일괄저장" SG 추가 — 브라우저가
     * JSON body로 보낸 여러 행 중 한 행을 이걸로 반영한다, ActionBulk 참고).
     * private loadFrom()을 그대로 감싼 공개 래퍼일 뿐 새 로직은 없다.
     *
     * @param array<string, mixed> $row
     */
    public function loadArray(array $row): void
    {
        $this->loadFrom($row);
    }

    /**
     * [2026-08-26 신설, 2026-09-14 확장] `ts`/`__ts__`는 DB가 자동으로
     * 관리하는 타임스탬프성 컬럼(예: MSSQL의 rowversion/timestamp
     * 계열)이라 클라이언트가 몰라도 되고(읽기에서 제외), 값을 보내와도
     * 절대 쓰기(INSERT/UPDATE)에 참여시키면 안 된다(쓰기에서도 제외).
     *
     * init()/initModelClass()가 스키마를 확정하는 시점에
     * stripExcludedFields()로 이 이름들을 $schema['fields']에서 통째로
     * 없애버리므로, 이후 selectRecord()/getSchema()/getAllValues()/
     * toJson()/setValue()/getValue()/insert()/update() 등 스키마를 보고
     * 동작하는 모든 곳에서 이 필드들은 애초에 존재하지 않는 필드처럼
     * 취급된다(assertField()도 예외를 던짐) — insert()/update() 안에
     * 있던 개별 차단 로직, loadFrom()의 별도 필터는 이제 불필요해져
     * 제거했다(스키마 자체에 없으니 그 이후의 array_key_exists() 검사가
     * 자동으로 걸러줌).
     *
     * [2026-09-14 확장 배경] 원래는 "쓰기만" 막았으나(당시 이름
     * WRITE_EXCLUDED_FIELDS), Crud::getSchema()를 그대로 클라이언트에
     * 내려보내는 소비자(crudjs.js 등)가 생기면서 "읽기(스키마 노출)"도
     * 막아야 할 필요가 생겨 이 시점에 확장했다. 이 확장 전에는
     * public/test/Board/2_6~2_18 등 여러 AGGrid config_*.php가 각자
     * `Crud::init()` 직후 같은 이름 배열로 직접 걸러내고 있었다(중복
     * 로직) — 이제 그 필터링은 여기서 한 번만 하면 되지만, 기존 화면의
     * 중복 코드 자체는 해가 없어 이번 변경 범위에서 건드리지 않았다.
     *
     * @var array<int, string>
     */
    private const EXCLUDED_FIELDS = ['ts', '__ts__'];

    /**
     * EXCLUDED_FIELDS에 해당하는 키를 필드 배열에서 없앤다.
     * init()/initModelClass() 양쪽에서 스키마를 확정하기 직전에 부른다.
     *
     * @param array<string, array> $fields
     * @return array<string, array>
     */
    private function stripExcludedFields(array $fields): array
    {
        foreach (self::EXCLUDED_FIELDS as $excludedField) {
            unset($fields[$excludedField]);
        }
        return $fields;
    }

    /**
     * [2026-09-14 신설] 화이트리스트 — 호출부가 넘긴 필드 목록에 있는
     * 필드만 스키마에 남긴다. init()/initModelClass()가 스키마를 확정하는
     * 시점에(stripExcludedFields() 바로 다음) 적용하므로, 그 이후
     * getSchema()/selectRecord()/getAllValues()/toJson()(읽기)과
     * insert()/update()(쓰기) 전부 이 좁혀진 스키마만 보고 동작한다 — 한
     * 번의 필터링으로 스키마 노출·읽기·쓰기가 동시에 화이트리스트 안으로
     * 제한된다.
     *
     * PK는 화이트리스트에 빠져 있어도 **항상 자동으로 포함**한다 —
     * selectRecord()/update()/deleteRecord()가 PK 없이는 아예 동작할 수
     * 없어서다(호출부가 실수로 PK를 안 적어도 조용히 깨지지 않게).
     * PK 값 자체를 클라이언트가 못 바꾸게 하고 싶다면 화이트리스트가 아닌
     * 별도 장치(예: 애초에 update 경로에서 PK를 안 받는 API 설계)가
     * 필요하다.
     *
     * $pWhitelist가 null이면(기본값) 아무 것도 안 걸러내고 그대로
     * 반환한다 — 기존 호출부(화이트리스트를 안 넘기는 모든 곳)는 동작이
     * 전혀 안 바뀐다.
     *
     * @param array<string, array> $fields
     * @param string|null $primaryKey
     * @param array<int, string>|null $whitelist
     * @return array<string, array>
     */
    private function applyWhitelist(array $fields, ?string $primaryKey, ?array $whitelist): array
    {
        if ($whitelist === null) {
            return $fields;
        }

        $allowedFields = $whitelist;
        if ($primaryKey !== null && !in_array($primaryKey, $allowedFields, true)) {
            $allowedFields[] = $primaryKey;
        }

        $result = [];
        foreach ($fields as $field => $meta) {
            if (in_array($field, $allowedFields, true)) {
                $result[$field] = $meta;
            }
        }
        return $result;
    }

    /**
     * [2026-09-14 신설] selectRecords()가 SELECT *로 읽어온 원본 행에서
     * $this->schema['fields']에 없는 컬럼(EXCLUDED_FIELDS로 빠졌거나
     * applyWhitelist()로 좁혀진 것)을 없앤다.
     *
     * selectRecord()/loadCursorRecord()는 값을 $this->data(스키마로 이미
     * 좁혀진 저장소)에 옮겨 담을 때 `array_key_exists($field, $this->data)`
     * 로 걸러지므로 애초에 이 필터링이 필요 없었다 — 그런데
     * selectRecords()는 SELECT * 결과를 그 필터링 없이 $this->recordSet에
     * 그대로 저장했고, toJson()이 recordSet을 담긴 그대로 클라이언트에
     * 내려보내(schema는 좁혀져 있어도 data는 원본 전체 컬럼) 화이트리스트가
     * 다중 조회 경로에서만 새는 구멍이 있었다 — 이 헬퍼로 SELECT * 결과를
     * recordSet에 담기 전에 한 번 걸러 그 구멍을 막는다.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function filterRowToSchema(array $row): array
    {
        return array_intersect_key($row, $this->schema['fields']);
    }

    /**
     * @param array<string, mixed> $source
     */
    private function loadFrom(array $source): void
    {
        $this->assertInitialized();

        foreach ($source as $field => $value) {
            if (array_key_exists($field, $this->schema['fields'])) {
                $this->setValue($field, $value);
            }
        }
    }

    // ------------------------------------------------------------------
    // 명세엔 없지만 "CRUD"를 완성하기 위해 추가한 실제 저장/삭제 메서드
    // ------------------------------------------------------------------

    /**
     * 현재 값들로 새 레코드를 INSERT 한다. auto_increment PK는 값이 비어 있으면 제외한다.
     * 성공 시 PK 값을 반환하고, 해당 필드에도 반영한다.
     *
     * @return string|int
     */
    public function insert()
    {
        $this->assertInitialized();

        $columns = [];
        $placeholders = [];
        $params = [];

        // 필드명이 한글 등 비ASCII일 수 있어 named placeholder(:필드명) 대신
        // positional placeholder(?)를 사용한다 (PDO는 파라미터명에 비ASCII 문자를 지원하지 않음).
        foreach ($this->schema['fields'] as $field => $meta) {
            if ($meta['autoIncrement'] && ($this->data[$field] === null || $this->data[$field] === '')) {
                continue;
            }
            // rowversion/timestamp 계열 컬럼(ts/__ts__)은 이 루프가 도는
            // $this->schema['fields']에 애초에 없다(stripExcludedFields(),
            // EXCLUDED_FIELDS 독스블록 참고) — 여기서 별도로 다시 걸러낼
            // 필요가 없다.
            // 클라이언트가 값을 보내지 않은(=touch 안 된) 필드는 컬럼 자체를 빼서
            // DB의 기본값/NULL 규칙을 그대로 따르게 한다 (강제로 NULL을 넣지 않음).
            if (!isset($this->touchedFields[$field])) {
                continue;
            }
            $columns[] = $this->db->dialect()->quoteIdentifier($field);
            $placeholders[] = '?';
            $params[] = $this->normalizeValueForField($field, $this->data[$field]);
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->db->dialect()->quoteIdentifier($this->tableName),
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        if ($this->primaryKey !== null && $this->schema['fields'][$this->primaryKey]['autoIncrement']) {
            $newId = $this->db->pdo()->lastInsertId();
            $this->data[$this->primaryKey] = $newId;
            return $newId;
        }

        return isset($this->data[$this->primaryKey]) ? $this->data[$this->primaryKey] : '';
    }

    /**
     * 현재 값들로 PK 기준 레코드를 UPDATE 한다. 영향받은 행 수를 반환한다.
     */
    public function update(): int
    {
        $this->assertInitialized();

        if ($this->primaryKey === null) {
            throw new RuntimeException("테이블에 프라이머리 키가 없습니다: {$this->tableName}");
        }

        $setParts = [];
        $params = [];

        // insert()와 동일한 이유로 positional placeholder(?) 사용.
        // 클라이언트가 값을 보내지 않은(=touch 안 된) 필드는 SET 절에서 아예 빼서
        // 기존 DB 값을 그대로 유지한다 (null로 덮어쓰지 않음).
        foreach (array_keys($this->schema['fields']) as $field) {
            if ($field === $this->primaryKey) {
                continue;
            }
            // insert()와 동일한 이유 — rowversion/timestamp 계열 컬럼은
            // $this->schema['fields']에 애초에 없어 이 루프에 안 걸린다.
            if (!isset($this->touchedFields[$field])) {
                continue;
            }
            $setParts[] = $this->db->dialect()->quoteIdentifier($field) . ' = ?';
            $params[] = $this->normalizeValueForField($field, $this->data[$field]);
        }

        if (empty($setParts)) {
            throw new RuntimeException('수정할 필드가 없습니다 (setValue()/loadPost() 등으로 값을 채운 필드가 없음).');
        }

        $params[] = $this->data[$this->primaryKey];

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = ?',
            $this->db->dialect()->quoteIdentifier($this->tableName),
            implode(', ', $setParts),
            $this->db->dialect()->quoteIdentifier($this->primaryKey)
        );

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * PK 값이 비어있으면 insert, 있으면 update 를 수행하는 편의 메서드.
     *
     * @return string|int
     */
    public function save()
    {
        $this->assertInitialized();

        $pkValue = $this->primaryKey !== null && isset($this->data[$this->primaryKey])
            ? $this->data[$this->primaryKey]
            : null;

        if ($pkValue === null || $pkValue === '') {
            return $this->insert();
        }

        $this->update();
        return $pkValue;
    }

    /**
     * PK 값으로 레코드를 삭제한다. 인자를 생략하면 현재 로드된 레코드의 PK를 사용한다.
     *
     * @param mixed $pKeyValue
     */
    public function deleteRecord($pKeyValue = null): int
    {
        $this->assertInitialized();

        if ($this->primaryKey === null) {
            throw new RuntimeException("테이블에 프라이머리 키가 없습니다: {$this->tableName}");
        }

        if ($pKeyValue === null) {
            $pKeyValue = isset($this->data[$this->primaryKey]) ? $this->data[$this->primaryKey] : null;
        }
        if ($pKeyValue === null) {
            throw new InvalidArgumentException('삭제할 레코드의 PK 값이 없습니다.');
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s = :pk',
            $this->db->dialect()->quoteIdentifier($this->tableName),
            $this->db->dialect()->quoteIdentifier($this->primaryKey)
        );
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['pk' => $pKeyValue]);

        return $stmt->rowCount();
    }

    /**
     * crudjs.js의 saveBulk()가 보내는 다중 레코드 payload를 받아 한 번에
     * 반영한다 — [2026-09-14] `crudjs_fetch.php`의 `saveBulk` case 안에
     * 직접 박혀 있던 로직을 재사용 가능하도록 이 메서드로 추출했다.
     * `t매출_내용`처럼 다중 레코드 쓰기가 필요한 다른 화면(실전 페이지)의
     * fetch 엔드포인트도 자신의 `Crud` 인스턴스로 이 메서드 하나만 부르면
     * 되고, 매번 이 반복문을 복붙할 필요가 없다.
     *
     * `$dirtyIndexes`는 PK가 아니라 **`$rows` 배열 안에서의 인덱스**다 —
     * PK가 아직 없는(=한 번도 저장 안 된) 신규 행도 인덱스로는 항상
     * 가리킬 수 있어야 INSERT를 지원할 수 있기 때문이다(PK를 키로 쓰면
     * PK가 `null`인 신규 행은 식별 자체가 안 된다). `save()`가 PK 유무로
     * 알아서 insert/update를 분기하므로 이 메서드는 그 분기를 신경 쓸
     * 필요가 없다. 반대로 `$deletedPks`는 여전히 PK 배열 그대로다 —
     * 삭제는 "서버에 이미 있는(=PK가 있는) 행"에만 의미가 있다.
     *
     * 행 하나의 실패가 나머지 처리를 막지 않도록 각각 개별 try/catch로
     * 감싼다(`ActionBulk::handle()`과 동일한 원칙). 행마다 `clear()`로
     * 이전 행의 값이 새지 않게 초기화하지만(스키마는 그대로 — `init()`을
     * 다시 안 불러 DB 왕복이 없다), 스키마 자체(화이트리스트 포함)는 호출
     * 전에 이미 `init()`된 상태를 그대로 재사용한다는 전제다.
     *
     * @param array<int, array<string, mixed>> $rows 클라이언트가 보낸 data 배열(원본 그대로)
     * @param array<int, int|string> $dirtyIndexes $rows 안에서의 인덱스 목록
     * @param array<int, int|string> $deletedPks 삭제할 PK 목록
     * @return array{results: array, deleteResults: array}
     */
    public function saveBulk_crudjs(array $rows, array $dirtyIndexes, array $deletedPks): array
    {
        $results = [];
        foreach ($dirtyIndexes as $dirtyIndex) {
            $dirtyIndex = (int) $dirtyIndex;
            $row = isset($rows[$dirtyIndex]) && is_array($rows[$dirtyIndex]) ? $rows[$dirtyIndex] : null;
            if ($row === null) {
                $results[] = ['index' => $dirtyIndex, 'success' => false, 'message' => 'data 배열에서 이 인덱스의 행을 찾을 수 없습니다.'];
                continue;
            }
            try {
                $this->clear();
                $this->loadArray($row);
                $newPk = $this->save();
                $results[] = ['index' => $dirtyIndex, 'success' => true, 'newPk' => (string) $newPk];
            } catch (Throwable $e) {
                $results[] = ['index' => $dirtyIndex, 'success' => false, 'message' => $e->getMessage()];
            }
        }

        $deleteResults = [];
        foreach ($deletedPks as $deletedPk) {
            $deletedPk = (string) $deletedPk;
            try {
                $this->clear();
                $this->deleteRecord($deletedPk);
                $deleteResults[] = ['pk' => $deletedPk, 'success' => true];
            } catch (Throwable $e) {
                $deleteResults[] = ['pk' => $deletedPk, 'success' => false, 'message' => $e->getMessage()];
            }
        }

        return ['results' => $results, 'deleteResults' => $deleteResults];
    }

    // ------------------------------------------------------------------
    // 보조 메서드
    // ------------------------------------------------------------------

    public function getSchema(): array
    {
        return $this->schema;
    }

    public function getPrimaryKey(): ?string
    {
        return $this->primaryKey;
    }

    /** @return array<string, mixed> */
    public function getAllValues(): array
    {
        return $this->data;
    }

    /**
     * 현재 스키마 + 커서 위치 + 적재된 레코드(들)를 JSON 문자열 하나로 직렬화한다.
     * {"schema": {...}, "cursor": -1, "data": [ {...레코드1}, {...레코드2}, ... ]} 형태 —
     * JSON 안에서 schema가 먼저, 그 아래 cursor, 그 아래 data(레코드 배열) 순서로 온다.
     * data는 selectRecords()로 여러 건을 적재했으면 그 전체를 담고, 그게 아니라
     * selectRecord()/setValue() 등으로 레코드 한 건만 다루던 상태라면 그 한 건을
     * 담은 1개짜리 배열이 된다. 둘 다 없으면(초기화만 하고 아무 값도 없으면) 빈 배열.
     * cursor는 현재 커서 위치를 그대로 담는다(-1=BOF, count(data)=EOF, 그 사이=유효 인덱스).
     */
    public function toJson(): string
    {
        $this->assertInitialized();

        if (!empty($this->recordSet)) {
            $records = $this->recordSet;
        } elseif (!empty($this->touchedFields)) {
            $records = [$this->data];
        } else {
            $records = [];
        }

        $payload = [
            'schema' => $this->schema,
            'cursor' => $this->cursor,
            'data'   => $records,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('JSON 직렬화에 실패했습니다: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * toJson()이 만든 JSON 문자열로부터 DB 접속 없이 Crud 객체를 복원한다.
     * schema를 그대로 되살리고(information_schema 조회 없이 — initModelClass()와 같은 원리),
     * data(레코드 배열)를 내부 레코드셋으로 채운 뒤 JSON에 담긴 cursor 위치를 그대로
     * 복원한다(직렬화 시점의 정확한 위치 — BOF/EOF 포함). cursor 키가 없는(구버전) JSON을
     * 만나면 레코드가 있으면 첫 레코드(0), 없으면 BOF/EOF(-1)로 대체한다.
     * 복원 직후에도 bof()/eof()/moveNext() 등으로 그대로 이어서 탐색할 수 있다.
     * $db는 나중에 실제로 insert()/update()/selectRecord() 등 DB 작업을 할 때만 쓰인다 —
     * 복원 자체는 DB에 전혀 접촉하지 않는다(DbContext는 처음 쓸 때 연결).
     */
    public static function fromJson(DbContext $db, string $json): Crud
    {
        $payload = json_decode($json, true);
        if (!is_array($payload) || !isset($payload['schema']) || !isset($payload['data'])) {
            throw new InvalidArgumentException('올바른 Crud JSON 형식이 아닙니다 (schema/data 키가 없음).');
        }

        $schema = $payload['schema'];
        if (!is_array($schema) || !isset($schema['tableName'], $schema['fields']) || !is_array($schema['fields'])) {
            throw new InvalidArgumentException('올바른 스키마 형식이 아닙니다 (tableName/fields 키가 없음).');
        }

        $crud = new self($db);
        $crud->tableName = (string) $schema['tableName'];
        $crud->primaryKey = isset($schema['primaryKey']) ? $schema['primaryKey'] : null;
        $crud->schema = [
            'tableName'  => $crud->tableName,
            'primaryKey' => $crud->primaryKey,
            'fields'     => $schema['fields'],
        ];

        $records = is_array($payload['data']) ? array_values($payload['data']) : [];
        $crud->recordSet = $records;
        $crud->cursor = isset($payload['cursor'])
            ? (int) $payload['cursor']
            : (empty($records) ? -1 : 0);

        if ($crud->cursor >= 0 && $crud->cursor < count($records)) {
            $crud->loadCursorRecord();
        } else {
            $crud->clear();
        }

        return $crud;
    }

    /**
     * [2026-09-14 신설 — resetData()를 public으로 승격 + 개명]
     * 스키마는 그대로 둔 채 현재 레코드 값(`$this->data`)과
     * `$this->touchedFields`만 빈 상태로 되돌린다. `init()`은 이 초기화에
     * 더해 `information_schema`/`sys.*`를 다시 조회해 스키마 전체를 새로
     * 읽어오는데, "스키마는 안 바뀌었고 그냥 다음 레코드를 다루기 전에
     * 상태만 깨끗이 하고 싶다"는 흔한 경우(예: `crudjs_fetch.php`의
     * `saveBulk`가 여러 행을 순회하며 매 행마다 깨끗한 상태로 시작해야
     * 하는 것)에는 그 스키마 재조회가 전부 낭비되는 DB 왕복이다 —
     * `clear()`는 그 재조회 없이 이 부분만 수행한다. `init()`/
     * `initModelClass()`/`selectRecord()`/`loadCursorRecord()`/
     * `fromJson()`도 내부적으로 이 메서드를 그대로 쓴다(스키마를 다시
     * 세운 직후거나, 애초에 스키마 재조회가 필요 없는 지점들).
     */
    public function clear(): void
    {
        $this->data = array_fill_keys(array_keys($this->schema['fields']), null);
        $this->touchedFields = [];
    }

    private function assertInitialized(): void
    {
        if ($this->tableName === null) {
            throw new RuntimeException('init() 을 먼저 호출해야 합니다.');
        }
    }

    private function assertField(string $field): void
    {
        $this->assertInitialized();
        if (!array_key_exists($field, $this->schema['fields'])) {
            throw new InvalidArgumentException("존재하지 않는 필드입니다: {$field}");
        }
    }

    /**
     * INT/DATETIME 등 문자열이 아닌 컬럼에 빈 문자열('')이 그대로 들어가면
     * DB가 타입 오류(예: 1366 Incorrect integer value)를 낸다.
     * nullable 필드에서 값이 ''(폼에서 비워둔 경우)이면 NULL로 바꿔준다.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalizeValueForField(string $field, $value)
    {
        if ($value === '' && $this->schema['fields'][$field]['nullable']) {
            return null;
        }
        return $value;
    }

    /**
     * [2026-09-14 신설] DB 컬럼이 varchar + 비UTF8 콜레이션(한글 환경에서
     * 흔한 CP949 계열)이면 PDO(dblib/FreeTDS)가 돌려주는 문자열이 유효한
     * UTF-8이 아닐 수 있다 — 이 상태로 json_encode()에 넘기면 조용히
     * false를 반환해 응답이 깨진다(실제로 crudjs_fetch.php의
     * selectRecords()에서 "JSON 직렬화에 실패했습니다: Malformed UTF-8
     * characters..."로 발견됨). `SP::sanitizeUtf8()`/`Board::
     * sanitizeUtf8()`와 목적·로직이 완전히 같지만, `Crud`가 `SP`/`Board`
     * 에 의존하게 만들 이유가 없어(반대 방향 의존은 이미 있음 — `Board`가
     * 스키마 추출을 `Crud`에 위임) 그대로 복제해서 둔다. `selectRecord()`/
     * `selectRecords()` — DB에서 값을 읽어오는 두 지점에만 적용한다
     * (insert()/update()로 쓰는 값은 클라이언트의 JSON에서 온 것이라
     * 이미 유효한 UTF-8이라 대상이 아니다).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function sanitizeUtf8Row(array $row): array
    {
        foreach ($row as $field => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (mb_check_encoding($value, 'UTF-8')) {
                continue;
            }
            $converted = @iconv('CP949', 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                $row[$field] = $converted;
            } else {
                $row[$field] = mb_convert_encoding($value, 'UTF-8', 'CP949');
            }
        }
        return $row;
    }
}
