<?php
declare(strict_types=1);

/**
 * [AGGrid 모듈용 프론트엔드 config — "권한"(tAuth, MSSQL deco DB) 그리드]
 * 바탕화면 deco권한.png(Access "권한" 편집 폼)를 웹으로 재현한다 —
 * 사용자 설명: 메뉴권한은 tAuth_ribbon에서, 일반권한은 tAuth_add에서 각각
 * 후보 목록을 가져와 체크박스로 고르고, 저장 시 체크된 항목들의
 * authstring을 전부 이어붙여 tAuth.authstring 하나에 저장한다.
 *
 * 이 화면은 "aggrid 기본형"(1_9/1_20/2_1과 동일 fetchMode/pageSize/
 * autoFitBottomGap)이되, **그리드 자체의 인라인 편집/행추가는 쓰지
 * 않는다** — AGGrid.js가 항상 그리는 "편집"/"+ 행 추가" 버튼을
 * CSS(`display:none`, 아래 <style> 참고)로 숨기고, 대신 이 화면이 직접
 * 그리는 "권한추가" 버튼 + 커스텀 모달로 추가/수정/삭제를 전부 처리한다.
 * "편집" 버튼이 없으면 grid.mode가 영원히 'view'로 고정돼(다른 어떤
 * 코드도 setMode(true)를 안 부름) 인라인 편집 자체가 애초에 발동할 방법이
 * 없다 — 시각적으로 숨기는 것과 기능을 끄는 것이 동시에 된다.
 *
 * 모달은 1_7의 `#b17-modal`(board-modal.js와 무관한, 이 화면 전용의
 * 가벼운 커스텀 모달)과 같은 패턴 — AGGrid.js 본체를 전혀 안 건드리고
 * 이 파일 안에서 완결된다. 저장/삭제는 ActionBulk의 {rows, deletedCodes}
 * 계약을 그대로 재사용(레코드 1건짜리 배열만 보낸다는 점만 다름,
 * fetch_권한.php 참고) — 그리드가 아니라 모달이 fetch()를 직접 호출한다.
 *
 * tAuth_ribbon/tAuth_add는 MS Access에서 직접 입력/관리되는 참조
 * 테이블이라 이 화면은 읽기만 한다(페이지가 열릴 때 한 번, 2_1이
 * tAuth를 콤보 원본으로 미리 읽어둔 것과 동일한 방식) — 그리드 데이터
 * fetch(fetch_권한.php)와는 완전히 별개 경로.
 *
 * **체크박스 → authstring 결합 규칙은 스크린샷에서 역산한 추정치다**
 * (실제 DB로 확인 못함): 각 항목의 authstring을 양쪽 `;`로 감싼 뒤
 * (`;권한명;`), `'@'`로 이어붙이되 맨 앞에도 `;`를 하나 더 둔다
 * (`';@;' + items.join(';@;') + ';@'`) — 그 결과 문자열 안의 모든 `@`가
 * 위치(맨 앞/중간/맨 뒤)와 무관하게 항상 `;@` 형태로 나타난다(예: 항목
 * 2개면 `;@;A;@;B;@`). deco권한.png 하단의 결합 결과 미리보기 텍스트가
 * `@;tabBar;...`로 시작하는 것에서 착안했으나, **원래 시도(`'@;' +
 * items.join('@;')`)는 항목이 2개 이상일 때 마지막이 아닌 항목 뒤에
 * `;`가 아니라 `@`만 붙어 `Auth::hasAuthority()`의 `;코드;` 부분 문자열
 * 검색에 안 걸리던 버그**(2026-08-26 발견)가 있어, 몇 번째 항목이든
 * 항상 `;권한명;` 형태로 나타나도록 이 형태로 확정했다. 기존 저장된
 * 값을 불러와 체크 상태를 복원할 때는 여전히 단순 부분 문자열
 * (`indexOf`)로 판단한다 — 이건 별개의 알려진 한계로 남겨둠
 * (md/2026-08-26-tlogin-로그인-Login-Auth.md 참고).
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Crud');

$connectionName = null; // config/db.php의 'driver' 기본 접속을 따른다
$authGridDb = new DbContext($connectionName); // 컨테이너 페이지의 변수와 겹치지 않게 고유 이름

// --- tAuth 스키마 조회 — authname/memo/authstring 실제 필드명 확정 ----
// code/authname/authstring은 사용자가 명시적으로 알려준 이름을 그대로
// 믿는다("code는 권한code, authname이 권한명", "authstring 필드"). 메모만
// 정확한 필드명을 안 알려줘서 이름 패턴으로 추정한다(2_1의 다른 필드들과
// 동일한 한계 — 실제와 다르면 이 정규식만 고치면 됨).
$authProbe = new Crud($authGridDb);
$authProbe->init('tAuth');
$primaryKey = $authProbe->getPrimaryKey();
$authFields = array_keys($authProbe->getSchema()['fields']);

$authnameField = null;
$authstringField = null;
$memoField = null;
foreach ($authFields as $field) {
    if ($field === $primaryKey) {
        continue;
    }
    if ($authnameField === null && strcasecmp($field, 'authname') === 0) {
        $authnameField = $field;
        continue;
    }
    if ($authstringField === null && strcasecmp($field, 'authstring') === 0) {
        $authstringField = $field;
        continue;
    }
    if ($memoField === null && preg_match('/메모|memo/i', $field) === 1) {
        $memoField = $field;
        continue;
    }
}
// 못 찾은 경우를 대비한 안전한 기본값(존재하지 않는 필드명을 그대로 써도
// "빈 값 취급"이 될 뿐 에러로 죽지는 않게) — 실제로는 위 세 값이 전부
// 정상적으로 채워질 것으로 예상.
$authnameField = $authnameField ?? 'authname';
$authstringField = $authstringField ?? 'authstring';
$memoField = $memoField ?? 'memo';

/**
 * tAuth_ribbon/tAuth_add 공통 조회 — "명칭"에 해당하는 컬럼과(있으면)
 * "authstring" 컬럼을 이름 패턴으로 찾아 {label, authstring} 배열로
 * 반환한다. authstring 컬럼이 따로 없으면(예: tAuth_add처럼 이미지에
 * authstring 컬럼이 안 보이는 경우) 명칭 자체를 authstring으로 쓴다.
 *
 * 2026-08-25 수정 — 사용자가 "메뉴권한/일반권한 항목이 안 나온다"고
 * 보고해 두 가지를 보강함:
 * 1) 명칭 패턴(`/명칭|name/i`)에 안 걸리면 "PK가 아닌 첫 번째 필드"를
 *    명칭으로 쓰는 안전한 대체 규칙을 추가(원본 Access 그리드의 "명칭"은
 *    화면 캡션일 뿐 실제 DB 필드명과 다를 수 있음).
 * 2) 그래도 못 찾거나 행이 0개인 경우를 구분할 수 있도록, 실제로 조회된
 *    필드 목록/행 수/최종 선택된 명칭 필드를 함께 반환한다 — 화면에서
 *    "항목 없음"만 보이면 원인을 알 수 없었는데, 이제 그 안에 진단 정보를
 *    같이 보여줘서 실제 DB 확인 없이도 원인을 좁힐 수 있게 함.
 *
 * @return array{items: array<int, array{label: string, authstring: string}>, fields: array<int, string>, rowCount: int, labelField: ?string}
 */
