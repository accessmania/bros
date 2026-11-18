<?php
declare(strict_types=1);

/**
 * [발주(v발주내역) AGGrid 화면 — 메뉴 "업무 > 발주"]
 * 구성: inquiry_발주_entry.php(이 파일) + inquiry_발주_fetch.php(조회 전용).
 * 문의내용 목록(inquiry_문의내용_entry.php)과 같은 모양·동작이다:
 *  - 원본은 뷰 v발주내역. Access의 mSP.create("sp발주내역", "select * from v발주내역", True)
 *    → 검색 → 데이터시트와 같은 자리. parentcode = t문의.code, 문의code = t문의내역.code.
 *  - 컬럼은 $purchaseFields 순서로 고정. 뷰에 없는 필드는 빼고 콘솔에 경고한다.
 *  - 오른쪽 위에 신규추가 / 상태 콤보 / 검색어 / 검색 버튼. 상태 콤보의 원본자료는
 *    tb상태_발주.상태. 검색어는 ?q=(표시 필드 전체), 상태는 ?st=.
 *  - 보기 전용. 행 더블클릭 → "발주확인" 탭(문의확인·문의내용확인·견적확인·수주확인 탭과 따로, 제목
 *    "발주 (t문의.code)")에 그 행의 문의(parentcode)를 문의확인 화면으로 열고, 그 발주(code)
 *    amd를 팝업으로 띄운다. 다른 행을 더블클릭하면 같은 탭을 덮어쓴다(iqpOpenDetail →
 *    inquiryContent_entry.php의 목록 모드 ?open=발주 / action('openInquiryDetail')).
 *  - 신규추가 → 문의확인을 신규(new)로 연다(문의 목록과 같은 "문의확인" 탭).
 *  - 필드감추기: nickname '발주' — src/Auth/FieldVisible.md.
 *
 *  - ?model=<모델명>: 모델 이력 팝업(inquiryContent_모델이력_entry.php) 안에서 그 모델명만(업체 관계없이) 보인다.
 *    신규추가 버튼은 숨기고, 더블클릭은 맨 위 창(index 셸)의 탭으로 연다.
 *    이름에 code가 들어간 필드는 숨긴다('code' 자체는 보인다 — iqpHideCodeFields).
 *
 * 페이지 함수: action(pcmd, pparams, psender) — 'search'({pstatus, pkeyword}) / 'requery'.
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

$connectionName = null; // config/db.php의 'driver' 기본 접속을 따른다
$purchaseDb = new DbContext($connectionName);
$viewName = 'v발주내역';

// 표시 필드 — Access 발주 목록 순서. inquiry_발주_fetch.php의 $purchaseFields와 같게 둔다.
$purchaseFields = [
    'code', 'parentcode', '문의code', '발주번호', '상태', '제품code', '고객사', '제품종류',
    '품목코드', '모델명', '매입통화', '제조사', '수량', '적용수량', '외화매입가', '선적예정일',
    '비고', '발주서납기', '송금예정일', '원화매입가', '매입총액', '외화송금잔액', '입고수량', '입고일',
    '매입환율', 'KRW매입환율', '원화송금액', 'USD송금액', '송금일', '송금메모', '할인금액', '송금잔액',
    '외화매입총액', 'USD할인금액',
];

$probe = new Crud($purchaseDb);
$probe->init($viewName);
$primaryKey = $probe->getPrimaryKey();
$fieldSchema = $probe->getSchema()['fields'];

$numericTypes = ['int', 'bigint', 'smallint', 'tinyint', 'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney'];
$columns = [];
$missingFields = [];
foreach ($purchaseFields as $field) {
    if (!isset($fieldSchema[$field])) {
        $missingFields[] = $field;
        continue;
    }
    $dataType = 'string';
    if (in_array(strtolower((string) ($fieldSchema[$field]['type'] ?? '')), $numericTypes, true)) {
        $dataType = 'number';
    }
    $column = [
        'field' => $field,
        'headerName' => $field,
        'editable' => false,
        'dataType' => $dataType,
    ];
    if ($field === '상태') {
        $column['align'] = 'center';
    }
    $columns[] = $column;
}

/**
 * 상태 콤보의 원본자료 — tb상태_발주.상태(code 오름차순). 문의 화면의
 * tb상태_문의와 같은 방식. 읽기에 실패해도 화면은 뜨고 콤보만 빈다($statusError).
 */
