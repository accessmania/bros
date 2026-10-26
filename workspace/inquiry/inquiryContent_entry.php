<?php
declare(strict_types=1);

/**
 * [문의확인 화면 — 문의 한 건(?code=<t문의.code>)의 상세]
 * 구성: inquiryContent_entry.php(이 파일) + inquiryContent_fetch.php(입력폼 읽기/저장)
 * + 탭마다 페이지 하나씩(inquiryContent_<탭>_entry.php / _fetch.php).
 * 메뉴에는 없고, 문의 목록(inquiry_entry.php)에서 행을 더블클릭하면 index의
 * setContent()가 "문의확인" 탭으로 이 페이지를 연다. 탭은 하나만 두고 돌려쓴다 —
 * 다른 문의/신규를 열면 같은 탭의 주소(?code=)만 바뀐다.
 *
 * 배치(바탕화면 문의확인.png):
 *  - 좌측 상단: t문의 입력폼 + 저장/삭제/신규추가/창닫기 버튼
 *  - 우측 상단: "문의" 탭 — iframe(inquiryContent_문의내용_entry.php)
 *  - 하단: 탭 5개 — 각각 iframe(inquiryContent_수주/발주/수주히스토리/
 *    발주히스토리/견적_entry.php). 처음 누를 때 불러온다(첫 탭 수주는 바로).
 *  - 오른쪽: talk 모듈(ttalk, type = TALK_TYPE, parentcode = code)
 *  - 고객사 콤보 옆 찾기 버튼 → 고객사 검색 팝업(모달 안 iframe,
 *    inquiryContent_고객사검색_entry.php). 더블클릭하면 action('setCustomer')로
 *    고객사code/고객사가 입력폼에 들어온다.
 *  탭 페이지는 모두 ?code=로 이 문의 code를 받는다(IC_TABS 참고).
 *
 * 지금 동작하는 것: 입력폼 표시/저장, 창닫기, 탭 6개(조회), talk.
 * 버튼 규칙: 삭제는 code가 있을 때만 활성(딸린 문의내역/수주/발주/견적/talk 대화도 함께 삭제),
 * 저장/신규추가(같은 탭을 신규로 다시 열기)/창닫기는 항상 활성.
 *
 * 페이지 함수:
 *  - requery() — 입력폼을 서버 값으로 다시 읽고, 불러온 탭 iframe마다
 *    action('requery')를 부른다.
 *  - action(pcmd, pparams, psender) — 'requery'. index가 탭을 옮길 때
 *    부르는데, 입력폼에 저장 안 한 값이 있으면 건너뛴다.
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

/**
 * ttalk.type — 문의 대화의 종류. Access 원본과 같은 'test'
 * (mTalk.init(Me.subtalk.Form, "test", Me.code, ...)).
 * inquiryContent_fetch.php의 TALK_TYPE(문의 삭제 때 함께 지울 대화)과 같아야 한다.
 */
const TALK_TYPE = 'test';

// talk에 넘길 로그인 정보 — 값이 없으면 code는 '0', 이름은 '무명'(Access의 nz_와 같음).
$talkLoginCode = (string) Login::getValue('code');
$talkLoginCode = $talkLoginCode === '' ? '0' : $talkLoginCode;
$talkLoginName = (string) Login::getValue('이름');
$talkLoginName = $talkLoginName === '' ? '무명' : $talkLoginName;

$code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
// code=new — 신규 문의. 입력폼/탭/talk 모두 빈 칸으로 열고, 저장하면 insert한 뒤
// 같은 "문의확인" 탭을 새 code로 다시 연다(icSave → icOpenSaved 참고).
$isNew = $code === 'new';

$connectionName = null; // config/db.php의 'driver' 기본 접속을 따른다
$db = new DbContext($connectionName);

