<?php
declare(strict_types=1);

/**
 * [견적(v문의_견적산출) AGGrid 화면 — 메뉴 "업무 > 견적"]
 * 구성: inquiry_견적_entry.php(이 파일) + inquiry_견적_fetch.php(조회 전용).
 * 문의내용 목록(inquiry_문의내용_entry.php)과 같은 모양·동작이다:
 *  - 원본은 뷰 v문의_견적산출. Access의 mSP.create("sp문의_견적산출", "select * from v문의_견적산출", True)
 *    → 검색 → 데이터시트와 같은 자리. parentcode = t문의.code, 문의code = t문의내역.code.
 *  - 컬럼은 $quoteFields 순서로 고정. 뷰에 없는 필드는 빼고 콘솔에 경고한다.
 *  - 오른쪽 위에 신규추가 / 상태 콤보 / 검색어 / 검색 버튼. 상태 콤보의 원본자료는
 *    tb상태_견적.상태. 검색어는 ?q=(표시 필드 전체), 상태는 ?st=.
 *  - 보기 전용. 행 더블클릭 → "견적확인" 탭(문의확인·문의내용확인 탭과 따로, 제목
 *    "견적 (t문의.code)")에 그 행의 문의(parentcode)를 문의확인 화면으로 열고, 그 견적(code)
 *    amd를 팝업으로 띄운다. 다른 행을 더블클릭하면 같은 탭을 덮어쓴다(iqeOpenDetail →
 *    inquiryContent_entry.php의 목록 모드 ?open=견적 / action('openInquiryDetail')).
 *  - 신규추가 → 문의확인을 신규(new)로 연다(문의 목록과 같은 "문의확인" 탭).
 *  - 필드감추기: nickname '견적' — src/Auth/FieldVisible.md.
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
$quoteDb = new DbContext($connectionName);
$viewName = 'v문의_견적산출';

// 표시 필드 — Access 견적 목록 순서. inquiry_견적_fetch.php의 $quoteFields와 같게 둔다.
$quoteFields = [
    'code', 'parentcode', '문의code', '상태', '견적날짜', '제품code', '고객사', '제품종류',
    '품목코드', '모델명', '제조사', '수량', '중량', 'Zone', '중량코드', 'StandardPrice',
    'DistiPrice', '적용환율', '할인률', '원화구매가', '유류할증요율', '은행수수료', '관세', '승수',
    '견적환율', '견적단가', 'USD단가', '실중량KG', 'DHL단가', '개별운송비', '운임비율', 'MOQ',
    '원화원가', '달러원가', '예상단가', '예상USD', '중량단가', '중량화물비용', 'KG배율', '부피중량',
    '화물배율', '비고',
];

$probe = new Crud($quoteDb);
$probe->init($viewName);
$primaryKey = $probe->getPrimaryKey();
$fieldSchema = $probe->getSchema()['fields'];

$numericTypes = ['int', 'bigint', 'smallint', 'tinyint', 'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney'];
$columns = [];
$missingFields = [];
foreach ($quoteFields as $field) {
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
 * 상태 콤보의 원본자료 — tb상태_견적.상태(code 오름차순). 문의 화면의
 * tb상태_문의와 같은 방식. 읽기에 실패해도 화면은 뜨고 콤보만 빈다($statusError).
 */
