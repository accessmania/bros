<?php
declare(strict_types=1);

/**
 * [ChosungGrid.js 전용 조회 엔드포인트 — "이름 하나로 서버가 매핑"]
 *
 * `ChosungGrid.js`는 `source`/`key`/`display`가 실제로 어느 테이블/컬럼을
 * 가리키는지 전혀 모른다 — 클라이언트는 `?source=<이름>`만 넘기고, 그
 * 이름을 실제 {테이블, key컬럼, display컬럼}으로 바꾸는 일은 이 파일이
 * 담당한다(`deco_main_fetch.php`의 `getRs($name)`, `Board`/`SP`가
 * "신뢰된 서버 코드만 SQL을 조립한다"를 지켜온 것과 같은 원칙 — 브라우저가
 * 원본 테이블명/컬럼명을 직접 골라 보내게 하지 않는다).
 *
 * `cg_source_map()`(아래)에 없는 `source`는 400으로 거절한다. 지금은
 * `'직원'`(t직원/별칭, ChosungGrid_entry.php 자기 검증 + 4_5 데모 우측),
 * `'룸'`(t룸/룸번호, 4_5 데모 좌측), `'웨이터'`/`'영업'`(2026-09-11
 * 추가 — t직원 중 웨이터=1/영업=1만, deco_amd_amd.php의 웨이터/영업
 * 선택) 네 개가 등록돼 있다 — 실제 화면(예: deco_amd_amd.php의 지명
 * 선택)에 더 쓰려면 이 배열에 항목을 추가하면 된다(각 항목이 필요로
 * 하는 정보는 연결/테이블/key컬럼/display컬럼 4개 + 정렬이 가나다순이
 * 아니면 order, 테이블 일부만 보여줘야 하면 filter).
 *
 * `Board::init()`을 거쳐 조회한다 — `Board::loadBoard()`가 CP949 콜레이션
 * 컬럼값을 자동으로 UTF-8로 정제해준다(2026-09-09, "1T(5) 선택시 웨이터가
 * 표시안됨" 버그와 같은 문제를 애초에 피함 — md/2026-09-08-deco-amd-
 * 전표화면-신설.md 참고). 응답은 항상 `[{key, display}, ...]` 형태의
 * JSON 배열 하나뿐이다 — 실제 컬럼명이 무엇이든 이 자리에서 이름을
 * 맞춰준다.
 *
 * 초성 필터(`$_GET['chosung']`, ChosungKeypad가 누적해 넘기는 문자열)는
 * `Board/4_1/fetch_직원.php`와 완전히 동일한 방식 — 한 글자씩 순회하며
 * `SqlDialect::chosungCondition($displayColumn, $position, $char)`를
 * AND로 엮는다(코드포인트 계산이 DB마다 달라 SqlDialect에 위임하는 이유도
 * 그 파일과 같음).
 *
 * 결과 건수는 이 파일 하나에서 `LIST_LIMIT`으로 안전하게 제한한다(페이징
 * UI가 없는 단순 선택 목록이라 전체를 한 번에 보여주는 게 자연스럽지만,
 * 무제한으로 끌어오면 안 되므로 상한만 둠 — 초성으로 좁히면 자연히 이
 * 상한 아래로 줄어든다).
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('DbContext', 'Board');

const LIST_LIMIT = 500;

/**
 * source 이름 → {connection, table, key, display, order?, filter?} 매핑표.
 * `order`(선택, 원본 SQL ORDER BY 조각)를 생략하면 `display ASC`(가나다순)
 * 로 정렬한다 — '직원'(별칭 가나다순)처럼 그걸로 충분한 경우가 기본,
 * '룸'처럼 표시값이 문자열 정렬로는 뜻(1T→2T→...→10T)이 안 맞는 경우만
 * 실제 순서를 담은 다른 컬럼으로 지정한다.
 * `filter`(선택, 원본 SQL WHERE 조각 — 이 배열 안에서만 정의되는 신뢰된
 * 값이라 사용자 입력과 섞이지 않는다)는 그 테이블 전체가 아니라 일부만
 * 보여줘야 할 때 쓴다(예: '웨이터'는 t직원 전체가 아니라 웨이터=1인
 * 사람만) — 생략하면 display IS NOT NULL 조건 외에 추가 제약이 없다.
 * @return array<string, array{connection: string, table: string, key: string, display: string, order?: string, filter?: string}>
 */
