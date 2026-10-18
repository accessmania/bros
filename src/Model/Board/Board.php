<?php
declare(strict_types=1);

require_once __DIR__ . '/../Crud/Crud.php';
require_once __DIR__ . '/../../Str/Str.php';
require_once __DIR__ . '/../SqlDialect/SqlDialect.php';
require_once __DIR__ . '/../../db/DbContext.php';

/**
 * md/Board.md(최초 스펙, 지시 원문)에서 출발한 게시판 클래스 — 지금 시점의
 * 전체 API 요약/사용 가이드는 src/Model/Board/Board.md(코드 옆 참고서)를
 * 본다.
 *
 * 스키마 추출은 Crud 를 내부에서 그대로 재사용한다("crud 처럼 가지고있는다").
 * init() 이 테이블/뷰 스키마 + 페이지 파라미터를 받아 calcBoard() 를 내부에서 호출해
 * 페이저 계산을 끝내두고, loadBoard() 를 호출하면 그 계산을 근거로 실제 레코드를 조회해
 * data(레코드) / pager(페이저) 정보를 하나의 배열($data)에 담는다.
 *
 * 진입점이 3가지다 — init()(테이블/뷰 모드) / initFromQuery()(임의 SELECT 문
 * 하나를 서브쿼리로 감싸는 query 모드) / initFromArray()(이미 PHP 배열로
 * 받은 결과셋을 대상으로 페이지/검색/정렬을 SQL이 아니라 메모리에서 처리하는
 * array 모드, 저장 프로시저 결과 연계용 — Board.md "배열 모드" 참고). 셋 다
 * calcBoard()/loadBoard() 이후의 커서 탐색/URL 헬퍼/렌더링 API는 최대한
 * 동일하게 쓸 수 있지만, query/array 모드는 진짜 테이블이 없어 쓰기(저장/
 * 삭제)와 스키마 의존 렌더링(getDefaultBodyHTML() 등)을 지원하지 않는다.
 *
 * MySQL/MSSQL 겸용: Crud와 마찬가지로 테이블/컬럼 인용, LIMIT/OFFSET 절은
 * SqlDialect로 위임한다(연결과 방언은 생성자에 넘긴 DbContext가 제공).
 *
 * PHP 7 호환 문법만 사용 (union type / mixed / non-capturing catch 등 8.0 전용 문법 미사용).
 */
class Board
{
    /** @var DbContext 연결(pdo())과 방언(dialect())의 공급원 — 내부 Crud와 같은 개체를 공유 */
    private $db;

    /** @var Crud 스키마 추출용 (crud 처럼 스키마를 가지고 있기 위해 내부에서 재사용) */
    private $crud;

    /** @var string|null */
    private $tableName = null;

    /**
     * [2026-08-26 추가 — initFromQuery() 지원] null이면 지금까지와 동일한
     * "테이블/뷰 모드"($tableName 사용). 값이 있으면 "query 모드" — 이
     * raw SELECT 문 하나를 `(원본) AS sub`로 서브쿼리 감싸서 FROM 절로
     * 쓴다(buildFromClause() 참고). $tableName과 동시에 유효할 일은
     * 없다(initFromQuery()가 $tableName을 null로 비움).
     * @var string|null
     */
    private $rawQuery = null;

    /**
     * [2026-08-26 추가] query 모드에서 probeQueryColumns()가 알아낸 컬럼
     * 이름 목록 — 검색/정렬 필드 화이트리스트로 쓴다(resolveAvailableFields()).
     * @var array<int, string>
     */
    private $queryColumns = [];

    /**
     * [2026-08-26 추가] query 모드에서 호출부가 지정한 "키 컬럼"(테이블
     * 모드의 PK 대응) — DB가 알려줄 수 없어(조인/집계 결과라 PK 제약이
     * 없음) initFromQuery()의 인수로 직접 받는다. null이면 'code'로 간주.
     * @var string|null
     */
    private $queryKeyColumn = null;

    /**
     * [2026-09-07 추가 — initFromArray() 지원, 예: SP::execute() 결과 연계]
     * null이면 지금까지와 동일(테이블 모드/query 모드). 값이 있으면 "array
     * 모드" — 이미 PHP 배열로 갖고 있는 결과셋(저장 프로시저 호출 결과 등,
     * SQL로 다시 조회할 방법이 없는 데이터)을 대상으로 페이지/검색/정렬을
     * SQL이 아니라 **PHP 메모리에서** 처리한다. $tableName/$rawQuery와
     * 동시에 유효할 일은 없다(initFromArray()가 둘 다 null로 비운다).
     * @var array<int, array<string, mixed>>|null
     */
    private $sourceRows = null;

    /**
     * [2026-09-07 추가] array 모드에서 호출부가 지정한 "키 컬럼"(query
     * 모드의 $queryKeyColumn과 동일한 이유·동일한 기본값 'code') — 배열은
     * 임의의 조회 결과라 DB가 PK를 알려줄 방법이 없다.
     * @var string|null
     */
    private $arrayKeyColumn = null;

    /**
     * [2026-09-07 추가] calcBoard()가 array 모드에서 검색+정렬까지 미리
     * 끝낸 결과(페이징 전 전체)를 담아두는 캐시 — loadBoard()가 이걸
     * array_slice()만 하면 되도록, 검색/정렬을 두 번 반복 계산하지 않기
     * 위함(테이블/query 모드는 이 캐시가 필요 없다 — SQL이 WHERE/ORDER BY/
     * LIMIT를 한 문장에서 한 번에 처리하므로).
     * @var array<int, array<string, mixed>>
     */
    private $arrayFilteredRows = [];

    /** [2026-09-07 추가] array 모드 검색 대상 필드(resolveArraySearchFields() 결과). @var array<int, string> */
    private $arraySearchFields = [];

    /** [2026-09-07 추가] array 모드 검색어(trim된 원문, SQL 이스케이프 불필요 — PHP 비교라서). @var string */
    private $arrayKeyword = '';

    /** [2026-09-07 추가] array 모드 extra 필터(init()의 $pExtraCondition에 대응하는 PHP 콜러블). @var callable|null */
    private $arrayExtraFilter = null;

    private $page = 1;
    private $pageSize = 10;
    private $pagerGroupSize = 10;

    /** init() 에 "code,이름,나이,..." 형태로 넘어온 표시용 컬럼 목록을 분리해 담아둔다. */
    private $columns = [];

    /** 컬럼명 => 표시 라벨. "code:번호"처럼 라벨을 안 주면 컬럼명 자체가 라벨이 된다. */
    private $columnLabels = [];

    /** init() 3번째 인수로 받은 WHERE 조건(raw SQL, WHERE 키워드 제외). 없으면 빈 문자열(전체). */
    private $condition = '';

    /** init() 5번째 인수로 받은(또는 기본값으로 계산된) ORDER BY 절 내용(ORDER BY 키워드 제외). */
    private $orderBy = '';

    /**
     * 헤더 클릭 정렬이 가능한 상태인지. pOrderBy 를 명시적으로 넘기면(호출자가 정렬을
     * 고정) false — 이 경우 getDefaultBodyHTML() 이 헤더를 링크로 만들지 않는다.
     */
    private $sortable = true;

    /** 현재 정렬 기준 필드명(스키마에 실제 존재하는 컬럼만). 없으면 null. */
    private $sortField = null;

    /** 현재 정렬 방향. 'ASC' 또는 'DESC'. */
    private $sortDir = 'ASC';

    // calcBoard() 계산 결과
    private $totalRows = 0;
    private $totalPages = 0;
    private $offset = 0;
    private $startPage = 1;
    private $endPage = 1;

    /**
     * loadBoard() 결과. {"records": [...], "pager": {...}} 형태.
     * @var array{records: array<int, array<string, mixed>>, pager: array<string, mixed>}
     */
    private $data = ['records' => [], 'pager' => []];

    /** records 배열을 옮겨다니는 커서. -1=BOF, count(records)=EOF, 그 사이=유효 인덱스. */
    private $dataCursor = -1;

    /** pager.pages 배열(현재 그룹의 페이지번호 목록)을 옮겨다니는 커서. */
    private $pageCursor = -1;

    /**
     * GET/POST 파라미터 이름 앞에 붙이는 접두어. 비어있으면(기본값) page/sort/dir/
     * pageSize/groupSize/q를 그대로 쓴다 — 지금까지의 모든 화면(1_1~1_4, 2_1)이
     * 이 경우라 동작이 안 바뀐다. 한 페이지에 게시판을 여러 개 두려면, 게시판마다
     * 다른 접두어를 주면 된다(예: new Board($db, 'b1_'), new Board($db, 'b2_')) —
     * 그러면 이 인스턴스가 읽고 쓰는 키가 b1_page/b1_sort/... 처럼 서로 안 겹친다.
     * BoardConfig::$paramPrefix와 같은 값을 주면, 화면단(진입 파일)이
     * $config->paramName()으로 만드는 code/return 파라미터 이름과도 짝이 맞는다.
     */
    private $paramPrefix = '';

    public function __construct(DbContext $db, string $pParamPrefix = '')
    {
        $this->db = $db;
        $this->crud = new Crud($db);
        $this->paramPrefix = $pParamPrefix;
    }

    /** 이 게시판 인스턴스가 쓰는 파라미터 접두어. 화면단이 직접 GET 키를 조립할 때 참고용. */
    public function getParamPrefix(): string
    {
        return $this->paramPrefix;
    }

