<?php
declare(strict_types=1);

/**
 * 상태 화면(status_entry.php)이 보여줄 상태 테이블 목록 — status_fetch.php도
 * 같은 목록을 화이트리스트로 쓴다(?t=키 로 받은 값이 여기 없으면 거부).
 * 순서는 화면 배치 순서(바탕화면 상태.png — Access "상태" 폼 재현):
 * 윗줄 문의/문의내역, 아랫줄 견적/수주/발주.
 *
 * @return array<string, array{table: string, title: string}>
 */
return [
    'inquiry'        => ['table' => 'tb상태_문의',     'title' => '문의상태'],
    'inquiry_detail' => ['table' => 'tb상태_문의내역', 'title' => '문의내역상태'],
    'quote'          => ['table' => 'tb상태_견적',     'title' => '견적상태'],
    'order'          => ['table' => 'tb상태_수주',     'title' => '수주상태'],
    'purchase'       => ['table' => 'tb상태_발주',     'title' => '발주상태'],
];
