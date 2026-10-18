<?php
declare(strict_types=1);

require_once __DIR__ . '/SqlDialect.php';

/**
 * MSSQL(SQL Server) 방언.
 *
 * MySQL의 information_schema.COLUMNS에는 COLUMN_KEY/EXTRA가 있어 PK/자동증가를
 * 그 안에서 바로 알 수 있지만, MSSQL의 INFORMATION_SCHEMA.COLUMNS에는 이
 * 컬럼들이 없다. 그래서 PK는 TABLE_CONSTRAINTS+KEY_COLUMN_USAGE 조인으로,
 * 자동증가(IDENTITY)는 sys.identity_columns로 각각 별도 조회해서 합친다.
 */
class SqlDialectMssql implements SqlDialect
{
    public function quoteIdentifier(string $name): string
    {
        return '[' . str_replace(']', ']]', $name) . ']';
    }

    public function limitClause(int $offset, int $count): string
    {
        // OFFSET/FETCH는 SQL Server 2012+ 문법. ORDER BY가 반드시 있어야
        // 유효한데, Board::resolveOrderBy()가 항상 기본 정렬(PK DESC)을
        // 채워주므로(PK가 있는 테이블이라면) 문제없다.
        return sprintf('OFFSET %d ROWS FETCH NEXT %d ROWS ONLY', $offset, $count);
    }

    public function loadSchema(PDO $pdo, string $tableName): array
    {
        $sql = 'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH, COLUMN_DEFAULT
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_NAME = :tableName
                ORDER BY ORDINAL_POSITION';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['tableName' => $tableName]);
        $columns = $stmt->fetchAll();

        if (!$columns) {
            throw new RuntimeException("테이블을 찾을 수 없습니다: {$tableName}");
        }

        $pkSql = "SELECT ku.COLUMN_NAME
                  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                  JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE ku
                    ON tc.CONSTRAINT_NAME = ku.CONSTRAINT_NAME AND tc.TABLE_NAME = ku.TABLE_NAME
                  WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY' AND tc.TABLE_NAME = :tableName";
        $pkStmt = $pdo->prepare($pkSql);
        $pkStmt->execute(['tableName' => $tableName]);
        $primaryKeyColumns = $pkStmt->fetchAll(PDO::FETCH_COLUMN);
        $primaryKey = isset($primaryKeyColumns[0]) ? $primaryKeyColumns[0] : null;

        // 이 프로젝트의 테이블들은 관례상 키 컬럼명이 거의 항상 'code'다
        // (tLogin/tAuth/t회원 등 기존에 확인된 전부가 그랬음). 그런데도
        // 위 TABLE_CONSTRAINTS+KEY_COLUMN_USAGE 조인으로 PRIMARY KEY
        // 제약을 못 찾는 테이블이 실제로 있었다(2026-08-26, t팀에서
        // 발견 — SSMS 화면상 "키" 아이콘은 보이는데 정식 PRIMARY KEY
        // 제약으로는 안 잡힘, 원인 미상). 그런 경우 이 필드가 null로
        // 남으면 AGGrid.js 쪽에서 행 식별자가 전부 겹쳐(getRowId 충돌)
        // 화면에 빈 값만 나오는 문제로 이어지므로, 정식 PK 제약을 못
        // 찾았을 때는 실제로 'code' 컬럼이 있는지 확인해 있으면 그걸
        // 기본 키로 간주한다.
        if ($primaryKey === null) {
            foreach ($columns as $col) {
                if ($col['COLUMN_NAME'] === 'code') {
                    $primaryKey = 'code';
                    break;
                }
            }
        }

        $identitySql = 'SELECT c.name
                        FROM sys.identity_columns c
                        JOIN sys.tables t ON c.object_id = t.object_id
                        WHERE t.name = :tableName';
        $identityStmt = $pdo->prepare($identitySql);
        $identityStmt->execute(['tableName' => $tableName]);
        $identityColumns = $identityStmt->fetchAll(PDO::FETCH_COLUMN);

        $fields = [];
        foreach ($columns as $col) {
            $name = $col['COLUMN_NAME'];
            // [2026-09-17 수정, 버그 발견] NVARCHAR(MAX)/VARCHAR(MAX)/TEXT
            // 계열 컬럼은 MSSQL의 INFORMATION_SCHEMA.COLUMNS에서
            // CHARACTER_MAXIMUM_LENGTH가 "무제한"이라는 뜻으로 -1을
            // 반환한다(SQL Server 고유 관례) — 이걸 그대로 정수 -1로
            // 내보내면, 그 값이 "글자 수 제한이 없다"는 뜻인 줄 모르는
            // 소비자(예: `crudjs.js`의 `validateSchema()` — `meta.maxLength`
            // 를 참/거짓만으로 판단해 -1도 "제한 있음"으로 오해)가
            // "모든 값이 -1자를 넘었다"고 오판하는 사고로 이어진다
            // (deco_amd_income_popup.php의 메모란에 값을 넣으면 항상
            // "메모: -1자를 넘었습니다"로 저장이 막히던 문제로 실제
            // 발견됨). "제한 없음"을 뜻하는 값은 이 프로젝트 전체가
            // 이미 `null`로 다루므로, 여기서 음수를 `null`로 정규화해
            // 그 관례를 유지한다 — 이 스키마를 쓰는 모든 소비자가 한
            // 번에 안전해진다(crudjs.js 등 개별 소비자 쪽을 일일이
            // 고칠 필요 없음).
            $characterMaxLength = $col['CHARACTER_MAXIMUM_LENGTH'];
            $maxLength = null;
            if ($characterMaxLength !== null && (int) $characterMaxLength > 0) {
                $maxLength = (int) $characterMaxLength;
            }
            $fields[$name] = [
                'type'          => $col['DATA_TYPE'],
                'nullable'      => $col['IS_NULLABLE'] === 'YES',
                'maxLength'     => $maxLength,
                'default'       => $col['COLUMN_DEFAULT'],
                'autoIncrement' => in_array($name, $identityColumns, true),
                'isPrimaryKey'  => $name === $primaryKey,
            ];
        }

        return [
            'tableName'  => $tableName,
            'primaryKey' => $primaryKey,
            'fields'     => $fields,
        ];
    }

