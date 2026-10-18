<?php

declare(strict_types=1);

/**
 * [crudjs_entry.php — CrudJs(id=필드명 자동 바인딩 JS CRUD) 연습 페이지]
 *
 * md/2026-09-14-crudjs-구현.md 설계 그대로: 룸번호/손님이름/입실시각(+PK)
 * 는 화면에 직접 그려진 입력칸으로 보인다. CRUDJS_VISIBLE_FIELDS에 있는
 * 그 외 필드(예: 날짜)는 값이 crudjs 인스턴스 내부의 `_data` 객체에만
 * 존재하고, 이 화면에 그 id의 입력칸을 안 만들어뒀으므로 기본적으로는
 * DOM에 전혀 안 나타난다 — 이 연습 페이지는 디버깅용으로
 * `showHidden: true`를 줘서, 화면에 없는 필드마다 hidden input을 만들어
 * 그 값을 개발자도구로 확인할 수 있게 한다(아래 `new CrudJs({...})`
 * 참고). `crudjs.getJson()`으로도 지금 들고 있는 값을 JSON으로 바로
 * 볼 수 있다. 저장/삭제/화면클리어/selectRecord를 이 화면에서 직접
 * 눌러볼 수 있다.
 *
 * [2026-09-14 후속] Crud::init()의 화이트리스트 인자로 CRUDJS_VISIBLE_FIELDS
 * (아래)에 있는 필드만 서버 스키마에 남긴다 — crudjs.js가 이 스키마만
 * 보고 화면 입력칸/hidden 노드를 만들므로, 이 목록 밖의 t매출 필드는
 * 화면에도 서버 응답에도 전혀 노출되지 않는다. **이 목록에 필드를
 * 추가·제거할 때는 crudjs_fetch.php의 CRUDJS_FIELD_WHITELIST도 반드시
 * 똑같이 맞춰야 한다** — 한쪽만 고치면 여기(화면에 그 필드의 hidden
 * 노드가 없음) 또는 그쪽(직접 POST 시 그 필드가 조용히 무시됨)에서
 * 어긋난다.
 *
 * 이 화면·crudjs_fetch.php 둘 다 여전히 레코드 단위 권한 검사/로그인
 * 보호는 없다 — 그 알려진 위험은 crudjs_fetch.php 상단 docblock과 위 md
 * 파일에 그대로 남겨뒀다. 실사용 화면으로 확장하기 전 재검토 필요.
 */

/**
 * crudjs_fetch.php의 CRUDJS_FIELD_WHITELIST와 반드시 같은 목록을 유지해야
 * 한다 — 이 화면(entry)은 "무엇을 보여줄지", fetch는 "무엇을 실제로
 * 읽고 쓸지"를 각자 결정하는데, 둘이 다른 파일이라 상수를 공유하지 않고
 * 그대로 복제해 둔다(Crud::sanitizeUtf8Row()가 SP.php/Board.php에도
 * 독립적으로 복제돼 있는 이 프로젝트의 기존 관례와 동일한 이유).
 */
const CRUDJS_VISIBLE_FIELDS = ['룸번호', '손님이름', '입실시각', '날짜'];
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);

require_once __DIR__ . '/Crud.php';
require_once APP_BASE_DIR . '/src/db/DbContext.php';

function crudjs_entry_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$loadError = null;
$schemaJson = 'null';

