<?php
declare(strict_types=1);

require_once __DIR__ . '/View.php';

/**
 * 모델(Crud/Board) 없이 templateFile만으로 그리는 화면. DB 조회가 전혀 필요
 * 없는 정적 안내 페이지 등에 쓴다. 임의의 값을 담아 템플릿에 넘길 수 있도록
 * $values만 받는다(모델은 없지만 "화면에 찍을 값"까지 없는 건 아니라서).
 */
class ViewEmpty extends View
{
    /** @var array<string, mixed> */
    private $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values, string $templateFile)
    {
        $this->values = $values;
        $this->templateFile = $templateFile;
    }

    /** @return array<string, mixed> */
    public function getValues(): array
    {
        return $this->values;
    }
}
