<?php
declare(strict_types=1);

/**
 * SVW(search/view/write) 패턴을 클래스로 감쌀 때, ViewBoard/ViewCrud/ActionCrud
 * 세 클래스가 공통으로 참조하는 설정값을 한 곳에 모아두는 값 객체(DTO)다.
 *
 * 예전 public/test/Board/second/ 방식은 view.php/write.php가 각자 따로 상수
 * (TABLE_NAME/PRIMARY_KEY/FLASH_ERROR_KEY 등)를 선언해두고 "반드시 같은 값이어야
 * 한다"는 주석으로만 결합을 지켰다(md/Board_ai.md 참고). 이 클래스는 그 값들을
 * 한 번만 만들어서 세 클래스가 전부 같은 인스턴스를 공유하게 해, 값이 어긋날
 * 여지를 코드로 없앤다.
 *
 * Board/Crud처럼 "행동"이 있는 클래스가 아니라 순수 데이터 뭉치라서, 게터/세터 없이
 * public 프로퍼티를 그대로 둔다.
 */
class BoardConfig
{
    /**
     * @var string|null config/db.php에 등록된 접속 이름(예: 'mysql', 'mssql',
     * 'mssql_deco'). new DbContext($config->connectionName)으로 쓰면
     * 화면마다 문자열을 따로따로 하드코딩하지 않고 이 config 한 곳에서만
     * 관리할 수 있다(md/sql-dialect-mysql-mssql.md 참고). null(비워둠)이면
     * new DbContext(null)이 config/db.php의 'driver' 기본값을 그대로
     * 따른다 — 그 테이블이 여러 DB에 동일하게 존재해서(t회원처럼) 화면을
     * 그대로 둔 채 'driver' 값만 바꿔가며 대조 검증하고 싶을 때 비워둔다.
     */
    public $connectionName;

    /** @var string DB 테이블/뷰 이름. */
    public $tableName;

    /** @var string 프라이머리 키 필드명. */
    public $primaryKey;

    /** @var string Board::init()에 그대로 넘길 "필드명:라벨" 콤마 문자열(검색/목록용). */
    public $columns;

    /** @var string 검색 대상 필드(콤마 구분, Board::init()에 그대로 넘김). */
    public $searchFields;

    /** @var array<int, string> view 폼에서 다루는 필드명 목록. */
    public $fields;

    /** @var string 목록(search) 페이지 URL. */
    public $listUrl;

    /** @var string 조회/편집(view) 페이지 URL. */
    public $viewUrl;

    /** @var string 저장/삭제 처리(write) 페이지 URL. */
    public $writeUrl;

    /** @var string write 실패 시 세션에 남기는 에러메시지 키. */
    public $flashErrorKey;

    /** @var string write 실패 시 세션에 남기는 입력값 키. */
    public $flashOldKey;

    /**
     * @var string|null ViewBoard 생성 시 넘길 목록 화면 템플릿 파일 경로(이름을
     * "board"로 지은 이유: ViewBoard가 다루는 모델이 Board라서 — 화면 역할
     * 이름(search)이 아니라 모델 이름으로 지으면 View/ViewBoard/ViewCrud
     * 클래스 이름 체계의 "View"와 겹쳐 헷갈리는 것을 피할 수 있다). 화면단이
     * 템플릿 경로를 직접 알 필요 없이 이 config 하나로 관리하려는 화면에서 쓴다
     * (예: public/test/Board/1_2/index.php). templateFile을 호출부가 직접
     * 정하는 화면(예: public/test/Board/1_1/search.php)에서는 필요 없어 null로
     * 둬도 된다.
     */
    public $boardTemplate;

    /**
     * @var string|null ViewCrud 생성 시 넘길 조회 화면 템플릿 파일 경로(이름을
     * "crud"로 지은 이유는 $boardTemplate과 동일 — ViewCrud가 다루는 모델이
     * Crud라서). 위 $boardTemplate과 같은 이유로 선택 사항이다.
     */
    public $crudTemplate;

    /**
     * @var string 한 페이지에 게시판을 2개 이상 둘 때, 이 게시판 인스턴스가 쓰는
     * GET/POST 파라미터 이름 앞에 붙일 접두어(예: 'b1_'). 비어있으면(기본값)
     * 접두어가 안 붙어 지금까지의 화면(1_1~1_4, 2_1 등 전부 단일 게시판)과
     * 동작이 100% 동일하다 — page/sort/q 같은 이름이 화면 전체에서 하나뿐이라고
     * 가정해도 안전했기 때문. 게시판이 여러 개 동시에 있으면, Board가 만드는
     * page/pageSize/groupSize/sort/dir/q 키가 서로 겹쳐서(둘 다 "?page=2"를
     * 보게 됨) 페이지를 넘기면 다른 게시판까지 같이 넘어가는 문제가 생긴다 —
     * 그래서 게시판마다 다른 접두어를 주면 Board 내부(new Board($db,
     * $prefix))와 이 config의 paramName()이 같은 접두어를 함께 써서 키가
     * 겹치지 않게 한다.
     */
    public $paramPrefix = '';

    /**
     * $paramPrefix가 붙은 파라미터 이름을 만든다. 진입 파일(content*.php류)이
     * $_GET[$config->primaryKey]/$_GET['return']/$_GET['q']처럼 직접 리터럴로
     * 읽는 대신 $_GET[$config->paramName($config->primaryKey)]처럼 이 메서드를
     * 거치면, 접두어가 비어있는 지금까지의 화면은 동작이 그대로이고(paramName()이
     * 그냥 원래 이름을 돌려줌), 나중에 접두어를 주면 코드 수정 없이 자동으로
     * 이름이 바뀐다.
     */
    public function paramName(string $name): string
    {
        return $this->paramPrefix . $name;
    }
}
