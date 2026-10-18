<?php
declare(strict_types=1);

require_once __DIR__ . '/ActionResponder.php';
require_once __DIR__ . '/../Model/Board/Board.php';

/**
 * ActionResponder의 기본 구현 — 지금까지 ActionCrud::handle()이 하던 것과
 * 동일하게, 처리 결과를 header 리다이렉트(+실패 시 세션 플래시)로 알린다.
 * ActionCrud 생성자에 responder를 안 넘기면 이게 자동으로 쓰이므로, 기존
 * 화면(1_1/1_2/1_3/2_1)은 이번 변경으로 동작이 전혀 안 바뀐다.
 */
class RedirectActionResponder implements ActionResponder
{
    public function respondNew(BoardConfig $config, string $returnRaw): void
    {
        $params = [$config->paramName($config->primaryKey) => ''];
        if ($returnRaw !== '') {
            $params[$config->paramName('return')] = $returnRaw;
        }
        header('Location: ' . Board::appendQuery($config->viewUrl, $params));
        exit;
    }

    public function respondSuccess(BoardConfig $config, string $returnRaw): void
    {
        header('Location: ' . Board::makeUrl($returnRaw, $config->listUrl, null, $config->paramPrefix));
        exit;
    }

    public function respondFailure(BoardConfig $config, string $returnRaw, string $codeForRedirect, string $message): void
    {
        $_SESSION[$config->flashErrorKey] = $message;
        $_SESSION[$config->flashOldKey] = $_POST;
        $backParams = [$config->paramName($config->primaryKey) => $codeForRedirect];
        if ($returnRaw !== '') {
            $backParams[$config->paramName('return')] = $returnRaw;
        }
        header('Location: ' . Board::appendQuery($config->viewUrl, $backParams));
        exit;
    }
}
