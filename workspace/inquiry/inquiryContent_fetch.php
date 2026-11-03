<?php
declare(strict_types=1);

/**
 * [문의확인 화면(inquiryContent_entry.php) 좌측 상단 입력폼의 서버 엔드포인트]
 * t문의 한 건을 CrudJs 계약으로 읽고 쓴다 — 입력폼 아래 crudbuttons iframe 안의
 * CrudJs가 부른다(src/Model/Crud/crudbuttons/crudbuttons.md "xxx_fetch.php 계약").
 * 탭 그리드들은 탭마다 자기 fetch(inquiryContent_<탭>_fetch.php)를 쓴다.
 *
 * action(CrudJs 계약 — 응답은 전부 {success:true, ...} 또는 {success:false, error}):
 *  - schema               — 스키마 → {success, schema}.
 *  - selectRecord&pk=     — t문의 한 건 → {success, schema, cursor:0, data:[행]}.
 *  - save (POST, JSON 행) — PK(code) 있으면 update, 없으면 insert → {success, pk}.
 *                           insert면 멤버code/멤버를 로그인한 사람(세션)으로 넣는다
 *                           (화면이 보낸 값은 바꿔 보낼 수 있으므로).
 *  - delete&pk= (POST)    — 문의와 딸린 문의내역/수주/발주/견적(parentcode = code),
 *                           talk 대화(ttalk, type = TALK_TYPE)를 한 트랜잭션으로 함께
 *                           삭제 → {success, affected, deleted:{이름: 건수}}.
 * 이 화면만 쓰는 action(입력폼의 이벤트/함수가 직접 부른다):
 *  - children&pk=         — 딸린 자료 건수 → {success, children:{문의내역: n, ...}}
 *                           (beforeCrudDelete의 확인창).
 *  - staff&customer=      — 고객사 하나의 직원 목록
 *                           → {success, staff:[[code, 담당자, {직책, ...}], ...]}
 *                           (고객사를 바꿀 때 고객사직원 콤보를 다시 채운다).
 *
 * 문의일자는 dblib 형식('Sep 30 2026 12:00:00:000AM')으로 나가지만 CrudJs가 받자마자
 * 'YYYY-MM-DD'로 바꾼다(따로 변환하지 않는다).
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Crud', 'Login');

// 독립 엔드포인트라 Layout(index)의 보호를 못 받으므로 여기서도 확인한다.
Login::isAlive();

header('Content-Type: application/json; charset=utf-8');

// 테이블은 클라이언트가 고르지 못하게 이 파일에 고정한다.
const INQUIRY_TABLE = 't문의';

/**
 * 입력폼이 읽고 쓰는 t문의 필드(PK code는 자동 포함). 화면에 컨트롤이 없는 필드
 * (멤버code, 고객사, 고객사직원)는 CrudJs 안에만 값이 있다 — 고객사/고객사직원은
 * 저장 직전 beforeCrudSave가 콤보의 표시 글자로 채운다.
 */
const RECORD_FIELDS = [
    '문의일자', '상태', '멤버code', '멤버', '고객사code', '고객사',
    '고객사직원code', '고객사직원', '휴대폰', '직책', '팩스',
    '회사전화번호', '이메일', '근무지주소', '배송주소', '비고',
];

/**
 * 직원을 고르면 입력폼에 옮겨 넣는 tb고객사_직원목록 필드 — t문의에 같은 이름의
 * 필드가 있다. inquiryContent_entry.php의 STAFF_DETAIL_FIELDS와 같아야 한다.
 */
const STAFF_DETAIL_FIELDS = ['직책', '회사전화번호', '휴대폰', '팩스', '이메일', '근무지주소'];

/** 문의에 딸린 자료(parentcode = 문의 code) — [테이블 => 화면 이름]. 문의를 지우면 함께 지운다. */
const CHILD_TABLES = ['t문의내역' => '문의내역', 't수주내역' => '수주', 't발주내역' => '발주', 't문의_견적산출' => '견적'];

