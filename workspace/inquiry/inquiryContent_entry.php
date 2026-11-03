<?php
declare(strict_types=1);

/**
 * [문의확인 화면 — 문의 한 건(?code=<t문의.code>)의 상세]
 * 구성: inquiryContent_entry.php(이 파일 — 입력폼 amd) + inquiryContent_fetch.php(CrudJs 계약)
 * + 탭마다 페이지 하나씩(inquiryContent_<탭>_entry.php / _fetch.php).
 * 메뉴에는 없고, 문의 목록(inquiry_entry.php)에서 행을 처음 더블클릭하면 index의
 * setContent()가 "문의확인" 탭으로 이 페이지를 연다.
 *
 * 화면 재사용 — 한 번 뜬 화면은 다시 열지 않는다. 이미 떠 있으면 목록의 더블클릭/신규추가는
 * action('openInquiry', {code})로 온다. 입력폼(crudbuttons/CrudJs — 스키마는 처음 한 번만
 * 받는다), 하위폼 iframe, talk을 그대로 두고 code만 바꾼다(icApplyCode):
 *  - code: 입력폼은 selectRecord, 하위폼은 각자 action('setParent', {code}), talk은 다시 init.
 *  - new: 입력폼은 신규입력(clear), 하위폼 iframe과 talk은 숨긴다(visible=false).
 *  - index 탭은 제목만 바꾼다(action('setTabTitle') — iframe을 다시 불러오지 않는다).
 *
 * 입력폼은 crudbuttons 모듈을 쓴다(src/Model/Crud/crudbuttons/crudbuttons.md):
 *  - 컨트롤 id = t문의 필드명. 값 채우기/모으기/저장/삭제는 아래 crudbuttons iframe
 *    안의 CrudJs가 한다. 이 페이지는 필드 배치와 이벤트(업무 규칙)만 정의한다.
 *  - 저장 전: 고객사/고객사직원(이름 사본)을 콤보의 표시 글자로 채운다(beforeCrudSave).
 *  - 신규를 처음 저장하면 화면을 새 code로 바꾼다(afterCrudSave → icApplyCode).
 *  - 신규입력 버튼: 폼을 비우고 하위폼/talk을 숨긴다(afterCrudClear → icApplyCode).
 *  - 삭제: crudbuttons 확인창 뒤, 딸린 자료가 있으면 건수를 보여주고 한 번 더 묻는다
 *    (beforeCrudDelete). 지우면 문의 목록으로 옮기고 이 탭을 닫는다(afterCrudDelete).
 *  - 창닫기: crudbuttons가 index에 'closed'를 보고하면 index가 이 탭을 닫는다.
 *
 * 배치(바탕화면 문의확인.png):
 *  - 좌측 상단: t문의 입력폼 + crudbuttons(저장/삭제/신규입력/창닫기)
 *  - 우측 상단: "문의내용" 탭 — iframe(inquiryContent_문의내용_entry.php)
 *  - 하단: 탭 5개 — 각각 iframe(inquiryContent_수주/발주/수주히스토리/
 *    발주히스토리/견적_entry.php). 처음 누를 때 불러온다(첫 탭 수주는 바로).
 *  - 오른쪽: talk 모듈(ttalk, type = TALK_TYPE, parentcode = code)
 *  - 고객사 콤보 옆 찾기 버튼 → 고객사 검색 팝업(모달 안 iframe,
 *    inquiryContent_고객사검색_entry.php). 더블클릭하면 action('setCustomer')로
 *    고객사code/고객사가 입력폼에 들어온다.
 *  탭 페이지는 모두 ?code=로 이 문의 code를 받는다.
 *
 * 페이지 함수: action(pcmd, pparams, psender) — 종류별 action_xxx로 나눠 넘긴다.
 *  getValue(pfield) / setValue(pfield, pvalue) — 문의 입력폼의 crud.getValue/setValue로 이어진다.
 *  - 'requery' — index가 탭을 옮길 때 부른다. 입력폼에 저장 안 한 값이 있으면 건너뛴다.
 *  - 'selectRecord_문의내용' {code} — 문의 탭 더블클릭. 문의내역 팝업(inquiryContent_문의내용_amd.php,
 *    비모달, 화면 오른쪽)을 띄우거나 떠 있는 팝업에 그 행을 보여준다(팝업은 다시 열지 않는다).
 *    팝업이 저장/삭제를 보고하면(action('amdReport')) 이 화면을 requery한다 — 저장이면
 *    action('requery', {code})로 저장한 문의내역 code를 넘겨, 문의 탭 그리드의 커서를 그 행에 맞춘다.
 *  - 'requery' {code?} — code(문의내역 code)를 주면 문의 탭이 다시 읽은 뒤 그 행으로 커서를 옮긴다.
 *    다른 문의/신규로 바뀌면 팝업을 닫는다.
 *  - 'openModelSearch' {keyword} — 문의내역 팝업의 모델 찾기. 모델 검색(tb제품)을 이 화면의
 *    모달(고객사 검색과 같은 크기)로 띄우고, 고른 행('setModel' {row})은 그 문의내역 팝업의 action('setModel')로 넘긴다.
 *    'closeModelSearch' — 닫기(모델 검색 화면에서 Esc).
 *  - 'openInquiry' {code} — 떠 있는 화면에 다른 문의/신규('new')를 연다
 *    → 'opened' / 'canceled' / 'unsupported'(아직 준비 전 — 부른 쪽이 새로 연다).
 *  - 'close' / 'selectRecord' / 'isDirty' — 버튼 iframe에 맡긴다.
 *  - 'setCustomer' {pcode, pname} / 'closeCustomerSearch' — 고객사 검색 팝업이 부른다.
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

/**
 * ttalk.type — 문의 대화의 종류. Access 원본과 같은 'test'
 * (mTalk.init(Me.subtalk.Form, "test", Me.code, ...)).
 * inquiryContent_fetch.php의 TALK_TYPE(문의 삭제 때 함께 지울 대화)과 같아야 한다.
 */
const TALK_TYPE = 'test';

// 로그인 정보 — talk과 신규 문의의 멤버 표시에 쓴다. 값이 없으면 code는 '0',
// 이름은 '무명'(Access의 nz_와 같음, talk 전용).
$loginCode = (string) Login::getValue('code');
$loginName = (string) Login::getValue('이름');
$talkLoginCode = $loginCode === '' ? '0' : $loginCode;
$talkLoginName = $loginName === '' ? '무명' : $loginName;

$code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
// code=new — 신규 문의. 입력폼/탭/talk 모두 빈 칸으로 열고, 저장하면 insert한 뒤
// 같은 "문의확인" 탭을 새 code로 다시 연다(afterCrudSave).
$isNew = $code === 'new';

$connectionName = null; // config/db.php의 'driver' 기본 접속을 따른다
$db = new DbContext($connectionName);

// 레코드는 콤보 원본(지금 값이 목록에 없을 때 넣어 둘 항목)과 존재 확인에만 쓴다.
// 입력칸 값은 crudbuttons(CrudJs)가 채운다.
$record = null;
$loadError = '';
if ($isNew) {
    $record = [];
} elseif ($code === '' || !ctype_digit($code)) {
    $loadError = '문의 code가 없습니다. 문의 목록에서 행을 더블클릭해 여세요.';
} else {
    $crud = new Crud($db);
    $crud->init('t문의');
    if ($crud->selectRecord($code)) {
        $record = $crud->getAllValues();
    } else {
        $loadError = '문의(' . $code . ')를 찾을 수 없습니다.';
    }
}

// 상태 콤보 원본 — inquiry_entry.php와 같은 tb상태_문의(code 오름차순, 빈 레코드 포함).
$statusOptions = [];
try {
    $statusStmt = $db->pdo()->query('SELECT tb상태_문의.상태 FROM tb상태_문의 ORDER BY tb상태_문의.code ASC');
    foreach ($statusStmt->fetchAll(PDO::FETCH_COLUMN) as $status) {
        $statusOptions[] = $status === null ? '' : (string) $status;
    }
} catch (Throwable $e) {
    // 콤보만 비고 화면은 뜬다.
}
// 지금 값이 목록에 없어도 사라지지 않게 넣어 둔다.
$currentStatus = $record !== null ? (string) ($record['상태'] ?? '') : '';
if ($currentStatus !== '' && !in_array($currentStatus, $statusOptions, true)) {
    array_unshift($statusOptions, $currentStatus);
}

// 고객사 콤보 원본 — tb고객사(값 code, 표시 회사명, 회사명 순). t문의에는 고객사code와
// 고객사(그때의 회사명 사본)가 같이 저장된다 — 저장할 때 고른 회사명을 함께 넣는다.
$customerOptions = []; // [[code, 회사명], ...]
try {
    $customerStmt = $db->pdo()->query('SELECT tb고객사.code, tb고객사.회사명 FROM tb고객사 ORDER BY tb고객사.회사명 ASC');
    foreach ($customerStmt->fetchAll(PDO::FETCH_NUM) as [$customerCode, $customerName]) {
        $customerOptions[] = [(string) $customerCode, $customerName === null ? '' : (string) $customerName];
    }
} catch (Throwable $e) {
    // 콤보만 비고 화면은 뜬다.
}
// 지금 문의의 고객사code가 tb고객사에 없으면(지워진 고객사 등) 저장돼 있던 이름으로 넣어 둔다.
$currentCustomerCode = $record !== null ? (string) ($record['고객사code'] ?? '') : '';
if ($currentCustomerCode !== '' && !in_array($currentCustomerCode, array_column($customerOptions, 0), true)) {
    array_unshift($customerOptions, [$currentCustomerCode, (string) ($record['고객사'] ?? $currentCustomerCode)]);
}

/**
 * 직원을 고르면 입력폼에 옮겨 넣는 tb고객사_직원목록 필드 — t문의에 같은 이름의
 * 필드가 있다. inquiryContent_fetch.php의 STAFF_DETAIL_FIELDS와 같아야 한다.
 */
const STAFF_DETAIL_FIELDS = ['직책', '회사전화번호', '휴대폰', '팩스', '이메일', '근무지주소'];