function cg_source_map(): array
{
    return [
        // ChosungGrid_entry.php 자기 검증용 — 4_1(초성검색)과 동일한 t직원/별칭.
        '직원' => [
            'connection' => 'mssql',
            'table' => 't직원',
            'key' => 'code',
            'display' => '별칭',
        ],
        // [2026-09-09 추가] 4_5 "룸+영업 선택" 듀얼 데모의 왼쪽(룸번호)
        // — display(룸번호)가 "1T(5)"/"10T(3)"처럼 문자열 정렬로는 순서가
        // 안 맞아(2_7/fetch_룸.php와 동일하게) '순서' 컬럼으로 정렬한다.
        '룸' => [
            'connection' => 'mssql',
            'table' => 't룸',
            'key' => 'code',
            'display' => '룸번호',
            'order' => '순서 ASC',
        ],
        // [2026-09-11 추가] deco_amd_amd.php의 "웨이터" 항목 클릭 시 여는
        // 초성그리드 — t직원 중 웨이터=1(bit, 2_5/직원 화면과 동일한
        // 역할 플래그 컬럼 — findMaechulRowsByRoomDate() 등에서 이미
        // 확인된 대로 이 프로젝트의 bit 컬럼은 =1/=0으로 비교)인 사람만.
        '웨이터' => [
            'connection' => 'mssql',
            'table' => 't직원',
            'key' => 'code',
            'display' => '별칭',
            'filter' => '[웨이터] = 1',
        ],
        // [2026-09-11 추가] deco_amd_amd.php의 "영업" 항목 클릭 시 여는
        // 초성그리드 — '웨이터'와 동일한 이유, t직원 중 영업=1인 사람만
        // (fetch영업목록()의 "(팀장)" 접미어 조합은 이 단순 픽커에는
        // 안 씀 — 이 지시는 웨이터와 "유사한 흐름"만 요구함).
        '영업' => [
            'connection' => 'mssql',
            'table' => 't직원',
            'key' => 'code',
            'display' => '별칭',
            'filter' => '[영업] = 1',
        ],
        // [2026-09-13 추가] deco_amd_tempitems.php("전표" 임시항목 화면)의
        // "아가씨추가"/"밴드추가" 버튼이 여는 초성그리드 — '웨이터'/'영업'과
        // 동일한 이유, t직원 중 아가씨=1/밴드=1인 사람만.
        '아가씨' => [
            'connection' => 'mssql',
            'table' => 't직원',
            'key' => 'code',
            'display' => '별칭',
            'filter' => '[아가씨] = 1',
        ],
        '밴드' => [
            'connection' => 'mssql',
            'table' => 't직원',
            'key' => 'code',
            'display' => '별칭',
            'filter' => '[밴드] = 1',
        ],
    ];
}

header('Content-Type: application/json; charset=utf-8');

$sourceName = isset($_GET['source']) ? (string) $_GET['source'] : '';
$map = cg_source_map();

if (!isset($map[$sourceName])) {
    http_response_code(400);
    echo json_encode(['error' => "알 수 없는 source입니다: {$sourceName}"], JSON_UNESCAPED_UNICODE);
    exit;
}

$src = $map[$sourceName];

try {
    $db = new DbContext($src['connection']);
    $dialect = $db->dialect();

    $rawChosung = isset($_GET['chosung']) ? (string) $_GET['chosung'] : '';
    $notNullCondition = $dialect->quoteIdentifier($src['display']) . ' IS NOT NULL';
    $extraCondition = $notNullCondition;
    if (!empty($src['filter'])) {
        $extraCondition .= ' AND (' . $src['filter'] . ')';
    }
    if ($rawChosung !== '') {
        $conditions = [];
        $length = mb_strlen($rawChosung, 'UTF-8');
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($rawChosung, $i, 1, 'UTF-8');
            $conditions[] = $dialect->chosungCondition($src['display'], $i + 1, $char);
        }
        $extraCondition .= ' AND ' . implode(' AND ', $conditions);
    }

    $orderBy = $src['order'] ?? ($src['display'] . ' ASC');

    $board = new Board($db);
    $board->init(
        $src['table'],
        $src['key'] . ',' . $src['display'],
        '',
        '',
        '',
        1,
        LIST_LIMIT,
        1,
        $extraCondition,
        $orderBy
    );

    $rows = [];
    foreach ($board->getRows() as $row) {
        $rows[] = [
            'key' => $row[$src['key']] ?? null,
            'display' => $row[$src['display']] ?? null,
        ];
    }

    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
