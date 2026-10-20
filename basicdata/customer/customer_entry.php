<?php
declare(strict_types=1);

/**
 * [고객사(tb고객사, MSSQL deco DB) AGGrid 화면 — 메뉴 "기초자료 > 고객사"]
 * 기초자료형(src/js/aggrid/aggrid.md "기초자료형") — name: customer,
 * tablename: tb고객사. 구성: customer_entry.php(이 파일, 화면 + 프론트 config)
 * + customer_fetch.php(CRUD). 표준 예시 basicdata/login/login_entry.php와
 * 같은 방식이되, 특수 컬럼(비밀번호/콤보/체크박스 등) 요청이 없어 전부
 * 일반 텍스트 컬럼이다.
 *
 * 컬럼은 하드코딩하지 않고 Crud::init()으로 스키마를 조회해 구성한다 —
 * PK와 필드명에 "code"가 들어간 컬럼은 columns에서 빼고, 각 컬럼에 SQL 타입으로 dataType을 준다
 * (customer_data_type_for()). "메모"는 AGGrid.js가 자동으로 맨 뒤로 보낸다.
 *
 * 2026-10-20 추가 — 행 더블클릭(잠금 모드) 시 팝업(src/js/Popup/Popup.js):
 * 위쪽에 저장/삭제/신규입력/창닫기 버튼과 고객사 필드 입력란(쓰기 가능),
 * 아래쪽에 담당자 그리드(tb고객사_직원목록,
 * parentcode = 고객사 키, 편집/추가/삭제 가능 — customer_staff_fetch.php에
 * ?parentcode=로 걸러 조회). cuOpenCustomerPopup() 참고.
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
$customerGridDb = new DbContext($connectionName);
$tableName = 'tb고객사';

$probe = new Crud($customerGridDb);
$probe->init($tableName);
$primaryKey = $probe->getPrimaryKey();
$fieldSchema = $probe->getSchema()['fields'];

/**
 * SQL DATA_TYPE → AGGrid.js의 dataType('string'|'number') 힌트
 * (login_entry.php의 b21_data_type_for()와 같은 규칙). 값 기준 추측으로
 * 문자 필드에 콤마/우측정렬이 붙는 오탐을 막는다.
 */
function customer_data_type_for(string $sqlType): string
{
    static $numericTypes = [
        'int', 'bigint', 'smallint', 'tinyint',
        'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney',
    ];
    return in_array(strtolower($sqlType), $numericTypes, true) ? 'number' : 'string';
}

$columns = [];
foreach ($fieldSchema as $field => $info) {
    // PK와 필드명에 "code"가 들어간 컬럼(대소문자 무관)은 표시하지 않는다
    // (기초자료형 규칙). 값은 행 데이터에 그대로 있어 저장/삭제엔 지장 없다.
    // PK, "code"가 들어간 필드, ts(rowversion — 이진값이라 표시 불가)는 숨긴다.
    if ($field === $primaryKey || stripos($field, 'code') !== false || $field === 'ts') {
        continue;
    }
    $columns[] = [
        'field' => $field,
        'headerName' => $field,
        'editable' => true,
        'dataType' => customer_data_type_for($info['type'] ?? ''),
    ];
}

$fetchUrl = APP_BASE_URL . '/basicdata/customer/customer_fetch.php';

// --- 더블클릭 팝업 하단의 담당자 그리드(tb고객사_직원목록) ------------------
// 2026-10-20 추가: 고객사 행을 더블클릭하면 팝업에 그 고객사 필드(보기만)와
// 담당자 그리드(쓰기 가능)를 띄운다. 담당자는 parentcode = 고객사 PK로 묶인다.
// 담당자 컬럼도 기초자료형 규칙대로 스키마에서 만들고 PK/code 필드는 숨긴다
// (parentcode도 숨겨지며, 새 행엔 그리드가 newRowDefaults로 채운다).
$staffColumns = [];
$staffPrimaryKey = null;
$staffError = null;
try {
    $staffProbe = new Crud($customerGridDb);
    $staffProbe->init('tb고객사_직원목록');
    $staffPrimaryKey = $staffProbe->getPrimaryKey();
    foreach ($staffProbe->getSchema()['fields'] as $field => $info) {
        if ($field === $staffPrimaryKey || stripos($field, 'code') !== false || $field === 'ts') {
            continue;
        }
        $staffColumns[] = [
            'field' => $field,
            'headerName' => $field,
            'editable' => true,
            'dataType' => customer_data_type_for($info['type'] ?? ''),
        ];
    }
} catch (RuntimeException $e) {
    $staffError = 'tb고객사_직원목록 — ' . $e->getMessage();
}
$staffFetchUrl = APP_BASE_URL . '/basicdata/customer_staff/customer_staff_fetch.php';
$popupJsVersion = filemtime(APP_BASE_DIR . '/src/js/Popup/Popup.js');

