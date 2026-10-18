<?php
declare(strict_types=1);

/**
 * [AGGrid 모듈용 프론트엔드 config — "로그인"(tLogin, MSSQL deco DB) 그리드]
 * src/js/aggrid/AGGrid.js가 소비할 설정을 <script> 블록으로 echo한다
 * (src/js/aggrid/aggrid.md "적용 가이드" 참고). fetchMode/pageSize/
 * autoFitBottomGap 값은 AGGrid의 공식 baseline(1_9/1_20)을 그대로 따른다.
 *
 * tLogin의 실제 컬럼 구성을 미리 알 수 없어서(fetch_로그인.php와 동일한
 * 이유), 이 파일도 요청마다 Crud::init()으로 스키마를 직접 조회해
 * columnDefs를 구성한다 — 1_9/1_20처럼 컬럼을 코드에 직접 나열하지 않는
 * 유일한 이유가 이것(tLogin만의 특수 사정).
 *
 * 세 컬럼만 일반 텍스트 컬럼과 다르게 특별 취급한다:
 *  - **비밀번호**(pw/pass/암호/비밀번호): <input type="password">로 편집
 *    (AG Grid Community의 기본 텍스트 에디터는 입력 타입을 바꿀 표준
 *    옵션이 없어 PasswordCellEditor를 직접 구현), 목록 표시는 항상 마스킹
 *    (••••••••, cellClass `b21-pw-mask-cell`로 글자 크기까지 별도 조정 —
 *    원래 쓰던 U+25CF(●)가 너무 크게 보인다는 피드백으로 더 작은
 *    U+2022(•)로 교체) — 그리드가 여러 사람이 볼 수 있는 화면이라 평문
 *    노출은 피한다.
 *  - **권한코드**: 콤보박스(2026-08-25 추가 지시)로 표시/편집한다.
 *    선택지 원본은 `tAuth` 테이블(code/authname 컬럼)이고, 이 화면(그리드
 *    데이터를 매 페이지/검색마다 fetch하는 fetch_로그인.php)이 아니라
 *    **이 config 파일이 페이지가 열릴 때 한 번만** 조회해 <script> 안에
 *    직접 값 배열로 심어둔다 — tAuth는 사실상 고정된 참조 테이블(권한
 *    종류 목록)이라 그리드가 페이지를 넘기거나 검색할 때마다 매번 같이
 *    다시 받아올 필요가 없다("게시물 가져올 때 같이 가져올 수 있나"라는
 *    질문에 대한 답 — grid row 데이터 fetch에 얹는 대신, 페이지 로드
 *    시점에 한 번만 별도로 받아 재사용하는 쪽을 선택). AG Grid 내장
 *    `agSelectCellEditor`는 `cellEditorParams.values`(선택 가능한 값
 *    목록)와 `colDef.valueFormatter`(그 값을 무슨 글자로 보여줄지)를
 *    같이 쓰면 "값은 code, 표시는 authname" 매핑이 된다 — 별도 렌더러를
 *    새로 만들 필요가 없다(AG Grid 표준 기능).
 *  - **사용여부**: 체크박스로 표시/편집한다. DB 원본 값(1/0/'1'/'0'/true 등
 *    무엇이든)을 valueGetter가 boolean으로 바꿔 체크박스에 넘기고,
 *    valueSetter가 체크박스 결과를 항상 정수 1(체크)/0(해제)로 바꿔
 *    row 데이터에 다시 써넣는다 — 저장(POST) 시 그 값이 그대로 실려
 *    나가므로 "저장 시 1/0" 요구가 클라이언트 쪽에서 자동으로 충족된다.
 *  - **id**: 평범한 텍스트 컬럼이지만 `cellStyle`(함수형)을 얹어, 같은
 *    행의 사용여부가 체크된 경우에만 옅은 노란 배경(`#fff9c4`)+갈색 글자
 *    (`#795548`)로 강조한다(2026-08-25 추가 지시) — 행 전체가 아니라
 *    이 컬럼 하나만 강조하는 요구라 AGGrid.js의 행 단위
 *    conditionalFormats로는 표현이 안 되고, AG Grid의 컬럼별 cellStyle을
 *    직접 써야 했다.
 *
 * primaryKey 컬럼은 AGGrid 기본값 그대로 columns에 안 넣는다(aggrid.md
 * "primaryKey 컬럼은 columns에 안 넣는 게 기본" 참고) — 값은 행 데이터에
 * 항상 있어 저장/삭제/선택 식별에는 지장 없다.
 *
 * 컬럼 순서(2026-08-25 추가 지시)는 스키마가 주는 순서를 그대로 안 쓰고
 * "이름,id,pw,권한,사용여부,나머지...,메모" 고정 순서로 조립한다("메모"는
 * 나머지 전부보다도 뒤 — 그리드에서 가장 바깥/오른쪽 끝 컬럼이 되게 함).
 * 한때 "권한"
 * (콤보) 옆에 같은 필드를 읽기전용으로 한 번 더 보여주는 "권한code(표시)"
 * 컬럼(field는 같고 colId만 다른 별도 컬럼)도 있었으나, 콤보 하나로도
 * 저장까지 충분하다는 걸 확인한 뒤(콤보가 field를 직접 읽고 쓰므로 표시용
 * 컬럼 유무와 무관하게 동작함) 화면이 번잡하다는 이유로 제거함 — 필요하면
 * 언제든 같은 방식(같은 field, 별도 colId, editable:false)으로 다시 추가
 * 가능.
 *
 * tLogin엔 "권한코드"(콤보가 다루는 FK)와는 별개로 순수 텍스트 "권한"
 * 컬럼이 실제로 따로 있다(2026-08-25 추가 확인) — 콤보 헤더도 우연히
 * 같은 이름이라 "권한"이 두 번 보이는 혼동이 생겨, 이 텍스트 "권한"
 * 컬럼(정확히 필드명이 '권한'인 것)만 columns에서 아예 뺀다(PK와 동일한
 * 스킵 방식). fetch_로그인.php가 서버 응답 자체에서도 이 필드를 빼므로
 * 화면에 안 보이는 것과 서버가 안 보내는 것 둘 다 일치한다.
 *
 * **컬럼 정렬/콤마 포맷(2026-09-01 추가)**: AGGrid.js는 원래 컬럼 타입을
 * 몰라 값이 숫자처럼 보이면 우측정렬+천단위 콤마를 붙였는데, id처럼
 * 원래는 문자 필드인 컬럼에 숫자만 입력해도 그렇게 처리되는 오탐이
 * 실사용에서 확인됐다. 이 화면은 `Crud::init()`으로 이미 실제 스키마
 * 타입을 알고 있으므로, `b21_data_type_for()`가 각 컬럼의 SQL DATA_TYPE을
 * `dataType: 'string'|'number'`(AGGrid.js가 소비하는 힌트)로 매핑해
 * columns에 실어 보낸다 — 값 추측 대신 실제 타입을 그대로 강제한다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Crud', 'Str');

$connectionName = null; // config/db.php의 'driver' 기본 접속을 따른다
$loginGridDb = new DbContext($connectionName); // 컨테이너 페이지의 변수와 겹치지 않게 고유 이름
$tableName = 'tLogin';
$passwordPattern = '/pw|pass|암호|비밀번호/i';
// "권한코드"/"권한code"처럼 "권한" 뒤에 "코드"/"code"가 붙은 컬럼만 매치 —
// "권한"(권한명) 단독 컬럼이 따로 있다면 그건 대상이 아니다.
$authorityCodePattern = '/권한.*(코드|code)/i';
$usageFieldPattern = '/사용여부|사용유무/i';

$probe = new Crud($loginGridDb);
$probe->init($tableName);
$primaryKey = $probe->getPrimaryKey();
$fieldSchema = $probe->getSchema()['fields'];
$allFields = array_keys($fieldSchema);

/**
 * SQL DATA_TYPE 문자열을 AGGrid.js의 `dataType`('string'|'number') 힌트로
 * 매핑한다. AGGrid.js는 원래 컬럼 타입을 몰라 값 하나하나를 보고 숫자처럼
 * 보이면 우측정렬+콤마를 붙였는데, 이 화면은 이미 Crud::init()으로 실제
 * 스키마를 조회해둔 상태라 값을 추측할 필요가 없다 — 실사용 보고("id 같은
 * 문자 필드에 숫자를 입력하면 숫자처럼(우측정렬+콤마) 처리된다")로 확인된
 * 오탐을 이 스키마 정보로 원천 차단한다(2026-09-01 추가).
 */
