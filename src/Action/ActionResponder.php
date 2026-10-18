<?php
declare(strict_types=1);

require_once __DIR__ . '/../Model/Board/BoardConfig.php';

/**
 * ActionCrud(그리고 앞으로 생길 다른 Action 계열)가 처리 결과를 "어떻게
 * 알릴지"만 담당하는 전략 인터페이스. Action은 "무엇을 했는지"(저장/삭제/
 * 신규 성공·실패)만 판단하고, 그 결과를 화면에 어떻게 돌려줄지는 이 객체에
 * 위임한다 — 전체 페이지 리다이렉트(RedirectActionResponder, 기본값)와
 * 팝업/AJAX용 JSON 응답(JsonActionResponder)이 같은 Action 로직을 공유하면서
 * 서로 다른 방식으로 응답할 수 있게 하기 위함.
 *
 * 세 메서드 모두 응답을 직접 출력/전송하고 끝난다(각 구현체 책임 —
 * 리다이렉트든 JSON 출력이든 스스로 종료까지 처리).
 */
interface ActionResponder
{
    /** "신규" 버튼: DB에 아무것도 안 쓴 상태에서 어떻게 알릴지. */
    public function respondNew(BoardConfig $config, string $returnRaw): void;

    /** 저장/삭제 성공을 어떻게 알릴지. */
    public function respondSuccess(BoardConfig $config, string $returnRaw): void;

    /** 저장/삭제 실패(예외 메시지 포함)를 어떻게 알릴지. */
    public function respondFailure(BoardConfig $config, string $returnRaw, string $codeForRedirect, string $message): void;
}
