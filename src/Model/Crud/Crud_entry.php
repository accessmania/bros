<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/Crud.php';
require_once __DIR__ . '/../../db/DbContext.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

const TEST_TABLE = 't회원';

$error = null;
$schemaJson = null;
$recordCount = null;
$restoreChecks = [];

try {
    $db = new DbContext();
    $crud = new Crud($db);
    $crud->init(TEST_TABLE);

    $schemaJson = json_encode($crud->getSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    $recordCount = $crud->selectRecords('');

    // toJson()/fromJson() 왕복이 폴더 이동 후에도 여전히 동작하는지 확인
    // (test_cursor/test_view.php에서 이미 검증된 것과 같은 방식의 스모크 테스트).
    $rawJson = $crud->toJson();
    $restored = Crud::fromJson($db, $rawJson);

    $restoreChecks[] = [
        'label' => 'recordCount 일치',
        'pass'  => $restored->recordCount() === $crud->recordCount(),
    ];
    $restoreChecks[] = [
        'label' => 'cursor 위치 일치',
        'pass'  => $restored->getCursorPosition() === $crud->getCursorPosition(),
    ];
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>Crud 클래스 시험</title>
<style>
    body { font-family: sans-serif; max-width: 640px; margin: 2rem auto; }
    table { border-collapse: collapse; width: 100%; margin-bottom: 1rem; }
    th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: left; font-size: 14px; }
    .notice { padding: 10px; border-radius: 4px; margin-bottom: 1rem; }
    .notice.error { background: #fdecea; color: #c0392b; }
    .pass { color: #0a7a2a; font-weight: bold; }
    .fail { color: #c0392b; font-weight: bold; }
    pre {
        background: #f5f5f5; border: 1px solid #ccc; padding: 10px;
        overflow-x: auto; font-size: 13px; white-space: pre-wrap; word-break: break-all;
    }
</style>
</head>
<body>
<h1>Crud 클래스 시험 (<?= h(TEST_TABLE) ?>)</h1>
<p>이 페이지는 <code>src/Model/Crud/</code> 폴더로 이동한 뒤에도 <code>Crud</code>가
require 경로/의존 클래스(<code>SqlDialect*</code>)를 정상적으로 찾아 동작하는지
확인하는 스모크 테스트입니다.</p>

<?php if ($error !== null): ?>
    <div class="notice error"><?= h($error) ?></div>
<?php else: ?>
    <p>selectRecords('') 결과: <?= (int) $recordCount ?>건</p>

    <h2>getSchema()</h2>
    <pre><?= h((string) $schemaJson) ?></pre>

    <h2>toJson() / fromJson() 왕복 검증</h2>
    <table>
        <tr><th>항목</th><th>결과</th></tr>
        <?php foreach ($restoreChecks as $check): ?>
        <tr>
            <td><?= h($check['label']) ?></td>
            <td class="<?= $check['pass'] ? 'pass' : 'fail' ?>"><?= $check['pass'] ? 'PASS' : 'FAIL' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
</body>
</html>
