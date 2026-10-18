<?php
declare(strict_types=1);

/**
 * md/Layout.md 명세에 맞춘 레이아웃(마스터페이지) 클래스.
 *
 * 이 클래스는 화면 마크업을 직접 만들지 않는다 — 로그인정보/메뉴/컨텐츠/하단페이지를
 * "데이터"로만 들고 있다가, 생성자에서 받은 브랜딩 템플릿(BT) 파일에 그 데이터를
 * 넘겨주고 render()가 그 파일을 실행하는 역할만 한다. 실제 <html>/<div> 마크업은
 * 전부 템플릿 파일(예: layoutBT.php) 쪽 책임이다 — 외부에서 받은 브랜딩 템플릿의
 * 원본 HTML을 그대로 써야 하기 때문(Board::getDefaultXxxHTML()처럼 클래스가 문자열을
 * 조립하는 방식은 채택하지 않음).
 *
 * PHP 7 호환 문법만 사용 (union type / mixed / non-capturing catch 등 8.0 전용 문법 미사용).
 */
class Layout
{
    /** @var string 브랜딩 템플릿(BT) 파일 경로. render()가 이 파일을 require 한다. */
    private $templateFile;

    /** @var array<string, mixed>|null 로그인 사용자 정보. 주입 안 하면 null(비로그인). */
    private $login = null;

    /** @var string 화면 본문(컨텐츠) HTML. */
    private $content = '';

    /** @var string 레이아웃 하단에 놓일 페이지 HTML. */
    private $bottomPage = '';

    /**
     * 상단메뉴 목록. [{"key":"1","label":"..."}, ...] 형태.
     * @var array<int, array{key: string, label: string}>
     */
    private $topMenu = [];

    /**
     * 상단메뉴 키 => 좌측메뉴 목록. {"1": [{"key":"1","label":"..."}, ...], ...} 형태.
     * 좌측메뉴가 상단메뉴에 종속되는 구조를 그대로 반영한 모양.
     * @var array<string, array<int, array{key: string, label: string}>>
     */
    private $leftMenuByTop = [];

    /** @var string|null 현재 선택된 상단메뉴 키. */
    private $activeTopKey = null;

    /** @var string|null 현재 선택된 좌측메뉴 키. */
    private $activeLeftKey = null;

    public function __construct(string $templateFile)
    {
        $this->templateFile = $templateFile;
    }

    // ------------------------------------------------------------------
    // 로그인 정보
    // ------------------------------------------------------------------

    /**
     * 화면단(index.php 등)이 세션 등에서 조회해온 로그인 사용자 정보를 그대로 주입한다.
     * Layout은 세션/인증에 직접 접근하지 않는다 — 인증 방식이 바뀌어도 이 클래스는
     * 안 건드려도 되게 하기 위함.
     *
     * @param array<string, mixed> $loginInfo
     */
    public function setLogin(array $loginInfo): void
    {
        $this->login = $loginInfo;
    }

    /** @return array<string, mixed>|null */
    public function getLogin(): ?array
    {
        return $this->login;
    }

    // ------------------------------------------------------------------
    // 컨텐츠 (본문 placeholder)
    // ------------------------------------------------------------------

