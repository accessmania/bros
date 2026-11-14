<?php
declare(strict_types=1);

/**
 * [송금(v송금관리) AGGrid 화면 — 메뉴 "송금 > 송금전체 / 원화송금관리 / 외화송금관리"]
 * 구성: inquiry_송금_entry.php(이 파일) + inquiry_송금_fetch.php(조회 전용).
 * 메뉴 세 개가 이 화면 하나를 ?g=로 나눠 쓴다(Access grouptype2와 같은 자리):
 *    ?g=전체 — "송금전체"      (grouptype2 없음)
 *    ?g=원화 — "원화송금관리"  (grouptype2 = 2)
 *    ?g=외화 — "외화송금관리"  (grouptype2 = 3)
 *    ?g=B전체 / B원화 / B외화 — "송금B전체" / "원화송금관리B" / "외화송금관리B"
 *      (송금B 메뉴 — 데이터·조건은 위 셋과 같고 메뉴 권한 button송금B만 다르다)
 *  g에 따라 바뀌는 것은 제목(= 메뉴 제목, $screenTitle)과 서버의 추가 조건(fetch의 $groupConditions)뿐이다.
 *  모르는 g는 '전체'로 본다. 조건 SQL은 fetch에 고정돼 있고 g는 그중 하나를 고르는 이름일 뿐이다.
 * 문의내용 목록(inquiry_문의내용_entry.php)과 같은 모양·동작이다:
 *  - 원본은 뷰 v송금관리. Access의 mSP.create("sp송금관리", "select * from v송금관리", True)
 *    → 검색 → 데이터시트와 같은 자리. parentcode = t문의.code, 문의code = t문의내역.code.
 *  - 컬럼은 $purchaseFields 순서로 고정. 뷰에 없는 필드는 빼고 콘솔에 경고한다.
 *  - 오른쪽 위에 신규추가 / 상태 콤보 / 검색어 / 검색 버튼. 상태 콤보의 원본자료는
 *    tb상태_발주.상태. 검색어는 ?q=(표시 필드 전체), 상태는 ?st=.
 *  - 송금B(?g=B전체|B원화|B외화)는 위·아래 두 그리드다(바탕화면 송금b.png, $showBottom):
 *      위 = 아래 설명의 목록. 더블클릭하면 그 행의 문의(parentcode)에 딸린 발주 행 전부를 아래에 불러온다
 *           (iqrLoadBottom — Access f문의_송금_bound_ds / q문의_송금_bound, inquiry_송금_하단_fetch.php).
 *      아래 = 조회 전용. "자세히보기"(또는 아래 행 더블클릭)는 고른 행의 발주 amd를 띄운다 — 아래의
 *           송금 목록 더블클릭과 같은 탭 + 팝업 길(iqrOpenBottomDetail → iqrOpenDetail).
 *    송금(전체/원화/외화)은 아래 영역 없이 지금처럼 위 목록만 있다.
 *  - 보기 전용. 행 더블클릭 → "<제목>확인" 탭(예: "원화송금관리확인" — 다른 목록에서 연 탭과 따로,
 *    제목 "<제목> (t문의.code)")에 그 행의 문의(parentcode)를 문의확인 화면으로 열고, 그 발주(code)
 *    amd를 팝업으로 띄운다. 다른 행을 더블클릭하면 같은 탭을 덮어쓴다(iqrOpenDetail →
 *    inquiryContent_entry.php의 목록 모드 ?open=<제목> / action('openInquiryDetail')).
 *  - 신규추가 → 문의확인을 신규(new)로 연다(문의 목록과 같은 "문의확인" 탭).
 *  - 필드감추기: nickname '발주' — Access applyDs("발주", ...)와 같다(발주 행이므로). src/Auth/FieldVisible.md.
 *  - 조회 조건: 고정 조건(원화송금액 > 0 또는 USD송금액 > 0 또는 송금일 없음 — Access vaddwhere)이
 *    늘 붙고, 원화/외화면 그 그룹 조건이 더 붙는다. inquiry_송금_fetch.php의 $fixedCondition / $groupConditions.
 *  - v송금관리의 행은 발주(t발주내역) 행이다 — 상태 콤보(tb상태_발주)·컬럼·팝업이 발주와 같다.
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
$viewName = 'v송금관리';

// 그룹(?g=) → 화면 제목(= 메뉴 제목). 여기 없는 값은 '전체'. fetch의 $groupConditions와 키가 같아야 한다.
// 송금B 메뉴 3개(B전체/B원화/B외화)는 데이터·조건이 송금과 같고 메뉴 권한(button송금B)만 다르다.
$screenTitles = [
    '전체'  => '송금전체',
    '원화'  => '원화송금관리',
    '외화'  => '외화송금관리',
    'B전체' => '송금B전체',
    'B원화' => '원화송금관리B',
    'B외화' => '외화송금관리B',
];
$group = isset($_GET['g']) ? trim((string) $_GET['g']) : '전체';
if (!isset($screenTitles[$group])) {
    $group = '전체';
}
$screenTitle = $screenTitles[$group];

// 송금B 메뉴(B전체/B원화/B외화)는 위·아래 두 그리드로 나눈다(바탕화면 송금b.png):
//  위 = 지금과 같은 목록, 아래 = 위에서 더블클릭한 행의 문의(parentcode)에 딸린 발주 행 전부
//  (Access f문의_송금_bound_ds / q문의_송금_bound — inquiry_송금_하단_fetch.php).
$splitGroups = ['B전체', 'B원화', 'B외화'];
$showBottom = in_array($group, $splitGroups, true);

// 표시 필드 — 발주 목록과 같은 컬럼·순서(Access도 발주 데이터시트 f문의_발주내역_ds2를 쓴다). inquiry_송금_fetch.php의 $purchaseFields와 같게 둔다.
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

/**
 * 아래 그리드(송금B만) 컬럼 — 송금b.png 순서(문의일자까지), 그 뒤는 t발주내역의 나머지 컬럼을
 * 스키마 순서대로. 고객사·문의일자는 t문의의 값(inquiry_송금_하단_fetch.php와 같은 규칙).
 * 바이너리 컬럼(ts 등)은 뺀다. 컬럼이 없으면 빼고 콘솔에 경고한다.
 */
