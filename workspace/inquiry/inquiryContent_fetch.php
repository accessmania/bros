<?php
declare(strict_types=1);

/**
 * [문의확인 화면(inquiryContent_entry.php) 좌측 상단 입력폼의 서버 엔드포인트]
 * 문의 한 건(?code=<t문의.code>)을 읽고 쓴다. 탭 그리드들은 탭마다 자기
 * fetch(inquiryContent_<탭>_fetch.php)를 쓴다.
 *
 * action:
 *  - record(GET)       — t문의 한 건 → {record}.
 *  - save_record(POST) — 입력폼 값 저장. body {code, values:{필드: 값}} → {ok, code, isNew}.
 *                        RECORD_FIELDS에 있는 필드만 쓴다(code/ts 등은 무시).
 *                        ?code=new면 새 문의로 insert하고 새 code를 돌려준다.
 *                        이때 멤버code/멤버는 로그인한 사람(세션)으로 넣는다.
 *  - children(GET)     — 딸린 자료 건수 → {children:{문의내역: n, ...}}(삭제 확인창용).
 *  - staff(GET)        — ?customer=<고객사code>의 직원 목록 → {staff:[[code, 담당자, {직책, ...}], ...]}
 *                        (고객사를 바꿀 때 고객사직원 콤보를 다시 채운다. 문의 code 불필요).
 *  - delete_record(POST) — 문의와 딸린 문의내역/수주/발주/견적(parentcode = code),
 *                        talk 대화(ttalk, type = TALK_TYPE)를 한 트랜잭션으로 함께
 *                        삭제 → {ok, code, deleted:{이름: 건수}}.
 *
 * 문의일자는 dblib 형식('Sep 30 2026 12:00:00:000AM')으로 오므로 Str::toDate()로
 * 'Y-m-d'로 바꿔 내려보낸다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Crud', 'Login', 'Str');

// 독립 엔드포인트라 Layout(index)의 보호를 못 받으므로 여기서도 확인한다.
Login::isAlive();

$connectionName = null; // config/db.php의 'driver' 기본 접속을 따른다
$db = new DbContext($connectionName); // 이 요청의 DB 접속(처음 쓸 때 연결)

const INQUIRY_TABLE = 't문의';

// 입력폼이 읽고 쓰는 t문의 필드(문의확인.png 좌측 상단). code는 키라 따로 다룬다.
const RECORD_FIELDS = [
    '문의일자', '상태', '멤버code', '멤버', '고객사code', '고객사',
    '고객사직원code', '고객사직원', '휴대폰', '직책', '팩스',
    '회사전화번호', '이메일', '근무지주소', '배송주소', '비고',
];

/** JSON으로 응답하고 끝낸다. */
function inquiry_content_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    exit;
}

/**
 * 직원을 고르면 입력폼에 옮겨 넣는 tb고객사_직원목록 필드 — t문의에 같은 이름의
 * 필드가 있다. inquiryContent_entry.php의 STAFF_DETAIL_FIELDS와 같아야 한다.
 */
const STAFF_DETAIL_FIELDS = ['직책', '회사전화번호', '휴대폰', '팩스', '이메일', '근무지주소'];

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

$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
if (!in_array($action, ['record', 'save_record', 'delete_record', 'children', 'staff'], true)) {
    inquiry_content_json(['error' => '알 수 없는 action입니다.'], 400);
}

// staff — 고객사(?customer=)의 직원 목록. 문의 code와 무관해 먼저 처리한다.
if ($action === 'staff') {
    try {
        $customer = isset($_GET['customer']) ? trim((string) $_GET['customer']) : '';
        inquiry_content_json(['staff' => inquiry_staff_options($db, $customer)]);
    } catch (Throwable $e) {
        inquiry_content_json(['error' => $e->getMessage()], 500);
    }
}

// code=new는 신규 문의(저장 시 insert) — save_record에서만 받는다.
$code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
$isNew = $code === 'new' && $action === 'save_record';
if (!$isNew && ($code === '' || !ctype_digit($code))) {
    inquiry_content_json(['error' => '문의 code가 없습니다.'], 400);
}

