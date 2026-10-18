<?php

declare(strict_types=1);

require_once __DIR__ . '/../Model/Crud/Crud.php';
require_once __DIR__ . '/../db/DbContext.php';

/**
 * 로그인 시 결정되는 tAuth(권한) 레코드를 세션에 보관하고 읽고 쓰는 클래스.
 *
 * Auth 스스로는 로그인 로직을 모른다 — Login::afterLogin()이 tLogin의
 * 권한code 값으로 loadByCode()를 호출해줘야 채워진다(로그인을 하면
 * auth rec이 하나 정해진다는 규칙을 그렇게 구현). 그 이후에는
 * getValue()/setValue()로 현재 로그인한 사용자의 권한 정보를 조회/수정할
 * 수 있다.
 *
 * Login과 마찬가지로 전부 static 메서드로 구성한다 — 실제 값은
 * $_SESSION이 담당하고, 이 클래스는 그 세션을 읽고 쓰는 창구일 뿐이다.
 *
 * tAuth 필드는 이름 패턴으로 추정하지 않고 FIELDS로 고정한다(2026-08-26
 * 확정).
 */
class Auth
{
    /** 실제 tAuth 컬럼 구성(2026-08-26 확정). */
    const FIELDS = ['code', 'authname', 'authstring', 'authcodes', 'memo'];

    const SESSION_KEY = 'auth';
    const TABLE_NAME = 'tAuth';

    /**
     * authstring 안에서 개별 권한 항목이 이어붙는 구분자
     * (md/2026-08-25-2_2-권한-tauth.md에서 확정된 '@;' + join('@;') 규칙).
     */
    const AUTHSTRING_DELIMITER = '@;';

    /** 모든 메뉴를 보는 마스터 권한 항목 이름(isMaster() 참고). */
    const MASTER_ITEM = '마스터';

    /**
     * tAuth에서 $code에 해당하는 레코드를 찾아 세션에 저장한다
     * (Login::afterLogin() 전용 — 로그인 성공 시 한 번 호출됨). 일치하는
     * 레코드가 없거나 $code가 비어있으면 권한 정보를 빈 상태로 만든다.
     * $db는 Login::init()으로 주입된 DbContext를 Login이 그대로 넘겨준다
     * (tLogin과 tAuth는 같은 DB에 있으므로 같은 연결을 재사용한다).
     *
     * @param mixed $code
     */
    public static function loadByCode($code, DbContext $db): void
    {
        self::ensureSession();

        if ($code === null || $code === '') {
            $_SESSION[self::SESSION_KEY] = [];
            return;
        }

        $crud = new Crud($db);
        $crud->init(self::TABLE_NAME);

        $_SESSION[self::SESSION_KEY] = [];
        if ($crud->selectRecord($code)) {
            $_SESSION[self::SESSION_KEY] = $crud->getAllValues();
        }
    }

    /**
     * getValue(필드명) — code/authname/authstring/authcodes/memo 중
     * 하나의 값을 그대로 반환한다. "권한이 있는지 없는지" 판단은 이
     * 메서드가 아니라 hasAuthority()가 전담한다(같은 메서드가 상황에
     * 따라 원본 값과 있음/없음 판정을 섞어 반환하면 호출부가 헷갈리기
     * 쉬워서 역할을 분리했다 —
     * md/2026-08-26-tlogin-로그인-Login-Auth.md의 의견 교환 참고).
     */
    public static function getValue(string $field)
    {
        self::ensureSession();
        return $_SESSION[self::SESSION_KEY][$field] ?? null;
    }

    /**
     * 세션에 담긴 auth rec 전체(필드명 => 값). loadByCode()가 담은 그대로라
     * FIELDS에 없는 컬럼이 tAuth에 더 있으면 그것도 포함된다. 없으면 빈 배열.
     *
     * @return array<string, mixed>
     */
    public static function getAllValues(): array
    {
        self::ensureSession();
        $rec = $_SESSION[self::SESSION_KEY] ?? [];
        return is_array($rec) ? $rec : [];
    }

