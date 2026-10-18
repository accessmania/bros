<?php

declare(strict_types=1);

require_once __DIR__ . '/../SqlDialect/SqlDialect.php';
require_once __DIR__ . '/../../db/DbContext.php';

/**
 * 저장 프로시저(Stored Procedure) 호출 전용 정적 유틸 클래스 — Board/Crud처럼
 * 스키마를 인스턴스에 들고 있는 구조가 아니라, "프로시저 이름 + 파라미터를
 * 주면 결과셋 하나를 배열로 돌려준다"는 한 가지 일만 한다(Domain 클래스와
 * 같은 급의 상태 없는 정적 유틸리티). 자세한 스펙/설계 배경은 sp.md 참고.
 *
 * **호출 문법(CALL vs EXEC)만 SqlDialect::callStatement()로 감싸고, 그 외
 * 로직은 DB와 무관하게 동일**하다 — Domain::lookup()이 테이블명만
 * quoteIdentifier()에 위임하는 것과 같은 원칙.
 *
 * **다중 결과셋(nextRowset())은 의도적으로 지원하지 않는다** — 이 프로젝트가
 * MSSQL 접속에 쓰는 pdo_dblib(FreeTDS)는 다중 result set 순회가 불안정하다고
 * 알려져 있다(md/mssql-connection-test.md 등 이 프로젝트가 이미 겪은 dblib
 * 특유의 문법/기능 제약들과 같은 종류의 위험). 결과셋이 여러 개 필요하면
 * (예: 4_5 화면에서 4개 프로시저를 조합) `SP::execute()`를 여러 번 호출해서
 * 화면단(엔드포인트)이 조합한다 — "프로시저 1개 호출 = 결과셋 1개"만 이
 * 클래스가 책임진다.
 */
class SP
{
    /**
     * 프로시저를 실행하고 결과셋을 배열로 반환한다.
     *
     * $procName은 스키마 접두어(예: 'dbo.qGrid_3_매출최종')를 포함할 수
     * 있는 **raw 문자열**로 그대로 조립된다(인용 처리 없음) — Domain의
     * $expr/$criteria, Crud/Board의 조건 문자열과 같은 "신뢰된 서버
     * 코드만 호출, 사용자 입력을 직접 연결하지 않는다"는 관례를 그대로
     * 따른다.
     *
     * $params는 프로시저가 받는 순서 그대로의 위치 지정 파라미터
     * 배열이다(예: `[$date]`) — Crud::insert()/update()와 동일한 이유로
     * named placeholder가 아니라 positional placeholder('?')를 쓴다(PDO는
     * 파라미터 이름에 비ASCII 문자를 지원하지 않는데, 프로시저 파라미터
     * 이름 자체를 이 클래스가 알 방법이 없어 애초에 이름으로 바인딩하지
     * 않는다).
     *
     * $db를 생략하면 `new DbContext()`(config/db.php의 'driver'
     * 기본값 — 이 프로젝트는 현재 'mssql')를 쓴다. 특정 접속을 쓰려면
     * 페이지 상단에서 만든 `new DbContext('mssql')`를 넘긴다.
     *
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function execute(string $procName, array $params = [], ?DbContext $db = null): array
    {
        $db = self::resolveDb($db);
        $pdo = $db->pdo();
        $dialect = $db->dialect();

        $params = array_values($params);
        $sql = $dialect->callStatement($procName, count($params));

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return self::sanitizeUtf8($rows);
    }

    /**
     * [Board::sanitizeUtf8()와 동일한 목적] DB 컬럼이 varchar + 비UTF8
     * 콜레이션(한글 환경에서 흔한 CP949 계열)이면 PDO(dblib/FreeTDS)가
     * 돌려주는 문자열이 유효한 UTF-8이 아닐 수 있다 — 이 상태로
     * json_encode()에 넘기면 조용히 false를 반환해 응답이 빈 문자열이
     * 된다. 프로시저 결과도 Board가 직접 SELECT한 결과와 똑같이 이
     * 문제를 겪을 수 있어 여기서도 동일하게 방어한다. Board 쪽 코드를
     * 그대로 재사용하지 않고 따로 두는 이유는, SP가 Board에 의존하게
     * 만들 이유가 없어서다(반대로 Board가 SP의 결과를 받는 방향은 있어도
     * SP가 Board를 참조할 이유는 없다).
     *
     * [2026-09-09 수정] "1T(5) 선택시 웨이터가 표시안됨" 버그 조사 —
     * `deco_main_fetch.php`의 `select매출code`/`select매출_내용code`
     * (SP::execute()를 거치지 않고 `t매출`/`t매출_내용`을 직접 SELECT하는
     * 유일한 두 case)는 이 sanitizeUtf8()을 안 거쳐, CP949 콜레이션
     * 컬럼값이 있으면 json_encode()가 통째로 false를 반환 → 응답 자체가
     * 빈 문자열이 돼 클라이언트가 "Unexpected end of JSON input"으로
     * 실패하고 있었다(웨이터만이 아니라 그 매출 전체가 안 갱신됨,
     * 화면엔 이전/더미 값이 그대로 남아 마치 "웨이터만 비어있는" 것처럼
     * 보였을 뿐). private → public으로 바꿔 그 두 case에서도 재사용한다
     * (SP가 Board에 의존하게 되는 것은 아니라 위 원칙과 충돌하지 않음 —
     * `deco_main_fetch.php`가 SP에 의존하는 방향은 이미 이 파일 전체가
     * 그렇게 쓰고 있다).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function sanitizeUtf8(array $rows): array
    {
        foreach ($rows as &$row) {
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
        }
        unset($row);

        return $rows;
    }

    /** $db를 생략하면 config/db.php의 'driver' 기본 접속으로 DbContext를 만든다. */
    private static function resolveDb(?DbContext $db): DbContext
    {
        return $db !== null ? $db : new DbContext();
    }
}