$loadAuthCandidates = function (string $tableName) use ($authGridDb): array {
    $crud = new Crud($authGridDb);
    $crud->init($tableName);
    $pk = $crud->getPrimaryKey();
    $fields = array_keys($crud->getSchema()['fields']);

    $labelField = null;
    $authstringField = null;
    foreach ($fields as $field) {
        if ($field === $pk) {
            continue;
        }
        if ($labelField === null && preg_match('/명칭|이름|name|label|title/i', $field) === 1) {
            $labelField = $field;
        }
        if ($authstringField === null && preg_match('/auth.?string/i', $field) === 1) {
            $authstringField = $field;
        }
    }
    if ($labelField === null) {
        // 이름 패턴으로 못 찾으면 PK 다음 첫 번째 필드를 명칭으로 간주
        // (이 프로젝트 테이블들의 "code, 명칭, ..." 관례를 따르는 대체 규칙).
        foreach ($fields as $field) {
            if ($field !== $pk) {
                $labelField = $field;
                break;
            }
        }
    }

    $items = [];
    $rowCount = 0;
    if ($labelField !== null) {
        $crud->selectRecords('');
        $crud->moveFirst();
        while (!$crud->eof()) {
            $rowCount++;
            $label = (string) $crud->getValue($labelField);
            $authstring = $authstringField !== null ? (string) $crud->getValue($authstringField) : $label;
            $items[] = ['label' => $label, 'authstring' => $authstring];
            $crud->moveNext();
        }
    }

    return ['items' => $items, 'fields' => $fields, 'rowCount' => $rowCount, 'labelField' => $labelField];
};

