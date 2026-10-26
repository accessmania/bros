<?php
declare(strict_types=1);

/**
 * [고객사 검색 팝업 — 문의확인(inquiryContent_entry.php) 입력폼의 고객사 찾기 버튼]
 * 문의확인 화면의 팝업(모달) 안 iframe으로 뜬다(바탕화면 고객사검색.png).
 *  - 위: 제목 "고객사 검색 및 추가" + 오른쪽 검색칸/검색 버튼(Enter도 됨)
 *  - 아래: tb고객사 AGGrid(보기 전용, 페이저 없음, 회사명 순).
 *    검색어는 모든 컬럼에 LIKE(inquiryContent_고객사검색_fetch.php).
 *  - 행 더블클릭 → 부모(문의확인)의 action('setCustomer', {pcode, pname})로
 *    고객사code/고객사(회사명)를 넘긴다. 부모가 입력폼에 넣고 팝업을 닫는다.
 *  - Esc → 부모의 action('closeCustomerSearch')로 팝업을 닫는다.
 *
 * 페이지 함수: search(검색어) / requery() / action('search'|'requery').
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

$tableName = 'tb고객사';
$gridId = '고객사검색';

// 컬럼 — 스키마 순서 그대로(code는 키라 숨김), 보기 전용. 숫자 타입이면 dataType 'number'.
$probe = new Crud(new DbContext(null));
$probe->init($tableName);
$primaryKey = $probe->getPrimaryKey();
$numericTypes = ['int', 'bigint', 'smallint', 'tinyint', 'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney'];
$columns = [];
foreach ($probe->getSchema()['fields'] as $field => $info) {
    if ($field === $primaryKey) {
        continue;
    }
    $columns[] = [
        'field' => $field,
        'headerName' => $field,
        'editable' => false,
        'dataType' => in_array(strtolower((string) ($info['type'] ?? '')), $numericTypes, true) ? 'number' : 'string',
    ];
}

$fetchUrl = APP_BASE_URL . '/workspace/inquiry/' . rawurlencode('inquiryContent_고객사검색_fetch.php');
$aggridJsPath = APP_BASE_DIR . '/src/js/aggrid/AGGrid.js';
$aggridJsVersion = is_file($aggridJsPath) ? filemtime($aggridJsPath) : time();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>고객사 검색</title>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
<style>
html, body { margin: 0; height: 100%; overflow: hidden; background: #fff; }
body { padding: 12px 14px; box-sizing: border-box; font-family: 'Malgun Gothic', 'Segoe UI', system-ui, sans-serif; font-size: 13px; color: #000; }
.cs-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.cs-head h1 { margin: 0; font-size: 1.05rem; font-weight: 600; }
.cs-search { display: flex; gap: 4px; }
.cs-search input {
    font: inherit; width: 160px; height: 26px; padding: 0 8px; box-sizing: border-box;
    border: 1px solid #9ca3af; border-radius: 3px; color: #000;
}
.cs-search input:focus { outline: none; border-color: #3b82f6; }
.cs-search button {
    width: 28px; height: 26px; padding: 0; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid #9ca3af; border-radius: 3px; background: #fff; color: #000;
}
.cs-search button:hover { background: #f3f4f6; }
/* 보기 전용 — AGGrid 툴바(편집 버튼)는 숨긴다. */
.ag-shell-toolbar { display: none !important; }
</style>
</head>
<body>
<div class="cs-head">
    <h1>고객사 검색 및 추가</h1>
    <div class="cs-search">
        <input type="text" id="cs-keyword" placeholder="검색어" aria-label="검색어" autocomplete="off">
        <button type="button" id="cs-search-btn" title="검색" aria-label="검색">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="10.5" cy="10.5" r="6.5"/><path d="M15.5 15.5 21 21"/></svg>
        </button>
    </div>
</div>
<div id="aggrid-<?= $gridId ?>"></div>
<script>
// 779건 수준이라 페이저 없이 한 번에 받고(fetchMode 'all'), 높이는 팝업 바닥까지 채운다.
new AGGrid({
    gridId: <?= json_encode($gridId, JSON_UNESCAPED_UNICODE) ?>,
    fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    primaryKey: <?= json_encode($primaryKey, JSON_UNESCAPED_UNICODE) ?>,
    fetchMode: 'all',
    autoFitBottomGap: 12,
    minHeight: 200,
    showSearch: false,
    showAddRow: false,
    showDeleteColumn: false,
    // 더블클릭 → 부모(문의확인)에 고객사code/회사명을 넘긴다(action을 거친다).
    onRowDblClick: function (values) {
        pickCustomer(values);
    },
    columns: <?= json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
});

function grid() {
    return AGGrid.instance(<?= json_encode($gridId, JSON_UNESCAPED_UNICODE) ?>);
}

/** 부모(문의확인) 창의 action — 이 페이지만 따로 열었으면 null. */
function parentAction() {
    if (window.parent !== window && typeof window.parent.action === 'function') {
        return window.parent.action;
    }
    return null;
}

/** 고른 고객사를 부모 입력폼에 넘긴다 — action('setCustomer', {pcode, pname}). */
function pickCustomer(values) {
    if (!values) {
        return;
    }
    var act = parentAction();
    if (!act) {
        alert('문의확인 화면에서 열어야 고객사를 넘길 수 있습니다.\n(고른 고객사: ' + values['회사명'] + ')');
        return;
    }
    act('setCustomer', { pcode: values.code, pname: values['회사명'] }, window);
}

/** search(검색어) — 인자를 주면 검색칸을 그 값으로 바꾼 뒤 검색칸 값으로 다시 조회한다. */
function search(pkeyword) {
    var input = document.getElementById('cs-keyword');
    if (pkeyword !== undefined) {
        input.value = pkeyword == null ? '' : String(pkeyword);
    }
    var g = grid();
    if (!g || !g.gridApi) {
        return;
    }
    g.searchKeyword = input.value.trim();
    g.requery();
}

/** requery() — 지금 검색어 그대로 다시 불러온다. */
function requery() {
    var g = grid();
    if (g && g.gridApi) {
        g.requery();
    }
}

/** action(pcmd, pparams, psender) — 부모(문의확인)가 부르는 창구. */
function action(pcmd, pparams, psender) {
    pparams = pparams || {};
    switch (pcmd) {
        case 'search':
            return search(pparams.pkeyword);
        case 'requery':
            return requery();
        default:
            console.warn('고객사검색.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

document.getElementById('cs-search-btn').addEventListener('click', function () { search(); });
document.getElementById('cs-keyword').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.isComposing) {
        search();
    }
});
// Esc — 팝업 닫기(부모가 닫는다).
document.addEventListener('keydown', function (e) {
    var act = parentAction();
    if (e.key === 'Escape' && act) {
        act('closeCustomerSearch', {}, window);
    }
});
</script>
</body>
</html>
