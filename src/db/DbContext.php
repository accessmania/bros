<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../Model/SqlDialect/SqlDialectFactory.php';

/**
 * DB 접속 하나(PDO + 방언 개체)를 들고 다니는 개체.
 *
 * 페이지 상단에서 `new DbContext('mssql')`로 한 번 만들고, DB를 쓰는
 * 클래스들(Login/Auth/Crud/Board 등)에 그 개체를 그대로 넘긴다 — 각 클래스가
 * 접속 이름을 따로 들고 있지 않게 하고, 같은 요청 안에서는 모두 같은 연결을
 * 나눠 쓰게 하기 위함이다(자세한 내용은 같은 폴더의 DbContext.md 참고).
 *
 * 생성자는 접속 이름만 기억하고 연결하지 않는다(지연 초기화) — pdo()/
 * dialect()가 처음 불릴 때 Database::connect()로 연결을 만들고, 그 이후의
 * 호출은 만들어둔 개체를 그대로 돌려준다. 그래서 상단에 늘 만들어 두어도
 * DB를 실제로 쓰지 않는 페이지는 연결 비용이 없다.
 */
class DbContext
{
    /** @var string|null config/db.php의 접속 키(null이면 config의 'driver' 기본값). */
    private $name;

    /** @var PDO|null pdo() 첫 호출 시 생성. */
    private $pdo = null;

    /** @var SqlDialect|null dialect() 첫 호출 시 생성. */
    private $dialect = null;

    /**
     * @param string|null $name config/db.php의 접속 키(예: 'mssql').
     *                          생략하면 Database::connect()와 동일하게 config의
     *                          'driver' 값을 쓴다.
     */
    public function __construct(?string $name = null)
    {
        $this->name = $name;
    }

    /**
     * 연결(PDO)을 돌려준다. 처음 불릴 때만 Database::connect()로 연결을
     * 만들고, 이후에는 같은 개체를 재사용한다.
     */
    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = Database::connect($this->name);
        }
        return $this->pdo;
    }

    /**
     * 이 연결에 맞는 SQL 방언 개체(MySQL/MSSQL)를 돌려준다. 처음 불릴 때
     * pdo()를 거쳐 만들고, 이후에는 재사용한다.
     */
    public function dialect(): SqlDialect
    {
        if ($this->dialect === null) {
            $this->dialect = SqlDialectFactory::forPdo($this->pdo());
        }
        return $this->dialect;
    }

    /** 생성자에 넘긴 접속 이름(생략했으면 null). */
    public function name(): ?string
    {
        return $this->name;
    }

    /** 이미 연결됐는지(pdo()가 한 번이라도 불렸는지). 연결을 만들지 않는다. */
    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }
}
