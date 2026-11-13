<?php
declare(strict_types=1);

/**
 * 접근 주소: http://192.168.0.40/bros/src/js/aggrid/aggrid_entry.php
 *
 * aggrid_entry — AGGrid.js 시험 화면(스타일 조정용). 다른 부품의 `*_entry.php`와
 * 같은 "모듈 옆에 시험 화면을 둔다"는 관례를 따른다.
 *
 * 구성: 상단 버튼 10개 + tLogin 그리드 하나.
 * 서버는 새로 만들지 않고 기초자료 화면의 basicdata/login/login_fetch.php를 그대로 쓴다.
 *
 * **실제 tLogin 데이터**다 — "편집" 후 "저장"하면 DB에 그대로 저장된다. 계정을 만들거나
 * 지우는 일은 시험 화면에서 막으려고 행 추가·삭제 컬럼은 껐다. 단 버튼6만 편집 모드에서
 * "삭제"가 나온다 — 누르고 확인하면 실제 계정이 지워진다. 비밀번호 컬럼은
 * columns에서 뺐다(평문 표시 방지).
 *
 * 버튼1은 테이블형(?style=table), 버튼2는 기본형(주소 그대로), 버튼3은 테이블형 +
 * 높은 행(?style=tableTall), 버튼4는 기본형 그리드에 gridStyle만 테이블형
 * (?style=tablePlain), 버튼5는 기본형 + 행선택기(?style=basicSelector), 버튼6은
 * 테이블형 + 행선택기 + 호버 색(?style=tableHover, 버튼4에서 손가락 포인터만 뺀 것)으로
 * 페이지를 다시 연다. 모양은 AGGrid의 gridStyle 옵션이
 * 정한다(aggrid.md "그리드 스타일" 참고). 다시 여는 이유: gridStyle은 그리드를 만들 때
 * 한 번만 정하고, AG Grid가 행 높이를 처음 그릴 때만 읽기 때문이다.
 *
 * 이 화면에만 있는 것: 높은 행(버튼3), 사용여부 파란 글자, 하단 버튼줄(모양만 있음).
 * 파란 글자와 하단 버튼줄은 버튼1·3에만 나온다. 버튼4는 gridStyle만 준 순수한 모양에
 * 행 번호 대신 행선택기를 붙이고, 호버 시 행 색을 옅은 회색으로, 포인터를 손가락 모양으로
 * 바꾼 것이다(showRowSelector: true, rowHoverColor: '#eeeeee', rowHoverPointer: true).
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

// 어떤 스타일로 열렸는지 — 'table'(버튼1), 'tableTall'(버튼3), 'tablePlain'(버튼4),
// 'basicSelector'(버튼5), 'tableHover'(버튼6), 그 밖에는 기본형(버튼2)
$styleName = isset($_GET['style']) ? (string) $_GET['style'] : '';
$tallRows = $styleName === 'tableTall';
$rowSelector = $styleName === 'tablePlain' || $styleName === 'basicSelector' || $styleName === 'tableHover';
if ($styleName === 'tablePlain' || $styleName === 'tableHover') {
    $rowHoverColor = '#eeeeee'; // 옅은 회색
} else {
    $rowHoverColor = null; // 안 주면 스타일 기본 음영
}
$tableExtras = $styleName === 'table' || $styleName === 'tableTall'; // 파란 글자 + 하단 버튼줄
$tableStyle = $tableExtras || $styleName === 'tablePlain' || $styleName === 'tableHover';
if ($tableStyle) {
    $gridStyle = 'table';
} else {
    $gridStyle = 'basic';
}

$connectionName = null; // config/db.php의 'driver' 기본 접속을 따른다
$db = new DbContext($connectionName);

// 컬럼은 스키마에서 읽는다(login_entry.php와 같은 방식).
$probe = new Crud($db);
$probe->init('tLogin');
$primaryKey = $probe->getPrimaryKey();
$fieldSchema = $probe->getSchema()['fields'];

$numericTypes = ['int', 'bigint', 'smallint', 'tinyint', 'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney'];
$columns = [];
foreach ($fieldSchema as $field => $fieldInfo) {
    if ($field === $primaryKey) {
        continue;
    }
    if (stripos($field, 'code') !== false) {
        continue; // 기초자료형 규칙 — code가 들어간 컬럼은 화면에서 숨긴다
    }
    if ($field === '권한') {
        continue; // login_fetch.php가 응답에서 빼는 컬럼이라 넣어도 빈칸으로만 나온다
    }
    if (preg_match('/pw|pass|암호|비밀번호/i', $field) === 1) {
        continue; // 비밀번호는 표시하지 않는다
    }

    if (in_array(strtolower($fieldInfo['type'] ?? ''), $numericTypes, true)) {
        $dataType = 'number';
    } else {
        $dataType = 'string';
    }
    $column = ['field' => $field, 'headerName' => $field, 'dataType' => $dataType, 'editable' => true];

    // 상태 값을 파란 글자로 보인다(tLogin에는 주문상태가 없어 사용여부로 대신한다).
    if ($tableExtras && preg_match('/사용여부|사용유무/i', $field) === 1) {
        $column['cellStyle'] = ['textAlign' => 'center', 'color' => '#1d4ed8'];
    }

    $columns[] = $column;
}

$fetchUrl = APP_BASE_URL . '/basicdata/login/login_fetch.php';

// 브라우저 캐시로 옛 AGGrid.js가 보이지 않게 수정시각을 쿼리스트링에 붙인다.
$aggridJsVersion = filemtime(__DIR__ . '/AGGrid.js');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>AGGrid 시험</title>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
<style>
html, body { margin: 0; height: 100%; }
body { padding: 16px; box-sizing: border-box; font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif; }
h1 { font-size: 1.1rem; margin: 0 0 12px; }

/* ===== 버튼 ===== */
.age-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 12px;
}
.age-btn {
    padding: 6px 14px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    background: #f3f4f6;
    color: #374151;
    font-size: 0.82rem;
    font-weight: 500;
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.age-btn:hover { background: #e5e7eb; }
.age-btn:active { background: #d1d5db; }

.age-btn-on { background: #1f5fa8; border-color: #1f5fa8; color: #fff; }
.age-btn-on:hover { background: #174a85; }

/* 버튼3 — 테이블형에서 행 높이만 높게 */
.age-tall #aggrid-tLogin .ag-shell-grid {
    --ag-row-height: 64px;
}
/* 그리드 위에 떠 있는 하단 버튼줄 — bottom은 페이저 높이만큼 띄운 값 */
.age-grid-wrap { position: relative; }
.age-floatbar {
    position: absolute;
    left: 8px;
    bottom: 56px;
    z-index: 10;
    display: flex;
    gap: 4px;
    padding: 4px 6px;
    background: #f7f7f7;
    border: 1px solid #c8c8c8;
}
.age-floatbar-btn {
    padding: 3px 10px;
    border: 1px solid #b5b5b5;
    border-radius: 2px;
    background: #ffffff;
    color: #333333;
    font-size: 12px;
    cursor: pointer;
}
.age-floatbar-btn-main { background: #1f5fa8; border-color: #1f5fa8; color: #ffffff; }
</style>
</head>
<body<?php if ($tallRows) { echo ' class="age-tall"'; } ?>>
<h1>AGGrid 시험 — tLogin</h1>

<div class="age-buttons">
    <button type="button" class="age-btn<?php if ($styleName === 'table') { echo ' age-btn-on'; } ?>" onclick="ageOpenStyle('table')">버튼1</button>
    <button type="button" class="age-btn<?php if (!$tableStyle && !$rowSelector) { echo ' age-btn-on'; } ?>" onclick="ageOpenStyle('')">버튼2</button>
    <button type="button" class="age-btn<?php if ($tallRows) { echo ' age-btn-on'; } ?>" onclick="ageOpenStyle('tableTall')">버튼3</button>
    <button type="button" class="age-btn<?php if ($styleName === 'tablePlain') { echo ' age-btn-on'; } ?>" onclick="ageOpenStyle('tablePlain')">버튼4</button>
    <button type="button" class="age-btn<?php if ($styleName === 'basicSelector') { echo ' age-btn-on'; } ?>" onclick="ageOpenStyle('basicSelector')">버튼5</button>
    <button type="button" class="age-btn<?php if ($styleName === 'tableHover') { echo ' age-btn-on'; } ?>" onclick="ageOpenStyle('tableHover')">버튼6</button>
    <button type="button" class="age-btn">버튼7</button>
    <button type="button" class="age-btn">버튼8</button>
    <button type="button" class="age-btn">버튼9</button>
    <button type="button" class="age-btn">버튼10</button>
</div>

<div class="age-grid-wrap">
    <div id="aggrid-tLogin"></div>
<?php if ($tableExtras) { ?>
    <div class="age-floatbar">
        <button type="button" class="age-floatbar-btn age-floatbar-btn-main">선택삭제</button>
        <button type="button" class="age-floatbar-btn">품목연결</button>
        <button type="button" class="age-floatbar-btn">ERP전송</button>
        <button type="button" class="age-floatbar-btn">Excel</button>
    </div>
<?php } ?>
</div>
<script>
// 버튼1('table'), 버튼2('' = 기본형), 버튼3('tableTall'), 버튼4('tablePlain'), 버튼5('basicSelector'), 버튼6('tableHover')
// — 페이지를 다시 연다(이유는 파일 맨 위 설명 참고)
function ageOpenStyle(pstyle) {
    if (pstyle === '') {
        location.href = location.pathname;
    } else {
        location.href = location.pathname + '?style=' + pstyle;
    }
}

new AGGrid({
    gridId: 'tLogin',
    fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    primaryKey: <?= json_encode($primaryKey, JSON_UNESCAPED_UNICODE) ?>,
    gridStyle: <?= json_encode($gridStyle) ?>,
    showRowSelector: <?= json_encode($rowSelector) ?>,
    rowHoverColor: <?= json_encode($rowHoverColor) ?>,
    rowHoverPointer: <?= json_encode($styleName === 'tablePlain') ?>,
    fetchMode: 'paged',
    pageSize: 100,
    autoFitBottomGap: 40,
    showAddRow: false,
    showDeleteColumn: <?= json_encode($styleName === 'tableHover') ?>,
    columns: <?= json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
});
</script>
</body>
</html>