    /** @param mixed $value */
    public static function setValue(string $field, $value): void
    {
        self::ensureSession();
        $_SESSION[self::SESSION_KEY][$field] = $value;
    }

    /**
     * authstring 안에 $code가 항목 하나로(구분자로 양쪽이 막힌 채)
     * 포함돼 있는지로 권한유무를 판단한다.
     *
     * 단순 strpos($authstring, $code)는 권한명이 서로 포함관계일 때
     * 오탐한다 — 예: "매출"이 "매출실적" 안에 부분 문자열로 들어있어서,
     * "매출실적" 권한만 있는데도 "매출" 권한이 있다고 잘못 판정된다.
     * 이걸 막기 위해 authstring 앞뒤에 구분자(';')를 덧대고, 찾는 값도
     * 양쪽에 구분자를 붙인 ";권한명;" 형태로 검색한다 — 이러면 문자열
     * 맨 앞/맨 끝에 있는 항목(원래는 한쪽에만 구분자가 붙어있던 경우)도
     * 항상 양쪽이 막힌 형태로 비교되어 부분 문자열 오탐이 없어진다.
     */
    public static function hasAuthority(string $code): bool
    {
        $authstring = (string) self::getValue('authstring');
        if ($authstring === '') {
            return false;
        }

        $paddedAuthstring = ';' . $authstring . ';';
        $paddedCode = ';' . $code . ';';

        return strpos($paddedAuthstring, $paddedCode) !== false;
    }

    /**
     * authstring을 항목 단위로 나눈다. 항목은 AUTHSTRING_DELIMITER('@;')로
     * 구분되고, 항목 안은 ';'로 이어진 토큰들이다:
     *  - 리본 메뉴 항목: 'tabBROSPOWER;group업무;button송금;' → ['tabBROSPOWER', 'group업무', 'button송금']
     *  - 일반 권한:     '견적쓰기;'                          → ['견적쓰기']
     * 빈 토큰과 끝에 붙은 '@'는 버린다.
     *
     * @return array<int, array<int, string>>
     */
    public static function getAuthItems(): array
    {
        $items = [];
        foreach (explode(self::AUTHSTRING_DELIMITER, (string) self::getValue('authstring')) as $piece) {
            $tokens = array_values(array_filter(array_map('trim', explode(';', $piece)), function (string $token): bool {
                return $token !== '' && $token !== '@';
            }));
            if (!empty($tokens)) {
                $items[] = $tokens;
            }
        }
        return $items;
    }

    /**
     * 리본 메뉴 항목의 버튼 이름들(예: ['button기초자료', 'button업무', 'button송금', ...]).
     * 좌측 메뉴(src/menu/menu.js)의 각 항목이 가진 ribbon 값과 맞춰 메뉴
     * 표시 여부를 정한다 — index.php가 이 목록을 화면에 넘긴다.
     *
     * @return array<int, string>
     */
    public static function getRibbonButtons(): array
    {
        $buttons = [];
        foreach (self::getAuthItems() as $tokens) {
            foreach ($tokens as $token) {
                if (strpos($token, 'button') === 0 && !in_array($token, $buttons, true)) {
                    $buttons[] = $token;
                }
            }
        }
        return $buttons;
    }

    /**
     * 마스터(관리자) 권한인지 — authstring에 '마스터' 항목이 그 자체로
     * 있어야 한다(다른 항목 안의 토큰으로는 인정하지 않는다). 마스터는
     * 리본 버튼과 상관없이 모든 메뉴를 본다.
     */
    public static function isMaster(): bool
    {
        foreach (self::getAuthItems() as $tokens) {
            if ($tokens === [self::MASTER_ITEM]) {
                return true;
            }
        }
        return false;
    }

    public static function clear(): void
    {
        self::ensureSession();
        unset($_SESSION[self::SESSION_KEY]);
    }

    private static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
