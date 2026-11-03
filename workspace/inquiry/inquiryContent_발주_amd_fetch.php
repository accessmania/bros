<?php
declare(strict_types=1);

/**
 * [발주 팝업(inquiryContent_발주_amd.php)의 서버 엔드포인트]
 * t발주내역 한 건을 CrudJs 계약으로 읽고 쓴다 — 팝업 입력폼 아래 crudbuttons iframe 안의
 * CrudJs가 부른다(src/Model/Crud/crudbuttons/crudbuttons.md "xxx_fetch.php 계약").
 *
 * action(CrudJs 계약 — 응답은 전부 {success:true, ...} 또는 {success:false, error}):
 *  - schema               — 스키마 → {success, schema}.
 *  - selectRecord&pk=     — 한 건 → {success, schema, cursor:0, data:[행]}.
 *  - save (POST, JSON 행) — PK(code) 있으면 update, 없으면 insert → {success, pk}.
 *                           insert는 parentcode(t문의.code)가 꼭 있어야 하고, update는
 *                           parentcode를 바꾸지 않는다(다른 문의로 옮기지 못하게).
 *  - delete&pk= (POST)    — 이 한 건을 지운다 → {success, affected}.
 * 이 화면만 쓰는 action:
 *  - inquiryDetail&code=&parentcode= — 문의내역(t문의내역) 한 건의 제품 정보
 *                           → {success, detail:{제품종류, 품목코드, 모델명, 제품code, 제조사}}
 *                           ("검색(문의code)" 칸에 문의내역 code를 넣으면 제품 칸을 채운다).
 *                           같은 문의(parentcode)의 문의내역만 찾는다.
 *
 * 숫자 필드의 천단위 콤마는 CrudJs가 보내기 전에 뗀다. 빈 숫자는 null로 저장한다.
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
const PURCHASE_TABLE = 't발주내역';

/**
 * 팝업 입력폼이 읽고 쓰는 t발주내역 필드 + parentcode, 제품code(PK code는 자동 포함).
 * t발주내역에 없는 필드는 Crud::init()의 화이트리스트가 알아서 뺀다(화면도 그 칸을 비활성으로 둔다).
 * inquiryContent_발주_amd.php의 컨트롤 id와 같아야 한다.
 */
const RECORD_FIELDS = [
    'parentcode', '문의code',
    '제품종류', '품목코드', '모델명', '제품code', '제조사',
    '상태', '발주번호', '수량', '적용수량', '입고수량', '매입통화', '매입환율', 'KRW매입환율',
    '외화매입가', '원화매입가', '발주서납기', '선적예정일', '입고일',
    '매입총액', '외화매입총액', '할인금액', 'USD할인금액', '원화송금액', 'USD송금액',
    '송금잔액', '외화송금잔액', '송금예정일', '송금일', '송금메모',
    '비고',
];

/** 숫자 필드 — 빈 값은 null로 저장한다. */
const NUMBER_FIELDS = [
    '문의code', '제품code',
    '수량', '적용수량', '입고수량', '매입환율', 'KRW매입환율', '외화매입가', '원화매입가',
    '매입총액', '외화매입총액', '할인금액', 'USD할인금액', '원화송금액', 'USD송금액',
    '송금잔액', '외화송금잔액',
];

/** 날짜 필드 — 빈 값은 null로 저장한다. */
const DATE_FIELDS = ['발주서납기', '선적예정일', '입고일', '송금예정일', '송금일'];

/**
 * "검색(문의code)"로 가져오는 문의내역의 제품 정보 — [t문의내역 필드 => t발주내역 필드].
 * inquiryContent_발주_amd.php의 AMD_DETAIL_MAP과 같아야 한다.
 */
const DETAIL_TABLE = 't문의내역';
const DETAIL_MAP = [
    '제품종류' => '제품종류',
    '품목코드' => '품목코드',
    '모델명' => '모델명',
    '제품code' => '제품code',
    '제조사' => '제조사',
];

/** JSON으로 응답하고 끝낸다. */
function amd_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    exit;
}

