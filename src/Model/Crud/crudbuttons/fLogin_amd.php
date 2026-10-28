<?php
declare(strict_types=1);

/**
 * [fLogin_amd.php — crudbuttons 시범 폼(tLogin 한 건)]
 * tLogin 필드와 같은 id의 컨트롤을 배치하고, 아래 iframe에 crudbuttons.php를 넣는다.
 * CRUD 실행과 버튼은 crudbuttons가 하고, 이 페이지는 필드와 이벤트만 정의한다
 * (checkCrudSave(id 중복 — async)/beforeCrudSave/afterCrudSave/…/isEnableAmdSave/
 * isEnableAmdDelete — 아래 스크립트). 저장 안 한 값 경고는 crudbuttons가 알아서 한다.
 *
 * 주소: fLogin_amd.php?code=<tLogin.code> — 기존 레코드. code 생략 또는 new — 신규.
 * crudbuttons_entry.php가 Popup(iframeUrl)으로 띄운다. 결과는 자기를 띄운 쪽에
 * 보고한다(psender.report): 'saved' {code, 이름} / 'deleted' {code} / 'closed'(crudbuttons가 보냄).
 *
 * 페이지 함수: action(pcmd, pparams, psender)
 *  - 'close'   — 바깥에서 닫으라고 할 때. 창닫기 버튼과 같은 길(crudbuttons)로 닫는다.
 * 띄운 쪽에 알리기/제목 바꾸기는 이벤트 안에서 psender.report() / psender.setCaption()으로
 * 한다 — 팝업이든 iframe이든 단독이든 crudbuttons가 알아서 처리한다.
 *  - 'requery' — 지금 레코드를 다시 읽는다(버튼 iframe에 위임).
 *  - 'selectRecord' {pk} — 다른 레코드를 연다(버튼 iframe에 위임).
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Login');

Login::isAlive();

$code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
$pk = ctype_digit($code) ? $code : ''; // 'new'나 빈 값이면 신규

// 권한code 콤보 원본 — tAuth(code, authname).
$authOptions = [];
try {
    $db = new DbContext(null);
    foreach ($db->pdo()->query('SELECT code, authname FROM tAuth ORDER BY code ASC')->fetchAll(PDO::FETCH_NUM) as [$authCode, $authName]) {
        $authOptions[] = [(string) $authCode, $authName === null ? '' : (string) $authName];
    }
} catch (Throwable $e) {
    // 콤보만 비고 화면은 뜬다.
}

$fetchUrl = APP_BASE_URL . '/src/Model/Crud/crudbuttons/fLogin_amd_fetch.php';
$buttonsSrc = 'crudbuttons.php?fetch=' . rawurlencode($fetchUrl) . '&pk=' . rawurlencode($pk);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>로그인</title>
<style>
html, body { margin: 0; height: 100%; }
body {
    display: flex; flex-direction: column; padding: 16px 20px 6px; box-sizing: border-box;
    background: #ececec; color: #000; font-family: 'Malgun Gothic', 'Segoe UI', system-ui, sans-serif; font-size: 13px;
}
.fl-form { display: grid; grid-template-columns: 70px 1fr 70px 1fr; gap: 8px 10px; align-items: center; }
.fl-form label { white-space: nowrap; }
.fl-form input, .fl-form select, .fl-form textarea {
    font: inherit; width: 100%; box-sizing: border-box; height: 25px; padding: 0 6px;
    border: 1px solid #a3a3a3; background: #fff; color: #000;
}
.fl-form textarea { height: 60px; padding: 4px 6px; resize: vertical; }
.fl-form input[readonly] { background: #e5e5e5; color: #6b7280; }
.fl-form input:focus, .fl-form select:focus, .fl-form textarea:focus { outline: none; border-color: #3b82f6; }
.fl-wide { grid-column: 2 / 5; }
.fl-form .fl-check { display: flex; align-items: center; gap: 6px; }
.fl-form .fl-check input { width: auto; height: auto; }
.fl-sep { grid-column: 1 / 5; border: 0; border-top: 1px solid #9ca3af; margin: 4px 0; }
.fl-required { color: #dc2626; }
/* 버튼 iframe — 폼 아래, 배경이 비치게. */
.cb-frame { display: block; width: 100%; height: 44px; border: 0; margin-top: auto; background: transparent; }
</style>
</head>
<body>
<form class="fl-form" id="fl-form" autocomplete="off" onsubmit="return false;">
    <label for="code">code</label>
    <input type="text" id="code" readonly>
    <label for="사용여부">사용여부</label>
    <label class="fl-check"><input type="checkbox" id="사용여부"> 사용</label>

    <hr class="fl-sep">

    <label for="id" class="fl-required">id</label>
    <input type="text" id="id">
    <label for="pw">pw</label>
    <input type="password" id="pw" autocomplete="new-password">

    <label for="이름" class="fl-required">이름</label>
    <input type="text" id="이름">
    <label for="권한code">권한</label>
    <select id="권한code">
        <option value=""></option>
