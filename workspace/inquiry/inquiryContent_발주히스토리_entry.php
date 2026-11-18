<?php
declare(strict_types=1);

/**
 * [문의확인 > "발주 히스토리" 탭 — inquiryContent_entry.php의 iframe 안에 뜨는 페이지]
 * v발주내역 중 parentcode = ?code(t문의.code)인 행을 보여준다. 보기 전용 —
 * 편집/페이저 없음. 툴바에 검색도구(AGGrid 검색창 + 검색 버튼)만 둔다(2026-11-18).
 * 조회는 inquiryContent_발주히스토리_fetch.php.
 * 필드감추기: nickname '발주'(업무 메뉴 화면과 같다) — src/Auth/FieldVisible.md.
 *
 * 행 더블클릭 → 부모의 action('selectRecord_발주', {code}) — 발주 amd 팝업(openPurchase, 발주 탭과 같다).
 *
 * 페이지 함수: requery() / action('requery') — 다시 불러온다.
 *  - action('setParent', {code}) — 부모 문의 code를 바꿔 다시 불러온다. 문의확인 화면이
 *    페이지를 다시 열지 않고 재사용할 때(다른 문의를 더블클릭) 부른다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Crud', 'Login', 'FieldVisible');

// 로그인이 안 살아있으면 여기서 login.php로 이동하고 끝난다.
Login::isAlive();

$code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
$tableName = 'v발주내역';
$gridId = '발주히스토리';
// [id, 글자, 추가 class]
$buttons = [];

// 컬럼 — 스키마 순서 그대로, 보기 전용. 숫자 타입이면 dataType 'number'.
$probe = new Crud(new DbContext(null));
$probe->init($tableName);
$numericTypes = ['int', 'bigint', 'smallint', 'tinyint', 'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney'];
$columns = [];
foreach ($probe->getSchema()['fields'] as $field => $info) {
    $columns[] = [
        'field' => $field,
        'headerName' => $field,
        'editable' => false,
        'dataType' => in_array(strtolower((string) ($info['type'] ?? '')), $numericTypes, true) ? 'number' : 'string',
    ];
}

$fetchUrl = APP_BASE_URL . '/workspace/inquiry/' . rawurlencode('inquiryContent_발주히스토리_fetch.php') . '?code=' . rawurlencode($code);
$aggridJsPath = APP_BASE_DIR . '/src/js/aggrid/AGGrid.js';
$aggridJsVersion = is_file($aggridJsPath) ? filemtime($aggridJsPath) : time();

// 필드감추기 — 이 계정 권한의 tfieldvisible 레코드 전부(src/Auth/FieldVisible.md).
// 화면 이름(nickname)은 업무 메뉴의 같은 자료 화면과 같다: '발주'.
$fieldVisible = FieldVisible::init(new DbContext(null));
$fieldVisibleJsPath = APP_BASE_DIR . '/src/Auth/FieldVisible.js';
$fieldVisibleJsVersion = is_file($fieldVisibleJsPath) ? filemtime($fieldVisibleJsPath) : time();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>발주 히스토리</title>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
<script src="<?= APP_BASE_URL ?>/src/Auth/FieldVisible.js?v=<?= $fieldVisibleJsVersion ?>"></script>
<style>
html, body { margin: 0; height: 100%; overflow: hidden; }
body { padding: 10px; box-sizing: border-box; font-family: 'Malgun Gothic', 'Segoe UI', system-ui, sans-serif; font-size: 13px; color: #1e293b; }
.ic-buttons { display: flex; gap: 6px; margin-bottom: 8px; }
.ic-buttons button { font: inherit; height: 28px; padding: 0 14px; cursor: pointer; border: 1px solid #d1d5db; border-radius: 4px; background: #fff; color: inherit; }
.ic-buttons button:hover { background: #f3f4f6; }
.ic-buttons .ic-add { background: #bfdbfe; border-color: #bfdbfe; }
.ic-buttons .ic-add:hover { background: #93c5fd; }
.ic-buttons .ic-ecount { color: #dc2626; font-weight: 600; }
/* 보기 전용 — AGGrid 툴바는 검색도구(검색창 + 검색 버튼)만 쓰고 편집 버튼은 숨긴다. */
.ag-shell-mode-btn { display: none !important; }
/* 검색도구를 툴바 앞(왼쪽)으로 — AGGrid는 오른쪽 묶음에 두므로 순서를 앞으로 당긴다(2026-11-18). */
.ag-shell-toolbar { justify-content: flex-start; }
.ag-shell-toolbar-right { order: -1; }
/* 검색어 칸은 흰 바탕(테두리로 칸이 보이게). */
.ag-shell-toolbar .ag-shell-search { background: #fff; border: 1px solid #d1d5db; border-radius: 4px; }
</style>
</head>
<body>
<div id="aggrid-<?= $gridId ?>"></div>
<script>
// 필드감추기 — {success, error, recs}. 이 탭의 nickname은 '발주'(업무 메뉴 화면과 같다).
var IC_TAB_FIELDVISIBLE = <?= json_encode($fieldVisible, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
var IC_TAB_NICKNAME = '발주';

// 페이저 없이 한 번에 받고(fetchMode 'all'), 높이는 iframe 바닥까지 채운다.
new AGGrid({
    gridId: <?= json_encode($gridId, JSON_UNESCAPED_UNICODE) ?>,
    fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    primaryKey: 'code',
    fetchMode: 'all',
    onReady: function (pgrid) {
        Applyds(pgrid, IC_TAB_NICKNAME, IC_TAB_FIELDVISIBLE);
    },
    autoFitBottomGap: 10,
    minHeight: 120,
    showSearch: true,   // 검색도구 — Enter/검색 버튼이면 ?q=로 서버에 다시 묻는다(fetch가 이 문의 안에서 찾는다)
    showAddRow: false,
    showDeleteColumn: false,
    onDataLoaded: function () { reportReady(); },
    onRowDblClick: function (prow, pkey) { openPurchase(pkey); },
    columns: (function (columns) {
        // code 계열(int)은 번호라 천단위 콤마를 붙이지 않는다.
        columns.forEach(function (col) {
            if (/code$/i.test(col.field)) {
                col.valueFormatter = function (params) { return params.value == null ? '' : params.value; };
            }
        });
        return columns;
    })(<?= json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>)
});

/**
 * reportReady() — 데이터를 그렸으면 부모(문의확인)에게 action('subformReady', {key})로
 * 알린다. 부모는 새 탭이 다 그려졌는지 세는 데 쓴다(다 되면 index에 tabReady).
 */
function reportReady() {
    if (window.parent === window || typeof window.parent.action !== 'function') {
        return;
    }
    window.parent.action('subformReady', { key: <?= json_encode($gridId, JSON_UNESCAPED_UNICODE) ?> }, window);
}

/**
 * openPurchase(pcode) — 행을 더블클릭하면 부모(문의확인)에 action('selectRecord_발주', {code})를 보낸다.
 * 발주 탭 더블클릭과 같다 — 부모가 발주 amd 팝업을 띄우거나, 떠 있으면 앞으로 가져와 이 행을 보인다.
 * v발주내역.code = t발주내역.code(2026-11-18 DB로 확인 — 4,643건 모두 같다). 이 페이지만 따로 열었으면 아무것도 안 한다.
 */
function openPurchase(pcode) {
    if (window.parent === window || typeof window.parent.action !== 'function') {
        return;
    }
    window.parent.action('selectRecord_발주', { code: pcode }, window);
}

/** requery() — 다시 불러온다. */
function requery() {
    var grid = AGGrid.instance(<?= json_encode($gridId, JSON_UNESCAPED_UNICODE) ?>);
    if (grid && grid.gridApi) {
        grid.requery();
    }
}

/**
 * setParent(pcode) — 부모 문의 code를 바꾼다. 그리드의 fetch 주소(?code=)만 바꾸고 다시
 * 불러온다(페이지는 다시 열지 않는다). AGGrid는 요청할 때마다 fetchUrl을 읽는다.
 */
function setParent(pcode) {
    var grid = AGGrid.instance(<?= json_encode($gridId, JSON_UNESCAPED_UNICODE) ?>);

    if (!grid) {
        return;
    }
    grid.fetchUrl = grid.fetchUrl.split('?')[0] + '?code=' + encodeURIComponent(pcode);
    if (grid.gridApi) {
        grid.requery();
    }
}

/** action(pcmd, pparams, psender) — 부모(inquiryContent_entry / index)가 부르는 창구. */
function action(pcmd, pparams, psender) {
    if (!pparams) {
        pparams = {};
    }
    switch (pcmd) {
        case 'setParent':
            return setParent(pparams.code);
        case 'requery':
            return requery();
        default:
            console.warn('발주히스토리.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

</script>
</body>
</html>
