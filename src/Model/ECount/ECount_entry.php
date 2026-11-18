<?php
declare(strict_types=1);

/**
 * [ECount 시험 화면] — ECount.php의 함수를 하나씩 눌러 본다. 결과와 요청·응답 기록(인증키·세션은 가림)을 보여준다.
 * 순서: 설정 읽기(tAPI) → Zone 조회 → 로그인(세션은 PHP 세션) → 단품 재고.
 * 서버: ECount::TEST_SERVER(true = 시험 서버 sboapi, false = 실제 서버 oapi — 지금은 false).
 * 접근 주소: http://192.168.0.40/bros/src/Model/ECount/ECount_entry.php
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('Login', 'ECount');

// 로그인이 안 살아있으면 여기서 login.php로 이동하고 끝난다.
Login::isAlive();

$fetchUrl = APP_BASE_URL . '/src/Model/ECount/ECount_fetch.php';
$serverText = ECount::TEST_SERVER ? '시험 서버(sboapi)' : '실제 서버(oapi)';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>ECount 시험</title>
<style>
body { margin: 0; padding: 16px; font-family: 'Malgun Gothic', 'Segoe UI', system-ui, sans-serif; font-size: 13px; color: #1e293b; background: #f8fafc; }
h1 { font-size: 16px; margin: 0 0 4px; }
.ec-server { color: #2563eb; margin-bottom: 12px; }
.ec-row { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 10px; }
.ec-row button { font: inherit; height: 28px; padding: 0 12px; cursor: pointer; border: 1px solid #a3a3a3; background: #e5e5e5; }
.ec-row button:hover { background: #d4d4d4; }
.ec-row .ec-danger { color: #dc2626; }
.ec-row input { font: inherit; height: 26px; padding: 0 6px; border: 1px solid #a3a3a3; width: 180px; }
.ec-status { min-height: 18px; margin: 8px 0; font-weight: 600; }
.ec-status.ec-ok { color: #15803d; }
.ec-status.ec-error { color: #dc2626; }
pre { background: #fff; border: 1px solid #d1d5db; padding: 10px; white-space: pre-wrap; word-break: break-all; max-height: 60vh; overflow: auto; margin: 0; }
</style>
</head>
<body>
<h1>ECount 시험</h1>
<div class="ec-server">지금 서버: <?= htmlspecialchars($serverText, ENT_QUOTES, 'UTF-8') ?> — 인증키·세션은 앞 4글자만 보입니다.</div>

<div class="ec-row">
    <button type="button" id="ec-config">1. 설정 읽기(tAPI)</button>
    <button type="button" id="ec-zone">2. Zone 조회</button>
    <button type="button" id="ec-login">3. 로그인(세션은 PHP 세션)</button>
</div>
<div class="ec-row">
    <input type="text" id="ec-product" placeholder="품목코드" autocomplete="off">
    <button type="button" id="ec-one">4. 단품 재고</button>
</div>

<div class="ec-status" id="ec-status"></div>
<pre id="ec-output"></pre>

<script>
// VBA식 JS — var(함수 맨 위에 모아 선언), function, if/else, 매개변수는 p로 시작, 서버는 async/await.
// 화면 전용 함수·변수는 ec / EC_ 접두사.
var EC_FETCH_URL = <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

/** paction을 창구에 보내고 결과·기록을 화면에 보인다. */
async function ecRun(paction, pbody) {
    var status = document.getElementById('ec-status');
    var output = document.getElementById('ec-output');
    var res;
    var data;

    status.className = 'ec-status';
    status.textContent = paction + ' 실행 중...';
    output.textContent = '';
    try {
        res = await fetch(EC_FETCH_URL + '?action=' + encodeURIComponent(paction), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(pbody)
        });
        data = await res.json();
    } catch (err) {
        status.className = 'ec-status ec-error';
        status.textContent = paction + ' 실패: ' + err.message;
        return;
    }

    if (data.success) {
        status.className = 'ec-status ec-ok';
        status.textContent = paction + ' 성공';
    } else {
        status.className = 'ec-status ec-error';
        status.textContent = paction + ' 실패: ' + data.error;
    }
    // 사용자 값이 섞일 수 있으므로 textContent로 넣는다.
    output.textContent = JSON.stringify(data, null, 2);
}

function ecRunOne() {
    ecRun('one', { productCode: document.getElementById('ec-product').value });
}

document.getElementById('ec-config').addEventListener('click', function () { ecRun('config', {}); });
document.getElementById('ec-zone').addEventListener('click', function () { ecRun('zone', {}); });
document.getElementById('ec-login').addEventListener('click', function () { ecRun('login', {}); });
document.getElementById('ec-one').addEventListener('click', ecRunOne);
</script>
</body>
</html>