function b21_data_type_for(string $sqlType): string
{
    static $numericTypes = [
        'int', 'bigint', 'smallint', 'tinyint',
        'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney',
    ];
    return in_array(strtolower($sqlType), $numericTypes, true) ? 'number' : 'string';
}

// 요청된 고정 순서(이름,id,pw,권한,사용여부,나머지...)를 만들기 위해,
// 먼저 필드들을 종류별로 한 번 분류한 뒤 원하는 순서대로 다시 나열한다.
$nameField = null;
$idField = null;
$passwordField = null;
$authorityField = null;
$usageField = null;
$memoField = null;
$restFields = [];

foreach ($allFields as $field) {
    if ($field === $primaryKey) {
        continue;
    }
    if ($field === '권한') {
        // 권한코드(콤보가 다루는 FK)와는 별개인 순수 텍스트 "권한" 컬럼 —
        // 콤보 헤더와 이름이 겹쳐 "권한"이 두 번 보이는 혼동을 만들어서
        // 감춘다(fetch_로그인.php가 서버 응답에서도 이미 뺌).
        continue;
    }
    if ($nameField === null && $field === '이름') {
        $nameField = $field;
        continue;
    }
    if ($idField === null && strcasecmp($field, 'id') === 0) {
        $idField = $field;
        continue;
    }
    if ($passwordField === null && preg_match($passwordPattern, $field) === 1) {
        $passwordField = $field;
        continue;
    }
    if ($authorityField === null && preg_match($authorityCodePattern, $field) === 1) {
        $authorityField = $field;
        continue;
    }
    if ($usageField === null && preg_match($usageFieldPattern, $field) === 1) {
        $usageField = $field;
        continue;
    }
    if ($memoField === null && $field === '메모') {
        // "메모"만 따로 빼뒀다가 맨 마지막에 붙인다(2026-08-25 추가 지시 —
        // 가장 바깥/오른쪽 끝 컬럼으로).
        $memoField = $field;
        continue;
    }
    $restFields[] = $field;
}