$statusOptions = [];
$statusError = '';
try {
    $statusStmt = $quoteDb->pdo()->query('SELECT tb상태_견적.상태 FROM tb상태_견적 ORDER BY tb상태_견적.code ASC');
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
$fieldVisible = FieldVisible::init($quoteDb);

$fetchUrl = APP_BASE_URL . '/workspace/inquiry/' . rawurlencode('inquiry_견적_fetch.php');

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
<title>견적</title>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
<script src="<?= APP_BASE_URL ?>/src/Auth/FieldVisible.js?v=<?= $fieldVisibleJsVersion ?>"></script>
<style>
html, body { margin: 0; height: 100%; }
body { padding: 16px; box-sizing: border-box; font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif; }

/* 검색 도구 묶음 — 그리드 툴바 오른쪽, "견적" 제목과 같은 줄(onToolbarRendered). 문의 화면과 같은 모양. */
.iqe-bar { display: flex; justify-content: flex-end; align-items: center; gap: 6px; }
.iqe-bar button, .iqe-bar select, .iqe-bar input {
    font: inherit; font-size: 0.82rem; height: 28px; box-sizing: border-box;
    border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #1e293b;
}
.iqe-bar button { padding: 0 12px; cursor: pointer; }
.iqe-bar button:hover { background: #f3f4f6; }
.iqe-bar .iqe-add-btn { background: #bfdbfe; border-color: #bfdbfe; }
.iqe-bar .iqe-add-btn:hover { background: #93c5fd; }
.iqe-bar select { padding: 0 6px; min-width: 120px; }
.iqe-bar input { padding: 0 8px; width: 220px; }
.iqe-bar select:focus, .iqe-bar input:focus { outline: none; border-color: #6b7280; }

.iqe-title {
    margin-left: 0.5cm; margin-right: 12px; padding-left: 8px;
    border-left: 4px solid #3b82f6;
    font-family: 'Pretendard', 'Malgun Gothic', 'Segoe UI', sans-serif;
    font-size: 1.15rem; font-weight: 700; letter-spacing: 0.08em; line-height: 1.2;
    color: #1e3a8a;
}
</style>
</head>
<body>

<div class="iqe-bar">
    <button type="button" class="iqe-add-btn" id="iqe-add">+ 신규추가</button>
    <!-- 상태 콤보 — 원본자료는 tb상태_견적.상태. 처음엔 아무것도 고르지 않은 빈 상태. -->
    <select id="iqe-status" aria-label="상태" autocomplete="off">
        <!-- 맨 위 공란 — 고르면 상태 조건 없이 전체를 조회한다. 상태 표에 빈 값이 있어도 공란은 이것 하나만 둔다. -->
        <option value=""></option>
<?php foreach ($statusOptions as $status): ?>
<?php if ($status === '') { continue; } ?>
        <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
    </select>
    <input type="text" id="iqe-keyword" placeholder="검색어" aria-label="검색어" />
    <button type="button" id="iqe-search">검색</button>
</div>

<div id="aggrid-견적목록"></div>
<script>
var IQE_GRID_ID = '견적목록';
var IQE_BAR = document.querySelector('.iqe-bar');
// 신규추가는 문의 목록(inquiry_문의_entry.php)과 같은 "문의확인" 탭을 돌려쓴다.
var IQE_CONTENT_TAB = '문의확인';
// 행 더블클릭은 따로 "견적확인" 탭 하나를 돌려쓴다(문의확인 화면의 목록 모드, ?open=견적&detail=).
var IQE_DETAIL_TAB = '견적확인';

// 필드감추기 — {success, error, recs}. 이 화면의 nickname은 '견적'.
var IQE_FIELDVISIBLE = <?= json_encode($fieldVisible, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
var IQE_NICKNAME = '견적';

<?php if ($statusError !== ''): ?>
console.warn('tb상태_견적을 읽지 못해 상태 콤보가 비어 있습니다: ' + <?= json_encode($statusError, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>);
<?php endif; ?>
<?php if (count($missingFields) > 0): ?>
console.warn('v문의_견적산출에 없는 필드라 뺐습니다: ' + <?= json_encode(implode(', ', $missingFields), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>);
<?php endif; ?>

new AGGrid({
    gridId: IQE_GRID_ID,
    fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    primaryKey: <?= json_encode($primaryKey, JSON_UNESCAPED_UNICODE) ?>,
    fetchMode: 'paged',
    pageSize: 100,
    autoFitBottomGap: 40,
    showSearch: false,
    showAddRow: false,
    showDeleteColumn: false,
    onReady: function (pgrid) {
        Applyds(pgrid, IQE_NICKNAME, IQE_FIELDVISIBLE);
    },
    onRowDblClick: function (pvalues) {
        iqeOpenDetail(pvalues.parentcode, pvalues.code);
    },
    onToolbarRendered: function (pgrid) {
        iqeDecorateToolbar(pgrid);
    },
    columns: iqePrepareColumns(<?= json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>)
});

/** code 계열(int)은 번호라 천단위 콤마를 붙이지 않는다. */
function iqePrepareColumns(pcolumns) {
    var i;

    for (i = 0; i < pcolumns.length; i++) {
        if (/code$/i.test(pcolumns[i].field)) {
            pcolumns[i].valueFormatter = iqePlainFormatter;
        }
    }
    return pcolumns;
}

function iqePlainFormatter(pparams) {
    if (pparams.value === null || pparams.value === undefined) {
        return '';
    }
    return pparams.value;
}

/**
 * 툴바 맨 앞 "편집" 버튼을 빼고 "견적" 제목을 두고, 검색 도구(.iqe-bar)를
 * 툴바 오른쪽으로 옮긴다. 툴바는 다시 그려질 때마다 innerHTML째 바뀌므로 매번 한다.
 */
function iqeDecorateToolbar(pgrid) {
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
    title.className = 'iqe-title';
    title.textContent = '견적';
    left.insertBefore(title, left.firstChild);

    right = document.querySelector('#' + CSS.escape(pgrid.toolbarId) + ' .ag-shell-toolbar-right');
    if (right) {
        right.insertBefore(IQE_BAR, right.firstChild);
    }
}

function iqeGrid() {
    return AGGrid.instance(IQE_GRID_ID);
}

/**
 * search(상태, 검색어) — 인자를 주면 콤보/입력칸을 그 값으로 바꾼 뒤 검색한다.
 * 상태 ''는 전체. 검색어는 표시 필드 전체에 LIKE. 둘 다 있으면 AND.
 */
function search(pstatus, pkeyword) {
    var statusEl;
    var keywordEl;
    var grid;

    statusEl = document.getElementById('iqe-status');
    keywordEl = document.getElementById('iqe-keyword');
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
    grid = iqeGrid();
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

    grid = iqeGrid();
    if (!grid || !grid.gridApi) {
        return;
    }
    grid.requery();
}

/**
 * iqeOpenContent(pcode) — 신규추가. 문의확인 화면(inquiryContent_entry.php)을 "문의확인" 탭에
 * 신규('new')로 연다. 이미 떠 있으면 그 화면에 action('openInquiry', {code})만 보낸다(다시 열지 않음).
 * inquiry_문의_entry.php의 openInquiryContent와 같은 일.
 */
async function iqeOpenContent(pcode) {
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

    win = iqeFindContentWindow(shell, IQE_CONTENT_TAB);
    if (win === null) {
        shell.setContent(url, title, IQE_CONTENT_TAB);
        return true;
    }

    result = await win.action('openInquiry', { code: pcode }, window);
    if (result === 'unsupported') {
        shell.setContent(url, title, IQE_CONTENT_TAB);
    }
    return true;
}

/**
 * iqeOpenDetail(pparentcode, pcode) — 행 더블클릭. "견적확인" 탭(문의확인·문의내용확인 탭과 따로)에
 * 그 행의 문의(pparentcode)를 문의확인 화면으로 열고, 그 위에 견적(pcode) amd를 팝업으로 띄운다.
 * 탭 제목은 "견적 (pparentcode)" — 괄호 안은 항상 t문의.code.
 *  - 탭이 이미 떠 있으면 다시 열지 않고 action('openInquiryDetail', {code, detail})로 덮어쓴다.
 *  - 없거나 아직 준비 전('unsupported')이면 setContent로 새로 연다(?code=&open=견적&detail=).
 */
async function iqeOpenDetail(pparentcode, pcode) {
    var query;
    var url;
    var title;
    var shell;
    var win;
    var result;

    if (pparentcode === null || pparentcode === undefined || pparentcode === '') {
        alert('이 견적에는 문의(parentcode)가 없습니다.');
        return false;
    }
    query = '?code=' + encodeURIComponent(pparentcode)
        + '&open=' + encodeURIComponent('견적') + '&detail=' + encodeURIComponent(pcode);
    url = 'workspace/inquiry/inquiryContent_entry.php' + query;
    // 탭 제목의 괄호 안은 항상 t문의.code(이 행의 parentcode).
    title = '견적 (' + pparentcode + ')';
    shell = window.parent;

    if (shell === window || typeof shell.setContent !== 'function') {
        location.href = 'inquiryContent_entry.php' + query;
        return true;
    }

    win = iqeFindContentWindow(shell, IQE_DETAIL_TAB);
    if (win === null) {
        shell.setContent(url, title, IQE_DETAIL_TAB);
        return true;
    }

    result = await win.action('openInquiryDetail', { code: pparentcode, detail: pcode }, window);
    if (result === 'unsupported') {
        shell.setContent(url, title, IQE_DETAIL_TAB);
    }
    return true;
}

/** 셸에 떠 있는 문의확인 화면의 창(IC_TAB_KEY가 ptabKey이고 action이 있는 탭 iframe). 없으면 null. */
function iqeFindContentWindow(pshell, ptabKey) {
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
            console.warn('견적.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

// 상태 콤보는 처음에 아무것도 고르지 않은 빈 칸(=전체 조회).
document.getElementById('iqe-status').selectedIndex = -1;

document.getElementById('iqe-add').addEventListener('click', function () {
    iqeOpenContent('new');
});
document.getElementById('iqe-search').addEventListener('click', function () {
    search();
});
document.getElementById('iqe-status').addEventListener('change', function () {
    search();
});
// 콤보/검색어 칸에 커서가 들어가면 값을 비우고 바로 검색한다(문의 화면과 같음).
document.getElementById('iqe-status').addEventListener('focus', function () {
    if (this.selectedIndex === -1 || this.value === '') {
        this.selectedIndex = -1;
        return;
    }
    this.selectedIndex = -1;
    search();
});
document.getElementById('iqe-keyword').addEventListener('focus', function () {
    if (this.value === '') {
        return;
    }
    this.value = '';
    search();
});
document.getElementById('iqe-keyword').addEventListener('keydown', function (pe) {
    if (pe.key === 'Enter' && !pe.isComposing) {
        search();
    }
});
</script>
</body>
</html>
