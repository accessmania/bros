<?php
declare(strict_types=1);

/**
 * [문의내용(v문의내역) AGGrid 화면 — 메뉴 "업무 > 문의내용"]
 * 구성: inquiry_문의내용_entry.php(이 파일) + inquiry_문의내용_fetch.php(조회 전용).
 * 문의 목록(inquiry_문의_entry.php)과 같은 모양이다 — 바탕화면 문의내용.png 기준:
 *  - 원본은 뷰 v문의내역(t문의내역.* + t문의.고객사 + t문의.문의일자). Access의
 *    sp문의내역(통과 쿼리) → q문의내역 → getsearch.init과 같은 자리.
 *  - 컬럼은 그림 순서로 고정($detailFields). 뷰에 없는 필드는 빼고 콘솔에 경고한다.
 *  - 그림 위쪽의 토글단추(문의/문의내역/견적...)는 두지 않는다(문의 화면과 같음).
 *  - 오른쪽 위에 신규추가 / 상태 콤보 / 검색어 / 검색 버튼. 상태 콤보의 원본자료는
 *    tb상태_문의내역.상태. 검색어는 ?q=(표시 필드 전체), 상태는 ?st=.
 *  - 보기 전용. 행 더블클릭 → "문의내용확인" 탭(문의 목록의 "문의확인" 탭과 따로, 제목
 *    "문의내용 (t문의.code)")에 그 행의 문의(parentcode)를 문의확인 화면으로 열고, 그 문의내역(code)
 *    amd를 팝업으로 띄운다. 다른 행을 더블클릭하면 같은 탭을 덮어쓴다(idtOpenDetail →
 *    inquiryContent_entry.php의 목록 모드 / action('openInquiryDetail')).
 *  - 신규추가 → 문의확인을 신규(new)로 연다(문의 목록과 같은 "문의확인" 탭).
 *  - 필드감추기: nickname '문의내용'(Access applyDs("문의내용", ...)) — src/Auth/FieldVisible.md.
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
$detailDb = new DbContext($connectionName);
$viewName = 'v문의내역';

// 표시 필드 — 문의내용.png 순서. inquiry_문의내용_fetch.php의 $detailFields와 같게 둔다.
$detailFields = [
    'code', 'parentcode', '제품code', '문의일자', '상태', '제품종류', '고객사',
    '모델명', '품목코드', '제조사', '수량', '외화매입가', '구매원가', '구매메모',
    '판매단가', '비고', '마진율', '판매메모', 'USD구매원가', 'USD판매단가', 'USD마진율',
];

$probe = new Crud($detailDb);
$probe->init($viewName);
$primaryKey = $probe->getPrimaryKey();
$fieldSchema = $probe->getSchema()['fields'];

$numericTypes = ['int', 'bigint', 'smallint', 'tinyint', 'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney'];
$columns = [];
$missingFields = [];
foreach ($detailFields as $field) {
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
 * 상태 콤보의 원본자료 — tb상태_문의내역.상태(code 오름차순). 문의 화면의
 * tb상태_문의와 같은 방식. 읽기에 실패해도 화면은 뜨고 콤보만 빈다($statusError).
 */