/** @var array<int, array{field: string, kind: string}> */
$orderedColumns = [];
if ($nameField !== null) {
    $orderedColumns[] = ['field' => $nameField, 'kind' => 'plain'];
}
if ($idField !== null) {
    // 사용여부가 체크된 행에서 이 컬럼만 강조(kind별 렌더링 참고).
    $orderedColumns[] = ['field' => $idField, 'kind' => 'id'];
}
if ($passwordField !== null) {
    $orderedColumns[] = ['field' => $passwordField, 'kind' => 'password'];
}
if ($authorityField !== null) {
    $orderedColumns[] = ['field' => $authorityField, 'kind' => 'authority_combo'];
}
if ($usageField !== null) {
    $orderedColumns[] = ['field' => $usageField, 'kind' => 'usage'];
}
foreach ($restFields as $field) {
    $orderedColumns[] = ['field' => $field, 'kind' => 'plain'];
}
if ($memoField !== null) {
    // 요청대로 나머지 전부보다 뒤, 즉 그리드에서 가장 바깥(오른쪽 끝) 컬럼.
    $orderedColumns[] = ['field' => $memoField, 'kind' => 'plain'];
}

// tAuth(권한 참조 테이블) — code/authname 두 컬럼만 필요. Crud의 커서
// 순회(selectRecords→moveFirst/eof/moveNext)로 전체를 읽는다(이 프로젝트가
// 이미 여러 화면에서 쓰는 방식, [[CRUD]] 참고). tAuth는 deco DB에 있다고
// 가정(tLogin과 같은 connectionName).
$authCrud = new Crud($loginGridDb);
$authCrud->init('tAuth');
$authCrud->selectRecords('');
$authOptions = [];
$authCrud->moveFirst();
while (!$authCrud->eof()) {
    $authOptions[] = [
        'code' => $authCrud->getValue('code'),
        'authname' => $authCrud->getValue('authname'),
    ];
    $authCrud->moveNext();
}

// "id" 컬럼의 조건부 강조(사용여부 체크 시)가 참조할 필드명 — 사용여부
// 필드가 없는 스키마라면(이론상) 항상 강조 없음으로 안전하게 폴백.
$usageFieldJson = $usageField !== null ? json_encode($usageField, JSON_UNESCAPED_UNICODE) : 'null';

$fetchUrl = APP_BASE_URL . '/basicdata/로그인/fetch_로그인.php';
?>
<style>
/* AGGrid.js 기본 CSS는 건드리지 않고, 이 그리드(#aggrid-로그인)의 특정
   컬럼 두 개만 cellClass로 좁혀서 조정한다(2026-08-25 추가 지시). */

/* 비밀번호 마스킹 — U+25CF(●)는 글자 자체가 커서 점이 과하게 크게
   보였다. 더 작은 점(U+2022 •)으로 바꾸고(valueFormatter, 아래 참고)
   글자 크기도 본문 기본값보다 살짝 줄여 적당한 크기로 맞춘다. */