    public function chosungCondition(string $columnName, int $position, string $char): string
    {
        $quotedColumn = $this->quoteIdentifier($columnName);
        $lengthCheck = sprintf("LEN(ISNULL(%s, '')) >= %d", $quotedColumn, $position);
        $codepointExpr = sprintf('UNICODE(SUBSTRING(%s, %d, 1))', $quotedColumn, $position);

        $chosungIndex = self::chosungIndexMap();
        if (isset($chosungIndex[$char])) {
            $rangeStart = 0xAC00 + $chosungIndex[$char] * 588;
            $rangeEnd = $rangeStart + 587;
            return sprintf('(%s AND %s BETWEEN %d AND %d)', $lengthCheck, $codepointExpr, $rangeStart, $rangeEnd);
        }

        if (preg_match('/^[A-Za-z0-9]$/', $char) === 1) {
            $escapedChar = str_replace("'", "''", $char);
            return sprintf("(%s AND SUBSTRING(%s, %d, 1) = '%s')", $lengthCheck, $quotedColumn, $position, $escapedChar);
        }

        return '1 = 0';
    }

    /**
     * 한글 초성(단자음) → 유니코드 완성형 한글 인덱스(0~18 중 겹자음
     * 자리를 뺀 14개). 코드포인트 산술 자체는 DB와 무관한 유니코드
     * 공통 규칙이라 SqlDialectMysql에도 같은 값의 맵이 별도로 있다
     * (dialect 구현체끼리 서로 참조하지 않게 하려고 각자 자기 파일 안에
     * 둠 — SQL 표현식만 dialect마다 다르고 이 맵 자체는 우연히 같음).
     *
     * @return array<string, int>
     */
    private static function chosungIndexMap(): array
    {
        return [
            'ㄱ' => 0, 'ㄴ' => 2, 'ㄷ' => 3, 'ㄹ' => 5, 'ㅁ' => 6, 'ㅂ' => 7,
            'ㅅ' => 9, 'ㅇ' => 11, 'ㅈ' => 12, 'ㅊ' => 14, 'ㅋ' => 15, 'ㅌ' => 16,
            'ㅍ' => 17, 'ㅎ' => 18,
        ];
    }

    /**
     * [2026-09-07 추가] MSSQL은 `EXEC name ?, ?, ...` 형태(괄호 없음).
     * $paramCount가 0이면 플레이스홀더 없이 프로시저 이름만 남긴다(뒤에
     * 불필요한 공백이 남지 않도록).
     */
    public function callStatement(string $procName, int $paramCount): string
    {
        if ($paramCount === 0) {
            return sprintf('EXEC %s', $procName);
        }
        $placeholders = implode(', ', array_fill(0, $paramCount, '?'));
        return sprintf('EXEC %s %s', $procName, $placeholders);
    }
}