/**
 * ttalk.type — 문의 대화의 종류. inquiryContent_entry.php의 TALK_TYPE과 같아야 한다.
 * 문의를 지우면 ttalk 중 type = TALK_TYPE이고 parentcode = 문의 code인 대화도 지운다
 * (다른 type의 대화는 다른 화면 것이라 건드리지 않는다).
 */
const TALK_TYPE = 'test';

/** JSON으로 응답하고 끝낸다. */
function inquiry_content_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    exit;
}

/** ?pk= 값 — 숫자가 아니면 오류로 끝낸다. */
function inquiry_content_pk(): string
{
    $pk = isset($_GET['pk']) ? trim((string) $_GET['pk']) : '';
    if ($pk === '' || !ctype_digit($pk)) {
        throw new InvalidArgumentException('문의 code(pk)가 없습니다.');
    }
    return $pk;
}

/**
 * 고객사 하나의 직원 목록 — tb고객사_직원목록 중 parentcode = 고객사code, 담당자 순.
 * [[code, 담당자, {직책, 회사전화번호, ...}], ...]. 입력폼의 고객사직원 콤보 원본이고,
 * 셋째 칸은 직원을 고를 때 입력폼에 채울 값이다. 화면이 처음 뜰 때는
 * inquiryContent_entry.php가 같은 쿼리로 직접 읽는다(ic_staff_options).
 *
 * @return array<int, array{0: string, 1: string, 2: array<string, string>}>
 */
function inquiry_staff_options(DbContext $db, string $customerCode): array
{
    if ($customerCode === '' || !ctype_digit($customerCode)) {
        return [];
    }
    $q = function (string $name) use ($db): string {
        return $db->dialect()->quoteIdentifier($name);
    };
    $sql = 'SELECT ' . $q('code') . ', ' . $q('담당자') . ', '
        . implode(', ', array_map($q, STAFF_DETAIL_FIELDS))
        . ' FROM ' . $q('tb고객사_직원목록')
        . ' WHERE ' . $q('parentcode') . ' = ' . $db->pdo()->quote($customerCode)
        . ' ORDER BY ' . $q('담당자') . ' ASC';
    $options = [];
    foreach ($db->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $details = [];
        foreach (STAFF_DETAIL_FIELDS as $field) {
            $details[$field] = $row[$field] === null ? '' : trim((string) $row[$field]);
        }
        $options[] = [(string) $row['code'], $row['담당자'] === null ? '' : (string) $row['담당자'], $details];
    }
    return $options;
}

/**
 * 딸린 자료마다 [화면 이름 => WHERE 붙은 FROM 절] — 건수 세기와 삭제가 같이 쓴다.
 * CHILD_TABLES(parentcode = code) + talk 대화(ttalk, type = TALK_TYPE).
 *
 * @return array<string, string>
 */
function inquiry_child_sources(DbContext $db, string $code): array
{
    $q = function (string $name) use ($db): string {
        return $db->dialect()->quoteIdentifier($name);
    };
    $byParent = $q('parentcode') . ' = ' . $db->pdo()->quote($code);
    $sources = [];
    foreach (CHILD_TABLES as $table => $label) {
        $sources[$label] = $q($table) . ' WHERE ' . $byParent;
    }
    $sources['talk 대화'] = $q('ttalk') . ' WHERE ' . $q('type') . ' = ' . $db->pdo()->quote(TALK_TYPE) . ' AND ' . $byParent;
    return $sources;
}

/**
 * 이 문의에 딸린 자료 건수 — [화면 이름 => 건수]. 건수가 0인 것은 빼고 돌려준다.
 *
 * @return array<string, int>
 */