try {
    // crudjs_fetch.php와 동일하게 t매출/mssql로 고정 — (0) "화면이
    // 그려질 때 서버단의 crud가 스키마정보를 추출해 클라 js에게 전해준다"
    // 를 페이지 렌더링 시점에 직접 수행한다(별도 왕복 없이 인라인으로 심음).
    $db = new DbContext('mssql');
    $crud = new Crud($db);
    $crud->init('t매출', CRUDJS_VISIBLE_FIELDS);
    $schemaJson = json_encode($crud->getSchema(), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

$crudjsJsPath = APP_BASE_DIR . '/src/Model/Crud/crudjs.js';
$crudjsJsUrl = APP_BASE_URL . '/src/Model/Crud/crudjs.js?v=' . (is_file($crudjsJsPath) ? filemtime($crudjsJsPath) : time());
$fetchUrl = APP_BASE_URL . '/src/Model/Crud/crudjs_fetch.php';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>CrudJs 연습 (t매출)</title>
<style>
    body { font-family: sans-serif; max-width: 640px; margin: 2rem auto; color: #202124; }
    h1 { font-size: 1.2rem; }
    .notice { padding: 10px 12px; border-radius: 6px; margin-bottom: 1rem; font-size: 0.85rem; }
    .notice.error { background: #fdecea; color: #c0392b; }
    .notice.warn { background: #fff8e1; color: #7a5b00; border: 1px solid #ffe08a; }
    .field-row { margin-bottom: 10px; }
    .field-row label { display: block; font-size: 0.82rem; color: #5f6368; margin-bottom: 3px; }
    .field-row input {
        width: 100%; box-sizing: border-box; padding: 6px 8px;
        border: 1px solid #dadce0; border-radius: 4px; font-size: 0.9rem;
    }
    .toolbar { display: flex; gap: 8px; flex-wrap: wrap; margin: 16px 0; align-items: center; }
    .toolbar input {
        padding: 6px 8px; border: 1px solid #dadce0; border-radius: 4px;
        font-size: 0.85rem; width: 120px;
    }
    .toolbar button {
        border: 1px solid #dadce0; background: #fff; color: #3c4043;
        border-radius: 6px; padding: 6px 14px; font-size: 0.85rem; cursor: pointer;
    }
    .toolbar button:hover { background: #f1f3f4; }
    #crudjs-status {
        font-size: 0.85rem; color: #3c4043; background: #f8f9fa;
        border: 1px solid #eee; border-radius: 6px; padding: 8px 10px;
    }
</style>
</head>
<body>
<h1>CrudJs 연습 (t매출)</h1>

<?php if ($loadError !== null): ?>
    <div class="notice error"><?= crudjs_entry_h($loadError) ?></div>
<?php else: ?>

    <p class="notice warn">
        이 화면은 화면에 보이는 필드만 서버 스키마에 남기는 화이트리스트가
        적용돼 있습니다. 값의 실제 저장소는 crudjs 인스턴스 내부의
        <code>_data</code> 객체이고, 화면 입력칸은 그 값을 뿌리는
        자리일 뿐입니다 — 이 연습 페이지는 <code>showHidden: true</code>로
        열어둬서 화면에 없는 필드(예: 날짜)도 hidden input으로 만들어
        개발자도구에서 확인할 수 있게 했습니다(꺼두면 그 필드는 DOM에
        전혀 안 나타납니다). 다만 레코드 단위 권한 검사·로그인 보호는
        여전히 없는 상태로, 원안 설계((0)~(5))를 검증하기 위한 연습
        페이지입니다. 자세한 내용은 <code>crudjs_fetch.php</code> 상단
        주석과 <code>md/2026-09-14-crudjs-구현.md</code> 참고.
    </p>

    <div class="field-row">
        <label for="룸번호">룸번호</label>
        <input type="text" id="룸번호">
    </div>
    <div class="field-row">
        <label for="손님이름">손님이름</label>
        <input type="text" id="손님이름">
    </div>
    <div class="field-row">
        <label for="입실시각">입실시각</label>
        <input type="text" id="입실시각" placeholder="예: 21:30">
    </div>

    <div class="toolbar">
        <input type="text" id="crudjs-pk-input" placeholder="code (selectRecord용)">
        <button type="button" id="crudjs-btn-select">selectRecord</button>
        <button type="button" id="crudjs-btn-save">저장</button>
        <button type="button" id="crudjs-btn-delete">삭제</button>
        <button type="button" id="crudjs-btn-clear">화면클리어</button>
        <button type="button" id="crudjs-btn-show-json">JSON 보기</button>
    </div>

    <p id="crudjs-status">대기 중</p>
    <pre id="crudjs-json-output" style="display:none; background:#f8f9fa; border:1px solid #eee; border-radius:6px; padding:8px 10px; font-size:0.8rem; overflow-x:auto;"></pre>

    <h3 style="font-size:1rem; margin-top:24px;">다중 레코드(커서) 모드</h3>
    <p class="notice warn" style="margin-bottom:10px;">
        여러 행을 커서로 넘나들며 값을 고치면 dirty로 표시되고, "이 행
        삭제 표시"로 삭제 대상을 표시할 수 있습니다. 실제 서버 반영은
        "일괄 저장"을 눌러야만 일어납니다 — 위 단일 레코드 저장/삭제와는
        별개 흐름입니다.
    </p>
    <div class="toolbar">
        <button type="button" id="crudjs-btn-select-records">여러 행 조회</button>
        <button type="button" id="crudjs-btn-move-first">처음</button>
        <button type="button" id="crudjs-btn-move-prev">이전</button>
        <span id="crudjs-cursor-label">0 / 0</span>
        <button type="button" id="crudjs-btn-move-next">다음</button>
        <button type="button" id="crudjs-btn-move-last">마지막</button>
    </div>
    <div class="toolbar">
        <button type="button" id="crudjs-btn-mark-deleted">이 행 삭제 표시</button>
        <button type="button" id="crudjs-btn-save-bulk">일괄 저장</button>
        <span id="crudjs-row-flags">(변경 없음)</span>
    </div>

    <script src="<?= crudjs_entry_h($crudjsJsUrl) ?>"></script>
    <script>
    (function () {
        var crudjs = new CrudJs({
            fetchUrl: <?= json_encode($fetchUrl, JSON_UNESCAPED_SLASHES) ?>,
            schema: <?= $schemaJson ?>,
            // window를 안 주면 이제 headless(화면 없이 _data만으로 동작)
            // 이므로, 이 화면(현재 페이지)에 직접 얹는 데모라 명시적으로
            // 현재 window를 참조시킨다.
            window: window,
            // 이 연습 페이지 전용 — 화면에 입력칸을 안 둔 필드(예: 날짜)도
            // hidden input으로 만들어 개발자도구에서 확인할 수 있게 한다.
            // 실사용 화면이라면 꺼서(기본값 false) 그 필드를 DOM에 전혀
            // 안 남기는 쪽이 맞다.
            showHidden: false,
        });

        var statusEl = document.getElementById('crudjs-status');

        function setStatus(text) {
            statusEl.textContent = text;
        }

        // 다중 레코드(커서) 모드 — 지금 몇 번째/전체 몇 건인지, 지금
        // 행이 dirty·삭제표시 상태인지를 화면에 반영한다. move에는
        // 별도 훅이 없으므로(지시대로) 클릭 핸들러가 직접 호출한다.
        var cursorLabelEl = document.getElementById('crudjs-cursor-label');
        var rowFlagsEl = document.getElementById('crudjs-row-flags');
        var markDeletedBtn = document.getElementById('crudjs-btn-mark-deleted');

        function updateCursorUi() {
            var pos = crudjs.getCursorPosition();
            var count = crudjs.recordCount();
            var label;
            if (count === 0) {
                label = '0 / 0';
            } else if (crudjs.bof()) {
                label = 'BOF / ' + count;
            } else if (crudjs.eof()) {
                label = 'EOF / ' + count;
            } else {
                label = (pos + 1) + ' / ' + count;
            }
            cursorLabelEl.textContent = label;

            var flags = [];
            if (crudjs.isDirty()) {
                flags.push('dirty');
            }
            if (crudjs.isDeleted()) {
                flags.push('삭제 표시됨');
            }
            if (flags.length > 0) {
                rowFlagsEl.textContent = flags.join(', ');
            } else {
                rowFlagsEl.textContent = '(변경 없음)';
            }

            if (crudjs.isDeleted()) {
                markDeletedBtn.textContent = '삭제 표시 해제';
            } else {
                markDeletedBtn.textContent = '이 행 삭제 표시';
            }
        }

        // crudjs.validateSchema()(스키마의 nullable/maxLength/type만
        // 보는 구조적 검사)는 save()가 자동으로 먼저 돌린다 — 여기
        // validate는 그걸로는 못 잡는 이 화면만의 업무 규칙(손님이름
        // 필수)을 추가로 보는 것, (4) 그대로 save_before 단계에서
        // 실행된다.
        crudjs.validate = function () {
            var name = crudjs.getValue('손님이름');
            if (!name) {
                crudjs._validationMessage = '손님이름을 입력해야 합니다.';
                return false;
            }
            return true;
        };

        // save_before가 false를 반환하면 save()는 서버 요청조차 안 보내고
        // 그 자리에서 멈춘다(crudjs.js 참고) — delete_before의 confirm()
        // 패턴과 동일하게, 여기서도 실제로 막아지는 걸 확인할 수 있게 한다.
        crudjs.save_before = function () {
            if (!window.confirm('저장하시겠습니까?')) {
                return false;
            }
            setStatus('저장 중...');
        };
        crudjs.save_after = function () {
            setStatus('저장 성공 (code=' + crudjs.getValue('code') + ')');
        };
        crudjs.delete_before = function () {
            return window.confirm('현재 code(' + crudjs.getValue('code') + ') 레코드를 삭제할까요?');
        };
        crudjs.delete_after = function () {
            setStatus('삭제 성공');
            crudjs.clear();
        };
        crudjs.selectRecord_before = function () {
            setStatus('조회 중...');
        };
        crudjs.selectRecord_after = function () {
            setStatus('조회 성공 (code=' + crudjs.getValue('code') + ')');
            updateCursorUi();
        };
        // clear_before도 false를 반환하면 실제로는 아무것도 안 지워진다
        // (crudjs.js 참고) — save_before/delete_before와 대칭으로 여기서도
        // 실제로 막히는 걸 확인할 수 있게 한다.
        crudjs.clear_before = function () {
            return window.confirm('입력한 내용을 모두 지울까요?');
        };
        crudjs.clear_after = function () {
            setStatus('화면을 초기화했습니다.');
        };

        document.getElementById('crudjs-btn-save').addEventListener('click', function () {
            crudjs.save().then(function (ok) {
                if (!ok) {
                    setStatus('저장 실패: ' + crudjs.getError());
                }
            });
        });

        document.getElementById('crudjs-btn-delete').addEventListener('click', function () {
            crudjs.deleteRecord().then(function (ok) {
                if (!ok) {
                    setStatus('삭제 실패: ' + crudjs.getError());
                }
            });
        });

        document.getElementById('crudjs-btn-clear').addEventListener('click', function () {
            if (!crudjs.clear()) {
                setStatus('초기화 취소: ' + crudjs.getError());
            }
        });

        // getJson() — 지금 crudjs 인스턴스가 내부에 들고 있는 값을
        // {schema, cursor, data} 그대로 확인(화면에 입력칸이 없는
        // 필드도 여기 포함된다).
        var jsonOutputEl = document.getElementById('crudjs-json-output');
        document.getElementById('crudjs-btn-show-json').addEventListener('click', function () {
            var isHidden = jsonOutputEl.style.display === 'none';
            if (isHidden) {
                jsonOutputEl.textContent = crudjs.getJson();
                jsonOutputEl.style.display = 'block';
            } else {
                jsonOutputEl.style.display = 'none';
            }
        });

        document.getElementById('crudjs-btn-select').addEventListener('click', function () {
            var pk = document.getElementById('crudjs-pk-input').value;
            if (!pk) {
                setStatus('selectRecord: code를 입력하세요.');
                return;
            }
            crudjs.selectRecord(pk).then(function (ok) {
                if (!ok) {
                    setStatus('조회 실패: ' + crudjs.getError());
                }
            });
        });

        document.getElementById('crudjs-btn-select-records').addEventListener('click', function () {
            crudjs.selectRecords().then(function (ok) {
                if (!ok) {
                    setStatus('여러 행 조회 실패: ' + crudjs.getError());
                } else {
                    setStatus('여러 행 조회 성공 (' + crudjs.recordCount() + '건)');
                }
                updateCursorUi();
            });
        });

        document.getElementById('crudjs-btn-move-first').addEventListener('click', function () {
            crudjs.moveFirst();
            updateCursorUi();
        });
        document.getElementById('crudjs-btn-move-prev').addEventListener('click', function () {
            crudjs.movePrevious();
            updateCursorUi();
        });
        document.getElementById('crudjs-btn-move-next').addEventListener('click', function () {
            crudjs.moveNext();
            updateCursorUi();
        });
        document.getElementById('crudjs-btn-move-last').addEventListener('click', function () {
            crudjs.moveLast();
            updateCursorUi();
        });

        document.getElementById('crudjs-btn-mark-deleted').addEventListener('click', function () {
            crudjs.markDeleted(!crudjs.isDeleted());
            updateCursorUi();
        });

        document.getElementById('crudjs-btn-save-bulk').addEventListener('click', function () {
            if (!window.confirm('변경된(dirty/삭제표시) 행을 서버에 일괄 반영할까요?')) {
                return;
            }
            crudjs.saveBulk().then(function (result) {
                if (!result) {
                    setStatus('일괄 저장 실패: ' + crudjs.getError());
                    return;
                }
                var saveOkCount = result.results.filter(function (r) { return r.success; }).length;
                var deleteOkCount = result.deleteResults.filter(function (r) { return r.success; }).length;
                setStatus(
                    '일괄 저장 완료 — 수정 성공 ' + saveOkCount + '/' + result.results.length +
                    ', 삭제 성공 ' + deleteOkCount + '/' + result.deleteResults.length
                );
                updateCursorUi();
            });
        });

        updateCursorUi();
    })();
    </script>
<?php endif; ?>
</body>
</html>
