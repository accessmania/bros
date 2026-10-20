<?php
declare(strict_types=1);

/**
 * [필드감추기(tfieldvisible_parent + tfieldvisible, MSSQL deco DB) — 메뉴 "기초자료 > 필드감추기"]
 * 바탕화면 필드감추가.png(Access "필드감추기" 폼)를 재현한다(2026-10-20).
 * 구성: fieldvisible_entry.php(이 파일) + fieldvisible_fetch.php(`?t=parent|child`).
 *
 * - 목록 그리드(tfieldvisible_parent) — **읽기 전용**. 편집/검색 도구는 숨긴다
 *   (CSS, 아래 <style>) — "편집" 버튼이 없으니 잠금(view) 모드로 고정된다.
 * - 행 더블클릭 → 팝업(src/js/Popup/Popup.js):
 *     위: 명칭/메모 입력 + 저장/삭제/신규추가/창닫기 버튼
 *     아래: tfieldvisible 그리드(테이블=nickname/필드=fieldname/감추기=visiblefalse,
 *           연결키 parentcode = 부모 code)
 *           — 감추기 체크박스만 바꿀 수 있고, 오른쪽 ■/□는 감추기 전체선택/해제.
 *   "저장"은 명칭/메모(부모)를 저장한 뒤, 아래 그리드에서 바꾼 감추기도 함께
 *   저장한다. 신규추가 → 저장하면 새 부모 키(newCode)로 아래 그리드를 연다.
 *
 * 컬럼은 기초자료형 규칙대로 스키마에서 만들고 PK와 필드명에 "code"가 들어간
 * 컬럼은 숨긴다(parentcode 포함).
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Crud', 'Login', 'Str');

// 로그인이 안 살아있으면 여기서 login.php로 이동하고 끝난다.
Login::isAlive();

$connectionName = null; // config/db.php의 'driver' 기본 접속을 따른다
$fieldVisibleDb = new DbContext($connectionName);

/**
 * SQL DATA_TYPE → AGGrid.js의 dataType('string'|'number') 힌트
 * (login_entry.php의 b21_data_type_for()와 같은 규칙).
 */
function fieldvisible_data_type_for(string $sqlType): string
{
    static $numericTypes = [
        'int', 'bigint', 'smallint', 'tinyint',
        'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney',
    ];
    return in_array(strtolower($sqlType), $numericTypes, true) ? 'number' : 'string';
}

// --- 부모(tfieldvisible_parent) — 목록 그리드 컬럼 + 팝업 명칭/메모 필드 ---
$parentProbe = new Crud($fieldVisibleDb);
$parentProbe->init('tfieldvisible_parent');
$parentPrimaryKey = $parentProbe->getPrimaryKey();
$parentColumns = [];
$nameField = null;
$memoField = null;
foreach ($parentProbe->getSchema()['fields'] as $field => $info) {
    if ($field === $parentPrimaryKey || stripos($field, 'code') !== false) {
        continue;
    }
    // ts(rowversion/timestamp)는 이진값이라 표시할 수 없다 — 숨김.
    if (in_array(strtolower((string) ($info['type'] ?? '')), ['timestamp', 'rowversion'], true) || $field === 'ts') {
        continue;
    }
    if ($nameField === null && $field === '명칭') {
        $nameField = $field;
    }
    if ($memoField === null && preg_match('/메모|memo/i', $field) === 1) {
        $memoField = $field;
    }
    $parentColumns[] = [
        'field' => $field,
        'headerName' => $field,
        'editable' => false, // 읽기 전용 목록
        'dataType' => fieldvisible_data_type_for($info['type'] ?? ''),
    ];
}
// 명칭 필드를 이름으로 못 찾으면 메모가 아닌 첫 표시 컬럼을 명칭으로 쓴다.
if ($nameField === null) {
    foreach ($parentColumns as $column) {
        if ($column['field'] !== $memoField) {
            $nameField = $column['field'];
            break;
        }
    }
}

