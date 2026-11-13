<?php
declare(strict_types=1);

/**
 * [ECount (전체재고) — 이카운트 전체 재고 목록. 보기 전용]
 * 문의 탭(inquiryContent_문의내용_entry.php)의 ECount 버튼 → 문의확인(inquiryContent_entry.php)이
 * action('openEcountStock')으로 이 화면을 비모달 팝업(Popup.js, 이동 가능)에 띄운다.
 * 배치는 Access "ECount (전체재고)" 폼 그림(이카운트전체재고.png)과 같다 — 위에 검색칸 + 🔍, 아래 품목코드·수량 그리드.
 *
 * 흐름:
 *  1. 열면 tAPI_재고(지난번 받은 재고)를 먼저 보인다.
 *  2. 곧바로 이카운트에서 전체 재고를 받아 tAPI_재고에 기록하고(트랜잭션 — 실패하면 예전 재고 그대로),
 *     그리드를 다시 읽는다. 받는 중·결과·실패 이유는 위쪽 안내 글에 보인다.
 *  3. 검색칸 — 품목코드 일부를 넣고 Enter 또는 🔍: 그리드를 그 글자가 들어간 행만 보이게 거른다(빈 칸이면 전체).
 *
 * 조회·갱신: inquiryContent_ECount재고_fetch.php (갱신은 src/Model/ECount/ECount.php의 inventoryAll).
 * 페이지 함수: action(pcmd, pparams, psender)
 *  - 'refresh' — 이카운트에서 다시 받아 기록하고 다시 읽는다(팝업이 이미 떠 있을 때 ECount 버튼을 또 누르면).
 * Esc를 누르면 부모(문의확인)의 action('closeEcountStock')로 팝업을 닫는다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Crud', 'Login', 'ECount');

// 로그인이 안 살아있으면 여기서 login.php로 이동하고 끝난다.
Login::isAlive();

$gridId = 'ECount재고';
// 행 식별 키 — 테이블의 PK, 없으면 품목코드. 테이블을 못 읽으면 이유를 화면에 보인다.
$primaryKey = '품목코드';
$loadError = '';
try {
    $probe = new Crud(new DbContext(null));
    $probe->init(ECount::STOCK_TABLE);
    if ($probe->getPrimaryKey() !== null) {
        $primaryKey = $probe->getPrimaryKey();
    }
} catch (Throwable $e) {
    $loadError = ECount::STOCK_TABLE . '을(를) 읽지 못했습니다: ' . $e->getMessage();
}

$columns = [
    ['field' => '품목코드', 'headerName' => '품목코드', 'editable' => false, 'dataType' => 'string', 'align' => 'left'],
    ['field' => '수량', 'headerName' => '수량', 'editable' => false, 'dataType' => 'number', 'align' => 'left'],
];

$fetchUrl = APP_BASE_URL . '/workspace/inquiry/' . rawurlencode('inquiryContent_ECount재고_fetch.php');
$aggridJsPath = APP_BASE_DIR . '/src/js/aggrid/AGGrid.js';
$aggridJsVersion = is_file($aggridJsPath) ? filemtime($aggridJsPath) : time();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>ECount (전체재고)</title>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
<style>
html, body { margin: 0; height: 100%; overflow: hidden; }
body { padding: 10px; box-sizing: border-box; font-family: 'Malgun Gothic', 'Segoe UI', system-ui, sans-serif; font-size: 13px; color: #1e293b; background: #ececec; }
/* 위쪽 줄 — 왼쪽 안내 글, 오른쪽 검색칸 + 🔍 (그림처럼 검색은 오른쪽) */
.es-top { display: flex; align-items: center; gap: 6px; margin-bottom: 8px; }
.es-status { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #374151; }
.es-status.es-error { color: #dc2626; }
.es-top input { font: inherit; height: 25px; width: 160px; box-sizing: border-box; padding: 0 6px; border: 1px solid #a3a3a3; background: #fff; }
.es-top button { font: inherit; height: 25px; padding: 0 8px; cursor: pointer; border: 1px solid #a3a3a3; background: #e5e5e5; }
.es-top button:hover { background: #d4d4d4; }
/* 보기 전용 — AGGrid 툴바(편집 버튼)는 숨긴다. */
.ag-shell-toolbar { display: none !important; }
.es-error-page { color: #dc2626; }
</style>
</head>
<body>
<?php if ($loadError !== ''): ?>
<p class="es-error-page"><?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></p>
<?php else: ?>
<div class="es-top">
    <span class="es-status" id="es-status"></span>
    <input type="text" id="es-keyword" placeholder="품목코드" autocomplete="off">
    <button type="button" id="es-search" title="찾기" aria-label="찾기">&#128269;</button>
</div>
<div id="aggrid-<?= htmlspecialchars($gridId, ENT_QUOTES, 'UTF-8') ?>"></div>
<script>
// VBA식 JS — var(함수 맨 위에 모아 선언), function, if/else, 매개변수는 p로 시작, 서버는 async/await.
// 화면 전용 함수·변수는 es / ES_ 접두사(ECount Stock). action/action_xxx에는 붙이지 않는다.
var ES_GRID_ID = <?= json_encode($gridId, JSON_UNESCAPED_UNICODE) ?>;
var ES_FETCH_URL = <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
// 갱신 요청 순번 — 갱신을 연달아 누르면 늦게 온 앞 요청의 결과 글이 나중 것을 덮지 않게.
var esRefreshSeq = 0;

// 페이저 없이 한 번에 받고(fetchMode 'all'), 높이는 팝업 바닥까지 채운다.
new AGGrid({
    gridId: ES_GRID_ID,
    fetchUrl: ES_FETCH_URL,
    primaryKey: <?= json_encode($primaryKey, JSON_UNESCAPED_UNICODE) ?>,
    fetchMode: 'all',
    gridStyle: 'basic',
    autoFitBottomGap: 10,
    minHeight: 120,
    showSearch: false,
    showAddRow: false,
    showDeleteColumn: false,
    onFirstDataRendered: esFirstRendered,
    columns: esColumns(<?= json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>)
});

/** 컬럼 — 수량은 소수점 없이 세 자리 콤마(이카운트는 1250.0000000000처럼 준다). */
function esColumns(pcolumns) {
    var i;

    for (i = 0; i < pcolumns.length; i++) {
        if (pcolumns[i].field === '수량') {
            pcolumns[i].valueFormatter = esFormatQty;
        }
    }
    return pcolumns;
}

/** 수량 표시 — 숫자면 반올림해 콤마, 아니면 그대로. */
function esFormatQty(pparams) {
    var n;

    if (pparams.value === null || pparams.value === undefined || pparams.value === '') {
        return '';
    }
    n = Number(String(pparams.value).replace(/,/g, ''));
    if (isNaN(n)) {
        return String(pparams.value);
    }
    return n.toLocaleString('en-US', { maximumFractionDigits: 0 });
}

/** 첫 데이터를 그린 뒤 — 컬럼 너비를 팝업 폭에 맞춘다(가로 스크롤이 생기지 않게). */
function esFirstRendered(pgrid) {
    if (!pgrid || !pgrid.gridApi) {
        return;
    }
    pgrid.gridApi.sizeColumnsToFit();
}

/** 위쪽 안내 글 — pisError면 빨간 글. */
function esStatus(ptext, pisError) {
    var el = document.getElementById('es-status');

    el.textContent = ptext;
    if (pisError) {
        el.classList.add('es-error');
    } else {
        el.classList.remove('es-error');
    }
}

/** 지금 시각 'HH:MM'. */
function esNow() {
    var d = new Date();
    var h = String(d.getHours());
    var m = String(d.getMinutes());

    if (h.length < 2) {
        h = '0' + h;
    }
    if (m.length < 2) {
        m = '0' + m;
    }
    return h + ':' + m;
}

/**
 * 이카운트에서 전체 재고를 받아 tAPI_재고에 기록하고 그리드를 다시 읽는다.
 * 실패하면 빨간 글로 이유를 보이고, 그리드는 예전 재고(tAPI_재고 그대로)를 보인다.
 */
async function esRefresh() {
    var res;
    var data;
    var seq;
    var grid;

    esRefreshSeq = esRefreshSeq + 1;
    seq = esRefreshSeq;
    esStatus('이카운트에서 전체 재고를 받는 중...', false);
    try {
        res = await fetch(ES_FETCH_URL + '?action=refresh', { method: 'POST' });
        data = await res.json();
        if (!data.success) {
            throw new Error(data.error);
        }
    } catch (err) {
        if (seq === esRefreshSeq) {
            esStatus('갱신 실패(지난번 재고를 보여줍니다): ' + err.message, true);
        }
        return false;
    }
    if (seq !== esRefreshSeq) {
        return false;
    }
    grid = AGGrid.instance(ES_GRID_ID);
    if (grid && grid.gridApi) {
        await grid.requery();
        esApplyFilter();
    }
    esStatus(data.count.toLocaleString('en-US') + '건 받음(' + esNow() + ')', false);
    return true;
}

/** 검색 — 품목코드 일부로 그리드를 거른다(브라우저 안에서, 빈 칸이면 전체). */
function esApplyFilter() {
    var grid = AGGrid.instance(ES_GRID_ID);
    var keyword = document.getElementById('es-keyword').value.trim();

    if (!grid || !grid.gridApi) {
        return;
    }
    grid.gridApi.setGridOption('quickFilterText', keyword);
}

// ══════════════════════════════════════════════════════════════════
// action — 부모(문의확인)가 이 화면을 부르는 창구.
// ══════════════════════════════════════════════════════════════════
function action(pcmd, pparams, psender) {
    if (!pparams) {
        pparams = {};
    }
    switch (pcmd) {
        case 'refresh':
            return action_refresh(pcmd, pparams, psender);
        default:
            console.warn('ECount재고.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

/** refresh — 이카운트에서 다시 받아 기록하고 다시 읽는다. */
function action_refresh(pcmd, pparams, psender) {
    return esRefresh();
}

/** Esc — 부모(문의확인)에게 팝업을 닫게 한다. 검색칸 Enter — 거르기. */
function esKeyDown(pevent) {
    if (pevent.key === 'Enter' && pevent.target.id === 'es-keyword') {
        pevent.preventDefault();
        esApplyFilter();
        return;
    }
    if (pevent.key !== 'Escape') {
        return;
    }
    if (window.parent === window || typeof window.parent.action !== 'function') {
        return;
    }
    window.parent.action('closeEcountStock', {}, window);
}

document.addEventListener('keydown', esKeyDown);
document.getElementById('es-search').addEventListener('click', esApplyFilter);
window.addEventListener('resize', function () {
    var grid = AGGrid.instance(ES_GRID_ID);

    if (grid && grid.gridApi) {
        grid.gridApi.sizeColumnsToFit();
    }
});

// 열자마자 이카운트에서 받아 기록한다(그 사이 그리드는 지난번 재고를 보인다).
esRefresh();
</script>
<?php endif; ?>
</body>
</html>
