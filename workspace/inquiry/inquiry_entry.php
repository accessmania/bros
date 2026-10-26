<?php
declare(strict_types=1);

/**
 * [문의(t문의) AGGrid 화면 — 메뉴 "업무 > 문의"]
 * 구성: workspace/inquiry/의 inquiry_entry.php(이 파일, 화면 + 프론트 config)
 * + inquiry_fetch.php(CRUD). 기초자료형
 * (src/js/aggrid/aggrid.md)과 같은 틀이되 다음이 다르다 — 바탕화면 문의.png 기준:
 *  - 컬럼은 그림 순서로 고정하고 code 계열 컬럼도 보인다(code는 읽기 전용).
 *  - 그림 위쪽의 토글단추(문의/문의내용/견적...)는 두지 않는다.
 *  - 오른쪽 위에 신규추가 / 상태 콤보 / 검색어 / 검색 버튼을 두고,
 *    AGGrid 자체 검색창은 끈다(showSearch:false). 검색은 이 바깥 콤보와
 *    입력칸 값으로만 한다 — 검색어는 grid.searchKeyword(?q=, 전체 필드),
 *    상태는 setExtraQuery({st})(?st=, 그 상태만)로 서버에 보낸다.
 *  - 상태 콤보의 원본자료는 Access의 Me.상태.RowSource =
 *    "SELECT tb상태_문의.상태 FROM tb상태_문의"와 같다(화면이 뜰 때 한 번 읽음).
 *  - 기본 정렬은 code 내림차순(inquiry_fetch.php).
 *  - 그리드 툴바의 "편집" 버튼은 빼고 그 자리에 "문의" 제목을 둔다
 *    (onToolbarRendered). 목록은 보기 전용이고, 신규/수정은 문의확인 화면
 *    (inquiryContent_entry.php)에서 한다 — 더블클릭/"+ 신규추가" 모두 "문의확인"
 *    탭 하나를 돌려쓴다(openInquiryContent).
 *
 * 페이지 함수(index에서 iframe.contentWindow로도 부를 수 있다):
 *  - search(상태, 검색어) — 인자를 주면 콤보/입력칸을 그 값으로 바꾸고, 생략하면
 *    지금 콤보/입력칸 값으로 검색한다. 상태 ''는 전체.
 *  - requery() — 지금 검색 조건 그대로 다시 불러온다.
 *  - selectRecord(code) — t문의.code로 레코드 하나를 고르고 문의확인 화면
 *    (inquiryContent_entry.php)을 "문의확인" 탭(하나를 돌려씀)에 연다. 그리드 행 더블클릭 시 호출.
 *  - action(pcmd, pparams, psender) — 'search' / 'requery' / 'selectRecord'({pcode}). index가 탭을
 *    옮길 때 action('requery')를 부르는데, 저장 안 한 편집이 있으면 그건
 *    건너뛴다(탭만 옮겼는데 편집이 날아가지 않게).
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

$connectionName = null; // config/db.php의 'driver' 기본 접속을 따른다
$inquiryGridDb = new DbContext($connectionName);
$tableName = 't문의';

// 표시 필드 — 문의.png 순서. 배송주소/ts는 넣지 않는다. inquiry_fetch.php의
// $inquiryFields와 같게 둔다(파일 하나 = 자기완결 원칙의 의도된 중복).
$inquiryFields = [
    'code', '문의일자', '멤버code', '멤버', '상태',
    '고객사code', '고객사', '고객사직원code', '고객사직원',
    '직책', '회사전화번호', '휴대폰', '팩스', '이메일', '근무지주소', '비고',
];

$probe = new Crud($inquiryGridDb);
$probe->init($tableName);
$primaryKey = $probe->getPrimaryKey();
$fieldSchema = $probe->getSchema()['fields'];

/**
 * SQL DATA_TYPE → AGGrid.js의 dataType('string'|'number') 힌트
 * (login_entry.php의 b21_data_type_for()와 같은 규칙).
 */