$bottomColumns = [];
$bottomMissing = [];
if ($showBottom) {
    $bottomFirst = [
        '고객사', '상태', '송금일', '송금예정일', 'USD할인금액', '할인금액', '원화송금액', 'USD송금액',
        '송금메모', '원화매입가', '매입총액', '송금잔액', '외화매입총액', '외화매입가', '외화송금잔액', '수량',
        '문의일자',
    ];
    $bottomProbe = new Crud($purchaseDb);
    $bottomProbe->init('t발주내역');
    $bottomSchema = $bottomProbe->getSchema()['fields'];
    $binaryTypes = ['timestamp', 'rowversion', 'binary', 'varbinary', 'image'];

    // 고객사·문의일자는 t문의에서 오므로 t발주내역 스키마에 없어도 있는 것으로 본다.
    $bottomOrder = [];
    foreach ($bottomFirst as $field) {
        if ($field === '고객사' || $field === '문의일자' || isset($bottomSchema[$field])) {
            $bottomOrder[] = $field;
        } else {
            $bottomMissing[] = $field;
        }
    }
    foreach ($bottomSchema as $field => $info) {
        if (in_array($field, $bottomOrder, true)) {
            continue;
        }
        if (in_array(strtolower((string) ($info['type'] ?? '')), $binaryTypes, true)) {
            continue;
        }
        $bottomOrder[] = $field;
    }

    foreach ($bottomOrder as $field) {
        $dataType = 'string';
        if (isset($bottomSchema[$field]) && $field !== '고객사' && $field !== '문의일자'
            && in_array(strtolower((string) ($bottomSchema[$field]['type'] ?? '')), $numericTypes, true)) {
            $dataType = 'number';
        }
        $bottomColumns[] = [
            'field' => $field,
            'headerName' => $field,
            'editable' => false,
            'dataType' => $dataType,
        ];
    }
}
$bottomFetchUrl = APP_BASE_URL . '/workspace/inquiry/' . rawurlencode('inquiry_송금_하단_fetch.php');

// fetch에도 같은 그룹을 넘긴다 — 서버가 그 그룹의 고정 조건을 붙인다.
$fetchUrl = APP_BASE_URL . '/workspace/inquiry/' . rawurlencode('inquiry_송금_fetch.php') . '?g=' . rawurlencode($group);

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
<title><?= htmlspecialchars($screenTitle, ENT_QUOTES, 'UTF-8') ?></title>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
<script src="<?= APP_BASE_URL ?>/src/Auth/FieldVisible.js?v=<?= $fieldVisibleJsVersion ?>"></script>
<style>
html, body { margin: 0; height: 100%; }
body { padding: 16px; box-sizing: border-box; font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif; }

