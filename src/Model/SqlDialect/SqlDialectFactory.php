<?php
declare(strict_types=1);

require_once __DIR__ . '/SqlDialect.php';
require_once __DIR__ . '/SqlDialectMysql.php';
require_once __DIR__ . '/SqlDialectMssql.php';

/**
 * PDO 연결이 스스로 밝히는 드라이버 이름(ATTR_DRIVER_NAME)으로 알맞은
 * SqlDialect를 골라준다. config/db.php의 'driver' 선택값을 Crud/Board에
 * 수동으로 넘길 필요가 없다 — Database::connect()가 만든 PDO를 넘기기만
 * 하면, 그 PDO가 이미 "나는 mysql이다/dblib이다"를 알고 있으므로 여기서
 * 자동으로 전파된다(config/Path.php가 자기 위치를 스스로 찾는 것과 같은
 * 원리를 DB 연결에 적용한 것).
 */
class SqlDialectFactory
{
    public static function forPdo(PDO $pdo): SqlDialect
    {
        $driverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        switch ($driverName) {
            case 'mysql':
                return new SqlDialectMysql();
            case 'dblib':
            case 'sqlsrv':
            case 'odbc':
                return new SqlDialectMssql();
            default:
                throw new RuntimeException("지원하지 않는 DB 드라이버입니다: {$driverName}");
        }
    }
}
