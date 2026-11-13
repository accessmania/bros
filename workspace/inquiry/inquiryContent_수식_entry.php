<?php
declare(strict_types=1);

/**
 * [수식 — 문의내용/수주/발주/견적의 수식 테이블 목록. 편집 가능(툴바의 편집 버튼)]
 * 주소: ?kind=문의내용|수주|발주|견적 → 그 수식 테이블(inquiryContent_수식_tables.php의 FORMULA_TABLES).
 * 각 amd(inquiryContent_<kind>_amd.php)의 "수식" 버튼으로 연다. 이 화면은 amd 안이 아니라
 * amd의 부모창(문의확인, inquiryContent_entry.php)의 팝업(ic-formula-modal) 안에 뜬다:
 *   amd → window.parent.action('openFormula', {kind}) → 문의확인이 이 화면을 ?kind=로 띄운다.
 *
 * 그리드: AGGrid 기본형, 행 높이 84px(기본 42px의 2배 — FS_ROW_HEIGHT, AG Grid API로 정함), 검색 없음,
 *         순서 오름차순(fetch가 정렬). 처음엔 보기 모드 — 툴바의 "편집"을 누르면 고치기·행 추가·삭제,
 *         "저장"으로 fetch(ActionBulk)에 저장한다. 실패한 행은 이유가 툴바에 보인다(AGGrid).
 *         가로 스크롤 없음 — 컬럼 너비를 팝업 폭에 맞추고(fsFitColumns), 긴 글자는 칸 안에서 줄바꿈.
 * 컬럼: 순서 / 필드명 / 수식 / 필드들 (Access "수식 (수주)" 폼 그림 수식수주.png와 같다).
 * 조회·저장·삭제: inquiryContent_수식_fetch.php?kind=.
 *
 * 페이지 함수: action(pcmd, pparams, psender)
 *  - 'requery' — 다시 읽는다.
 * Esc를 누르면 부모(문의확인)의 action('closeFormula')로 팝업을 닫는다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Crud', 'Login');
require_once __DIR__ . '/inquiryContent_수식_tables.php';

// 로그인이 안 살아있으면 여기서 login.php로 이동하고 끝난다.
Login::isAlive();

$gridId = '수식';
$kind = '';
$tableName = '';
// 행 식별 키 — 테이블의 PK, 없으면 순서. 종류가 틀리거나 테이블을 못 읽으면 이유를 화면에 보인다.
$primaryKey = '순서';
$loadError = '';
try {
    $kind = formula_kind();
    $tableName = FORMULA_TABLES[$kind];
    $probe = new Crud(new DbContext(null));
    $probe->init($tableName);
    if ($probe->getPrimaryKey() !== null) {
        $primaryKey = $probe->getPrimaryKey();
    }
} catch (InvalidArgumentException $e) {
    $loadError = $e->getMessage();
} catch (Throwable $e) {
    $loadError = $tableName . '을(를) 읽지 못했습니다: ' . $e->getMessage();
}

// 컬럼 — 순서는 번호라 가운데, 나머지는 글자라 왼쪽.
// 가로 스크롤이 생기지 않게 팝업 폭에 맞춘다(JS fsFitColumns). 그래서 긴 수식·필드들은 칸 안에서
// 줄바꿈한다(cellStyle — white-space: normal, 줄 높이 20px. 줄 높이를 안 주면 한 줄이 행 높이만큼 커진다).
$wrapStyle = ['whiteSpace' => 'normal', 'wordBreak' => 'break-all', 'lineHeight' => '20px', 'paddingTop' => '6px'];
$columns = [
    // 순서는 좁게 — AGGrid 기본 최소 폭(90px)보다 작게 두려면 minWidth를 따로 준다.
    ['field' => '순서', 'headerName' => '순서', 'editable' => true, 'dataType' => 'string', 'align' => 'center', 'minWidth' => 60],
    ['field' => '필드명', 'headerName' => '필드명', 'editable' => true, 'dataType' => 'string', 'cellStyle' => $wrapStyle],
    ['field' => '수식', 'headerName' => '수식', 'editable' => true, 'dataType' => 'string', 'cellStyle' => $wrapStyle],
    ['field' => '필드들', 'headerName' => '필드들', 'editable' => true, 'dataType' => 'string', 'cellStyle' => $wrapStyle],
];

$fetchUrl = APP_BASE_URL . '/workspace/inquiry/' . rawurlencode('inquiryContent_수식_fetch.php')
    . '?kind=' . rawurlencode($kind);
$aggridJsPath = APP_BASE_DIR . '/src/js/aggrid/AGGrid.js';
$aggridJsVersion = is_file($aggridJsPath) ? filemtime($aggridJsPath) : time();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>수식 (<?= htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') ?>)</title>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
<style>
html, body { margin: 0; height: 100%; overflow: hidden; }
body { padding: 10px; box-sizing: border-box; font-family: 'Malgun Gothic', 'Segoe UI', system-ui, sans-serif; font-size: 13px; color: #1e293b; }
.fs-error { color: #dc2626; }
</style>
</head>
<body>
<?php if ($loadError !== ''): ?>
<p class="fs-error"><?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></p>
<?php else: ?>
<div id="aggrid-<?= htmlspecialchars($gridId, ENT_QUOTES, 'UTF-8') ?>"></div>
<script>
// VBA식 JS — var(함수 맨 위에 모아 선언), function, if/else, 매개변수는 p로 시작.
// 화면 전용 함수·변수는 fs / FS_ 접두사(Formula Screen). action/action_xxx에는 붙이지 않는다.
var FS_GRID_ID = <?= json_encode($gridId, JSON_UNESCAPED_UNICODE) ?>;
// 행 높이(px) — 기본형 42px의 2배. CSS 변수(--ag-row-height)는 AG Grid 36에서 기본형에 먹지 않아
// 첫 데이터를 그린 뒤 AG Grid API(setGridOption('rowHeight'))로 정한다(fsFirstRendered).
var FS_ROW_HEIGHT = 84;
// 팝업 폭에 맞출 때 컬럼별 너비 한계(px) — 순서·필드명은 좁게 고정하고, 남는 폭은 수식·필드들이 나눠 갖는다.
// delete는 편집 모드에서만 생기는 AGGrid의 "삭제" 컬럼(colId).
var FS_COLUMN_LIMITS = [
    { key: '순서', minWidth: 60, maxWidth: 60 },
    { key: '필드명', minWidth: 110, maxWidth: 150 },
    { key: '수식', minWidth: 200 },
    { key: '필드들', minWidth: 150 },
    { key: 'delete', minWidth: 70, maxWidth: 70 }
];

// 페이저 없이 한 번에 받고(fetchMode 'all'), 높이는 팝업 바닥까지 채운다.
new AGGrid({
    gridId: FS_GRID_ID,
    fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    primaryKey: <?= json_encode($primaryKey, JSON_UNESCAPED_UNICODE) ?>,
    fetchMode: 'all',
    gridStyle: 'basic',
    autoFitBottomGap: 10,
    minHeight: 120,
    showSearch: false,
    showAddRow: true,              // 편집 모드에서 "+ 행 추가"
    showDeleteColumn: true,        // 편집 모드에서 행별 "삭제" 컬럼
    showRowNumber: false,          // 순서 컬럼이 번호 역할 — No 컬럼은 안 그린다(폭 절약)
    onFirstDataRendered: fsFirstRendered,
    onToolbarRendered: fsToolbarRendered,
    columns: <?= json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
});

// ══════════════════════════════════════════════════════════════════
// action — 부모(문의확인)가 이 화면을 부르는 창구.
// ══════════════════════════════════════════════════════════════════
function action(pcmd, pparams, psender) {
    if (!pparams) {
        pparams = {};
    }
    switch (pcmd) {
        case 'requery':
            return action_requery(pcmd, pparams, psender);
        default:
            console.warn('수식.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

/** requery — 다시 읽는다. */
function action_requery(pcmd, pparams, psender) {
    var grid = AGGrid.instance(FS_GRID_ID);

    if (grid && grid.gridApi) {
        grid.requery();
    }
}