    public function setContent(string $html): void
    {
        $this->content = $html;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    // ------------------------------------------------------------------
    // 상단/좌측 메뉴
    // ------------------------------------------------------------------

    /**
     * 상단메뉴 항목(키/라벨) 목록을 주입한다. URL은 여기 안 담는다(6번 moveMenu()가
     * 키를 실제 경로로 바꿔준다) — 나중에 메뉴 데이터 출처가 json에서 DB로 바뀌어도
     * 이 메서드 시그니처(배열 모양)만 맞으면 그대로 재사용 가능하다.
     *
     * @param array<int, array{key: string, label: string}> $items
     */
    public function setTopMenu(array $items): void
    {
        $this->topMenu = $items;
    }

    /**
     * 상단메뉴 키별로 좌측메뉴 목록을 묶어서 주입한다.
     *
     * @param array<string, array<int, array{key: string, label: string}>> $itemsByTopKey
     */
    public function setLeftMenu(array $itemsByTopKey): void
    {
        $this->leftMenuByTop = $itemsByTopKey;
    }

    /** @return array<int, array{key: string, label: string}> */
    public function getTopMenu(): array
    {
        return $this->topMenu;
    }

    /**
     * 현재 활성 상단메뉴(activeTopKey)에 해당하는 좌측메뉴 목록만 반환한다.
     * 다른 상단메뉴의 좌측메뉴는 섞이지 않는다 — 템플릿은 이 결과만 순회하면 된다.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public function getLeftMenu(): array
    {
        if ($this->activeTopKey === null) {
            return [];
        }
        return isset($this->leftMenuByTop[$this->activeTopKey]) ? $this->leftMenuByTop[$this->activeTopKey] : [];
    }

    // ------------------------------------------------------------------
    // 활성 메뉴 상태
    // ------------------------------------------------------------------

    public function setActiveMenu(string $topKey, ?string $leftKey = null): void
    {
        $this->activeTopKey = $topKey;
        $this->activeLeftKey = $leftKey;
    }

    public function getActiveTopMenu(): ?string
    {
        return $this->activeTopKey;
    }

    public function getActiveLeftMenu(): ?string
    {
        return $this->activeLeftKey;
    }

    /** 템플릿이 상단메뉴 항목에 강조(active) 클래스를 붙일지 판단할 때 쓴다. */
    public function isActiveTop(string $key): bool
    {
        return $this->activeTopKey === $key;
    }

    /** 템플릿이 좌측메뉴 항목에 강조(active) 클래스를 붙일지 판단할 때 쓴다. */
    public function isActiveLeft(string $key): bool
    {
        return $this->activeLeftKey === $key;
    }

    // ------------------------------------------------------------------
    // 메뉴 이동
    // ------------------------------------------------------------------

    /**
     * 상단/좌측 메뉴 키를 조합해 그 메뉴로 이동할 URL(쿼리스트링)을 만든다.
     * 좌측 키를 생략하면 상단 키만으로 URL을 만든다(상단메뉴만 클릭한 경우 —
     * 화면단이 좌측메뉴 기본값을 알아서 정하게 함, Board::getPageUrl()과 같은 성격의
     * "순수 URL 계산" 함수).
     */
    public function moveMenu(string $topKey, ?string $leftKey = null): string
    {
        $params = ['top' => $topKey];
        if ($leftKey !== null) {
            $params['left'] = $leftKey;
        }
            
        return '?' . http_build_query($params);
    }

    // ------------------------------------------------------------------
    // 하단 페이지
    // ------------------------------------------------------------------

    /** 레이아웃 하단 자리에 놓일 단일 페이지(컨텐츠)를 주입한다. */
    public function setBottomPage(string $html): void
    {
        $this->bottomPage = $html;
    }

    public function getBottomPage(): string
    {
        return $this->bottomPage;
    }

    // ------------------------------------------------------------------
    // 렌더링
    // ------------------------------------------------------------------

    /**
     * 지금까지 주입된 데이터를 갖고 templateFile을 실행해 화면을 출력한다.
     * require가 이 메서드(=Layout 인스턴스) 안에서 호출되므로, templateFile
     * 안에서는 $this로 이 Layout 인스턴스의 get*()/isActive*()/moveMenu() 등을
     * 그대로 쓸 수 있다 — 템플릿 파일은 이 조회 전용 메서드들만 부르고, 데이터를
     * 계산하거나 세션/DB에 접근하는 로직은 두지 않는다(그건 화면단/컨트롤러 몫).
     */
    public function render(): void
    {
        require $this->templateFile;
    }
}
