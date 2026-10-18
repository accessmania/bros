<?php
declare(strict_types=1);

require_once __DIR__ . '/SqlDialect.php';

/**
 * MySQL(MariaDB 포함) 방언. 기존 Crud::init()에 있던 information_schema
 * 조회 로직을 그대로 옮겨왔다(동작 변화 없음).
 */
class SqlDialectMysql implements SqlDialect
{
    public function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function limitClause(int $offset, int $count): string
    {
        return sprintf('LIMIT %d, %d', $offset, $count);
    }

    public function loadSchema(PDO $pdo, string $tableName): array
    {
        $sql = 'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH,
                       COLUMN_KEY, COLUMN_DEFAULT, EXTRA
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tableName
                ORDER BY ORDINAL_POSITION';

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['tableName' => $tableName]);
        $columns = $stmt->fetchAll();

        if (!$columns) {
            throw new RuntimeException("테이블을 찾을 수 없습니다: {$tableName}");
        }

        $fields = [];
        $primaryKey = null;

        foreach ($columns as $col) {
            $name = $col['COLUMN_NAME'];
            $isPrimaryKey = $col['COLUMN_KEY'] === 'PRI';

            $fields[$name] = [
                'type'          => $col['DATA_TYPE'],
                'nullable'      => $col['IS_NULLABLE'] === 'YES',
                'maxLength'     => $col['CHARACTER_MAXIMUM_LENGTH'] !== null
                    ? (int) $col['CHARACTER_MAXIMUM_LENGTH']
                    : null,
                'default'       => $col['COLUMN_DEFAULT'],
                'autoIncrement' => strpos((string) $col['EXTRA'], 'auto_increment') !== false,
                'isPrimaryKey'  => $isPrimaryKey,
            ];

            if ($isPrimaryKey) {
                $primaryKey = $name;
            }
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
        $lengthCheck = sprintf("CHAR_LENGTH(IFNULL(%s, '')) >= %d", $quotedColumn, $position);
        // MySQL엔 MSSQL의 UNICODE()에 대응하는 함수가 없어, 문자를 UTF-32로
        // 변환한 뒤 ORD()로 그 4바이트 값(=코드포인트)을 읽는다.
        $codepointExpr = sprintf('ORD(CONVERT(SUBSTRING(%s, %d, 1) USING utf32))', $quotedColumn, $position);

        $chosungIndex = [
            'ㄱ' => 0, 'ㄴ' => 2, 'ㄷ' => 3, 'ㄹ' => 5, 'ㅁ' => 6, 'ㅂ' => 7,
            'ㅅ' => 9, 'ㅇ' => 11, 'ㅈ' => 12, 'ㅊ' => 14, 'ㅋ' => 15, 'ㅌ' => 16,
            'ㅍ' => 17, 'ㅎ' => 18,
        ];
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
     * [2026-09-07 추가] MySQL은 `CALL name(?, ?, ...)` 형태.
     */
    public function callStatement(string $procName, int $paramCount): string
    {
        $placeholders = implode(', ', array_fill(0, $paramCount, '?'));
        return sprintf('CALL %s(%s)', $procName, $placeholders);
    }
}
