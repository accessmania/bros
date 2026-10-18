<?php
declare(strict_types=1);

require_once __DIR__ . '/ActionResponder.php';

/**
 * ActionResponder의 팝업/AJAX용 구현 — 페이지를 이동시키는 대신 JSON을
 * 출력하고 끝낸다. 실패 시에도 세션 플래시를 쓰지 않는다 — 페이지를 벗어난
 * 적이 없어(모달이 그대로 열려있는 상태) 사용자가 입력한 값이 폼에 이미
 * 남아있으므로, 복원할 값을 세션에 왕복시킬 필요가 없다. 에러 메시지는
 * 응답 본문에 바로 실어 보낸다.
 */
class JsonActionResponder implements ActionResponder
{
    public function respondNew(BoardConfig $config, string $returnRaw): void
    {
        $this->emit(['success' => true, 'action' => 'new']);
    }

    public function respondSuccess(BoardConfig $config, string $returnRaw): void
    {
        $this->emit(['success' => true, 'action' => 'save']);
    }

    public function respondFailure(BoardConfig $config, string $returnRaw, string $codeForRedirect, string $message): void
    {
        http_response_code(422);
        $this->emit(['success' => false, 'message' => $message]);
    }

    /** @param array<string, mixed> $payload */
    private function emit(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        exit;
    }
}
