<?php
declare(strict_types=1);

/**
 * 로그인 전 화면(Login::LOGIN_SCREEN_URL이 가리키는 곳). id/pw/계정저장
 * 체크박스만 있는 순수 폼 — DB 접근은 이 화면이 아니라 login_action.php의
 * 몫이다(PRG 패턴). Login::isAlive()가 리다이렉트할 때 실어 보낸 return을
 * hidden 필드로 그대로 폼에 실어, 로그인 성공 후 원래 보려던 화면으로
 * 돌아갈 수 있게 한다.
 *
 * [접근 주소 힌트] 브라우저에서 직접 열 때는
 * `<서버 주소>/<프로젝트 폴더>/public/amania/login/login.php`로 접근한다
 * (2026-09-23 public/test/Login에서 이 위치로 이동 — config/Path.php의
 * LOGIN_BASE_URL이 이 경로를 가리키므로, Login::isAlive()로 리다이렉트되는
 * 다른 모든 화면도 결국 이 주소로 온다).
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('Login', 'Str');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// login_action.php가 실패 시 남겨둔 1회성 플래시(PRG 패턴, 이 프로젝트의
// 다른 write.php/action.php들과 동일한 관례) — 읽는 즉시 지운다.
$flashError = '';
if (isset($_SESSION['login_flash_error'])) {
    $flashError = (string) $_SESSION['login_flash_error'];
}

$flashOldId = '';
if (isset($_SESSION['login_flash_old_id'])) {
    $flashOldId = (string) $_SESSION['login_flash_old_id'];
}

unset($_SESSION['login_flash_error'], $_SESSION['login_flash_old_id']);

// id 입력칸 초깃값 — 직전 실패 시도가 남긴 값(플래시)이 있으면 그걸
// 우선 보여주고(사용자가 방금 입력했던 걸 그대로 유지), 없으면
// "계정저장" 체크로 성공했던 마지막 로그인의 id(쿠키)를 보여준다.
// 비밀번호는 어느 경우에도 채우지 않는다.
$idFieldValue = $flashOldId;
if ($idFieldValue === '') {
    $idFieldValue = Login::rememberedId();
}

// 비밀번호 입력칸 초깃값 — "계정저장"을 체크하고 성공했던 마지막
// 로그인이 있으면 그 비밀번호를 그대로 복원한다(체크 안 하고
// 로그인했으면 login_action.php가 이 쿠키를 지워서 여기선 항상 빈 값).
$pwFieldValue = Login::rememberedPassword();

// "계정저장" 체크박스도 비밀번호가 복원된 경우엔 미리 체크해둔다 —
// 안 그러면 이 화면을 그대로 다시 제출했을 때 remember가 꺼진 것으로
// 처리돼(login_action.php) 방금 복원된 비밀번호가 곧바로 지워진다.
$rememberChecked = '';
if ($pwFieldValue !== '') {
    $rememberChecked = ' checked';
}

// 아이디가 이미 채워져 있으면(직전 입력 또는 기억된 값) 커서를 비밀번호
// 칸에, 비어있으면 아이디 칸에 둔다.
$idAutofocus = '';
$pwAutofocus = '';
if ($idFieldValue === '') {
    $idAutofocus = ' autofocus';
} else {
    $pwAutofocus = ' autofocus';
}

$return = '';
if (isset($_GET['return'])) {
    $return = (string) $_GET['return'];
}

/** @param mixed $value */
function h($value): string
{
    return Str::escape($value);
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>로그인</title>
<style>
    body { font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif; background: #f5f6f8; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
    .login-box { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 32px; width: 320px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .login-box h1 { font-size: 1.3rem; margin: 0 0 20px; }
    .login-box .error { background: #fef2f2; color: #dc2626; border-radius: 6px; padding: 8px 12px; font-size: 0.85rem; margin-bottom: 16px; }
    .field { margin-bottom: 14px; }
    .field label { display: block; font-size: 0.85rem; color: #374151; margin-bottom: 4px; }
    .field input[type="text"], .field input[type="password"] { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem; }
    .remember { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: #374151; margin-bottom: 18px; }
    button { width: 100%; padding: 9px; border: none; border-radius: 6px; background: #2563eb; color: #fff; font-size: 0.95rem; cursor: pointer; }
    button:hover { background: #1d4ed8; }
</style>
</head>
<body>
<div class="login-box">
    <h1>로그인</h1>
    <?php if ($flashError !== ''): ?>
    <p class="error"><?= h($flashError) ?></p>
    <?php endif; ?>
    <form method="post" action="login_action.php">
        <input type="hidden" name="return" value="<?= h($return) ?>">
        <div class="field">
            <label for="login-id">아이디</label>
            <input type="text" id="login-id" name="id" value="<?= h($idFieldValue) ?>"<?= $idAutofocus ?> required>
        </div>
        <div class="field">
            <label for="login-pw">비밀번호</label>
            <input type="password" id="login-pw" name="pw" value="<?= h($pwFieldValue) ?>"<?= $pwAutofocus ?> required>
        </div>
        <label class="remember">
            <input type="checkbox" name="remember" value="1"<?= $rememberChecked ?>> 계정저장
        </label>
        <button type="submit">로그인</button>
    </form>
</div>
</body>
</html>