// --- 자식(tfieldvisible) — 팝업 아래 그리드 컬럼. 감추기만 편집(체크박스) ---
$childPrimaryKey = null;
$childColumns = [];
$childError = null;
try {
    $childProbe = new Crud($fieldVisibleDb);
    $childProbe->init('tfieldvisible');
    $childPrimaryKey = $childProbe->getPrimaryKey();
    $childSchema = $childProbe->getSchema()['fields'];
    // 필드감추가.png의 세 컬럼으로 고정(2026-10-20 — 실제 필드명 확인 후).
    // tablename/ts/parentcode/sourcecode 등 나머지는 표시하지 않는다.
    $childLayout = [
        ['field' => 'nickname',     'headerName' => '테이블', 'checkbox' => false],
        ['field' => 'fieldname',    'headerName' => '필드',   'checkbox' => false],
        ['field' => 'visiblefalse', 'headerName' => '감추기', 'checkbox' => true],
    ];
    foreach ($childLayout as $column) {
        if (!isset($childSchema[$column['field']])) {
            continue; // 스키마에 없는 필드는 건너뛴다(필드명이 바뀐 경우 대비)
        }
        $column['dataType'] = fieldvisible_data_type_for($childSchema[$column['field']]['type'] ?? '');
        $childColumns[] = $column;
    }
} catch (RuntimeException $e) {
    $childError = 'tfieldvisible — ' . $e->getMessage();
}

$fetchUrl = APP_BASE_URL . '/basicdata/fieldvisible/fieldvisible_fetch.php';

// 브라우저 캐시로 옛 JS가 보이지 않게 수정시각을 쿼리스트링에 붙인다.
$aggridJsPath = APP_BASE_DIR . '/src/js/aggrid/AGGrid.js';
$aggridJsVersion = is_file($aggridJsPath) ? filemtime($aggridJsPath) : time();
$popupJsVersion = filemtime(APP_BASE_DIR . '/src/js/Popup/Popup.js');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>필드감추기</title>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
<script src="<?= APP_BASE_URL ?>/src/js/Popup/Popup.js?v=<?= $popupJsVersion ?>"></script>
<style>
html, body { margin: 0; height: 100%; }
body { padding: 16px; box-sizing: border-box; font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif; }
h1 { font-size: 1.1rem; margin: 0 0 12px; }

/* 목록 그리드는 읽기 전용 — 편집 버튼과 검색 도구를 숨긴다. */
#aggrid-필드감추기 .ag-shell-mode-btn,
#aggrid-필드감추기 .ag-shell-search-wrap,
#aggrid-필드감추기 .ag-shell-search-btn {
    display: none;
}

