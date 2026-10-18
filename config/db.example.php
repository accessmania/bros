<?php

declare(strict_types=1);

/**
 * DB 접속 정보 예시 — 이 파일을 같은 폴더에 `db.php`로 복사한 뒤 실제
 * 값을 채운다. `db.php`는 비밀번호가 들어 있어 Git에 올리지 않는다
 * (.gitignore). 키 구조/설명은 `db.php` 상단 주석과 동일하다.
 */
return [
    // 화면이 접속 이름을 따로 정하지 않으면(Database::connect(null)) 이 값을 쓴다.
    'driver' => 'mssql',

    'mssql' => [
        'type'   => 'mssql',
        'host'   => '127.0.0.1',
        'port'   => 1433,
        'dbname' => 'DBNAME',
        'user'   => 'USER',
        'pass'   => 'PASSWORD',
        // null이면 Database.php가 TDSVER를 건드리지 않고 freetds.conf 기본값을 쓴다.
        'tdsVersion' => null,
    ],
];
