<?php
declare(strict_types=1);

/**
 * [수식 팝업의 종류(kind) → 수식 테이블] — inquiryContent_수식_entry.php와 _fetch.php가 함께 쓴다.
 * 주소의 ?kind= 값은 이 목록에 있는 것만 받는다(테이블 이름은 여기 고정 — 사용자 입력으로 만들지 않는다).
 * 새 종류가 생기면 여기에 한 줄 더하고, 문의확인(inquiryContent_entry.php)의 IC_FORMULA_KINDS에도 더한다.
 */
const FORMULA_TABLES = [
    '문의내용' => 'tb수식_문의',   // 문의내용 amd의 수식 — 테이블 이름은 tb수식_문의(문의내용 아님)
    '수주' => 'tb수식_수주',
    '발주' => 'tb수식_발주',
    '견적' => 'tb수식_견적',
];

/**
 * 종류(kind) → 그 amd 폼의 데이터 테이블. 수식 계산(inquiryContent_calcFormula_fetch.php)이 이 테이블의
 * 필드 타입으로 글자 필드를 가린다 — 글자 필드(결제통화 등)는 cdata 없이 그대로 수식에 넣는다.
 */
const FORMULA_FORM_TABLES = [
    '문의내용' => 't문의내역',
    '수주' => 't수주내역',
    '발주' => 't발주내역',
    '견적' => 't문의_견적산출',
];

/** 그리드에 보여줄 필드 — 순서대로. 모든 수식 테이블에 있어야 한다. */
const FORMULA_FIELDS = ['순서', '필드명', '수식', '필드들'];

/** ?kind= 값 — 목록에 없으면 InvalidArgumentException. */
function formula_kind(): string
{
    $kind = isset($_GET['kind']) ? trim((string) $_GET['kind']) : '';
    if (!array_key_exists($kind, FORMULA_TABLES)) {
        throw new InvalidArgumentException('알 수 없는 수식 종류입니다: ' . $kind);
    }
    return $kind;
}
