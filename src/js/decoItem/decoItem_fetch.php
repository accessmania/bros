<?php
declare(strict_types=1);

/**
 * [decoItem_fetch — decoItem.js 전용 조회 엔드포인트]
 *
 * [2026-09-12 개명] 이전 이름 `deco상품_fetch.php` — "한글파일명 대신
 * 영문파일명으로" 지시로 `decoItem_fetch.php`로 개명(내용/로직은 동일).
 *
 * `t상품`/`t상품분류`(접속 이름 `mssql`)를 조회해
 * JSON으로 응답한다. `ChosungGrid_fetch.php`의 `cg_source_map()`처럼
 * "이름 하나로 여러 테이블을 매핑"하는 간접 계층을 두지 않는다 — 지시(7)
 * "이건 여러 프로젝트에 재사용가능한 모듈은 아니다"에 따라, 이 화면
 * 전용으로 두 테이블을 직접 안다.
 *
 * ## ?action=...
 *   - `'분류목록'`(기본값) — t상품분류 전체를 `{code, 분류}` 배열로
 *     반환한다(바탕화면 `매출/상품.png` 좌측 "양주/맥주/음료/서비스/
 *     안주" 목록, 좌측 ag-grid에 그대로 rowData로 얹힌다), `code`
 *     오름차순.
 *   - `'상품목록'` — `?분류code=...`로 지정된 분류에 속한 상품만
 *     `{code, 상품명, 현금가, 카드가, 순서, 세트메뉴}` 배열로 반환한다.
 *     **`순서` 내림차순, 같은 순서면 `상품명` 오름차순**(지시 3 —
 *     "순서필드의 값이 클수록 위에 나오게" + 2026-09-12 추가 지시
 *     "상품정렬을 순서 desc, 상품asc로"). `분류code`가 없으면 빈 배열.
 *     [2026-09-13 추가] "세트메뉴도 [목록에] 추가되게, 대신 화면에는
 *     안나오게" — `세트메뉴`(t상품의 실제 bit 컬럼,
 *     `public/test/Board/2_9/config_상품.php` 참고)를 SELECT엔 추가했지만
 *     이 화면의 `columnDefs`(decoItem.js `_initGrid()`)엔 그 컬럼을 안
 *     넣어 그리드엔 안 보인다 — 값은 선택 결과(row)에 실려서 나갈 뿐.
 *
 * [2026-09-12 정정] `t상품분류`의 실제 컬럼명은 `code`/`분류`다(사용자가
 * 직접 확인해준 값 — 이전엔 `분류명`으로 추정했었는데 틀렸었다). `t상품`
 * 쪽 컬럼명(`분류code`/`상품명`/`현금가`/`카드가`/`순서`)은 그대로
 * 유지(지시 3 원문의 SQL과 정확히 일치).
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);

// SP.php가 DbContext.php를 대신 require해준다(deco_main_fetch.php와 동일 관례).
require_once APP_BASE_DIR . '/src/Model/SP/SP.php';

header('Content-Type: application/json; charset=utf-8');

/** t상품분류 전체를 {code, 분류} 배열로 반환한다. */
function fetch상품분류목록(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT [code], [분류] FROM [t상품분류] ORDER BY [code] ASC');
    $stmt->execute();
    return SP::sanitizeUtf8($stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * 지정된 분류에 속한 상품만 정렬해 반환한다 — 순서 내림차순, 순서가
 * 같으면 상품명 오름차순(2026-09-12 추가 지시: "상품정렬을 순서 desc,
 * 상품asc로").
 */
function fetch상품목록(PDO $pdo, string $pCategoryCode): array
{
    $stmt = $pdo->prepare('SELECT [code], [상품명], [현금가], [카드가], [순서], [세트메뉴] FROM [t상품] WHERE [분류code] = ? ORDER BY [순서] DESC, [상품명] ASC');
    $stmt->execute([$pCategoryCode]);
    return SP::sanitizeUtf8($stmt->fetchAll(PDO::FETCH_ASSOC));
}

$categoryCode = isset($_GET['분류code']) && $_GET['분류code'] !== '' ? (string) $_GET['분류code'] : null;
$action = isset($_GET['action']) && $_GET['action'] !== '' ? (string) $_GET['action'] : '분류목록';

try {
    switch ($action) {
        case '상품목록':
            $data = $categoryCode !== null
                ? fetch상품목록((new DbContext('mssql'))->pdo(), $categoryCode)
                : [];
            break;
        case '분류목록':
        default:
            $data = fetch상품분류목록((new DbContext('mssql'))->pdo());
            break;
    }
    echo json_encode($data);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