#aggrid-로그인 .b21-pw-mask-cell {
    font-size: 0.72rem;
    letter-spacing: 1px;
}

/* 사용여부 체크박스 — AG Grid 기본 체크박스(agCheckboxCellRenderer/
   agCheckboxCellEditor)가 그리는 실제 <input type="checkbox">를 셀
   범위 안에서만 살짝 키운다. */
#aggrid-로그인 .b21-usage-cell input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}
</style>
<script>
// <input type="password">로 편집하는 커스텀 셀 에디터 — AG Grid Community의
// agTextCellEditor에는 입력 타입을 password로 바꿀 표준 옵션이 없어서,
// AG Grid Cell Editor Component 규약(init/getGui/afterGuiAttached/getValue)
// 만 따르는 최소 구현을 직접 둔다. 이 화면(로그인) 전용이라 AGGrid.js 본체가
// 아니라 여기(config_로그인.php)에 둔다.
function PasswordCellEditor() {}
PasswordCellEditor.prototype.init = function (params) {
    this.eInput = document.createElement('input');
    this.eInput.type = 'password';
    this.eInput.value = params.value == null ? '' : params.value;
    this.eInput.style.width = '100%';
    this.eInput.style.boxSizing = 'border-box';
};
PasswordCellEditor.prototype.getGui = function () { return this.eInput; };
PasswordCellEditor.prototype.afterGuiAttached = function () { this.eInput.focus(); this.eInput.select(); };
PasswordCellEditor.prototype.getValue = function () { return this.eInput.value; };
PasswordCellEditor.prototype.destroy = function () {};
PasswordCellEditor.prototype.isPopup = function () { return false; };

// tAuth 조회 결과 — 페이지 로드 시점에 config_로그인.php(PHP)가 이미 한 번
// 조회해 여기 값으로 심어뒀다(그리드가 매 페이지/검색마다 다시 받는
// fetch_로그인.php 응답과는 별개 경로). AUTH_CODES는 콤보의 선택 가능한
// 값(code) 목록, AUTH_LABEL_BY_CODE는 그 code를 authname으로 보여주기
// 위한 조회 맵 — 둘 다 "권한" 컬럼의 cellEditorParams/valueFormatter가 쓴다.
var AUTH_OPTIONS = <?= json_encode($authOptions, JSON_UNESCAPED_UNICODE) ?>;
var AUTH_CODES = AUTH_OPTIONS.map(function (opt) { return opt.code; });
var AUTH_LABEL_BY_CODE = {};
AUTH_OPTIONS.forEach(function (opt) { AUTH_LABEL_BY_CODE[String(opt.code)] = opt.authname; });

// 사용여부 판정 — 서버의 Str::toBool()(src/Str/Str.php)과 같은 규칙. 참/거짓
// 문자열 목록도 PHP 상수를 그대로 심어서, 로그인(Login::authentication)과
// 이 그리드의 체크박스가 서로 다르게 판정하는 일이 없게 한다.
var B21_TRUE_STRINGS = <?= json_encode(Str::TRUE_STRINGS) ?>;
function b21ToBool(v) {
    if (typeof v === 'boolean') { return v; }
    if (v === null || v === undefined) { return false; }
    if (typeof v === 'number') { return v !== 0; }
    var text = String(v).trim().toLowerCase();
    if (text === '') { return false; }
    if (!isNaN(Number(text))) { return Number(text) !== 0; }
    if (B21_TRUE_STRINGS.indexOf(text) >= 0) { return true; }
    return false; // FALSE_STRINGS와 그 밖의 값 — Str::toBool()의 기본값(false)과 동일
}