$ribbonResult = $loadAuthCandidates('tAuth_ribbon');
$addResult = $loadAuthCandidates('tAuth_add');
$ribbonItems = $ribbonResult['items'];
$addItems = $addResult['items'];

$fetchUrl = BOARD_BASE_URL . '/2_2/fetch_권한.php';
?>
<style>
/* "편집"/"+ 행 추가"는 이 화면에서 안 쓴다(모달로 대신) — 항상 숨김. */
#aggrid-권한 .ag-shell-mode-btn,
#aggrid-권한 .ag-shell-add-btn {
    display: none;
}

/* "권한추가" 버튼 — 별도 줄이 아니라 AGGrid 툴바(검색어와 같은 행)
   왼쪽 그룹(.ag-shell-toolbar-left, "편집"/"+ 행 추가"가 원래 있던 자리
   — 이 화면에선 둘 다 CSS로 숨겨 비어있음)에 직접 꽂아 넣는다(아래 JS
   참고). 패딩/글자크기는 AGGrid.js의 `.ag-shell-toolbar button`(공통
   베이스) 수치를 그대로 옮겨왔다. 배색은 2026-08-25엔 "+ 행 추가"와
   같은 초록이었으나, 2026-08-26 "AGGrid와 동일한 파란색 계열로" 지시로
   AGGrid.js가 그때부터 쓰는 저장/"+ 행 추가" 파랑(`#bfdbfe`/`#1e293b`)
   으로 다시 맞춤. */
