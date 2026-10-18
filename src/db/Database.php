<?php

declare(strict_types=1);

/**
 * PDO 연결을 만들어주는 팩토리.
 *
 * config/db.php는 이름별로 접속 정보 블록을 여러 개 가질 수 있다(예: 'mysql',
 * 'mssql', 'mssql_deco' — 같은 MSSQL 서버라도 DB가 다르면 이름을 따로 둠).
 * connect($name)의 $name을 생략하면 config의 'driver' 값을 기본으로 쓴다.
 * 화면이 특정 접속을 항상 써야 하면(예: tLogin은 항상 'mssql_deco') 그
 * 이름을 명시적으로 넘긴다 — 그러면 'driver' 기본값이 뭐든 그 화면은
 * 항상 지정한 DB로만 붙는다.
 *
 * 어떤 DSN을 조립할지는 이름 자체가 아니라 그 블록의 'type'('mysql'|'mssql')
 * 필드로 판단한다 — 'mssql'/'mssql_deco'처럼 이름이 여러 개여도 전부 MSSQL
 * DSN 규칙을 타게 하기 위함.
 */
class Database
{
    /** @var array<string, PDO> 접속 이름 => 연결 */
    private static $connections = [];

    public static function connect(?string $name = null): PDO
    {
        // 이 파일은 src/db/ 밑에 있으므로 두 단계 위가 프로젝트 루트다.
        $config = require __DIR__ . '/../../config/db.php';

        if ($name === null) {
            $name = isset($config['driver']) ? $config['driver'] : 'mysql';
        }

        if (isset(self::$connections[$name])) {
            return self::$connections[$name];
        }

        if (!isset($config[$name])) {
            throw new RuntimeException("config/db.php에 '{$name}' 접속 정보가 없습니다.");
        }
        $c = $config[$name];
        $type = isset($c['type']) ? $c['type'] : 'mysql';

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if ($type === 'mssql') {
            // pdo_dblib(FreeTDS)는 freetds.conf의 기본 TDS 프로토콜 버전이 이
            // SQL Server와 안 맞으면 접속 자체가 실패한다(md/mssql-connection-test.md
            // 참고) — config에 확인된 버전을 채워두면 요청 단위로 오버라이드한다.
            if (!empty($c['tdsVersion'])) {
                putenv('TDSVER=' . $c['tdsVersion']);
            }
            $dsn = sprintf('dblib:host=%s:%d;dbname=%s;charset=UTF-8', $c['host'], $c['port'], $c['dbname']);
            // pdo_dblib는 네이티브 prepare 지원이 불안정해서 PDO의 기본값(에뮬레이션
            // 모드)을 그대로 둔다 — EMULATE_PREPARES를 false로 강제하지 않음.
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'],
                $c['port'],
                $c['dbname'],
                $c['charset']
            );
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
        }

        self::$connections[$name] = new PDO($dsn, $c['user'], $c['pass'], $options);

        return self::$connections[$name];
    }
}
