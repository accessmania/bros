<?php

declare(strict_types=1);

require_once __DIR__ . '/../Model/SqlDialect/SqlDialect.php';
require_once __DIR__ . '/../db/DbContext.php';

/**
 * MS Access의 도메인 집계 함수(DLookup/DMax/DMin)를 PHP로 옮긴 정적 유틸
 * 클래스. Board/Crud처럼 스키마를 통째로 읽어와 인스턴스에 들고 있는
 * 구조가 아니라, "조건에 맞는 값 하나(또는 최댓값/최솟값 하나)"만 즉석에서
 * 뽑아오는 가벼운 조회 전용이다 — 자세한 스펙/예시는 domain.md 참고.
 *
 * $expr(가져올 필드/표현식)와 $criteria(WHERE 조건)는 Access와 동일하게
 * **raw SQL 문자열**로 그대로 조립된다(따옴표 처리 없음) — Crud::selectRecords($pWhere)/
 * Board의 조건 문자열과 같은 "신뢰된 서버 코드만 호출, 사용자 입력을 직접
 * 연결하지 않는다"는 관례를 그대로 따른다. $domain(테이블명)만
 * SqlDialect::quoteIdentifier()로 자동 인용해 MySQL/MSSQL 양쪽에서 그대로
 * 동작한다.
 */
class Domain
{
    /**
     * DLookup(expr, domain, [criteria]) — 조건에 맞는 첫 번째 행에서 $expr 값 하나를
     * 가져온다. 일치하는 행이 없으면 null. 정렬을 지정하지 않으면(Access와 동일하게)
     * 어떤 행이 "첫 번째"인지는 DB 구현에 맡겨진다 — 특정 행을 원하면 $criteria로
     * 좁히거나(예: PK 등가조건) $expr/$criteria에 직접 정렬을 넣을 필요는 없고
     * 애초에 하나로 좁혀지도록 조건을 주는 것이 Access 관례와도 맞다.
     *
     * @return mixed 일치하는 값, 없으면 null
     */
    public static function lookup(string $expr, string $domain, ?string $criteria = null, ?DbContext $db = null)
    {
        $db = self::resolveDb($db);
        $pdo = $db->pdo();
        $dialect = $db->dialect();
        $where = self::whereClause($criteria);

        // MSSQL의 OFFSET/FETCH(SqlDialect::limitClause())는 ORDER BY가 반드시
        // 있어야 하는데(md/sql-dialect-mysql-mssql.md 참고), 여기선 정렬을
        // 요구하지 않으므로 그 메서드를 안 쓰고 TOP/LIMIT을 직접 분기한다.
        if (self::isMssql($pdo)) {
            $sql = sprintf('SELECT TOP 1 %s FROM %s%s', $expr, $dialect->quoteIdentifier($domain), $where);
        } else {
            $sql = sprintf('SELECT %s FROM %s%s LIMIT 1', $expr, $dialect->quoteIdentifier($domain), $where);
        }

        $stmt = $pdo->query($sql);
        $value = $stmt !== false ? $stmt->fetchColumn() : false;

        return $value === false ? null : $value;
    }

    /**
     * DMax(expr, domain, [criteria]) — 조건에 맞는 행들 중 $expr의 최댓값.
     * 일치하는 행이 없으면 null(SQL MAX()가 빈 집합에서 NULL을 주는 것 그대로).
     *
     * @return mixed
     */
    public static function max(string $expr, string $domain, ?string $criteria = null, ?DbContext $db = null)
    {
        return self::aggregate('MAX', $expr, $domain, $criteria, $db);
    }

    /**
     * DMin(expr, domain, [criteria]) — 조건에 맞는 행들 중 $expr의 최솟값.
     * 일치하는 행이 없으면 null.
     *
     * @return mixed
     */
    public static function min(string $expr, string $domain, ?string $criteria = null, ?DbContext $db = null)
    {
        return self::aggregate('MIN', $expr, $domain, $criteria, $db);
    }