/** 문의에 딸린 자료(parentcode = 문의 code) — [테이블 => 화면 이름]. 문의를 지우면 함께 지운다. */
const CHILD_TABLES = ['t문의내역' => '문의내역', 't수주내역' => '수주', 't발주내역' => '발주', 't문의_견적산출' => '견적'];

/**
 * ttalk.type — 문의 대화의 종류. inquiryContent_entry.php의 TALK_TYPE과 같아야 한다.
 * 문의를 지우면 ttalk 중 type = TALK_TYPE이고 parentcode = 문의 code인 대화도 지운다
 * (다른 type의 대화는 다른 화면 것이라 건드리지 않는다).
 */
const TALK_TYPE = 'test';

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

switch ($action) {
    case 'children':
        // 삭제 확인창에 보여줄 딸린 자료 건수.
        inquiry_content_json(['children' => inquiry_child_counts($db, $code)]);
        break;

    case 'delete_record':
        // 딸린 문의내역/수주/발주/견적(parentcode = code)과 talk 대화(ttalk,
        // type = TALK_TYPE)를 먼저 지우고 문의를 지운다. 한 트랜잭션이라 중간에
        // 실패하면 전부 되돌린다.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            inquiry_content_json(['error' => 'POST로만 삭제할 수 있습니다.'], 400);
        }
        $pdo = $db->pdo();
        $q = function (string $name) use ($db): string {
            return $db->dialect()->quoteIdentifier($name);
        };
        try {
            $pdo->beginTransaction();
            $deleted = [];
            foreach (inquiry_child_sources($db, $code) as $label => $from) {
                $n = $pdo->exec('DELETE FROM ' . $from);
                if ($n > 0) {
                    $deleted[$label] = $n;
                }
            }
            $n = $pdo->exec('DELETE FROM ' . $q(INQUIRY_TABLE) . ' WHERE ' . $q('code') . ' = ' . $pdo->quote($code));
            if ($n === 0) {
                $pdo->rollBack();
                inquiry_content_json(['error' => '문의(' . $code . ')를 찾을 수 없습니다.'], 404);
            }
            $pdo->commit();
            inquiry_content_json(['ok' => true, 'code' => $code, 'deleted' => $deleted]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            inquiry_content_json(['error' => $e->getMessage()], 500);
        }
        break;

    case 'record':
        $crud = new Crud($db);
        $crud->init(INQUIRY_TABLE);
        if (!$crud->selectRecord($code)) {
            inquiry_content_json(['error' => '문의(' . $code . ')를 찾을 수 없습니다.'], 404);
        }
        $record = $crud->getAllValues();
        if (array_key_exists('문의일자', $record)) {
            $record['문의일자'] = Str::toDate($record['문의일자'], $record['문의일자']);
        }
        inquiry_content_json(['record' => $record]);
        break;

    case 'save_record':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            inquiry_content_json(['error' => 'POST로만 저장할 수 있습니다.'], 400);
        }
        $payload = json_decode((string) file_get_contents('php://input'), true);
        $values = isset($payload['values']) && is_array($payload['values']) ? $payload['values'] : [];
        try {
            $crud = new Crud($db);
            $crud->init(INQUIRY_TABLE);
            // 신규면 빈 레코드에 값을 넣어 insert(code는 identity라 DB가 정한다),
            // 아니면 기존 레코드를 읽어 update.
            if (!$isNew && !$crud->selectRecord($code)) {
                inquiry_content_json(['error' => '문의(' . $code . ')를 찾을 수 없습니다.'], 404);
            }
            foreach (RECORD_FIELDS as $field) {
                if (array_key_exists($field, $values)) {
                    $crud->setValue($field, $values[$field]);
                }
            }
            // 신규 문의의 멤버는 로그인한 사람(code와 이름 둘 다) — 화면이 보낸 값이
            // 아니라 세션 값으로 넣는다(화면 값은 바꿔 보낼 수 있으므로).
            if ($isNew) {
                $crud->setValue('멤버code', (string) Login::getValue('code'));
                $crud->setValue('멤버', (string) Login::getValue('이름'));
            }
            $savedCode = $crud->save();
            inquiry_content_json(['ok' => true, 'code' => (string) ($isNew ? $savedCode : $code), 'isNew' => $isNew]);
        } catch (Throwable $e) {
            inquiry_content_json(['error' => $e->getMessage()], 500);
        }
        break;

}
