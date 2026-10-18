<?php
declare(strict_types=1);

/**
 * 로그인/권한 세션을 비우고 로그인 화면으로 돌려보낸다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('Login');

Login::logout();

header('Location: ' . LOGIN_BASE_URL . '/login.php');
exit;