    /**
     * MAX/MIN 공용 구현. 집계함수라 결과가 항상 정확히 1행이므로(빈 집합이면
     * 값이 NULL인 1행) DLookup처럼 LIMIT/TOP을 따로 붙일 필요가 없다 — 이 덕분에
     * 서버가 후보 행을 전부 클라이언트로 보내지 않고 자기 안에서 계산해서 1행만
     * 돌려준다(테이블이 커도 가벼움, domain.md의 성능 비교 참고).
     *
     * @return mixed
     */
    private static function aggregate(
        string $fn,
        string $expr,
        string $domain,
        ?string $criteria,
        ?DbContext $db
    ) {
        $db = self::resolveDb($db);
        $pdo = $db->pdo();
        $dialect = $db->dialect();
        $where = self::whereClause($criteria);

        $sql = sprintf('SELECT %s(%s) FROM %s%s', $fn, $expr, $dialect->quoteIdentifier($domain), $where);

        $stmt = $pdo->query($sql);
        $value = $stmt !== false ? $stmt->fetchColumn() : false;

        return ($value === false || $value === null) ? null : $value;
    }

    /**
     * [2026-09-19 추가] "도메인 함수를 여러 번 호출해야 하는 게 부담이면
     * 1회 호출로 값들을 가져오게" 지시 — `lookup()`이 한 번에 값 하나만
     * 가져오는 것과 달리, 같은 테이블/조건에서 여러 컬럼이 함께 필요할
     * 때 SELECT를 여러 번 나눠 보내지 않고 한 번에 가져온다. Access
     * `DLookup()`에는 없는 확장(원래 한 번에 값 하나만 지원)이지만, 이
     * 프로젝트에서 같은 도메인/조건으로 여러 값을 반복 조회하는 부담을
     * 줄이기 위해 추가했다. `lookup()`과 동일한 TOP 1/LIMIT 1 분기를
     * 그대로 재사용하고, `fetchColumn()` 대신 `fetch(PDO::FETCH_ASSOC)`
     * 로 여러 컬럼을 한 번에 받는다.
     *
     * @param string[] $fields 가져올 필드명/표현식 목록 — 각 원소는
     *   `lookup()`의 `$expr`과 동일하게 raw SQL로 그대로 조립된다.
     * @return array<string, mixed>|null 일치하는 첫 번째 행(필드명을
     *   키로 하는 연관배열), 없으면 null
     */
    public static function lookupRow(array $fields, string $domain, ?string $criteria = null, ?DbContext $db = null): ?array
    {
        $db = self::resolveDb($db);
        $pdo = $db->pdo();
        $dialect = $db->dialect();
        $where = self::whereClause($criteria);
        $exprList = implode(', ', $fields);

        if (self::isMssql($pdo)) {
            $sql = sprintf('SELECT TOP 1 %s FROM %s%s', $exprList, $dialect->quoteIdentifier($domain), $where);
        } else {
            $sql = sprintf('SELECT %s FROM %s%s LIMIT 1', $exprList, $dialect->quoteIdentifier($domain), $where);
        }

        $stmt = $pdo->query($sql);
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        return $row === false ? null : $row;
    }

    private static function whereClause(?string $criteria): string
    {
        return ($criteria !== null && $criteria !== '') ? ' WHERE ' . $criteria : '';
    }

    /**
     * SqlDialectFactory와 동일한 방식(PDO 자신이 밝히는 드라이버 이름)으로
     * MSSQL 계열(dblib/sqlsrv/odbc)인지 판별한다. SqlDialect::limitClause()를
     * 재사용하지 않고 여기서 직접 판별하는 이유는 lookup()의 주석 참고
     * (ORDER BY 없는 조회에는 그 메서드가 안 맞음).
     */
    private static function isMssql(PDO $pdo): bool
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        return in_array($driver, ['dblib', 'sqlsrv', 'odbc'], true);
    }

    /** $db를 생략하면 config/db.php의 'driver' 기본 접속으로 DbContext를 만든다. */
    private static function resolveDb(?DbContext $db): DbContext
    {
        return $db !== null ? $db : new DbContext();
    }
}