.b22-add-btn {
    padding: 4px 12px; border: none; border-radius: 4px;
    background: #bfdbfe; color: #1e293b; font-weight: 600; font-size: 0.82rem;
    cursor: pointer; transition: background-color 0.15s ease;
}
.b22-add-btn:hover { background: #93c5fd; }

/* 모달 — board-modal.js와 무관한 이 화면 전용의 가벼운 커스텀 모달
   (1_7의 #b17-modal과 동일한 원칙). */
.b22-modal-overlay {
    position: fixed; inset: 0; background: rgba(0, 0, 0, 0.4);
    display: flex; align-items: center; justify-content: center; z-index: 1000;
}
.b22-modal-overlay[hidden] { display: none; }
.b22-modal-box {
    background: #fff; border-radius: 8px; padding: 20px 24px;
    width: 720px; max-width: 92vw; max-height: 88vh; overflow-y: auto;
    box-shadow: 0 8px 28px rgba(0, 0, 0, 0.25);
}
.b22-modal-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 14px;
}
.b22-modal-header h3 { margin: 0; font-size: 1.05rem; }
.b22-modal-close-btn {
    border: none; background: transparent; font-size: 1.3rem; line-height: 1;
    cursor: pointer; color: #6b7280;
}
.b22-modal-field { margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
.b22-modal-field label { width: 70px; flex-shrink: 0; font-size: 0.85rem; color: #374151; }
.b22-modal-field input[type="text"] {
    flex: 1; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.85rem;
}
.b22-modal-lists { display: flex; gap: 16px; margin: 14px 0; }
.b22-modal-list-col { flex: 1; min-width: 0; }
.b22-modal-list-col h4 {
    margin: 0 0 6px; font-size: 0.88rem; background: #dde6f0; padding: 6px 8px; border-radius: 4px;
}
.b22-check-list {
    border: 1px solid #e5e7eb; border-radius: 4px; max-height: 320px; overflow-y: auto; padding: 4px 0;
}
.b22-check-item {
    display: block; padding: 4px 10px; font-size: 0.85rem; cursor: pointer;
}
.b22-check-item:hover { background: #f3f4f6; }
.b22-check-item input { margin-right: 6px; }
/* 저장/취소/삭제 버튼 — 2026-08-25, "권한추가는 녹색바탕+흰글씨, 저장도
   파란 바탕+흰 글자로" 요청에 따라 진한 단색 배경 + 흰 글자 톤으로
   맞춤(삭제는 이미 같은 톤 — 빨간 바탕+흰 글자). 취소는 지시가 없어
   기존 무채색(AGGrid `.ag-shell-cancel-btn`과 같은 톤) 유지.
   각 버튼 선택자를 `button.b22-...`(class+타입)로 써서
   `.b22-modal-footer button`(공통 베이스, 역시 class+타입이라 특이도가
   같음)과 동일 특이도로 만들고 이 규칙들이 파일에서 더 뒤에 오므로
   확실히 이기게 했다 — 특이도가 낮은 `.b22-modal-save-btn`(class만)
   그대로였다면 공통 베이스가 되레 font-weight 등 일부 속성을 덮어써버려
   "바꿨는데 안 바뀐 것처럼 보이는" 원인이 될 수 있었음. */
.b22-modal-footer { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; }
.b22-modal-footer button {
    padding: 4px 12px; border: none; border-radius: 4px; font-size: 0.82rem;
    font-weight: 500; cursor: pointer; transition: background-color 0.15s ease;
}
.b22-modal-footer button.b22-modal-save-btn { background: #2563eb; color: #fff; font-weight: 600; }
.b22-modal-footer button.b22-modal-save-btn:hover { background: #1d4ed8; }
.b22-modal-footer button.b22-modal-cancel-btn { background: #f3f4f6; color: #374151; }
.b22-modal-footer button.b22-modal-cancel-btn:hover { background: #e5e7eb; }
.b22-modal-footer button.b22-modal-delete-btn {
    background: #e0736c; color: #fff; border-radius: 6px; padding: 4px 10px; font-size: 0.78rem;
}
.b22-modal-footer button.b22-modal-delete-btn:hover { background: #c85950; }
</style>
<div class="b22-modal-overlay" id="b22-modal" hidden>
    <div class="b22-modal-box">
        <div class="b22-modal-header">
            <h3 id="b22-modal-title">권한 추가</h3>
            <button type="button" class="b22-modal-close-btn" id="b22-modal-close-btn">&times;</button>
        </div>
        <input type="hidden" id="b22-modal-code">
        <div class="b22-modal-field">
            <label for="b22-modal-authname">권한명</label>
            <input type="text" id="b22-modal-authname">
        </div>
        <div class="b22-modal-field">
            <label for="b22-modal-memo">메모</label>
            <input type="text" id="b22-modal-memo">
        </div>
        <div class="b22-modal-lists">
            <div class="b22-modal-list-col">
                <h4>메뉴권한</h4>
                <div class="b22-check-list" id="b22-ribbon-list"></div>
            </div>
            <div class="b22-modal-list-col">
                <h4>일반권한</h4>
                <div class="b22-check-list" id="b22-add-list"></div>
            </div>
        </div>
        <div class="b22-modal-footer">
            <button type="button" class="b22-modal-delete-btn" id="b22-modal-delete-btn">삭제</button>
            <button type="button" class="b22-modal-cancel-btn" id="b22-modal-cancel-btn">취소</button>
            <button type="button" class="b22-modal-save-btn" id="b22-modal-save-btn">저장</button>
        </div>
    </div>
</div>
<script>
// tAuth_ribbon/tAuth_add — 페이지 로드 시점에 config_권한.php(PHP)가 이미
// 한 번 조회해 심어둔 후보 목록(2_1의 AUTH_OPTIONS와 동일한 원칙 —
// 그리드 데이터 fetch와는 별개 경로, 정적 참조 테이블이라 매번 다시 안
// 받아온다).
var B22_RIBBON_ITEMS = <?= json_encode($ribbonItems, JSON_UNESCAPED_UNICODE) ?>;
var B22_ADD_ITEMS = <?= json_encode($addItems, JSON_UNESCAPED_UNICODE) ?>;
// 항목이 비어 보일 때 원인을 화면에서 바로 확인하기 위한 진단 정보
// (tAuth_ribbon/tAuth_add에서 실제로 발견된 컬럼 목록, 행 수, 명칭으로
// 채택한 필드) — DB에 직접 접속할 방법이 없어 추가함.
var B22_RIBBON_DEBUG = <?= json_encode(['fields' => $ribbonResult['fields'], 'rowCount' => $ribbonResult['rowCount'], 'labelField' => $ribbonResult['labelField']], JSON_UNESCAPED_UNICODE) ?>;
var B22_ADD_DEBUG = <?= json_encode(['fields' => $addResult['fields'], 'rowCount' => $addResult['rowCount'], 'labelField' => $addResult['labelField']], JSON_UNESCAPED_UNICODE) ?>;
var B22_PK_FIELD = <?= json_encode($primaryKey, JSON_UNESCAPED_UNICODE) ?>;
var B22_AUTHNAME_FIELD = <?= json_encode($authnameField, JSON_UNESCAPED_UNICODE) ?>;
var B22_MEMO_FIELD = <?= json_encode($memoField, JSON_UNESCAPED_UNICODE) ?>;
var B22_AUTHSTRING_FIELD = <?= json_encode($authstringField, JSON_UNESCAPED_UNICODE) ?>;
var B22_FETCH_URL = <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

function b22Escape(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// items의 각 authstring이 existingAuthstring 안에 부분 문자열로 있으면
// 체크된 것으로 본다(정확한 결합 구분자를 실측 못해 substring 포함
// 여부로 판정 — 클래스 상단 docblock의 "체크박스 → authstring 결합
// 규칙" 참고).
// items가 비어 있을 때 "항목 없음"만 보여주면 원인(테이블이 실제로
// 비어있는지, 명칭 컬럼을 못 찾았는지)을 알 수 없어 debugInfo를 함께
// 표시한다 — 실제 DB 확인 없이도 이 메시지만 보고 원인을 좁힐 수 있게
// 하기 위함(2026-08-25, "항목이 안 나온다" 보고에 대응).
function b22RenderCheckList(containerId, items, existingAuthstring, debugInfo) {
    if (items.length === 0) {
        var msg = '항목 없음';
        if (debugInfo) {
            msg += ' (테이블 행 수: ' + debugInfo.rowCount
                + ', 명칭으로 쓴 컬럼: ' + (debugInfo.labelField || '없음')
                + ', 발견된 전체 컬럼: ' + (debugInfo.fields.join(', ') || '없음') + ')';
        }
        document.getElementById(containerId).innerHTML = '<div style="padding:8px;color:#9ca3af;font-size:0.78rem;line-height:1.5;">' + b22Escape(msg) + '</div>';
        return;
    }
    var html = items.map(function (item, idx) {
        var checked = !!existingAuthstring && existingAuthstring.indexOf(item.authstring) !== -1;
        return '<label class="b22-check-item">'
            + '<input type="checkbox" data-authstring="' + b22Escape(item.authstring) + '"' + (checked ? ' checked' : '') + '>'
            + b22Escape(item.label) + '</label>';
    }).join('');
    document.getElementById(containerId).innerHTML = html;
}

function b22OpenModal(values, key) {
    document.getElementById('b22-modal-title').textContent = key ? '권한 수정' : '권한 추가';
    document.getElementById('b22-modal-code').value = key || '';
    document.getElementById('b22-modal-authname').value = values ? (values[B22_AUTHNAME_FIELD] || '') : '';
    document.getElementById('b22-modal-memo').value = values ? (values[B22_MEMO_FIELD] || '') : '';
    var existingAuthstring = values ? (values[B22_AUTHSTRING_FIELD] || '') : '';
    b22RenderCheckList('b22-ribbon-list', B22_RIBBON_ITEMS, existingAuthstring, B22_RIBBON_DEBUG);
    b22RenderCheckList('b22-add-list', B22_ADD_ITEMS, existingAuthstring, B22_ADD_DEBUG);
    document.getElementById('b22-modal-delete-btn').style.display = key ? '' : 'none';
    document.getElementById('b22-modal').removeAttribute('hidden');
}

function b22CloseModal() {
    document.getElementById('b22-modal').setAttribute('hidden', 'hidden');
}

function b22CollectCheckedAuthstrings() {
    var checked = [];
    document.querySelectorAll('#b22-ribbon-list input:checked, #b22-add-list input:checked').forEach(function (el) {
        checked.push(el.getAttribute('data-authstring'));
    });
    return checked;
}

function b22Save() {
    var code = document.getElementById('b22-modal-code').value;
    var row = {};
    row[B22_AUTHNAME_FIELD] = document.getElementById('b22-modal-authname').value;
    row[B22_MEMO_FIELD] = document.getElementById('b22-modal-memo').value;
    var checkedList = b22CollectCheckedAuthstrings();
    // 각 항목을 양쪽 ';'로 감싸(';권한명;') 이어붙인다 — 몇 번째 항목이든
    // 항상 ";권한명;" 형태로 문자열 안에 나타나야 Auth::hasAuthority()의
    // 부분 문자열 검색이 정확히 매치된다(위 클래스 상단 docblock 참고).
    row[B22_AUTHSTRING_FIELD] = '';
    if (checkedList.length > 0) {
        // 맨 앞의 '@'도 예외 없이 ';@'가 되도록 앞에 ';'를 하나 더
        // 붙인다 — 이러면 문자열 안의 모든 '@'가 항상 ';@' 형태로
        // 나타나(맨 앞/중간/맨 뒤 구분 없이) 일관된다.
        row[B22_AUTHSTRING_FIELD] = ';@;' + checkedList.join(';@;') + ';@';
    }
    if (code) {
        row[B22_PK_FIELD] = code;
    }
    fetch(B22_FETCH_URL + '?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ rows: [row], deletedCodes: [] }),
    })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            var result = (data.results || [])[0];
            if (result && result.success) {
                b22CloseModal();
                AGGrid.instance('권한').requery();
            } else {
                window.alert('저장 실패: ' + (result && result.message ? result.message : '알 수 없는 오류'));
            }
        })
        .catch(function () {
            window.alert('저장 중 오류가 발생했습니다.');
        });
}

function b22Delete() {
    var code = document.getElementById('b22-modal-code').value;
    if (!code || !window.confirm('이 권한을 삭제하시겠습니까?')) {
        return;
    }
    fetch(B22_FETCH_URL + '?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ rows: [], deletedCodes: [code] }),
    })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            var result = (data.deleteResults || [])[0];
            if (result && result.success) {
                b22CloseModal();
                AGGrid.instance('권한').requery();
            } else {
                window.alert('삭제 실패: ' + (result && result.message ? result.message : '알 수 없는 오류'));
            }
        })
        .catch(function () {
            window.alert('삭제 중 오류가 발생했습니다.');
        });
}

document.getElementById('b22-modal-close-btn').addEventListener('click', b22CloseModal);
document.getElementById('b22-modal-cancel-btn').addEventListener('click', b22CloseModal);
document.getElementById('b22-modal-save-btn').addEventListener('click', b22Save);
document.getElementById('b22-modal-delete-btn').addEventListener('click', b22Delete);

new AGGrid({
    gridId: '권한',
    fetchUrl: B22_FETCH_URL,
    primaryKey: B22_PK_FIELD,
    // baseline(1_9/1_20/2_1)과 동일한 값 그대로.
    fetchMode: 'paged',
    pageSize: 100,
    autoFitBottomGap: 40,
    // view 모드에서만 호출됨(이 화면은 항상 view 고정) — 더블클릭한 행을
    // 그대로 수정 모달로 연다.
    onRowDblClick: function (values, key) { b22OpenModal(values, key); },
    columns: [
        { field: B22_AUTHNAME_FIELD, headerName: '권한명', editable: false },
        { field: B22_MEMO_FIELD, headerName: '메모', editable: false }
    ],
    // "+ 권한추가" 버튼을 별도 줄로 그리드 위에 얹지 않고, AGGrid 자체
    // 툴바(검색어와 같은 행)의 왼쪽 그룹(.ag-shell-toolbar-left, 원래
    // "편집"/"+ 행 추가"가 있던 자리 — 이 화면은 둘 다 CSS로 숨겨서
    // 비어있음)에 직접 꽂아 넣는다. `new AGGrid(...)` 생성자는 AG Grid
    // 라이브러리를 비동기로 로드한 뒤에야 툴바 DOM을 만들기 때문에
    // (`new AGGrid()` 바로 다음 줄에서 DOM에 접근하면 아직 없음 —
    // 캐시돼 있어도 .then()은 항상 마이크로태스크라 한 박자 늦음),
    // 실제로 DOM 구성이 끝난 뒤 호출되는 `onReady` 콜백에서 삽입해야
    // 안전하다(2026-08-25, "화면에서 안보임" 보고로 발견 — 이전엔
    // 생성자가 동기적으로 툴바를 그린다고 잘못 가정했었음).
    onReady: function () {
        var toolbarLeft = document.querySelector('#aggrid-권한-toolbar .ag-shell-toolbar-left');
        toolbarLeft.insertAdjacentHTML('afterbegin', '<button type="button" class="b22-add-btn" id="b22-add-btn">+ 권한추가</button>');
        document.getElementById('b22-add-btn').addEventListener('click', function () { b22OpenModal(null, null); });
    }
});
</script>