    /**
     * 1. init(pTableOrView, pColumns, pSearchFields, pKeyword, pOrderBy, pPage, pPageSize, pPagerGroupSize)
     *
     * 게시판에 쓰일 테이블/뷰의 스키마를 Crud::init() 으로 추출해 가지고 있고,
     * 현재 페이지번호/게시글 행수/페이저 그룹크기를 받아 calcBoard() → loadBoard() 를
     * 순서대로 호출해서, init() 한 번으로 바로 레코드/페이저 조회까지 끝낸다.
     *
     * $pPage/$pPageSize/$pPagerGroupSize 는 생략(null)하면 resolveIntParam() 이 대신
     * $_GET → $_POST 순서로 같은 이름의 값을 조사해서 채운다(둘 다 없으면 기본값 사용).
     * 명시적으로 값을 넘기면 그 값을 그대로 쓰고 GET/POST는 조사하지 않는다.
     *
     * $pColumns 는 화면에 표시할 컬럼을 "code,이름,나이,신장,몸무게" 처럼 콤마로 구분한
     * 문자열로 받는다(배열이 아님). 각 항목은 "컬럼명" 또는 "컬럼명:라벨" 형태 —
     * 라벨을 안 주면 컬럼명 자체가 라벨이 된다(예: "code:번호,이름,나이"면 code는
     * "번호"로 표시되고 이름/나이는 그대로 표시됨). getArrayColumns() 로 컬럼명 배열을,
     * getArrayColumnLabels() 로 컬럼명=>라벨 배열을 꺼내 쓴다. 빈 문자열이면(생략 시)
     * 둘 다 빈 배열을 반환한다.
     *
     * $pSearchFields 는 검색 대상 필드를 "이름,나이" 처럼 콤마로 구분한 문자열로 받는다.
     * 비어있으면(생략 시) 전체 필드(스키마의 모든 컬럼)가 검색 대상이 된다. 스키마에
     * 없는 필드명은 조용히 무시된다(전부 무시되면 결과적으로 전체 필드로 처리됨).
     * $pKeyword 는 실제 검색어. 비어있으면(생략 시) 검색 자체를 안 해서 조건 없이
     * 테이블 전체를 대상으로 한다 — $pSearchFields 값과 무관하게 $pKeyword가 없으면
     * 필터링이 아예 안 걸린다. 검색 대상 필드마다 `field` LIKE '%keyword%' 를 OR로
     * 묶어서 WHERE 조건을 만든다(내부에서 PDO::quote() 로 안전하게 이스케이프 — 호출하는
     * 쪽은 그냥 원문 검색어만 넘기면 됨, Crud::selectRecords() 의 $pWhere 와 달리 값
     * 바인딩/이스케이프를 Board가 대신 처리함). 최종적으로 조립된 WHERE 조건 원문은
     * getWhereString() 으로 확인할 수 있다.
     *
     * $pOrderBy 는 ORDER BY 절 내용을 raw SQL 문자열로 받는다(ORDER BY 키워드 제외).
     * 비어있으면(생략 시) 프라이머리 키 기준 내림차순("`pk` DESC")을 기본값으로 쓴다
     * (프라이머리 키가 없는 테이블/뷰면 정렬 없음).
     *
     * $pExtraCondition(9번째, 2026-08-26 추가) 은 검색어 조건과 별개로 항상 AND로
     * 덧붙는 raw SQL 조건이다(예: "`팀장` = 1" 처럼 체크박스/라디오 필터를 표현할 때).
     * 검색어 조건과 둘 다 있으면 `(검색조건) AND (extra조건)`으로 묶인다. 기존
     * 호출부는 이 인수를 안 넘기면(기본값 '') 지금까지와 동작이 완전히 같다 —
     * Crud::selectRecords() 의 $pWhere 와 같은 관례로 raw SQL이라 호출하는 쪽이
     * 안전하게 조립한 문자열만 넘겨야 한다(사용자 입력을 직접 연결하지 않는다).
     */
    public function init(
        string $pTableOrView,
        string $pColumns = '',
        string $pSearchFields = '',
        string $pKeyword = '',
        string $pOrderBy = '',
        ?int $pPage = null,
        ?int $pPageSize = null,
        ?int $pPagerGroupSize = null,
        string $pExtraCondition = '',
        string $pDefaultOrderBy = ''
    ): void {
        $this->crud->init($pTableOrView);
        $this->tableName = $pTableOrView;
        // initFromQuery()/initFromArray()를 먼저 썼던 인스턴스를 재사용하는
        // 경우를 대비한 방어적 초기화(2026-08-26 추가, 2026-09-07 sourceRows
        // 추가) — 보통은 요청마다 새 Board를 만들어 쓰므로 실제로 이 경로를
        // 타는 경우는 드물다.
        $this->rawQuery = null;
        $this->sourceRows = null;

        $searchCondition = $this->resolveCondition($pSearchFields, $pKeyword);
        $this->condition = $this->combineConditions($searchCondition, $pExtraCondition);
        $this->orderBy = $this->resolveOrderBy($pOrderBy, $pDefaultOrderBy);

        $this->page = $this->resolveIntParam($pPage, 'page', 1);
        $this->pageSize = $this->resolveIntParam($pPageSize, 'pageSize', 5);
        $this->pagerGroupSize = $this->resolveIntParam($pPagerGroupSize, 'groupSize', 2);

        $this->columns = [];
        $this->columnLabels = [];
        if (trim($pColumns) !== '') {
            foreach (explode(',', $pColumns) as $entry) {
                $entry = trim($entry);
                if ($entry === '') {
                    continue;
                }
                // "컬럼명" 또는 "컬럼명:라벨" 형태.
                $parts = explode(':', $entry, 2);
                $column = trim($parts[0]);
                if ($column === '') {
                    continue;
                }
                $label = isset($parts[1]) ? trim($parts[1]) : '';
                $this->columns[] = $column;
                $this->columnLabels[$column] = $label !== '' ? $label : $column;
            }
        }

        $this->data = ['records' => [], 'pager' => []];
        $this->dataCursor = -1;
        $this->pageCursor = -1;

        $this->calcBoard();
        $this->loadBoard();
    }

    /**
     * [2026-08-26 추가] `init()`이 실제 테이블/뷰 이름을 받는 것과 달리,
     * 임의의 SELECT 문 하나($pRawQuery)를 대상으로 게시판을 연다 —
     * "저장프로시저의 결과 select 문도 aggrid 연동이 되나?"/"임의의
     * select 문장을 제시하면 쓰기제외로 표시하기가 되나?" 논의(2026-08-26,
     * [[2026-08-26-tlogin-로그인-Login-Auth]] 참고)의 결론을 그대로
     * 구현한 것 — 페이징/검색/정렬/extraCondition은 `init()`과 동일하게
     * 지원하되, 내부적으로 $pRawQuery를 `(원본) AS sub` 서브쿼리로 감싸서
     * 적용한다(buildFromClause() 참고).
     *
     * **쓰기(저장/삭제)는 이 경로로 아예 지원하지 않는다** — `$this->crud`를
     * 전혀 초기화하지 않으므로(실제 테이블 이름을 모름) `ActionBulk`/
     * `Crud::update()` 등 쓰기 경로와는 애초에 연결되지 않는다. 화면단이
     * AGGrid를 조회 전용으로 띄우는 건 화면 쪽 책임(예: 편집/삭제/행추가
     * 버튼을 끄는 옵션)이다.
     *
     * **키 컬럼($pKeyColumn)은 DB가 알려줄 수 없다** — 조인/집계 결과에는
     * "이 결과의 기본키"라는 제약 자체가 없기 때문에, `init()`처럼
     * `Crud::getPrimaryKey()`로 자동 판별하는 대신 호출부가 "이 SELECT
     * 결과에서 어느 컬럼이 각 행을 고유하게 식별하는지" 직접 알려준다.
     * `null`이면(생략) 이 프로젝트 테이블들의 관례대로 `'code'`로 간주한다.
     * 이 값이 실제로 유일한 값을 갖는지는 프레임워크가 검증할 수 없으므로
     * (임의 쿼리라 보장할 방법이 없음) 정확한 값을 넘기는 게 호출부의
     * 책임이다 — 잘못되면 AGGrid에서 행 식별자가 겹쳐 화면에 자료가
     * 제대로 안 보일 수 있다(과거 "PK 없는 테이블" 버그와 같은 증상).
     *
     * 컬럼 목록은 `probeQueryColumns()`가 0건 프로브 쿼리로 알아낸다 —
     * `Crud::init()`이 `INFORMATION_SCHEMA.COLUMNS`로 하는 일의 query
     * 모드 대응.
     */
    public function initFromQuery(
        string $pRawQuery,
        string $pSearchFields = '',
        string $pKeyword = '',
        string $pOrderBy = '',
        ?int $pPage = null,
        ?int $pPageSize = null,
        ?int $pPagerGroupSize = null,
        string $pExtraCondition = '',
        string $pDefaultOrderBy = '',
        ?string $pKeyColumn = null
    ): void {
        $this->tableName = null;
        $this->rawQuery = trim($pRawQuery);
        $this->sourceRows = null;
        $this->queryKeyColumn = $pKeyColumn;
        if ($this->queryKeyColumn === null) {
            $this->queryKeyColumn = 'code';
        }
        $this->queryColumns = $this->probeQueryColumns($this->rawQuery);

        $searchCondition = $this->resolveCondition($pSearchFields, $pKeyword);
        $this->condition = $this->combineConditions($searchCondition, $pExtraCondition);
        $this->orderBy = $this->resolveOrderBy($pOrderBy, $pDefaultOrderBy);

        $this->page = $this->resolveIntParam($pPage, 'page', 1);
        $this->pageSize = $this->resolveIntParam($pPageSize, 'pageSize', 5);
        $this->pagerGroupSize = $this->resolveIntParam($pPagerGroupSize, 'groupSize', 2);

        $this->columns = [];
        $this->columnLabels = [];

        $this->data = ['records' => [], 'pager' => []];
        $this->dataCursor = -1;
        $this->pageCursor = -1;

        $this->calcBoard();
        $this->loadBoard();
    }

