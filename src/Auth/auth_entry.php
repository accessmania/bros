<?php
declare(strict_types=1);

/**
 * 현재 로그인한 사용자의 권한(tAuth) 레코드 확인 화면.
 *
 * 로그인 시 Login::afterLogin()이 Auth::loadByCode()로 세션에 담아둔 auth
 * rec의 **모든 필드**를 "필드 : 값" 형식으로 리치텍스트 상자 하나에
 * 나열한다(Auth::getAllValues() — FIELDS에 없는 컬럼도 포함).
 *
 * 세션만 읽으므로 DB 접속(DbContext/Login::init)이 필요 없다. 표시 전용이며
 * 여기서 값을 바꾸거나 저장하지 않는다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('Login', 'Auth', 'Str');

// 로그인이 안 살아있으면 여기서 login.php로 이동하고 끝난다.
Login::isAlive();

function h($value): string
{
    return Str::escape($value);
}

$authRec = Auth::getAllValues();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>권한 정보</title>
<style>
body {
    font-family: 'Segoe UI', Inter, system-ui, sans-serif;
    margin: 0;
    padding: 24px 16px;
    background: #f5f6f8;
    color: #1f2328;
}
.ae-wrap { max-width: 760px; margin: 0 auto; }
.ae-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap; }
.ae-head h1 { font-size: 1.25rem; margin: 0 0 12px; }
.ae-head .ae-user { font-size: 0.9rem; color: #57606a; }
.ae-head a { color: #0969da; }

/* 리치텍스트 상자 — auth rec 전체를 "필드 : 값" 줄로 나열한다. */
.ae-richtext {
    background: #fff;
    border: 1px solid #d0d7de;
    border-radius: 6px;
    padding: 20px 24px;
    min-height: 240px;
    line-height: 1.7;
    overflow-wrap: anywhere;
}
.ae-richtext p { margin: 0 0 6px; }
.ae-richtext .ae-field { font-weight: 600; }
.ae-richtext .ae-value { white-space: pre-wrap; }
.ae-richtext .ae-empty { color: #8c959f; font-style: italic; }

/* 권한 확인 — 권한명을 넣고 버튼을 누르면 true/false를 alert로 보여준다. */
.ae-check { display: flex; gap: 8px; margin: 0 0 12px; }
.ae-check input {
    flex: 1; padding: 6px 8px; border: 1px solid #d0d7de; border-radius: 4px; font-size: 0.9rem;
}
.ae-check button {
    padding: 6px 14px; border: none; border-radius: 4px; background: #2563eb; color: #fff;
    font-size: 0.9rem; font-weight: 600; cursor: pointer;
}
.ae-check button:hover { background: #1d4ed8; }
</style>
</head>
<body>
<div class="ae-wrap">
    <div class="ae-head">
        <h1>권한 정보 (auth rec)</h1>
        <div class="ae-user">
            <?= h(Login::getValue('이름')) ?> (<?= h(Login::getValue('id')) ?>)
            · <a href="<?= h(LOGIN_BASE_URL . '/logout.php') ?>">로그아웃</a>
        </div>
    </div>

    <div class="ae-check">
        <input type="text" id="ae-check-input" placeholder="권한명 (예: button기초자료)">
        <button type="button" id="ae-check-btn">권한 확인</button>
    </div>

    <div class="ae-richtext" role="document" aria-label="권한 정보">
<?php if (empty($authRec)): ?>
        <p class="ae-empty">이 계정에 연결된 권한(tAuth) 레코드가 없습니다. tLogin의 권한code가 비어 있거나 tAuth에 해당 code가 없습니다.</p>
<?php else: ?>
<?php foreach ($authRec as $field => $value): ?>
        <p><span class="ae-field"><?= h($field) ?></span> : <?php if ($value === null || $value === ''): ?><span class="ae-empty">(없음)</span><?php else: ?><span class="ae-value"><?= h($value) ?></span><?php endif; ?></p>
<?php endforeach; ?>
<?php endif; ?>
    </div>
</div>
<script>
// 권한 확인 — index의 getauth(권한명)로 판단한다(getbank('auth')에 저장된
// authstring 기준, index 안의 탭으로 열어야 동작).
function aeCheck() {
    if (window.parent === window || typeof window.parent.getauth !== 'function') {
        window.alert('getauth를 찾을 수 없습니다. 메뉴(index)에서 열어 주세요.');
        return;
    }
    var code = document.getElementById('ae-check-input').value;
    window.alert(window.parent.getauth(code) ? 'true' : 'false');
}

document.getElementById('ae-check-btn').addEventListener('click', aeCheck);
document.getElementById('ae-check-input').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
        aeCheck();
    }
});
</script>
</body>
</html>