/**
 * 고객사직원 콤보 원본 — tb고객사_직원목록 중 parentcode = 고객사code(고객사에 딸린
 * 직원만), 값 code, 표시 담당자, 담당자 순. 셋째 칸은 직원을 고를 때 입력폼에 채울
 * 값({직책, 회사전화번호, ...}). 고객사를 바꾸면 화면이
 * inquiryContent_fetch.php?action=staff로 다시 채운다(같은 쿼리, inquiry_staff_options).
 *
 * @return array<int, array{0: string, 1: string, 2: array<string, string>}>
 */
function ic_staff_options(DbContext $db, string $customerCode): array
{
    if ($customerCode === '' || !ctype_digit($customerCode)) {
        return [];
    }
    $q = function (string $name) use ($db): string {
        return $db->dialect()->quoteIdentifier($name);
    };
    $sql = 'SELECT ' . $q('code') . ', ' . $q('담당자') . ', '
        . implode(', ', array_map($q, STAFF_DETAIL_FIELDS))
        . ' FROM ' . $q('tb고객사_직원목록')
        . ' WHERE ' . $q('parentcode') . ' = ' . $db->pdo()->quote($customerCode)
        . ' ORDER BY ' . $q('담당자') . ' ASC';
    $options = [];
    foreach ($db->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $details = [];
        foreach (STAFF_DETAIL_FIELDS as $field) {
            $details[$field] = $row[$field] === null ? '' : trim((string) $row[$field]);
        }
        $options[] = [(string) $row['code'], $row['담당자'] === null ? '' : (string) $row['담당자'], $details];
    }
    return $options;
}

$staffOptions = [];
try {
    $staffOptions = ic_staff_options($db, $currentCustomerCode);
} catch (Throwable $e) {
    // 콤보만 비고 화면은 뜬다.
}
// 지금 문의의 직원이 목록에 없으면(다른 고객사 직원으로 저장된 2건 등) 저장돼 있던 이름으로 넣어 둔다.
$currentStaffCode = $record !== null ? (string) ($record['고객사직원code'] ?? '') : '';
if ($currentStaffCode !== '' && !in_array($currentStaffCode, array_column($staffOptions, 0), true)) {
    array_unshift($staffOptions, [$currentStaffCode, (string) ($record['고객사직원'] ?? $currentStaffCode)]);
}
// 직원을 고를 때 채울 값 {직원code: {직책, ...}}.
$staffDetails = [];
foreach ($staffOptions as $option) {
    if (isset($option[2])) {
        $staffDetails[$option[0]] = $option[2];
    }
}

/** 탭 iframe 주소 — inquiryContent_<탭키>_entry.php?code=. */
function ic_tab_src(string $key, string $code): string
{
    return rawurlencode('inquiryContent_' . $key . '_entry.php') . '?code=' . rawurlencode($code);
}

/**
 * 탭 iframe의 주소 속성 — 신규(code=new)면 아무 주소도 안 넣어 빈 칸으로 둔다.
 * $lazy면 처음 누를 때 불러오도록 data-src에 넣는다.
 */
function ic_tab_src_attr(string $key, string $code, bool $isNew, bool $lazy = false): string
{
    if ($isNew) {
        return '';
    }
    return ($lazy ? 'data-src' : 'src') . '="' . ic_tab_src($key, $code) . '"';
}

/** select의 option 하나 — 값과 표시 글자를 이스케이프해 그린다. */
function ic_option(string $value, string $label): string
{
    return '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
}

// 하단 탭 — [탭키(파일 이름), 탭 글자]. 첫 탭이 처음에 보인다.
$bottomTabs = [
    ['수주', '수주'],
    ['발주', '발주'],
    ['수주히스토리', '수주 히스토리'],
    ['발주히스토리', '발주 히스토리'],
    ['견적', '견적'],
];

// crudbuttons iframe — fetch(CrudJs 계약)와 처음 열 PK(신규면 빈 값 → 빈 폼).
// close=0: 창닫기 버튼을 숨긴다 — 이 화면은 index 탭 자체라 탭의 X로 닫는다.
$fetchUrl = APP_BASE_URL . '/workspace/inquiry/inquiryContent_fetch.php';
$pk = $isNew ? '' : $code;
$buttonsSrc = APP_BASE_URL . '/src/Model/Crud/crudbuttons/crudbuttons.php?fetch=' . rawurlencode($fetchUrl) . '&pk=' . rawurlencode($pk) . '&close=0';

$talkJsPath = SRC_DIR . 'js/talk/talk.js';
$talkJsVersion = is_file($talkJsPath) ? filemtime($talkJsPath) : time();
// 문의내역 팝업(비모달)을 띄우는 Popup.js.
$popupJsPath = SRC_DIR . 'js/Popup/Popup.js';
$popupJsVersion = is_file($popupJsPath) ? filemtime($popupJsPath) : time();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title><?= $isNew ? '문의(new)' : '문의 (' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . ')' ?></title>
<!-- document.currentScript로 talk_fetch.php 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/talk/talk.js?v=<?= $talkJsVersion ?>"></script>
<script src="<?= APP_BASE_URL ?>/src/js/Popup/Popup.js?v=<?= $popupJsVersion ?>"></script>
<style>
/* 화면 배경색 하나(--ic-bg)를 페이지/박스/탭 칸이 같이 쓴다(탭 머리는 따로 — 고른 탭 흰색, 나머지 회색).
   code가 있는 문의는 회색, code가 없을 때(신규 문의 등)는 모두 흰색(body.ic-no-code).
   탭 페이지(iframe)는 자기 배경이 없어 탭 칸 색이 그대로 비친다. */