$statusOptions = [];
$statusError = '';
try {
    $statusStmt = $purchaseDb->pdo()->query('SELECT tb상태_발주.상태 FROM tb상태_발주 ORDER BY tb상태_발주.code ASC');
    foreach ($statusStmt->fetchAll(PDO::FETCH_COLUMN) as $status) {
        if ($status === null) {
            $statusOptions[] = '';
        } else {
            $statusOptions[] = (string) $status;
        }
    }
} catch (Throwable $e) {
    $statusError = $e->getMessage();
}

// 필드감추기 — 이 계정 권한의 tfieldvisible 레코드 전부(src/Auth/FieldVisible.md).
$fieldVisible = FieldVisible::init($purchaseDb);

$fetchUrl = APP_BASE_URL . '/workspace/inquiry/' . rawurlencode('inquiry_발주_fetch.php');
// ?model=<모델명> — 모델 이력 팝업(inquiryContent_모델이력_entry.php)이 띄울 때. 그 모델명만 보이게
// fetch 주소에 붙인다(AGGrid는 검색·상태 값을 &로 이어 붙인다). 신규추가 버튼은 숨긴다.
$modelFilter = isset($_GET['model']) ? trim((string) $_GET['model']) : '';
if ($modelFilter !== '') {
    $fetchUrl .= '?model=' . rawurlencode($modelFilter);
}

// 브라우저 캐시로 옛 JS가 보이지 않게 수정시각을 쿼리스트링에 붙인다.
$aggridJsPath = APP_BASE_DIR . '/src/js/aggrid/AGGrid.js';
$aggridJsVersion = is_file($aggridJsPath) ? filemtime($aggridJsPath) : time();
$fieldVisibleJsPath = APP_BASE_DIR . '/src/Auth/FieldVisible.js';
$fieldVisibleJsVersion = is_file($fieldVisibleJsPath) ? filemtime($fieldVisibleJsPath) : time();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>발주</title>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
<script src="<?= APP_BASE_URL ?>/src/Auth/FieldVisible.js?v=<?= $fieldVisibleJsVersion ?>"></script>
<style>
html, body { margin: 0; height: 100%; }
body { padding: 16px; box-sizing: border-box; font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif; }