new AGGrid({
    gridId: '로그인',
    fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    primaryKey: <?= json_encode($primaryKey, JSON_UNESCAPED_UNICODE) ?>,
    // 아래 세 값은 AGGrid 공식 baseline(1_9/1_20)이 고른 예시값을 그대로
    // 따른 것 — 모듈 기본값이 아니라 화면이 선택한 값이지만, 특별한 이유가
    // 없으면 baseline을 그대로 복사해 시작한다는 관례를 그대로 지켰다.
    fetchMode: 'paged',
    pageSize: 100,
    autoFitBottomGap: 40,
    columns: [
<?php
$first = true;
foreach ($orderedColumns as $column) {
    $field = $column['field'];
    $kind = $column['kind'];
    $fieldJson = json_encode($field, JSON_UNESCAPED_UNICODE);

    if (!$first) {
        echo ",\n";
    }
    $first = false;

    // 실제 스키마 타입을 아는 컬럼은 값 기준 추측 대신 이 타입을 그대로
    // AGGrid.js에 넘겨 정렬/콤마 포맷을 강제한다(위 b21_data_type_for()
    // 참고) — id처럼 숫자만 입력해도 원래는 문자 필드인 컬럼이 콤마/우측
    // 정렬로 잘못 표시되던 문제를 여기서 막는다.
    $dataTypeJson = json_encode(
        b21_data_type_for($fieldSchema[$field]['type'] ?? ''),
        JSON_UNESCAPED_UNICODE
    );

    if ($kind === 'id') {
        // 사용여부가 체크(0이 아닌 값/true — Access의 -1 포함)된 행에서만 이 컬럼(id)을 옅은 노란
        // 배경 + 갈색 글자로 강조한다 — 행 전체가 아니라 이 컬럼 하나만
        // (2026-08-25 추가 지시). AGGrid.js의 conditionalFormats/dirty
        // 강조는 행(getRowStyle) 단위라 이 요구엔 안 맞아서, AG Grid의
        // 컬럼별 cellStyle(함수형)을 그대로 사용 — 체크박스 값 변경 시
        // AGGrid.js가 그 행을 redrawRows()로 다시 그리므로, cellStyle도
        // 매번 재평가되어 저장 전에도 즉시 반영된다. `dataType`은 cellStyle을
        // 이미 직접 지정한 컬럼이라도 콤마 포맷 여부에는 여전히 관여하므로
        // (2026-09-01 추가) — id에 숫자만 입력해도 콤마가 안 붙게 함께 준다.
        echo "        {\n";
        echo "            field: {$fieldJson}, headerName: {$fieldJson}, editable: true, dataType: {$dataTypeJson},\n";
        echo "            cellStyle: function (params) {\n";
        echo "                if (!params.data) { return null; }\n";
        echo "                var v = params.data[{$usageFieldJson}];\n";
        echo "                var isUsed = b21ToBool(v);\n";
        echo "                return isUsed ? { backgroundColor: '#fff9c4', color: '#795548' } : null;\n";
        echo "            }\n";
        echo '        }';
    } elseif ($kind === 'password') {
        echo "        {\n";
        echo "            field: {$fieldJson}, headerName: {$fieldJson}, editable: true, dataType: {$dataTypeJson},\n";
        echo "            cellEditor: PasswordCellEditor, cellClass: 'b21-pw-mask-cell',\n";
        echo "            valueFormatter: function (params) { return params.value ? '••••••••' : ''; }\n";
        echo '        }';
    } elseif ($kind === 'authority_combo') {
        // 콤보박스(편집 가능) — 값은 항상 tAuth.code, 화면 표시는
        // valueFormatter가 AUTH_LABEL_BY_CODE로 찾은 authname.
        // agSelectCellEditor는 AG Grid Community 내장 컴포넌트라 별도
        // 구현이 필요 없다.
        echo "        {\n";
        echo "            field: {$fieldJson}, headerName: '권한', editable: true, dataType: {$dataTypeJson},\n";
        echo "            cellEditor: 'agSelectCellEditor',\n";
        echo "            cellEditorParams: { values: AUTH_CODES },\n";
        echo "            valueFormatter: function (params) {\n";
        echo "                if (params.value == null || params.value === '') { return ''; }\n";
        echo "                var label = AUTH_LABEL_BY_CODE[String(params.value)];\n";
        echo "                return label != null ? label : String(params.value);\n";
        echo "            }\n";
        echo '        }';
    } elseif ($kind === 'usage') {
        echo "        {\n";
        echo "            field: {$fieldJson}, headerName: {$fieldJson}, editable: true,\n";
        echo "            cellRenderer: 'agCheckboxCellRenderer', cellEditor: 'agCheckboxCellEditor', cellClass: 'b21-usage-cell',\n";
        echo "            valueGetter: function (params) {\n";
        echo "                if (!params.data) { return false; }\n";
        echo "                var v = params.data[{$fieldJson}];\n";
        echo "                return b21ToBool(v);\n";
        echo "            },\n";
        echo "            valueSetter: function (params) {\n";
        echo "                params.data[{$fieldJson}] = params.newValue ? 1 : 0;\n";
        echo "                return true;\n";
        echo "            },\n";
        echo "            valueFormatter: function (params) { return params.value ? '사용' : '미사용'; }\n";
        echo '        }';
    } else {
        echo '        ' . json_encode(
            ['field' => $field, 'headerName' => $field, 'editable' => true, 'dataType' => b21_data_type_for($fieldSchema[$field]['type'] ?? '')],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
?>

    ]
});
</script>
