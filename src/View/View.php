<?php
declare(strict_types=1);

/**
 * 화면(템플릿)을 렌더링하는 모든 클래스의 최상위 클래스.
 *
 * "무슨 모델을 다루는가"는 전혀 모르고, "templateFile을 실행해서 문자열로
 * 만든다"는 계약 하나만 가진다 — 모델과 무관한 화면(ViewEmpty)도 이 계약만
 * 있으면 충분하고, ViewCrud/ViewBoard는 여기에 모델별 데이터 준비 로직만
 * 얹는다(공통은 위로, 다른 건 아래로).
 */
abstract class View
{
    /** @var string */
    protected $templateFile;

    /**
     * templateFile을 실행해 화면 HTML을 문자열로 만들어 반환한다. require가 이
     * 메서드(=이 인스턴스) 안에서 호출되므로, templateFile 안에서는 $this로 이
     * 인스턴스의 get*() 메서드를 그대로 쓸 수 있다.
     */
    public function render(): string
    {
        ob_start();
        require $this->templateFile;
        return ob_get_clean();
    }
}