/* 검색 도구 묶음 — 그리드 툴바 오른쪽, "발주" 제목과 같은 줄(onToolbarRendered). 문의 화면과 같은 모양. */
.iqp-bar { display: flex; justify-content: flex-end; align-items: center; gap: 6px; }
.iqp-bar button, .iqp-bar select, .iqp-bar input {
    font: inherit; font-size: 0.82rem; height: 28px; box-sizing: border-box;
    border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #1e293b;
}
.iqp-bar button { padding: 0 12px; cursor: pointer; }
.iqp-bar button:hover { background: #f3f4f6; }
.iqp-bar .iqp-add-btn { background: #bfdbfe; border-color: #bfdbfe; }
.iqp-bar .iqp-add-btn:hover { background: #93c5fd; }
.iqp-bar select { padding: 0 6px; min-width: 120px; }
.iqp-bar input { padding: 0 8px; width: 220px; }
.iqp-bar select:focus, .iqp-bar input:focus { outline: none; border-color: #6b7280; }

.iqp-title {
    margin-left: 0.5cm; margin-right: 12px; padding-left: 8px;
    border-left: 4px solid #3b82f6;
    font-family: 'Pretendard', 'Malgun Gothic', 'Segoe UI', sans-serif;
    font-size: 1.15rem; font-weight: 700; letter-spacing: 0.08em; line-height: 1.2;
    color: #1e3a8a;
}
</style>
</head>
<body>

<div class="iqp-bar">
    <button type="button" class="iqp-add-btn" id="iqp-add"<?= $modelFilter !== '' ? ' hidden' : '' ?>>+ 신규추가</button>
    <!-- 상태 콤보 — 원본자료는 tb상태_발주.상태. 처음엔 아무것도 고르지 않은 빈 상태. -->
    <select id="iqp-status" aria-label="상태" autocomplete="off">
        <!-- 맨 위 공란 — 고르면 상태 조건 없이 전체를 조회한다. 상태 표에 빈 값이 있어도 공란은 이것 하나만 둔다. -->
        <option value=""></option>
<?php foreach ($statusOptions as $status): ?>
<?php if ($status === '') { continue; } ?>
        <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
    </select>
    <input type="text" id="iqp-keyword" placeholder="검색어" aria-label="검색어" />
    <button type="button" id="iqp-search">검색</button>
</div>

<div id="aggrid-발주목록"></div>
<script>
var IQP_GRID_ID = '발주목록';
var IQP_BAR = document.querySelector('.iqp-bar');
// 신규추가는 문의 목록(inquiry_문의_entry.php)과 같은 "문의확인" 탭을 돌려쓴다.
var IQP_CONTENT_TAB = '문의확인';
// 행 더블클릭은 따로 "발주확인" 탭 하나를 돌려쓴다(문의확인 화면의 목록 모드, ?open=발주&detail=).
var IQP_DETAIL_TAB = '발주확인';

// 필드감추기 — {success, error, recs}. 이 화면의 nickname은 '발주'.
var IQP_FIELDVISIBLE = <?= json_encode($fieldVisible, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
var IQP_NICKNAME = '발주';
// 모델 이력 팝업(?model=) 안인지 — 그때만 이름에 code가 들어간 필드를 숨긴다(iqpHideCodeFields).
var IQP_MODEL_MODE = <?= json_encode($modelFilter !== '') ?>;

<?php if ($statusError !== ''): ?>
console.warn('tb상태_발주을 읽지 못해 상태 콤보가 비어 있습니다: ' + <?= json_encode($statusError, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>);
<?php endif; ?>
<?php if (count($missingFields) > 0): ?>
console.warn('v발주내역에 없는 필드라 뺐습니다: ' + <?= json_encode(implode(', ', $missingFields), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>);
<?php endif; ?>

new AGGrid({
    gridId: IQP_GRID_ID,
    fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    primaryKey: <?= json_encode($primaryKey, JSON_UNESCAPED_UNICODE) ?>,
    fetchMode: 'paged',
    pageSize: 100,
    autoFitBottomGap: 40,
    showSearch: false,
    showAddRow: false,
    showDeleteColumn: false,
    onReady: function (pgrid) {
        Applyds(pgrid, IQP_NICKNAME, IQP_FIELDVISIBLE);
        iqpHideCodeFields(pgrid);
    },
    onRowDblClick: function (pvalues) {
        iqpOpenDetail(pvalues.parentcode, pvalues.code);
    },
    onToolbarRendered: function (pgrid) {
        iqpDecorateToolbar(pgrid);
    },
    columns: iqpPrepareColumns(<?= json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>)
});

/** code 계열(int)은 번호라 천단위 콤마를 붙이지 않는다. */
/**
 * 모델 이력 팝업(?model=) 안에서만 — 이름에 code가 들어간 필드(대소문자 무관)를 숨긴다.
 * 이름이 정확히 'code'인 필드는 보인다(2026-11-18). 업무 메뉴에서 열면 아무것도 안 한다.
 * 필드감추기(Applyds) 뒤에 부르므로 둘 다 적용된다(숨김만 더한다).
 */
function iqpHideCodeFields(pgrid) {
    var cols;
    var id;
    var hide = [];
    var i;

    if (IQP_MODEL_MODE !== true || !pgrid || !pgrid.gridApi) {
        return;
    }
    cols = pgrid.gridApi.getColumns();
    for (i = 0; i < cols.length; i++) {
        id = cols[i].getColId();
        if (id.toLowerCase() === 'code') {
            continue;
        }
        if (id.toLowerCase().indexOf('code') !== -1) {
            hide.push(id);
        }
    }
    if (hide.length > 0) {
        pgrid.gridApi.setColumnsVisible(hide, false);
    }
}

function iqpPrepareColumns(pcolumns) {
    var i;

    for (i = 0; i < pcolumns.length; i++) {
        if (/code$/i.test(pcolumns[i].field)) {
            pcolumns[i].valueFormatter = iqpPlainFormatter;
        }
    }
    return pcolumns;
}

function iqpPlainFormatter(pparams) {
    if (pparams.value === null || pparams.value === undefined) {
        return '';
    }
    return pparams.value;
}

/**
 * 툴바 맨 앞 "편집" 버튼을 빼고 "발주" 제목을 두고, 검색 도구(.iqp-bar)를
 * 툴바 오른쪽으로 옮긴다. 툴바는 다시 그려질 때마다 innerHTML째 바뀌므로 매번 한다.
 */
function iqpDecorateToolbar(pgrid) {
    var left;
    var right;
    var modeBtn;
    var title;

    left = document.querySelector('#' + CSS.escape(pgrid.toolbarId) + ' .ag-shell-toolbar-left');
    if (!left) {
        return;
    }
    modeBtn = left.querySelector('.ag-shell-mode-btn');
    if (modeBtn) {
        modeBtn.remove();
    }
    title = document.createElement('span');
    title.className = 'iqp-title';
    title.textContent = '발주';
    left.insertBefore(title, left.firstChild);

    right = document.querySelector('#' + CSS.escape(pgrid.toolbarId) + ' .ag-shell-toolbar-right');
    if (right) {
        right.insertBefore(IQP_BAR, right.firstChild);
    }
}

function iqpGrid() {
    return AGGrid.instance(IQP_GRID_ID);
}

/**
 * search(상태, 검색어) — 인자를 주면 콤보/입력칸을 그 값으로 바꾼 뒤 검색한다.
 * 상태 ''는 전체. 검색어는 표시 필드 전체에 LIKE. 둘 다 있으면 AND.
 */
function search(pstatus, pkeyword) {
    var statusEl;
    var keywordEl;
    var grid;

    statusEl = document.getElementById('iqp-status');
    keywordEl = document.getElementById('iqp-keyword');
    if (pstatus !== undefined) {
        if (pstatus === null) {
            statusEl.value = '';
        } else {
            statusEl.value = String(pstatus);
        }
    }
    if (pkeyword !== undefined) {
        if (pkeyword === null) {
            keywordEl.value = '';
        } else {
            keywordEl.value = String(pkeyword);
        }
    }
    grid = iqpGrid();
    if (!grid || !grid.gridApi) {
        return;
    }
    grid.searchKeyword = keywordEl.value.trim();
    // setExtraQuery()가 requery()까지 한다.
    if (statusEl.value === '') {
        grid.setExtraQuery({});
    } else {
        grid.setExtraQuery({ st: statusEl.value });
    }
}

/** requery() — 지금 검색 조건 그대로 다시 불러온다. */
function requery() {
    var grid;

    grid = iqpGrid();
    if (!grid || !grid.gridApi) {
        return;
    }
    grid.requery();
}

/**
 * iqpOpenContent(pcode) — 신규추가. 문의확인 화면(inquiryContent_entry.php)을 "문의확인" 탭에
 * 신규('new')로 연다. 이미 떠 있으면 그 화면에 action('openInquiry', {code})만 보낸다(다시 열지 않음).
 * inquiry_문의_entry.php의 openInquiryContent와 같은 일.
 */
async function iqpOpenContent(pcode) {
    var url;
    var title;
    var shell;
    var win;
    var result;

    url = 'workspace/inquiry/inquiryContent_entry.php?code=' + encodeURIComponent(pcode);
    title = '문의 (' + pcode + ')';
    shell = window.parent;
    // 모델 이력 팝업 안(?model=)이면 부모는 팝업 화면이다 — 탭을 여는 index 셸은 맨 위 창.
    if (typeof shell.setContent !== 'function' && typeof window.top.setContent === 'function') {
        shell = window.top;
    }

    if (shell === window || typeof shell.setContent !== 'function') {
        location.href = 'inquiryContent_entry.php?code=' + encodeURIComponent(pcode);
        return true;
    }

    win = iqpFindContentWindow(shell, IQP_CONTENT_TAB);
    if (win === null) {
        shell.setContent(url, title, IQP_CONTENT_TAB);
        return true;
    }

    result = await win.action('openInquiry', { code: pcode }, window);
    if (result === 'unsupported') {
        shell.setContent(url, title, IQP_CONTENT_TAB);
    }
    return true;
}

/**
 * iqpOpenDetail(pparentcode, pcode) — 행 더블클릭. "발주확인" 탭(문의확인·문의내용확인·견적확인·수주확인 탭과 따로)에
 * 그 행의 문의(pparentcode)를 문의확인 화면으로 열고, 그 위에 발주(pcode) amd를 팝업으로 띄운다.
 * 탭 제목은 "발주 (pparentcode)" — 괄호 안은 항상 t문의.code.
 *  - 탭이 이미 떠 있으면 다시 열지 않고 action('openInquiryDetail', {code, detail})로 덮어쓴다.
 *  - 없거나 아직 준비 전('unsupported')이면 setContent로 새로 연다(?code=&open=발주&detail=).
 */
async function iqpOpenDetail(pparentcode, pcode) {
    var query;
    var url;
    var title;
    var shell;
    var win;
    var result;

    if (pparentcode === null || pparentcode === undefined || pparentcode === '') {
        alert('이 발주에는 문의(parentcode)가 없습니다.');
        return false;
    }
    query = '?code=' + encodeURIComponent(pparentcode)
        + '&open=' + encodeURIComponent('발주') + '&detail=' + encodeURIComponent(pcode);
    url = 'workspace/inquiry/inquiryContent_entry.php' + query;
    // 탭 제목의 괄호 안은 항상 t문의.code(이 행의 parentcode).
    title = '발주 (' + pparentcode + ')';
    shell = window.parent;
    // 모델 이력 팝업 안(?model=)이면 부모는 팝업 화면이다 — 탭을 여는 index 셸은 맨 위 창.
    if (typeof shell.setContent !== 'function' && typeof window.top.setContent === 'function') {
        shell = window.top;
    }

    if (shell === window || typeof shell.setContent !== 'function') {
        location.href = 'inquiryContent_entry.php' + query;
        return true;
    }

    win = iqpFindContentWindow(shell, IQP_DETAIL_TAB);
    if (win === null) {
        shell.setContent(url, title, IQP_DETAIL_TAB);
        return true;
    }

    result = await win.action('openInquiryDetail', { code: pparentcode, detail: pcode }, window);
    if (result === 'unsupported') {
        shell.setContent(url, title, IQP_DETAIL_TAB);
    }
    return true;
}

/** 셸에 떠 있는 문의확인 화면의 창(IC_TAB_KEY가 ptabKey이고 action이 있는 탭 iframe). 없으면 null. */
function iqpFindContentWindow(pshell, ptabKey) {
    var frames;
    var win;
    var i;

    frames = pshell.document.querySelectorAll('iframe[data-tab-iframe]');
    for (i = 0; i < frames.length; i++) {
        try {
            win = frames[i].contentWindow;
            if (win && win.IC_TAB_KEY === ptabKey && typeof win.action === 'function') {
                return win;
            }
        } catch (e) {
            // 다른 출처 iframe이면 접근이 막힌다 — 문의확인 화면이 아니므로 넘어간다.
        }
    }
    return null;
}

/**
 * action(pcmd, pparams, psender) — index(셸)가 iframe.contentWindow.action으로 부르는 창구.
 * index가 탭을 옮길 때 action('requery')를 부른다.
 */
function action(pcmd, pparams, psender) {
    if (!pparams) {
        pparams = {};
    }
    switch (pcmd) {
        case 'requery':
            return requery();
        case 'search':
            return search(pparams.pstatus, pparams.pkeyword);
        default:
            console.warn('발주.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

// 상태 콤보는 처음에 아무것도 고르지 않은 빈 칸(=전체 조회).
document.getElementById('iqp-status').selectedIndex = -1;

document.getElementById('iqp-add').addEventListener('click', function () {
    iqpOpenContent('new');
});
document.getElementById('iqp-search').addEventListener('click', function () {
    search();
});
document.getElementById('iqp-status').addEventListener('change', function () {
    search();
});
// 콤보/검색어 칸에 커서가 들어가면 값을 비우고 바로 검색한다(문의 화면과 같음).
document.getElementById('iqp-status').addEventListener('focus', function () {
    if (this.selectedIndex === -1 || this.value === '') {
        this.selectedIndex = -1;
        return;
    }
    this.selectedIndex = -1;
    search();
});
document.getElementById('iqp-keyword').addEventListener('focus', function () {
    if (this.value === '') {
        return;
    }
    this.value = '';
    search();
});
document.getElementById('iqp-keyword').addEventListener('keydown', function (pe) {
    if (pe.key === 'Enter' && !pe.isComposing) {
        search();
    }
});
</script>
</body>
</html>
