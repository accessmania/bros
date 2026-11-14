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

// 로고(src/img/image.png, 343×77, 바탕 #085E7F 단색). 왼쪽 브랜드 영역을 같은 색으로 칠해
// 로고 사각형 경계가 보이지 않게 한다 — 그래서 --brand 값은 로고 바탕색과 반드시 같아야 한다.
$logoUrl = APP_BASE_URL . '/src/img/image.png';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>로그인 — BroS POWER</title>
<style>
    :root {
        --brand: #085E7F;          /* 로고 바탕색과 같은 값(위 PHP 주석) */
        --brand-dark: #064a65;
        --brand-soft: #e6f1f5;
        --ink: #0f2430;
        --muted: #5b6b75;
        --line: #d5dee3;
        --danger: #c62828;
        --danger-bg: #fdecec;
    }
    * { box-sizing: border-box; }
    html, body { height: 100%; margin: 0; }
    body {
        font-family: 'Pretendard', 'Malgun Gothic', 'Segoe UI', system-ui, -apple-system, sans-serif;
        color: var(--ink);
        background: #f3f6f8;
    }

    /* 화면 = 왼쪽 브랜드 영역 + 오른쪽 로그인 폼 */
    .lg-wrap { display: flex; min-height: 100vh; }

    /* 왼쪽 — 로고와 같은 단색 바탕. 꾸밈(빛·원)은 모서리에만 두어 로고 둘레는 단색 그대로다. */
    .lg-brand {
        position: relative;
        flex: 1.15;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        background:
            radial-gradient(circle at 0% 0%, rgba(255, 255, 255, 0.10), transparent 32%),
            radial-gradient(circle at 100% 100%, rgba(0, 0, 0, 0.18), transparent 38%),
            var(--brand);
        color: #fff;
        padding: 48px 32px;
    }
    .lg-ring {
        position: absolute;
        border-radius: 50%;
        border: 1px solid rgba(255, 255, 255, 0.12);
        pointer-events: none;
    }
    .lg-ring.r1 { width: 520px; height: 520px; left: -260px; bottom: -260px; }
    .lg-ring.r2 { width: 340px; height: 340px; left: -170px; bottom: -170px; border-color: rgba(255, 255, 255, 0.18); }
    .lg-ring.r3 { width: 260px; height: 260px; right: -110px; top: -110px; }
    .lg-logo { display: block; width: 343px; max-width: 80%; height: auto; position: relative; }
    .lg-tagline {
        position: relative;
        margin: 22px 0 0;
        font-size: 0.95rem;
        letter-spacing: 0.32em;
        color: rgba(255, 255, 255, 0.78);
        text-align: center;
    }
    .lg-rule { width: 56px; height: 2px; margin: 18px auto 0; background: rgba(255, 255, 255, 0.45); position: relative; }
    .lg-copy {
        position: absolute;
        bottom: 22px;
        left: 0;
        right: 0;
        text-align: center;
        font-size: 0.75rem;
        letter-spacing: 0.08em;
        color: rgba(255, 255, 255, 0.55);
    }

    /* 오른쪽 — 로그인 폼 */
    .lg-panel {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 48px 24px;
        background: #fff;
    }
    .lg-form { width: 100%; max-width: 340px; }
    .lg-form h1 { margin: 0; font-size: 1.6rem; font-weight: 700; letter-spacing: -0.01em; }
    .lg-form .lg-sub { margin: 6px 0 28px; font-size: 0.9rem; color: var(--muted); }
    .lg-error {
        margin: 0 0 18px;
        padding: 10px 12px;
        border-left: 3px solid var(--danger);
        border-radius: 6px;
        background: var(--danger-bg);
        color: var(--danger);
        font-size: 0.85rem;
    }
    .field { margin-bottom: 16px; }
    .field label { display: block; margin-bottom: 6px; font-size: 0.82rem; font-weight: 600; color: #34444e; }
    .field input[type="text"], .field input[type="password"] {
        width: 100%;
        height: 44px;
        padding: 0 14px;
        border: 1px solid var(--line);
        border-radius: 8px;
        background: #fbfcfd;
        font: inherit;
        font-size: 0.95rem;
        color: var(--ink);
        transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
    }
    .field input:focus {
        outline: none;
        border-color: var(--brand);
        background: #fff;
        box-shadow: 0 0 0 3px rgba(8, 94, 127, 0.15);
    }
    .remember {
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 4px 0 24px;
        font-size: 0.86rem;
        color: #34444e;
        cursor: pointer;
        user-select: none;
    }
    .remember input { width: 16px; height: 16px; margin: 0; accent-color: var(--brand); }
    .lg-submit {
        width: 100%;
        height: 46px;
        border: none;
        border-radius: 8px;
        background: var(--brand);
        color: #fff;
        font-family: 'Pretendard', 'Malgun Gothic', 'Segoe UI', system-ui, -apple-system, sans-serif;
        font-size: 1rem;
        font-weight: 600;
        letter-spacing: 0.04em;
        cursor: pointer;
        box-shadow: 0 6px 16px -6px rgba(8, 94, 127, 0.55);
        transition: background 0.15s, transform 0.05s;
    }
    .lg-submit:hover { background: var(--brand-dark); }
    .lg-submit:active { transform: translateY(1px); }
    .lg-submit:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(8, 94, 127, 0.3); }

    /* 좁은 화면 — 로고 띠를 위에, 폼을 아래에 쌓는다. */
    @media (max-width: 820px) {
        .lg-wrap { flex-direction: column; }
        /* 띠가 낮아 모서리 빛이 로고까지 닿으면 로고 사각형 경계가 드러난다 — 좁은 화면은 단색만. */
        .lg-brand { flex: none; padding: 40px 16px 34px; background: var(--brand); }
        .lg-ring { display: none; }
        .lg-tagline { margin-top: 14px; font-size: 0.8rem; letter-spacing: 0.24em; }
        .lg-rule, .lg-copy { display: none; }
        .lg-panel { flex: 1; align-items: flex-start; padding: 32px 16px 40px; }
    }
</style>
</head>
<body>
<div class="lg-wrap">
    <!-- 왼쪽 브랜드 영역 — 로고(src/img/image.png)와 같은 바탕색 -->
    <section class="lg-brand" aria-label="BroS POWER">
        <span class="lg-ring r1"></span>
        <span class="lg-ring r2"></span>
        <span class="lg-ring r3"></span>
        <img class="lg-logo" src="<?= h($logoUrl) ?>" alt="BroS POWER" width="343" height="77">
        <p class="lg-tagline">업무 통합 관리 시스템</p>
        <div class="lg-rule"></div>
        <div class="lg-copy">© BroS POWER</div>
    </section>

    <!-- 오른쪽 로그인 폼 — 동작(필드 이름·action·hidden return)은 예전과 같다 -->
    <main class="lg-panel">
    <div class="lg-form">
    <h1>로그인</h1>
    <p class="lg-sub">아이디와 비밀번호를 입력하세요.</p>
    <?php if ($flashError !== ''): ?>
    <p class="lg-error"><?= h($flashError) ?></p>
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
        <button type="submit" class="lg-submit">로그인</button>
    </form>
    </div>
    </main>
</div>
</body>
</html>