    /**
     * [2026-09-07 추가 — SP::execute() 등 이미 PHP 배열로 받은 결과셋을
     * 게시판으로 표현하는 진입점] `initFromQuery()`가 임의 SELECT 문
     * 하나를 대상으로 하는 것과 달리, **SQL로 다시 조회할 방법이 없는
     * 데이터**(저장 프로시저 호출 결과가 대표적 — MSSQL은 `EXEC`를
     * 서브쿼리 안에 못 넣어 `initFromQuery()`의 "(원본) AS sub" 방식이
     * 애초에 안 통한다)를 대상으로 한다. `$pRows`는 이미 전량이 PHP
     * 배열로 넘어와 있으므로, 페이지/검색/정렬을 SQL 푸시다운이 아니라
     * **PHP 메모리**에서 처리한다 — 총건수도 `SELECT COUNT(*)`를 다시
     * 묻지 않고 `count($pRows)`(필터 적용 후엔 필터링된 결과의 count)로
     * 곧바로 안다(src/Model/SP/sp.md, src/Model/Board/Board.md "배열 모드"
     * 참고).
     *
     * `initFromQuery()`와 동일하게 **쓰기(저장/삭제)는 이 경로로 아예
     * 지원하지 않는다** — `$this->crud`를 전혀 초기화하지 않는다.
     *
     * $pSearchFields/$pKeyword — `init()`/`initFromQuery()`와 동일한 뜻
     * (검색 대상 필드 콤마 목록 + 검색어)이지만, 내부적으로 SQL LIKE가
     * 아니라 `mb_stripos()` 기반 PHP 부분일치로 필터링한다.
     *
     * $pOrderBy/$pDefaultOrderBy — `resolveOrderBy()`를 그대로 재사용해
     * $sortField/$sortDir을 계산하지만(헤더 클릭 정렬과 완전히 동일하게
     * 동작), 그 메서드가 만드는 SQL ORDER BY 문자열 자체는 배열 모드에서
     * 쓰지 않는다(참고용으로 $this->orderBy에 보관만 됨) — 실제 정렬은
     * $sortField/$sortDir로 `usort()`를 한다. 여러 컬럼을 섞은 raw SQL
     * 정렬(예: `"세트메뉴 DESC, 상품명 ASC"`)을 $pDefaultOrderBy로 주면
     * $sortField가 null로 남아(resolveOrderBy()의 기존 규칙) 정렬 자체를
     * 건너뛴다 — 다중 컬럼 정렬은 이 배열 모드에서 지원하지 않는다는 뜻.
     *
     * $pExtraFilter(선택) — `init()`/`initFromQuery()`의 $pExtraCondition
     * (raw SQL)에 대응하는 배열 모드 버전. SQL 조건 문자열이 아니라 **PHP
     * 콜러블** `function (array $row): bool`을 받는다 — 검색어 조건과
     * 함께(AND) 적용된다.
     *
     * $pKeyColumn — `initFromQuery()`의 $pKeyColumn과 동일한 이유(DB가
     * 알려줄 방법이 없어 호출부가 직접 지정). null이면 'code'로 간주.
     *
     * 컬럼 목록은 `probeQueryColumns()`(0건 프로브 쿼리) 없이
     * `array_keys($pRows[0] ?? [])`로 즉시 구한다 — 이미 데이터가 손에
     * 있어 쿼리 모드보다 오히려 더 단순하다.
     */
    public function initFromArray(
        array $pRows,
        string $pSearchFields = '',
        string $pKeyword = '',
        string $pOrderBy = '',
        ?int $pPage = null,
        ?int $pPageSize = null,
        ?int $pPagerGroupSize = null,
        ?callable $pExtraFilter = null,
        string $pDefaultOrderBy = '',
        ?string $pKeyColumn = null
    ): void {
        $this->tableName = null;
        $this->rawQuery = null;
        $this->sourceRows = array_values($pRows);
        $this->arrayKeyColumn = $pKeyColumn !== null ? $pKeyColumn : 'code';
        $this->arrayExtraFilter = $pExtraFilter;
        $this->arraySearchFields = $this->resolveArraySearchFields($pSearchFields);
        $this->arrayKeyword = trim($pKeyword);

        // 실제 SQL은 없지만, getWhereString()이 "지금 검색어가 걸려있는지"를
        // 참고용으로나마 보여줄 수 있도록 설명 문자열만 남긴다(실행되는
        // SQL이 아님을 Board.md에 명시).
        $this->condition = $this->arrayKeyword !== '' ? '(array mode) ' . $this->arrayKeyword : '';

        $this->orderBy = $this->resolveOrderBy($pOrderBy, $pDefaultOrderBy);

        $this->page = $this->resolveIntParam($pPage, 'page', 1);
        $this->pageSize = $this->resolveIntParam($pPageSize, 'pageSize', 5);
        $this->pagerGroupSize = $this->resolveIntParam($pPagerGroupSize, 'groupSize', 2);

        $this->columns = [];
        $this->columnLabels = [];

        $this->data = ['records' => [], 'pager' => []];
        $this->dataCursor = -1;
        $this->pageCursor = -1;

        $this->calcBoard();
        $this->loadBoard();
    }

    /**
     * $rawQuery를 `SELECT * FROM (원본) AS sub WHERE 1 = 0`로 감싸 실제
     * 데이터 없이 컬럼 이름만 알아낸다(PDOStatement::getColumnMeta()).
     * MSSQL/MySQL 둘 다 이 형태의 0건 서브쿼리를 지원한다.
     *
     * public — `initFromQuery()`가 내부적으로 쓰지만, 화면(config_*.php)이
     * AGGrid `columns` 정의를 만들 때 데이터 조회 없이 컬럼 이름만 필요한
     * 경우에도 이것만 따로 호출할 수 있다(`Crud::init()` 하나로 스키마
     * 조회와 데이터 조회 둘 다 되는 테이블 모드와 달리, query 모드는 이
     * 메서드가 그 "스키마 조회" 역할을 담당).
     *
     * @return array<int, string>
     */
    public function probeQueryColumns(string $rawQuery): array
    {
        $sql = sprintf('SELECT * FROM (%s) AS sub WHERE 1 = 0', $rawQuery);
        $stmt = $this->db->pdo()->query($sql);
        if ($stmt === false) {
            return [];
        }

        $columns = [];
        $columnCount = $stmt->columnCount();
        for ($i = 0; $i < $columnCount; $i++) {
            $meta = $stmt->getColumnMeta($i);
            if ($meta !== false && isset($meta['name'])) {
                $columns[] = $meta['name'];
            }
        }
        return $columns;
    }

    /**
     * 검색 대상 필드 문자열 + 키워드로부터 WHERE 조건을 조립한다. 키워드가 비어있으면
     * 검색을 아예 안 하므로 빈 문자열(조건 없음)을 반환한다. 필드 문자열이 비어있거나
     * 스키마에 없는 필드만 있으면 전체 필드를 검색 대상으로 쓴다. 각 필드마다
     * `field` LIKE '%keyword%' 를 만들어 OR로 묶는다. 값은 PDO::quote() 로 이스케이프.
     */
    private function resolveCondition(string $pSearchFields, string $pKeyword): string
    {
        $keyword = trim($pKeyword);
        if ($keyword === '') {
            return '';
        }

        $schemaFields = $this->resolveAvailableFields();

        $fields = [];
        if (trim($pSearchFields) !== '') {
            foreach (explode(',', $pSearchFields) as $field) {
                $field = trim($field);
                if ($field !== '' && in_array($field, $schemaFields, true)) {
                    $fields[] = $field;
                }
            }
        }

        if (empty($fields)) {
            $fields = $schemaFields;
        }

        $quoted = $this->db->pdo()->quote('%' . $keyword . '%');

        $conditions = [];
        foreach ($fields as $field) {
            $conditions[] = sprintf('%s LIKE %s', $this->db->dialect()->quoteIdentifier($field), $quoted);
        }

        return implode(' OR ', $conditions);
    }

    /**
     * 검색어 조건과 init() 9번째 인수(extra 조건)를 AND로 묶는다. 둘 중 하나만
     * 있으면 그것만 그대로 반환(불필요한 괄호 안 씌움), 둘 다 있으면
     * "(a) AND (b)"로, 둘 다 없으면 빈 문자열(=조건 없음).
     */
    private function combineConditions(string $a, string $b): string
    {
        if ($a === '') {
            return $b;
        }
        if ($b === '') {
            return $a;
        }
        return '(' . $a . ') AND (' . $b . ')';
    }

