<?php
declare(strict_types=1);

/**
 * [운임표 — tb운임표 목록 팝업(편집 가능)]
 * 견적 팝업(inquiryContent_견적_amd.php)의 운임표 버튼 → 문의확인(inquiryContent_entry.php)이
 * action('openFreight')로 이 화면을 비모달 팝업(Popup.js, 이동 가능)에 띄운다(수식·ECount 팝업과 같은 방식).
 *
 *  - AGGrid 기본형(gridStyle 'basic'), 한 번에 전부 받는다(fetchMode 'all').
 *  - 정렬: 중량코드 오름차순, **숫자 순서**(2 → 10). 중량코드가 글자 필드여도 숫자로 비교한다
 *    (컬럼의 sort:'asc' + frCompareNumber). 숫자가 아닌 값·빈 값은 맨 뒤. 서버도 중량코드 순으로 주지만
 *    글자 순서일 수 있어 화면이 다시 정렬한다. 헤더를 누르면 브라우저 안에서 다시 정렬된다(서버 왕복 없음).
 *  - 검색 없음. 툴바의 편집 버튼 → 칸을 고치고 저장(AGGrid 기본 동작 — 저장은 fetch의 save, ActionBulk).
 *    행 추가·삭제 컬럼은 끈다(기존 행 값 고치기만). 메뉴 "기초자료 > 운임표"(basicdata/freight/)와 같은 테이블이다.
 *  - 컬럼: 테이블의 필드 전부(PK 제외), 스키마 타입으로 dataType을 준다(기초자료형 규칙과 같다).
 *    단, 중량코드는 PK여도 맨 앞에 보인다(PK면 고칠 수 없게 editable false). 콤마 없이 오른쪽 정렬.
 *
 * 페이지 함수: action(pcmd, pparams, psender)
 *  - 'requery' — 다시 읽는다(팝업이 이미 떠 있을 때 운임표 버튼을 또 누르면).
 * Esc를 누르면 부모(문의확인)의 action('closeFreight')로 팝업을 닫는다.
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

$gridId = '운임표팝업';
$tableName = 'tb운임표';
// 맨 앞에 보이고 숫자 순서로 정렬하는 필드 — JS의 FR_SORT_FIELD와 같은 값.
$sortField = '중량코드';
$primaryKey = null;
$columns = [];
$sortColumn = null;
$loadError = '';

/**
 * SQL DATA_TYPE → AGGrid.js의 dataType('string'|'number') — freight_entry.php의 freight_data_type_for()와 같은 규칙.
 * 숫자 필드는 오른쪽 정렬 + 콤마, 글자 필드는 숫자처럼 보여도 콤마를 붙이지 않는다.
 */
function fr_data_type_for(string $psqlType): string
{
    $numericTypes = [
        'int', 'bigint', 'smallint', 'tinyint',
        'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney',
    ];
    if (in_array(strtolower($psqlType), $numericTypes, true)) {
        return 'number';
    }
    return 'string';
}

// 컬럼은 스키마에서 만든다. 테이블을 못 읽으면 이유를 화면에 보인다.
try {
    $probe = new Crud(new DbContext(null));
    $probe->init($tableName);
    $primaryKey = $probe->getPrimaryKey();
    foreach ($probe->getSchema()['fields'] as $field => $info) {
        // 중량코드 — PK여도 보인다. 숫자 정렬(comparator)은 JS의 frColumns()가 붙인다.
        if ($field === $sortField) {
            $sortColumn = [
                'field' => $field,
                'headerName' => $field,
                'editable' => $field !== $primaryKey, // PK는 고치면 행을 못 찾으므로 잠근다
                'dataType' => 'string', // 코드라 콤마를 붙이지 않는다
                'align' => 'right',
                'sort' => 'asc',        // 처음 열 때 오름차순(AG Grid 컬럼 속성)
            ];
            continue;
        }
        if ($field === $primaryKey) {
            continue;
        }
        $columns[] = [
            'field' => $field,
            'headerName' => $field,
            'editable' => true, // 편집 모드일 때만 실제로 고칠 수 있다(AGGrid가 모드를 덧씌운다)
            'dataType' => fr_data_type_for((string) ($info['type'] ?? '')),
        ];
    }
    if ($sortColumn === null) {
        throw new RuntimeException($tableName . '에 ' . $sortField . ' 필드가 없습니다.');
    }
    array_unshift($columns, $sortColumn);
} catch (Throwable $e) {
    $loadError = $tableName . '을(를) 읽지 못했습니다: ' . $e->getMessage();
}

