<?php
declare(strict_types=1);

/**
 * "로그인 이후" 화면의 예시 — 화면 첫머리에 항상 Login::isAlive()를 두는
 * 패턴을 그대로 보여준다. 실행되는 시점에 이미 로그인이 살아있음이
 * 보장되므로, 그 아래 코드는 별도 분기 없이 Login::getValue()/
 * Auth::getValue()를 바로 쓸 수 있다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('Login', 'Auth', 'Str');

// 화면 맨 첫 실행 줄 — 로그인이 안 살아있으면 여기서 이미 login.php로
// 이동하고 스크립트가 끝난다.
Login::isAlive();

function h($value): string
{
    return Str::escape($value);
}
?>
<!doctype html>
<html lang="ko">
<head><meta charset="utf-8"><title>로그인 확인</title></head>
<body style="font-family:'Segoe UI',Inter,system-ui,sans-serif; padding:32px;">
    <h1>Login::isAlive() 통과 — 로그인된 상태입니다.</h1>
    <p>아이디: <strong><?= h(Login::getValue('id')) ?></strong></p>
    <p>권한명: <strong><?= h(Auth::getValue('authname')) ?></strong></p>

    <h2>로그인 세션 전체 (Login::getValue 결과 확인용)</h2>
    <pre><?= h(json_encode($_SESSION['login'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>

    <h2>권한 세션 전체 (Auth::getValue 결과 확인용)</h2>
    <pre><?= h(json_encode($_SESSION['auth'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>

    <p><a href="logout.php">로그아웃</a></p>
</body>
</html>