    /**
     * 정렬 정보를 해석한다. 명시적으로 주면(pOrderBy 비어있지 않음) 그대로 ORDER BY 절
     * 내용으로 쓰고 — 이 경우 헤더 클릭 정렬은 비활성화($sortable=false, 정렬 필드를
     * SQL 문자열만으로는 알 수 없어서 헤더에 어떤 화살표를 붙일지 판단 불가).
     *
     * 명시값이 없으면(생략) 헤더 클릭이 남긴 sort/dir 을 $_GET → $_POST 순서로 조사한다.
     * sort가 스키마에 없는 필드명이면 무시. sort가 없거나 무시됐으면 $pDefaultOrderBy가
     * 있으면 그걸(여러 컬럼도 가능, 예: "세트메뉴 DESC, 상품명 ASC") 그대로 기본 정렬로
     * 쓰고(헤더 클릭 정렬은 여전히 켜진 채로 남는다 — $pOrderBy와 달리 "화면을 처음 열 때만"
     * 적용되는 기본값일 뿐, 사용자가 헤더를 클릭하면 그 결과가 우선함), 그것도 없으면
     * 프라이머리 키 기준 내림차순을 기본 정렬로 쓴다(프라이머리 키가 없으면 정렬 없음).
     * 이 결과를 $sortField/$sortDir 에 저장해 getDefaultBodyHTML() 이 헤더 링크/화살표를
     * 만들 때 쓴다(단, $pDefaultOrderBy를 그대로 쓴 경우는 여러 컬럼/방향이 섞인 raw SQL이라
     * 특정 필드 하나로 표현할 수 없으므로 $sortField는 null로 남는다 — 헤더 화살표는 안
     * 켜지지만 정렬 자체는 정상 적용됨).
     */
    private function resolveOrderBy(string $pOrderBy, string $pDefaultOrderBy = ''): string
    {
        $orderBy = trim($pOrderBy);
        if ($orderBy !== '') {
            $this->sortable = false;
            $this->sortField = null;
            return $orderBy;
        }

        $this->sortable = true;

        $schemaFields = $this->resolveAvailableFields();

        $sortKey = $this->paramPrefix . 'sort';
        $dirKey = $this->paramPrefix . 'dir';

        $sortField = null;
        if (isset($_GET[$sortKey]) && $_GET[$sortKey] !== '') {
            $sortField = (string) $_GET[$sortKey];
        } elseif (isset($_POST[$sortKey]) && $_POST[$sortKey] !== '') {
            $sortField = (string) $_POST[$sortKey];
        }
        if ($sortField !== null && !in_array($sortField, $schemaFields, true)) {
            $sortField = null;
        }

        if ($sortField === null && trim($pDefaultOrderBy) !== '') {
            $this->sortField = null;
            $this->sortDir = null;
            return trim($pDefaultOrderBy);
        }

        if ($sortField === null) {
            $sortField = $this->resolveDefaultKeyColumn();
            $sortDir = 'DESC';
        } else {
            $dirRaw = '';
            if (isset($_GET[$dirKey])) {
                $dirRaw = strtoupper((string) $_GET[$dirKey]);
            } elseif (isset($_POST[$dirKey])) {
                $dirRaw = strtoupper((string) $_POST[$dirKey]);
            }
            $sortDir = $dirRaw === 'DESC' ? 'DESC' : 'ASC';
        }

        $this->sortField = $sortField;
        $this->sortDir = $sortDir;

        if ($sortField === null) {
            return '';
        }

        return sprintf('%s %s', $this->db->dialect()->quoteIdentifier($sortField), $sortDir);
    }

    /**
     * [2026-08-26 추가 — initFromQuery() 지원] 검색/정렬 필드 화이트리스트로
     * 쓸 "이 게시판이 다루는 컬럼 이름 목록"을 반환한다. 테이블 모드면
     * `Crud`가 조회해둔 실제 스키마, query 모드(initFromQuery())면
     * `probeQueryColumns()`가 0건 프로브로 알아낸 컬럼 목록 — 둘 중 어느
     * 쪽인지는 $rawQuery 유무로 판단한다.
     *
     * @return array<int, string>
     */
    private function resolveAvailableFields(): array
    {
        if ($this->sourceRows !== null) {
            return array_keys($this->sourceRows[0] ?? []);
        }
        if ($this->rawQuery !== null) {
            return $this->queryColumns;
        }
        return array_keys($this->crud->getSchema()['fields']);
    }

    /**
     * [2026-08-26 추가 — initFromQuery() 지원] 헤더 클릭 정렬이 없을 때
     * 기본 정렬 기준으로 쓸 "키 컬럼"을 반환한다. 테이블 모드는 지금까지와
     * 동일하게 `Crud::getPrimaryKey()`(DB가 알려준 진짜 PK, 없으면 null).
     * query 모드는 DB가 알려줄 방법이 없어(조인/집계 결과에는 PK 제약이라는
     * 게 없음) `initFromQuery()`가 호출부에게 직접 받은 값(`$queryKeyColumn`,
     * 안 주면 'code'로 간주)을 그대로 쓴다 — 이 값이 실제로 유일한 값을
     * 갖는지는 프레임워크가 검증할 수 없으므로 호출부의 책임이다.
     */
    private function resolveDefaultKeyColumn(): ?string
    {
        if ($this->sourceRows !== null) {
            return $this->arrayKeyColumn;
        }
        if ($this->rawQuery !== null) {
            return $this->queryKeyColumn;
        }
        return $this->crud->getPrimaryKey();
    }

    /**
     * [2026-09-07 추가] initFromArray()의 검색 대상 필드를 계산한다.
     * resolveCondition()의 필드 화이트리스트 로직(콤마 목록 파싱 →
     * resolveAvailableFields()로 검증 → 비어있으면 전체 필드)과 동일한
     * 규칙이지만, SQL LIKE 조건 문자열을 만드는 대신 필드 이름 배열만
     * 반환한다(실제 매칭은 filterArrayRows()가 PHP로 한다).
     *
     * @return array<int, string>
     */
    private function resolveArraySearchFields(string $pSearchFields): array
    {
        $available = $this->resolveAvailableFields();

        $fields = [];
        if (trim($pSearchFields) !== '') {
            foreach (explode(',', $pSearchFields) as $field) {
                $field = trim($field);
                if ($field !== '' && in_array($field, $available, true)) {
                    $fields[] = $field;
                }
            }
        }

        if (empty($fields)) {
            $fields = $available;
        }

        return $fields;
    }

    /**
     * [2026-09-07 추가] $this->sourceRows에 검색어(부분일치, 대상 필드는
     * $this->arraySearchFields) + extra 필터($this->arrayExtraFilter)를
     * 적용한다. 키워드가 비어있으면 검색은 건너뛰고 extra 필터만 적용한다
     * (resolveCondition()이 키워드 없으면 조건 자체를 안 만드는 것과 동일한
     * 원칙).
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterArrayRows(): array
    {
        $rows = $this->sourceRows ?? [];
        $keyword = $this->arrayKeyword;
        $fields = $this->arraySearchFields;
        $extraFilter = $this->arrayExtraFilter;

        if ($keyword === '' && $extraFilter === null) {
            return $rows;
        }

        $filtered = [];
        foreach ($rows as $row) {
            if ($keyword !== '') {
                $matched = false;
                foreach ($fields as $field) {
                    if (isset($row[$field]) && mb_stripos((string) $row[$field], $keyword) !== false) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
            }
            if ($extraFilter !== null && !$extraFilter($row)) {
                continue;
            }
            $filtered[] = $row;
        }

        return $filtered;
    }

    /**
     * [2026-09-07 추가] $rows를 $this->sortField/$this->sortDir(둘 다
     * resolveOrderBy()가 이미 세팅해둔 값 — 헤더 클릭 정렬과 동일한 규칙)
     * 기준으로 정렬한다. $sortField가 null이면(다중 컬럼 raw SQL을
     * $pDefaultOrderBy로 준 경우 등) 정렬을 건너뛰고 원본 순서 그대로
     * 반환한다.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function sortArrayRows(array $rows): array
    {
        if ($this->sortField === null) {
            return $rows;
        }

        $field = $this->sortField;
        $dirFactor = $this->sortDir === 'DESC' ? -1 : 1;

        usort($rows, function (array $a, array $b) use ($field, $dirFactor) {
            $av = isset($a[$field]) ? $a[$field] : null;
            $bv = isset($b[$field]) ? $b[$field] : null;
            if ($av == $bv) {
                return 0;
            }
            return ($av < $bv ? -1 : 1) * $dirFactor;
        });

        return $rows;
    }

    /**
     * $field 를 기준으로 $dir 방향으로 정렬하도록 이동하는 쿼리스트링을 만든다.
     * 현재 요청의 다른 $_GET 파라미터(검색어 등)는 유지한 채 sort/dir/page(1로 리셋)/
     * pageSize/groupSize만 덮어쓴다. 정렬이 바뀌면 결과 순서가 통째로 바뀌므로 1페이지로
     * 되돌린다. getDefaultBodyHTML() 이 클릭 가능한 헤더 링크를 만들 때 쓴다.
     */
    public function getSortUrl(string $field, string $dir): string
    {
        $params = $_GET;
        $params[$this->paramPrefix . 'sort'] = $field;
        $params[$this->paramPrefix . 'dir'] = $dir;
        $params[$this->paramPrefix . 'page'] = 1;
        $params[$this->paramPrefix . 'pageSize'] = $this->pageSize;
        $params[$this->paramPrefix . 'groupSize'] = $this->pagerGroupSize;

        return '?' . http_build_query($params);
    }

    /**
     * makeUrl()이 $whitelist를 생략했을 때 쓰는 기본 화이트리스트 — 지금까지 모든
     * 화면이 "목록 상태"로 취급해온 6개 키. 예전엔 getReturnQuery()/buildReturnUrl()
     * 두 곳에 이 배열이 그대로 중복돼 있었다 — 이제 한 곳(이 상수)에만 있고,
     * 화면단이 다른 화이트리스트가 필요하면 makeUrl() 호출 시 직접 넘기면 된다.
     *
     * @var array<int, string>
     */
    public const DEFAULT_QUERY_WHITELIST = ['q', 'page', 'pageSize', 'groupSize', 'sort', 'dir'];