<?php foreach ($authOptions as [$authCode, $authName]): ?>
        <option value="<?= htmlspecialchars($authCode, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($authName, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
    </select>

    <label for="부서">부서</label>
    <input type="text" id="부서">
    <label for="직급">직급</label>
    <input type="text" id="직급">

    <label for="유선전화">유선전화</label>
    <input type="text" id="유선전화">
    <label for="핸드폰">핸드폰</label>
    <input type="text" id="핸드폰">

    <label for="이메일">이메일</label>
    <input type="text" id="이메일">
    <label for="지분률">지분률</label>
    <input type="text" id="지분률" style="text-align: right;">

    <label for="메모">메모</label>
    <textarea id="메모" class="fl-wide"></textarea>
</form>

<iframe class="cb-frame" id="cb-frame" src="<?= htmlspecialchars($buttonsSrc, ENT_QUOTES, 'UTF-8') ?>" title="저장/삭제 버튼"></iframe>

<script>
// 로그인한 사람 — 본인 계정은 지우지 못하게 한다(isEnableAmdDelete).
var FL_LOGIN_CODE = <?= json_encode((string) Login::getValue('code')) ?>;
// 서버 주소 — id 중복 확인(checkCrudSave)에 쓴다.
var FL_FETCH_URL = <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

// 띄운 쪽에 알리기(psender.report)와 제목 바꾸기(psender.setCaption)는 crudbuttons가
// 한다 — 이 폼이 팝업에 있든 iframe에 있든 단독이든 이 폼은 신경 쓰지 않는다.

/** 제목을 지금 레코드로 맞춘다 — "로그인 (신규)" / "로그인 (code 이름)". */
function flCaption(psender) {
    var pk = psender.getPk();
    var name;

    if (pk === null) {
        psender.setCaption('로그인 (신규)');
    } else {
        name = flText(psender.getCrud().getValue('이름'));
        psender.setCaption('로그인 (' + pk + ' ' + name + ')');
    }
}

/** 버튼 iframe의 창 — 'requery'/'selectRecord'를 그쪽 action에 맡길 때 쓴다. 없으면 null. */
function flButtons() {
    var frame = document.getElementById('cb-frame');

    if (frame === null || !frame.contentWindow) {
        return null;
    }
    if (typeof frame.contentWindow.action !== 'function') {
        return null;
    }
    return frame.contentWindow;
}

/** 값을 앞뒤 공백 없는 글자로 — null/undefined면 ''. */
function flText(pvalue) {
    if (pvalue === null || pvalue === undefined) {
        return '';
    }
    return String(pvalue).trim();
}

// ── CRUD 이벤트 — crudbuttons.js가 부른다(psender = CrudButtons). false면 막음. ──
function isEnableAmdSave(psender, rerror) {
    return true;
}

