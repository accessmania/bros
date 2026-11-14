<?php
declare(strict_types=1);

/**
 * [납품출하대기(v납품출하대기) AGGrid 화면 — 메뉴 "업무 > 납품출하대기"]
 * 구성: inquiry_납품출하대기_entry.php(이 파일) + inquiry_납품출하대기_fetch.php(조회 전용).
 * 문의내용 목록(inquiry_문의내용_entry.php)과 같은 모양·동작이다:
 *  - 원본은 뷰 v납품출하대기. Access의 mSP.create("sp납품출하대기", "select * from v납품출하대기", True)
 *    → 검색 → 데이터시트와 같은 자리. parentcode = t문의.code, 문의code = t문의내역.code.
 *  - 컬럼은 $orderFields 순서로 고정. 뷰에 없는 필드는 빼고 콘솔에 경고한다.
 *  - 오른쪽 위에 신규추가 / 상태 콤보 / 검색어 / 검색 버튼. 상태 콤보의 원본자료는
 *    tb상태_수주.상태(Access: Me.상태.RowSource = "SELECT 상태 FROM tb상태_수주").
 *    검색어는 ?q=(표시 필드 전체), 상태는 ?st=.
 *  - v납품출하대기의 행은 수주(t수주내역) 행이다 — 컬럼·상태·팝업이 모두 수주 목록과 같고,
 *    메뉴/탭 이름만 "납품출하대기"다.
 *  - 보기 전용. 행 더블클릭 → "납품출하대기확인" 탭(문의확인·문의내용확인·견적확인·수주확인·발주확인·선적미입고확인 탭과 따로, 제목
 *    "납품출하대기 (t문의.code)")에 그 행의 문의(parentcode)를 문의확인 화면으로 열고, 그 수주(code)
 *    amd를 팝업으로 띄운다. 다른 행을 더블클릭하면 같은 탭을 덮어쓴다(iqdOpenDetail →
 *    inquiryContent_entry.php의 목록 모드 ?open=납품출하대기 / action('openInquiryDetail')).
 *  - 신규추가 → 문의확인을 신규(new)로 연다(문의 목록과 같은 "문의확인" 탭).
 *  - 필드감추기: nickname '납품출하대기' — src/Auth/FieldVisible.md.
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
$orderDb = new DbContext($connectionName);
$viewName = 'v납품출하대기';

// 표시 필드 — 수주 목록과 같은 컬럼·순서(v납품출하대기도 수주 행). inquiry_납품출하대기_fetch.php의 $orderFields와 같게 둔다.
$orderFields = [
    'code', 'parentcode', '문의code', '수주일자', '상태', '제품종류', '고객사', '모델명',
    '품목코드', '제조사', '수량', '적용수량', '판매단가', '변경납기', '비고', '출하예정일',
    '배송방법', 'USD판매단가', '공급가총액', 'USD총액', '납품일자', '수금예정일', '미수금', 'usd미수금',
    '결제통화', '결제환율', '할인금액', '수금', '수금일', 'USD할인금액', 'USD수금', '수금메모',
    '세금계산서', '배송주소',
];
$probe = new Crud($orderDb);
$probe->init($viewName);
$primaryKey = $probe->getPrimaryKey();
$fieldSchema = $probe->getSchema()['fields'];

$numericTypes = ['int', 'bigint', 'smallint', 'tinyint', 'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney'];
$columns = [];
$missingFields = [];
foreach ($orderFields as $field) {
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
 * 상태 콤보의 원본자료 — tb상태_수주.상태(code 오름차순). 문의 화면의
 * tb상태_문의와 같은 방식. 읽기에 실패해도 화면은 뜨고 콤보만 빈다($statusError).
 */
