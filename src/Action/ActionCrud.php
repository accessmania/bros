<?php
declare(strict_types=1);

require_once __DIR__ . '/Action.php';
require_once __DIR__ . '/../Model/Crud/Crud.php';
require_once __DIR__ . '/../Model/Board/Board.php';
require_once __DIR__ . '/../Model/Board/BoardConfig.php';
require_once __DIR__ . '/ActionResponder.php';
require_once __DIR__ . '/RedirectActionResponder.php';

/**
 * 모델이 Crud(단일 레코드)인 Action. 예전 BoardControllerWrite와 책임이 동일 —
 * $_POST의 action(save/delete/new)에 따라 저장/삭제/신규을 판단한다.
 *
 * 판단 결과를 화면에 "어떻게" 알릴지는 $responder(ActionResponder)에
 * 위임한다 — 생성자에서 안 넘기면 RedirectActionResponder(지금까지의
 * header 리다이렉트 동작)가 기본값이라, 기존 화면(1_1/1_2/1_3/2_1)은 이
 * 파라미터가 생긴 뒤에도 동작이 전혀 안 바뀐다. 팝업/AJAX 화면처럼 다르게
 * 응답해야 하면 JsonActionResponder 등 다른 구현체를 생성자에 넘기면 된다.
 */
class ActionCrud extends Action
{
    /** @var Crud */
    private $crud;

    /** @var ActionResponder */
    private $responder;

    public function __construct(Crud $crud, BoardConfig $config, ?ActionResponder $responder = null)
    {
        parent::__construct($config);
        $this->crud = $crud;
        $this->responder = $responder ?? new RedirectActionResponder();
    }

    public function handle(): void
    {
        $action = isset($_POST['action']) ? $_POST['action'] : 'save';
        $returnRaw = isset($_POST['return']) ? (string) $_POST['return'] : '';
        $codeForRedirect = isset($_POST[$this->config->primaryKey]) ? (string) $_POST[$this->config->primaryKey] : '';

        // "신규": DB에 아무것도 쓰지 않는다.
        if ($action === 'new') {
            $this->responder->respondNew($this->config, $returnRaw);
            return;
        }

        try {
            if ($action === 'delete') {
                $key = trim($codeForRedirect);
                if ($key === '') {
                    throw new InvalidArgumentException('삭제할 레코드의 ' . $this->config->primaryKey . ' 값이 없습니다.');
                }
                $this->crud->deleteRecord($key);
            } else {
                $this->crud->loadPost();
                $this->crud->save();
            }

            $this->responder->respondSuccess($this->config, $returnRaw);
        } catch (Throwable $e) {
            $this->responder->respondFailure($this->config, $returnRaw, $codeForRedirect, $e->getMessage());
        }
    }
}