/* 검색 도구 묶음 — 그리드 툴바 오른쪽, 화면 제목과 같은 줄(onToolbarRendered). 문의 화면과 같은 모양. */
.iqr-bar { display: flex; justify-content: flex-end; align-items: center; gap: 6px; }
.iqr-bar button, .iqr-bar select, .iqr-bar input {
    font: inherit; font-size: 0.82rem; height: 28px; box-sizing: border-box;
    border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #1e293b;
}
.iqr-bar button { padding: 0 12px; cursor: pointer; }
.iqr-bar button:hover { background: #f3f4f6; }
.iqr-bar .iqr-add-btn { background: #bfdbfe; border-color: #bfdbfe; }
.iqr-bar .iqr-add-btn:hover { background: #93c5fd; }
.iqr-bar select { padding: 0 6px; min-width: 120px; }
.iqr-bar input { padding: 0 8px; width: 220px; }
.iqr-bar select:focus, .iqr-bar input:focus { outline: none; border-color: #6b7280; }

/* 송금B 아래 영역 — 자세히보기 버튼줄 + 아래 그리드(툴바는 숨긴다). */
.iqr-bottom { margin-top: 12px; }
.iqr-bottom-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
.iqr-bottom-bar button {
    font: inherit; font-size: 0.82rem; height: 28px; padding: 0 12px; cursor: pointer;
    border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #1e293b;
}
.iqr-bottom-bar button:hover { background: #f3f4f6; }
.iqr-bottom-bar .iqr-detail-mark { color: #dc2626; font-weight: 700; }
.iqr-bottom-caption { font-size: 0.82rem; color: #64748b; }
#aggrid-송금하단 .ag-shell-toolbar { display: none !important; }

.iqr-title {
    margin-left: 0.5cm; margin-right: 12px; padding-left: 8px;
    border-left: 4px solid #3b82f6;
    font-family: 'Pretendard', 'Malgun Gothic', 'Segoe UI', sans-serif;
    font-size: 1.15rem; font-weight: 700; letter-spacing: 0.08em; line-height: 1.2;
    color: #1e3a8a;
}
</style>
</head>
<body>

<div class="iqr-bar">
    <button type="button" class="iqr-add-btn" id="iqr-add">+ 신규추가</button>
    <!-- 상태 콤보 — 원본자료는 tb상태_발주.상태. 처음엔 아무것도 고르지 않은 빈 상태. -->
    <select id="iqr-status" aria-label="상태" autocomplete="off">
        <!-- 맨 위 공란 — 고르면 상태 조건 없이 전체를 조회한다. 상태 표에 빈 값이 있어도 공란은 이것 하나만 둔다. -->
        <option value=""></option>
<?php foreach ($statusOptions as $status): ?>
<?php if ($status === '') { continue; } ?>
        <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
    </select>
    <input type="text" id="iqr-keyword" placeholder="검색어" aria-label="검색어" />
    <button type="button" id="iqr-search">검색</button>
</div>

<div id="aggrid-송금목록"></div>
<?php if ($showBottom): ?>
<!-- 송금B 아래 영역 — 위에서 더블클릭한 문의의 발주 행들. 자세히보기 = 고른 행의 발주 amd. -->
<div class="iqr-bottom">
    <div class="iqr-bottom-bar">
        <button type="button" id="iqr-detail"><span class="iqr-detail-mark">!</span> 자세히보기</button>
        <span class="iqr-bottom-caption" id="iqr-bottom-caption">위 목록에서 행을 더블클릭하면 그 문의의 발주가 여기 나옵니다.</span>
    </div>
    <div id="aggrid-송금하단"></div>
</div>
<?php endif; ?>
<script>
// 화면 제목 = 메뉴 제목('송금전체' / '원화송금관리' / '외화송금관리' — PHP의 $screenTitles).
// 탭 키·탭 제목·문의확인의 목록 모드 종류(?open=)에 그대로 쓴다.
var IQR_TITLE = <?= json_encode($screenTitle, JSON_UNESCAPED_UNICODE) ?>;
var IQR_GRID_ID = '송금목록';
var IQR_BAR = document.querySelector('.iqr-bar');
// 신규추가는 문의 목록(inquiry_문의_entry.php)과 같은 "문의확인" 탭을 돌려쓴다.
var IQR_CONTENT_TAB = '문의확인';
// 행 더블클릭은 따로 "<제목>확인" 탭 하나를 돌려쓴다(문의확인 화면의 목록 모드, ?open=<제목>&detail=).
var IQR_DETAIL_TAB = IQR_TITLE + '확인';

// 필드감추기 — {success, error, recs}. 이 화면의 nickname은 '발주'.
var IQR_FIELDVISIBLE = <?= json_encode($fieldVisible, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
var IQR_NICKNAME = '발주';

// 송금B(위·아래 두 그리드)인지 — PHP의 $showBottom. 아니면 아래 영역이 없다.
var IQR_SHOW_BOTTOM = <?= $showBottom ? 'true' : 'false' ?>;
var IQR_BOTTOM_GRID_ID = '송금하단';
var IQR_BOTTOM_FETCH_URL = <?= json_encode($bottomFetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
// 아래 그리드가 지금 보여주는 문의 code(위에서 더블클릭한 행의 parentcode). 없으면 ''.
var iqrBottomParent = '';

<?php if ($statusError !== ''): ?>
console.warn('tb상태_발주을 읽지 못해 상태 콤보가 비어 있습니다: ' + <?= json_encode($statusError, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>);
<?php endif; ?>
<?php if (count($missingFields) > 0): ?>
console.warn('v송금관리에 없는 필드라 뺐습니다: ' + <?= json_encode(implode(', ', $missingFields), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>);
<?php endif; ?>

// 위 그리드 — 송금B면 높이를 화면의 절반쯤으로 고정하고(아래 그리드 자리), 아니면 바닥까지 채운다.
var iqrTopOptions = {
    gridId: IQR_GRID_ID,
    fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    primaryKey: <?= json_encode($primaryKey, JSON_UNESCAPED_UNICODE) ?>,
    fetchMode: 'paged',
    pageSize: 100,
    showSearch: false,
    showAddRow: false,
    showDeleteColumn: false,
    onReady: function (pgrid) {
        Applyds(pgrid, IQR_NICKNAME, IQR_FIELDVISIBLE);
    },
    onRowDblClick: function (pvalues) {
        iqrTopDblClick(pvalues);
    },
    onToolbarRendered: function (pgrid) {
        iqrDecorateToolbar(pgrid);
    },
    columns: iqrPrepareColumns(<?= json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>)
};
if (IQR_SHOW_BOTTOM) {
    iqrTopOptions.height = '42vh';
} else {
    iqrTopOptions.autoFitBottomGap = 40;
}
new AGGrid(iqrTopOptions);

// 아래 그리드(송금B만) — 처음엔 빈 목록(?code 없음). 위에서 더블클릭하면 iqrLoadBottom이 채운다.
if (IQR_SHOW_BOTTOM) {
<?php if (count($bottomMissing) > 0): ?>
    console.warn('t발주내역에 없는 필드라 아래 목록에서 뺐습니다: ' + <?= json_encode(implode(', ', $bottomMissing), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>);
<?php endif; ?>
    new AGGrid({
        gridId: IQR_BOTTOM_GRID_ID,
        fetchUrl: IQR_BOTTOM_FETCH_URL,
        primaryKey: 'code',
        fetchMode: 'all',
        autoFitBottomGap: 20,
        minHeight: 150,
        showSearch: false,
        showAddRow: false,
        showDeleteColumn: false,
        onRowDblClick: function (pvalues) {
            iqrOpenBottomDetail();
        },
        columns: iqrPrepareColumns(<?= json_encode($bottomColumns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>)
    });
}

/**
 * 위 그리드 더블클릭.
 *  - 송금B: 그 행의 문의(parentcode)에 딸린 발주 행들을 아래 그리드에 불러온다(iqrLoadBottom).
 *  - 송금(전체/원화/외화): 지금처럼 문의확인 탭 + 발주 amd 팝업(iqrOpenDetail).
 */
function iqrTopDblClick(pvalues) {
    if (IQR_SHOW_BOTTOM) {
        iqrLoadBottom(pvalues.parentcode);
        return;
    }
    iqrOpenDetail(pvalues.parentcode, pvalues.code);
}

/**
 * iqrLoadBottom(pparentcode) — 아래 그리드를 그 문의(t문의.code)의 발주 행들로 다시 읽는다.
 * Access: Me.txtcode = p문의code / RecordSource = "select * from q문의_송금_bound where parentcode = …".
 * AGGrid는 요청할 때마다 fetchUrl을 읽으므로 주소의 ?code=만 바꾸고 requery한다.
 */
function iqrLoadBottom(pparentcode) {
    var grid;
    var caption;

    if (pparentcode === null || pparentcode === undefined || pparentcode === '') {
        alert('이 행에는 문의(parentcode)가 없습니다.');
        return false;
    }
    grid = AGGrid.instance(IQR_BOTTOM_GRID_ID);
    if (!grid || !grid.gridApi) {
        return false;
    }
    iqrBottomParent = String(pparentcode);
    grid.fetchUrl = IQR_BOTTOM_FETCH_URL + '?code=' + encodeURIComponent(iqrBottomParent);
    grid.selectRow(null);
    grid.requery();

    caption = document.getElementById('iqr-bottom-caption');
    caption.textContent = '문의 ' + iqrBottomParent + '의 발주';
    return true;
}

/**
 * 자세히보기(또는 아래 그리드 더블클릭) — 아래에서 고른 행의 발주 amd를 띄운다.
 * 다른 송금 화면과 같은 길을 쓴다: "<제목>확인" 탭에 그 문의를 문의확인으로 열고 그 위에 발주 amd
 * 팝업(iqrOpenDetail → inquiryContent_entry.php 목록 모드). 그래서 amd의 수식·문의내역 연결 등이
 * 그대로 동작한다. 고른 행이 없으면 알려 주고 아무것도 안 한다.
 */
function iqrOpenBottomDetail() {
    var grid;

    grid = AGGrid.instance(IQR_BOTTOM_GRID_ID);
    if (!grid || grid.activeKey === null || grid.activeKey === '') {
        alert('아래 목록에서 발주 행을 먼저 고르세요.');
        return false;
    }
    return iqrOpenDetail(iqrBottomParent, grid.activeKey);
}

/** 아래 그리드를 지금 문의로 다시 읽는다(고른 행은 그대로 둔다). 아직 고른 문의가 없으면 안 한다. */
function iqrRequeryBottom() {
    var grid;

    if (!IQR_SHOW_BOTTOM || iqrBottomParent === '') {
        return;
    }
    grid = AGGrid.instance(IQR_BOTTOM_GRID_ID);
    if (!grid || !grid.gridApi) {
        return;
    }
    grid.requery();
}

/** code 계열(int)은 번호라 천단위 콤마를 붙이지 않는다. */
function iqrPrepareColumns(pcolumns) {
    var i;

    for (i = 0; i < pcolumns.length; i++) {
        if (/code$/i.test(pcolumns[i].field)) {
            pcolumns[i].valueFormatter = iqrPlainFormatter;
        }
    }
    return pcolumns;
}

function iqrPlainFormatter(pparams) {
    if (pparams.value === null || pparams.value === undefined) {
        return '';
    }
    return pparams.value;
}

/**
 * 툴바 맨 앞 "편집" 버튼을 빼고 화면 제목(IQR_TITLE)을 두고, 검색 도구(.iqr-bar)를
 * 툴바 오른쪽으로 옮긴다. 툴바는 다시 그려질 때마다 innerHTML째 바뀌므로 매번 한다.
 */
function iqrDecorateToolbar(pgrid) {
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
    title.className = 'iqr-title';
    title.textContent = IQR_TITLE;
    left.insertBefore(title, left.firstChild);

    right = document.querySelector('#' + CSS.escape(pgrid.toolbarId) + ' .ag-shell-toolbar-right');
    if (right) {
        right.insertBefore(IQR_BAR, right.firstChild);
    }
}

function iqrGrid() {
    return AGGrid.instance(IQR_GRID_ID);
}

/**
 * search(상태, 검색어) — 인자를 주면 콤보/입력칸을 그 값으로 바꾼 뒤 검색한다.
 * 상태 ''는 전체. 검색어는 표시 필드 전체에 LIKE. 둘 다 있으면 AND.
 */
function search(pstatus, pkeyword) {
    var statusEl;
    var keywordEl;
    var grid;

    statusEl = document.getElementById('iqr-status');
    keywordEl = document.getElementById('iqr-keyword');
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
    grid = iqrGrid();
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

/**
 * requery() — 지금 검색 조건 그대로 다시 불러온다. 송금B면 아래 그리드도 지금 문의로 다시 읽는다
 * (index가 탭을 옮길 때 부르므로, 자세히보기 탭에서 저장한 값이 돌아오면 보인다).
 */
function requery() {
    var grid;

    grid = iqrGrid();
    if (!grid || !grid.gridApi) {
        return;
    }
    grid.requery();
    iqrRequeryBottom();
}

/**
 * iqrOpenContent(pcode) — 신규추가. 문의확인 화면(inquiryContent_entry.php)을 "문의확인" 탭에
 * 신규('new')로 연다. 이미 떠 있으면 그 화면에 action('openInquiry', {code})만 보낸다(다시 열지 않음).
 * inquiry_문의_entry.php의 openInquiryContent와 같은 일.
 */
async function iqrOpenContent(pcode) {
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

    win = iqrFindContentWindow(shell, IQR_CONTENT_TAB);
    if (win === null) {
        shell.setContent(url, title, IQR_CONTENT_TAB);
        return true;
    }

    result = await win.action('openInquiry', { code: pcode }, window);
    if (result === 'unsupported') {
        shell.setContent(url, title, IQR_CONTENT_TAB);
    }
    return true;
}

/**
 * iqrOpenDetail(pparentcode, pcode) — 행 더블클릭. "<제목>확인" 탭(IQR_DETAIL_TAB — 다른 목록에서 연 탭과 따로)에
 * 그 행의 문의(pparentcode)를 문의확인 화면으로 열고, 그 위에 발주(pcode) amd를 팝업으로 띄운다.
 * 탭 제목은 "<제목> (pparentcode)" — 괄호 안은 항상 t문의.code.
 *  - 탭이 이미 떠 있으면 다시 열지 않고 action('openInquiryDetail', {code, detail})로 덮어쓴다.
 *  - 없거나 아직 준비 전('unsupported')이면 setContent로 새로 연다(?code=&open=<제목>&detail=).
 */
async function iqrOpenDetail(pparentcode, pcode) {
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
        + '&open=' + encodeURIComponent(IQR_TITLE) + '&detail=' + encodeURIComponent(pcode);
    url = 'workspace/inquiry/inquiryContent_entry.php' + query;
    // 탭 제목의 괄호 안은 항상 t문의.code(이 행의 parentcode).
    title = IQR_TITLE + ' (' + pparentcode + ')';
    shell = window.parent;

    if (shell === window || typeof shell.setContent !== 'function') {
        location.href = 'inquiryContent_entry.php' + query;
        return true;
    }

    win = iqrFindContentWindow(shell, IQR_DETAIL_TAB);
    if (win === null) {
        shell.setContent(url, title, IQR_DETAIL_TAB);
        return true;
    }

    result = await win.action('openInquiryDetail', { code: pparentcode, detail: pcode }, window);
    if (result === 'unsupported') {
        shell.setContent(url, title, IQR_DETAIL_TAB);
    }
    return true;
}

/** 셸에 떠 있는 문의확인 화면의 창(IC_TAB_KEY가 ptabKey이고 action이 있는 탭 iframe). 없으면 null. */
function iqrFindContentWindow(pshell, ptabKey) {
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
            console.warn(IQR_TITLE + '.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

// 상태 콤보는 처음에 아무것도 고르지 않은 빈 칸(=전체 조회).
document.getElementById('iqr-status').selectedIndex = -1;

document.getElementById('iqr-add').addEventListener('click', function () {
    iqrOpenContent('new');
});
if (IQR_SHOW_BOTTOM) {
    document.getElementById('iqr-detail').addEventListener('click', function () {
        iqrOpenBottomDetail();
    });
}
document.getElementById('iqr-search').addEventListener('click', function () {
    search();
});
document.getElementById('iqr-status').addEventListener('change', function () {
    search();
});
// 콤보/검색어 칸에 커서가 들어가면 값을 비우고 바로 검색한다(문의 화면과 같음).
document.getElementById('iqr-status').addEventListener('focus', function () {
    if (this.selectedIndex === -1 || this.value === '') {
        this.selectedIndex = -1;
        return;
    }
    this.selectedIndex = -1;
    search();
});
document.getElementById('iqr-keyword').addEventListener('focus', function () {
    if (this.value === '') {
        return;
    }
    this.value = '';
    search();
});
document.getElementById('iqr-keyword').addEventListener('keydown', function (pe) {
    if (pe.key === 'Enter' && !pe.isComposing) {
        search();
    }
});
</script>
</body>
</html>
