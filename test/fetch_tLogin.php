<?php
declare(strict_types=1);

/**
 * tLogin AGGrid용 CRUD 엔드포인트 — select(GET)/save·delete(POST).
 * src/js/aggrid/aggrid.md "서버 구현 패턴"을 그대로 따른다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Board', 'Crud', 'ActionBulk', 'BoardConfig');

$config = new BoardConfig();
$config->connectionName = null; // config/db.php의 'driver' 기본 접속을 따른다
$db = new DbContext($config->connectionName); // 이 요청의 DB 접속(처음 쓸 때 연결)
$config->tableName = 'tLogin';
$config->primaryKey = 'code';
$config->columns = 'code:번호,id,pw,이름,부서,직급,유선전화,핸드폰,이메일,권한code,권한,사용여부,지분률,메모'; // PK 포함 필수
$config->searchFields = 'id,이름,부서';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $crud = new Crud($db);
    (new ActionBulk($crud, $config))->handle(); // {results, deleteResults} 응답 후 exit
}

header('Content-Type: application/json; charset=utf-8');
$board = new Board($db);

if (isset($_GET['mode']) && $_GET['mode'] === 'all') {
    $board->init($config->tableName, $config->columns, $config->searchFields, $_GET['q'] ?? '', '', 1, 1000000, 1);
    echo json_encode(['rows' => $board->getRows()], JSON_UNESCAPED_UNICODE);
} else {
    $board->init($config->tableName, $config->columns, $config->searchFields, $_GET['q'] ?? '', '', null, null, 1);
    $totalRows = $board->getTotalRows();
    $lastRow = ($board->getPage() * $board->getPageSize() >= $totalRows) ? $totalRows : -1;
    echo json_encode(['rows' => $board->getRows(), 'lastRow' => $lastRow], JSON_UNESCAPED_UNICODE);
}
