<?php

declare(strict_types=1);

// 서버 ini의 default_charset이 깨진 값일 수 있어 <meta charset>보다 우선하는
// HTTP 헤더를 명시적으로 선언한다(md/2026-08-09-crud-class.md류 항목에서
// 이미 겪은 함정 — 이 프로젝트 test 페이지들의 공통 관례).
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/Domain.php';
require_once __DIR__ . '/../Model/Crud/Crud.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

const TEST_TABLE = 't회원';
const TEST_PK = 'code';
const REPEAT_COUNT = 20;

$error = null;
$demo = [];
$perf = null;
$crossCheck = null;

try {
    $db = new DbContext();

    // 1) 기능 시연 — DMax로 "상위 1개 rec"의 키를 구하고, 그 키로 DLookup까지
    //    이어서 호출한다. 1_10(목록 없이 t회원 최상위 1건을 바로 여는 화면)이
    //    실제로 쓰게 될 흐름과 동일하다.
    $maxCode = Domain::max(TEST_PK, TEST_TABLE, null, $db);
    $minCode = Domain::min(TEST_PK, TEST_TABLE, null, $db);
    $topName = $maxCode !== null
        ? Domain::lookup('이름', TEST_TABLE, TEST_PK . ' = ' . (int) $maxCode, $db)
        : null;

    $demo = [
        ['label' => "DMax('" . TEST_PK . "', '" . TEST_TABLE . "')", 'value' => $maxCode],
        ['label' => "DMin('" . TEST_PK . "', '" . TEST_TABLE . "')", 'value' => $minCode],
        ['label' => "DLookup('이름', '" . TEST_TABLE . "', '" . TEST_PK . " = " . $maxCode . "')", 'value' => $topName],
    ];

    // 2) 성능 비교 — 같은 값(최댓값 code)을 두 가지 방식으로 REPEAT_COUNT번씩
    //    구해 평균 소요시간을 비교한다.
    //    (a) Domain::max() — 서버가 집계만 계산해 1행만 돌려줌.
    //    (b) Crud::selectRecords('')로 전체 테이블을 PHP로 가져온 뒤 배열에서
    //        직접 최댓값을 계산 — "Board/Crud로 통째로 끌어와 계산"하던
    //        예전 방식과 같은 비용 구조(이번 Domain 클래스를 만든 동기).
    $domainTimes = [];
    $domainResult = null;
    for ($i = 0; $i < REPEAT_COUNT; $i++) {
        $start = microtime(true);
        $domainResult = Domain::max(TEST_PK, TEST_TABLE, null, $db);
        $domainTimes[] = microtime(true) - $start;
    }

    $fullScanTimes = [];
    $fullScanResult = null;
    $crud = new Crud($db);
    $crud->init(TEST_TABLE);
    for ($i = 0; $i < REPEAT_COUNT; $i++) {
        $start = microtime(true);
        $crud->selectRecords('');
        $codes = [];
        // Crud는 커서 기반이라 전체 값을 뽑으려면 순회해야 한다(Board::getRows()와
        // 동일한 패턴) — 이 순회 비용 자체가 "예전 방식"의 실제 비용이다.
        $crud->moveFirst();
        while (!$crud->eof()) {
            $codes[] = $crud->getValue(TEST_PK);
            $crud->moveNext();
        }
        $fullScanResult = !empty($codes) ? max(array_map('intval', $codes)) : null;
        $fullScanTimes[] = microtime(true) - $start;
    }

    $avg = function (array $times): float {
        return empty($times) ? 0.0 : array_sum($times) / count($times);
    };

    $perf = [
        'domainAvgMs'   => $avg($domainTimes) * 1000,
        'fullScanAvgMs' => $avg($fullScanTimes) * 1000,
        'domainResult'  => $domainResult,
        'fullScanResult' => $fullScanResult,
    ];

    $crossCheck = ((string) $domainResult === (string) $fullScanResult);
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>Domain 클래스 시험 - DLookup/DMax/DMin</title>
<style>
    body { font-family: sans-serif; max-width: 720px; margin: 2rem auto; }
    table { border-collapse: collapse; width: 100%; margin-bottom: 1.5rem; }
    th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: left; font-size: 14px; }
    th { background: #f5f5f5; }
    .notice { padding: 10px; border-radius: 4px; margin-bottom: 1rem; }
    .notice.error { background: #fdecea; color: #c0392b; }
    .pass { color: #0a7a2a; font-weight: bold; }
    .fail { color: #c0392b; font-weight: bold; }
    .note { color: #666; font-size: 13px; }
</style>
</head>
<body>
<h1>Domain 클래스 시험 (DLookup / DMax / DMin)</h1>
<p class="note">대상 테이블: <code><?= h(TEST_TABLE) ?></code> / 키필드: <code><?= h(TEST_PK) ?></code> /
반복 횟수: <?= REPEAT_COUNT ?>회</p>

<?php if ($error !== null): ?>
    <div class="notice error"><?= h($error) ?></div>
<?php else: ?>

    <h2>1. 기능 시연</h2>
    <table>
        <tr><th>호출</th><th>결과</th></tr>
        <?php foreach ($demo as $row): ?>
        <tr>
            <td><code><?= h((string) $row['label']) ?></code></td>
            <td><?= h((string) ($row['value'] === null ? 'null' : $row['value'])) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <h2>2. 성능 비교 — DMax(집계 SQL) vs 전체 로드 후 PHP 계산</h2>
    <table>
        <tr><th>방식</th><th>평균 소요시간 (ms)</th><th>결과값</th></tr>
        <tr>
            <td><code>Domain::max()</code></td>
            <td><?= number_format($perf['domainAvgMs'], 4) ?></td>
            <td><?= h((string) $perf['domainResult']) ?></td>
        </tr>
        <tr>
            <td><code>Crud::selectRecords('')</code> 전체 로드 + PHP <code>max()</code></td>
            <td><?= number_format($perf['fullScanAvgMs'], 4) ?></td>
            <td><?= h((string) $perf['fullScanResult']) ?></td>
        </tr>
    </table>
    <p>
        두 방식의 결과값 일치:
        <?= $crossCheck ? '<span class="pass">PASS</span>' : '<span class="fail">FAIL</span>' ?>
    </p>
    <p class="note">
        <?= TEST_TABLE ?> 테이블 행 수가 적으면 두 방식의 차이가 작게(또는 오차 범위 안에서
        역전되어) 보일 수 있습니다 — 행 수가 늘어날수록 "전체 로드 후 계산" 쪽 비용만
        선형으로 커지고 <code>Domain::max()</code>는 거의 그대로인 것이 핵심 비교 지점입니다.
    </p>

<?php endif; ?>
</body>
</html>
