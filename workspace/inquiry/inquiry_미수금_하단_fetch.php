<?php
declare(strict_types=1);

/**
 * [미수금B 화면 아래 그리드의 조회 엔드포인트 — inquiry_미수금_entry.php(?g=B…)의 "미수금하단" 그리드]
 * Access subds2(f문의_미수금_bound)의 원본 q문의_미수금_bound와 같다:
 *   SELECT t문의.문의일자, t문의.고객사, t수주내역.*
 *   FROM t문의 INNER JOIN t수주내역 ON t문의.code = t수주내역.parentcode
 *   WHERE parentcode = p문의code
 *  - ?code=<t문의.code> — 위 그리드에서 더블클릭한 행의 parentcode. 그 문의의 수주 행 전부(code 오름차순).
 *    code가 비어 있으면 빈 목록(화면이 처음 뜰 때). 숫자가 아니면 400.
 *  - 조회만 한다 — POST는 400. 수정은 자세히보기(수주 amd)에서 한다.
 *  - t수주내역.*는 스키마(Crud::init)로 컬럼을 펼친다. 바이너리 컬럼(ts 등)은 뺀다.
 *    t수주내역에 고객사/문의일자 컬럼이 있어도 t문의의 값을 쓴다(Access 쿼리와 같은 이름이 겹치지 않게).
 *  - 날짜 타입 컬럼과 문의일자는 dblib 형식이라 Str::toDate()로 'Y-m-d'로 바꾼다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Crud', 'Login', 'Str');

// 독립 엔드포인트라 Layout(index)의 보호를 못 받으므로 여기서도 확인한다.
Login::isAlive();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '미수금B 아래 목록은 조회 전용입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
if ($code === '') {
    // 아직 위에서 고른 행이 없다 — 빈 목록.
    echo json_encode(['rows' => [], 'lastRow' => 0], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!ctype_digit($code)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '문의 code가 숫자가 아닙니다: ' . $code], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = new DbContext(null); // config/db.php의 'driver' 기본 접속
$dialect = $db->dialect();

try {
    $probe = new Crud($db);
    $probe->init('t수주내역');
    $schema = $probe->getSchema()['fields'];

    // SELECT 목록 — t문의.문의일자, t문의.고객사 + t수주내역의 나머지 컬럼(바이너리 제외).
    // 컬럼명은 DB 스키마에서 온 값이라 사용자 입력이 아니다. 값(code)만 ?로 넘긴다.
    $binaryTypes = ['timestamp', 'rowversion', 'binary', 'varbinary', 'image'];
    $dateTypes = ['datetime', 'date', 'smalldatetime', 'datetime2'];
    $selectParts = [
        $dialect->quoteIdentifier('t문의') . '.' . $dialect->quoteIdentifier('문의일자') . ' AS ' . $dialect->quoteIdentifier('문의일자'),
        $dialect->quoteIdentifier('t문의') . '.' . $dialect->quoteIdentifier('고객사') . ' AS ' . $dialect->quoteIdentifier('고객사'),
    ];
    $dateFields = ['문의일자'];
    foreach ($schema as $field => $info) {
        $type = strtolower((string) ($info['type'] ?? ''));
        if ($field === '문의일자' || $field === '고객사' || in_array($type, $binaryTypes, true)) {
            continue;
        }
        $selectParts[] = $dialect->quoteIdentifier('t수주내역') . '.' . $dialect->quoteIdentifier($field);
        if (in_array($type, $dateTypes, true)) {
            $dateFields[] = $field;
        }
    }

    $sql = 'SELECT ' . implode(', ', $selectParts)
        . ' FROM ' . $dialect->quoteIdentifier('t문의')
        . ' INNER JOIN ' . $dialect->quoteIdentifier('t수주내역')
        . ' ON ' . $dialect->quoteIdentifier('t문의') . '.' . $dialect->quoteIdentifier('code')
        . ' = ' . $dialect->quoteIdentifier('t수주내역') . '.' . $dialect->quoteIdentifier('parentcode')
        . ' WHERE ' . $dialect->quoteIdentifier('t수주내역') . '.' . $dialect->quoteIdentifier('parentcode') . ' = ?'
        . ' ORDER BY ' . $dialect->quoteIdentifier('t수주내역') . '.' . $dialect->quoteIdentifier('code') . ' ASC';

    $stmt = $db->pdo()->prepare($sql);
    $stmt->execute([$code]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        foreach ($dateFields as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = Str::toDate($row[$field], $row[$field]);
            }
        }
        $rows[] = $row;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '미수금B 아래 목록 조회 실패 — ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['rows' => $rows, 'lastRow' => count($rows)], JSON_UNESCAPED_UNICODE);
