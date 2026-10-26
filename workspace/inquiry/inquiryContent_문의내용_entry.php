<?php
declare(strict_types=1);

/**
 * [문의확인 > "문의" 탭 — inquiryContent_entry.php의 iframe 안에 뜨는 페이지]
 * t문의내역 중 parentcode = ?code(t문의.code)인 행을 보여준다. 보기 전용 —
 * 편집/페이저 없음. 그리드 위에 신규추가/견적자료/발주자료/ECount 버튼 —
 * 신규추가는 문의확인 화면을 신규로 다시 연다(addNew → 부모의 icNew), 나머지는 다음 단계.
 * 조회는 inquiryContent_문의내용_fetch.php.
 *
 * 페이지 함수: requery() / action('requery') — 다시 불러온다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Crud', 'Login');

// 로그인이 안 살아있으면 여기서 login.php로 이동하고 끝난다.
Login::isAlive();

$code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
$tableName = 't문의내역';
$gridId = '문의내용';
// [id, 글자, 추가 class]
$buttons = [
    ['new', '+ 신규추가', 'ic-add'],
    ['quote', '견적자료', ''],
    ['order', '발주자료', ''],
    ['ecount', 'ECount', 'ic-ecount'],
];

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

$fetchUrl = APP_BASE_URL . '/workspace/inquiry/' . rawurlencode('inquiryContent_문의내용_fetch.php') . '?code=' . rawurlencode($code);
$aggridJsPath = APP_BASE_DIR . '/src/js/aggrid/AGGrid.js';
$aggridJsVersion = is_file($aggridJsPath) ? filemtime($aggridJsPath) : time();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>문의내용</title>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
<style>
html, body { margin: 0; height: 100%; overflow: hidden; }
body { padding: 10px; box-sizing: border-box; font-family: 'Malgun Gothic', 'Segoe UI', system-ui, sans-serif; font-size: 13px; color: #1e293b; }
.ic-buttons { display: flex; gap: 6px; margin-bottom: 8px; }
.ic-buttons button { font: inherit; height: 28px; padding: 0 14px; cursor: pointer; border: 1px solid #d1d5db; border-radius: 4px; background: #fff; color: inherit; }
.ic-buttons button:hover { background: #f3f4f6; }
.ic-buttons .ic-add { background: #bfdbfe; border-color: #bfdbfe; }
.ic-buttons .ic-add:hover { background: #93c5fd; }
.ic-buttons .ic-ecount { color: #dc2626; font-weight: 600; }
/* 보기 전용 — AGGrid 툴바(편집 버튼)는 숨긴다. */
.ag-shell-toolbar { display: none !important; }
</style>
</head>
<body>
<div class="ic-buttons">
<?php foreach ($buttons as [$id, $label, $class]): ?>
    <button type="button" id="ic-<?= $id ?>" class="<?= $class ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></button>
<?php endforeach; ?>
</div>
<div id="aggrid-<?= $gridId ?>"></div>
<script>
// 몇 건뿐이라 페이저 없이 한 번에 받고(fetchMode 'all'), 높이는 iframe 바닥까지 채운다.
new AGGrid({
    gridId: <?= json_encode($gridId, JSON_UNESCAPED_UNICODE) ?>,
    fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    primaryKey: 'code',
    fetchMode: 'all',
    autoFitBottomGap: 10,
    minHeight: 120,
    showSearch: false,
    showAddRow: false,
    showDeleteColumn: false,
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

/** requery() — 다시 불러온다. */
function requery() {
    var grid = AGGrid.instance(<?= json_encode($gridId, JSON_UNESCAPED_UNICODE) ?>);
    if (grid && grid.gridApi) {
        grid.requery();
    }
}

/** action(pcmd, pparams, psender) — 부모(inquiryContent_entry / index)가 부르는 창구. */
function action(pcmd, pparams, psender) {
    switch (pcmd) {
        case 'requery':
            return requery();
        default:
            console.warn('문의내용.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

/**
 * 신규추가 — 입력폼의 신규추가와 같다: 이 탭을 품은 문의확인 화면의 icNew()를
 * 불러, 같은 "문의확인" 탭을 신규(code=new)로 다시 연다(저장 안 한 값이 있으면
 * 그쪽에서 묻는다). 이 페이지만 따로 열었으면 이 창을 신규 문의확인 화면으로 바꾼다.
 */
function addNew() {
    if (window.parent !== window && typeof window.parent.icNew === 'function') {
        window.parent.icNew();
        return;
    }
    location.href = <?= json_encode(APP_BASE_URL . '/workspace/inquiry/inquiryContent_entry.php?code=new', JSON_UNESCAPED_SLASHES) ?>;
}

// 버튼 — 신규추가 말고는 동작을 다음 단계에서 붙인다(지금은 안내만).
document.getElementById('ic-new').addEventListener('click', addNew);
document.querySelectorAll('.ic-buttons button:not(#ic-new)').forEach(function (btn) {
    btn.addEventListener('click', function () {
        alert(btn.textContent + ' — 준비 중입니다.');
    });
});
</script>
</body>
</html>
