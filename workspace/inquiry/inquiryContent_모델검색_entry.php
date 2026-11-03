<?php
declare(strict_types=1);

/**
 * [모델 검색 팝업 — 문의내역 팝업(inquiryContent_문의내용_amd.php)의 모델 찾기 버튼]
 * 모달 안 iframe으로 뜬다 — 고객사 검색(inquiryContent_고객사검색_entry.php)과 같은 틀.
 * 보통은 문의확인(inquiryContent_entry.php)이 화면 전체 크기로 띄우고, 고른 행을 문의내역 팝업에
 * 전해 준다. 문의내역 팝업만 따로 열었으면 그 화면 안의 모달로 뜬다. 어느 쪽이든 이 페이지는
 * 부모(window.parent)의 action만 부른다.
 *  - 위: 제목 "모델 검색" + 오른쪽 검색칸/검색 버튼(Enter도 됨). ?q=로 첫 검색어를 받는다
 *    (문의내역의 검색 칸 글자).
 *  - 아래: tb제품 AGGrid(보기 전용, 페이저 없음, 모델명 순). code 류 필드(이름이 code로
 *    끝남)와 ts는 보이지 않는다. 검색어는 보이는 컬럼 전체에 LIKE(inquiryContent_모델검색_fetch.php).
 *  - 행 더블클릭 → 부모(문의내역)의 action('setModel', {row})로 그 행 전체를 넘긴다.
 *    부모가 관련 필드에 넣고 팝업을 닫는다.
 *  - Esc → 부모의 action('closeModelSearch')로 팝업을 닫는다.
 *
 * 페이지 함수: action(pcmd, pparams, psender) — 'search' {pkeyword} / 'requery'.
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

$tableName = 'tb제품';
$gridId = '모델검색';
$keyword = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

/** 화면에 안 보이는 필드 — code 류(이름이 code로 끝남, 대소문자 무시)와 ts. fetch와 같아야 한다. */
function model_search_hidden(string $field): bool
{
    return preg_match('/code$/i', $field) === 1 || strtolower($field) === 'ts';
}

// 컬럼 — 스키마 순서 그대로(code 류/ts는 뺀다), 보기 전용. 숫자 타입이면 dataType 'number'.
$probe = new Crud(new DbContext(null));
$probe->init($tableName);
$primaryKey = $probe->getPrimaryKey();
$numericTypes = ['int', 'bigint', 'smallint', 'tinyint', 'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney'];
$columns = [];
foreach ($probe->getSchema()['fields'] as $field => $info) {
    if (model_search_hidden($field)) {
        continue;
    }
    $columns[] = [
        'field' => $field,
        'headerName' => $field,
        'editable' => false,
        'dataType' => in_array(strtolower((string) ($info['type'] ?? '')), $numericTypes, true) ? 'number' : 'string',
    ];
}

