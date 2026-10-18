<?php
declare(strict_types=1);

/**
 * talk.js 전용 엔드포인트 — mode로 조회/추가를 구분한다.
 *
 *   mode=select (기본, GET)  type + parentcode의 ttalk rows를 code 순 JSON 배열로
 *   mode=insert (POST)       type, parentcode, whocode, whoname, comment 한 건 추가
 *                            → {ok: true, code: 새 code}
 *
 * ttalk.code는 identity, datetime은 nvarchar라 'yyyy-mm-dd hh:mi:ss'(style 120)
 * 문자열로 서버 시각을 기록한다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext');

header('Content-Type: application/json; charset=utf-8');

$mode = isset($_REQUEST['mode']) ? (string) $_REQUEST['mode'] : 'select';
$type = isset($_REQUEST['type']) ? (string) $_REQUEST['type'] : '';
$parentcode = isset($_REQUEST['parentcode']) ? (string) $_REQUEST['parentcode'] : '';

try {
    $db = new DbContext('mssql');
    $pdo = $db->pdo();

    if ($mode === 'insert') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new InvalidArgumentException('insert는 POST로만 호출할 수 있습니다.');
        }
        $comment = isset($_POST['comment']) ? (string) $_POST['comment'] : '';
        if (trim($comment) === '') {
            throw new InvalidArgumentException('comment가 비어 있습니다.');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO ttalk (type, parentcode, whocode, whoname, comment, [datetime])
             VALUES (?, ?, ?, ?, ?, CONVERT(nvarchar(19), GETDATE(), 120))'
        );
        $stmt->execute([
            $type,
            $parentcode,
            isset($_POST['whocode']) ? (string) $_POST['whocode'] : '',
            isset($_POST['whoname']) ? (string) $_POST['whoname'] : '',
            $comment,
        ]);

        echo json_encode(['ok' => true, 'code' => $pdo->lastInsertId()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    if ($mode !== 'select') {
        throw new InvalidArgumentException("알 수 없는 mode입니다: {$mode}");
    }

    $stmt = $pdo->prepare(
        'SELECT *
           FROM ttalk
          WHERE type = ? AND parentcode = ?
          ORDER BY code ASC'
    );
    $stmt->execute([$type, $parentcode]);

    $rows = $stmt->fetchAll();
    // ts(rowversion)는 바이너리라 UTF-8이 아니어서 json_encode가 통째로
    // 실패한다 — 화면에 쓸 일이 없으니 빼고 내려준다.
    foreach ($rows as &$row) {
        unset($row['ts']);
    }
    unset($row);

    // 인코딩 실패를 빈 응답으로 삼키지 않고 catch로 보내 에러를 보이게 한다.
    echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