function inquiry_data_type_for(string $sqlType): string
{
    static $numericTypes = [
        'int', 'bigint', 'smallint', 'tinyint',
        'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney',
    ];
    return in_array(strtolower($sqlType), $numericTypes, true) ? 'number' : 'string';
}

/**
 * 상태 콤보의 원본자료 — Access의 Me.상태.RowSource =
 * "SELECT tb상태_문의.상태 FROM tb상태_문의"와 같고, 순서는 code 오름차순.
 * 화면이 뜰 때 한 번 읽어 오른쪽 위 상태 콤보(#iq-status)에 넣는다. 상태는
 * t문의에 글자 그대로 저장되므로 값과 표시가 같다. 레코드 그대로 넣으므로
 * 상태가 빈(NULL/'') 레코드도 빈 항목으로 들어간다 — 빈 항목을 고르면
 * 상태 조건 없이 전체를 조회한다. 테이블이 없거나 읽기에 실패해도
 * 화면은 뜨고 콤보만 비게 한다($statusError에 원인을 남긴다).
 */
$statusOptions = [];
$statusError = '';
try {
    $statusStmt = $inquiryGridDb->pdo()->query('SELECT tb상태_문의.상태 FROM tb상태_문의 ORDER BY tb상태_문의.code ASC');
    foreach ($statusStmt->fetchAll(PDO::FETCH_COLUMN) as $status) {
        $statusOptions[] = $status === null ? '' : (string) $status;
    }
} catch (Throwable $e) {
    $statusError = $e->getMessage();
}

$columns = [];
foreach ($inquiryFields as $field) {
    if (!isset($fieldSchema[$field])) {
        continue;
    }
    $column = [
        'field' => $field,
        'headerName' => $field,
        'editable' => $field !== $primaryKey,
        'dataType' => inquiry_data_type_for($fieldSchema[$field]['type'] ?? ''),
    ];
    if ($field === '상태') {
        $column['align'] = 'center';
    }
    $columns[] = $column;
}

$fetchUrl = APP_BASE_URL . '/workspace/inquiry/inquiry_fetch.php';

// 브라우저 캐시로 옛 AGGrid.js가 보이지 않게 수정시각을 쿼리스트링에 붙인다.
$aggridJsPath = APP_BASE_DIR . '/src/js/aggrid/AGGrid.js';
$aggridJsVersion = is_file($aggridJsPath) ? filemtime($aggridJsPath) : time();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>문의</title>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
<style>
html, body { margin: 0; height: 100%; }
body { padding: 16px; box-sizing: border-box; font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif; }

/* 검색 도구 묶음 — 문의.png의 신규추가/콤보/입력칸 자리. 그리드 툴바 오른쪽,
   "문의" 제목과 같은 줄에 들어간다(onToolbarRendered). */