:root { --ic-bg: #f3f4f6; }
/* body.cb-no-pk — crudbuttons가 PK가 없을 때(신규) 붙인다(crudbuttons.md "바탕색"). */
body.ic-no-code, body.cb-no-pk { --ic-bg: #ffffff; }

html, body { margin: 0; height: 100%; }
body {
    padding: 12px; box-sizing: border-box; background: var(--ic-bg); color: #1e293b;
    font-family: 'Malgun Gothic', 'Segoe UI', system-ui, sans-serif; font-size: 13px;
}

/* 전체 배치 — 왼쪽(위: 폼+문의탭, 아래: 하단 탭) / 오른쪽(talk). */
.ic-page { display: grid; grid-template-columns: minmax(0, 1fr) 390px; gap: 12px; height: 100%; }
.ic-main { display: grid; grid-template-rows: auto minmax(0, 1fr); gap: 12px; min-width: 0; min-height: 0; }
.ic-top { display: grid; grid-template-columns: 490px minmax(0, 1fr); gap: 12px; min-width: 0; }
.ic-talk { min-height: 0; border-radius: 4px; overflow: hidden; }
/* 신규 문의 — talk을 숨기고 talk 채팅영역 색의 빈 칸만 둔다. */
.ic-talk.ic-talk-empty { background: #9bbbd4; }
#ic-talk { height: 100%; }
#ic-talk[hidden] { display: none; }

.ic-box { background: var(--ic-bg); border: 1px solid #d1d5db; border-radius: 4px; padding: 12px; box-sizing: border-box; min-width: 0; }

/* 좌측 상단 문의 박스 — 문의확인.png처럼 진한 테두리 + 오른쪽/아래 그림자. */
.ic-box.ic-form-box { padding-right: 42px; border-color: #1f2937; box-shadow: 3px 3px 0 #1f2937, 5px 5px 6px rgba(0, 0, 0, 0.18); }

/* 좌측 상단 입력폼 — 라벨/값 2쌍씩. */
.ic-form { display: grid; grid-template-columns: 78px 1fr 52px 1fr; gap: 6px 8px; align-items: center; }
/* 캡션과 입력값 글자는 검은색(읽기전용 멤버 칸 포함). */
.ic-form label { color: #000; white-space: nowrap; }
.ic-form .ic-wide { grid-column: 2 / 5; }
.ic-form input, .ic-form select, .ic-form textarea {
    font: inherit; width: 100%; box-sizing: border-box; height: 26px; padding: 0 6px;
    border: 1px solid #d1d5db; border-radius: 3px; background: #fff; color: #000;
}
.ic-form textarea { height: 46px; padding: 4px 6px; resize: vertical; }
.ic-form textarea.ic-memo { height: 100px; }
.ic-form input[readonly] { background: #f3f4f6; color: #000; }
.ic-form input:focus, .ic-form select:focus, .ic-form textarea:focus { outline: none; border-color: #3b82f6; }
/* 콤보 + 오른쪽 아이콘 버튼(고객사 찾기) — 콤보는 다른 칸과 같은 너비 그대로 두고,
   버튼은 칸 바깥 오른쪽에 붙인다. 그 자리(30px)는 박스 너비와 오른쪽 안쪽 여백을
   그만큼 늘려 마련한다(.ic-top 첫 칸, .ic-form-box padding-right). */
.ic-with-button { position: relative; min-width: 0; }
.ic-icon-btn {
    position: absolute; top: 0; left: calc(100% + 4px);
    width: 26px; height: 26px; padding: 0; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid #d1d5db; border-radius: 3px; background: #fff; color: #000;
}
.ic-icon-btn:hover { background: #f3f4f6; }

/* crudbuttons iframe(저장/삭제/신규입력/창닫기) — 폼 아래, 배경이 비치게. */
.cb-frame { display: block; width: 100%; height: 44px; border: 0; margin-top: 8px; background: transparent; }

/* 탭(우측 상단 "문의" 하나, 하단 5개) — 문의확인.png처럼 위쪽에 탭 머리.
   탭 칸 안은 iframe 하나가 꽉 채운다(탭 페이지가 버튼/그리드를 갖는다). */
.ic-tabs { display: flex; flex-direction: column; min-width: 0; min-height: 0; }
.ic-tabbar { display: flex; gap: 2px; }
/* 탭 머리 — 고른 탭은 흰색, 안 고른 탭은 회색(문의확인.png). 글자/높이는 작게. */
.ic-tabbar button {
    font: inherit; font-size: 12px; min-width: 80px; height: 22px; padding: 0 12px; cursor: pointer;
    border: 1px solid #d1d5db; border-bottom: none; border-radius: 3px 3px 0 0;
    background: #e5e7eb; color: #4b5563;
}
.ic-tabbar button.ic-active { background: #ffffff; font-weight: 600; color: #1e293b; }
.ic-tabpane { position: relative; flex: 1; min-height: 240px; padding: 0; overflow: hidden; border-radius: 0 4px 4px 4px; }
.ic-tabpane[hidden] { display: none; }
.ic-tabpane iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; display: block; }
/* 신규 문의 — 하위폼 iframe은 지우지 않고 숨기기만 한다(visible=false, icApplyCode). */
.ic-tabpane iframe[hidden] { display: none; }

.ic-load-error { padding: 40px; text-align: center; color: #dc2626; font-size: 14px; }

/* 고객사 검색 팝업(모달) — 화면 위에 반투명 막을 깔고 가운데 창을 띄운다.
   창 안은 iframe(inquiryContent_고객사검색_entry.php). */
/* z-index — Popup(문의내역 팝업, 1000부터 올라간다)보다 위에 떠야 한다(모델 검색은 그 팝업이 연다). */
.ic-modal { position: fixed; inset: 0; z-index: 100000; display: flex; align-items: center; justify-content: center; background: rgba(15, 23, 42, 0.35); }
.ic-modal[hidden] { display: none; }
.ic-modal-window {
    display: flex; flex-direction: column; width: min(1150px, 94vw); height: min(660px, 90vh);
    background: #fff; border: 1px solid #6b7280; border-radius: 4px;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
}
.ic-modal-title {
    display: flex; align-items: center; justify-content: space-between; height: 32px; padding: 0 6px 0 12px;
    border-bottom: 1px solid #d1d5db; font-size: 13px; color: #000; user-select: none;
}
.ic-modal-close { width: 28px; height: 24px; border: 0; background: transparent; font-size: 18px; line-height: 1; cursor: pointer; color: #374151; }
.ic-modal-close:hover { background: #ef4444; color: #fff; }
.ic-modal-window iframe { flex: 1; width: 100%; border: 0; display: block; }</style>
</head>
<body<?= ($isNew || $record === null) ? ' class="ic-no-code"' : '' ?>>
<?php if ($record === null): ?>
<div class="ic-load-error"><?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></div>
<?php else: ?>
<div class="ic-page">
    <div class="ic-main">
        <div class="ic-top">
            <!-- 좌측 상단: t문의 입력폼 — 컨트롤 id = 필드명(CrudJs가 채우고 읽는다) -->
            <div class="ic-box ic-form-box">
                <form class="ic-form" id="ic-form" autocomplete="off" onsubmit="return false;">
                    <input type="hidden" id="code">

                    <label for="문의일자">문의일자</label>
                    <input type="date" id="문의일자">
                    <label for="상태">상태</label>
                    <select id="상태">
<?php foreach ($statusOptions as $status): ?>
                        <?= ic_option($status, $status) ?>

<?php endforeach; ?>
                    </select>

                    <label for="멤버">멤버</label>
                    <input type="text" id="멤버" readonly>
                    <label for="고객사code">고객사</label>
                    <!-- 고객사 콤보(tb고객사: 값 code, 표시 회사명) + 찾기 버튼 -->
                    <div class="ic-with-button">
                        <select id="고객사code">
<?php foreach ($customerOptions as [$customerCode, $customerName]): ?>
                            <?= ic_option($customerCode, $customerName) ?>

<?php endforeach; ?>
                        </select>
                        <button type="button" class="ic-icon-btn" id="ic-customer-find" title="고객사 찾기" aria-label="고객사 찾기">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="10.5" cy="10.5" r="6.5"/><path d="M15.5 15.5 21 21"/></svg>
                        </button>
                    </div>

                    <label for="고객사직원code">고객사직원</label>
                    <!-- 고객사직원 콤보(tb고객사_직원목록, 고른 고객사의 직원만: 값 code, 표시 담당자) -->
                    <select id="고객사직원code">
<?php foreach ($staffOptions as $option): ?>
                        <?= ic_option($option[0], $option[1]) ?>

<?php endforeach; ?>
                    </select>
                    <label for="휴대폰">휴대폰</label>
                    <input type="text" id="휴대폰">

                    <label for="직책">직책</label>
                    <input type="text" id="직책">
                    <label for="팩스">팩스</label>
                    <input type="text" id="팩스">

                    <label for="회사전화번호">회사전화번호</label>
                    <input type="text" id="회사전화번호">
                    <label for="이메일">이메일</label>
                    <input type="text" id="이메일">

                    <label for="근무지주소">근무지주소</label>
                    <textarea id="근무지주소" class="ic-wide"></textarea>

                    <label for="배송주소">배송주소</label>
                    <textarea id="배송주소" class="ic-wide"></textarea>

                    <label for="비고">비고</label>
                    <textarea id="비고" class="ic-wide ic-memo"></textarea>
                </form>
                <!-- 저장/삭제/신규입력/창닫기 — crudbuttons(CrudJs가 위 컨트롤에 붙는다) -->
                <iframe class="cb-frame" id="cb-frame" src="<?= htmlspecialchars($buttonsSrc, ENT_QUOTES, 'UTF-8') ?>" title="저장/삭제 버튼"></iframe>
            </div>

            <!-- 우측 상단: "문의내용" 탭 — inquiryContent_문의내용_entry.php -->
            <div class="ic-tabs">
                <div class="ic-tabbar"><button type="button" class="ic-active">문의내용</button></div>
                <div class="ic-tabpane ic-box">
                    <iframe class="ic-tab-frame" data-key="문의내용" <?= ic_tab_src_attr('문의내용', $code, $isNew) ?><?= $isNew ? ' hidden' : '' ?> title="문의내용"></iframe>
                </div>
            </div>
        </div>

        <!-- 하단: 탭 5개 — 각 탭 페이지를 iframe으로. data-src는 처음 누를 때 src로 옮긴다. -->
        <div class="ic-tabs" id="ic-bottom-tabs">
            <div class="ic-tabbar">
<?php foreach ($bottomTabs as $i => [$key, $label]): ?>
                <button type="button"<?= $i === 0 ? ' class="ic-active"' : '' ?> data-tab="<?= $key ?>"><?= $label ?></button>
<?php endforeach; ?>
            </div>
<?php foreach ($bottomTabs as $i => [$key, $label]): ?>
            <div class="ic-tabpane ic-box" data-pane="<?= $key ?>"<?= $i === 0 ? '' : ' hidden' ?>>
                <iframe class="ic-tab-frame" data-key="<?= $key ?>" <?= ic_tab_src_attr($key, $code, $isNew, $i !== 0) ?><?= $isNew ? ' hidden' : '' ?> title="<?= $label ?>"></iframe>
            </div>
<?php endforeach; ?>
        </div>
    </div>

    <!-- 고객사 검색 팝업 — 찾기 버튼이 연다(icOpenCustomerSearch). iframe은 처음 열 때 불러온다. -->
    <div class="ic-modal" id="ic-customer-modal" hidden>
        <div class="ic-modal-window" role="dialog" aria-modal="true" aria-label="고객사 검색/추가">
            <div class="ic-modal-title">
                <span>고객사 검색/추가</span>
                <button type="button" class="ic-modal-close" id="ic-customer-modal-close" title="닫기" aria-label="닫기">&times;</button>
            </div>
            <iframe id="ic-customer-frame" data-src="<?= rawurlencode('inquiryContent_고객사검색_entry.php') ?>" title="고객사 검색"></iframe>
        </div>
    </div>

    <!-- 모델 검색 팝업(tb제품) — 문의내역 팝업의 모델 찾기 버튼이 action('openModelSearch')로 연다.
         크기는 고객사 검색과 같다. iframe은 열 때마다 받은 검색어로 불러온다(icOpenModelSearch). -->
    <div class="ic-modal" id="ic-model-modal" hidden>
        <div class="ic-modal-window" role="dialog" aria-modal="true" aria-label="모델 검색">
            <div class="ic-modal-title">
                <span>모델 검색</span>
                <button type="button" class="ic-modal-close" id="ic-model-modal-close" title="닫기" aria-label="닫기">&times;</button>
            </div>
            <iframe id="ic-model-frame" title="모델 검색"></iframe>
        </div>
    </div>

    <!-- 오른쪽: talk — 신규일 때는 안쪽(#ic-talk)을 숨기고 빈 칸 색만 보인다(icApplyCode). -->
    <div class="ic-talk<?= $isNew ? ' ic-talk-empty' : '' ?>" id="ic-talk-box">
        <div id="ic-talk"<?= $isNew ? ' hidden' : '' ?>></div>
    </div>
</div>

<script>
// VBA식 JS — var(함수 맨 위에 모아 선언), function, if/else, for, 매개변수는 p로 시작,
// 문자열은 +로 잇기, 서버를 기다리는 일은 async/await(crudbuttons.md 1절).
// 화면 전용 함수·변수는 ic / IC_ 접두사. 이벤트 함수와 action/action_xxx에는 붙이지 않는다.

// 지금 보여주는 문의 code와 신규 여부 — 처음엔 주소(?code=)의 값이지만, 화면을 다시 열지
// 않고 재사용하므로(openInquiry) 바뀐다. 바꾸는 곳은 icApplyCode 한 곳뿐이다.
var icCode = <?= json_encode($code) ?>;
// 신규 문의(code=new) — 아직 DB에 없다. 저장하면 insert된다.
var icIsNew = <?= $isNew ? 'true' : 'false' ?>;
// 다른 화면(문의 목록)이 이 화면을 찾을 때 쓰는 표시 — index의 탭키와 같다.
var IC_TAB_KEY = '문의확인';
var IC_FETCH_URL = <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
// 신규 문의의 기본값 — 문의일자는 오늘, 멤버는 로그인한 사람(저장 때 서버가 세션 값으로 다시 넣는다).
var IC_TODAY = <?= json_encode(date('Y-m-d')) ?>;
var IC_LOGIN_CODE = <?= json_encode($loginCode, JSON_UNESCAPED_UNICODE) ?>;
var IC_LOGIN_NAME = <?= json_encode($loginName, JSON_UNESCAPED_UNICODE) ?>;

// 직원을 고르면 채우는 입력폼 칸(PHP의 STAFF_DETAIL_FIELDS)과, 지금 콤보 직원들의 그 값
// {직원code: {직책, 회사전화번호, ...}}. 고객사를 바꿔 직원 목록을 다시 받으면 icLoadStaff가 바꾼다.
var IC_STAFF_DETAIL_FIELDS = <?= json_encode(STAFF_DETAIL_FIELDS, JSON_UNESCAPED_UNICODE) ?>;
var IC_STAFF_DETAILS = <?= json_encode((object) $staffDetails, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
// 지금 고객사직원 콤보가 어느 고객사의 직원 목록인지(다시 읽을 때 고객사가 바뀌었으면 다시 받는다).
var IC_STAFF_CUSTOMER = <?= json_encode($currentCustomerCode) ?>;

// 이 문의를 지웠는지 — 지운 뒤 crudbuttons가 부르는 신규입력(clear)에서 새 문의를 열지 않게.
var icDeleted = false;
// crudbuttons(psender) — 이벤트가 처음 불릴 때 담아 둔다. 이벤트 밖(직원 고르기, 고객사
// 검색 팝업)에서 값을 넣을 때 psender.getCrud().setValue로 넣기 위해서다.
var icSender = null;

// ── 새 탭 준비 알림 — index가 새 탭을 숨겨 두고 기다린다(index.php의 tabReady) ──
// TAB_READY_MANUAL = true: "다 그리면 내가 action('tabReady')로 알린다"는 표시. index는
// 이 값이 있으면 load 때 바로 보이지 않고 tabReady를 기다린다(오래 걸리면 시간 초과로 보인다).
// 기다리는 것: 입력폼 값(form), talk 대화(talk), 처음 보이는 하위폼의 데이터(subform:<탭키>).
var TAB_READY_MANUAL = true;
var icReadyWait = {};      // 아직 기다리는 것 {이름: true}
var icReadySent = false;   // tabReady를 이미 보냈는지(한 번만 보낸다)

/** 기다릴 것을 정한다 — 화면이 처음 뜰 때 한 번. */
function icSetupReady() {
    var frames = document.querySelectorAll('iframe.ic-tab-frame[src]');
    var i;

    icReadyWait['form'] = true;
    if (!icIsNew) {
        icReadyWait['talk'] = true;
    }
    for (i = 0; i < frames.length; i++) {
        icReadyWait['subform:' + frames[i].getAttribute('data-key')] = true;
    }
}

/** 하나(pname)가 준비됐다 — 기다리던 것이 다 끝나면 index에 action('tabReady')를 보낸다. */
function icMarkReady(pname) {
    if (icReadySent) {
        return;
    }
    delete icReadyWait[pname];
    if (Object.keys(icReadyWait).length > 0) {
        return;
    }
    icReadySent = true;
    if (window.parent !== window && typeof window.parent.action === 'function') {
        window.parent.action('tabReady', {}, window);
    }
}

icSetupReady();

// ── 오른쪽 talk — ttalk(type = TALK_TYPE, parentcode = 문의 code) ──
// 신규 문의는 대화를 달 code가 아직 없으므로 talk을 띄우지 않는다(빈 칸).
// code가 바뀌면 icShowTalk가 새 parentcode로 다시 init한다(위젯은 그대로 재사용).
var IC_TALK_TYPE = <?= json_encode(TALK_TYPE, JSON_UNESCAPED_UNICODE) ?>;
// Access의 nz_(mLogin.getvalue("code"), 0) / nz_(mLogin.getvalue("이름"), "무명")과 같다.
var IC_TALK_LOGIN_CODE = <?= json_encode($talkLoginCode, JSON_UNESCAPED_UNICODE) ?>;
var IC_TALK_LOGIN_NAME = <?= json_encode($talkLoginName, JSON_UNESCAPED_UNICODE) ?>;
var icTalk = null;

/**
 * talk을 문의 code(pcode)의 대화로 보여준다 — 처음이면 위젯을 만들고, 있으면 다시 init.
 * 대화를 다 그리면 끝나는 Promise를 돌려준다.
 */
function icShowTalk(pcode) {
    if (icTalk === null) {
        icTalk = new Talk('ic-talk');
    }
    return Promise.resolve(icTalk.action('init', {
        type: IC_TALK_TYPE,
        parentcode: pcode,
        logincode: IC_TALK_LOGIN_CODE,
        loginname: IC_TALK_LOGIN_NAME,
        pollSeconds: 0
    }));
}

if (!icIsNew) {
    // 대화를 다 그렸으면(실패해도) 준비 완료로 센다.
    icShowTalk(icCode).then(
        function () { icMarkReady('talk'); },
        function () { icMarkReady('talk'); }
    );
}

// ══════════════════════════════════════════════════════════════════
// CRUD 이벤트 — crudbuttons가 부른다(psender = CrudButtons). false면 막음.
// 정의하지 않은 이벤트(isEnableAmdSave 등)는 "통과"다.
// ══════════════════════════════════════════════════════════════════

/** 저장 전 — 고객사/고객사직원 이름 사본을 콤보의 표시 글자로 채운다(t문의에 code와 이름이 같이 있다). */
function beforeCrudSave(psender, ppara) {
    var crud = psender.getCrud();

    crud.setValue('고객사', icSelectedText('고객사code'));
    crud.setValue('고객사직원', icSelectedText('고객사직원code'));
    return true;
}

/**
 * 저장 뒤 — 신규를 처음 저장했으면 화면을 새 code로 바꾼다(icApplyCode: 하위폼/talk이
 * 다시 보이고 그 code로 채워진다). 페이지는 다시 열지 않는다.
 */
function afterCrudSave(psender, ppara) {
    icSender = psender;
    icApplyCode(psender.getPk());
    psender.report('saved', { code: psender.getPk() });
}

/**
 * 삭제 전 — crudbuttons의 확인창("삭제할까요?") 뒤에 불린다. 딸린 문의내역/수주/발주/
 * 견적/talk 대화가 있으면 건수를 보여주고 한 번 더 묻는다(없으면 바로 통과).
 */
async function beforeCrudDelete(psender, ppara) {
    var res;
    var data;
    var children;

    res = await fetch(IC_FETCH_URL + '?action=children&pk=' + encodeURIComponent(psender.getPk()));
    data = await res.json();
    if (!data.success) {
        ppara.error = '딸린 자료 확인 실패: ' + data.error;
        return false;
    }

    children = icCountText(data.children);
    if (children === '') {
        return true;
    }
    if (!confirm('딸린 자료도 함께 삭제됩니다: ' + children + '\n계속할까요?')) {
        ppara.error = '삭제를 취소했습니다.';
        return false;
    }
    return true;
}

/** 삭제 뒤 — 문의 목록 탭으로 옮겨 다시 조회하고 이 탭을 닫는다. */
function afterCrudDelete(psender, ppara) {
    icDeleted = true;
    psender.report('deleted', { code: psender.getPk() });
    icGoToListAndClose();
}

/**
 * 신규입력 전 — 삭제 뒤에는 탭이 닫히는 중이라 비우지 않는다. 그 밖에는 통과
 * (저장 안 한 값 확인은 crudbuttons가 이미 했다).
 */
function beforeCrudClear(psender, ppara) {
    icSender = psender;
    if (icDeleted) {
        ppara.error = '삭제했습니다.';
        return false;
    }
    return true;
}

/**
 * 신규 폼을 비운 뒤 — 기본값(문의일자 오늘, 멤버는 로그인한 사람)을 넣고 화면을 신규로
 * 바꾼다(icApplyCode: 하위폼 iframe과 talk은 숨긴다 — 지우지 않는다).
 */
function afterCrudClear(psender, ppara) {
    var crud = psender.getCrud();

    icSender = psender;
    crud.setValue('문의일자', IC_TODAY);
    crud.setValue('멤버code', IC_LOGIN_CODE);
    crud.setValue('멤버', IC_LOGIN_NAME);
    icApplyCode('new');
    icMarkReady('form');
}

/**
 * 레코드를 채운 뒤 — 콤보에 없는 값(지워진 고객사, 다른 고객사 직원 등)은 저장돼 있던
 * 이름으로 항목을 만들어 넣고 다시 고른다(CrudJs가 그릴 때는 항목이 없어 빈 칸이 됐다).
 * 고객사가 바뀌었으면(다시 읽기) 직원 목록도 그 고객사의 직원으로 다시 받는다.
 * 이 함수가 끝난 직후 crudbuttons가 지금 값을 기준값으로 기억하므로, 값은 여기서
 * 바로(기다리지 않고) 맞춰 둔다.
 */
function afterCrudSelectRecord(psender, ppara) {
    var crud = psender.getCrud();
    var rows = crud.getRecordSet();
    var row;
    var customer;

    icSender = psender;
    if (rows.length === 0) {
        return;
    }
    row = rows[0];

    icEnsureOption('상태', row['상태'], row['상태']);
    crud.setValue('상태', row['상태']);

    icEnsureOption('고객사code', row['고객사code'], row['고객사']);
    crud.setValue('고객사code', row['고객사code']);

    icEnsureOption('고객사직원code', row['고객사직원code'], row['고객사직원']);
    crud.setValue('고객사직원code', row['고객사직원code']);

    customer = icText(row['고객사code']);
    if (customer !== IC_STAFF_CUSTOMER) {
        icLoadStaff(customer, row['고객사직원code'], row['고객사직원']);
    }

    // 다른 문의를 열었으면 하위폼/talk/제목도 그 code로 바꾼다(같은 code면 아무것도 안 한다).
    icApplyCode(row['code']);
    icMarkReady('form');
}

// ══════════════════════════════════════════════════════════════════
// action — 바깥(index 셸, 문의 목록)과 안쪽(탭/팝업 iframe)이 이 화면을 부르는 창구.
// 종류별 하위 함수로 나눠 넘기기만 한다.
//   action_screen   — requery(화면 전체 다시 읽기) / openInquiry(떠 있는 화면에 다른 문의·신규 열기)
//   action_detail   — selectRecord_문의내용(문의 탭 더블클릭 → 문의내역 팝업) / amdReport(팝업의 보고)
//                     addnew_문의내용(문의 탭 신규추가 → 문의내역 팝업을 신규로)
//   action_sub      — selectRecord_수주/발주/견적(그 탭 더블클릭 → 그 팝업)
//                     addnew_수주/발주/견적(그 탭 신규추가 → 그 팝업을 신규로) — IC_SUB_POPUPS
//   action_model    — openModelSearch / setModel / closeModelSearch(모델 검색 팝업 — 문의내역 팝업 대신 띄운다)
//   action_buttons  — close / selectRecord / isDirty(버튼 iframe에 맡긴다)
//   action_customer — setCustomer / closeCustomerSearch(고객사 검색 팝업)
// ══════════════════════════════════════════════════════════════════
function action(pcmd, pparams, psender) {
    if (!pparams) {
        pparams = {};
    }

    switch (pcmd) {
        case 'requery':
        case 'openInquiry':
        case 'subformReady':
            return action_screen(pcmd, pparams, psender);

        case 'selectRecord_문의내용':
        case 'addnew_문의내용':
        case 'amdReport':
            return action_detail(pcmd, pparams, psender);

        case 'selectRecord_수주':
        case 'selectRecord_발주':
        case 'selectRecord_견적':
        case 'addnew_수주':
        case 'addnew_발주':
        case 'addnew_견적':
            return action_sub(pcmd, pparams, psender);

        case 'openModelSearch':
        case 'setModel':
        case 'closeModelSearch':
            return action_model(pcmd, pparams, psender);

        case 'close':
        case 'selectRecord':
        case 'isDirty':
            return action_buttons(pcmd, pparams, psender);

        case 'setCustomer':
        case 'closeCustomerSearch':
            return action_customer(pcmd, pparams, psender);

        default:
            console.warn('문의확인.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

/**
 * requery — 이 화면 전체를 서버 값으로 다시 읽는다: 입력폼(버튼 iframe), 불러온 탭
 * iframe들, talk 대화. index가 이 탭으로 옮겨 올 때 부른다 — 입력폼에 저장 안 한
 * 값이 있으면 묻지 않고 건너뛴다(신규 문의는 다시 읽을 게 없다).
 * openInquiry {code} — 떠 있는 이 화면에 다른 문의(또는 'new')를 연다(icOpenInquiry).
 */
function action_screen(pcmd, pparams, psender) {
    var buttons;

    switch (pcmd) {
        case 'openInquiry':
            return icOpenInquiry(pparams.code);

        case 'subformReady':
            // 하위폼이 데이터를 그렸다(새 탭 준비 세기 — 이미 보냈으면 무시된다).
            return icMarkReady('subform:' + pparams.key);

        case 'requery':
            if (icIsNew) {
                return;
            }
            // 하위폼과 talk은 늘 다시 읽는다(문의내역/수주/발주/견적 팝업에서 저장/삭제한 결과가 보이게).
            // pparams.code가 있으면 pparams.tab(없으면 '문의내용') 탭에 넘겨 그 행으로 커서를 옮기게 한다.
            icRequeryTabs(pparams.code, pparams.tab);
            if (icTalk !== null) {
                icTalk.action('show');
            }
            // 입력폼은 저장 안 한 값이 있으면 묻지 않고 건너뛴다.
            buttons = icButtons();
            if (buttons === null || buttons.action('isDirty') === true) {
                return;
            }
            return buttons.action('requery', {}, window);
    }
}

/** 레코드/창 명령 — 버튼 iframe(crudbuttons)의 action에 그대로 맡긴다. */
function action_buttons(pcmd, pparams, psender) {
    var buttons = icButtons();

    if (buttons === null) {
        return;
    }
    return buttons.action(pcmd, pparams, window);
}

/**
 * 문의내역 팝업(inquiryContent_문의내용_amd.php — 비모달, 화면 오른쪽).
 *  - selectRecord_문의내용 {code}: 문의 탭에서 행을 더블클릭했다 — 팝업을 띄우거나,
 *    이미 떠 있으면 그 팝업에 그 행을 보여준다(팝업은 다시 열지 않는다).
 *  - amdReport {ptype, payload, popupId}: 팝업(crudbuttons)의 보고 — 저장/삭제했으면
 *    이 화면을 다시 읽는다(action('requery') — 문의 탭 그리드가 바뀐 값으로 보인다).
 *    'closed'는 할 일이 없다(팝업은 crudbuttons가 닫고, onClose가 icDetailPopup을 비운다).
 */
function action_detail(pcmd, pparams, psender) {
    switch (pcmd) {
        case 'selectRecord_문의내용':
            return icOpenDetail(pparams.code);

        case 'addnew_문의내용':
            return icOpenDetailNew();

        case 'amdReport':
            // 저장이면 저장한 행(code)을 넘겨 그 팝업의 탭 그리드 커서를 그 행에 맞춘다
            // (수주/발주/견적 팝업이면 그 탭, 아니면 문의 탭 — icSubKeyOfPopup).
            if (pparams.ptype === 'saved') {
                return action('requery', {
                    code: pparams.payload ? pparams.payload.code : null,
                    tab: icSubKeyOfPopup(pparams.popupId)
                }, window);
            }
            if (pparams.ptype === 'deleted') {
                return action('requery', {}, window);
            }
            return;
    }
}

/**
 * 수주/발주/견적 팝업(inquiryContent_수주_amd.php 등 — 비모달, 화면 오른쪽). IC_SUB_POPUPS 참고.
 *  - selectRecord_<탭> {code}: 그 탭에서 행을 더블클릭했다 — 팝업을 띄우거나,
 *    이미 떠 있으면 그 팝업에 그 행을 보여준다(팝업은 다시 열지 않는다).
 *  - addnew_<탭>: 그 탭의 신규추가 — 이 문의(icCode)의 새 행을 팝업에 연다.
 * pcmd의 '_' 뒤가 탭 키(수주/발주/견적)다.
 * 저장/삭제 보고(amdReport)는 action_detail이 함께 받는다(popupId로 탭을 고른다).
 */
function action_sub(pcmd, pparams, psender) {
    var pos = pcmd.indexOf('_');
    var kind = pcmd.substring(0, pos);
    var key = pcmd.substring(pos + 1);

    switch (kind) {
        case 'selectRecord':
            return icOpenSub(key, pparams.code);
        case 'addnew':
            return icOpenSubNew(key);
    }
}

/**
 * 모델 검색 팝업(inquiryContent_모델검색_entry.php — tb제품) — 문의내역 팝업은 작아서
 * 이 화면이 대신 띄운다(고객사 검색과 같은 크기).
 *  - openModelSearch {keyword}: 문의내역 팝업(psender)이 연다. 고른 값을 돌려줄 곳으로
 *    psender를 기억한다. 처리했으면 true(문의내역 팝업은 자기 모달을 띄우지 않는다).
 *  - setModel {row}: 모델 검색 화면에서 행을 더블클릭했다 — 기억해 둔 문의내역 팝업의
 *    action('setModel', {row})로 넘기고 팝업을 닫는다.
 *  - closeModelSearch: 팝업을 닫는다(모델 검색 화면에서 Esc).
 */
function action_model(pcmd, pparams, psender) {
    switch (pcmd) {
        case 'openModelSearch':
            return icOpenModelSearch(pparams.keyword, psender);
        case 'setModel':
            return icSetModel(pparams.row);
        case 'closeModelSearch':
            return icCloseModelSearch();
    }
}

/** 고객사 검색 팝업 — 더블클릭한 고객사 넣기 / 팝업 닫기(팝업에서 Esc). */
function action_customer(pcmd, pparams, psender) {
    switch (pcmd) {
        case 'setCustomer':
            return icSetCustomer(pparams.pcode, pparams.pname);
        case 'closeCustomerSearch':
            return icCloseCustomerSearch();
    }
}

// ══════════════════════════════════════════════════════════════════
// 화면 도우미
// ══════════════════════════════════════════════════════════════════

/** 값을 글자로 — null/undefined면 ''. */
function icText(pvalue) {
    if (pvalue === null || pvalue === undefined) {
        return '';
    }
    return String(pvalue);
}

/** 버튼 iframe의 창 — 명령을 그쪽 action에 맡길 때 쓴다. 아직 로드 전이면 null. */
function icButtons() {
    var frame = document.getElementById('cb-frame');

    if (frame === null || !frame.contentWindow) {
        return null;
    }
    if (typeof frame.contentWindow.action !== 'function') {
        return null;
    }
    return frame.contentWindow;
}

/** 버튼 상태(수정됨 표시 포함)를 다시 정하게 한다 — 스크립트로 값을 넣었을 때(입력 이벤트가 안 생긴다). */
function icButtonsRefresh() {
    var buttons = icButtons();

    if (buttons !== null) {
        buttons.action('refresh', {}, window);
    }
}

/** 필드에 값을 넣는다 — crudbuttons(CrudJs)가 준비됐으면 그쪽으로, 아니면 컨트롤에 바로. */
function icSetField(pfield, pvalue) {
    var el;

    if (icSender !== null && icSender.getCrud() !== null) {
        icSender.getCrud().setValue(pfield, pvalue);
        return;
    }
    el = document.getElementById(pfield);
    if (el !== null) {
        el.value = icText(pvalue);
    }
}

/**
 * 문의 입력폼의 CrudJs — 이벤트에서 담아 둔 icSender가 있으면 그것, 없으면 버튼 iframe의
 * crudButtons에서 꺼낸다. 아직 준비 전이면 null.
 */
function icCrud() {
    var buttons;

    if (icSender !== null && icSender.getCrud() !== null) {
        return icSender.getCrud();
    }
    buttons = icButtons();
    if (buttons === null || !buttons.crudButtons) {
        return null;
    }
    return buttons.crudButtons.getCrud();
}

/**
 * getValue(pfield) — 문의 입력폼(t문의)의 필드 값을 읽는다 → crud.getValue(pfield).
 * CrudJs가 아직 준비 전이면 null.
 */
function getValue(pfield) {
    var crud = icCrud();

    if (crud === null) {
        return null;
    }
    return crud.getValue(pfield);
}

/**
 * setValue(pfield, pvalue) — 문의 입력폼(t문의)의 필드에 값을 넣는다 → crud.setValue(pfield, pvalue).
 * CrudJs가 아직 준비 전이면 아무것도 안 한다.
 */
function setValue(pfield, pvalue) {
    var crud = icCrud();

    if (crud === null) {
        return;
    }
    crud.setValue(pfield, pvalue);
}

/** 콤보(pid)에 값(pvalue)이 없으면 맨 앞에 항목(plabel, 없으면 값)을 만들어 넣는다. 빈 값이면 아무것도 안 한다. */
function icEnsureOption(pid, pvalue, plabel) {
    var el = document.getElementById(pid);
    var value = icText(pvalue);
    var label = icText(plabel);
    var i;

    if (el === null || value === '') {
        return;
    }
    for (i = 0; i < el.options.length; i++) {
        if (el.options[i].value === value) {
            return;
        }
    }
    if (label === '') {
        label = value;
    }
    el.add(new Option(label, value), 0);
}

/** 콤보에서 고른 항목의 표시 글자(회사명/담당자). 고르지 않았으면 ''. */
function icSelectedText(pid) {
    var el = document.getElementById(pid);

    if (el === null || el.selectedIndex === -1) {
        return '';
    }
    return el.options[el.selectedIndex].text;
}

/**
 * 고른 직원(pstaffCode)의 직책/회사전화번호/휴대폰/팩스/이메일/근무지주소를 입력폼에
 * 채운다(직원목록 값 그대로 — 비어 있으면 칸도 비운다).
 * 직원목록에 없는 직원(저장돼 있던 이름만 있는 경우)이면 아무것도 바꾸지 않는다.
 */
function icFillFromStaff(pstaffCode) {
    var details = IC_STAFF_DETAILS[icText(pstaffCode)];
    var name;
    var i;

    if (!details) {
        return;
    }
    for (i = 0; i < IC_STAFF_DETAIL_FIELDS.length; i++) {
        name = IC_STAFF_DETAIL_FIELDS[i];
        icSetField(name, icText(details[name]));
    }
    icButtonsRefresh();
}

/**
 * 고객사직원 콤보를 고객사(pcustomerCode)의 직원으로 다시 채운다 — 직원은 고객사에
 * 딸려 온다. pkeepValue를 주면(다시 읽을 때) 그 직원을 고르고, 목록에 없으면
 * pkeepName(저장돼 있던 이름)으로 넣어 둔다. 안 주면 선택을 비운다(고객사를 바꿨으니
 * 직원도 다시 골라야 한다).
 */
async function icLoadStaff(pcustomerCode, pkeepValue, pkeepName) {
    var el = document.getElementById('고객사직원code');
    var customer = icText(pcustomerCode);
    var keep = icText(pkeepValue);
    var staff = [];
    var res;
    var data;
    var i;

    try {
        if (customer !== '') {
            res = await fetch(IC_FETCH_URL + '?action=staff&customer=' + encodeURIComponent(customer));
            data = await res.json();
            if (!data.success) {
                throw new Error(data.error);
            }
            staff = data.staff;
        }
    } catch (err) {
        console.error('직원 목록을 불러오지 못했습니다: ' + err.message);
        return;
    }

    while (el.options.length > 0) {
        el.remove(0);
    }
    IC_STAFF_DETAILS = {};
    for (i = 0; i < staff.length; i++) {
        el.add(new Option(staff[i][1], staff[i][0]));
        if (staff[i][2]) {
            IC_STAFF_DETAILS[icText(staff[i][0])] = staff[i][2];
        } else {
            IC_STAFF_DETAILS[icText(staff[i][0])] = {};
        }
    }
    IC_STAFF_CUSTOMER = customer;

    if (keep !== '') {
        icEnsureOption('고객사직원code', keep, pkeepName);
        icSetField('고객사직원code', keep);
    } else {
        icSetField('고객사직원code', '');
        el.selectedIndex = -1;
    }
    icButtonsRefresh();
}

// ── 문의내역 팝업(비모달) — 한 번 띄우면 닫을 때까지 다시 열지 않고 재사용한다 ──
var IC_DETAIL_URL = encodeURIComponent('inquiryContent_문의내용_amd.php');
var IC_DETAIL_WIDTH = 600;
var IC_DETAIL_HEIGHT = 660;
// 떠 있는 문의내역 팝업(Popup) — 없으면 null. 팝업이 닫히면 onClose가 null로 되돌린다.
var icDetailPopup = null;

/**
 * 문의내역(pcode)을 팝업에 보여준다.
 *  - 팝업이 떠 있고 준비됐으면: 그 팝업의 action('selectRecord', {pk}) — 입력폼/crudbuttons는
 *    다시 초기화하지 않고 값만 읽는다(저장 안 한 값이 있으면 팝업이 먼저 묻는다).
 *  - 떠 있지만 아직 로드 중이면: 팝업 안 주소만 바꾼다.
 *  - 없으면: 비모달 팝업을 화면 오른쪽에 새로 띄운다.
 */
function icOpenDetail(pcode) {
    var code = icText(pcode);

    if (code === '') {
        return;
    }
    return icShowDetail(
        IC_DETAIL_URL + '?code=' + encodeURIComponent(code),
        '문의내역 (' + code + ')',
        'selectRecord',
        { pk: code }
    );
}

/**
 * 문의 탭의 신규추가(action('addnew_문의내용')) — 이 문의(icCode)의 새 문의내역을 팝업에 연다.
 * 문의 code가 amd의 parentcode로 들어간다. 띄우는 조건은 더블클릭(icOpenDetail)과 같다:
 *  - 팝업이 떠 있고 준비됐으면: 그 팝업의 action('newRecord', {parentcode}) — 폼만 비운다.
 *  - 떠 있지만 아직 로드 중이면: 팝업 안 주소만 바꾼다(?code=new&parentcode=).
 *  - 없으면: 비모달 팝업을 화면 오른쪽에 새로 띄운다.
 * 아직 저장 안 한 신규 문의는 code가 없으므로 아무것도 안 한다.
 */
function icOpenDetailNew() {
    var parentCode = icText(icCode);

    if (icIsNew || parentCode === '' || parentCode === 'new') {
        return;
    }
    return icShowDetail(
        IC_DETAIL_URL + '?code=new&parentcode=' + encodeURIComponent(parentCode),
        '문의내역 (신규)',
        'newRecord',
        { parentcode: parentCode }
    );
}

/**
 * 문의내역 팝업을 띄우거나 재사용한다 — icOpenDetail / icOpenDetailNew가 같이 쓴다.
 *  - 팝업이 떠 있고 준비됐으면: 그 팝업의 action(pcmd, pparams).
 *  - 떠 있지만 아직 로드 중이면: 팝업 안 주소만 purl로 바꾼다.
 *  - 없으면: purl로 비모달 팝업을 화면 오른쪽에 새로 띄운다(제목 ptitle).
 */
function icShowDetail(purl, ptitle, pcmd, pparams) {
    var win;

    if (icDetailPopup !== null) {
        win = icDetailWindow();
        if (win !== null) {
            return win.action(pcmd, pparams, window);
        }
        icDetailPopup.iframeEl.src = purl;
        return;
    }

    icDetailPopup = new Popup({
        id: 'ic-detail',
        title: ptitle,
        modal: false,
        draggable: true,
        position: icDetailPosition(),
        iframeUrl: purl,
        iframeWidth: IC_DETAIL_WIDTH,
        iframeHeight: icDetailHeight(),
        bodyPadding: 0,     // 팝업 테두리와 입력폼 사이 여백 없음(기본 16px)

        onClose: function () {
            icDetailPopup = null;
        }
    });
}

/** 떠 있는 문의내역 팝업 안의 창 — 팝업이 없거나 아직 로드 전(action 없음)이면 null. */
function icDetailWindow() {
    var win = null;

    if (icDetailPopup === null || !icDetailPopup.iframeEl) {
        return null;
    }
    try {
        win = icDetailPopup.iframeEl.contentWindow;
    } catch (err) {
        win = null;
    }
    if (win === null || typeof win.action !== 'function') {
        return null;
    }
    return win;
}

/** 팝업 높이 — 기본 IC_DETAIL_HEIGHT, 화면이 작으면 화면에 맞춘다(머리줄 자리 빼고). */
function icDetailHeight() {
    var room = window.innerHeight - 60;

    if (room < IC_DETAIL_HEIGHT) {
        return Math.max(room, 300);
    }
    return IC_DETAIL_HEIGHT;
}

/** 팝업 위치 — 화면 오른쪽 위. */
function icDetailPosition() {
    var left = window.innerWidth - IC_DETAIL_WIDTH - 24;

    if (left < 10) {
        left = 10;
    }
    return { top: 10, left: left };
}

/**
 * 문의내역 팝업을 닫는다 — 다른 문의/신규로 바뀔 때(icApplyCode). 팝업의 창닫기와 같은
 * 길(action('close'))이라 저장 안 한 값이 있으면 팝업이 먼저 묻는다.
 */
function icCloseDetail() {
    var win;

    if (icDetailPopup === null) {
        return;
    }
    win = icDetailWindow();
    if (win !== null) {
        win.action('close', {}, window);
        return;
    }
    icDetailPopup.close();
}

// ── 수주/발주/견적 팝업(비모달) — 문의내역 팝업과 같은 규칙: 한 번 띄우면 닫을 때까지 재사용한다 ──
// 탭 키(수주/발주/견적)마다 팝업 하나. id는 amdReport의 popupId로 돌아와 어느 탭인지 알려 준다.
var IC_SUB_POPUPS = {
    '수주': { id: 'ic-order', url: encodeURIComponent('inquiryContent_수주_amd.php'), width: 600, height: 720 },
    '발주': { id: 'ic-purchase', url: encodeURIComponent('inquiryContent_발주_amd.php'), width: 600, height: 780 },
    '견적': { id: 'ic-quote', url: encodeURIComponent('inquiryContent_견적_amd.php'), width: 600, height: 860 }
};
// 떠 있는 팝업(Popup) {탭 키: Popup} — 없으면 null. 팝업이 닫히면 onClose가 null로 되돌린다.
var icSubPopups = { '수주': null, '발주': null, '견적': null };

/** pkey 탭의 행(pcode)을 팝업에 보여준다 — 띄우는 조건은 icOpenDetail과 같다(icShowSub). */
function icOpenSub(pkey, pcode) {
    var conf = IC_SUB_POPUPS[pkey];
    var code = icText(pcode);

    if (!conf || code === '') {
        return;
    }
    return icShowSub(
        pkey,
        conf.url + '?code=' + encodeURIComponent(code),
        pkey + ' (' + code + ')',
        'selectRecord',
        { pk: code }
    );
}

/**
 * pkey 탭의 신규추가(action('addnew_<탭>')) — 이 문의(icCode)의 새 행을 팝업에 연다.
 * 문의 code가 amd의 parentcode로 들어간다. 아직 저장 안 한 신규 문의는 아무것도 안 한다.
 */
function icOpenSubNew(pkey) {
    var conf = IC_SUB_POPUPS[pkey];
    var parentCode = icText(icCode);

    if (!conf || icIsNew || parentCode === '' || parentCode === 'new') {
        return;
    }
    return icShowSub(
        pkey,
        conf.url + '?code=new&parentcode=' + encodeURIComponent(parentCode),
        pkey + ' (신규)',
        'newRecord',
        { parentcode: parentCode }
    );
}

/**
 * pkey 탭의 팝업을 띄우거나 재사용한다 — icOpenSub / icOpenSubNew가 같이 쓴다.
 *  - 팝업이 떠 있고 준비됐으면: 그 팝업의 action(pcmd, pparams).
 *  - 떠 있지만 아직 로드 중이면: 팝업 안 주소만 purl로 바꾼다.
 *  - 없으면: purl로 비모달 팝업을 화면 오른쪽에 새로 띄운다(제목 ptitle).
 */
function icShowSub(pkey, purl, ptitle, pcmd, pparams) {
    var conf = IC_SUB_POPUPS[pkey];
    var win;

    if (icSubPopups[pkey] !== null) {
        win = icSubWindow(pkey);
        if (win !== null) {
            return win.action(pcmd, pparams, window);
        }
        icSubPopups[pkey].iframeEl.src = purl;
        return;
    }

    icSubPopups[pkey] = new Popup({
        id: conf.id,
        title: ptitle,
        modal: false,
        draggable: true,
        position: icSubPosition(conf.width),
        iframeUrl: purl,
        iframeWidth: conf.width,
        iframeHeight: icSubHeight(conf.height),
        bodyPadding: 0,     // 팝업 테두리와 입력폼 사이 여백 없음(기본 16px)

        onClose: function () {
            icSubPopups[pkey] = null;
        }
    });
}

/** 떠 있는 pkey 탭 팝업 안의 창 — 팝업이 없거나 아직 로드 전(action 없음)이면 null. */
function icSubWindow(pkey) {
    var popup = icSubPopups[pkey];
    var win = null;

    if (!popup || !popup.iframeEl) {
        return null;
    }
    try {
        win = popup.iframeEl.contentWindow;
    } catch (err) {
        win = null;
    }
    if (win === null || typeof win.action !== 'function') {
        return null;
    }
    return win;
}

/** 팝업 높이 — 기본 pheight, 화면이 작으면 화면에 맞춘다(머리줄 자리 빼고). */
function icSubHeight(pheight) {
    var room = window.innerHeight - 60;

    if (room < pheight) {
        return Math.max(room, 300);
    }
    return pheight;
}

/** 팝업 위치 — 화면 오른쪽 위(폭 pwidth). */
function icSubPosition(pwidth) {
    var left = window.innerWidth - pwidth - 24;

    if (left < 10) {
        left = 10;
    }
    return { top: 10, left: left };
}

/**
 * pkey 탭의 팝업을 닫는다 — 팝업의 창닫기와 같은 길(action('close'))이라 저장 안 한 값이
 * 있으면 팝업이 먼저 묻는다.
 */
function icCloseSub(pkey) {
    var win;

    if (!icSubPopups[pkey]) {
        return;
    }
    win = icSubWindow(pkey);
    if (win !== null) {
        win.action('close', {}, window);
        return;
    }
    icSubPopups[pkey].close();
}

/** 수주/발주/견적 팝업을 모두 닫는다 — 다른 문의/신규로 바뀔 때(icApplyCode). */
function icCloseAllSubs() {
    var keys = Object.keys(IC_SUB_POPUPS);
    var i;

    for (i = 0; i < keys.length; i++) {
        icCloseSub(keys[i]);
    }
}

/** 팝업 id(amdReport의 popupId) → 그 팝업의 탭 키. 수주/발주/견적 팝업이 아니면 '문의내용'. */
function icSubKeyOfPopup(ppopupId) {
    var keys = Object.keys(IC_SUB_POPUPS);
    var i;

    for (i = 0; i < keys.length; i++) {
        if (IC_SUB_POPUPS[keys[i]].id === ppopupId) {
            return keys[i];
        }
    }
    return '문의내용';
}

// ── 모델 검색 팝업(tb제품) — 문의내역 팝업 대신 이 화면이 띄운다(고객사 검색과 같은 크기) ──
var IC_MODEL_SEARCH_URL = encodeURIComponent('inquiryContent_모델검색_entry.php');
// 모델 검색을 연 창(문의내역 팝업) — 고른 값을 돌려줄 곳. 닫으면 null.
var icModelRequester = null;

/** 모델 검색 팝업을 연다 — pkeyword를 첫 검색어로, 고른 값은 prequester(창)에 돌려준다. 처리했으면 true. */
function icOpenModelSearch(pkeyword, prequester) {
    var frame = document.getElementById('ic-model-frame');

    icModelRequester = prequester || null;
    frame.src = IC_MODEL_SEARCH_URL + '?q=' + encodeURIComponent(icText(pkeyword).trim());
    document.getElementById('ic-model-modal').hidden = false;
    return true;
}

/** 모델 검색 팝업을 닫는다. */
function icCloseModelSearch() {
    document.getElementById('ic-model-modal').hidden = true;
    icModelRequester = null;
}

/** 고른 tb제품 행을 모델 검색을 연 창(문의내역 팝업)의 action('setModel')로 넘기고 팝업을 닫는다. */
function icSetModel(prow) {
    var requester = icModelRequester;

    icCloseModelSearch();
    if (requester === null || typeof requester.action !== 'function') {
        return;
    }
    try {
        requester.action('setModel', { row: prow }, window);
    } catch (err) {
        // 그 사이 문의내역 팝업이 닫혔으면 넘길 곳이 없다.
    }
}

// ── 고객사 검색 팝업 ──
/** 고객사 찾기(콤보 옆 아이콘) — 고객사 검색 팝업을 연다. iframe은 처음 열 때 불러온다. */
function icOpenCustomerSearch() {
    var frame = document.getElementById('ic-customer-frame');
    var input;

    if (frame.hasAttribute('data-src')) {
        frame.src = frame.getAttribute('data-src');
        frame.removeAttribute('data-src');
    }
    document.getElementById('ic-customer-modal').hidden = false;
    // 이미 불러온 팝업이면 검색칸에 바로 입력할 수 있게 한다.
    try {
        input = frame.contentWindow.document.getElementById('cs-keyword');
        if (input) {
            input.focus();
        }
    } catch (err) {
        // 아직 로드 중이면 넘어간다.
    }
}

/** 고객사 검색 팝업을 닫는다. */
function icCloseCustomerSearch() {
    document.getElementById('ic-customer-modal').hidden = true;
}

/**
 * 팝업에서 고른 고객사를 입력폼 콤보에 넣는다 — action('setCustomer', {pcode, pname}).
 * 콤보에 없는 code면(방금 추가된 고객사 등) 항목을 만들어 넣는다. 넣은 뒤 팝업을 닫고,
 * 고객사가 바뀌었으면 직원 목록도 그 고객사의 직원으로 바꾼다.
 */
function icSetCustomer(pcode, pname) {
    var el = document.getElementById('고객사code');
    var value = icText(pcode);
    var changed;

    if (value === '') {
        return;
    }
    changed = (el.value !== value);
    icEnsureOption('고객사code', value, pname);
    icSetField('고객사code', value);
    icCloseCustomerSearch();
    el.focus();
    icButtonsRefresh();
    if (changed) {
        icLoadStaff(value);
    }
}

// ── 탭 열기/닫기(index 셸) ──
/** 이 페이지가 들어 있는 index 탭을 닫는다(index 밖에서 열었으면 아무것도 안 함). */
function icCloseTab() {
    var frame = window.frameElement;
    var tabId = null;

    if (frame) {
        tabId = frame.getAttribute('data-tab-iframe');
    }
    if (tabId && window.parent && typeof window.parent.action === 'function') {
        window.parent.action('closeTab', { id: tabId }, window);
    }
}

// ── 화면 재사용 — 한 번 뜬 화면(입력폼, crudbuttons, 하위폼, talk)은 다시 열지 않는다 ──

/**
 * 떠 있는 이 화면에 다른 문의(pcode) 또는 신규('new')를 연다 — action('openInquiry').
 * 입력폼은 버튼 iframe에 맡긴다(code면 selectRecord, new면 신규입력 — 저장 안 한 값이
 * 있으면 crudbuttons가 먼저 묻는다). 하위폼/talk/제목은 그 뒤 이벤트(afterCrudSelectRecord /
 * afterCrudClear)가 icApplyCode로 바꾼다. 성공하면 index에서 이 탭으로 옮긴다.
 * 돌려주는 값: 'opened' / 'canceled'(묻는 창에서 취소, 또는 읽기 실패) /
 * 'unsupported'(버튼 iframe이 아직 준비 전 — 부른 쪽이 화면을 새로 연다).
 */
async function icOpenInquiry(pcode) {
    var code = icText(pcode);
    var buttons = icButtons();
    var ok;

    if (buttons === null || code === '') {
        return 'unsupported';
    }

    if (code === 'new') {
        ok = await buttons.action('clear', {}, window);
    } else {
        ok = await buttons.action('selectRecord', { pk: code }, window);
    }
    if (ok !== true) {
        return 'canceled';
    }

    icSetTabTitle(true);
    return 'opened';
}

/**
 * 화면을 문의 code(pcode, 신규면 'new')에 맞춘다 — 바뀐 게 없으면(같은 code) 아무것도 안 한다.
 *  - 신규: 하위폼 iframe과 talk을 숨긴다(지우지 않는다 — visible=false), 배경 흰색.
 *  - code: 하위폼을 다시 보이고 각자 action('setParent', {code})로 다시 읽게 한다
 *    (아직 안 연 탭은 주소만 바꿔 두고, 처음 누를 때 그 code로 열린다). talk도 그 code로.
 *  - 문서 제목과 index 탭 제목을 "문의 (code)" / "문의(new)"로.
 * icCode/icIsNew를 바꾸는 곳은 여기 한 곳뿐이다.
 */
function icApplyCode(pcode) {
    var code = icText(pcode);
    var frames;
    var i;

    if (code === '' || code === icCode) {
        return;
    }
    icCode = code;
    icIsNew = (code === 'new');

    // 문의내역/수주/발주/견적 팝업은 앞 문의의 것이라 닫는다.
    icCloseDetail();
    icCloseAllSubs();

    if (icIsNew) {
        document.body.classList.add('ic-no-code');
    } else {
        document.body.classList.remove('ic-no-code');
    }

    frames = document.querySelectorAll('iframe.ic-tab-frame');
    for (i = 0; i < frames.length; i++) {
        icApplyCodeToFrame(frames[i]);
    }

    if (icIsNew) {
        document.getElementById('ic-talk').hidden = true;
        document.getElementById('ic-talk-box').classList.add('ic-talk-empty');
    } else {
        document.getElementById('ic-talk').hidden = false;
        document.getElementById('ic-talk-box').classList.remove('ic-talk-empty');
        icShowTalk(icCode);
    }

    icSetTabTitle(false);
}

/**
 * 하위폼 iframe 하나를 지금 code(icCode)에 맞춘다.
 *  - 신규면 숨기기만 한다.
 *  - 이미 불러온 iframe이면 action('setParent', {code})로 다시 읽게 한다(아직 로드 중이면 주소를 바꾼다).
 *  - 아직 안 불러온 iframe: 보이는 탭이면 지금 불러오고, 숨은 탭이면 주소(data-src)만 바꿔 둔다.
 */
function icApplyCodeToFrame(pframe) {
    var url = icTabSrc(pframe.getAttribute('data-key'), icCode);
    var pane = pframe.closest('.ic-tabpane');
    var win = null;

    if (icIsNew) {
        pframe.hidden = true;
        return;
    }
    pframe.hidden = false;

    if (pframe.hasAttribute('src')) {
        try {
            win = pframe.contentWindow;
        } catch (err) {
            win = null;
        }
        if (win !== null && typeof win.action === 'function') {
            win.action('setParent', { code: icCode }, window);
        } else {
            pframe.src = url;
        }
        return;
    }

    if (pane !== null && pane.hidden) {
        pframe.setAttribute('data-src', url);
    } else {
        pframe.removeAttribute('data-src');
        pframe.src = url;
    }
}

/** 하위폼 주소 — inquiryContent_<탭키>_entry.php?code= (PHP의 ic_tab_src와 같다). */
function icTabSrc(pkey, pcode) {
    return encodeURIComponent('inquiryContent_' + pkey + '_entry.php') + '?code=' + encodeURIComponent(pcode);
}

/**
 * 문서 제목과 index 탭 제목을 지금 code로 맞춘다. pactivate가 true면 index에서 이 탭으로
 * 옮긴다(목록에서 더블클릭했을 때). iframe은 다시 불러오지 않는다(index의 setTabTitle).
 * index의 탭 주소(tab.src)는 처음 연 code 그대로 남지만, 이후 열기는 전부 openInquiry를
 * 거치므로 문제없다.
 */
function icSetTabTitle(pactivate) {
    var title;
    var frame = window.frameElement;
    var tabId = null;

    if (icIsNew) {
        title = '문의(new)';
    } else {
        title = '문의 (' + icCode + ')';
    }
    document.title = title;

    if (frame) {
        tabId = frame.getAttribute('data-tab-iframe');
    }
    if (tabId && window.parent !== window && typeof window.parent.action === 'function') {
        window.parent.action('setTabTitle', { id: tabId, title: title, activate: pactivate }, window);
    }
}

/**
 * 삭제 뒤 — "문의" 목록 탭으로 옮겨 다시 조회하고(index의 selectLeftMenu: 이미 열려
 * 있으면 그 탭으로 옮기며 requery, 없으면 새로 연다) 이 탭은 닫는다. 목록으로 먼저
 * 옮긴 뒤 닫아야 한다 — 닫으면 이 iframe이 없어져 뒤의 코드가 안 돈다.
 */
function icGoToListAndClose() {
    if (window.parent !== window && typeof window.parent.action === 'function') {
        window.parent.action('selectLeftMenu', { title: '문의' }, window);
    }
    icCloseTab();
}

/** {문의내역: 2, 수주: 1} → '문의내역 2건, 수주 1건'. 없으면 ''. */
function icCountText(pcounts) {
    var names;
    var text = '';
    var i;

    if (!pcounts) {
        return '';
    }
    names = Object.keys(pcounts);
    for (i = 0; i < names.length; i++) {
        if (text !== '') {
            text = text + ', ';
        }
        text = text + names[i] + ' ' + pcounts[names[i]] + '건';
    }
    return text;
}

// ── 탭 iframe ──
/**
 * 탭 iframe 하나에 action('requery', pparams)를 부른다(아직 로드 중이거나 action이 없으면
 * 넘어간다). pparams를 생략하면 {}.
 */
function icRequeryFrame(pframe, pparams) {
    if (!pparams) {
        pparams = {};
    }
    try {
        if (pframe.contentWindow && typeof pframe.contentWindow.action === 'function') {
            pframe.contentWindow.action('requery', pparams, window);
        }
    } catch (err) {
        // 아직 로드 중이면 조용히 넘어간다.
    }
}

/**
 * 이미 불러온 탭 iframe마다 action('requery')를 부른다(같은 출처라 직접 호출).
 * pcode를 주면 ptab 탭(없으면 '문의내용')에만 {code}로 넘겨 그 행으로 커서를 옮기게 한다
 * (다른 탭은 다른 테이블이라 넘기지 않는다).
 */
function icRequeryTabs(pcode, ptab) {
    var frames = document.querySelectorAll('iframe.ic-tab-frame[src]');
    var code = icText(pcode);
    var tab = icText(ptab);
    var i;

    if (tab === '') {
        tab = '문의내용';
    }
    for (i = 0; i < frames.length; i++) {
        if (code !== '' && frames[i].getAttribute('data-key') === tab) {
            icRequeryFrame(frames[i], { code: code });
        } else {
            icRequeryFrame(frames[i]);
        }
    }
}

/**
 * 하단 탭 전환 — 고른 탭만 보이고, 처음 보이는 탭이면 data-src를 src로 옮겨 그때
 * 불러오고, 이미 불러온 탭이면 다시 조회한다(index의 탭 전환과 같은 규칙).
 */
function icSelectBottomTab(pkey) {
    var buttons = document.querySelectorAll('#ic-bottom-tabs .ic-tabbar button');
    var panes = document.querySelectorAll('#ic-bottom-tabs .ic-tabpane');
    var frame;
    var i;

    // 신규 문의는 하위폼이 숨어 있다 — 탭 머리만 바꾸고 불러오거나 다시 읽지 않는다.
    if (icIsNew) {
        for (i = 0; i < buttons.length; i++) {
            if (buttons[i].getAttribute('data-tab') === pkey) {
                buttons[i].classList.add('ic-active');
            } else {
                buttons[i].classList.remove('ic-active');
            }
        }
        for (i = 0; i < panes.length; i++) {
            panes[i].hidden = (panes[i].getAttribute('data-pane') !== pkey);
        }
        return;
    }

    for (i = 0; i < buttons.length; i++) {
        if (buttons[i].getAttribute('data-tab') === pkey) {
            buttons[i].classList.add('ic-active');
        } else {
            buttons[i].classList.remove('ic-active');
        }
    }

    for (i = 0; i < panes.length; i++) {
        if (panes[i].getAttribute('data-pane') !== pkey) {
            panes[i].hidden = true;
            continue;
        }
        panes[i].hidden = false;
        frame = panes[i].querySelector('iframe.ic-tab-frame');
        if (frame === null) {
            continue;
        }
        if (frame.hasAttribute('data-src')) {
            frame.src = frame.getAttribute('data-src');
            frame.removeAttribute('data-src');
        } else if (frame.hasAttribute('src')) {
            icRequeryFrame(frame);
        }
    }
}

/** 하단 탭 머리 하나에 클릭을 단다(for 안에서 바로 달면 var가 마지막 값만 기억하므로 따로 뺐다). */
function icBindBottomTab(pbutton) {
    pbutton.addEventListener('click', function () {
        icSelectBottomTab(pbutton.getAttribute('data-tab'));
    });
}

/** 위쪽 "문의" 탭 머리 — 눌러도 다시 조회한다(탭이 하나뿐이라 전환은 없다). */
function icBindTopTab(pbutton) {
    pbutton.addEventListener('click', function () {
        var frame = pbutton.closest('.ic-tabs').querySelector('iframe.ic-tab-frame[src]');

        if (icIsNew) {
            return;
        }
        if (frame !== null) {
            icRequeryFrame(frame);
        }
    });
}

// ── 시작 — 이벤트 연결 ──
function icInit() {
    var bottomButtons = document.querySelectorAll('#ic-bottom-tabs .ic-tabbar button');
    var topButtons = document.querySelectorAll('.ic-top .ic-tabbar button');
    var i;

    // 고객사를 콤보에서 바꾸면 직원 목록도 그 고객사의 직원으로 바꾼다(직원은 고객사에 딸려 온다).
    document.getElementById('고객사code').addEventListener('change', function () {
        icLoadStaff(this.value);
    });
    // 직원을 고르면 그 직원의 직책/회사전화번호/휴대폰/팩스/이메일/근무지주소를 채운다.
    document.getElementById('고객사직원code').addEventListener('change', function () {
        icFillFromStaff(this.value);
    });

    document.getElementById('ic-customer-find').addEventListener('click', icOpenCustomerSearch);
    document.getElementById('ic-customer-modal-close').addEventListener('click', icCloseCustomerSearch);
    // 팝업 바깥(반투명 막)을 누르거나 Esc를 누르면 닫는다.
    document.getElementById('ic-customer-modal').addEventListener('click', function (pevent) {
        if (pevent.target === this) {
            icCloseCustomerSearch();
        }
    });
    document.addEventListener('keydown', function (pevent) {
        if (pevent.key === 'Escape' && !document.getElementById('ic-customer-modal').hidden) {
            icCloseCustomerSearch();
        }
        if (pevent.key === 'Escape' && !document.getElementById('ic-model-modal').hidden) {
            icCloseModelSearch();
        }
    });

    // 모델 검색 팝업 — × 버튼, 바깥(반투명 막) 클릭으로 닫는다.
    document.getElementById('ic-model-modal-close').addEventListener('click', icCloseModelSearch);
    document.getElementById('ic-model-modal').addEventListener('click', function (pevent) {
        if (pevent.target === this) {
            icCloseModelSearch();
        }
    });

    for (i = 0; i < bottomButtons.length; i++) {
        icBindBottomTab(bottomButtons[i]);
    }
    for (i = 0; i < topButtons.length; i++) {
        icBindTopTab(topButtons[i]);
    }
}

icInit();
</script>
<?php endif; ?>
</body>
</html>