/**
 * 첫 데이터를 그린 뒤 — AGGrid의 onFirstDataRendered(pgrid = AGGrid 객체)가 부른다.
 * AGGrid가 먼저 내용 기준 너비(bestfit)를 잡은 다음이라, 여기서 팝업 폭에 다시 맞춘다.
 *  1. 행 높이를 FS_ROW_HEIGHT로(처음 그린 행들도 다시 재게 resetRowHeights()까지).
 *  2. 컬럼 너비를 팝업 폭에 맞춘다(fsFitColumns) — 가로 스크롤이 생기지 않게.
 */
function fsFirstRendered(pgrid) {
    if (!pgrid || !pgrid.gridApi) {
        return;
    }
    pgrid.gridApi.setGridOption('rowHeight', FS_ROW_HEIGHT);
    pgrid.gridApi.resetRowHeights();
    fsFitColumns();
}

/**
 * 컬럼 너비를 그리드 폭에 꼭 맞춘다(AG Grid sizeColumnsToFit) — FS_COLUMN_LIMITS 안에서.
 * 팝업 크기가 바뀌면(window resize) 다시 부른다.
 */
function fsFitColumns() {
    var grid = AGGrid.instance(FS_GRID_ID);

    if (!grid || !grid.gridApi) {
        return;
    }
    grid.gridApi.sizeColumnsToFit({ columnLimits: FS_COLUMN_LIMITS });
}

/**
 * 툴바를 다시 그린 뒤 — AGGrid가 보기/편집 모드를 바꿀 때마다 부른다(처음 1회 포함).
 * 모드가 바뀌면 AGGrid가 컬럼을 다시 만들어(삭제 컬럼이 생기거나 없어짐) 너비가 풀리므로,
 * 컬럼이 다 바뀐 다음(setTimeout 0)에 팝업 폭에 다시 맞춘다.
 */
function fsToolbarRendered(pgrid) {
    setTimeout(fsFitColumns, 0);
}

/** Esc — 부모(문의확인)에게 팝업을 닫게 한다. */
function fsKeyDown(pevent) {
    if (pevent.key !== 'Escape') {
        return;
    }
    if (window.parent === window || typeof window.parent.action !== 'function') {
        return;
    }
    window.parent.action('closeFormula', {}, window);
}

document.addEventListener('keydown', fsKeyDown);
window.addEventListener('resize', fsFitColumns);
</script>
<?php endif; ?>
</body>
</html>