    /**
     * 예전의 getReturnQuery()(인스턴스, 현재 $_GET을 화이트리스트로 압축)와
     * buildReturnUrl()(static, 전달받은 쿼리문자열을 화이트리스트로 걸러 URL에
     * 붙임)을 하나로 합친 것 — 둘 다 "파라미터 소스를 화이트리스트로 거른다"는
     * 같은 일을 하고, 차이는 소스가 $_GET이냐 전달받은 문자열이냐, 결과를
     * 쿼리문자열로만 줄지 URL까지 붙여줄지뿐이었다. static인 이유는 예전
     * buildReturnUrl()과 같다 — write.php/action.php처럼 Board 인스턴스를 아예
     * 안 만드는 처리 전용 페이지에서도 목록으로 되돌아가는 URL을 만들어야 해서다.
     *
     * $source — null이면 현재 $_GET을 읽는다(예전 getReturnQuery() 자리).
     *   문자열이면(빈 문자열 포함) parse_str()로 그 값을 해석한다(예전
     *   buildReturnUrl()의 $rawQuery 자리) — 빈 문자열은 파싱 결과가 빈 배열이라
     *   "돌아갈 상태가 없으면 $baseUrl을 그대로 반환"이 별도 분기 없이 성립한다.
     * $baseUrl — null이면 걸러낸 쿼리문자열만 반환(예전 getReturnQuery()의
     *   반환값과 동일, 앞에 "?" 없음). 주어지면 appendQuery()로 그 URL에 붙여
     *   전체 URL을 반환(예전 buildReturnUrl()의 반환값과 동일).
     * $whitelist — 남길 파라미터 이름(접두어 제외) 목록. 생략(null)하면
     *   self::DEFAULT_QUERY_WHITELIST를 쓴다.
     * $paramPrefix — 파라미터 이름 앞의 접두어. $source를 압축한 쪽과 다시 푸는
     *   쪽이 같은 접두어를 써야 한다(BoardConfig::$paramPrefix와 동일한 값).
     */
    public static function makeUrl(?string $source, ?string $baseUrl = null, ?array $whitelist = null, string $paramPrefix = ''): string
    {
        $whitelist = $whitelist ?? self::DEFAULT_QUERY_WHITELIST;

        if ($source === null) {
            $params = $_GET;
        } else {
            parse_str($source, $params); //쿼리스트링을 연관배열로 바꿔주는 함수
        }

        $allowed = [];
        foreach ($whitelist as $key) {
            $paramKey = $paramPrefix . $key;
            if (isset($params[$paramKey]) && $params[$paramKey] !== '') {
                $allowed[$paramKey] = $params[$paramKey];
            }
        }

        if ($baseUrl === null) {
            return http_build_query($allowed);
        }

        return self::appendQuery($baseUrl, $allowed);
    }

    /**
     * $baseUrl에 $params를 쿼리스트링으로 이어붙인다. $baseUrl이 이미 `?...`를
     * 갖고 있으면(예: Layout 메뉴에 붙은 게시판의 "index.php?top=1&left=1"처럼
     * listUrl/viewUrl 자체에 쿼리스트링이 포함된 경우) `&`로, 없으면 `?`로 이어붙인다
     * — 단순히 항상 `?`를 붙이면 "index.php?top=1&left=1?code=8"처럼 `?`가 두 번
     * 들어가는 잘못된 URL이 만들어지기 때문. makeUrl()과 ActionCrud가
     * 공통으로 사용한다.
     *
     * @param array<string, mixed> $params
     */
    public static function appendQuery(string $baseUrl, array $params): string
    {
        if ($params === []) {
            return $baseUrl;
        }

        $separator = strpos($baseUrl, '?') !== false ? '&' : '?';

        return $baseUrl . $separator . http_build_query($params);
    }

    /**
     * page/pageSize/pagerGroupSize 값을 해석한다.
     * $pExplicit 를 명시적으로 넘겼으면(null이 아니면) 그 값을 그대로 쓰고(1 미만이면 1로
     * 보정), 생략(null)했으면 $_GET[$pKey] → $_POST[$pKey] 순서로 조사해서 있으면 그 값을,
     * 둘 다 없으면 $pDefault 를 쓴다(마찬가지로 1 미만이면 1로 보정).
     */
    private function resolveIntParam(?int $pExplicit, string $pKey, int $pDefault): int
    {
        if ($pExplicit !== null) {
            return $pExplicit >= 1 ? $pExplicit : 1;
        }

        $paramKey = $this->paramPrefix . $pKey;
        if (isset($_GET[$paramKey]) && $_GET[$paramKey] !== '') {
            $value = (int) $_GET[$paramKey];
        } elseif (isset($_POST[$paramKey]) && $_POST[$paramKey] !== '') {
            $value = (int) $_POST[$paramKey];
        } else {
            $value = $pDefault;
        }

        return $value >= 1 ? $value : 1;
    }

    /**
     * init() 에 "code,이름,나이,..." 문자열로 넘긴 표시용 컬럼 목록을 배열로 반환한다.
     * 컬럼을 안 넘겼으면 빈 배열.
     *
     * @return array<int, string>
     */
    public function getArrayColumns(): array
    {
        return $this->columns;
    }

    /**
     * init() 에 "code:번호,이름,..." 문자열로 넘긴 컬럼별 표시 라벨을 컬럼명=>라벨
     * 배열로 반환한다. 라벨을 안 준 컬럼은 컬럼명 자체가 라벨로 들어있다.
     *
     * @return array<string, string>
     */
    public function getArrayColumnLabels(): array
    {
        return $this->columnLabels;
    }

    /**
     * init() 의 검색 대상 필드(3번째 인수) + 키워드(4번째 인수)로부터 조립된 최종
     * WHERE 조건(raw SQL, WHERE 키워드 제외)을 반환한다. 키워드를 안 줬으면(전체 조회)
     * 빈 문자열.
     *
     * [2026-09-07 추가] array 모드(initFromArray())에서는 이 값이 **실행되는
     * SQL이 아니다** — 실제 필터링은 filterArrayRows()가 PHP에서 하고, 여기
     * 반환되는 문자열은 "지금 어떤 검색어가 걸려있는지"를 보여주는 참고용
     * 표기(`'(array mode) ' . 검색어`)일 뿐이다.
     */
    public function getWhereString(): string
    {
        return $this->condition;
    }

    /**
     * 2. calcBoard()
     *
     * 전체 행수를 조회해 총 페이지수, 현재 페이지 조회에 필요한 offset(getWhere()로 조회됨),
     * 페이저 그룹의 시작/끝 페이지번호를 계산한다. init() 내부에서 호출된다.
     */
    /**
     * [2026-08-26 추가] FROM 절에 쓸 대상을 반환한다 — 테이블 모드면
     * 지금까지와 동일하게 `quoteIdentifier($tableName)`, query 모드면
     * `$rawQuery`를 `(원본) AS sub`로 감싼 서브쿼리.
     */
    private function buildFromClause(): string
    {
        if ($this->rawQuery !== null) {
            return '(' . $this->rawQuery . ') AS sub';
        }
        return $this->db->dialect()->quoteIdentifier($this->tableName);
    }

    private function calcBoard(): void
    {
        if ($this->sourceRows !== null) {
            // [2026-09-07 추가] array 모드 — COUNT(*) 쿼리 자체가 필요 없다.
            // 검색+정렬을 여기서 미리 끝내 $arrayFilteredRows에 캐시해두면,
            // loadBoard()는 그 결과를 array_slice()만 하면 된다(검색/정렬을
            // 두 번 계산하지 않기 위함).
            $filtered = $this->filterArrayRows();
            $this->arrayFilteredRows = $this->sortArrayRows($filtered);
            $totalRows = count($this->arrayFilteredRows);
        } else {
            $sql = sprintf('SELECT COUNT(*) FROM %s', $this->buildFromClause());
            if ($this->condition !== '') {
                $sql .= ' WHERE ' . $this->condition;
            }
            $totalRows = (int) $this->db->pdo()->query($sql)->fetchColumn();
        }
        $totalPages = $totalRows > 0 ? (int) ceil($totalRows / $this->pageSize) : 0;

        if ($this->page < 1) {
            $this->page = 1;
        } elseif ($totalPages > 0 && $this->page > $totalPages) {
            $this->page = $totalPages;
        }

        $startPage = (int) (floor(($this->page - 1) / $this->pagerGroupSize) * $this->pagerGroupSize) + 1;
        $endPage = min($startPage + $this->pagerGroupSize - 1, max($totalPages, 1));

        $this->totalRows = $totalRows;
        $this->totalPages = $totalPages;
        $this->offset = ($this->page - 1) * $this->pageSize;
        $this->startPage = $startPage;
        $this->endPage = $endPage;
    }

