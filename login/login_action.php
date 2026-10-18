<?php
declare(strict_types=1);

/**
 * 로그인 폼(login.php) 제출 처리 전용(화면 없음). 순서가 중요하다:
 *   1) Login::applyRememberMe() — 세션이 시작되기 전에 먼저 호출해야
 *      "계정저장" 체크 시 쿠키 수명이 실제로 늘어난다.
 *   2) Login::authentication()
 *   3) 성공하면 Login::afterLogin()으로 세션 저장 + 권한 결정 + 이동.
 *   4) 실패하면 PRG + 세션 플래시로 login.php로 되돌아간다(이 프로젝트의
 *      다른 write.php/action.php들과 동일한 관례).
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('Login');

// DB 접속은 페이지 상단에서 한 번 만들어 Login에 주입한다(src/db/DbContext.md).
// 여기서는 연결하지 않고, authentication()이 처음 쓸 때 연결된다.
$db = new DbContext('mssql');
Login::init($db);

/**
 * return 파라미터를 신뢰하지 않고 검증한다 — APP_BASE_URL로 시작하는
 * 우리 서버 내부 경로일 때만 사용하고, 아니면 기본 목적지로 보낸다
 * (오픈 리다이렉트 방지).
 */
function resolve_return_url(string $return, string $default): string
{
    if ($return !== '' && strpos($return, APP_BASE_URL . '/') === 0) {
        return $return;
    }
    return $default;
}

$remember = isset($_POST['remember']) && $_POST['remember'] === '1';
Login::applyRememberMe($remember);

$id = '';
if (isset($_POST['id'])) {
    $id = (string) $_POST['id'];
}

$pw = '';
if (isset($_POST['pw'])) {
    $pw = (string) $_POST['pw'];
}

$return = '';
if (isset($_POST['return'])) {
    $return = (string) $_POST['return'];
}


//로그인 후 옮길 페이지
$defaultUrl = AMANIA_BASE_URL . '/index.php';
//$defaultUrl = LAYOUT_BASE_URL . '/index.php';
//$defaultUrl = LOGIN_BASE_URL . '/welcome.php';

if (Login::authentication($id, $pw)) {
    Login::rememberId($id);
    if ($remember) {
        Login::rememberPassword($pw);
    } else {
        Login::forgetPassword();
    }
    Login::afterLogin(resolve_return_url($return, $defaultUrl));
    exit; // afterLogin()이 이미 리다이렉트+exit 하지만, 가독성을 위해 명시.
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['login_flash_error'] = Login::getLastError();
$_SESSION['login_flash_old_id'] = $id;

$backUrl = 'login.php';
if ($return !== '') {
    $backUrl .= '?return=' . urlencode($return);
}
header('Location: ' . $backUrl);
exit;
