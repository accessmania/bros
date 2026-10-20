<?php
declare(strict_types=1);

/**
 * [AGGrid/팝업용 CRUD 엔드포인트 — 필드감추기(tfieldvisible_parent + tfieldvisible)]
 * 화면은 fieldvisible_entry.php. 테이블이 둘이라 `?t=`로 고른다(상태
 * 화면의 status_fetch.php와 같은 방식, 목록에 없는 값은 거부):
 *  - t=parent — tfieldvisible_parent(명칭/메모). 목록 그리드 + 팝업 위쪽 저장/삭제.
 *    삭제는 자식(tfieldvisible, parentcode = 부모 code)도 한 트랜잭션으로 같이 지운다.
 *  - t=child  — tfieldvisible(테이블/필드/감추기). 팝업 아래쪽 그리드.
 *    `&parentcode=<부모 키>`로 그 부모의 행만 조회한다(연결키 parentcode).
 *    `&action=init_children`(POST) — 그 부모의 자식 행이 없으면
 *    tfieldvisible_source에서 채운다(팝업이 열릴 때 호출, 아래 참고).
 *
 * 서버 계약은 다른 기초자료형 fetch와 같다(aggrid.md "서버 계약").
 * 기본 정렬은 code 오름차순(code 필드가 없으면 PK).
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Board', 'Crud', 'ActionBulk', 'BoardConfig');

$tables = [
    'parent' => 'tfieldvisible_parent',
    'child'  => 'tfieldvisible',
];
$tableKey = isset($_GET['t']) ? (string) $_GET['t'] : '';
if (!isset($tables[$tableKey])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '알 수 없는 테이블입니다: ' . $tableKey], JSON_UNESCAPED_UNICODE);
    exit;
}

$connectionName = null; // config/db.php의 'driver' 기본 접속을 따른다
$db = new DbContext($connectionName); // 이 요청의 DB 접속(처음 쓸 때 연결)
$tableName = $tables[$tableKey];

$probe = new Crud($db);
$probe->init($tableName);
$primaryKey = $probe->getPrimaryKey();
$allFields = array_keys($probe->getSchema()['fields']);

$config = new BoardConfig();
$config->connectionName = $connectionName;
$config->tableName = $tableName;
$config->primaryKey = $primaryKey;
$config->columns = implode(',', $allFields);
$config->searchFields = implode(',', $allFields);
$config->fields = $allFields;

// 기본 정렬은 code 오름차순 — code 필드가 없으면 PK로. 헤더로 정렬하면 그쪽이 우선.
$sortField = in_array('code', $allFields, true) ? 'code' : $primaryKey;
$defaultOrderBy = $sortField !== null ? $db->dialect()->quoteIdentifier($sortField) . ' ASC' : '';
// child는 Access 원본과 같은 순서 — order by tablename, fieldname(2026-10-20 지시).
if ($tableKey === 'child') {
    $defaultOrderBy = $db->dialect()->quoteIdentifier('tablename') . ' ASC, '
        . $db->dialect()->quoteIdentifier('fieldname') . ' ASC';
}

// child는 부모 하나의 행만 — parentcode 값은 PDO quote로 넣는다. 부모가
// 없는(신규추가 직후, 아직 저장 전) 경우 parentcode가 비어 오면 빈 목록.
$extraCondition = '';
if ($tableKey === 'child') {
    $parentCode = isset($_GET['parentcode']) ? (string) $_GET['parentcode'] : '';
    $extraCondition = $parentCode === ''
        ? '1 = 0'
        : $db->dialect()->quoteIdentifier('parentcode') . ' = ' . $db->pdo()->quote($parentCode);
}

// --- init_children — 팝업이 열릴 때 자식 행 채우기(Access VBA 이식, 2026-10-20) ---
// 원본 VBA: Me.code가 없으면 종료. tfieldvisible에 parentcode = code인 행이
// 하나도 없을 때만, tfieldvisible_source 중 tfieldvisible.sourcecode로 아직
// 연결되지 않은 행(left join ... where sourcecode is null, code 순)을
// tfieldvisible(tablename, fieldname, visiblefalse, nickname, parentcode)로
// 넣는다. "없으면 넣기"를 SQL 한 문장(IF NOT EXISTS)으로 해서 확인과 삽입
// 사이에 다른 요청이 끼어들 틈을 없앴다. POST로만 받는다.
if ($tableKey === 'child' && isset($_GET['action']) && $_GET['action'] === 'init_children') {
    header('Content-Type: application/json; charset=utf-8');
    $parentCode = isset($_GET['parentcode']) ? trim((string) $_GET['parentcode']) : '';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $parentCode === '' || !ctype_digit($parentCode)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'POST와 숫자 parentcode가 필요합니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $stmt = $db->pdo()->prepare(
            'IF NOT EXISTS (SELECT 1 FROM tfieldvisible WHERE parentcode = :p1)
             INSERT INTO tfieldvisible (tablename, fieldname, visiblefalse, nickname, parentcode)
             SELECT s.tablename, s.fieldname, s.visiblefalse, s.nickname, :p2
             FROM tfieldvisible_source s
             LEFT JOIN tfieldvisible v ON s.code = v.sourcecode
             WHERE v.sourcecode IS NULL
             ORDER BY s.code'
        );
        $stmt->execute(['p1' => (int) $parentCode, 'p2' => (int) $parentCode]);
        echo json_encode(['success' => true, 'inserted' => $stmt->rowCount()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// --- action 결정 (login_fetch.php와 동일한 방식) -----------------------
$validActions = ['list_paged', 'list_all', 'save', 'delete'];
$action = isset($_GET['action']) && in_array($_GET['action'], $validActions, true)
    ? $_GET['action']
    : ($_SERVER['REQUEST_METHOD'] === 'POST'
        ? 'save'
        : ((isset($_GET['mode']) && $_GET['mode'] === 'all') ? 'list_all' : 'list_paged'));

// --- 부모 삭제 — 자식(tfieldvisible, parentcode = 부모 code)도 같이 지운다 ---
// (2026-10-20 지시) ActionBulk는 부모만 지우므로 여기서 직접 처리한다. 자식과
// 부모를 한 트랜잭션으로 지워, 부모 삭제가 실패하면 자식 삭제도 되돌린다.
// 응답은 ActionBulk와 같은 {results, deleteResults} 형식.
if ($tableKey === 'parent' && $action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $deletedCodes = isset($payload['deletedCodes']) && is_array($payload['deletedCodes']) ? $payload['deletedCodes'] : [];
    $pdo = $db->pdo();
    $quotedPk = $db->dialect()->quoteIdentifier((string) $primaryKey);
    $deleteResults = [];
    foreach ($deletedCodes as $code) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                'DELETE FROM tfieldvisible WHERE parentcode IN (SELECT code FROM tfieldvisible_parent WHERE ' . $quotedPk . ' = :k)'
            )->execute(['k' => (string) $code]);
            $deleteParent = $pdo->prepare('DELETE FROM tfieldvisible_parent WHERE ' . $quotedPk . ' = :k');
            $deleteParent->execute(['k' => (string) $code]);
            if ($deleteParent->rowCount() === 0) {
                throw new RuntimeException('삭제할 항목을 찾을 수 없습니다: ' . $code);
            }
            $pdo->commit();
            $deleteResults[] = ['code' => $code, 'success' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $deleteResults[] = ['code' => $code, 'success' => false, 'message' => $e->getMessage()];
        }
    }
    echo json_encode(['results' => [], 'deleteResults' => $deleteResults], JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($action) {
    case 'save':
    case 'delete':
        // ActionBulk::handle()이 body의 rows/deletedCodes를 보고 저장/삭제를
        // 처리하고 {results(newCode 포함), deleteResults} JSON을 응답한 뒤 exit한다.
        $crud = new Crud($db);
        (new ActionBulk($crud, $config))->handle();
        break;

    case 'list_all':
        header('Content-Type: application/json; charset=utf-8');
        $board = new Board($db);
        $keyword = isset($_GET['q']) ? (string) $_GET['q'] : '';
        $board->init($config->tableName, $config->columns, $config->searchFields, $keyword, '', 1, 1000000, 1, $extraCondition, $defaultOrderBy);
        echo json_encode(['rows' => $board->getRows()], JSON_UNESCAPED_UNICODE);
        break;

    case 'list_paged':
    default:
        // page/pageSize/sort/dir는 Board::init()이 $_GET에서 직접 읽는다.
        header('Content-Type: application/json; charset=utf-8');
        $board = new Board($db);
        $keyword = isset($_GET['q']) ? (string) $_GET['q'] : '';
        $board->init($config->tableName, $config->columns, $config->searchFields, $keyword, '', null, null, 1, $extraCondition, $defaultOrderBy);
        $totalRows = $board->getTotalRows();
        $lastRow = ($board->getPage() * $board->getPageSize() >= $totalRows) ? $totalRows : -1;
        echo json_encode(['rows' => $board->getRows(), 'lastRow' => $lastRow], JSON_UNESCAPED_UNICODE);
        break;
}
