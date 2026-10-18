<?php
declare(strict_types=1);

require_once __DIR__ . '/View.php';
require_once __DIR__ . '/../Model/Board/Board.php';
require_once __DIR__ . '/../Model/Board/BoardConfig.php';

/**
 * 모델이 Board(목록+페이저)인 View. 예전 BoardControllerSearch와 책임이 동일하다.
 *
 * $board는 이미 init()까지 끝난 상태로 전달받는다 — 이 클래스는 그걸 그대로
 * 들고 있다가 템플릿에 넘겨줄 뿐, Board 초기화/조회 로직을 갖지 않는다.
 */
class ViewBoard extends View
{
    /** @var Board */
    private $board;

    /** @var BoardConfig */
    private $config;

    /** @var string view로 넘길 "return" 문자열(q/page/pageSize/groupSize/sort/dir 화이트리스트 압축). */
    private $returnQuery;

    public function __construct(Board $board, BoardConfig $config, string $returnQuery, string $templateFile)
    {
        $this->board = $board;
        $this->config = $config;
        $this->returnQuery = $returnQuery;
        $this->templateFile = $templateFile;
    }

    public function getBoard(): Board
    {
        return $this->board;
    }

    public function getConfig(): BoardConfig
    {
        return $this->config;
    }

    public function getReturnQuery(): string
    {
        return $this->returnQuery;
    }

    /** view로 이동하는 링크를 만들 때 필요한 프라이머리 키 필드명. */
    public function getPrimaryKey(): ?string
    {
        return $this->board->getSchema()['primaryKey'];
    }
}