    /**
     * calcBoard() 가 계산한, 현재 페이지 레코드를 조회하기 위한 행조건(LIMIT 절)을 반환한다.
     * loadBoard() 가 내부적으로 이 값을 그대로 SQL 뒤에 이어붙여 사용한다.
     */
    public function getWhere(): string
    {
        return $this->db->dialect()->limitClause($this->offset, $this->pageSize);
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function getPagerGroupSize(): int
    {
        return $this->pagerGroupSize;
    }

    /**
     * $p 페이지로 이동하는 쿼리스트링을 만든다. 현재 요청의 $_GET 파라미터(검색어 등
     * Board가 모르는 화면단 전용 값 포함)를 그대로 유지한 채 page/pageSize/groupSize만
     * 지금 값으로 덮어쓴 "?...&page=..&pageSize=..&groupSize=.." 형태.
     * 화면단(<a href="...">)에서 페이지 이동 링크를 만들 때 쓴다.
     */
    public function getPageUrl(int $p): string
    {
        $params = $_GET;
        $params[$this->paramPrefix . 'page'] = $p;
        $params[$this->paramPrefix . 'pageSize'] = $this->pageSize;
        $params[$this->paramPrefix . 'groupSize'] = $this->pagerGroupSize;

        return '?' . http_build_query($params);
    }

    /**
     * 화면에 바로 찍을 수 있는 기본 요약 정보 HTML(<p class="meta">...</p>)을 만들어
     * 반환한다. "전체 N건 / 전체 N페이지 중 N페이지 (페이지당 N건, 페이저 그룹 N개씩)".
     */
    public function getDefaultInfoHTML(): string
    {
        return '<p class="meta">'
            . '전체 ' . Str::escape($this->totalRows) . '건 / 전체 ' . Str::escape($this->totalPages) . '페이지 중 '
            . Str::escape($this->page) . '페이지 (페이지당 ' . Str::escape($this->pageSize) . '건, '
            . '페이저 그룹 ' . Str::escape($this->pagerGroupSize) . '개씩)'
            . '</p>';
    }

    /**
     * 화면에 바로 찍을 수 있는 기본 목록 테이블 HTML(<table>...</table>)을 만들어
     * 반환한다. init() 에 넘긴 컬럼 목록(getArrayColumns())을 헤더로 쓰되, 헤더 텍스트는
     * getArrayColumnLabels() 의 라벨(없으면 컬럼명 자체)로 표시하고, 현재 페이지
     * 레코드(getRows())를 각 행으로 찍는다. 레코드가 없으면 "레코드가 없습니다" 한 행.
     *
     * 헤더 클릭 정렬: $sortable 이 true(= init() 의 pOrderBy 를 명시적으로 안 준 경우)이고
     * 그 컬럼이 실제 스키마 필드면, 헤더를 링크로 만든다. 이미 그 컬럼으로 정렬 중이면
     * 화살표(▲/▼)를 붙이고 클릭 시 방향을 반대로 토글, 아직 그 컬럼이 아니면 화살표
     * 없이 클릭 시 오름차순(ASC)부터 시작한다.
     *
     * $rowLinkUrl 을 주면(예: "view.php?code={pk}") 각 행의 셀을 그 링크로 감싸서
     * 레코드 선택(클릭 시 상세/수정 페이지로 이동)이 가능하게 한다. URL 안의 "{pk}"가
     * 해당 행의 프라이머리 키 값(urlencode 처리)으로 치환된다. Board 클래스 자체는
     * "view.php" 같은 특정 페이지명을 모르므로 어떤 게시판/화면에든 재사용 가능.
     * 프라이머리 키가 없는 테이블/뷰거나 $rowLinkUrl 을 안 주면 평범한 텍스트 셀로 표시.
     */
    public function getDefaultBodyHTML(?string $rowLinkUrl = null): string
    {
        $columns = $this->columns;
        $rows = $this->getRows();
        $schemaFields = array_keys($this->crud->getSchema()['fields']);
        $primaryKey = $this->crud->getPrimaryKey();

        $html = '<table><thead><tr>';
        foreach ($columns as $column) {
            $label = isset($this->columnLabels[$column]) ? $this->columnLabels[$column] : $column;

            if ($this->sortable && in_array($column, $schemaFields, true)) {
                $isCurrent = $this->sortField === $column;
                if ($isCurrent) {
                    $nextDir = $this->sortDir === 'ASC' ? 'DESC' : 'ASC';
                    $arrow = $this->sortDir === 'ASC' ? ' &#9650;' : ' &#9660;';
                } else {
                    $nextDir = 'ASC';
                    $arrow = '';
                }
                $url = Str::escape($this->getSortUrl($column, $nextDir));
                $html .= '<th><a href="' . $url . '">' . Str::escape($label) . $arrow . '</a></th>';
            } else {
                $html .= '<th>' . Str::escape($label) . '</th>';
            }
        }
        $html .= '</tr></thead><tbody>';

        if (empty($rows)) {
            $html .= '<tr><td colspan="' . count($columns) . '">레코드가 없습니다.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $rowUrl = null;
                if ($rowLinkUrl !== null && $primaryKey !== null && isset($row[$primaryKey])) {
                    $rowUrl = str_replace('{pk}', rawurlencode((string) $row[$primaryKey]), $rowLinkUrl);
                }

                $html .= '<tr>';
                foreach ($columns as $column) {
                    $value = isset($row[$column]) ? $row[$column] : '';
                    if ($rowUrl !== null) {
                        $html .= '<td><a href="' . Str::escape($rowUrl) . '">' . Str::escape($value) . '</a></td>';
                    } else {
                        $html .= '<td>' . Str::escape($value) . '</td>';
                    }
                }
                $html .= '</tr>';
            }
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * SG(스크롤그리드) 패턴 — 페이저 대신 스크롤로 넘기는 목록의 기본
     * HTML을 통째로 만들어 반환한다(Board.md "SG(스크롤그리드) 패턴"
     * 참고 — 2026-09-07부로 md/Board.md가 아니라 이 파일 옆
     * src/Model/Board/Board.md로 이관됨, public/test/Board/1_6/이 최초
     * 실사용처). getDefaultBodyHTML()
     * 과 구조는 거의 같되 두 가지가 다르다 — (1) `<table>`을
     * `<div class="sg-wrap">`로 감싸고 맨 앞에 DB 값과 무관한 표시 순서
     * 기준 순번 열(`sg-rownum`)을 추가, (2) 행 링크에 항상
     * `class="js-modal-link"`를 붙인다(board-modal.js가 없는 페이지에서도
     * 그냥 무해한 클래스일 뿐이라 안전 — 팝업+AJAX 화면 재사용을 염두에 둠).
     *
     * getDefaultBodyHTML()과 마찬가지로 **마크업만** 만들고 CSS는 안
     * 담는다 — 스크롤 높이/호버 색 등은 화면마다 다를 수 있어서, 호출하는
     * 화면이 `.sg-wrap`/`.sg-table`/`.sg-rownum` 클래스에 맞춰 직접
     * `<style>`을 둬야 한다.
     *
     * $rowLinkUrl은 getDefaultBodyHTML()과 동일한 "{pk}" 치환 방식(예:
     * '?top=1&left=6&code={pk}&return=...') — null이면 행이 링크 없는
     * 텍스트 셀로만 표시된다. 헤더 클릭 정렬도 getDefaultBodyHTML()과
     * 동일하게 지원($sortable/$sortField/$sortDir).
     *
     * $autoFitBottomGap(기본 null=미사용, 2026-08-15 "1_7 브라우저 창
     * 하단까지 자동 높이" 추가)를 정수로 넘기면 `.sg-wrap`에
     * `data-sg-fit="{그 값}"` 속성을 붙인다. 이 속성은 이 메서드 자체는
     * 해석하지 않고(고정 420px 같은 CSS 높이도 여전히 화면단이 직접
     * 둬야 함 — JS 미실행 시의 폴백), src/js/board-modal.js의
     * `_fitScrollGrids()`가 이 속성이 붙은 요소만 찾아 "자신을 담고 있는
     * 스크롤 영역(보통 Layout의 main.content)의 바닥에서 그 값(px)만큼
     * 띄운 높이"로 자동 계산해 인라인 스타일을 덮어쓴다(창 크기 조절/
     * 목록 새로고침 시에도 다시 계산됨) — 그 영역 자신은 넘치지 않게
     * 딱 맞춰지므로 이중 스크롤바가 안 생긴다. null로 두면 속성 자체가
     * 안 붙어 기존 화면(1_4/1_5/1_6 등, 고정 CSS 높이)의 동작에 전혀
     * 영향이 없다.
     */
    public function getDefaultScrollGridHTML(?string $rowLinkUrl = null, string $rowNumberLabel = '순번', ?int $autoFitBottomGap = null): string
    {
        $columns = $this->columns;
        $rows = $this->getRows();
        $schemaFields = array_keys($this->crud->getSchema()['fields']);
        $primaryKey = $this->crud->getPrimaryKey();

        $sgWrapAttr = $autoFitBottomGap !== null ? ' data-sg-fit="' . (int) $autoFitBottomGap . '"' : '';
        $html = '<div class="sg-wrap"' . $sgWrapAttr . '><table class="sg-table"><thead><tr>';
        $html .= '<th>' . Str::escape($rowNumberLabel) . '</th>';
        foreach ($columns as $column) {
            $label = isset($this->columnLabels[$column]) ? $this->columnLabels[$column] : $column;

            if ($this->sortable && in_array($column, $schemaFields, true)) {
                $isCurrent = $this->sortField === $column;
                if ($isCurrent) {
                    $nextDir = $this->sortDir === 'ASC' ? 'DESC' : 'ASC';
                    $arrow = $this->sortDir === 'ASC' ? ' &#9650;' : ' &#9660;';
                } else {
                    $nextDir = 'ASC';
                    $arrow = '';
                }
                $url = Str::escape($this->getSortUrl($column, $nextDir));
                $html .= '<th><a href="' . $url . '">' . Str::escape($label) . $arrow . '</a></th>';
            } else {
                $html .= '<th>' . Str::escape($label) . '</th>';
            }
        }
        $html .= '</tr></thead><tbody>';

        if (empty($rows)) {
            $html .= '<tr><td colspan="' . (count($columns) + 1) . '">레코드가 없습니다.</td></tr>';
        } else {
            $rowNumber = 0;
            foreach ($rows as $row) {
                $rowNumber++;
                $rowUrl = null;
                if ($rowLinkUrl !== null && $primaryKey !== null && isset($row[$primaryKey])) {
                    $rowUrl = str_replace('{pk}', rawurlencode((string) $row[$primaryKey]), $rowLinkUrl);
                }

                $html .= '<tr>';
                if ($rowUrl !== null) {
                    $html .= '<td class="sg-rownum"><a class="js-modal-link" href="' . Str::escape($rowUrl) . '">' . $rowNumber . '</a></td>';
                } else {
                    $html .= '<td class="sg-rownum">' . $rowNumber . '</td>';
                }
                foreach ($columns as $column) {
                    $value = isset($row[$column]) ? $row[$column] : '';
                    if ($rowUrl !== null) {
                        $html .= '<td><a class="js-modal-link" href="' . Str::escape($rowUrl) . '">' . Str::escape($value) . '</a></td>';
                    } else {
                        $html .= '<td>' . Str::escape($value) . '</td>';
                    }
                }
                $html .= '</tr>';
            }
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    /**
     * [2026-08-15 "진짜 인라인 편집 + 일괄저장" SG 추가] getDefaultScrollGridHTML()과
     * 데이터 출처(getRows()/컬럼/컬럼라벨/PK)는 완전히 동일하지만, HTML
     * 문자열이 아니라 **배열**을 반환한다 — 브라우저의 ScrollGrid(JS,
     * src/js/ScrollGrid.js)가 이 값을 json_encode()해서 받은 뒤 직접
     * `<table>`을 그리고 셀을 편집 가능하게 만드는 용도다(1_7 참고).
     *
     * rows는 loadBoard()가 SELECT *로 읽어온 전체 컬럼이 아니라, init()에
     * 넘긴 표시 컬럼($this->columns)만 골라 담는다 — getDefaultBodyHTML()/
     * getDefaultScrollGridHTML()이 화면에 그 컬럼만 보여주는 것과 동일한
     * 원칙(편집 화면에 노출되면 안 되는 필드를 실수로 흘리지 않기 위함).
     *
     * [2026-08-16 PK 컬럼을 columns에서 제외 — md/Board_ai.md "1_7 데이터시트
     * 느낌 + PK 숨김 + 컬럼 너비 조절" 참고] primaryKey에 해당하는 컬럼은
     * $columnsData(헤더/화면 표시용 컬럼 목록)에서 아예 뺀다 — 내부 식별자를
     * 사용자에게 보여줄 이유가 없다는 판단(Access 데이터시트도 내부 ID 컬럼을
     * 화면에 노출하지 않는 것과 같은 원칙). 값 자체는 $rowsData에는 여전히
     * 그대로 담아 보낸다 — 편집/삭제 시 어떤 레코드인지 식별해야 하므로
     * (ScrollGrid.js가 row.values[primaryKey]로 계속 참조함). 클라이언트
     * (ScrollGrid.js)는 헤더/셀 렌더링을 전부 $columnsData를 순회해서 만들기
     * 때문에, 여기서 빼는 것만으로 그리드 표와 "레코드 편집" 모달 양쪽에서
     * 자동으로 안 보이게 된다 — 클라이언트 쪽 코드는 손댈 필요가 없다.
     *
     * @return array{primaryKey: ?string, columns: array<int, array{name: string, label: string}>, rows: array<int, array<string, mixed>>}
     */
    public function getScrollGridData(): array
    {
        $columns = $this->columns;
        $rows = $this->getRows();
        $labels = $this->columnLabels;
        $primaryKey = $this->crud->getPrimaryKey();

        $columnsData = [];
        foreach ($columns as $column) {
            if ($column === $primaryKey) {
                continue;
            }
            $columnsData[] = [
                'name' => $column,
                'label' => isset($labels[$column]) ? $labels[$column] : $column,
            ];
        }

        $rowsData = [];
        foreach ($rows as $row) {
            $entry = [];
            foreach ($columns as $column) {
                $entry[$column] = isset($row[$column]) ? $row[$column] : '';
            }
            $rowsData[] = $entry;
        }

        return [
            'primaryKey' => $primaryKey,
            'columns' => $columnsData,
            'rows' => $rowsData,
        ];
    }

    /**
     * 화면에 바로 찍을 수 있는 기본 페이저 HTML(<div class="pager">...</div>)을 만들어
     * 반환한다. "처음"/"이전"/(현재 그룹의 페이지 번호들)/"다음"/"마지막" 순서로 놓는다.
     * "이전"/"다음"은 그룹 단위가 아니라 **한 페이지씩** 이동한다(page-1/page+1) —
     * "처음"/"마지막"과 표시 조건이 자연스럽게 짝을 이루도록(둘 다 page>1 / page<totalPages
     * 일 때만 보임) 통일한 것. 페이지 번호 목록(현재 그룹)은 별개로 그대로 보여준다.
     * 이동할 수 없는 버튼은 클릭은 안 되지만(링크가 아님) 자리는 그대로 차지하는
     * `<span class="hidden">`으로 렌더링한다 — 그래야 유무에 따라 가운데 페이지
     * 번호들이 좌우로 밀리지 않고 항상 같은 위치에 고정된다(화면단에서
     * `.pager span.hidden { visibility: hidden; }` CSS로 시각적으로만 숨김).
     * getPageUrl() 이 만드는 링크를 그대로 쓰고, moveFirstPage()/eofPage()/getPager()/
     * moveNextPage() 커서로 현재 그룹의 페이지 번호를 순회한다(호출 후 pageCursor는
     * EOF에 남음 — getRows() 와 동일한 특성).
     */
    public function getDefaultPagerHTML(): string
    {
        $html = '<div class="pager">';

        if ($this->page > 1) {
            $html .= '<a href="' . Str::escape($this->getPageUrl(1)) . '">|&laquo; 처음</a>';
        } else {
            $html .= '<span class="hidden">|&laquo; 처음</span>';
        }

        if ($this->page > 1) {
            $html .= '<a href="' . Str::escape($this->getPageUrl($this->page - 1)) . '">&laquo; 이전</a>';
        } else {
            $html .= '<span class="hidden">&laquo; 이전</span>';
        }

        $this->moveFirstPage();
        while (!$this->eofPage()) {
            $p = $this->getPager();
            if ($p === $this->page) {
                $html .= '<span class="current">' . Str::escape($p) . '</span>';
            } else {
                $html .= '<a href="' . Str::escape($this->getPageUrl($p)) . '">' . Str::escape($p) . '</a>';
            }
            $this->moveNextPage();
        }

        if ($this->page < $this->totalPages) {
            $html .= '<a href="' . Str::escape($this->getPageUrl($this->page + 1)) . '">다음 &raquo;</a>';
        } else {
            $html .= '<span class="hidden">다음 &raquo;</span>';
        }

        if ($this->page < $this->totalPages) {
            $html .= '<a href="' . Str::escape($this->getPageUrl($this->totalPages)) . '">마지막 &raquo;|</a>';
        } else {
            $html .= '<span class="hidden">마지막 &raquo;|</span>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * [2026-09-07 추가] 현재 정렬 기준 필드/방향 — resolveOrderBy()가
     * 계산해둔 값을 그대로 노출한다. `getDefaultBodyHTML()`/
     * `getDefaultScrollGridHTML()`이 헤더 화살표를 그릴 때 내부적으로
     * 쓰는 것과 같은 값 — array/query 모드처럼 그 메서드들을 못 쓰는
     * 화면이 직접 테이블을 그릴 때 헤더 화살표를 재현하려면 이 getter가
     * 필요하다(`SP_Board_entry.php` 참고). `$sortable`이 false거나 다중
     * 컬럼 raw SQL 정렬을 썼으면 `getSortField()`는 null.
     */
    public function getSortField(): ?string
    {
        return $this->sortField;
    }

    public function getSortDir(): string
    {
        return $this->sortDir;
    }

    /** 헤더 클릭 정렬이 가능한 상태인지(명시적 $pOrderBy를 안 쓴 경우). */
    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function getTotalRows(): int
    {
        return $this->totalRows;
    }

    public function getTotalPages(): int
    {
        return $this->totalPages;
    }

    public function getStartPage(): int
    {
        return $this->startPage;
    }

    public function getEndPage(): int
    {
        return $this->endPage;
    }

    public function getSchema(): array
    {
        return $this->crud->getSchema();
    }

    /**
     * 3. loadBoard()
     *
     * calcBoard() 에서 계산된 값(getWhere() 등)을 근거로 실제 레코드를 조회하고,
     * $data = {"records": [...], "pager": {...}} 형태로 저장한다. WHERE(getWhereString())
     * → ORDER BY → LIMIT(getWhere()) 순서로 SQL을 조립한다.
     */
    public function loadBoard(): void
    {
        $this->assertInitialized();

        if ($this->sourceRows !== null) {
            // [2026-09-07 추가] array 모드 — calcBoard()가 이미 검색+정렬을
            // 끝내 $arrayFilteredRows에 캐시해뒀으므로, 여기서는 SQL의
            // LIMIT/OFFSET에 대응하는 array_slice()만 하면 된다.
            $records = array_slice($this->arrayFilteredRows, $this->offset, $this->pageSize);
            $records = array_values($records);
        } else {
            $sql = sprintf('SELECT * FROM %s', $this->buildFromClause());
            if ($this->condition !== '') {
                $sql .= ' WHERE ' . $this->condition;
            }
            if ($this->orderBy !== '') {
                $sql .= ' ORDER BY ' . $this->orderBy;
            }
            $sql .= ' ' . $this->getWhere();

            $stmt = $this->db->pdo()->query($sql);
            $records = $stmt !== false ? $stmt->fetchAll() : [];
            $records = $this->sanitizeUtf8($records);
        }

        $pages = [];
        for ($p = $this->startPage; $p <= $this->endPage; $p++) {
            $pages[] = $p;
        }

        $this->data = [
            'records' => $records,
            'pager'   => [
                'currentPage'    => $this->page,
                'pageSize'       => $this->pageSize,
                'totalRows'      => $this->totalRows,
                'totalPages'     => $this->totalPages,
                'pagerGroupSize' => $this->pagerGroupSize,
                'startPage'      => $this->startPage,
                'endPage'        => $this->endPage,
                'pages'          => $pages,
            ],
        ];

        $this->dataCursor = empty($records) ? -1 : 0;

        $this->pageCursor = -1;
        foreach ($pages as $i => $p) {
            if ($p === $this->page) {
                $this->pageCursor = $i;
                break;
            }
        }
        if ($this->pageCursor === -1 && !empty($pages)) {
            $this->pageCursor = 0;
        }
    }

    /**
     * [2026-08-26 추가 — 팀/룸/상품분류/상품/TC 등 일부 테이블에서
     * "행은 나오는데 값이 전부 빈값"으로 보고된 문제를 추적한 결과]
     *
     * DB 컬럼이 varchar + 비UTF8 콜레이션(한글 환경에서 흔한
     * Korean_Wansung_CI_AS 등 CP949 계열)이면 PDO(dblib/FreeTDS)가
     * 돌려주는 문자열이 유효한 UTF-8이 아닐 수 있다. 이 상태로
     * json_encode()에 넘기면 조용히 false를 반환해(에러 없이) 응답
     * 본문이 빈 문자열이 되고, AGGrid.js는 그 응답을 파싱하다 실패해
     * 화면에 자료가 하나도 안 나오거나 값이 빈 채로 보인다.
     *
     * 컬럼 타입을 nvarchar로 바꾸는 게 정석이지만(DB 스키마 변경이라
     * 이 프로젝트 범위 밖), 그전까지는 여기서 유효하지 않은 UTF-8
     * 문자열만 골라 CP949로 가정하고 변환한다 — 이미 정상인 UTF-8
     * 문자열(nvarchar 컬럼 등)은 mb_check_encoding()이 걸러줘서
     * 손대지 않는다.
     *
     * @param array<int, array<string, mixed>> $records
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeUtf8(array $records): array
    {
        foreach ($records as &$record) {
            foreach ($record as $field => $value) {
                if (!is_string($value) || $value === '') {
                    continue;
                }
                if (mb_check_encoding($value, 'UTF-8')) {
                    continue;
                }
                $converted = @iconv('CP949', 'UTF-8//IGNORE', $value);
                if ($converted !== false) {
                    $record[$field] = $converted;
                } else {
                    $record[$field] = mb_convert_encoding($value, 'UTF-8', 'CP949');
                }
            }
        }
        unset($record);

        return $records;
    }

    // ------------------------------------------------------------------
    // 4. records(data) 커서 탐색 — getData(index), moveFirst/moveNext/moveLast/movePrevious, bof/eof
    // ------------------------------------------------------------------

    /**
     * $index 를 주면 그 위치의 레코드를 바로 반환(랜덤 접근), 생략하면 현재 커서 위치의
     * 레코드를 반환한다. 범위를 벗어나면 null.
     *
     * @return array<string, mixed>|null
     */
    public function getData(?int $index = null): ?array
    {
        $records = $this->data['records'];

        if ($index !== null) {
            return isset($records[$index]) ? $records[$index] : null;
        }

        if ($this->dataCursor < 0 || $this->dataCursor >= count($records)) {
            return null;
        }

        return $records[$this->dataCursor];
    }

    /**
     * 현재 페이지의 레코드 전체를 배열로 반환한다. moveFirst()/eof()/getData()/moveNext()
     * 커서를 내부에서 돌려서 모으는 방식이라, 호출 후 커서는 EOF에 위치하게 된다
     * (다시 처음부터 순회하려면 moveFirst()를 호출하면 된다).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRows(): array
    {
        $rows = [];

        $this->moveFirst();
        while (!$this->eof()) {
            $rows[] = $this->getData();
            $this->moveNext();
        }

        return $rows;
    }

    public function bof(): bool
    {
        if (empty($this->data['records'])) {
            return true;
        }
        return $this->dataCursor < 0;
    }

    public function eof(): bool
    {
        if (empty($this->data['records'])) {
            return true;
        }
        return $this->dataCursor >= count($this->data['records']);
    }

    public function moveFirst(): void
    {
        if (empty($this->data['records'])) {
            return;
        }
        $this->dataCursor = 0;
    }

    public function moveLast(): void
    {
        if (empty($this->data['records'])) {
            return;
        }
        $this->dataCursor = count($this->data['records']) - 1;
    }

    public function moveNext(): void
    {
        $count = count($this->data['records']);
        if ($count === 0) {
            return;
        }
        if ($this->dataCursor < $count) {
            $this->dataCursor++;
        }
    }

    public function movePrevious(): void
    {
        if (empty($this->data['records'])) {
            return;
        }
        if ($this->dataCursor >= 0) {
            $this->dataCursor--;
        }
    }

    // ------------------------------------------------------------------
    // 4. pager 커서 탐색 — getPager(index), moveFirstPage/moveNextPage/moveLastPage/
    //    movePreviousPage, bofPage/eofPage (pager.pages 배열, 즉 현재 그룹의 페이지번호 목록을 옮겨다님)
    // ------------------------------------------------------------------

    /**
     * $index 를 주면 pager.pages 의 그 위치 페이지번호를 바로 반환, 생략하면 현재
     * pageCursor 위치의 페이지번호를 반환한다. 범위를 벗어나면 null.
     */
    public function getPager(?int $index = null): ?int
    {
        $pages = $this->data['pager']['pages'];

        if ($index !== null) {
            return isset($pages[$index]) ? $pages[$index] : null;
        }

        if ($this->pageCursor < 0 || $this->pageCursor >= count($pages)) {
            return null;
        }

        return $pages[$this->pageCursor];
    }

    public function bofPage(): bool
    {
        if (empty($this->data['pager']['pages'])) {
            return true;
        }
        return $this->pageCursor < 0;
    }

    public function eofPage(): bool
    {
        if (empty($this->data['pager']['pages'])) {
            return true;
        }
        return $this->pageCursor >= count($this->data['pager']['pages']);
    }

    public function moveFirstPage(): void
    {
        if (empty($this->data['pager']['pages'])) {
            return;
        }
        $this->pageCursor = 0;
    }

    public function moveLastPage(): void
    {
        if (empty($this->data['pager']['pages'])) {
            return;
        }
        $this->pageCursor = count($this->data['pager']['pages']) - 1;
    }

    public function moveNextPage(): void
    {
        $count = count($this->data['pager']['pages']);
        if ($count === 0) {
            return;
        }
        if ($this->pageCursor < $count) {
            $this->pageCursor++;
        }
    }

    public function movePreviousPage(): void
    {
        if (empty($this->data['pager']['pages'])) {
            return;
        }
        if ($this->pageCursor >= 0) {
            $this->pageCursor--;
        }
    }

    // ------------------------------------------------------------------
    // 보조
    // ------------------------------------------------------------------

    /** @return array{records: array<int, array<string, mixed>>, pager: array<string, mixed>} */
    public function getAllData(): array
    {
        return $this->data;
    }

    /**
     * 현재 스키마 + 컬럼/라벨 + 조건/정렬 + 커서 위치 + data(레코드+페이저)를 JSON
     * 문자열 하나로 직렬화한다("crud 때처럼" — Crud::toJson() 과 동일한 방식). schema가
     * 최상위, 그 아래 columns/labels/condition/orderBy/dataCursor/pageCursor, 그 아래
     * data(records+pager) 순서로 온다.
     */
    public function toJson(): string
    {
        $this->assertInitialized();

        $payload = [
            'schema'     => $this->crud->getSchema(),
            'columns'    => $this->columns,
            'labels'     => $this->columnLabels,
            'condition'  => $this->condition,
            'orderBy'    => $this->orderBy,
            'dataCursor' => $this->dataCursor,
            'pageCursor' => $this->pageCursor,
            'data'       => $this->data,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('JSON 직렬화에 실패했습니다: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * toJson() 이 만든 JSON 문자열로부터 DB 접속 없이 Board 객체를 복원한다. 내부적으로
     * Crud::fromJson() 을 그대로 재사용해 스키마를 복원하고(information_schema 조회
     * 없음), columns/labels/condition/orderBy/커서/data 를 JSON에 담긴 값 그대로
     * 되살린다. 복원 직후에도 bof()/eof()/moveNext()/getRows()/getDefaultBodyHTML() 등을
     * 그대로 이어서 쓸 수 있다. $db는 나중에 실제로 loadBoard() 등을 다시 호출할 때만
     * 쓰인다 — 복원 자체는 DB에 전혀 접촉하지 않는다.
     */
    public static function fromJson(DbContext $db, string $json): Board
    {
        $payload = json_decode($json, true);
        if (!is_array($payload) || !isset($payload['schema']) || !isset($payload['data'])) {
            throw new InvalidArgumentException('올바른 Board JSON 형식이 아닙니다 (schema/data 키가 없음).');
        }

        $schema = $payload['schema'];
        if (!is_array($schema) || !isset($schema['tableName'])) {
            throw new InvalidArgumentException('올바른 스키마 형식이 아닙니다 (tableName 키가 없음).');
        }

        $board = new self($db);
        $board->tableName = (string) $schema['tableName'];
        // Crud::fromJson() 을 재사용해 스키마를 복원 (DB 접속 없음).
        $board->crud = Crud::fromJson($db, (string) json_encode(['schema' => $schema, 'cursor' => -1, 'data' => []]));

        $board->columns = isset($payload['columns']) && is_array($payload['columns']) ? $payload['columns'] : [];
        $board->columnLabels = isset($payload['labels']) && is_array($payload['labels']) ? $payload['labels'] : [];
        $board->condition = isset($payload['condition']) ? (string) $payload['condition'] : '';
        $board->orderBy = isset($payload['orderBy']) ? (string) $payload['orderBy'] : '';

        $data = is_array($payload['data']) ? $payload['data'] : [];
        $records = isset($data['records']) && is_array($data['records']) ? $data['records'] : [];
        $pager = isset($data['pager']) && is_array($data['pager']) ? $data['pager'] : [];
        $board->data = ['records' => $records, 'pager' => $pager];

        $board->page = isset($pager['currentPage']) ? (int) $pager['currentPage'] : 1;
        $board->pageSize = isset($pager['pageSize']) ? (int) $pager['pageSize'] : 10;
        $board->totalRows = isset($pager['totalRows']) ? (int) $pager['totalRows'] : 0;
        $board->totalPages = isset($pager['totalPages']) ? (int) $pager['totalPages'] : 0;
        $board->pagerGroupSize = isset($pager['pagerGroupSize']) ? (int) $pager['pagerGroupSize'] : 10;
        $board->startPage = isset($pager['startPage']) ? (int) $pager['startPage'] : 1;
        $board->endPage = isset($pager['endPage']) ? (int) $pager['endPage'] : 1;
        $board->offset = ($board->page - 1) * $board->pageSize;

        $board->dataCursor = isset($payload['dataCursor']) ? (int) $payload['dataCursor'] : (empty($records) ? -1 : 0);
        $pages = isset($pager['pages']) && is_array($pager['pages']) ? $pager['pages'] : [];
        $board->pageCursor = isset($payload['pageCursor']) ? (int) $payload['pageCursor'] : (empty($pages) ? -1 : 0);

        return $board;
    }

    private function assertInitialized(): void
    {
        // [2026-08-26 변경, 2026-09-07 array 모드 추가] query/array 모드는
        // $tableName을 일부러 null로 둔다(진짜 테이블이 없으므로) — $rawQuery
        // 또는 $sourceRows가 있으면 그것도 "초기화됨"으로 인정한다.
        if ($this->tableName === null && $this->rawQuery === null && $this->sourceRows === null) {
            throw new RuntimeException('init() 또는 initFromQuery() 또는 initFromArray() 를 먼저 호출해야 합니다.');
        }
    }
}