function inquiry_child_counts(DbContext $db, string $code): array
{
    $counts = [];
    foreach (inquiry_child_sources($db, $code) as $label => $from) {
        $n = (int) $db->pdo()->query('SELECT COUNT(*) FROM ' . $from)->fetchColumn();
        if ($n > 0) {
            $counts[$label] = $n;
        }
    }
    return $counts;
}

$action = isset($_GET['action']) && $_GET['action'] !== '' ? (string) $_GET['action'] : 'schema';

try {
    $db = new DbContext(null); // config/db.php의 'driver' 기본 접속
    $crud = new Crud($db);
    $crud->init(INQUIRY_TABLE, RECORD_FIELDS);

    switch ($action) {
        case 'schema':
            inquiry_content_json(['success' => true, 'schema' => $crud->getSchema()]);
            break;

        case 'selectRecord':
            $pk = inquiry_content_pk();
            if (!$crud->selectRecord($pk)) {
                inquiry_content_json(['success' => false, 'error' => '문의(' . $pk . ')를 찾을 수 없습니다.'], 404);
            }
            inquiry_content_json([
                'success' => true,
                'schema' => $crud->getSchema(),
                'cursor' => 0,
                'data' => [$crud->getAllValues()],
            ]);
            break;

        case 'save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new InvalidArgumentException('POST로만 저장할 수 있습니다.');
            }
            $row = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($row)) {
                throw new InvalidArgumentException('요청 본문이 올바른 JSON이 아닙니다.');
            }
            $pk = isset($row['code']) ? trim((string) $row['code']) : '';
            if ($pk !== '' && !ctype_digit($pk)) {
                throw new InvalidArgumentException('문의 code가 올바르지 않습니다.');
            }
            // 화이트리스트 밖 필드는 스키마에 없어 loadArray()가 건너뛴다.
            $crud->loadArray($row);
            // 신규 문의의 멤버는 로그인한 사람(code와 이름 둘 다) — 세션 값으로 넣는다.
            if ($pk === '') {
                $crud->setValue('멤버code', (string) Login::getValue('code'));
                $crud->setValue('멤버', (string) Login::getValue('이름'));
            }
            inquiry_content_json(['success' => true, 'pk' => (string) $crud->save()]);
            break;

        case 'delete':
            // 딸린 문의내역/수주/발주/견적(parentcode = code)과 talk 대화(ttalk,
            // type = TALK_TYPE)를 먼저 지우고 문의를 지운다. 한 트랜잭션이라 중간에
            // 실패하면 전부 되돌린다.
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new InvalidArgumentException('POST로만 삭제할 수 있습니다.');
            }
            $pk = inquiry_content_pk();
            $pdo = $db->pdo();
            try {
                $pdo->beginTransaction();
                $deleted = [];
                foreach (inquiry_child_sources($db, $pk) as $label => $from) {
                    $n = $pdo->exec('DELETE FROM ' . $from);
                    if ($n > 0) {
                        $deleted[$label] = $n;
                    }
                }
                $affected = $crud->deleteRecord($pk);
                if ($affected === 0) {
                    $pdo->rollBack();
                    inquiry_content_json(['success' => false, 'error' => '문의(' . $pk . ')를 찾을 수 없습니다.'], 404);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            inquiry_content_json(['success' => true, 'affected' => $affected, 'deleted' => $deleted]);
            break;

        case 'children':
            // 삭제 확인창에 보여줄 딸린 자료 건수.
            inquiry_content_json(['success' => true, 'children' => inquiry_child_counts($db, inquiry_content_pk())]);
            break;

        case 'staff':
            $customer = isset($_GET['customer']) ? trim((string) $_GET['customer']) : '';
            inquiry_content_json(['success' => true, 'staff' => inquiry_staff_options($db, $customer)]);
            break;

        default:
            throw new InvalidArgumentException('알 수 없는 action입니다: ' . $action);
    }
} catch (Throwable $e) {
    inquiry_content_json(['success' => false, 'error' => $e->getMessage()], 500);
}