/** 본인 계정은 지우지 못한다. */
function isEnableAmdDelete(psender, rerror) {
    if (FL_LOGIN_CODE === '') {
        return true;
    }
    if (String(psender.getPk()) === FL_LOGIN_CODE) {
        rerror.error = '본인 계정은 삭제할 수 없습니다.';
        return false;
    }
    return true;
}

/**
 * 저장 전 서버 확인(async) — 같은 id를 다른 사람이 이미 쓰면 막는다.
 * crudbuttons가 결과를 기다린다(await). id가 비었으면 여기선 통과시키고
 * 아래 beforeCrudSave가 "id를 입력하세요"로 막는다.
 */
async function checkCrudSave(psender, ppara) {
    var id = flText(psender.getCrud().getValue('id'));
    var pk = psender.getPk();
    var url;
    var res;
    var data;

    if (id === '') {
        return true;
    }

    url = FL_FETCH_URL + '?action=checkId&id=' + encodeURIComponent(id);
    if (pk !== null) {
        url = url + '&code=' + encodeURIComponent(pk);   // 고치는 중인 자기 자신은 빼고 센다.
    }

    res = await fetch(url);
    data = await res.json();

    if (!data.success) {
        ppara.error = 'id 확인 실패: ' + data.error;
        return false;
    }
    if (data.exists) {
        ppara.error = '이미 쓰고 있는 id입니다: ' + id;
        document.getElementById('id').focus();
        return false;
    }
    return true;
}

/** id와 이름은 꼭 있어야 저장한다. */
function beforeCrudSave(psender, ppara) {
    var crud = psender.getCrud();

    if (flText(crud.getValue('id')) === '') {
        ppara.error = 'id를 입력하세요.';
        document.getElementById('id').focus();
        return false;
    }

    if (flText(crud.getValue('이름')) === '') {
        ppara.error = '이름을 입력하세요.';
        document.getElementById('이름').focus();
        return false;
    }

    return true;
}

/** 저장 뒤 — 캡션을 맞추고, 띄운 쪽에 'saved'로 알린다. */
function afterCrudSave(psender, ppara) {
    var payload = {
        code: psender.getPk(),
        이름: psender.getCrud().getValue('이름')
    };

    flCaption(psender);
    psender.report('saved', payload);
}

function beforeCrudDelete(psender, ppara) {
    return true;
}

/** 삭제 뒤 — 띄운 쪽에 'deleted'로 알린다(폼은 crudbuttons가 곧 비운다). */
function afterCrudDelete(psender, ppara) {
    var payload = {
        code: psender.getPk()
    };

    psender.report('deleted', payload);
}

function beforeCrudClear(psender, ppara) {
    return true;
}

/** 신규입력 — 기본값(사용여부=사용)을 넣고 id 칸으로. */
function afterCrudClear(psender, ppara) {
    psender.getCrud().setValue('사용여부', '1');
    flCaption(psender);
    document.getElementById('id').focus();
}

function beforeCrudSelectRecord(psender, ppara) {
    return true;
}

function afterCrudSelectRecord(psender, ppara) {
    flCaption(psender);
}

/** action(pcmd, pparams, psender) — 버튼 iframe과 바깥(entry)이 이 화면을 부르는 창구. */
function action(pcmd, pparams, psender) {
    var buttons;

    if (!pparams) {
        pparams = {};
    }
    buttons = flButtons();

    switch (pcmd) {
        case 'close':
            // 바깥에서 닫으라고 할 때 — 창닫기 버튼과 같은 길(crudbuttons)로 닫는다.
            if (buttons !== null) {
                buttons.action('close');
            }
            return;

        case 'requery':
            if (buttons !== null) {
                buttons.action('requery');
            }
            return;

        case 'selectRecord':
            if (buttons !== null) {
                buttons.action('selectRecord', { pk: pparams.pk });
            }
            return;

        default:
            console.warn('fLogin_amd.action: 알 수 없는 pcmd - ' + pcmd);
    }
}
</script>
</body>
</html>
