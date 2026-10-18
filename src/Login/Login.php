<?php

declare(strict_types=1);

require_once __DIR__ . '/../Model/Crud/Crud.php';
require_once __DIR__ . '/../db/DbContext.php';
require_once __DIR__ . '/../Auth/Auth.php';
require_once __DIR__ . '/../Str/Str.php';

/**
 * tLogin 테이블 기반 로그인/세션 관리.
 *
 * 전부 static 메서드로 구성한다 — "현재 로그인한 사용자"는 한 요청 안에
 * 정확히 하나뿐인 개념이라 인스턴스를 여러 개 만들 이유가 없다
 * (src/Domain/Domain.php가 같은 이유로 전부 static인 것과 동일). 다만 static
 * 프로퍼티 자체는 요청(프로세스)이 끝나면 사라진다 — 로그인 상태를 여러
 * 요청에 걸쳐 유지하는 실제 저장소는 $_SESSION이고, 이 클래스의 static
 * 메서드는 그 세션을 읽고 쓰는 창구일 뿐이다.
 *
 * tLogin 필드는 이름 패턴으로 추정하지 않고 FIELDS로 고정한다(2026-08-26
 * 확정 — 이전에는 실제 컬럼명을 몰라 정규식으로 추정했으나, 실제 구성이
 * 확정되어 더 이상 추정할 필요가 없어졌다).
 */
class Login
{
    /** 실제 tLogin 컬럼 구성(2026-08-26 확정). */
    const FIELDS = [
        'code', 'id', 'pw', '부서', '직급', '이름', '유선전화', '핸드폰',
        '이메일', '메모', '권한code', '권한', '사용여부', '지분률',
    ];

    const ID_FIELD = 'id';
    const PASSWORD_FIELD = 'pw';
    const USAGE_FIELD = '사용여부';
    const AUTHORITY_CODE_FIELD = '권한code';

    const SESSION_KEY = 'login';
    const TABLE_NAME = 'tLogin';

    /**
     * isAlive()가 죽어있다고 판단했을 때 이동시킬 "로그인 전 화면" —
     * 이 동작 자체를 Login 클래스 안에 정의해둠으로써, 호출부(각 화면
     * 첫머리)는 "로그인이 안 됐으면 어디로 가는지"를 몰라도 되고, 나중에
     * 로그인 화면 경로가 바뀌어도 이 상수만 고치면 된다. `login/` 폴더
     * 자체의 위치는 `config/Path.php`의 `LOGIN_BASE_URL`이 정한다 —
     * 이 상수는 그 밑의 파일명(`login.php`)만 붙인다.
     */
    const LOGIN_SCREEN_URL = LOGIN_BASE_URL . '/login.php';

    /** "계정저장" 관련 쿠키/세션의 공통 수명(초 단위, 30일). */
    const REMEMBER_ME_LIFETIME_SECONDS = 60 * 60 * 24 * 30;

    /** id를 담아두는 쿠키 이름(로그인 성공 시 항상 갱신). */
    const REMEMBER_ID_COOKIE = 'wave_remember_id';

    /** "계정저장" 체크 시에만 비밀번호를 담아두는 쿠키 이름. */
    const REMEMBER_PW_COOKIE = 'wave_remember_pw';

    /** @var string authentication() 마지막 실패 사유(성공 시 빈 문자열). */
    private static $lastError = '';

    /** @var array<string, mixed>|null authentication() 성공 시 찾은 레코드(afterLogin() 전달용). */
    private static $pendingRecord = null;

    /** @var DbContext|null init()으로 주입받은 DB 접속(src/db/DbContext.php). */
    private static $db = null;

    /**
     * 0. init(DbContext) — tLogin/tAuth를 조회할 DB 접속을 외부(페이지
     * 상단)에서 주입한다. 예전엔 CONNECTION_NAME 상수로 고정했으나,
     * 페이지가 만든 DbContext를 받아 쓰도록 바꿨다 — Login은 접속 이름을
     * 모른다. DB를 쓰는 authentication()/afterLogin()보다 먼저 호출해야
     * 한다(세션만 읽는 getValue()/isAlive() 등은 init 없이도 동작한다).
     * DbContext는 처음 쓸 때 연결하므로 init() 자체는 연결 비용이 없다.
     */
    public static function init(DbContext $db): void
    {
        self::$db = $db;
    }

    private static function db(): DbContext
    {
        if (self::$db === null) {
            throw new RuntimeException('Login::init(DbContext)를 먼저 호출해야 합니다.');
        }
        return self::$db;
    }

