<?php
declare(strict_types=1);

/**
 * 라우트 테이블 — "라우트 이름" => "그 라우트를 처리하는 핸들러"만 담당하는
 * 최소 단위의 라우터. 이름 붙은 URL 생성이나 GET/POST 구분("verb 디스패치")은
 * 이 클래스의 책임 밖이다(md/2026-08-24-라우터-1_11-route-table.md 참고 —
 * URL 생성(url())은 한때 있었으나 실익이 작다고 판단해 제거함).
 *
 * 핸들러는 두 가지 형태를 받는다:
 *  - 문자열(파일 경로): require해서 그 출력(echo)을 문자열로 캡처해 반환한다.
 *    이 require는 (private이긴 하지만) 인스턴스 메서드 run() 안에서 실행되므로,
 *    핸들러 파일 안에서 $this로 이 Router 인스턴스에 접근할 수 있다
 *    (Layout::render()가 템플릿 파일에 $this로 자신을 노출하는 것과 동일한
 *    원리).
 *  - callable: 호출한 뒤 반환값을 문자열로 반환한다.
 *
 * 등록되지 않은 이름으로 dispatch()하면 생성자에 준 $notFoundHandler(생략
 * 시 null)를 대신 실행하고, 그것도 없으면 예외를 던진다. 이 클래스는 이름을
 * "등록된 라우트 목록에 있는지"로만 판단하므로, 화면단이 별도로 화이트리스트
 * 검증을 할 필요가 없다 — 라우트 테이블 자체가 곧 화이트리스트다(임의의
 * $_GET 값으로 임의 파일이 include될 위험이 index.php의 문자열 조립 방식과
 * 달리 구조적으로 없다).
 */
class Router
{
    /** @var array<string, callable|string> */
    private $routes = [];

    /** @var callable|string|null */
    private $notFoundHandler;

    /** @param callable|string|null $notFoundHandler */
    public function __construct($notFoundHandler = null)
    {
        $this->notFoundHandler = $notFoundHandler;
    }

    /** @param callable|string $handler */
    public function add(string $name, $handler): void
    {
        $this->routes[$name] = $handler;
    }

    public function has(string $name): bool
    {
        return isset($this->routes[$name]);
    }

    /** @return array<int, string> 등록된 라우트 이름 목록(등록 순서 유지). */
    public function names(): array
    {
        return array_keys($this->routes);
    }

    /** 이름에 해당하는 핸들러를 실행해 그 출력(HTML 등)을 문자열로 반환한다. */
    public function dispatch(string $name): string
    {
        if (isset($this->routes[$name])) {
            return $this->run($this->routes[$name]);
        }

        if ($this->notFoundHandler !== null) {
            return $this->run($this->notFoundHandler);
        }

        throw new RuntimeException("라우트를 찾을 수 없습니다: {$name}");
    }

    /**
     * @param callable|string $handler
     */
    private function run($handler): string
    {
        if (is_string($handler)) {
            if (!is_file($handler)) {
                throw new RuntimeException("라우트 핸들러 파일을 찾을 수 없습니다: {$handler}");
            }
            ob_start();
            require $handler;
            return (string) ob_get_clean();
        }

        return (string) call_user_func($handler);
    }
}
