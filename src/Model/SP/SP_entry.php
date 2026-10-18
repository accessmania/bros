<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/SP.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * 이 프로젝트가 실제로 다루는 deco DB의 프로시저 4개 — 전부 날짜 파라미터
 * 하나만 받는다. "빠른 호출" 버튼이 이 목록을 그대로 써서 프로시저명 입력칸을
 * 채워준다(값은 미리 채우기만, 실행은 사용자가 직접 "실행" 버튼을 눌러야 함 —
 * SP는 쓰기 프로시저도 호출할 수 있는 범용 도구라 자동 실행은 하지 않는다).
 *
 * @var array<int, string>
 */
const KNOWN_PROCS = [
    'qGrid_3_매출최종',
    'getSQL가용아가씨',
    'getSQL투입아가씨',
    'getStats',
];

$connectionName = isset($_GET['conn']) && $_GET['conn'] !== '' ? (string) $_GET['conn'] : 'mssql';
$procName = isset($_GET['proc']) ? trim((string) $_GET['proc']) : '';
$paramsRaw = isset($_GET['params']) ? (string) $_GET['params'] : '';

$rows = null;
$error = null;
$sqlPreview = null;
$elapsedMs = null;

if ($procName !== '') {
    // 콤마로 구분된 파라미터를 그대로 문자열 배열로 넘긴다 — 프로시저가
    // 실제로 기대하는 타입(날짜/정수 등)으로의 변환은 SQL 엔진이 암묵
    // 변환으로 처리하는 걸 전제로 한다(이 연습 화면은 타입을 안 가림).
    $params = [];
    if (trim($paramsRaw) !== '') {
        foreach (explode(',', $paramsRaw) as $p) {
            $params[] = trim($p);
        }
    }

    try {
        $db = new DbContext($connectionName);
        $dialect = $db->dialect();
        $sqlPreview = $dialect->callStatement($procName, count($params));

        $start = microtime(true);
        $rows = SP::execute($procName, $params, $db);
        $elapsedMs = (microtime(true) - $start) * 1000;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>SP 클래스 시험 - 저장 프로시저 호출 연습</title>
<style>
    body { font-family: sans-serif; max-width: 920px; margin: 2rem auto; padding: 0 1rem; }
    fieldset { margin-bottom: 1.2rem; border: 1px solid #ddd; border-radius: 6px; }
    legend { font-weight: bold; padding: 0 6px; }
    label { display: inline-block; min-width: 90px; }
    input[type="text"], select {
        padding: 5px 8px; font-size: 14px; border: 1px solid #ccc; border-radius: 4px;
    }
    .row { margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
    .row input[type="text"] { flex: 1; }
    button {
        padding: 6px 14px; border-radius: 4px; border: 1px solid #2563eb;
        background: #2563eb; color: #fff; font-size: 14px; cursor: pointer;
    }
    button:hover { background: #1d4ed8; }
    .preset-btn {
        background: #f3f4f6; color: #1f2937; border: 1px solid #d1d5db;
        margin: 2px; font-size: 13px; padding: 4px 10px;
    }
    .preset-btn:hover { background: #e5e7eb; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; }
    th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: left; font-size: 13px; }
    th { background: #f5f5f5; }
    .notice { padding: 10px; border-radius: 4px; margin-bottom: 1rem; }
    .notice.error { background: #fdecea; color: #c0392b; }
    .note { color: #666; font-size: 13px; }
    pre {
        background: #f5f5f5; border: 1px solid #ccc; padding: 10px;
        overflow-x: auto; font-size: 13px; white-space: pre-wrap; word-break: break-all;
    }
    code { background: #f0f0f0; padding: 1px 4px; border-radius: 3px; }
</style>
</head>
<body>
<h1>SP 클래스 시험 — 저장 프로시저 호출 연습</h1>
<p class="note">
    <code>SP::execute($procName, $params, $db)</code>를 화면에서 직접 호출해보는 도구.
    아래 "빠른 호출"은 이 프로젝트가 실제로 갖고 있는 deco DB 프로시저 4개(전부 날짜
    파라미터 1개)의 이름만 채워준다 — 실행은 "실행" 버튼을 직접 눌러야 한다.
</p>

<fieldset>
<legend>빠른 호출 (deco DB, 날짜 파라미터 1개)</legend>
<div class="row">
    <?php foreach (KNOWN_PROCS as $p): ?>
        <button type="button" class="preset-btn" onclick="fillProc('<?= h($p) ?>')"><?= h($p) ?></button>
    <?php endforeach; ?>
</div>
</fieldset>

<form method="get">
<fieldset>
<legend>프로시저 호출</legend>
<div class="row">
    <label for="sp-proc">프로시저명</label>
    <input type="text" id="sp-proc" name="proc" value="<?= h($procName) ?>" placeholder="예: qGrid_3_매출최종 (dbo. 접두어 포함 가능)">
</div>
<div class="row">
    <label for="sp-params">파라미터</label>
    <input type="text" id="sp-params" name="params" value="<?= h($paramsRaw) ?>" placeholder="콤마로 구분, 예: 2026-09-07">
</div>
<div class="row">
    <label for="sp-conn">접속</label>
    <select id="sp-conn" name="conn">
        <?php foreach (['mssql'] as $name): ?>
            <option value="<?= h($name) ?>" <?= $connectionName === $name ? 'selected' : '' ?>><?= h($name) ?></option>
        <?php endforeach; ?>
    </select>
</div>
<div class="row">
    <button type="submit">실행</button>
</div>
</fieldset>
</form>

<script>
function fillProc(name) {
    document.getElementById('sp-proc').value = name;
    document.getElementById('sp-params').value = new Date().toISOString().slice(0, 10);
}
</script>

<?php if ($error !== null): ?>
    <div class="notice error"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($sqlPreview !== null): ?>
    <p class="note">실제 조립된 호출문(<code>SqlDialect::callStatement()</code> 결과):
        <code><?= h($sqlPreview) ?></code></p>
<?php endif; ?>

<?php if ($rows !== null): ?>
    <p class="note">결과 <?= count($rows) ?>행, <?= number_format((float) $elapsedMs, 2) ?>ms</p>

    <?php if (empty($rows)): ?>
        <p>결과가 없습니다(빈 배열).</p>
    <?php else: ?>
        <h2>표 형태</h2>
        <table>
            <tr>
                <?php foreach (array_keys($rows[0]) as $col): ?>
                    <th><?= h((string) $col) ?></th>
                <?php endforeach; ?>
            </tr>
            <?php foreach ($rows as $row): ?>
            <tr>
                <?php foreach ($row as $value): ?>
                    <td><?= h($value === null ? '' : (string) $value) ?></td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <h2>원본 JSON (<code>SP::execute()</code> 반환값)</h2>
    <pre><?= h((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
<?php endif; ?>

</body>
</html>
