<?php
declare(strict_types=1);

/**
 * [crudbuttons.php — 폼 아래 iframe에 넣는 재사용 CRUD 버튼 페이지]
 * 저장/삭제/신규입력/창닫기 버튼 + 그 안의 CrudJs가 부모 폼(window.parent)의
 * id=필드명 컨트롤에 바인딩해 CRUD를 한다. 이벤트(beforeCrudSave 등)는 부모 폼이
 * 정의한다 — 자세한 계약은 crudbuttons.js 상단 참고.
 *
 * 주소: crudbuttons.php?fetch=<xxx_fetch.php 주소>&pk=<처음 열 레코드 PK, 생략 시 신규>
 *  - fetch는 이 앱 안의 주소(APP_BASE_URL로 시작)만 받는다.
 *
 * 부모 폼에 넣는 법:
 *   <iframe class="cb-frame" src="<?= APP_BASE_URL ?>/src/Model/Crud/crudbuttons/crudbuttons.php?fetch=...&pk=..."></iframe>
 *
 * 페이지 함수: action(pcmd, pparams, psender) — 버튼도 전부 이리로 흐른다.
 *  - 'save' / 'delete' / 'clear'(신규입력) / 'close'(창닫기 — 팝업이면 팝업을, 단독이면 창을 닫는다)
 *  - 'selectRecord' {pk} — 그 레코드를 연다.
 *  - 'requery' — 지금 레코드를 다시 읽는다.
 *  (신규입력/selectRecord/requery/close는 저장 안 한 값이 있으면 먼저 묻는다.)
 *  - 'refresh' — 버튼 활성/비활성만 다시 정한다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('Login');

Login::isAlive();

$fetchUrl = isset($_GET['fetch']) ? trim((string) $_GET['fetch']) : '';
$pk = isset($_GET['pk']) ? trim((string) $_GET['pk']) : '';
// 다른 사이트 주소로 폼 값을 보내지 않도록 이 앱 안의 경로만 받는다.
$fetchError = '';
if ($fetchUrl === '' || strpos($fetchUrl, APP_BASE_URL . '/') !== 0) {
    $fetchError = 'fetch 주소가 없거나 이 앱의 주소가 아닙니다.';
}

$crudJsPath = APP_BASE_DIR . '/src/Model/Crud/crudjs.js';
$buttonsJsPath = __DIR__ . '/crudbuttons.js';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<title>crudbuttons</title>
<style>
html, body { margin: 0; height: 100%; background: transparent; overflow: hidden; }
body { font-family: 'Malgun Gothic', 'Segoe UI', system-ui, sans-serif; font-size: 13px; color: #000; }
.cb-bar { display: flex; align-items: center; gap: 6px; padding: 6px 2px; }
.cb-btn {
    font: inherit; height: 28px; min-width: 78px; padding: 0 10px; cursor: pointer; white-space: nowrap;
    border: 1px solid #a3a3a3; border-radius: 3px; background: #e5e5e5; color: #000;
}
.cb-btn:hover:not(:disabled) { background: #d4d4d4; }
.cb-btn:disabled { color: #9ca3af; cursor: default; }
/* 저장 안 한 값이 있을 때 저장 버튼(crudbuttons.js refresh()). */
.cb-btn-save.cb-dirty { background: #fde68a; border-color: #d97706; font-weight: 700; }
.cb-btn-save.cb-dirty:hover:not(:disabled) { background: #fcd34d; }
.cb-spacer { flex: 1; }
.cb-message { max-width: 50%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #374151; }
.cb-message.cb-error { color: #dc2626; }
</style>
</head>
<body>
<?php if ($fetchError !== ''): ?>
<div class="cb-bar"><span class="cb-message cb-error"><?= htmlspecialchars($fetchError, ENT_QUOTES, 'UTF-8') ?></span></div>
<?php else: ?>
<div id="cb-root"></div>
<script src="<?= APP_BASE_URL ?>/src/Model/Crud/crudjs.js?v=<?= filemtime($crudJsPath) ?>"></script>
<script src="crudbuttons.js?v=<?= filemtime($buttonsJsPath) ?>"></script>
<script>
var crudButtons = new CrudButtons({
    containerId: 'cb-root',
    fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    pk: <?= json_encode($pk, JSON_UNESCAPED_UNICODE) ?>
});

/** action(pcmd, pparams, psender) — 버튼과 부모 폼이 이 페이지를 부르는 창구. */
function action(pcmd, pparams, psender) {
    if (!pparams) {
        pparams = {};
    }

    switch (pcmd) {
        case 'save':
            return crudButtons.save();
        case 'delete':
            return crudButtons.remove();
        case 'clear':
            return crudButtons.newRecord();
        case 'close':
            return crudButtons.close();
        case 'selectRecord':
            return crudButtons.selectRecord(pparams.pk);
        case 'requery':
            return crudButtons.requery();
        case 'refresh':
            return crudButtons.refresh();
        default:
            console.warn('crudbuttons.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

crudButtons.init();
</script>
<?php endif; ?>
</body>
</html>
