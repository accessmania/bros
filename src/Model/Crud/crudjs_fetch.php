<?php

declare(strict_types=1);

/**
 * [crudjs_fetch.php — crudjs.js 전용 조회/저장/삭제 엔드포인트]
 *
 * md/2026-09-14-crudjs-구현.md ((0)~(5)) 설계를 그대로 구현한다 — 서버는
 * Crud::init()으로 뽑은 스키마를 클라이언트에 그대로 건네고, 클라이언트가
 * 다시 보낸 값을 그대로 save()에 반영한다. 어떤 필드가 그 스키마·조회·
 * 저장 대상이 되는지는 아래 CRUDJS_FIELD_WHITELIST가 정한다.
 *
 * [2026-09-14 후속 — 필드 단위 화이트리스트 적용]
 * `Crud::init()`의 화이트리스트 인자(아래 `CRUDJS_FIELD_WHITELIST`)로
 * 화면에 실제로 보이는 3개 필드(+PK, 자동 포함)만 스키마에 남긴다 — 이
 * 시점부터 스키마 노출/조회 응답/insert·update 전부 이 좁혀진 필드만
 * 다룬다(그 밖의 필드를 클라이언트가 보내도 `loadFrom()`이 스키마에 없는
 * 키는 조용히 무시하므로 SQL에 절대 반영되지 않는다). `crudjs_entry.php`
 * 의 `CRUDJS_VISIBLE_FIELDS`와 반드시 같은 목록을 유지해야 한다 — 한쪽만
 * 좁히면 다른 경로로 그대로 새므로 함께 맞춘다.
 *
 * [남아있는 알려진 위험 — 2026-09-13/14 세션에서 사용자와 상세히 논의한
 * 뒤, 이번 화이트리스트 적용 범위 밖으로 명시적으로 남긴 것들]
 * - **레코드(행) 단위 권한 검사가 없다.** pk 값만 바꾸면 화이트리스트
 *   안의 필드에 대해서는 어떤 t매출 레코드든 조회/수정/삭제할 수 있다
 *   (IDOR).
 * - **로그인 여부 확인(Login::isAlive())이 걸려 있지 않다.**
 * - 최소한의 방어로 **테이블명은 클라이언트가 지정하지 못하게 이 파일
 *   안에 고정**했다(아래 `CRUDJS_TABLE`) — "임의 테이블에 crud를 돌릴 수
 *   있는" 형태의 확장된 위험도 막았지만, 위 두 항목은 여전히 남는다.
 *
 * ## 액션
 * - `?action=schema` — `Crud::init()`의 스키마만 반환.
 * - `?action=selectRecord&pk=...` — PK로 레코드 1건 조회. 응답은
 *   `{success, schema, cursor:0, data:[그 행]}` — 아래 `selectRecords`와
 *   정확히 같은 봉투 모양이다(레코드가 1건뿐인 특수한 경우로 취급).
 * - `?action=selectRecords` — [2026-09-14 신설, 같은 날 TOP N으로 조정]
 *   t매출 레코드를 커서 방식으로 반환한다(여러 건). `Crud.php`에는 페이징
 *   개념이 없어(Crud.md 참고, 페이징은 Board의 역할) PK 내림차순
 *   `CRUDJS_SELECT_RECORDS_LIMIT`(=20)건만 서버가 직접 조립한 WHERE
 *   서브쿼리로 제한한다 — 클라이언트가 보낸 조건 문자열을 그대로
 *   `Crud::selectRecords($pWhere)`의 raw SQL WHERE에 넘기면 SQL 인젝션이
 *   되므로, 이 액션은 의도적으로 `?where=` 같은 파라미터를 아예 안 받고
 *   서버가 고정 조건만 쓴다. 응답은 `Crud::toJson()`을 그대로 감싼
 *   `{success:true, schema, cursor, data:[...]}`.
 * - `?action=save` (POST, JSON body = `{필드명: 값, ...}`) — 받은 값을
 *   그대로 `loadArray()` + `save()`(PK 있으면 update, 없으면 insert).
 * - `?action=delete&pk=...` (POST) — PK로 삭제.
 * - `?action=saveBulk` (POST, JSON body = `{data:[...], dirty:[인덱스,...],
 *   deleted:[PK,...]}`) — [2026-09-14 신설, 같은 날 dirty를 인덱스 기반으로
 *   전환] `selectRecord`/`selectRecords` 응답과 정확히 같은 봉투 모양을
 *   그대로 다시 받는다(`schema`/`cursor`는 이 액션에선 안 쓰고 무시).
 *   `dirty`는 PK가 아니라 **`data` 배열 안에서의 인덱스**다 — PK로
 *   찾으면 PK가 아직 없는(=한 번도 저장 안 된) 신규 행은 식별이
 *   불가능해 INSERT 케이스를 다룰 수 없었다(PHP `isset($row[$pk])`가
 *   `null` 값엔 항상 `false`). 인덱스는 그 행이 신규(INSERT)든 기존
 *   (UPDATE)이든 상관없이 항상 가리킬 수 있어, `data[인덱스]`를 그대로
 *   `Crud::clear()+loadArray()+save()`에 넘기면(`ActionBulk::handle()`과
 *   동일한 패턴) `Crud::save()`가 PK 유무로 알아서 insert/update를
 *   분기한다. `deleted`는 여전히 PK 배열 그대로 — 삭제는 서버에 이미
 *   존재하는(PK가 있는) 행에만 의미가 있다. 행 하나의 실패가 나머지를
 *   막지 않도록 각각 개별 try/catch. 응답은 `{success:true,
 *   results:[{index,success,newPk|message}],
 *   deleteResults:[{pk,success,message?}]}`.
 *
 * 응답은 전부 `{"success": true, ...}` 또는 `{"success": false, "error":
 * "..."}` 형태 하나로 통일한다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);

require_once __DIR__ . '/Crud.php';
require_once APP_BASE_DIR . '/src/db/DbContext.php';
// selectRecords()의 TOP N 조건을 안전하게 인용할 때 쓴다(deco_amd_fetch.php
// 의 updateMaechulField()와 동일한 이유로 명시적으로 require).

header('Content-Type: application/json; charset=utf-8');

// 클라이언트가 테이블/접속을 고르지 못하게 이 파일 안에서만 고정한다
// (지시 "저장 테이블은 t매출을 사용한다" — deco 업장의 실제 매출
// 테이블, config/db.php의 'mssql' 접속).
const CRUDJS_TABLE = 't매출';
const CRUDJS_CONNECTION = 'mssql';
// crudjs_entry.php의 CRUDJS_VISIBLE_FIELDS와 반드시 동일하게 유지해야
// 하는 목록 — 이 목록에 있는 필드만 서버 스키마/읽기/쓰기에 남긴다
// (Crud::init()의 $pWhitelist, PK는 항상 자동 포함). 두 파일이 각자
// 독립 진입점이라 상수를 공유하지 않고 그대로 복제해 둔다
// (sanitizeUtf8Row()와 같은 이 프로젝트의 기존 관례) — 여기만 고치고
// crudjs_entry.php를 안 맞추면, entry가 그 필드의 hidden 노드를 아예
// 안 만들어(스키마에 없음) 화면에서 값을 채울 방법이 없어진다.
const CRUDJS_FIELD_WHITELIST = ['룸번호', '손님이름', '입실시각'];
// selectRecords()가 전체 대신 가져올 최근 건수 — Crud.php는 페이징
// 개념이 없어(Crud.md "MySQL/MSSQL 겸용" 절 참고, 페이징은 Board의
// 역할) selectRecords()의 raw WHERE 조건에 서브쿼리로 흉내낸다.
const CRUDJS_SELECT_RECORDS_LIMIT = 20;

$action = isset($_GET['action']) && $_GET['action'] !== '' ? (string) $_GET['action'] : 'schema';

try {
    $db = new DbContext(CRUDJS_CONNECTION);
    $crud = new Crud($db);
    $crud->init(CRUDJS_TABLE, CRUDJS_FIELD_WHITELIST);

    switch ($action) {
        case 'schema':
            echo json_encode(['success' => true, 'schema' => $crud->getSchema()], JSON_UNESCAPED_UNICODE);
            break;

        case 'selectRecord':
            $pk = isset($_GET['pk']) ? (string) $_GET['pk'] : '';
            if ($pk === '') {
                throw new InvalidArgumentException('pk가 필요합니다.');
            }
            $found = $crud->selectRecord($pk);
            if (!$found) {
                echo json_encode(['success' => false, 'error' => '해당 레코드를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            // selectRecords()/saveBulk()와 정확히 같은 봉투 모양
            // ({schema, cursor, data}) — 레코드 1건이라 cursor는 항상
            // 0. Crud::selectRecord()는 자기 내부 cursor 프로퍼티를
            // 안 건드리므로(원래 커서 개념과 무관한 메서드) toJson()을
            // 그대로 쓰지 않고 직접 조립한다.
            echo json_encode([
                'success' => true,
                'schema' => $crud->getSchema(),
                'cursor' => 0,
                'data' => [$crud->getAllValues()],
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'selectRecords':
            // 전체 대신 "PK 내림차순 상위 N건"만 — 서버가 직접 조립한
            // 고정 조건이라 사용자 입력이 전혀 안 섞인다(위 docblock의
            // SQL 인젝션 회피 원칙 그대로 유지). Crud::selectRecords()는
            // WHERE 조각만 받을 뿐 TOP/ORDER BY 파라미터가 없어서,
            // "PK가 TOP N 서브쿼리 결과 안에 있다"는 조건 뒤에 같은
            // ORDER BY를 이어붙여 표현한다 — 최종 SQL은 문법상 정상적인
            // "WHERE ... ORDER BY ..." 한 문장이 된다.
            $dialect = $db->dialect();
            $quotedTable = $dialect->quoteIdentifier(CRUDJS_TABLE);
            $primaryKeyForLimit = $crud->getPrimaryKey();
            if ($primaryKeyForLimit === null) {
                throw new RuntimeException('테이블에 프라이머리 키가 없습니다: ' . CRUDJS_TABLE);
            }
            $quotedPk = $dialect->quoteIdentifier($primaryKeyForLimit);
            $limit = CRUDJS_SELECT_RECORDS_LIMIT;
            $topCondition = $quotedPk . ' IN (SELECT TOP ' . $limit . ' ' . $quotedPk .
                ' FROM ' . $quotedTable . ' ORDER BY ' . $quotedPk . ' DESC)' .
                ' ORDER BY ' . $quotedPk . ' DESC';
            $crud->selectRecords($topCondition);
            // toJson()이 이미 정확히 {schema, cursor, data} 모양이라
            // success 키만 얹어 그대로 쓴다.
            $payload = json_decode($crud->toJson(), true);
            $payload['success'] = true;
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            break;

        case 'saveBulk':
            $raw = file_get_contents('php://input');
            $payload = json_decode((string) $raw, true);
            if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
                throw new InvalidArgumentException('요청 본문이 올바른 형식이 아닙니다.');
            }

            $primaryKey = $crud->getPrimaryKey();
            if ($primaryKey === null) {
                throw new RuntimeException('테이블에 프라이머리 키가 없습니다: ' . CRUDJS_TABLE);
            }

            $dirtyIndexes = isset($payload['dirty']) && is_array($payload['dirty']) ? $payload['dirty'] : [];
            $deletedPks = isset($payload['deleted']) && is_array($payload['deleted']) ? $payload['deleted'] : [];

            // [2026-09-14 후속 — Crud::saveBulk_crudjs()로 추출] 행마다
            // clear()+loadArray()+save()/deleteRecord()를 반복하던 로직을
            // Crud.php로 옮겨 재사용 가능하게 만들었다 — t매출_내용처럼
            // 다중 레코드 쓰기가 필요한 다른 화면(실전 페이지)의 fetch
            // 엔드포인트도 자신의 $crud로 이 메서드 하나만 부르면 된다.
            // dirty가 PK 대신 인덱스인 이유/deleted가 여전히 PK인 이유는
            // Crud::saveBulk_crudjs()의 docblock 참고.
            $bulkResult = $crud->saveBulk_crudjs($payload['data'], $dirtyIndexes, $deletedPks);

            echo json_encode(
                ['success' => true, 'results' => $bulkResult['results'], 'deleteResults' => $bulkResult['deleteResults']],
                JSON_UNESCAPED_UNICODE
            );
            break;

        case 'save':
            $raw = file_get_contents('php://input');
            $row = json_decode((string) $raw, true);
            if (!is_array($row)) {
                throw new InvalidArgumentException('요청 본문이 올바른 JSON이 아닙니다.');
            }
            // (5) "로컬의 스키마정보에 탑재된 데이터값을 그대로... 바로
            // save" — 설계 그대로 loadArray()에 반영한다. 화이트리스트
            // 밖 필드(및 ts/__ts__, Crud::EXCLUDED_FIELDS)는 스키마 자체에
            // 없어 loadFrom()이 조용히 건너뛴다.
            $crud->loadArray($row);
            $pk = $crud->save();
            echo json_encode(['success' => true, 'pk' => $pk], JSON_UNESCAPED_UNICODE);
            break;

        case 'delete':
            $pk = isset($_GET['pk']) ? (string) $_GET['pk'] : '';
            if ($pk === '') {
                throw new InvalidArgumentException('pk가 필요합니다.');
            }
            $affected = $crud->deleteRecord($pk);
            echo json_encode(['success' => true, 'affected' => $affected], JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new InvalidArgumentException("알 수 없는 action입니다: {$action}");
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