$fetchUrl = APP_BASE_URL . '/workspace/inquiry/' . rawurlencode('inquiryContent_운임표_fetch.php');
$aggridJsPath = APP_BASE_DIR . '/src/js/aggrid/AGGrid.js';
$aggridJsVersion = is_file($aggridJsPath) ? filemtime($aggridJsPath) : time();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>운임표</title>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
<style>
html, body { margin: 0; height: 100%; overflow: hidden; }
body { padding: 10px; box-sizing: border-box; font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif; }
.fr-error-page { color: #dc2626; }
</style>
</head>
<body>
<?php if ($loadError !== ''): ?>
<p class="fr-error-page"><?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></p>
<?php else: ?>
<div id="aggrid-<?= htmlspecialchars($gridId, ENT_QUOTES, 'UTF-8') ?>"></div>
<script>
// VBA식 JS — var(함수 맨 위에 모아 선언), function, if/else, 매개변수는 p로 시작.
// 화면 전용 함수·변수는 fr / FR_ 접두사(FReight). action/action_xxx에는 붙이지 않는다.
var FR_GRID_ID = <?= json_encode($gridId, JSON_UNESCAPED_UNICODE) ?>;
var FR_SORT_FIELD = <?= json_encode($sortField, JSON_UNESCAPED_UNICODE) ?>;

// 기본형, 한 번에 전부 받고(중량코드 숫자 오름차순 — frColumns), 높이는 팝업 바닥까지 채운다.
new AGGrid({
    gridId: FR_GRID_ID,
    fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    primaryKey: <?= json_encode($primaryKey, JSON_UNESCAPED_UNICODE) ?>,
    fetchMode: 'all',
    gridStyle: 'basic',
    autoFitBottomGap: 10,
    minHeight: 120,
    showSearch: false,
    showAddRow: false,
    showDeleteColumn: false,
    columns: frColumns(<?= json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>)
});

/** 컬럼 — 중량코드에 숫자 비교(frCompareNumber)를 붙인다(PHP는 함수를 넘길 수 없어 여기서). */
function frColumns(pcolumns) {
    var i;

    for (i = 0; i < pcolumns.length; i++) {
        if (pcolumns[i].field === FR_SORT_FIELD) {
            pcolumns[i].comparator = frCompareNumber;
        }
    }
    return pcolumns;
}

/** 값을 숫자로 — 빈 값이나 숫자가 아니면 null(콤마는 뗀다). */
function frToNumber(pvalue) {
    var n;

    if (pvalue === null || pvalue === undefined) {
        return null;
    }
    if (String(pvalue).trim() === '') {
        return null;
    }
    n = Number(String(pvalue).replace(/,/g, ''));
    if (isNaN(n)) {
        return null;
    }
    return n;
}

/**
 * 숫자 순서 비교(AG Grid comparator) — '2' < '10'. 숫자가 아닌 값·빈 값은 맨 뒤(오름차순 기준).
 * 음수면 pa가 앞, 양수면 pb가 앞, 0이면 같다.
 */
function frCompareNumber(pa, pb) {
    var na = frToNumber(pa);
    var nb = frToNumber(pb);

    if (na === null && nb === null) {
        return 0;
    }
    if (na === null) {
        return 1;
    }
    if (nb === null) {
        return -1;
    }
    return na - nb;
}

// ══════════════════════════════════════════════════════════════════
// action — 부모(문의확인)가 이 화면을 부르는 창구.
// ══════════════════════════════════════════════════════════════════
function action(pcmd, pparams, psender) {
    if (!pparams) {
        pparams = {};
    }
    switch (pcmd) {
        case 'requery':
            return action_requery(pcmd, pparams, psender);
        default:
            console.warn('운임표.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

/**
 * requery — 서버에서 다시 읽는다(운임표를 기초자료에서 고친 뒤 버튼을 또 누르면 바뀐 값이 보이게).
 * 편집 모드면 다시 읽지 않는다(저장 안 한 값이 사라지지 않게 — 잠금으로 돌아간 뒤에는 다시 읽는다).
 */
function action_requery(pcmd, pparams, psender) {
    var grid = AGGrid.instance(FR_GRID_ID);

    if (grid === null || !grid.gridApi) {
        return false;
    }
    if (grid.mode === 'edit') {
        return false;
    }
    grid.requery();
    return true;
}

/** Esc — 부모(문의확인)에게 팝업을 닫게 한다. */
function frKeyDown(pevent) {
    if (pevent.key !== 'Escape') {
        return;
    }
    if (window.parent === window || typeof window.parent.action !== 'function') {
        return;
    }
    window.parent.action('closeFreight', {}, window);
}

document.addEventListener('keydown', frKeyDown);
</script>
<?php endif; ?>
</body>
</html>
