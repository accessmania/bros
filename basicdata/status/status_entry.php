<?php
declare(strict_types=1);

/**
 * [상태(tb상태_* 5개, MSSQL deco DB) AGGrid 화면 — 메뉴 "기초자료 > 상태"]
 * 기초자료형(src/js/aggrid/aggrid.md "기초자료형")을 한 화면에 그리드 5개로
 * 펼친 변형 — 바탕화면 상태.png(Access "상태" 폼)를 재현한다: 윗줄
 * 문의상태/문의내역상태, 아랫줄 견적상태/수주상태/발주상태.
 *
 * 구성: status_entry.php(이 파일) + status_fetch.php(CRUD, `?t=<키>`로 테이블
 * 선택) + status_tables.php(키 → 테이블/제목 목록, 두 파일이 같이 씀).
 *
 * 기초자료형과 다른 점:
 *  - 그리드가 여러 개라 autoFitBottomGap(화면 바닥까지 채우기) 대신 고정
 *    height를 쓴다 — 위 그리드가 화면을 다 차지하지 않게.
 *  - 테이블 하나가 DB에 없어도 화면 전체가 죽지 않게, 그 칸에만 원인을
 *    표시한다(Crud::init() 예외를 잡음).
 * 그 밖의 컬럼 규칙(스키마 조회, PK와 필드명에 "code"가 들어간 컬럼 숨김,
 * dataType)은 기초자료형 그대로다.
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
$statusGridDb = new DbContext($connectionName);
$statusTables = require __DIR__ . '/status_tables.php';

/**
 * SQL DATA_TYPE → AGGrid.js의 dataType('string'|'number') 힌트
 * (login_entry.php의 b21_data_type_for()와 같은 규칙).
 */
function status_data_type_for(string $sqlType): string
{
    static $numericTypes = [
        'int', 'bigint', 'smallint', 'tinyint',
        'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney',
    ];
    return in_array(strtolower($sqlType), $numericTypes, true) ? 'number' : 'string';
}

/**
 * 테이블마다 그리드 설정을 만든다. 테이블이 없는 등 스키마 조회가 실패하면
 * error에 원인을 담아 그 칸에만 표시한다.
 *
 * @var array<int, array{key: string, title: string, primaryKey: ?string, columns: array, fetchUrl: string, error: ?string}>
 */
$grids = [];
foreach ($statusTables as $key => $info) {
    $grid = [
        'key' => $key,
        'title' => $info['title'],
        'primaryKey' => null,
        'columns' => [],
        'fetchUrl' => APP_BASE_URL . '/basicdata/status/status_fetch.php?t=' . rawurlencode($key),
        'error' => null,
    ];
    try {
        $probe = new Crud($statusGridDb);
        $probe->init($info['table']);
        $grid['primaryKey'] = $probe->getPrimaryKey();
        foreach ($probe->getSchema()['fields'] as $field => $fieldInfo) {
            // PK와 필드명에 "code"가 들어간 컬럼(대소문자 무관)은 표시하지
            // 않는다(기초자료형 규칙). 값은 행 데이터에 그대로 있다.
            if ($field === $grid['primaryKey'] || stripos($field, 'code') !== false) {
                continue;
            }
            $grid['columns'][] = [
                'field' => $field,
                'headerName' => $field,
                'editable' => true,
                'dataType' => status_data_type_for($fieldInfo['type'] ?? ''),
            ];
        }
    } catch (RuntimeException $e) {
        $grid['error'] = $info['table'] . ' — ' . $e->getMessage();
    }
    $grids[] = $grid;
}

// 브라우저 캐시로 옛 AGGrid.js가 보이지 않게 수정시각을 쿼리스트링에 붙인다.
$aggridJsPath = APP_BASE_DIR . '/src/js/aggrid/AGGrid.js';
$aggridJsVersion = is_file($aggridJsPath) ? filemtime($aggridJsPath) : time();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>상태</title>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
<style>
html, body { margin: 0; }
body { padding: 16px; box-sizing: border-box; font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif; }
h1 { font-size: 1.1rem; margin: 0 0 12px; }

/* 상태.png 배치 — 3칸 격자, 윗줄은 문의/문의내역 두 칸만 쓰고 셋째 칸은
   비운다(.st-spacer). 좁은 화면에선 칸 수가 줄어 아래로 흐른다. */
.st-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 20px 24px;
    max-width: 1200px;
}
.st-cell h2 { font-size: 0.9rem; font-weight: 600; margin: 0 0 6px; }
.st-error {
    padding: 12px; border: 1px solid #fca5a5; border-radius: 4px;
    background: #fef2f2; color: #b91c1c; font-size: 0.82rem; line-height: 1.5;
}
/* 검색도구(검색창 + "검색" 버튼)는 이 화면에서 안 쓴다 — 상태 목록이 짧아
   필요 없음. AGGrid가 항상 그리는 요소라 이 화면 범위(.st-grid)에서만 숨긴다. */
.st-grid .ag-shell-search-wrap,
.st-grid .ag-shell-search-btn {
    display: none;
}
@media (max-width: 1150px) {
    .st-spacer { display: none; }
}
</style>
</head>
<body>
<h1>상태</h1>

<div class="st-grid">
<?php foreach ($grids as $index => $grid): ?>
    <div class="st-cell">
        <h2><?= Str::escape($grid['title']) ?></h2>
<?php if ($grid['error'] !== null): ?>
        <div class="st-error">테이블을 불러오지 못했습니다: <?= Str::escape($grid['error']) ?></div>
<?php else: ?>
        <div id="aggrid-<?= Str::escape($grid['title']) ?>"></div>
<?php endif; ?>
    </div>
<?php if ($index === 1): ?>
    <div class="st-spacer"></div>
<?php endif; ?>
<?php endforeach; ?>
</div>

<script>
var ST_GRIDS = <?= json_encode(array_values(array_filter($grids, function (array $grid): bool {
    return $grid['error'] === null;
})), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

// 컬럼 너비는 내용 기준 bestfit(autoSizeAllColumns) — 화면이 뜰 때(첫 데이터
// 표시 직후) 맞추고, 편집/잠금 전환(삭제 컬럼이 생기고 없어짐) 후에도 다시
// 맞춘다. (2026-10-20: 한때 그리드 폭에 맞추는 sizeColumnsToFit을 썼으나
// bestfit 지시로 교체 — 내용이 칸보다 넓으면 가로 스크롤이 생길 수 있다.)
function stFitColumns(aggrid) {
    if (aggrid.gridApi) {
        aggrid.gridApi.autoSizeAllColumns();
    }
}

ST_GRIDS.forEach(function (grid) {
    new AGGrid({
        gridId: grid.title,
        fetchUrl: grid.fetchUrl,
        primaryKey: grid.primaryKey,
        // 페이저 없음 — 상태 목록은 짧아서 전체를 한 번에 받는다('all'은
        // clientSide 모델이라 AG Grid 페이저(pagination)를 아예 안 그린다).
        fetchMode: 'all',
        // 그리드가 여러 개라 화면 바닥까지 채우지 않고 고정 높이(상태.png 크기).
        height: '280px',
        columns: grid.columns,
        onFirstDataRendered: stFitColumns,
        // 툴바는 setMode()가 컬럼을 다시 세팅하기 직전에 그려지므로, 한 박자
        // 뒤(컬럼 세팅이 끝난 뒤)에 맞춘다.
        onToolbarRendered: function (aggrid) {
            setTimeout(function () { stFitColumns(aggrid); }, 0);
        }
    });
});
</script>
</body>
</html>
