<?php
declare(strict_types=1);

/**
 * [통화단위(tbcurrencyunit, MSSQL deco DB) AGGrid 화면 — 메뉴 "기초자료 > 통화단위"]
 * 기초자료형(src/js/aggrid/aggrid.md "기초자료형") — name: currency,
 * tablename: tbcurrencyunit. 구성: currency_entry.php(이 파일, 화면 + 프론트 config)
 * + currency_fetch.php(CRUD). 표준 예시 basicdata/login/login_entry.php와
 * 같은 방식이되, 특수 컬럼(비밀번호/콤보/체크박스 등) 요청이 없어 전부
 * 일반 텍스트 컬럼이다.
 *
 * 컬럼은 하드코딩하지 않고 Crud::init()으로 스키마를 조회해 구성한다 —
 * PK와 필드명에 "code"가 들어간 컬럼은 columns에서 빼고, 각 컬럼에 SQL 타입으로 dataType을 준다
 * (currency_data_type_for()). "메모"는 AGGrid.js가 자동으로 맨 뒤로 보낸다.
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
$currencyGridDb = new DbContext($connectionName);
$tableName = 'tbcurrencyunit';

$probe = new Crud($currencyGridDb);
$probe->init($tableName);
$primaryKey = $probe->getPrimaryKey();
$fieldSchema = $probe->getSchema()['fields'];

/**
 * SQL DATA_TYPE → AGGrid.js의 dataType('string'|'number') 힌트
 * (login_entry.php의 b21_data_type_for()와 같은 규칙). 값 기준 추측으로
 * 문자 필드에 콤마/우측정렬이 붙는 오탐을 막는다.
 */
function currency_data_type_for(string $sqlType): string
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
    if ($field === $primaryKey || stripos($field, 'code') !== false) {
        continue;
    }
    $columns[] = [
        'field' => $field,
        'headerName' => $field,
        'editable' => true,
        'dataType' => currency_data_type_for($info['type'] ?? ''),
    ];
}

$fetchUrl = APP_BASE_URL . '/basicdata/currency/currency_fetch.php';

// 브라우저 캐시로 옛 AGGrid.js가 보이지 않게 수정시각을 쿼리스트링에 붙인다.
$aggridJsPath = APP_BASE_DIR . '/src/js/aggrid/AGGrid.js';
$aggridJsVersion = is_file($aggridJsPath) ? filemtime($aggridJsPath) : time();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>통화단위</title>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
<style>
html, body { margin: 0; height: 100%; }
body { padding: 16px; box-sizing: border-box; font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif; }
h1 { font-size: 1.1rem; margin: 0 0 12px; }
</style>
</head>
<body>
<h1>통화단위</h1>

<div id="aggrid-통화단위"></div>
<script>
new AGGrid({
    gridId: '통화단위',
    fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    primaryKey: <?= json_encode($primaryKey, JSON_UNESCAPED_UNICODE) ?>,
    // baseline(1_9) 값 그대로.
    fetchMode: 'paged',
    pageSize: 100,
    autoFitBottomGap: 40,
    columns: <?= json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
});
</script>
</body>
</html>
