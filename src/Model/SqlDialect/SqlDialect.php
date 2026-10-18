<?php
declare(strict_types=1);

/**
 * MySQL/MSSQL처럼 SQL 문법이 갈리는 지점만 감싼 작은 인터페이스.
 *
 * Crud/Board는 SQL을 조립할 때 테이블/컬럼명을 직접 백틱으로 감싸거나
 * LIMIT을 직접 쓰지 않고, 전부 이 인터페이스를 거친다. 실제로 어떤 DB에
 * 붙어 있는지는 SqlDialectFactory가 PDO 연결 자체(ATTR_DRIVER_NAME)로부터
 * 자동 판별해서 알맞은 구현체(SqlDialectMysql/SqlDialectMssql)를 골라준다 —
 * Crud/Board를 쓰는 쪽(화면단)은 이 존재 자체를 몰라도 된다.
 */
interface SqlDialect
{
    /** 테이블/컬럼명을 그 DB 문법에 맞게 인용한다 (MySQL: `name`, MSSQL: [name]). */
    public function quoteIdentifier(string $name): string;

    /** offset/count로 페이징하는 절을 만든다. SQL 문 맨 끝에 그대로 이어붙여 쓴다. */
    public function limitClause(int $offset, int $count): string;

    /**
     * 테이블 스키마(PK/필드/타입/제약조건)를 조회해 Crud::$schema와 동일한
     * 형태({"tableName", "primaryKey", "fields"})로 반환한다. DB마다
     * information_schema 조회 방식이 달라(특히 PK/자동증가 판별) 이 메서드로 감싼다.
     *
     * @return array{tableName: string, primaryKey: ?string, fields: array<string, array>}
     */
    public function loadSchema(PDO $pdo, string $tableName): array;

    /**
     * [2026-08-26 추가 — 초성검색(4_1) 이식] "$columnName의 $position번째
     * 글자가 $char로 시작하는가"에 해당하는 SQL 조건 하나를 만든다(레거시
     * Access `getWhereFirstSoundSearch`/`getWhere` 이식 — src/js/
     * ChosungKeypad/chosungKeypad.md 참고). $char가 이 프로젝트가 다루는
     * 14개 단자음(ㄱㄴㄷㄹㅁㅂㅅㅇㅈㅊㅋㅌㅍㅎ)이면 그 초성 범위(유니코드
     * 완성형 한글이 초성마다 588개 코드포인트씩 연속 배치되는 성질을
     * 이용 — 원본 VBA의 문자열 BETWEEN 리터럴은 Access/Jet 콜레이션에
     * 의존해 MySQL/MSSQL로 그대로 못 옮기므로, 코드포인트 산술로
     * 재구현), 숫자/영문 한 글자면 정확히 그 글자와 같은지를 비교한다.
     * 지원하지 않는 문자(겹자음 등)면 항상 거짓인 조건을 반환해 안전하게
     * 무시한다. "글자를 코드포인트로 바꾸는 함수"가 DB마다 달라(MSSQL
     * `UNICODE()`, MySQL은 `ORD(CONVERT(... USING utf32))`) 이 인터페이스로
     * 감싼다 — Crud/Board의 다른 메서드들과 동일한 이유.
     */
    public function chosungCondition(string $columnName, int $position, string $char): string;

    /**
     * [2026-09-07 추가 — SP 클래스] 저장 프로시저를 호출하는 문장을 만든다.
     * $paramCount개의 위치 지정 플레이스홀더('?')를 담아 PDOStatement::execute()에
     * 값 배열을 그대로 넘길 수 있는 형태로 반환한다 — 호출 문법 자체가
     * DB마다 다르기 때문에(MySQL: `CALL name(?, ?)`, MSSQL: `EXEC name ?, ?`)
     * quoteIdentifier()/limitClause()와 같은 이유로 이 인터페이스에 감싼다.
     *
     * $procName은 스키마 접두어(예: 'dbo.procName')를 포함할 수 있어
     * quoteIdentifier()로 인용하지 않고 그대로 조립한다 — Domain::$domain과
     * 달리 여기서는 인용 처리를 하지 않으므로, 신뢰된 서버 코드에서만
     * 와야 한다(사용자 입력을 직접 연결하지 않는다).
     */
    public function callStatement(string $procName, int $paramCount): string;
}
