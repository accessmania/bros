<?php
declare(strict_types=1);

/**
 * 로그인(tLogin) AGGrid 화면(컨테이너 페이지).
 * 구성: testlogin.php(이 파일) + ../basicdata/로그인/config_로그인.php(프론트 config) + ../basicdata/로그인/fetch_로그인.php(CRUD).
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
<title>로그인</title>
<style>
  html, body { margin: 0; height: 100%; }
  body { padding: 16px; box-sizing: border-box; font-family: 'Segoe UI', Inter, system-ui, -apple-system, sans-serif; }
  h1 { font-size: 1.1rem; margin: 0 0 12px; }
</style>
<!-- document.currentScript로 vendor 경로를 찾으므로 async/defer 없이 동기 로드 -->
<script src="<?= APP_BASE_URL ?>/src/js/aggrid/AGGrid.js?v=<?= $aggridJsVersion ?>"></script>
</head>
<body>
<h1>로그인</h1>

<div id="aggrid-로그인"></div>
<?php require __DIR__ . '/../basicdata/로그인/config_로그인.php'; ?>
</body>
</html>
