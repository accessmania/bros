<?php
declare(strict_types=1);

/**
 * talk_entry — talk.js 연습용 페이지.
 *
 * 로그인code/로그인이름(+ type/parentcode/polling 주기)을 텍스트로 직접
 * 넣고 [init]을 누르면 그 값이 그대로 talk.action('init', {...})으로
 * 들어간다 — 실제 화면에서는 이 값들을 세션에서 꺼내 넘기게 된다.
 */
header('Content-Type: text/html; charset=utf-8');

$talkJsVersion = filemtime(__DIR__ . '/talk.js');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>talk.js 연습</title>
<style>
    body {
        font-family: 'Malgun Gothic', 'Segoe UI', system-ui, sans-serif;
        color: #1f2d3d; margin: 0; padding: 12px; background: #eee;
    }
    .te-form {
        display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: center;
        margin-bottom: 10px; font-size: 0.85rem;
    }
    .te-form label { display: flex; gap: 4px; align-items: center; }
    .te-form input { width: 110px; padding: 3px 6px; }
    .te-form input.te-num { width: 50px; }
    .te-test {
        display: flex; flex-wrap: wrap; gap: 6px 10px; align-items: center;
        margin-bottom: 10px; padding: 8px; background: #fff; border: 1px solid #ddd;
        font-size: 0.85rem;
    }
    .te-test input { width: 90px; padding: 3px 6px; }
    .te-test input.te-wide { width: 200px; }
    .te-main { display: flex; gap: 12px; align-items: flex-start; }
    #talk-root { width: 400px; height: 820px; flex: none; }
    .te-log {
        flex: 1; min-width: 0; height: 820px; overflow: auto; box-sizing: border-box;
        margin: 0; padding: 8px; background: #fff; border: 1px solid #ddd;
        font-family: Consolas, 'Courier New', monospace; font-size: 0.75rem;
        white-space: pre-wrap; word-break: break-all;
    }
</style>
</head>
<body>
<div class="te-form">
    <label>로그인code <input id="te-logincode" value="A001"></label>
    <label>로그인이름 <input id="te-loginname" value="홍길동"></label>
    <label>type <input id="te-type" value="test"></label>
    <label>parentcode <input id="te-parentcode" value="1"></label>
    <label>polling(초) <input id="te-poll" class="te-num" value="0"></label>
    <button type="button" id="te-init">init</button>
</div>

<div class="te-test">
    <button type="button" data-cmd="show">show</button>
    <button type="button" data-cmd="getmystyle">getmystyle</button>
    <button type="button" data-cmd="getyourstyle">getyourstyle</button>
    <button type="button" data-cmd="setvalue">setvalue</button>
    <button type="button" data-cmd="setdate">setdate</button>
    <button type="button" data-cmd="clear">clear</button>
    <span>|</span>
    <label>whocode <input id="tv-whocode" value="A001"></label>
    <label>whoname <input id="tv-whoname" value="홍길동"></label>
    <label>datetime <input id="tv-datetime" value=""></label>
    <label>comment <input id="tv-comment" class="te-wide" value="테스트 메세지"></label>
</div>

<div class="te-main">
    <div id="talk-root"></div>
    <pre class="te-log" id="te-log"></pre>
</div>

<script src="talk.js?v=<?= $talkJsVersion ?>"></script>
<script>
(function () {
    var talk = new Talk('talk-root');

    function val(id) {
        return document.getElementById(id).value.trim();
    }

    var logEl = document.getElementById('te-log');
    function log(message) {
        var now = new Date();
        logEl.textContent = '[' + now.toLocaleTimeString() + '] ' + message + '\n\n' + logEl.textContent;
    }

    // setvalue/getmystyle/getyourstyle 시험값 — datetime이 비어 있으면 지금 시각.
    function testRow() {
        var dt = val('tv-datetime');
        if (dt === '') {
            var d = new Date();
            var z = function (n) { return (n < 10 ? '0' : '') + n; };
            dt = d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate()) +
                ' ' + z(d.getHours()) + ':' + z(d.getMinutes()) + ':' + z(d.getSeconds());
        }
        return { whocode: val('tv-whocode'), whoname: val('tv-whoname'), datetime: dt, comment: val('tv-comment') };
    }

    var tests = {
        show: function () {
            return talk.action('show').then(function (rows) {
                log('show → ' + rows.length + '건\n' + JSON.stringify(rows, null, 2));
            });
        },
        getmystyle: function () {
            var r = testRow();
            var html = talk.action('getmystyle', { name: r.whoname, time: r.datetime, comment: r.comment });
            log('getmystyle →\n' + html);
            // 반환값만으로는 안 보이니 확인용으로 채팅영역에도 붙여본다.
            talk.listEl.insertAdjacentHTML('beforeend', html);
        },
        getyourstyle: function () {
            var r = testRow();
            var html = talk.action('getyourstyle', { name: r.whoname, time: r.datetime, comment: r.comment });
            log('getyourstyle →\n' + html);
            talk.listEl.insertAdjacentHTML('beforeend', html);
        },
        setvalue: function () {
            var r = testRow();
            talk.action('setvalue', r);
            log('setvalue → ' + JSON.stringify(r));
        },
        setdate: function () {
            var r = testRow();
            talk.action('setdate', { datetime: r.datetime });
            log('setdate → ' + r.datetime);
        },
        clear: function () {
            talk.action('clear');
            log('clear');
        },
    };

    document.querySelectorAll('.te-test button[data-cmd]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            try {
                Promise.resolve(tests[btn.dataset.cmd]()).catch(function (e) { log('오류: ' + e.message); });
            } catch (e) {
                log('오류: ' + e.message);
            }
        });
    });

    document.getElementById('te-init').addEventListener('click', function () {
        log('init → logincode=' + val('te-logincode') + ', type=' + val('te-type') + ', parentcode=' + val('te-parentcode'));
        talk.action('init', {
            type: val('te-type'),
            parentcode: val('te-parentcode'),
            logincode: val('te-logincode'),
            loginname: val('te-loginname'),
            pollSeconds: Number(val('te-poll')) || 0,
        }).then(function (rows) {
            log('init → show ' + rows.length + '건');
        }).catch(function (e) {
            log('오류: ' + e.message);
        });
    });
})();
</script>
</body>
</html>