$fetchUrl = APP_BASE_URL . '/workspace/inquiry/' . rawurlencode('inquiryContent_모델검색_fetch.php');
$aggridJsPath = APP_BASE_DIR . '/src/js/aggrid/AGGrid.js';
$aggridJsVersion = is_file($aggridJsPath) ? filemtime($aggridJsPath) : time();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>모델 검색</title>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
<style>
html, body { margin: 0; height: 100%; overflow: hidden; background: #fff; }
body { padding: 12px 14px; box-sizing: border-box; font-family: 'Malgun Gothic', 'Segoe UI', system-ui, sans-serif; font-size: 13px; color: #000; }
.ms-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.ms-head h1 { margin: 0; font-size: 1.05rem; font-weight: 600; }
.ms-search { display: flex; gap: 4px; }
.ms-search input {
    font: inherit; width: 160px; height: 26px; padding: 0 8px; box-sizing: border-box;
    border: 1px solid #9ca3af; border-radius: 3px; color: #000;
}
.ms-search input:focus { outline: none; border-color: #3b82f6; }
.ms-search button {
    width: 28px; height: 26px; padding: 0; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid #9ca3af; border-radius: 3px; background: #fff; color: #000;
}
.ms-search button:hover { background: #f3f4f6; }
/* 보기 전용 — AGGrid 툴바(편집 버튼)는 숨긴다. */
.ag-shell-toolbar { display: none !important; }
</style>
</head>
<body>
<div class="ms-head">
    <h1>모델 검색</h1>
    <div class="ms-search">
        <input type="text" id="ms-keyword" placeholder="검색어" aria-label="검색어" autocomplete="off"
               value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>">
        <button type="button" id="ms-search-btn" title="검색" aria-label="검색">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="10.5" cy="10.5" r="6.5"/><path d="M15.5 15.5 21 21"/></svg>
        </button>
    </div>
</div>
<div id="aggrid-<?= $gridId ?>"></div>
<script>
// VBA식 JS — var, function, if/else, for, 매개변수는 p로 시작. 화면 전용 이름은 ms / MS_.

var MS_GRID_ID = <?= json_encode($gridId, JSON_UNESCAPED_UNICODE) ?>;

// 페이저 없이 한 번에 받고(fetchMode 'all'), 높이는 팝업 바닥까지 채운다.
// 첫 검색어(?q=)가 있으면 그 글자로 걸러서 시작한다.
var msGrid = new AGGrid({
    gridId: MS_GRID_ID,
    fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    primaryKey: <?= json_encode($primaryKey, JSON_UNESCAPED_UNICODE) ?>,
    fetchMode: 'all',
    autoFitBottomGap: 12,
    minHeight: 200,
    showSearch: false,
    showAddRow: false,
    showDeleteColumn: false,
    // 더블클릭 → 부모(문의내역)에 그 행 전체를 넘긴다(action을 거친다).
    onRowDblClick: function (prow) {
        msPick(prow);
    },
    columns: <?= json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
});
msGrid.searchKeyword = <?= json_encode($keyword, JSON_UNESCAPED_UNICODE) ?>;

/** 부모(문의내역) 창 — 이 페이지만 따로 열었으면 null. */
function msParent() {
    if (window.parent !== window && typeof window.parent.action === 'function') {
        return window.parent;
    }
    return null;
}

/** 고른 행을 부모에 넘긴다 — action('setModel', {row}). 관련 필드에 넣는 일은 부모가 한다. */
function msPick(prow) {
    var parentWin = msParent();

    if (!prow) {
        return;
    }
    if (parentWin === null) {
        alert('문의내역 화면에서 열어야 모델을 넘길 수 있습니다.');
        return;
    }
    parentWin.action('setModel', { row: prow }, window);
}

/** search(pkeyword) — 인자를 주면 검색칸을 그 값으로 바꾼 뒤 검색칸 값으로 다시 조회한다. */
function search(pkeyword) {
    var input = document.getElementById('ms-keyword');

    if (pkeyword !== undefined) {
        if (pkeyword === null) {
            input.value = '';
        } else {
            input.value = String(pkeyword);
        }
    }
    if (!msGrid.gridApi) {
        msGrid.searchKeyword = input.value.trim();
        return;
    }
    msGrid.searchKeyword = input.value.trim();
    msGrid.requery();
}

/** requery() — 지금 검색어 그대로 다시 불러온다. */
function requery() {
    if (msGrid.gridApi) {
        msGrid.requery();
    }
}

/** action(pcmd, pparams, psender) — 부모(문의내역)가 부르는 창구. */
function action(pcmd, pparams, psender) {
    if (!pparams) {
        pparams = {};
    }
    switch (pcmd) {
        case 'search':
            return search(pparams.pkeyword);
        case 'requery':
            return requery();
        default:
            console.warn('모델검색.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

document.getElementById('ms-search-btn').addEventListener('click', function () {
    search();
});
document.getElementById('ms-keyword').addEventListener('keydown', function (pevent) {
    if (pevent.key === 'Enter' && !pevent.isComposing) {
        search();
    }
});
// Esc — 팝업 닫기(부모가 닫는다).
document.addEventListener('keydown', function (pevent) {
    var parentWin = msParent();

    if (pevent.key === 'Escape' && parentWin !== null) {
        parentWin.action('closeModelSearch', {}, window);
    }
});
</script>
</body>
</html>