    /**
     * "계정저장" 여부로 세션 쿠키 수명을 정한다. 세션이 시작되기 **전에**
     * 호출해야 한다(쿠키 수명은 session_start() 시점에 확정되므로) —
     * login_action.php가 authentication()보다 먼저 호출한다.
     *
     * 체크하면 세션 쿠키 수명을 30일로 늘려 브라우저를 꺼도 로그인이
     * 유지되게 한다. 세션ID 자체가 유출되면 그 기간 내내 위험하다는
     * 트레이드오프가 있다 — 선택자+검증자 토큰을 DB에 저장하는 정석
     * 방식이 더 안전하지만, 이 프로젝트 규모에서는 과설계라 판단해
     * 채택하지 않았다(자세한 논의는 md/2026-08-26-tlogin-로그인-Login-Auth.md 참고).
     */
    public static function applyRememberMe(bool $remember): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if ($remember) {
            session_set_cookie_params(self::REMEMBER_ME_LIFETIME_SECONDS);
        }
    }

    /**
     * 로그인 성공 시 id를 쿠키에 남겨둔다("계정저장" 체크 여부와 무관하게
     * 항상 — login_action.php가 authentication() 성공 직후에 호출).
     * 다음 방문 때 login.php가 rememberedId()로 읽어 id 입력칸에 미리
     * 채워 보여주는 용도다.
     */
    public static function rememberId(string $id): void
    {
        setcookie(self::REMEMBER_ID_COOKIE, $id, time() + self::REMEMBER_ME_LIFETIME_SECONDS, APP_BASE_URL);
    }

    /**
     * rememberId()로 저장해둔 id를 읽는다. 없으면 빈 문자열.
     */
    public static function rememberedId(): string
    {
        $id = '';
        if (isset($_COOKIE[self::REMEMBER_ID_COOKIE])) {
            $id = (string) $_COOKIE[self::REMEMBER_ID_COOKIE];
        }
        return $id;
    }

    /**
     * "계정저장" 체크 상태로 로그인에 성공했을 때만 비밀번호까지 쿠키에
     * 남겨둔다(login_action.php가 authentication() 성공 + remember 체크
     * 조건일 때만 호출) — 체크 안 하면 이 메서드 대신 forgetPassword()를
     * 불러 이전에 남아있던 값을 지운다.
     *
     * 주의: 비밀번호를 쿠키에 그대로 저장하는 것은 보안상 위험이 있다
     * (그 브라우저/컴퓨터에 접근 가능한 사람이나 코드가 평문 비밀번호를
     * 그대로 읽어갈 수 있음). httponly로 자바스크립트 접근만 막아뒀을
     * 뿐, 로컬 접근 자체를 막지는 못한다 — 실사용 전 재검토 권장(자세한
     * 논의는 md/2026-08-26-tlogin-로그인-Login-Auth.md 참고).
     */
    public static function rememberPassword(string $pw): void
    {
        setcookie(self::REMEMBER_PW_COOKIE, $pw, time() + self::REMEMBER_ME_LIFETIME_SECONDS, APP_BASE_URL, '', false, true);
    }

    /**
     * rememberPassword()로 저장해둔 비밀번호를 읽는다. 없으면 빈 문자열.
     */
    public static function rememberedPassword(): string
    {
        $pw = '';
        if (isset($_COOKIE[self::REMEMBER_PW_COOKIE])) {
            $pw = (string) $_COOKIE[self::REMEMBER_PW_COOKIE];
        }
        return $pw;
    }

    /**
     * 저장돼 있던 비밀번호 쿠키를 지운다 — "계정저장"을 체크하지 않고
     * 로그인에 성공했을 때, 이전에 남아있던 비밀번호가 계속 복원되지
     * 않도록 호출한다.
     */
    public static function forgetPassword(): void
    {
        setcookie(self::REMEMBER_PW_COOKIE, '', time() - 3600, APP_BASE_URL, '', false, true);
    }

    /**
     * 1. authentication(id, pw) — 로그인 성공 여부를 반환한다. 실패
     * 사유는 getLastError()로 확인한다: 없는 계정 / 잘못된 비밀번호 /
     * 사용정지된 계정(사용여부 필드가 꺼져 있음) 3가지를 구분한다.
     *
     * 성공해도 이 함수 자체는 세션에 아무것도 쓰지 않는다 — 실제 저장과
     * 권한(Auth) 결정은 afterLogin()의 몫이다.
     */
    public static function authentication(string $id, string $pw): bool
    {
        self::$lastError = '';
        self::$pendingRecord = null;

        $pdo = self::db()->pdo();
        $dialect = self::db()->dialect();

        $crud = new Crud(self::db());
        $crud->init(self::TABLE_NAME);

        $where = $dialect->quoteIdentifier(self::ID_FIELD) . ' = ' . $pdo->quote($id);
        $crud->selectRecords($where);

        if ($crud->recordCount() === 0) {
            self::$lastError = '존재하지 않는 계정입니다.';
            return false;
        }

        // 타이밍 공격에 안전하게 비교(hash_equals) — tLogin.pw는 평문 저장이라
        // 근본적인 위험은 남아있으나(실사용 전 password_hash() 전환 권장),
        // 최소한 비교 시간으로 값을 추측하는 것은 막는다.
        $dbPassword = (string) $crud->getValue(self::PASSWORD_FIELD);
        if (!hash_equals($dbPassword, $pw)) {
            self::$lastError = '비밀번호가 일치하지 않습니다.';
            return false;
        }

        // 사용여부는 1/'1'/true/'True'/-1(Access Yes/No) 등 여러 모양으로 오므로
        // 프로젝트 공통 변환 규칙 Str::toBool()로 판정한다(src/Str/Str.md 참고 —
        // basicdata/로그인/config_로그인.php의 체크박스 판정도 같은 규칙).
        if (!Str::toBool($crud->getValue(self::USAGE_FIELD))) {
            self::$lastError = '사용이 정지된 계정입니다.';
            return false;
        }

        self::$pendingRecord = $crud->getAllValues();
        return true;
    }

    public static function getLastError(): string
    {
        return self::$lastError;
    }

    /**
     * 6. afterLogin — 로그인 성공 이후에 할 일을 정의한다: 로그인 정보를
     * 세션에 저장하고, tLogin의 권한code로 tAuth 레코드를 찾아 Auth에도
     * 저장한 뒤, 지정된 페이지로 이동한다. authentication()이 먼저
     * true를 반환한 뒤에만 호출할 수 있다.
     */
    public static function afterLogin(string $redirectUrl): void
    {
        if (self::$pendingRecord === null) {
            throw new RuntimeException('afterLogin()은 authentication() 성공 이후에만 호출할 수 있습니다.');
        }

        self::ensureSession();
        $_SESSION[self::SESSION_KEY] = self::$pendingRecord;

        Auth::loadByCode(self::$pendingRecord[self::AUTHORITY_CODE_FIELD], self::db());

        header('Location: ' . $redirectUrl);
        exit;
    }

    /**
     * 2. getValue(필드명) — 로그인 이후 유지되는 로그인 정보를 조회한다.
     */
    public static function getValue(string $field)
    {
        self::ensureSession();
        return $_SESSION[self::SESSION_KEY][$field] ?? null;
    }

    /**
     * 4. setValue(필드명, 값) — 유지되고 있는 로그인 정보의 값을 바꾼다
     * (세션에만 반영되며 DB에는 반영되지 않는다).
     */
    public static function setValue(string $field, $value): void
    {
        self::ensureSession();
        $_SESSION[self::SESSION_KEY][$field] = $value;
    }

    /**
     * 5. isAlive — 로그인 정보가 살아있는지 검사한다. 살아있으면 true를
     * 반환해 그 아래 코드가 이어서 실행되게 하고, 죽어있으면 이 메서드
     * 안에서 곧바로 로그인 전 화면(LOGIN_SCREEN_URL)으로 이동시키고
     * 스크립트를 끝낸다(반환하지 않음) — 호출부(각 화면 첫머리)는
     * `Login::isAlive();` 한 줄만 두면 된다.
     *
     * Layout(모든 메뉴 화면의 단일 진입점)의 index.php 맨 위에 한 번만
     * 두면 그 밑의 메뉴 화면 전체를 커버한다. 다만 Layout을 거치지 않는
     * 독립 엔드포인트(각 게시판의 action.php/fetch_*.php 등)는 이
     * index.php의 보호를 못 받으므로 그 파일들엔 각자 이 메서드를 불러야
     * 한다.
     */
    public static function isAlive(): bool
    {
        self::ensureSession();

        if (!empty($_SESSION[self::SESSION_KEY])) {
            return true;
        }

        self::redirectToLoginScreen();
        return false; // redirectToLoginScreen()이 exit()하므로 실제로는 도달하지 않음.
    }

    /**
     * isAlive()가 false일 때의 동작 — 로그인 전 화면(LOGIN_SCREEN_URL)으로
     * 이동한다. 로그인 성공 후 원래 보려던 화면으로 돌아갈 수 있도록
     * 현재 요청 URI를 return 파라미터로 함께 실어 보낸다(이 프로젝트가
     * 여러 화면에서 써온 "return 릴레이" 원칙과 동일).
     */
    private static function redirectToLoginScreen(): void
    {
        $url = self::LOGIN_SCREEN_URL; // 이미 완전한 URL(LOGIN_BASE_URL 기반)이다.

        $return = '';
        if (isset($_SERVER['REQUEST_URI'])) {
            $return = (string) $_SERVER['REQUEST_URI'];
        }
        if ($return !== '') {
            $url .= '?return=' . urlencode($return);
        }

        header('Location: ' . $url);
        exit;
    }

    /**
     * 로그인/권한 세션을 모두 비운다. 스펙엔 없지만 isAlive()를 실제로
     * 확인해보려면 반드시 필요한 최소 보조 기능이라 추가했다.
     */
    public static function logout(): void
    {
        self::ensureSession();
        unset($_SESSION[self::SESSION_KEY]);
        Auth::clear();
    }

    private static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