/** ?pk= 값 — 숫자가 아니면 오류. */
function amd_pk(): string
{
    $pk = isset($_GET['pk']) ? trim((string) $_GET['pk']) : '';
    if ($pk === '' || !ctype_digit($pk)) {
        throw new InvalidArgumentException('발주 code(pk)가 없습니다.');
    }
    return $pk;
}

$action = isset($_GET['action']) && $_GET['action'] !== '' ? (string) $_GET['action'] : 'schema';

try {
    $db = new DbContext(null); // config/db.php의 'driver' 기본 접속

    // inquiryDetail — 발주 한 건과 무관해 Crud(t발주내역) 없이 처리한다.
    if ($action === 'inquiryDetail') {
        $detailCode = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
        $parentCode = isset($_GET['parentcode']) ? trim((string) $_GET['parentcode']) : '';
        if ($detailCode === '' || !ctype_digit($detailCode)) {
            throw new InvalidArgumentException('문의code가 올바르지 않습니다.');
        }
        $detail = new Crud($db);
        $detail->init(DETAIL_TABLE);
        if (!$detail->selectRecord($detailCode)) {
            amd_json(['success' => false, 'error' => '문의내역(' . $detailCode . ')을 찾을 수 없습니다.'], 404);
        }
        $row = $detail->getAllValues();
        // 다른 문의의 문의내역은 가져오지 않는다.
        if ($parentCode !== '' && (string) ($row['parentcode'] ?? '') !== $parentCode) {
            amd_json(['success' => false, 'error' => '문의내역(' . $detailCode . ')은 이 문의의 것이 아닙니다.'], 400);
        }
        $result = [];
        foreach (DETAIL_MAP as $source => $target) {
            if (array_key_exists($source, $row)) {
                $result[$target] = $row[$source] === null ? '' : trim((string) $row[$source]);
            }
        }
        amd_json(['success' => true, 'detail' => $result]);
    }

    $crud = new Crud($db);
    $crud->init(PURCHASE_TABLE, RECORD_FIELDS);

    switch ($action) {
        case 'schema':
            amd_json(['success' => true, 'schema' => $crud->getSchema()]);
            break;

        case 'selectRecord':
            $pk = amd_pk();
            if (!$crud->selectRecord($pk)) {
                amd_json(['success' => false, 'error' => '발주(' . $pk . ')를 찾을 수 없습니다.'], 404);
            }
            amd_json([
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
                throw new InvalidArgumentException('발주 code가 올바르지 않습니다.');
            }
            if ($pk === '') {
                // 신규 — 어느 문의(t문의.code)에 딸린 행인지 꼭 있어야 한다.
                $parentCode = isset($row['parentcode']) ? trim((string) $row['parentcode']) : '';
                if ($parentCode === '' || !ctype_digit($parentCode)) {
                    throw new InvalidArgumentException('신규 발주는 parentcode(문의 code)가 필요합니다.');
                }
                $row['parentcode'] = $parentCode;
            } else {
                // 수정 — parentcode는 건드리지 않는다(보내지 않으면 기존 값 그대로).
                unset($row['parentcode']);
            }
            foreach (array_merge(NUMBER_FIELDS, DATE_FIELDS) as $field) {
                if (array_key_exists($field, $row) && trim((string) $row[$field]) === '') {
                    $row[$field] = null;
                }
            }
            // 화이트리스트 밖 필드는 스키마에 없어 loadArray()가 건너뛴다.
            $crud->loadArray($row);
            amd_json(['success' => true, 'pk' => (string) $crud->save()]);
            break;

        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new InvalidArgumentException('POST로만 삭제할 수 있습니다.');
            }
            $pk = amd_pk();
            $affected = $crud->deleteRecord($pk);
            if ($affected === 0) {
                amd_json(['success' => false, 'error' => '발주(' . $pk . ')를 찾을 수 없습니다.'], 404);
            }
            amd_json(['success' => true, 'affected' => $affected]);
            break;

        default:
            throw new InvalidArgumentException('알 수 없는 action입니다: ' . $action);
    }
} catch (Throwable $e) {
    amd_json(['success' => false, 'error' => $e->getMessage()], 500);
}