/* 팝업 — 필드감추가.png 배치. */
.fv-popup { width: 460px; max-width: 90vw; font-size: 0.85rem; }
.fv-row { display: flex; gap: 8px; margin-bottom: 8px; }
.fv-row label { flex: 0 0 44px; padding-top: 5px; color: #9a3412; font-weight: 600; }
.fv-row input, .fv-row textarea {
    flex: 1; padding: 5px 7px; border: 1px solid #d1d5db; border-radius: 4px;
    font: inherit; box-sizing: border-box;
}
.fv-row textarea { height: 64px; resize: vertical; }
.fv-buttons { display: flex; gap: 6px; margin: 10px 0 12px 52px; }
.fv-buttons button {
    padding: 4px 12px; border: 1px solid #cbd5e1; border-radius: 4px;
    background: #f8fafc; color: #1f2937; font-size: 0.82rem; cursor: pointer;
}
.fv-buttons button:hover { background: #e2e8f0; }
.fv-buttons .fv-save-btn { background: #2563eb; border-color: #2563eb; color: #fff; font-weight: 600; }
.fv-buttons .fv-save-btn:hover { background: #1d4ed8; }
.fv-buttons .fv-delete-btn { background: #e0736c; border-color: #e0736c; color: #fff; }
.fv-buttons .fv-delete-btn:hover { background: #c85950; }
.fv-buttons .fv-close-btn { margin-left: auto; }
.fv-child { display: flex; gap: 6px; align-items: flex-start; }
.fv-child-grid { flex: 1; min-width: 0; }
.fv-child-side { display: flex; flex-direction: column; gap: 6px; }
.fv-child-side button {
    width: 28px; height: 26px; border: 1px solid #cbd5e1; border-radius: 4px;
    background: #f8fafc; cursor: pointer; font-size: 0.8rem; line-height: 1;
}
.fv-child-side button:hover { background: #e2e8f0; }
/* 아래 그리드는 툴바(편집/저장/검색)를 안 쓴다 — 저장은 팝업의 "저장" 버튼이 한다. */
.fv-popup .ag-shell-toolbar { display: none; }
.fv-message {
    padding: 10px; border: 1px solid #e5e7eb; border-radius: 4px; background: #f9fafb; color: #6b7280;
}
.fv-error { border-color: #fca5a5; background: #fef2f2; color: #b91c1c; }
</style>
</head>
<body>
<h1>필드감추기</h1>

<div id="aggrid-필드감추기"></div>
<script>
var FV = {
    fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    parentPrimaryKey: <?= json_encode($parentPrimaryKey, JSON_UNESCAPED_UNICODE) ?>,
    parentColumns: <?= json_encode($parentColumns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    nameField: <?= json_encode($nameField, JSON_UNESCAPED_UNICODE) ?>,
    memoField: <?= json_encode($memoField, JSON_UNESCAPED_UNICODE) ?>,
    childPrimaryKey: <?= json_encode($childPrimaryKey, JSON_UNESCAPED_UNICODE) ?>,
    childColumns: <?= json_encode($childColumns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    childError: <?= json_encode($childError, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    trueStrings: <?= json_encode(Str::TRUE_STRINGS) ?>
};

function fvEscape(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// 감추기 판정 — Str::toBool()과 같은 규칙(login_entry.php의 b21ToBool()과 동일).
function fvToBool(v) {
    if (typeof v === 'boolean') { return v; }
    if (v === null || v === undefined) { return false; }
    if (typeof v === 'number') { return v !== 0; }
    var text = String(v).trim().toLowerCase();
    if (text === '') { return false; }
    if (!isNaN(Number(text))) { return Number(text) !== 0; }
    return FV.trueStrings.indexOf(text) >= 0;
}

/** 자식 그리드 columnDefs — 감추기(체크박스)만 편집 가능, 저장 값은 1/0. */
function fvChildColumnDefs() {
    return FV.childColumns.map(function (col) {
        if (!col.checkbox) {
            return { field: col.field, headerName: col.headerName, editable: false, dataType: col.dataType };
        }
        return {
            field: col.field, headerName: col.headerName, editable: true,
            cellRenderer: 'agCheckboxCellRenderer', cellEditor: 'agCheckboxCellEditor',
            valueGetter: function (params) { return params.data ? fvToBool(params.data[col.field]) : false; },
            valueSetter: function (params) { params.data[col.field] = params.newValue ? 1 : 0; return true; }
        };
    });
}

// --- 팝업 상태 — 한 번에 하나만 열린다(모달) ----------------------------
// key = 부모 PK(저장/삭제용), code = 자식 parentcode와 맞출 부모 code(보통 같은 값).
var fvState = { popup: null, childGrid: null, key: '', code: '' };

function fvMainGrid() {
    return AGGrid.instance('필드감추기');
}

/** 아래 그리드를 지금 부모(fvState.code)로 다시 만든다. 부모가 아직 없으면(신규) 안내만. */
function fvRenderChild() {
    if (fvState.childGrid) {
        fvState.childGrid.destroy();
        fvState.childGrid = null;
    }
    var holder = document.getElementById('fv-child-grid');
    if (FV.childError) {
        holder.innerHTML = '<div class="fv-message fv-error">필드 목록을 불러오지 못했습니다: ' + fvEscape(FV.childError) + '</div>';
        return;
    }
    if (!fvState.code) {
        holder.innerHTML = '<div class="fv-message">신규 항목입니다. 명칭을 입력하고 저장하면 필드 목록이 나타납니다.</div>';
        return;
    }
    holder.innerHTML = '<div id="aggrid-필드감추기상세"></div>';
    fvState.childGrid = new AGGrid({
        gridId: '필드감추기상세',
        fetchUrl: FV.fetchUrl + '?t=child&parentcode=' + encodeURIComponent(fvState.code),
        primaryKey: FV.childPrimaryKey,
        fetchMode: 'all',
        height: '420px',
        // 감추기 체크박스를 바로 바꿀 수 있게 편집 모드로 시작(툴바는 CSS로 숨김).
        defaultMode: 'edit',
        showAddRow: false,
        showDeleteColumn: false,
        columns: fvChildColumnDefs()
    });
}

/**
 * 자식 행 채우기(Access VBA 이식) → 아래 그리드 표시. 이 부모의 tfieldvisible
 * 행이 하나도 없으면 서버가 tfieldvisible_source에서 채운다(init_children).
 * code가 없으면(신규, 저장 전) VBA처럼 아무것도 안 하고 안내만 보인다.
 * 채우기가 실패해도 그리드는 그대로 연다(원인은 alert).
 */
function fvInitAndRenderChild() {
    if (!fvState.code || FV.childError) {
        fvRenderChild();
        return;
    }
    var code = fvState.code;
    fetch(FV.fetchUrl + '?t=child&action=init_children&parentcode=' + encodeURIComponent(code), {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data.success) {
                window.alert('필드 목록 채우기 실패: ' + (data.message || '알 수 없는 오류'));
            }
        })
        .catch(function () { window.alert('필드 목록 채우기 중 오류가 발생했습니다.'); })
        .then(function () {
            // 그 사이 팝업이 닫혔거나 다른 항목으로 바뀌었으면 그리지 않는다.
            if (fvState.popup && fvState.code === code) {
                fvRenderChild();
            }
        });
}

function fvFillForm(values) {
    document.getElementById('fv-name').value = values && FV.nameField ? (values[FV.nameField] || '') : '';
    document.getElementById('fv-memo').value = values && FV.memoField ? (values[FV.memoField] || '') : '';
}

/** 부모의 연결값 — 자식 parentcode와 맞춰 볼 부모 code. code 필드가 없으면 PK 값. */
function fvParentCode(values, key) {
    var code = values && values.code != null && values.code !== '' ? values.code : key;
    return code != null ? String(code) : '';
}

function fvOpenPopup(values, key) {
    // 아래 그리드는 부모 code = 자식 parentcode인 행만 보여준다(서버가
    // ?parentcode=로 거름 — fieldvisible_fetch.php, 값이 비면 빈 목록).
    fvState.key = key != null ? String(key) : '';
    fvState.code = fvParentCode(values, key);
    fvState.popup = new Popup({
        id: 'fv-popup',
        title: '필드감추기',
        modal: true,
        draggable: true,
        html: '<div class="fv-popup">'
            + '<div class="fv-row"><label for="fv-name">명칭</label><input type="text" id="fv-name"></div>'
            + '<div class="fv-row"><label for="fv-memo">메모</label><textarea id="fv-memo"></textarea></div>'
            + '<div class="fv-buttons">'
            + '<button type="button" class="fv-save-btn" id="fv-save-btn">저장</button>'
            + '<button type="button" class="fv-delete-btn" id="fv-delete-btn">삭제</button>'
            + '<button type="button" id="fv-new-btn">신규추가</button>'
            + '<button type="button" class="fv-close-btn" id="fv-close-btn">창닫기</button>'
            + '</div>'
            + '<div class="fv-child"><div class="fv-child-grid" id="fv-child-grid"></div>'
            + '<div class="fv-child-side">'
            + '<button type="button" id="fv-check-all" title="감추기 전체선택">■</button>'
            + '<button type="button" id="fv-uncheck-all" title="감추기 전체해제">□</button>'
            + '</div></div>'
            + '</div>',
        onClose: function () {
            if (fvState.childGrid) {
                fvState.childGrid.destroy();
                fvState.childGrid = null;
            }
            fvState.popup = null;
        }
    });
    fvFillForm(values);
    fvInitAndRenderChild();

    document.getElementById('fv-save-btn').addEventListener('click', fvSave);
    document.getElementById('fv-delete-btn').addEventListener('click', fvDelete);
    document.getElementById('fv-new-btn').addEventListener('click', fvNew);
    document.getElementById('fv-close-btn').addEventListener('click', function () { fvState.popup.close(); });
    document.getElementById('fv-check-all').addEventListener('click', function () { fvSetAllHidden(true); });
    document.getElementById('fv-uncheck-all').addEventListener('click', function () { fvSetAllHidden(false); });
}

/** ■/□ — 아래 그리드의 감추기를 전부 체크/해제한다(저장은 "저장" 버튼). */
function fvSetAllHidden(checked) {
    var grid = fvState.childGrid;
    var checkboxCol = FV.childColumns.filter(function (col) { return col.checkbox; })[0];
    if (!grid || !grid.gridApi || !checkboxCol) {
        return;
    }
    // setDataValue는 valueSetter(1/0)와 cellValueChanged(변경 표시)를 그대로 탄다.
    grid.gridApi.forEachNode(function (node) {
        if (node.data) {
            node.setDataValue(checkboxCol.field, checked);
        }
    });
}

function fvPost(action, body) {
    return fetch(FV.fetchUrl + '?t=parent&action=' + action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(body)
    }).then(function (res) { return res.json(); });
}

/** 저장 — 명칭/메모(부모)를 저장하고, 아래 그리드에서 바꾼 감추기도 저장한다. */
function fvSave() {
    var name = document.getElementById('fv-name').value.trim();
    if (name === '') {
        window.alert('명칭을 입력해 주세요.');
        return;
    }
    var row = {};
    if (FV.nameField) { row[FV.nameField] = name; }
    if (FV.memoField) { row[FV.memoField] = document.getElementById('fv-memo').value; }
    if (fvState.key) { row[FV.parentPrimaryKey] = fvState.key; }
    var wasNew = !fvState.key;

    fvPost('save', { rows: [row], deletedCodes: [] })
        .then(function (data) {
            var result = (data.results || [])[0];
            if (!result || !result.success) {
                window.alert('저장 실패: ' + (result && result.message ? result.message : '알 수 없는 오류'));
                return;
            }
            if (wasNew) {
                // 새 부모 키로 아래 그리드를 연다(이 부모의 필드 목록 — 처음엔 비어 있을 수 있다).
                fvState.key = result.newCode || '';
                fvState.code = fvState.key; // 새 부모 — PK(newCode)를 연결값으로 쓴다
                fvInitAndRenderChild();
            } else if (fvState.childGrid) {
                fvState.childGrid.save(); // 실패하면 AGGrid가 직접 알린다
            }
            fvMainGrid().requery();
        })
        .catch(function () { window.alert('저장 중 오류가 발생했습니다.'); });
}

/** 삭제 — 지금 부모 레코드와 그 자식(tfieldvisible) 행을 함께 지운다(확인 후,
 * 서버가 한 트랜잭션으로 처리). 아직 저장 안 한 신규면 창만 닫는다. */
function fvDelete() {
    if (!fvState.key) {
        fvState.popup.close();
        return;
    }
    if (!window.confirm('이 항목과 필드 목록(감추기 설정)을 모두 삭제하시겠습니까?')) {
        return;
    }
    fvPost('delete', { rows: [], deletedCodes: [fvState.key] })
        .then(function (data) {
            var result = (data.deleteResults || [])[0];
            if (!result || !result.success) {
                window.alert('삭제 실패: ' + (result && result.message ? result.message : '알 수 없는 오류'));
                return;
            }
            fvState.popup.close();
            fvMainGrid().requery();
        })
        .catch(function () { window.alert('삭제 중 오류가 발생했습니다.'); });
}

/** 신규추가 — 입력란을 비우고 새 항목 상태로 바꾼다(저장해야 실제로 생긴다). */
function fvNew() {
    fvState.key = '';
    fvState.code = '';
    fvFillForm(null);
    fvRenderChild();
    document.getElementById('fv-name').focus();
}

new AGGrid({
    gridId: '필드감추기',
    fetchUrl: FV.fetchUrl + '?t=parent',
    primaryKey: FV.parentPrimaryKey,
    fetchMode: 'paged',
    pageSize: 100,
    autoFitBottomGap: 40,
    columns: FV.parentColumns,
    // 읽기 전용(편집 버튼 숨김 → 항상 잠금 모드)이라 더블클릭이 항상 동작한다.
    onRowDblClick: fvOpenPopup
});
</script>
</body>
</html>