$statusOptions = [];
$statusError = '';
try {
    $statusStmt = $detailDb->pdo()->query('SELECT tb상태_문의내역.상태 FROM tb상태_문의내역 ORDER BY tb상태_문의내역.code ASC');
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
$fieldVisible = FieldVisible::init($detailDb);

$fetchUrl = APP_BASE_URL . '/workspace/inquiry/' . rawurlencode('inquiry_문의내용_fetch.php');

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
<title>문의내용</title>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
<script src="<?= APP_BASE_URL ?>/src/Auth/FieldVisible.js?v=<?= $fieldVisibleJsVersion ?>"></script>
<style>
html, body { margin: 0; height: 100%; }
body { padding: 16px; box-sizing: border-box; font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif; }

/* 검색 도구 묶음 — 그리드 툴바 오른쪽, "문의내용" 제목과 같은 줄(onToolbarRendered). 문의 화면과 같은 모양. */
.idt-bar { display: flex; justify-content: flex-end; align-items: center; gap: 6px; }
.idt-bar button, .idt-bar select, .idt-bar input {
    font: inherit; font-size: 0.82rem; height: 28px; box-sizing: border-box;
    border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #1e293b;
}
.idt-bar button { padding: 0 12px; cursor: pointer; }
.idt-bar button:hover { background: #f3f4f6; }
.idt-bar .idt-add-btn { background: #bfdbfe; border-color: #bfdbfe; }
.idt-bar .idt-add-btn:hover { background: #93c5fd; }
.idt-bar select { padding: 0 6px; min-width: 120px; }
.idt-bar input { padding: 0 8px; width: 220px; }
.idt-bar select:focus, .idt-bar input:focus { outline: none; border-color: #6b7280; }

.idt-title {
    margin-left: 0.5cm; margin-right: 12px; padding-left: 8px;
    border-left: 4px solid #3b82f6;
    font-family: 'Pretendard', 'Malgun Gothic', 'Segoe UI', sans-serif;
    font-size: 1.15rem; font-weight: 700; letter-spacing: 0.08em; line-height: 1.2;
    color: #1e3a8a;
}
</style>
</head>
<body>

<div class="idt-bar">
    <button type="button" class="idt-add-btn" id="idt-add">+ 신규추가</button>
    <!-- 상태 콤보 — 원본자료는 tb상태_문의내역.상태. 처음엔 아무것도 고르지 않은 빈 상태. -->
    <select id="idt-status" aria-label="상태" autocomplete="off">
        <!-- 맨 위 공란 — 고르면 상태 조건 없이 전체를 조회한다. 상태 표에 빈 값이 있어도 공란은 이것 하나만 둔다. -->
        <option value=""></option>
<?php foreach ($statusOptions as $status): ?>
<?php if ($status === '') { continue; } ?>
        <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
    </select>
    <input type="text" id="idt-keyword" placeholder="검색어" aria-label="검색어" />
    <button type="button" id="idt-search">검색</button>
</div>

<div id="aggrid-문의내용목록"></div>
<script>
var IDT_GRID_ID = '문의내용목록';
var IDT_BAR = document.querySelector('.idt-bar');
// 신규추가는 문의 목록(inquiry_문의_entry.php)과 같은 "문의확인" 탭을 돌려쓴다.
var IDT_CONTENT_TAB = '문의확인';
// 행 더블클릭은 따로 "문의내용확인" 탭 하나를 돌려쓴다(문의확인 화면의 목록 모드, ?open=문의내용&detail=).
var IDT_DETAIL_TAB = '문의내용확인';

// 필드감추기 — {success, error, recs}. 이 화면의 nickname은 '문의내용'.
var IDT_FIELDVISIBLE = <?= json_encode($fieldVisible, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
var IDT_NICKNAME = '문의내용';

<?php if ($statusError !== ''): ?>
console.warn('tb상태_문의내역을 읽지 못해 상태 콤보가 비어 있습니다: ' + <?= json_encode($statusError, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>);
<?php endif; ?>
<?php if (count($missingFields) > 0): ?>
console.warn('v문의내역에 없는 필드라 뺐습니다: ' + <?= json_encode(implode(', ', $missingFields), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>);
<?php endif; ?>

new AGGrid({
    gridId: IDT_GRID_ID,
    fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    primaryKey: <?= json_encode($primaryKey, JSON_UNESCAPED_UNICODE) ?>,
    fetchMode: 'paged',
    pageSize: 100,
    autoFitBottomGap: 40,
    showSearch: false,
    showAddRow: false,
    showDeleteColumn: false,
    onReady: function (pgrid) {
        Applyds(pgrid, IDT_NICKNAME, IDT_FIELDVISIBLE);
    },
    onRowDblClick: function (pvalues) {
        idtOpenDetail(pvalues.parentcode, pvalues.code);
    },
    onToolbarRendered: function (pgrid) {
        idtDecorateToolbar(pgrid);
    },
    columns: idtPrepareColumns(<?= json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>)
});

/** code 계열(int)은 번호라 천단위 콤마를 붙이지 않는다. */
function idtPrepareColumns(pcolumns) {
    var i;

    for (i = 0; i < pcolumns.length; i++) {
        if (/code$/i.test(pcolumns[i].field)) {
            pcolumns[i].valueFormatter = idtPlainFormatter;
        }
    }
    return pcolumns;
}

function idtPlainFormatter(pparams) {
    if (pparams.value === null || pparams.value === undefined) {
        return '';
    }
    return pparams.value;
}

/**
 * 툴바 맨 앞 "편집" 버튼을 빼고 "문의내용" 제목을 두고, 검색 도구(.idt-bar)를
 * 툴바 오른쪽으로 옮긴다. 툴바는 다시 그려질 때마다 innerHTML째 바뀌므로 매번 한다.
 */
function idtDecorateToolbar(pgrid) {
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
    title.className = 'idt-title';
    title.textContent = '문의내용';
    left.insertBefore(title, left.firstChild);

    right = document.querySelector('#' + CSS.escape(pgrid.toolbarId) + ' .ag-shell-toolbar-right');
    if (right) {
        right.insertBefore(IDT_BAR, right.firstChild);
    }
}

function idtGrid() {
    return AGGrid.instance(IDT_GRID_ID);
}

/**
 * search(상태, 검색어) — 인자를 주면 콤보/입력칸을 그 값으로 바꾼 뒤 검색한다.
 * 상태 ''는 전체. 검색어는 표시 필드 전체에 LIKE. 둘 다 있으면 AND.
 */
function search(pstatus, pkeyword) {
    var statusEl;
    var keywordEl;
    var grid;

    statusEl = document.getElementById('idt-status');
    keywordEl = document.getElementById('idt-keyword');
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
    grid = idtGrid();
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

    grid = idtGrid();
    if (!grid || !grid.gridApi) {
        return;
    }
    grid.requery();
}

/**
 * idtOpenContent(pcode) — 신규추가. 문의확인 화면(inquiryContent_entry.php)을 "문의확인" 탭에
 * 신규('new')로 연다. 이미 떠 있으면 그 화면에 action('openInquiry', {code})만 보낸다(다시 열지 않음).
 * inquiry_문의_entry.php의 openInquiryContent와 같은 일.
 */
async function idtOpenContent(pcode) {
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

    win = idtFindContentWindow(shell, IDT_CONTENT_TAB);
    if (win === null) {
        shell.setContent(url, title, IDT_CONTENT_TAB);
        return true;
    }

    result = await win.action('openInquiry', { code: pcode }, window);
    if (result === 'unsupported') {
        shell.setContent(url, title, IDT_CONTENT_TAB);
    }
    return true;
}

/**
 * idtOpenDetail(pparentcode, pcode) — 행 더블클릭. "문의내용확인" 탭(문의 목록의 "문의확인"
 * 탭과 따로)에 그 행의 문의(pparentcode)를 문의확인 화면으로 열고, 그 위에 문의내역(pcode)
 * amd를 팝업으로 띄운다. 탭 제목은 "문의내용 (pparentcode)" — 괄호 안은 항상 t문의.code.
 *  - 탭이 이미 떠 있으면 다시 열지 않고 action('openInquiryDetail', {code, detail})로 덮어쓴다.
 *  - 없거나 아직 준비 전('unsupported')이면 setContent로 새로 연다(?code=&open=문의내용&detail=).
 */
async function idtOpenDetail(pparentcode, pcode) {
    var url;
    var title;
    var shell;
    var win;
    var result;

    if (pparentcode === null || pparentcode === undefined || pparentcode === '') {
        alert('이 문의내역에는 문의(parentcode)가 없습니다.');
        return false;
    }
    url = 'workspace/inquiry/inquiryContent_entry.php?code=' + encodeURIComponent(pparentcode)
        + '&open=' + encodeURIComponent('문의내용') + '&detail=' + encodeURIComponent(pcode);
    // 탭 제목의 괄호 안은 항상 t문의.code(이 행의 parentcode).
    title = '문의내용 (' + pparentcode + ')';
    shell = window.parent;

    if (shell === window || typeof shell.setContent !== 'function') {
        location.href = 'inquiryContent_entry.php?code=' + encodeURIComponent(pparentcode)
            + '&open=' + encodeURIComponent('문의내용') + '&detail=' + encodeURIComponent(pcode);
        return true;
    }

    win = idtFindContentWindow(shell, IDT_DETAIL_TAB);
    if (win === null) {
        shell.setContent(url, title, IDT_DETAIL_TAB);
        return true;
    }

    result = await win.action('openInquiryDetail', { code: pparentcode, detail: pcode }, window);
    if (result === 'unsupported') {
        shell.setContent(url, title, IDT_DETAIL_TAB);
    }
    return true;
}

/** 셸에 떠 있는 문의확인 화면의 창(IC_TAB_KEY가 ptabKey이고 action이 있는 탭 iframe). 없으면 null. */
function idtFindContentWindow(pshell, ptabKey) {
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
            console.warn('문의내용.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

// 상태 콤보는 처음에 아무것도 고르지 않은 빈 칸(=전체 조회).
document.getElementById('idt-status').selectedIndex = -1;

document.getElementById('idt-add').addEventListener('click', function () {
    idtOpenContent('new');
});
document.getElementById('idt-search').addEventListener('click', function () {
    search();
});
document.getElementById('idt-status').addEventListener('change', function () {
    search();
});
// 콤보/검색어 칸에 커서가 들어가면 값을 비우고 바로 검색한다(문의 화면과 같음).
document.getElementById('idt-status').addEventListener('focus', function () {
    if (this.selectedIndex === -1 || this.value === '') {
        this.selectedIndex = -1;
        return;
    }
    this.selectedIndex = -1;
    search();
});
document.getElementById('idt-keyword').addEventListener('focus', function () {
    if (this.value === '') {
        return;
    }
    this.value = '';
    search();
});
document.getElementById('idt-keyword').addEventListener('keydown', function (pe) {
    if (pe.key === 'Enter' && !pe.isComposing) {
        search();
    }
});
</script>
</body>
</html>
