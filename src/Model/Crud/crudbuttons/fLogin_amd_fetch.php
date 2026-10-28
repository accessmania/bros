<?php
declare(strict_types=1);

/**
 * [fLogin_amd_fetch.php — crudbuttons 시범 화면(fLogin_amd.php)의 CrudJs 엔드포인트]
 * tLogin 한 건을 CrudJs 형식으로 읽고 쓴다. crudbuttons.php가 ?fetch=로 이 주소를
 * 받아 CrudJs(fetchUrl)에 그대로 넘긴다 — crudjs_fetch.php와 같은 계약이다.
 *
 * action:
 *  - schema                 — 스키마만 → {success, schema}.
 *  - selectRecord&pk=       — 한 건 → {success, schema, cursor:0, data:[행]}.
 *  - selectRecords          — 전체(이름 순) → {success, schema, cursor, data:[...]}
 *                             (crudbuttons_entry.php의 목록이 쓴다).
 *  - save (POST, JSON 행)   — PK 있으면 update, 없으면 insert → {success, pk}.
 *  - delete&pk= (POST)      — 삭제 → {success, affected}.
 *  - checkId&id=[&code=]    — 그 id를 다른 레코드(code가 아닌 것)가 이미 쓰는지 → {success, exists}
 *                             (fLogin_amd.php의 checkCrudSave — 저장 전 비동기 검사).
 *
 * 다루는 필드는 TLOGIN_FIELD_WHITELIST(+PK 자동)뿐이다 — 순수 텍스트 '권한'은
 * 권한code 콤보가 대신하므로 뺐다(basicdata/login과 같은 이유).
 * 응답은 전부 {success:true, ...} 또는 {success:false, error}.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Crud', 'Login');

// 독립 엔드포인트라 Layout(index)의 보호를 못 받으므로 여기서도 확인한다.
Login::isAlive();

header('Content-Type: application/json; charset=utf-8');

// 테이블은 클라이언트가 고르지 못하게 이 파일에 고정한다.
const TLOGIN_TABLE = 'tLogin';
// fLogin_amd.php에 id=필드명으로 놓인 컨트롤과 같아야 한다.
const TLOGIN_FIELD_WHITELIST = ['id', 'pw', '이름', '부서', '직급', '유선전화', '핸드폰', '이메일', '메모', '권한code', '사용여부', '지분률'];

$action = isset($_GET['action']) && $_GET['action'] !== '' ? (string) $_GET['action'] : 'schema';

try {
    $db = new DbContext(null); // config/db.php의 'driver' 기본 접속
    $crud = new Crud($db);
    $crud->init(TLOGIN_TABLE, TLOGIN_FIELD_WHITELIST);

    switch ($action) {
        case 'schema':
            echo json_encode(['success' => true, 'schema' => $crud->getSchema()], JSON_UNESCAPED_UNICODE);
            break;

        case 'selectRecord':
            $pk = isset($_GET['pk']) ? (string) $_GET['pk'] : '';
            if ($pk === '') {
                throw new InvalidArgumentException('pk가 필요합니다.');
            }
            if (!$crud->selectRecord($pk)) {
                echo json_encode(['success' => false, 'error' => '해당 레코드를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode([
                'success' => true,
                'schema' => $crud->getSchema(),
                'cursor' => 0,
                'data' => [$crud->getAllValues()],
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'selectRecords':
            // 사용자 입력 없이 서버가 고정한 조건만 쓴다(crudjs_fetch.php와 같은 원칙).
            $q = function (string $name) use ($db): string {
                return $db->dialect()->quoteIdentifier($name);
            };
            $crud->selectRecords($q((string) $crud->getPrimaryKey()) . ' IS NOT NULL ORDER BY ' . $q('이름') . ' ASC');
            $payload = json_decode($crud->toJson(), true);
            $payload['success'] = true;
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            break;

        case 'save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new InvalidArgumentException('POST로만 저장할 수 있습니다.');
            }
            $row = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($row)) {
                throw new InvalidArgumentException('요청 본문이 올바른 JSON이 아닙니다.');
            }
            // 화이트리스트 밖 필드는 스키마에 없어 loadArray()가 건너뛴다.
            $crud->loadArray($row);
            $pk = $crud->save();
            echo json_encode(['success' => true, 'pk' => $pk], JSON_UNESCAPED_UNICODE);
            break;

        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new InvalidArgumentException('POST로만 삭제할 수 있습니다.');
            }
            $pk = isset($_GET['pk']) ? (string) $_GET['pk'] : '';
            if ($pk === '') {
                throw new InvalidArgumentException('pk가 필요합니다.');
            }
            echo json_encode(['success' => true, 'affected' => $crud->deleteRecord($pk)], JSON_UNESCAPED_UNICODE);
            break;

        case 'checkId':
            $id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
            $code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
            $q = function (string $name) use ($db): string {
                return $db->dialect()->quoteIdentifier($name);
            };
            $sql = 'SELECT COUNT(*) FROM ' . $q(TLOGIN_TABLE) . ' WHERE ' . $q('id') . ' = ?';
            $params = [$id];
            // 고치는 중인 자기 자신은 빼고 센다.
            if ($code !== '' && ctype_digit($code)) {
                $sql .= ' AND ' . $q('code') . ' <> ?';
                $params[] = $code;
            }
            $stmt = $db->pdo()->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'exists' => (int) $stmt->fetchColumn() > 0], JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new InvalidArgumentException("알 수 없는 action입니다: {$action}");
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
