<?php
declare(strict_types=1);

/**
 * tLogin 기본형 AGGrid 화면(컨테이너 페이지).
 * 구성: test.php(이 파일) + config_tLogin.php(프론트 config) + fetch_tLogin.php(CRUD).
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);

// 브라우저 캐시로 옛 AGGrid.js가 보이지 않게 수정시각을 쿼리스트링에 붙인다.
$aggridJsPath = APP_BASE_DIR . '/src/js/aggrid/AGGrid.js';
$aggridJsVersion = is_file($aggridJsPath) ? filemtime($aggridJsPath) : time();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>tLogin</title>
<style>
  html, body { margin: 0; height: 100%; }
  body { padding: 16px; box-sizing: border-box; font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif; }
  h1 { font-size: 1.1rem; margin: 0 0 12px; }
</style>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
</head>
<body>
<h1>tLogin</h1>

<div id="aggrid-tLogin"></div>
<?php require __DIR__ . '/config_tLogin.php'; ?>
</body>
</html>