.iq-bar { display: flex; justify-content: flex-end; align-items: center; gap: 6px; }
.iq-bar button, .iq-bar select, .iq-bar input {
    font: inherit; font-size: 0.82rem; height: 28px; box-sizing: border-box;
    border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #1e293b;
}
.iq-bar button { padding: 0 12px; cursor: pointer; }
.iq-bar button:hover { background: #f3f4f6; }
.iq-bar .iq-add-btn { background: #bfdbfe; border-color: #bfdbfe; }
.iq-bar .iq-add-btn:hover { background: #93c5fd; }
.iq-bar select { padding: 0 6px; min-width: 120px; }
.iq-bar input { padding: 0 8px; width: 220px; }
.iq-bar select:focus, .iq-bar input:focus { outline: none; border-color: #6b7280; }

/* 그리드 툴바 맨 앞 제목("편집" 버튼 자리) — 왼쪽 0.5cm 띄우고, 왼쪽 세로 막대와
   진한 남색 굵은 글씨로 제목처럼 보이게 한다. */
.iq-title {
    margin-left: 0.5cm; margin-right: 12px; padding-left: 8px;
    border-left: 4px solid #3b82f6;
    font-family: 'Pretendard', 'Malgun Gothic', 'Segoe UI', sans-serif;
    font-size: 1.15rem; font-weight: 700; letter-spacing: 0.08em; line-height: 1.2;
    color: #1e3a8a;
}
</style>
</head>
<body>

<div class="iq-bar">
    <button type="button" class="iq-add-btn" id="iq-add">+ 신규추가</button>
    <!-- 상태 콤보 — 원본자료는 tb상태_문의.상태(화면이 뜰 때 $statusOptions로 읽음).
         처음엔 아무것도 고르지 않은 빈 상태로 둔다(아래 스크립트의 selectedIndex = -1).
         autocomplete="off" — 새로고침 때 브라우저가 직전 선택을 되살려 빈 칸이
         풀리지 않게 한다. -->
    <select id="iq-status" aria-label="상태" autocomplete="off">
<?php foreach ($statusOptions as $status): ?>
        <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
    </select>
    <input type="text" id="iq-keyword" placeholder="검색어" aria-label="검색어" />
    <button type="button" id="iq-search">검색</button>
</div>

<div id="aggrid-문의"></div>
<script>
// 검색 도구 묶음 — 그리드가 준비되면 onToolbarRendered가 툴바 오른쪽으로 옮긴다.
var IQ_BAR = document.querySelector('.iq-bar');
<?php if ($statusError !== ''): ?>
console.warn('tb상태_문의를 읽지 못해 상태 콤보가 비어 있습니다:', <?= json_encode($statusError, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>);
<?php endif; ?>

new AGGrid({
    gridId: '문의',
    fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    primaryKey: <?= json_encode($primaryKey, JSON_UNESCAPED_UNICODE) ?>,
    // baseline(1_9) 값 그대로.
    fetchMode: 'paged',
    pageSize: 100,
    autoFitBottomGap: 40,
    // 검색은 위 .iq-bar의 콤보/입력칸으로만 한다.
    showSearch: false,
    // 행 더블클릭 → selectRecord(그 행의 t문의.code). AGGrid는 읽기전용(view)
    // 모드에서만 이 콜백을 부른다(편집모드 더블클릭은 셀 편집).
    onRowDblClick: function (values) {
        selectRecord(values.code);
    },
    // 목록 응답의 where = 이 rows를 실제로 고른 조건. search() 직후 첫 응답에서만 보여준다.
    onDataLoaded: function (data) {
        if (!inquiryAlertWhere) {
            return;
        }
        inquiryAlertWhere = false;
        // 검색 확인용 — 필요할 때 주석을 풀어 쓴다.
        // alert('WHERE ' + (data.where ? data.where : '(조건 없음)'));
    },
    // 툴바 맨 앞의 "편집" 버튼을 빼고 그 자리에 "문의" 제목을 둔다. 툴바는
    // 모드가 바뀔 때마다 다시 그려지므로 onReady가 아니라 이 콜백에서 한다.
    // 목록은 보기 전용이라 편집모드로 들어갈 길이 없다. (편집모드일 때의
    // "잠금" 버튼은 지우지 않고 그대로 둔다.)
    onToolbarRendered: function (grid) {
        var left = document.querySelector('#' + CSS.escape(grid.toolbarId) + ' .ag-shell-toolbar-left');
        if (!left) {
            return;
        }
        if (grid.mode !== 'edit') {
            var modeBtn = left.querySelector('.ag-shell-mode-btn');
            if (modeBtn) {
                modeBtn.remove();
            }
        }
        var title = document.createElement('span');
        title.className = 'iq-title';
        title.textContent = '문의';
        left.insertBefore(title, left.firstChild);

        // 검색 도구(.iq-bar)를 "문의"와 같은 줄(툴바 오른쪽)로 옮긴다. 툴바를
        // 다시 그리면 innerHTML째 바뀌어 빠지므로 매번 다시 붙인다 — 요소
        // 자체(IQ_BAR)는 그대로라 콤보 선택값/입력값/이벤트가 유지된다.
        var right = document.querySelector('#' + CSS.escape(grid.toolbarId) + ' .ag-shell-toolbar-right');
        if (right) {
            right.insertBefore(IQ_BAR, right.firstChild);
        }
    },
    columns: (function (columns) {
        // code 계열(int)은 번호라 천단위 콤마를 붙이지 않는다(정렬은 오른쪽 그대로).
        columns.forEach(function (col) {
            if (/code$/i.test(col.field)) {
                col.valueFormatter = function (params) { return params.value == null ? '' : params.value; };
            }
        });
        return columns;
    })(<?= json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>)
});

// search()가 켜고, 다음 목록 응답(onDataLoaded)이 WHERE를 alert한 뒤 끈다.
var inquiryAlertWhere = false;

function inquiryGrid() {
    return AGGrid.instance('문의');
}

/**
 * search(상태, 검색어) — 인자를 주면 콤보/입력칸을 그 값으로 바꾼 뒤,
 * 콤보/입력칸 값으로 검색한다.
 *  - 상태: 값이 있으면 t문의.상태 = 그 값. ''(또는 콤보에 없는 값)면 조건 없음.
 *  - 검색어: 그리드의 모든 컬럼에 LIKE '%검색어%'(OR).
 *  - 둘 다 있으면 AND.
 * 그리드를 이 조건으로 다시 조회하고, 그 목록 응답에 서버가 실어 보낸 WHERE
 * (화면에 보이는 행을 실제로 고른 조건)를 alert로 보여준다(onDataLoaded).
 */
function search(pstatus, pkeyword) {
    var statusEl = document.getElementById('iq-status');
    var keywordEl = document.getElementById('iq-keyword');
    if (pstatus !== undefined) {
        // 콤보에 없는 값이면 브라우저가 selectedIndex를 -1(아무것도 안 고름)로
        // 두므로 value는 ''가 되어 전체 조회가 된다.
        statusEl.value = pstatus == null ? '' : String(pstatus);
    }
    if (pkeyword !== undefined) {
        keywordEl.value = pkeyword == null ? '' : String(pkeyword);
    }
    var grid = inquiryGrid();
    if (!grid || !grid.gridApi) {
        return;
    }
    grid.searchKeyword = keywordEl.value.trim();
    // 이번 검색의 첫 응답에서만 WHERE를 alert한다(스크롤로 다음 페이지를
    // 받을 때는 안 띄움) — onDataLoaded 참고.
    inquiryAlertWhere = true;
    // setExtraQuery()가 requery()까지 한다.
    grid.setExtraQuery(statusEl.value === '' ? {} : { st: statusEl.value });
}

/** requery() — 지금 검색 조건 그대로 다시 불러온다. */
function requery() {
    var grid = inquiryGrid();
    if (!grid || !grid.gridApi) {
        return;
    }
    grid.requery();
}

// selectRecord()로 마지막에 고른 레코드({code, values}). 없으면 null.
var inquiryCurrentRecord = null;

/**
 * 문의확인 화면(inquiryContent_entry.php)은 index 탭 하나(탭키 "문의확인")를
 * 돌려쓴다 — setContent(주소, 제목, 탭키)는 같은 탭키의 탭이 있으면 새로 만들지
 * 않고 그 탭의 주소와 제목만 바꾼다. 제목은 "문의 (code)" / "문의 (new)".
 * 그 탭 입력폼에 저장 안 한 값이 있으면 바꾸기 전에 묻는다(취소하면 false).
 * index 밖에서 열었으면 이 창을 그 화면으로 바꾼다.
 */
var INQUIRY_CONTENT_TAB = '문의확인';

function openInquiryContent(pcode) {
    var url = 'workspace/inquiry/inquiryContent_entry.php?code=' + encodeURIComponent(pcode);
    var shell = window.parent;
    if (shell === window || typeof shell.setContent !== 'function') {
        location.href = 'inquiryContent_entry.php?code=' + encodeURIComponent(pcode);
        return true;
    }
    // 이미 열린 문의확인 탭(IC_CODE를 가진 iframe)에 저장 안 한 값이 있는지 본다.
    var frames = shell.document.querySelectorAll('iframe[data-tab-iframe]');
    for (var i = 0; i < frames.length; i++) {
        try {
            var win = frames[i].contentWindow;
            if (win && typeof win.icFormDirty === 'function' && win.icFormDirty()
                && !confirm('문의확인 화면에 저장하지 않은 내용이 있습니다. 버리고 다른 문의를 열까요?')) {
                return false;
            }
        } catch (e) {
            // 다른 출처 iframe 등은 넘어간다.
        }
    }
    shell.setContent(url, '문의 (' + pcode + ')', INQUIRY_CONTENT_TAB);
    return true;
}

/**
 * selectRecord(code) — t문의.code로 레코드 하나를 고른다(행 더블클릭 시 호출).
 * 그 행을 현재 행으로 표시해 inquiryCurrentRecord({code, values})에 담고,
 * 문의확인 화면을 "문의확인" 탭 하나에 그 code로 연다(openInquiryContent).
 * 행 값은 지금 불러와 있는 행에서 찾는다 — paged라 아직 안 불러온 페이지의
 * 행이면 values는 null.
 */
function selectRecord(pcode) {
    var grid = inquiryGrid();
    if (!grid || !grid.gridApi || pcode == null || pcode === '') {
        return null;
    }
    // alert('selectRecord — t문의.code = ' + pcode);

    var values = null;
    grid.gridApi.forEachNode(function (node) {
        if (!values && node.data && String(node.data.code) === String(pcode)) {
            values = node.data;
        }
    });
    grid.selectRow(pcode);
    inquiryCurrentRecord = { code: pcode, values: values };

    openInquiryContent(pcode);
    return inquiryCurrentRecord;
}

/**
 * 신규추가 — 문의확인 화면을 신규(code=new)로 "문의확인" 탭에 연다. 입력폼/탭/
 * talk이 모두 빈 칸이고, 거기서 저장하면 t문의에 insert된 뒤 같은 탭이 새 code로
 * 다시 열린다(inquiryContent_entry.php의 icOpenSaved).
 */
function addNew() {
    openInquiryContent('new');
}

/**
 * action(pcmd, pparams, psender) — index(셸)가 iframe.contentWindow.action으로
 * 부르는 창구. 'requery'는 탭을 옮길 때 불리므로, 저장 안 한 편집이
 * 있으면 건너뛴다.
 */
function action(pcmd, pparams, psender) {
    pparams = pparams || {};
    switch (pcmd) {
        case 'requery':
            var grid = inquiryGrid();
            if (grid && grid.gridApi && grid._dirtyCount() > 0) {
                return;
            }
            return requery();
        case 'search':
            return search(pparams.pstatus, pparams.pkeyword);
        case 'selectRecord':
            return selectRecord(pparams.pcode);
        default:
            console.warn('문의.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

// 상태 콤보는 처음에 아무것도 고르지 않은 빈 칸으로 보인다(=전체 조회).
document.getElementById('iq-status').selectedIndex = -1;

document.getElementById('iq-add').addEventListener('click', addNew);
document.getElementById('iq-search').addEventListener('click', function () { search(); });
// 상태 콤보를 고르면 바로 검색한다(검색어 칸 값과 AND).
document.getElementById('iq-status').addEventListener('change', function () { search(); });

// 콤보/검색어 칸에 커서가 들어가면(클릭·Tab) 값을 비우고 바로 search()한다
// (나머지 하나의 값은 그대로 조건에 남는다). 이미 비어 있으면 바뀐 게 없으니
// 조회하지 않는다. 콤보는 선택 없음(selectedIndex -1)으로 되돌린다.
document.getElementById('iq-status').addEventListener('focus', function () {
    if (this.selectedIndex === -1 || this.value === '') {
        this.selectedIndex = -1;
        return;
    }
    this.selectedIndex = -1;
    search();
});
document.getElementById('iq-keyword').addEventListener('focus', function () {
    if (this.value === '') {
        return;
    }
    this.value = '';
    search();
});
document.getElementById('iq-keyword').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.isComposing) {
        search();
    }
});
</script>
</body>
</html>