// 브라우저 캐시로 옛 AGGrid.js가 보이지 않게 수정시각을 쿼리스트링에 붙인다.
$aggridJsPath = APP_BASE_DIR . '/src/js/aggrid/AGGrid.js';
$aggridJsVersion = is_file($aggridJsPath) ? filemtime($aggridJsPath) : time();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>고객사</title>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
<script src="<?= APP_BASE_URL ?>/src/js/Popup/Popup.js?v=<?= $popupJsVersion ?>"></script>
<style>
html, body { margin: 0; height: 100%; }
body { padding: 16px; box-sizing: border-box; font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif; }
h1 { font-size: 1.1rem; margin: 0 0 12px; }

/* 고객사 더블클릭 팝업 — 고객사 필드(입력) → 버튼 줄 → 담당자 그리드. */
.cu-popup { width: 900px; max-width: 90vw; font-size: 0.85rem; }
.cu-buttons { display: flex; gap: 6px; margin-bottom: 12px; }
.cu-buttons button {
    padding: 4px 12px; border: 1px solid #cbd5e1; border-radius: 4px;
    background: #f8fafc; color: #1f2937; font-size: 0.82rem; cursor: pointer;
}
.cu-buttons button:hover { background: #e2e8f0; }
.cu-buttons .cu-save-btn { background: #2563eb; border-color: #2563eb; color: #fff; font-weight: 600; }
.cu-buttons .cu-save-btn:hover { background: #1d4ed8; }
.cu-buttons .cu-delete-btn { background: #e0736c; border-color: #e0736c; color: #fff; }
.cu-buttons .cu-delete-btn:hover { background: #c85950; }
.cu-buttons .cu-close-btn { margin-left: auto; }
.cu-fields {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 6px 16px; margin-bottom: 14px;
}
.cu-field { display: flex; gap: 8px; align-items: center; }
.cu-field label { flex: 0 0 84px; color: #6b7280; }
.cu-field input {
    flex: 1; min-width: 0; padding: 4px 6px; border: 1px solid #d1d5db; border-radius: 4px;
    font: inherit; box-sizing: border-box;
}
.cu-message {
    padding: 10px; border: 1px solid #e5e7eb; border-radius: 4px; background: #f9fafb; color: #6b7280;
}
.cu-error { border-color: #fca5a5; background: #fef2f2; color: #b91c1c; }
</style>
</head>
<body>
<h1>고객사</h1>

<div id="aggrid-고객사"></div>
<script>
var CU_COLUMNS = <?= json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var CU_STAFF = {
    fetchUrl: <?= json_encode($staffFetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    primaryKey: <?= json_encode($staffPrimaryKey, JSON_UNESCAPED_UNICODE) ?>,
    columns: <?= json_encode($staffColumns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    error: <?= json_encode($staffError, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>
};

function cuEscape(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// --- 고객사 팝업 — 한 번에 하나(모달). key = 고객사 PK(신규면 '') -------------
var CU_FETCH_URL = <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
var CU_PRIMARY_KEY = <?= json_encode($primaryKey, JSON_UNESCAPED_UNICODE) ?>;
var cuState = { popup: null, staffGrid: null, key: '' };

function cuMainGrid() {
    return AGGrid.instance('고객사');
}

/** 담당자 그리드를 지금 고객사(cuState.key)로 다시 만든다. 신규(저장 전)면 안내만. */
function cuRenderStaff() {
    if (cuState.staffGrid) {
        cuState.staffGrid.destroy();
        cuState.staffGrid = null;
    }
    var holder = document.getElementById('cu-staff');
    if (CU_STAFF.error) {
        holder.innerHTML = '<div class="cu-message cu-error">담당자 테이블을 불러오지 못했습니다: ' + cuEscape(CU_STAFF.error) + '</div>';
        return;
    }
    if (!cuState.key) {
        holder.innerHTML = '<div class="cu-message">신규 고객사입니다. 저장하면 담당자를 입력할 수 있습니다.</div>';
        return;
    }
    holder.innerHTML = '<div id="aggrid-고객사담당자"></div>';
    cuState.staffGrid = new AGGrid({
        gridId: '고객사담당자',
        fetchUrl: CU_STAFF.fetchUrl + '?parentcode=' + encodeURIComponent(cuState.key),
        primaryKey: CU_STAFF.primaryKey,
        // 한 고객사의 담당자는 몇 명 안 돼 전체를 한 번에 받는다(페이저 없음).
        fetchMode: 'all',
        height: '240px',
        columns: CU_STAFF.columns,
        // 새 담당자는 이 고객사에 속하게 — parentcode는 화면에 안 보이는 컬럼.
        newRowDefaults: { parentcode: cuState.key }
    });
}

function cuFillForm(values) {
    CU_COLUMNS.forEach(function (col, i) {
        var v = values ? values[col.field] : '';
        document.getElementById('cu-f' + i).value = v == null ? '' : v;
    });
    cuState.popup.setCaption(values && values['회사명'] ? values['회사명'] : '새 고객사');
}

/**
 * 고객사 행 더블클릭 → 팝업(잠금 모드에서만 — AGGrid onRowDblClick 규칙).
 * 위: 저장/삭제/신규입력/창닫기 + 고객사 필드 입력란, 아래: 담당자 그리드
 * (parentcode = 고객사 키, 자체 툴바로 편집/추가/삭제/저장).
 */
function cuOpenCustomerPopup(values, key) {
    cuState.key = key != null ? String(key) : '';
    var fieldsHtml = CU_COLUMNS.map(function (col, i) {
        return '<div class="cu-field"><label for="cu-f' + i + '">' + cuEscape(col.headerName) + '</label>'
            + '<input type="text" id="cu-f' + i + '"></div>';
    }).join('');

    cuState.popup = new Popup({
        id: 'cu-customer-popup',
        title: '고객사',
        modal: true,
        draggable: true,
        // 배치: 고객사 필드 → 버튼 줄(예전 "담당자" 제목 자리) → 담당자 그리드.
        html: '<div class="cu-popup">'
            + '<div class="cu-fields">' + fieldsHtml + '</div>'
            + '<div class="cu-buttons">'
            + '<button type="button" class="cu-save-btn" id="cu-save-btn">저장</button>'
            + '<button type="button" class="cu-delete-btn" id="cu-delete-btn">삭제</button>'
            + '<button type="button" id="cu-new-btn">신규입력</button>'
            + '<button type="button" class="cu-close-btn" id="cu-close-btn">창닫기</button>'
            + '</div>'
            + '<div id="cu-staff"></div>'
            + '</div>',
        // 팝업을 닫으면 담당자 그리드도 정리한다(다음에 다시 열 때 새로 만든다).
        onClose: function () {
            if (cuState.staffGrid) {
                cuState.staffGrid.destroy();
                cuState.staffGrid = null;
            }
            cuState.popup = null;
        }
    });
    cuFillForm(values);
    cuRenderStaff();

    document.getElementById('cu-save-btn').addEventListener('click', cuSave);
    document.getElementById('cu-delete-btn').addEventListener('click', cuDelete);
    document.getElementById('cu-new-btn').addEventListener('click', cuNew);
    document.getElementById('cu-close-btn').addEventListener('click', function () { cuState.popup.close(); });
}

function cuPost(action, body) {
    return fetch(CU_FETCH_URL + '?action=' + action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(body)
    }).then(function (res) { return res.json(); });
}

/** 저장 — 입력란의 고객사 필드를 저장하고, 담당자 그리드에 저장 안 한 편집이 있으면 같이 저장한다. */
function cuSave() {
    var row = {};
    CU_COLUMNS.forEach(function (col, i) {
        var v = document.getElementById('cu-f' + i).value;
        // 숫자 컬럼의 빈 값은 ''가 아니라 null로 보낸다(DB 형변환 오류 방지).
        row[col.field] = (v.trim() === '' && col.dataType === 'number') ? null : v;
    });
    if (cuState.key) {
        row[CU_PRIMARY_KEY] = cuState.key;
    }
    var wasNew = !cuState.key;

    cuPost('save', { rows: [row], deletedCodes: [] })
        .then(function (data) {
            var result = (data.results || [])[0];
            if (!result || !result.success) {
                window.alert('저장 실패: ' + (result && result.message ? result.message : '알 수 없는 오류'));
                return;
            }
            cuState.popup.setCaption(row['회사명'] || '고객사');
            if (wasNew) {
                cuState.key = result.newCode || '';
                cuRenderStaff(); // 이제 담당자를 입력할 수 있다
            } else if (cuState.staffGrid) {
                cuState.staffGrid.save(); // 담당자 편집분(있으면) — 실패하면 AGGrid가 알린다
            }
            cuMainGrid().requery();
        })
        .catch(function () { window.alert('저장 중 오류가 발생했습니다.'); });
}

/** 삭제 — 지금 고객사와 그 담당자를 함께 지운다(확인 후, 서버가 한 트랜잭션으로
 * 처리 — customer_fetch.php). 저장 전 신규면 창만 닫는다. */
function cuDelete() {
    if (!cuState.key) {
        cuState.popup.close();
        return;
    }
    if (!window.confirm('이 고객사와 담당자를 모두 삭제하시겠습니까?')) {
        return;
    }
    cuPost('delete', { rows: [], deletedCodes: [cuState.key] })
        .then(function (data) {
            var result = (data.deleteResults || [])[0];
            if (!result || !result.success) {
                window.alert('삭제 실패: ' + (result && result.message ? result.message : '알 수 없는 오류'));
                return;
            }
            cuState.popup.close();
            cuMainGrid().requery();
        })
        .catch(function () { window.alert('삭제 중 오류가 발생했습니다.'); });
}

/** 신규입력 — 입력란을 비우고 새 고객사 상태로(저장해야 실제로 생긴다). */
function cuNew() {
    cuState.key = '';
    cuFillForm(null);
    cuRenderStaff();
    document.getElementById('cu-f0').focus();
}

new AGGrid({
    gridId: '고객사',
    fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    primaryKey: <?= json_encode($primaryKey, JSON_UNESCAPED_UNICODE) ?>,
    // baseline(1_9) 값 그대로.
    fetchMode: 'paged',
    pageSize: 100,
    autoFitBottomGap: 40,
    columns: CU_COLUMNS,
    onRowDblClick: cuOpenCustomerPopup
});
</script>
</body>
</html>
