<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/SP.php';
require_once __DIR__ . '/../Board/Board.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * SP::execute() 결과를 Board::initFromArray()에 그대로 넣어 게시판(검색+
 * 정렬+페이징)으로 표현하는 실전 조합 예제 — sp.md/Board.md의 "연계" 절이
 * 설명하는 흐름을 실제로 눌러볼 수 있는 페이지. 이 파일 하나가
 * `SP::execute()` → `Board::initFromArray()` → (헤더 화살표/페이저를
 * 직접 그리는) 화면단, 3단계를 전부 보여준다.
 *
 * 렌더링을 손으로 짜는 이유: array 모드는 `$this->crud`(스키마)가 없어
 * `Board::getDefaultBodyHTML()`을 못 쓴다(Board.md "렌더링" 절 참고) —
 * 대신 `getRows()`/`getDefaultInfoHTML()`/`getDefaultPagerHTML()`/
 * `getSortField()`/`getSortDir()`/`getSortUrl()`(전부 모드와 무관하게
 * 동작)만으로 `getDefaultBodyHTML()`이 하던 일(헤더 클릭 정렬 화살표
 * 포함)을 이 화면이 직접 재현한다.
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
$keyColumnRaw = isset($_GET['keyColumn']) ? trim((string) $_GET['keyColumn']) : '';
$keyword = isset($_GET['q']) ? (string) $_GET['q'] : '';

$error = null;
$board = null;
$sourceColumns = [];

if ($procName !== '') {
    $params = [];
    if (trim($paramsRaw) !== '') {
        foreach (explode(',', $paramsRaw) as $p) {
            $params[] = trim($p);
        }
    }

    try {
        $db = new DbContext($connectionName);

        // 1) SP 실행 — 전체 결과가 이 시점에 이미 PHP 배열로 손에 있다.
        $rows = SP::execute($procName, $params, $db);
        $sourceColumns = !empty($rows) ? array_keys($rows[0]) : [];

        // 2) Board에 그대로 주입 — SQL을 전혀 안 씀(COUNT(*) 재질의 없음).
        $board = new Board($db);
        $board->initFromArray(
            $rows,
            '',                                      // 전체 필드 대상 검색
            $keyword,
            '',
            null,
            null,
            null,
            null,
            '',
            $keyColumnRaw !== '' ? $keyColumnRaw : null
        );
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>SP + Board 조합 시험 - initFromArray() 실전 예제</title>
<style>
    body { font-family: sans-serif; max-width: 960px; margin: 2rem auto; padding: 0 1rem; }
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
    th a { color: #1f2937; text-decoration: none; }
    th a:hover { text-decoration: underline; }
    .notice { padding: 10px; border-radius: 4px; margin-bottom: 1rem; }
    .notice.error { background: #fdecea; color: #c0392b; }
    .note { color: #666; font-size: 13px; }
    .meta { color: #444; font-size: 13px; margin: 10px 0; }
    .pager { margin-top: 10px; }
    .pager a, .pager span { display: inline-block; padding: 3px 8px; margin-right: 4px; font-size: 13px; }
    .pager a { color: #2563eb; text-decoration: none; border: 1px solid #d1d5db; border-radius: 4px; }
    .pager a:hover { background: #eff6ff; }
    .pager span.current { border: 1px solid #2563eb; border-radius: 4px; background: #2563eb; color: #fff; }
    .pager span.hidden { visibility: hidden; }
</style>
</head>
<body>
<h1>SP + Board 조합 시험 — <code>Board::initFromArray()</code> 실전 예제</h1>
<p class="note">
    <code>SP::execute()</code>가 돌려준 결과 배열을 <code>Board::initFromArray()</code>에
    그대로 넣어, 검색/정렬/페이징이 있는 게시판으로 표현하는 흐름을 실제로 눌러보는
    페이지입니다(<code>src/Model/SP/sp.md</code>·<code>src/Model/Board/Board.md</code>
    "배열 모드" 참고). 페이지 이동/헤더 클릭 정렬을 눌러도 <b>SP는 다시 실행되지 않습니다</b>
    — 이번 요청 한 번에 받은 배열 안에서 <code>Board</code>가 검색/정렬/페이징을
    전부 PHP 메모리로 처리합니다.
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
<legend>SP 실행 + Board 주입</legend>
<div class="row">
    <label for="sp-proc">프로시저명</label>
    <input type="text" id="sp-proc" name="proc" value="<?= h($procName) ?>" placeholder="예: qGrid_3_매출최종">
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
    <label for="sp-key">키 컬럼</label>
    <input type="text" id="sp-key" name="keyColumn" value="<?= h($keyColumnRaw) ?>" placeholder="비우면 'code'로 간주 — Board::initFromArray()의 $pKeyColumn">
</div>
<div class="row">
    <label for="sp-q">검색어</label>
    <input type="text" id="sp-q" name="q" value="<?= h($keyword) ?>" placeholder="전체 필드 대상 부분일치(PHP mb_stripos)">
</div>
<div class="row">
    <button type="submit">SP 실행 + Board 표시</button>
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

<?php if ($board !== null): ?>

    <?= $board->getDefaultInfoHTML() ?>

    <?php if (empty($sourceColumns)): ?>
        <p>SP 결과가 비어있습니다(빈 배열).</p>
    <?php else: ?>
        <table>
            <tr>
                <?php foreach ($sourceColumns as $col): ?>
                    <?php
                    if ($board->isSortable()) {
                        $isCurrent = $board->getSortField() === $col;
                        if ($isCurrent) {
                            $nextDir = $board->getSortDir() === 'ASC' ? 'DESC' : 'ASC';
                            $arrow = $board->getSortDir() === 'ASC' ? ' &#9650;' : ' &#9660;';
                        } else {
                            $nextDir = 'ASC';
                            $arrow = '';
                        }
                        $sortUrl = h($board->getSortUrl($col, $nextDir));
                        echo '<th><a href="' . $sortUrl . '">' . h($col) . $arrow . '</a></th>';
                    } else {
                        echo '<th>' . h($col) . '</th>';
                    }
                    ?>
                <?php endforeach; ?>
            </tr>
            <?php $pageRows = $board->getRows(); ?>
            <?php if (empty($pageRows)): ?>
                <tr><td colspan="<?= count($sourceColumns) ?>">검색 결과가 없습니다.</td></tr>
            <?php else: ?>
                <?php foreach ($pageRows as $row): ?>
                <tr>
                    <?php foreach ($sourceColumns as $col): ?>
                        <td><?= h(isset($row[$col]) && $row[$col] !== null ? (string) $row[$col] : '') ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>

        <?= $board->getDefaultPagerHTML() ?>
    <?php endif; ?>

<?php endif; ?>

</body>
</html>