// 입력폼 첫 값 — 화면이 뜰 때 PHP가 바로 채운다(requery()는 fetch의 action=record로 다시 읽음).
$record = null;
$loadError = '';
if ($isNew) {
    // 모든 칸 빈 값 — 문의일자는 오늘(기본값, 바꿀 수 있음), 멤버는 로그인한
    // 사람으로 보여준다(저장할 때 서버가 멤버code/멤버를 세션 값으로 넣는다,
    // inquiryContent_fetch.php).
    $record = [
        '문의일자' => date('Y-m-d'),
        '멤버code' => (string) Login::getValue('code'),
        '멤버' => (string) Login::getValue('이름'),
    ];
} elseif ($code === '' || !ctype_digit($code)) {
    $loadError = '문의 code가 없습니다. 문의 목록에서 행을 더블클릭해 여세요.';
} else {
    $crud = new Crud($db);
    $crud->init('t문의');
    if ($crud->selectRecord($code)) {
        $record = $crud->getAllValues();
        $record['문의일자'] = Str::toDate($record['문의일자'] ?? null, '');
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

// 하단 탭 — [탭키(파일 이름), 탭 글자]. 첫 탭이 처음에 보인다.
$bottomTabs = [
    ['수주', '수주'],
    ['발주', '발주'],
    ['수주히스토리', '수주 히스토리'],
    ['발주히스토리', '발주 히스토리'],
    ['견적', '견적'],
];

/** 입력칸 value 속성용 — 레코드 값(없으면 ''). */
function ic_value(?array $record, string $field): string
{
    if ($record === null || !isset($record[$field])) {
        return '';
    }
    return htmlspecialchars((string) $record[$field], ENT_QUOTES, 'UTF-8');
}

$fetchUrl = APP_BASE_URL . '/workspace/inquiry/inquiryContent_fetch.php?code=' . rawurlencode($code);
$talkJsPath = SRC_DIR . 'js/talk/talk.js';
$talkJsVersion = is_file($talkJsPath) ? filemtime($talkJsPath) : time();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title><?= $isNew ? '문의(new)' : '문의 (' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . ')' ?></title>
<!-- document.currentScript로 talk_fetch.php 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/talk/talk.js?v=<?= $talkJsVersion ?>"></script>
<style>
/* 화면 배경색 하나(--ic-bg)를 페이지/박스/탭 칸이 같이 쓴다(탭 머리는 따로 — 고른 탭 흰색, 나머지 회색).
   code가 있는 문의는 회색, code가 없을 때(신규 문의 등)는 모두 흰색(body.ic-no-code).
   탭 페이지(iframe)는 자기 배경이 없어 탭 칸 색이 그대로 비친다. */
:root { --ic-bg: #f3f4f6; }
body.ic-no-code { --ic-bg: #ffffff; }

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
/* 신규 문의 — talk을 띄우지 않고 talk 채팅영역 색의 빈 칸만 둔다. */
.ic-talk.ic-talk-empty { background: #9bbbd4; }

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
.ic-form .ic-dirty { background: #fffbeb; }
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

.ic-buttons { display: flex; justify-content: center; gap: 6px; margin-top: 12px; }
.ic-buttons button {
    font: inherit; height: 28px; padding: 0 14px; cursor: pointer;
    border: 1px solid #d1d5db; border-radius: 4px; background: #fff; color: inherit;
}
.ic-buttons button:hover:not(:disabled) { background: #f3f4f6; }
.ic-buttons button:disabled { color: #9ca3af; cursor: not-allowed; }
.ic-buttons .ic-save { background: #bfdbfe; border-color: #bfdbfe; }
.ic-buttons .ic-save:hover { background: #93c5fd; }
.ic-message { margin-top: 8px; min-height: 16px; text-align: center; color: #6b7280; font-size: 12px; }
.ic-message.ic-error { color: #dc2626; }

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

.ic-load-error { padding: 40px; text-align: center; color: #dc2626; font-size: 14px; }

/* 고객사 검색 팝업(모달) — 화면 위에 반투명 막을 깔고 가운데 창을 띄운다.
   창 안은 iframe(inquiryContent_고객사검색_entry.php). */
.ic-modal { position: fixed; inset: 0; z-index: 100; display: flex; align-items: center; justify-content: center; background: rgba(15, 23, 42, 0.35); }
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
.ic-modal-window iframe { flex: 1; width: 100%; border: 0; display: block; }
</style>
</head>
<body<?= ($isNew || $record === null) ? ' class="ic-no-code"' : '' ?>>
<?php if ($record === null): ?>
<div class="ic-load-error"><?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></div>
<?php else: ?>
<div class="ic-page">
    <div class="ic-main">
        <div class="ic-top">
            <!-- 좌측 상단: t문의 입력폼 -->
            <div class="ic-box ic-form-box">
                <form class="ic-form" id="ic-form" autocomplete="off" onsubmit="return false;">
                    <label for="ic-문의일자">문의일자</label>
                    <input type="date" id="ic-문의일자" name="문의일자" value="<?= ic_value($record, '문의일자') ?>">
                    <label for="ic-상태">상태</label>
                    <select id="ic-상태" name="상태">
<?php foreach ($statusOptions as $status): ?>
                        <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"<?= $status === $currentStatus ? ' selected' : '' ?>><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
                    </select>

                    <label for="ic-멤버">멤버</label>
                    <input type="text" id="ic-멤버" value="<?= ic_value($record, '멤버') ?>" readonly>
                    <label for="ic-고객사code">고객사</label>
                    <!-- 고객사 콤보(tb고객사: 값 code, 표시 회사명) + 찾기 버튼 -->
                    <div class="ic-with-button">
                        <select id="ic-고객사code" name="고객사code">
<?php foreach ($customerOptions as [$customerCode, $customerName]): ?>
                            <option value="<?= htmlspecialchars($customerCode, ENT_QUOTES, 'UTF-8') ?>"<?= $customerCode === $currentCustomerCode ? ' selected' : '' ?>><?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
                        </select>
                        <button type="button" class="ic-icon-btn" id="ic-customer-find" title="고객사 찾기" aria-label="고객사 찾기">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="10.5" cy="10.5" r="6.5"/><path d="M15.5 15.5 21 21"/></svg>
                        </button>
                    </div>

                    <label for="ic-고객사직원code">고객사직원</label>
                    <!-- 고객사직원 콤보(tb고객사_직원목록, 고른 고객사의 직원만: 값 code, 표시 담당자) -->
                    <select id="ic-고객사직원code" name="고객사직원code">
<?php foreach ($staffOptions as [$staffCode, $staffName]): ?>
                        <option value="<?= htmlspecialchars($staffCode, ENT_QUOTES, 'UTF-8') ?>"<?= $staffCode === $currentStaffCode ? ' selected' : '' ?>><?= htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
                    </select>
                    <label for="ic-휴대폰">휴대폰</label>
                    <input type="text" id="ic-휴대폰" name="휴대폰" value="<?= ic_value($record, '휴대폰') ?>">

                    <label for="ic-직책">직책</label>
                    <input type="text" id="ic-직책" name="직책" value="<?= ic_value($record, '직책') ?>">
                    <label for="ic-팩스">팩스</label>
                    <input type="text" id="ic-팩스" name="팩스" value="<?= ic_value($record, '팩스') ?>">

                    <label for="ic-회사전화번호">회사전화번호</label>
                    <input type="text" id="ic-회사전화번호" name="회사전화번호" value="<?= ic_value($record, '회사전화번호') ?>">
                    <label for="ic-이메일">이메일</label>
                    <input type="text" id="ic-이메일" name="이메일" value="<?= ic_value($record, '이메일') ?>">

                    <label for="ic-근무지주소">근무지주소</label>
                    <textarea id="ic-근무지주소" name="근무지주소" class="ic-wide"><?= ic_value($record, '근무지주소') ?></textarea>

                    <label for="ic-배송주소">배송주소</label>
                    <textarea id="ic-배송주소" name="배송주소" class="ic-wide"><?= ic_value($record, '배송주소') ?></textarea>

                    <label for="ic-비고">비고</label>
                    <textarea id="ic-비고" name="비고" class="ic-wide ic-memo"><?= ic_value($record, '비고') ?></textarea>
                </form>
                <div class="ic-buttons">
                    <!-- 버튼 규칙: 삭제는 code가 있을 때만(신규면 비활성), 저장/신규추가/창닫기는 항상 활성. -->
                    <button type="button" class="ic-save" id="ic-save">저장</button>
                    <button type="button" id="ic-delete"<?= $isNew ? ' disabled title="저장 전인 신규 문의는 삭제할 수 없습니다"' : '' ?>>삭제</button>
                    <button type="button" id="ic-new">신규추가</button>
                    <button type="button" id="ic-close">창닫기</button>
                </div>
                <div class="ic-message" id="ic-message"></div>
            </div>

            <!-- 우측 상단: "문의" 탭 — inquiryContent_문의내용_entry.php -->
            <div class="ic-tabs">
                <div class="ic-tabbar"><button type="button" class="ic-active">문의</button></div>
                <div class="ic-tabpane ic-box">
                    <iframe class="ic-tab-frame" <?= ic_tab_src_attr('문의내용', $code, $isNew) ?> title="문의"></iframe>
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
                <iframe class="ic-tab-frame" <?= ic_tab_src_attr($key, $code, $isNew, $i !== 0) ?> title="<?= $label ?>"></iframe>
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

    <!-- 오른쪽: talk -->
    <div class="ic-talk<?= $isNew ? ' ic-talk-empty' : '' ?>" id="ic-talk"></div>
</div>

<script>
var IC_CODE = <?= json_encode($code) ?>;
// 신규 문의(code=new) — 아직 DB에 없다. 저장하면 insert된다.
var IC_IS_NEW = <?= $isNew ? 'true' : 'false' ?>;
var IC_FETCH_URL = <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
// 고객사/고객사직원은 콤보(고객사code/고객사직원code)로 고르고, 저장할 때 고른
// 이름(회사명/담당자)을 고객사/고객사직원에 함께 넣는다(icSave).
var IC_FIELDS = ['문의일자', '상태', '고객사code', '고객사직원code', '휴대폰', '직책', '팩스', '회사전화번호', '이메일', '근무지주소', '배송주소', '비고'];

// 직원을 고르면 채우는 입력폼 칸(PHP의 STAFF_DETAIL_FIELDS)과, 지금 콤보 직원들의 그 값
// {직원code: {직책, 회사전화번호, ...}}. 고객사를 바꿔 직원 목록을 다시 받으면 icLoadStaff가 바꾼다.
var IC_STAFF_DETAIL_FIELDS = <?= json_encode(STAFF_DETAIL_FIELDS, JSON_UNESCAPED_UNICODE) ?>;
var IC_STAFF_DETAILS = <?= json_encode((object) array_column(array_filter($staffOptions, function (array $o): bool { return isset($o[2]); }), 2, 0), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

// ── 오른쪽 talk — ttalk(type = TALK_TYPE, parentcode = 문의 code) ──
// 신규 문의는 대화를 달 code가 아직 없으므로 talk을 띄우지 않는다(빈 칸).
var icTalk = null;
if (!IC_IS_NEW) {
    icTalk = new Talk('ic-talk');
    icTalk.action('init', {
        type: <?= json_encode(TALK_TYPE, JSON_UNESCAPED_UNICODE) ?>,
        parentcode: IC_CODE,
        // Access의 nz_(mLogin.getvalue("code"), 0) / nz_(mLogin.getvalue("이름"), "무명")과 같다.
        logincode: <?= json_encode($talkLoginCode, JSON_UNESCAPED_UNICODE) ?>,
        loginname: <?= json_encode($talkLoginName, JSON_UNESCAPED_UNICODE) ?>,
        pollSeconds: 0
    });
}

// ── 입력폼 ──
function icField(name) {
    return document.getElementById('ic-' + name);
}

function icMessage(text, isError) {
    var el = document.getElementById('ic-message');
    el.textContent = text || '';
    el.classList.toggle('ic-error', !!isError);
}

/** 폼에 레코드 값을 채우고 "수정됨" 표시를 지운다. */
function icFill(record) {
    IC_FIELDS.concat(['멤버']).forEach(function (name) {
        var el = icField(name);
        if (!el) {
            return;
        }
        var value = record[name] == null ? '' : String(record[name]);
        if (el.tagName === 'SELECT' && value !== '' && !Array.prototype.some.call(el.options, function (o) { return o.value === value; })) {
            // 목록에 없는 값 — 고객사/직원 code면 저장돼 있던 이름으로 보여준다.
            var nameField = { '고객사code': '고객사', '고객사직원code': '고객사직원' }[name];
            var label = nameField && record[nameField] ? String(record[nameField]) : value;
            el.add(new Option(label, value), 0);
        }
        el.value = value;
        if (el.tagName === 'SELECT' && value === '') {
            el.selectedIndex = -1;
        }
        el.classList.remove('ic-dirty');
    });
}

/**
 * 고른 직원(staffCode)의 직책/회사전화번호/휴대폰/팩스/이메일/근무지주소를 입력폼에
 * 채운다(직원목록 값 그대로 — 비어 있으면 칸도 비운다). 바뀐 칸에는 "수정됨" 표시.
 * 직원목록에 없는 직원(저장돼 있던 이름만 있는 경우)이면 아무것도 바꾸지 않는다.
 */
function icFillFromStaff(staffCode) {
    var details = IC_STAFF_DETAILS[String(staffCode)];
    if (!details) {
        return;
    }
    IC_STAFF_DETAIL_FIELDS.forEach(function (name) {
        var el = icField(name);
        var value = details[name] == null ? '' : String(details[name]);
        if (el && el.value !== value) {
            el.value = value;
            el.classList.add('ic-dirty');
        }
    });
}

/** 콤보에서 고른 항목의 표시 글자(회사명/담당자). 고르지 않았으면 ''. */
function icSelectedText(name) {
    var el = icField(name);
    return el.selectedIndex === -1 ? '' : el.options[el.selectedIndex].text;
}

/**
 * 고객사직원 콤보를 고객사(customerCode)의 직원으로 다시 채운다 — 직원은 고객사에
 * 딸려 온다. keepValue/keepName을 주면(requery로 다시 읽을 때) 그 직원을 고르고,
 * 목록에 없으면 저장돼 있던 이름으로 넣어 둔다. 안 주면 선택을 비우고 "수정됨"
 * 표시를 붙인다(고객사를 바꿨으니 직원도 다시 골라야 한다).
 */
function icLoadStaff(customerCode, keepValue, keepName) {
    var el = icField('고객사직원code');
    var keep = keepValue == null ? '' : String(keepValue);
    while (el.options.length) {
        el.remove(0);
    }
    el.selectedIndex = -1;
    var request = customerCode
        ? fetch(IC_FETCH_URL + '&action=staff&customer=' + encodeURIComponent(customerCode), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) { return res.json(); })
        : Promise.resolve({ staff: [] });
    return request
        .then(function (data) {
            if (!data.staff) {
                throw new Error(data.error || '직원 목록을 불러오지 못했습니다.');
            }
            IC_STAFF_DETAILS = {};
            data.staff.forEach(function (pair) {
                el.add(new Option(pair[1], pair[0]));
                IC_STAFF_DETAILS[String(pair[0])] = pair[2] || {};
            });
            if (keep !== '') {
                if (!data.staff.some(function (pair) { return String(pair[0]) === keep; })) {
                    el.add(new Option(keepName ? String(keepName) : keep, keep), 0);
                }
                el.value = keep;
                el.classList.remove('ic-dirty');
            } else {
                el.selectedIndex = -1;
                if (keepValue === undefined) {
                    el.classList.add('ic-dirty');
                }
            }
        })
        .catch(function (e) {
            icMessage(e.message, true);
        });
}

// ── 고객사 검색 팝업 ──
/** 고객사 찾기(콤보 옆 아이콘) — 고객사 검색 팝업을 연다. iframe은 처음 열 때 불러온다. */
function icOpenCustomerSearch() {
    var frame = document.getElementById('ic-customer-frame');
    if (frame.hasAttribute('data-src')) {
        frame.src = frame.getAttribute('data-src');
        frame.removeAttribute('data-src');
    }
    document.getElementById('ic-customer-modal').hidden = false;
    // 이미 불러온 팝업이면 검색칸에 바로 입력할 수 있게 한다.
    try {
        var input = frame.contentWindow.document.getElementById('cs-keyword');
        if (input) {
            input.focus();
        }
    } catch (e) {
        // 아직 로드 중이면 넘어간다.
    }
}

/** 고객사 검색 팝업을 닫는다. */
function icCloseCustomerSearch() {
    document.getElementById('ic-customer-modal').hidden = true;
}

/**
 * 팝업에서 고른 고객사를 입력폼 콤보에 넣는다 — action('setCustomer', {pcode, pname}).
 * 콤보에 없는 code면(방금 추가된 고객사 등) 항목을 만들어 넣는다. 넣은 뒤 팝업을 닫는다.
 */
function icSetCustomer(pcode, pname) {
    if (pcode == null || pcode === '') {
        return;
    }
    var el = icField('고객사code');
    var value = String(pcode);
    var changed = el.value !== value;
    var option = Array.prototype.find.call(el.options, function (o) { return o.value === value; });
    if (!option) {
        option = new Option(pname == null ? value : String(pname), value);
        el.add(option, 0);
    }
    el.value = value;
    el.classList.add('ic-dirty');
    icCloseCustomerSearch();
    el.focus();
    // 고객사가 바뀌었으면 직원 목록도 그 고객사의 직원으로 바꾼다.
    if (changed) {
        icLoadStaff(value);
    }
}

function icFormDirty() {
    return document.querySelectorAll('#ic-form .ic-dirty').length > 0;
}

function icSave() {
    var values = {};
    IC_FIELDS.forEach(function (name) {
        values[name] = icField(name).value;
    });
    // code와 함께 그 이름도 저장한다(t문의에 이름 사본이 있다).
    values['고객사'] = icSelectedText('고객사code');
    values['고객사직원'] = icSelectedText('고객사직원code');
    icMessage('저장 중...');
    return fetch(IC_FETCH_URL + '&action=save_record', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ code: IC_CODE, values: values })
    })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data.ok) {
                throw new Error(data.error || '저장 실패');
            }
            document.querySelectorAll('#ic-form .ic-dirty').forEach(function (el) { el.classList.remove('ic-dirty'); });
            icMessage('저장했습니다.');
            // 신규를 처음 저장했으면 같은 탭을 새 code로 다시 연다.
            if (data.isNew && data.code) {
                icOpenSaved(data.code);
            }
        })
        .catch(function (e) {
            icMessage('저장 실패: ' + e.message, true);
        });
}

/** 이 페이지가 들어 있는 index 탭을 닫는다(index 밖에서 열었으면 아무것도 안 함). */
function icCloseTab() {
    var frame = window.frameElement;
    var tabId = frame ? frame.getAttribute('data-tab-iframe') : null;
    if (tabId && window.parent && typeof window.parent.action === 'function') {
        window.parent.action('closeTab', { id: tabId }, window);
    }
}

/** 창닫기 — 저장 안 한 값이 있으면 묻고 탭을 닫는다. */
function icClose() {
    if (icFormDirty() && !confirm('저장하지 않은 내용이 있습니다. 닫을까요?')) {
        return;
    }
    icCloseTab();
}

/**
 * 이 화면을 다른 code(또는 'new')로 다시 연다 — 탭은 탭키 "문의확인" 하나를
 * 돌려쓰고, 제목만 "문의 (code)"로 바뀐다. 이 iframe만 이동하지 않고 index의
 * setContent()를 거친다: 그래야 index가 기억하는 탭 주소(tab.src)/제목도 같이
 * 바뀌어, 나중에 목록에서 같은 code를 더블클릭해도 맞게 열린다. index 밖에서
 * 열었으면 이 창에서 바로 연다.
 */
var IC_TAB_KEY = '문의확인';

function icOpen(pcode) {
    var url = 'workspace/inquiry/inquiryContent_entry.php?code=' + encodeURIComponent(pcode);
    if (window.parent !== window && typeof window.parent.setContent === 'function') {
        window.parent.setContent(url, '문의 (' + pcode + ')', IC_TAB_KEY);
        return;
    }
    location.href = 'inquiryContent_entry.php?code=' + encodeURIComponent(pcode);
}

/**
 * 신규추가 — 문의 목록/문의 탭의 신규추가와 같다: 같은 "문의확인" 탭을 신규
 * (code=new)로 다시 연다. 저장 안 한 값이 있으면 먼저 묻는다.
 */
function icNew() {
    if (icFormDirty() && !confirm('저장하지 않은 내용이 있습니다. 버리고 새 문의를 열까요?')) {
        return;
    }
    icOpen('new');
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

/** {문의내역: 2, 수주: 1} → '문의내역 2건, 수주 1건' */
function icCountText(counts) {
    return Object.keys(counts || {}).map(function (label) {
        return label + ' ' + counts[label] + '건';
    }).join(', ');
}

/**
 * 삭제 — code가 있는 문의만(신규는 버튼이 비활성). 딸린 문의내역/수주/발주/견적
 * 건수를 먼저 받아 확인창에 보여주고, 확인하면 문의와 함께 지운 뒤 이 탭을 닫는다.
 */
function icDelete() {
    if (IC_IS_NEW) {
        return;
    }
    var headers = { 'X-Requested-With': 'XMLHttpRequest' };
    return fetch(IC_FETCH_URL + '&action=children', { headers: headers })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            var children = icCountText(data.children);
            var question = '문의(' + IC_CODE + ')를 삭제할까요? 되돌릴 수 없습니다.'
                + (children ? '\n\n딸린 자료도 함께 삭제됩니다: ' + children : '');
            if (!confirm(question)) {
                return;
            }
            icMessage('삭제 중...');
            return fetch(IC_FETCH_URL + '&action=delete_record', { method: 'POST', headers: headers })
                .then(function (res) { return res.json(); })
                .then(function (result) {
                    if (!result.ok) {
                        throw new Error(result.error || '삭제 실패');
                    }
                    icMessage('삭제했습니다.');
                    icGoToListAndClose();
                });
        })
        .catch(function (e) {
            icMessage('삭제 실패: ' + e.message, true);
        });
}

/**
 * 신규 문의를 저장한 뒤 — 같은 "문의확인" 탭을 새 code로 다시 연다(탭, talk이
 * 그 code로 채워진다). 방금 저장해서 저장 안 한 값은 없다.
 */
function icOpenSaved(newCode) {
    icOpen(newCode);
}

// ── 탭 iframe ──
/** 이미 불러온 탭 iframe마다 action('requery')를 부른다(같은 출처라 직접 호출). */
function icRequeryTabs() {
    document.querySelectorAll('iframe.ic-tab-frame[src]').forEach(icRequeryFrame);
}

/** talk 대화를 다시 읽어 그린다(신규 문의는 talk이 없어 넘어간다). */
function icRequeryTalk() {
    if (icTalk) {
        icTalk.action('show');
    }
}

/**
 * requery() — 이 화면 전체를 서버 값으로 다시 읽는다: 입력폼(+ 고객사에 딸린 직원
 * 목록), 불러온 탭 iframe들(문의/수주/발주/히스토리/견적), talk 대화.
 * index에서 이 탭으로 옮겨 올 때(탭바 클릭, 좌측 메뉴, 옆 탭이 닫힐 때) index가
 * action('requery')로 부른다 — 입력폼에 저장 안 한 값이 있으면 action이 건너뛴다.
 */
function requery() {
    // 신규 문의는 아직 DB에 없어 다시 읽을 게 없다.
    if (IC_IS_NEW) {
        return Promise.resolve();
    }
    icRequeryTabs();
    icRequeryTalk();
    return fetch(IC_FETCH_URL + '&action=record', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data.record) {
                throw new Error(data.error || '불러오기 실패');
            }
            icFill(data.record);
            icMessage('');
            // 직원 목록은 고객사에 딸려 오므로 그 고객사의 직원으로 다시 채운다.
            return icLoadStaff(data.record['고객사code'], data.record['고객사직원code'] || null, data.record['고객사직원']);
        })
        .catch(function (e) {
            icMessage('불러오기 실패: ' + e.message, true);
        });
}

/**
 * action(pcmd, pparams, psender) — 바깥(index 셸)과 안쪽(탭/팝업 iframe)이 이 화면을
 * 부르는 창구.
 *  - 'requery' — index가 탭을 옮길 때 부른다. 입력폼에 저장 안 한 값이 있으면 건너뛴다.
 *  - 'setCustomer' {pcode, pname} — 고객사 검색 팝업이 더블클릭한 고객사를 넘긴다.
 *  - 'closeCustomerSearch' — 고객사 검색 팝업을 닫는다(팝업에서 Esc).
 */
function action(pcmd, pparams, psender) {
    pparams = pparams || {};
    switch (pcmd) {
        case 'requery':
            if (icFormDirty()) {
                return;
            }
            return requery();
        case 'setCustomer':
            return icSetCustomer(pparams.pcode, pparams.pname);
        case 'closeCustomerSearch':
            return icCloseCustomerSearch();
        default:
            console.warn('문의확인.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

document.querySelectorAll('#ic-form input, #ic-form select, #ic-form textarea').forEach(function (el) {
    if (el.readOnly) {
        return;
    }
    el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', function () {
        el.classList.add('ic-dirty');
    });
});
document.getElementById('ic-save').addEventListener('click', icSave);
document.getElementById('ic-delete').addEventListener('click', icDelete);
document.getElementById('ic-new').addEventListener('click', icNew);
document.getElementById('ic-customer-find').addEventListener('click', icOpenCustomerSearch);
// 고객사를 콤보에서 바꾸면 직원 목록도 그 고객사의 직원으로 바꾼다(직원은 고객사에 딸려 온다).
icField('고객사code').addEventListener('change', function () {
    icLoadStaff(this.value);
});
// 직원을 고르면 그 직원의 직책/회사전화번호/휴대폰/팩스/이메일/근무지주소를 채운다.
icField('고객사직원code').addEventListener('change', function () {
    icFillFromStaff(this.value);
});
document.getElementById('ic-customer-modal-close').addEventListener('click', icCloseCustomerSearch);
// 팝업 바깥(반투명 막)을 누르거나 Esc를 누르면 닫는다.
document.getElementById('ic-customer-modal').addEventListener('click', function (e) {
    if (e.target === this) {
        icCloseCustomerSearch();
    }
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !document.getElementById('ic-customer-modal').hidden) {
        icCloseCustomerSearch();
    }
});
document.getElementById('ic-close').addEventListener('click', icClose);

// 값이 없는 콤보(신규 문의, 또는 기존 문의의 빈 상태/고객사)는 첫 항목이 아니라
// 빈 칸(선택 없음)으로 시작한다 — 그대로 두면 브라우저가 첫 항목을 보여주고, 저장할
// 때 그 값이 들어가 버린다. PHP가 selected를 붙인 항목이 없으면 값이 없는 것이다.
document.querySelectorAll('#ic-form select').forEach(function (el) {
    var hasStored = Array.prototype.some.call(el.options, function (o) { return o.defaultSelected; });
    if (IC_IS_NEW || !hasStored) {
        el.selectedIndex = -1;
    }
});

/** 탭 iframe 하나에 action('requery')를 부른다(아직 로드 중이거나 action이 없으면 넘어간다). */
function icRequeryFrame(frame) {
    try {
        if (frame.contentWindow && typeof frame.contentWindow.action === 'function') {
            frame.contentWindow.action('requery', {}, window);
        }
    } catch (e) {
        // 아직 로드 중이면 조용히 넘어간다.
    }
}

// 하단 탭 전환 — 처음 보이는 탭이면 data-src를 src로 옮겨 그때 불러오고,
// 이미 불러온 탭이면 다시 조회한다(index의 탭 전환과 같은 규칙).
document.querySelectorAll('#ic-bottom-tabs .ic-tabbar button').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var key = btn.getAttribute('data-tab');
        document.querySelectorAll('#ic-bottom-tabs .ic-tabbar button').forEach(function (b) {
            b.classList.toggle('ic-active', b === btn);
        });
        document.querySelectorAll('#ic-bottom-tabs .ic-tabpane').forEach(function (pane) {
            var show = pane.getAttribute('data-pane') === key;
            pane.hidden = !show;
            if (!show) {
                return;
            }
            var frame = pane.querySelector('iframe.ic-tab-frame');
            if (!frame) {
                return;
            }
            if (frame.hasAttribute('data-src')) {
                frame.src = frame.getAttribute('data-src');
                frame.removeAttribute('data-src');
            } else if (frame.hasAttribute('src')) {
                icRequeryFrame(frame);
            }
        });
    });
});

// 위쪽 "문의" 탭 머리를 눌러도 다시 조회한다(탭이 하나뿐이라 전환은 없다).
document.querySelectorAll('.ic-top .ic-tabbar button').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var frame = btn.closest('.ic-tabs').querySelector('iframe.ic-tab-frame[src]');
        if (frame) {
            icRequeryFrame(frame);
        }
    });
});
</script>
<?php endif; ?>
</body>
</html>
