<?php
declare(strict_types=1);

require_once __DIR__ . '/../Model/Board/BoardConfig.php';

/**
 * 화면을 그리지 않고(템플릿 없음) 요청을 처리한 뒤 리다이렉트만 하는
 * 클래스들의 최상위. View와 짝을 이룬다 — "View는 보여준다, Action은
 * 실행하고 결과를 리다이렉트로 알려준다."
 *
 * 지금은 자식이 ActionCrud 하나뿐이라 공통 로직은 config 보관 정도만 두고
 * 최소한으로 유지한다. 두 번째 실제 쓰기 흐름(예: ActionBulk의 일괄 처리)이
 * 생기면 그때 flash 기록/리다이렉트 조립 같은 공통 부분을 더 끌어올린다.
 */
abstract class Action
{
    /** @var BoardConfig */
    protected $config;

    public function __construct(BoardConfig $config)
    {
        $this->config = $config;
    }

    /** 요청($_POST 등)을 처리하고 리다이렉트까지 마친다(화면 출력 없음). */
    abstract public function handle(): void;
}
