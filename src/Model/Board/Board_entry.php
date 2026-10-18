<?php

declare(strict_types=1);

// 서버 ini의 default_charset이 깨진 값일 수 있어 <meta charset>보다 우선하는
// HTTP 헤더를 명시적으로 선언한다(이 프로젝트 test 페이지들의 공통 관례).
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/Board.php';
require_once __DIR__ . '/../../db/DbContext.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

const TEST_TABLE = 't회원';
const TEST_COLUMNS = 'code:번호,이름,나이,신장,몸무게';
const TEST_PAGE_SIZE = 5;

$error = null;
$infoHtml = null;
$bodyHtml = null;
$pagerHtml = null;
$json = null;

try {
    $board = new Board(new DbContext());
    // page/pageSize/groupSize를 명시적으로 넘겨서 $_GET/$_POST와 무관하게
    // 항상 같은 결과가 나오는 순수 스모크 테스트로 만든다(폴더 이동 후에도
    // require 경로가 정확히 맞물려 클래스가 정상 동작하는지 확인이 목적).
    $board->init(TEST_TABLE, TEST_COLUMNS, '', '', '', 1, TEST_PAGE_SIZE, 5);

    $infoHtml = $board->getDefaultInfoHTML();
    $bodyHtml = $board->getDefaultBodyHTML();
    $pagerHtml = $board->getDefaultPagerHTML();

    $json = json_encode(
        json_decode($board->toJson(), true),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>Board 클래스 시험</title>
<style>
    body { font-family: sans-serif; max-width: 640px; margin: 2rem auto; }
    table { border-collapse: collapse; width: 100%; margin-bottom: 1rem; }
    th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: left; font-size: 14px; }
    .notice { padding: 10px; border-radius: 4px; margin-bottom: 1rem; }
    .notice.error { background: #fdecea; color: #c0392b; }
    .pager a, .pager span { display: inline-block; padding: 4px 8px; margin-right: 4px; }
    pre {
        background: #f5f5f5; border: 1px solid #ccc; padding: 10px;
        overflow-x: auto; font-size: 13px; white-space: pre-wrap; word-break: break-all;
    }
</style>
</head>
<body>
<h1>Board 클래스 시험 (<?= h(TEST_TABLE) ?>)</h1>
<p>이 페이지는 <code>src/Model/Board/</code> 폴더로 이동한 뒤에도 <code>Board</code>가
require 경로/의존 클래스(<code>Crud</code>/<code>SqlDialect*</code>/<code>Str</code>)를
정상적으로 찾아 동작하는지 확인하는 스모크 테스트입니다.</p>

<?php if ($error !== null): ?>
    <div class="notice error"><?= h($error) ?></div>
<?php else: ?>
    <?= $infoHtml ?>
    <?= $bodyHtml ?>
    <div class="pager"><?= $pagerHtml ?></div>

    <h2>toJson()</h2>
    <pre><?= h((string) $json) ?></pre>
<?php endif; ?>
</body>
</html>
