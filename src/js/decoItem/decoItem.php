<?php
declare(strict_types=1);

/**
 * decoItem.php — "상품선택" 화면의 HTML 뼈대(좌측 분류 ag-grid 컨테이너
 * + 검색바 + 우측 상품 ag-grid 컨테이너)만 그리는 순수 마크업 조각.
 * `decoItem.js`가 `fetch()`로 이 페이지를 받아 컨테이너에 그대로
 * 꽂아 넣은 뒤, 두 ag-grid를 모두 JS에서 초기화하고 데이터(JSON)를
 * 채운다.
 *
 * [2026-09-12 — 좌측도 ag-grid로 변경] 최초엔 좌측을 이 파일이 직접
 * `t상품분류`를 조회해 `<div class="dp-category-btn">` 목록으로 구워
 * 보냈었다. 이번 지시로 "화면 좌측에도 aggrid(검색/편집 없는)를
 * 배치"하도록 바뀌면서, 좌측 데이터도 결국 ag-grid API로 얹어야 해
 * DB 조회를 이 페이지가 미리 해둘 이유가 없어졌다 — 좌측/우측 둘 다
 * `decoItem_fetch.php`의 JSON을 `decoItem.js`가 직접 불러 각 그리드에
 * 채운다(`fetch()...then(res=>res.json())`가 `res.ok`를 확인하지 않아
 * HTTP 500 실패를 조용히 삼키던 버그는 `decoItem.js` 쪽에서 이미 고쳐
 * 응답 실패가 항상 화면에 그대로 보인다). 그래서 이 파일은 이제 DB/
 * 세션 접근이 전혀 없는 순수 정적 마크업이라 `config/Path.php`
 * 부트스트랩도 필요 없다.
 *
 * `?containerId=...`(선택, 기본 `'dp-root'`) — 이 화면을 여러 개 동시에
 * 띄울 가능성에 대비해, 그 값을 그대로 자식 엘리먼트 id 접두어로 써서
 * `{containerId}-categories`(좌측 분류 그리드)/`{containerId}-search`
 * (검색바)/`{containerId}-grid`(우측 상품 그리드)/`{containerId}-keypad`
 * (2026-09-12 추가 — `ChosungKeypad` 버튼식 초성 키패드 자리) 4개 id를
 * 만든다(`decoItem.js`가 기대하는 것과 정확히 같은 규칙). 신뢰 안 된
 * 입력(쿼리스트링)이라 `htmlspecialchars()`로 이스케이프해 출력한다.
 *
 * [2026-09-12 추가] "초성검색이 우측에 표시안됨... 기존 ChosungKeypad
 * 처럼 누르는 버튼식 키패드를 기대함" — 상품명 검색이 문자/초성을
 * 다 받아주는 로직만으로는 충분치 않았다(이 화면의 실사용처가 키보드
 * 없이 터치로 조작하는 POS 단말이라, 눈에 보이는 버튼이 필요했다).
 * `dp-product-col`(검색바+그리드 세로 스택)과 `dp-keypad-col`(키패드
 * 자리)을 `dp-main-col` 아래 나란히(가로) 배치 — `ChosungGrid.js`의
 * `.cg-grid-col`+`.cg-keypad-col` 배치와 같은 형태.
 */
$containerId = isset($_GET['containerId']) && $_GET['containerId'] !== '' ? (string) $_GET['containerId'] : 'dp-root';

function decoItemEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

header('Content-Type: text/html; charset=utf-8');
?>
<div class="dp-category-col">
    <div id="<?= decoItemEscape($containerId . '-categories') ?>" class="ag-theme-alpine dp-category-grid"></div>
</div>
<div class="dp-main-col">
    <div class="dp-product-col">
        <input type="text" class="dp-search" id="<?= decoItemEscape($containerId . '-search') ?>" placeholder="상품명 검색 (초성 가능)">
        <div id="<?= decoItemEscape($containerId . '-grid') ?>" class="ag-theme-alpine dp-grid"></div>
    </div>
    <div class="dp-keypad-col" id="<?= decoItemEscape($containerId . '-keypad') ?>"></div>
</div>
