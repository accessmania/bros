<?php
declare(strict_types=1);

/**
 * [문의내역 팝업(inquiryContent_문의내용_amd.php)의 서버 엔드포인트]
 * t문의내역 한 건(?code=<t문의내역.code>)을 읽고 쓴다.
 *
 * action:
 *  - record(GET)         — t문의내역 한 건 → {record}.
 *  - save_record(POST)   — body {values:{필드: 값}} → {ok, code, isNew}.
 *                          RECORD_FIELDS 중 t문의내역에 실제로 있는 필드만 쓴다.
 *                          ?code=new면 ?parentcode=(t문의.code)로 새 행을 insert한다.
 *  - delete_record(POST) — 이 한 건을 지운다 → {ok, code}.
 *  - models(GET)         — ?keyword=로 모델 목록 검색 → {models:[{모델명, 제품종류, 품목코드, 제조사}, ...]}
 *                          (모델명 콤보 원본. 검색필드에 입력한 글자가 모델명에 들어간 것).
 *
 * 숫자 필드는 화면이 천단위 콤마를 붙여 보여주므로 저장 전에 콤마를 뺀다.
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

$db = new DbContext(null); // config/db.php의 'driver' 기본 접속

const DETAIL_TABLE = 't문의내역';

// 팝업 입력폼이 읽고 쓰는 t문의내역 필드(문의내용팝업.png). code/parentcode는 따로 다룬다.
// inquiryContent_문의내용_amd.php의 필드 배치와 같아야 한다.
const RECORD_FIELDS = [
    '모델명', '제품종류', '품목코드', '제조사',
    '상태', '수량', '구매원가', 'USD구매원가', '판매단가', 'USD판매단가', '마진율', 'USD마진율',
    '구매메모', '판매메모', '비고',
];

/** 천단위 콤마를 붙여 보여주는 숫자 필드 — 저장 전에 콤마를 빼고, 빈 값은 null. */
const NUMBER_FIELDS = ['수량', '구매원가', 'USD구매원가', '판매단가', 'USD판매단가', '마진율', 'USD마진율'];

/**
 * 모델명 콤보의 원본 — 모델명을 고르면 제품종류/품목코드/제조사가 함께 들어온다.
 * TODO: 실제 모델(품목) 테이블이 정해지면 바꾼다. 지금은 t문의내역에 이미 입력된
 * 모델들을 중복 없이 모아 쓴다.
 */
const MODEL_TABLE = 't문의내역';
const MODEL_FIELDS = ['모델명', '제품종류', '품목코드', '제조사'];

/** JSON으로 응답하고 끝낸다. */
function amd_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
if (!in_array($action, ['record', 'save_record', 'delete_record', 'models'], true)) {
    amd_json(['error' => '알 수 없는 action입니다.'], 400);
}

$q = function (string $name) use ($db): string {
    return $db->dialect()->quoteIdentifier($name);
};

// models — 문의내역 code와 무관해 먼저 처리한다.
if ($action === 'models') {
    try {
        $keyword = isset($_GET['keyword']) ? trim((string) $_GET['keyword']) : '';
        $cols = implode(', ', array_map($q, MODEL_FIELDS));
        $where = $q('모델명') . ' IS NOT NULL';
        if ($keyword !== '') {
            $where .= ' AND ' . $q('모델명') . ' LIKE ' . $db->pdo()->quote('%' . $keyword . '%');
        }
        $sql = 'SELECT TOP 200 ' . $cols . ' FROM ' . $q(MODEL_TABLE)
            . ' WHERE ' . $where . ' GROUP BY ' . $cols . ' ORDER BY ' . $q('모델명') . ' ASC';
        $models = [];
        foreach ($db->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $models[] = array_map(function ($v): string {
                return $v === null ? '' : trim((string) $v);
            }, $row);
        }
        amd_json(['models' => $models]);
    } catch (Throwable $e) {
        amd_json(['error' => $e->getMessage()], 500);
    }
}

// code=new는 신규 문의내역(저장 시 insert) — save_record에서만 받는다.
$code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
$isNew = $code === 'new' && $action === 'save_record';
if (!$isNew && ($code === '' || !ctype_digit($code))) {
    amd_json(['error' => '문의내역 code가 없습니다.'], 400);
}
$parentCode = isset($_GET['parentcode']) ? trim((string) $_GET['parentcode']) : '';
if ($isNew && ($parentCode === '' || !ctype_digit($parentCode))) {
    amd_json(['error' => '신규 문의내역은 parentcode(문의 code)가 필요합니다.'], 400);
}

switch ($action) {
    case 'record':
        $crud = new Crud($db);
        $crud->init(DETAIL_TABLE);
        if (!$crud->selectRecord($code)) {
            amd_json(['error' => '문의내역(' . $code . ')을 찾을 수 없습니다.'], 404);
        }
        $record = $crud->getAllValues();
        unset($record['ts']);
        amd_json(['record' => $record]);
        break;

    case 'save_record':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            amd_json(['error' => 'POST로만 저장할 수 있습니다.'], 400);
        }
        $payload = json_decode((string) file_get_contents('php://input'), true);
        $values = isset($payload['values']) && is_array($payload['values']) ? $payload['values'] : [];
        try {
            $crud = new Crud($db);
            $crud->init(DETAIL_TABLE);
            if (!$isNew && !$crud->selectRecord($code)) {
                amd_json(['error' => '문의내역(' . $code . ')을 찾을 수 없습니다.'], 404);
            }
            // t문의내역에 없는 필드는 건너뛴다(화면도 그 칸을 비활성으로 둔다).
            $schemaFields = $crud->getSchema()['fields'];
            foreach (RECORD_FIELDS as $field) {
                if (!array_key_exists($field, $values) || !isset($schemaFields[$field])) {
                    continue;
                }
                $value = $values[$field];
                if (in_array($field, NUMBER_FIELDS, true)) {
                    $value = str_replace(',', '', trim((string) $value));
                    $value = $value === '' ? null : $value;
                }
                $crud->setValue($field, $value);
            }
            if ($isNew) {
                $crud->setValue('parentcode', $parentCode);
            }
            $savedCode = $crud->save();
            amd_json(['ok' => true, 'code' => (string) ($isNew ? $savedCode : $code), 'isNew' => $isNew]);
        } catch (Throwable $e) {
            amd_json(['error' => $e->getMessage()], 500);
        }
        break;

    case 'delete_record':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            amd_json(['error' => 'POST로만 삭제할 수 있습니다.'], 400);
        }
        try {
            $n = $db->pdo()->exec('DELETE FROM ' . $q(DETAIL_TABLE) . ' WHERE ' . $q('code') . ' = ' . $db->pdo()->quote($code));
            if ($n === 0) {
                amd_json(['error' => '문의내역(' . $code . ')을 찾을 수 없습니다.'], 404);
            }
            amd_json(['ok' => true, 'code' => $code]);
        } catch (Throwable $e) {
            amd_json(['error' => $e->getMessage()], 500);
        }
        break;
}