$statusOptions = [];
$statusError = '';
try {
    $statusStmt = $orderDb->pdo()->query('SELECT tb상태_수주.상태 FROM tb상태_수주 ORDER BY tb상태_수주.code ASC');
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
$fieldVisible = FieldVisible::init($orderDb);

$fetchUrl = APP_BASE_URL . '/workspace/inquiry/' . rawurlencode('inquiry_납품출하대기_fetch.php');

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
<title>납품출하대기</title>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
<script src="<?= APP_BASE_URL ?>/src/Auth/FieldVisible.js?v=<?= $fieldVisibleJsVersion ?>"></script>
<style>
html, body { margin: 0; height: 100%; }
body { padding: 16px; box-sizing: border-box; font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif; }

/* 검색 도구 묶음 — 그리드 툴바 오른쪽, "납품출하대기" 제목과 같은 줄(onToolbarRendered). 문의 화면과 같은 모양. */
.iqd-bar { display: flex; justify-content: flex-end; align-items: center; gap: 6px; }
.iqd-bar button, .iqd-bar select, .iqd-bar input {
    font: inherit; font-size: 0.82rem; height: 28px; box-sizing: border-box;
    border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #1e293b;
}
.iqd-bar button { padding: 0 12px; cursor: pointer; }
.iqd-bar button:hover { background: #f3f4f6; }
.iqd-bar .iqd-add-btn { background: #bfdbfe; border-color: #bfdbfe; }
.iqd-bar .iqd-add-btn:hover { background: #93c5fd; }
.iqd-bar select { padding: 0 6px; min-width: 120px; }
.iqd-bar input { padding: 0 8px; width: 220px; }
.iqd-bar select:focus, .iqd-bar input:focus { outline: none; border-color: #6b7280; }

.iqd-title {
    margin-left: 0.5cm; margin-right: 12px; padding-left: 8px;
    border-left: 4px solid #3b82f6;
    font-family: 'Pretendard', 'Malgun Gothic', 'Segoe UI', sans-serif;
    font-size: 1.15rem; font-weight: 700; letter-spacing: 0.08em; line-height: 1.2;
    color: #1e3a8a;
}
</style>
</head>
<body>

<div class="iqd-bar">
    <button type="button" class="iqd-add-btn" id="iqd-add">+ 신규추가</button>
    <!-- 상태 콤보 — 원본자료는 tb상태_수주.상태. 처음엔 아무것도 고르지 않은 빈 상태. -->
    <select id="iqd-status" aria-label="상태" autocomplete="off">
        <!-- 맨 위 공란 — 고르면 상태 조건 없이 전체를 조회한다. 상태 표에 빈 값이 있어도 공란은 이것 하나만 둔다. -->
        <option value=""></option>
<?php foreach ($statusOptions as $status): ?>
<?php if ($status === '') { continue; } ?>
        <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
    </select>
    <input type="text" id="iqd-keyword" placeholder="검색어" aria-label="검색어" />
    <button type="button" id="iqd-search">검색</button>
</div>

<div id="aggrid-납품출하대기목록"></div>
<script>
var IQD_GRID_ID = '납품출하대기목록';
var IQD_BAR = document.querySelector('.iqd-bar');
// 신규추가는 문의 목록(inquiry_문의_entry.php)과 같은 "문의확인" 탭을 돌려쓴다.
var IQD_CONTENT_TAB = '문의확인';
// 행 더블클릭은 따로 "납품출하대기확인" 탭 하나를 돌려쓴다(문의확인 화면의 목록 모드, ?open=납품출하대기&detail=).
var IQD_DETAIL_TAB = '납품출하대기확인';

// 필드감추기 — {success, error, recs}. 이 화면의 nickname은 '납품출하대기'.
var IQD_FIELDVISIBLE = <?= json_encode($fieldVisible, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
var IQD_NICKNAME = '납품출하대기';

<?php if ($statusError !== ''): ?>
console.warn('tb상태_수주을 읽지 못해 상태 콤보가 비어 있습니다: ' + <?= json_encode($statusError, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>);
<?php endif; ?>
<?php if (count($missingFields) > 0): ?>
console.warn('v납품출하대기에 없는 필드라 뺐습니다: ' + <?= json_encode(implode(', ', $missingFields), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>);
<?php endif; ?>

new AGGrid({
    gridId: IQD_GRID_ID,
    fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    primaryKey: <?= json_encode($primaryKey, JSON_UNESCAPED_UNICODE) ?>,
    fetchMode: 'paged',
    pageSize: 100,
    autoFitBottomGap: 40,
    showSearch: false,
    showAddRow: false,
    showDeleteColumn: false,
    onReady: function (pgrid) {
        Applyds(pgrid, IQD_NICKNAME, IQD_FIELDVISIBLE);
    },
    onRowDblClick: function (pvalues) {
        iqdOpenDetail(pvalues.parentcode, pvalues.code);
    },
    onToolbarRendered: function (pgrid) {
        iqdDecorateToolbar(pgrid);
    },
    columns: iqdPrepareColumns(<?= json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>)
});

/** code 계열(int)은 번호라 천단위 콤마를 붙이지 않는다. */
function iqdPrepareColumns(pcolumns) {
    var i;

    for (i = 0; i < pcolumns.length; i++) {
        if (/code$/i.test(pcolumns[i].field)) {
            pcolumns[i].valueFormatter = iqdPlainFormatter;
        }
    }
    return pcolumns;
}

function iqdPlainFormatter(pparams) {
    if (pparams.value === null || pparams.value === undefined) {
        return '';
    }
    return pparams.value;
}

/**
 * 툴바 맨 앞 "편집" 버튼을 빼고 "납품출하대기" 제목을 두고, 검색 도구(.iqd-bar)를
 * 툴바 오른쪽으로 옮긴다. 툴바는 다시 그려질 때마다 innerHTML째 바뀌므로 매번 한다.
 */
function iqdDecorateToolbar(pgrid) {
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
    title.className = 'iqd-title';
    title.textContent = '납품출하대기';
    left.insertBefore(title, left.firstChild);

    right = document.querySelector('#' + CSS.escape(pgrid.toolbarId) + ' .ag-shell-toolbar-right');
    if (right) {
        right.insertBefore(IQD_BAR, right.firstChild);
    }
}

function iqdGrid() {
    return AGGrid.instance(IQD_GRID_ID);
}

/**
 * search(상태, 검색어) — 인자를 주면 콤보/입력칸을 그 값으로 바꾼 뒤 검색한다.
 * 상태 ''는 전체. 검색어는 표시 필드 전체에 LIKE. 둘 다 있으면 AND.
 */
function search(pstatus, pkeyword) {
    var statusEl;
    var keywordEl;
    var grid;

    statusEl = document.getElementById('iqd-status');
    keywordEl = document.getElementById('iqd-keyword');
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
    grid = iqdGrid();
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

    grid = iqdGrid();
    if (!grid || !grid.gridApi) {
        return;
    }
    grid.requery();
}

/**
 * iqdOpenContent(pcode) — 신규추가. 문의확인 화면(inquiryContent_entry.php)을 "문의확인" 탭에
 * 신규('new')로 연다. 이미 떠 있으면 그 화면에 action('openInquiry', {code})만 보낸다(다시 열지 않음).
 * inquiry_문의_entry.php의 openInquiryContent와 같은 일.
 */
async function iqdOpenContent(pcode) {
    var url;
    var title;
    var shell;
    var win;
    var result;

    url = 'workspace/inquiry/inquiryContent_entry.php?code=' + encodeURIComponent(pcode);
    title = '문의 (' + pcode + ')';
    shell = window.parent;

    if (shell === window || typeof shell.setContent !== 'function') {
        location.href = 'inquiryContent_entry.php?code=' + encodeURIComponent(pcode);
        return true;
    }

    win = iqdFindContentWindow(shell, IQD_CONTENT_TAB);
    if (win === null) {
        shell.setContent(url, title, IQD_CONTENT_TAB);
        return true;
    }

    result = await win.action('openInquiry', { code: pcode }, window);
    if (result === 'unsupported') {
        shell.setContent(url, title, IQD_CONTENT_TAB);
    }
    return true;
}

/**
 * iqdOpenDetail(pparentcode, pcode) — 행 더블클릭. "납품출하대기확인" 탭(다른 목록에서 연 탭과 따로)에
 * 그 행의 문의(pparentcode)를 문의확인 화면으로 열고, 그 위에 수주(pcode) amd를 팝업으로 띄운다.
 * 탭 제목은 "납품출하대기 (pparentcode)" — 괄호 안은 항상 t문의.code.
 *  - 탭이 이미 떠 있으면 다시 열지 않고 action('openInquiryDetail', {code, detail})로 덮어쓴다.
 *  - 없거나 아직 준비 전('unsupported')이면 setContent로 새로 연다(?code=&open=납품출하대기&detail=).
 */
async function iqdOpenDetail(pparentcode, pcode) {
    var query;
    var url;
    var title;
    var shell;
    var win;
    var result;

    if (pparentcode === null || pparentcode === undefined || pparentcode === '') {
        alert('이 수주에는 문의(parentcode)가 없습니다.');
        return false;
    }
    query = '?code=' + encodeURIComponent(pparentcode)
        + '&open=' + encodeURIComponent('납품출하대기') + '&detail=' + encodeURIComponent(pcode);
    url = 'workspace/inquiry/inquiryContent_entry.php' + query;
    // 탭 제목의 괄호 안은 항상 t문의.code(이 행의 parentcode).
    title = '납품출하대기 (' + pparentcode + ')';
    shell = window.parent;

    if (shell === window || typeof shell.setContent !== 'function') {
        location.href = 'inquiryContent_entry.php' + query;
        return true;
    }

    win = iqdFindContentWindow(shell, IQD_DETAIL_TAB);
    if (win === null) {
        shell.setContent(url, title, IQD_DETAIL_TAB);
        return true;
    }

    result = await win.action('openInquiryDetail', { code: pparentcode, detail: pcode }, window);
    if (result === 'unsupported') {
        shell.setContent(url, title, IQD_DETAIL_TAB);
    }
    return true;
}

/** 셸에 떠 있는 문의확인 화면의 창(IC_TAB_KEY가 ptabKey이고 action이 있는 탭 iframe). 없으면 null. */
function iqdFindContentWindow(pshell, ptabKey) {
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
            console.warn('납품출하대기.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

// 상태 콤보는 처음에 아무것도 고르지 않은 빈 칸(=전체 조회).
document.getElementById('iqd-status').selectedIndex = -1;

document.getElementById('iqd-add').addEventListener('click', function () {
    iqdOpenContent('new');
});
document.getElementById('iqd-search').addEventListener('click', function () {
    search();
});
document.getElementById('iqd-status').addEventListener('change', function () {
    search();
});
// 콤보/검색어 칸에 커서가 들어가면 값을 비우고 바로 검색한다(문의 화면과 같음).
document.getElementById('iqd-status').addEventListener('focus', function () {
    if (this.selectedIndex === -1 || this.value === '') {
        this.selectedIndex = -1;
        return;
    }
    this.selectedIndex = -1;
    search();
});
document.getElementById('iqd-keyword').addEventListener('focus', function () {
    if (this.value === '') {
        return;
    }
    this.value = '';
    search();
});
document.getElementById('iqd-keyword').addEventListener('keydown', function (pe) {
    if (pe.key === 'Enter' && !pe.isComposing) {
        search();
    }
});
</script>
</body>
</html>
