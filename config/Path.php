<?php
declare(strict_types=1);

/**
 * 프로젝트 루트/URL prefix를 "wave"라는 이름을 하드코딩하지 않고 자동으로
 * 알아낸다. 이 파일은 항상 "<프로젝트 폴더>/config/Path.php"에 있다는
 * 것만 보장되면 되므로, 자기 자신의 위치(__DIR__)로부터 역산한다 —
 * 프로젝트 폴더 이름이 바뀌어도(예: 압축을 풀어 다른 서버/다른 폴더명으로
 * 배포) 이 파일은 전혀 손댈 필요가 없다.
 *
 * 이 파일을 최초로 불러오는 각 진입 파일 맨 위에도 "wave"를 하드코딩하지
 * 않는다 — `config/Path.php`가 나올 때까지 상위 폴더로 올라가며 찾는
 * 부트스트랩 루프(각 진입 파일 맨 위, 아래와 동일한 형태)를 쓴다:
 *
 *   for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
 *       if ($__bootDir === dirname($__bootDir)) {
 *           throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
 *       }
 *   }
 *   require_once $__bootDir . '/config/Path.php';
 *   unset($__bootDir);
 *
 * 이러면 프로젝트 폴더 이름이 뭐든, DOCUMENT_ROOT 구조가 어떻든 항상
 * 정확히 이 파일을 찾아낸다 — 다른 환경에 배포할 때 코드 어디에도 고칠
 * 곳이 없다(자세한 배경은 md/portable-deployment.md 참고).
 */
if (!defined('APP_BASE_DIR')) {
    // __DIR__ = .../<프로젝트 폴더>/config → 한 단계 올리면 프로젝트 폴더의
    // 실제 절대경로(이름이 뭐든 상관없음)가 나온다.
    define('APP_BASE_DIR', dirname(__DIR__));
}

if (!defined('APP_BASE_URL')) {
    // DOCUMENT_ROOT 바로 밑에 프로젝트 폴더가 있고, URL 경로가 그 폴더
    // 구조를 그대로 반영한다는 전제(지금 NAS 배포 방식) 하에, 폴더명으로
    // URL prefix를 자동 계산한다. 리버스프록시/URL 재작성처럼 URL 경로가
    // 폴더 구조와 다른 환경이면, 이 파일을 불러오기 전에
    // `define('APP_BASE_URL', '원하는값');`을 먼저 해두면 이 자동계산
    // 대신 그 값이 쓰인다(위 if로 이미 방어돼 있음).
    define('APP_BASE_URL', '/' . basename(APP_BASE_DIR));
}

if (!defined('SRC_DIR')) {
    define('SRC_DIR', APP_BASE_DIR . '/src/');
}

/**
 * [서브트리 기준점 상수] `public/test/...` 밑 세 폴더(Layout/test1, Board,
 * Login)를 가리키는 URL/파일경로를 지금까지 화면마다(27개 파일, 46곳) 각자
 * 리터럴 문자열로 조립해왔다 — 이 폴더들 중 하나라도 이름이 바뀌거나
 * 옮겨지면(예: "재사용을 위해 다른 서버에 배포하며 public/test를
 * public/main으로 정리") 그 화면들을 전부 찾아 고쳐야 했다.
 *
 * 이 상수들은 APP_BASE_URL/APP_BASE_DIR이 "프로젝트 폴더 자체의 이름"을
 * 흡수해주는 것과 같은 원리를, 그 밑의 서브트리 이름에도 한 단계 더
 * 적용한 것이다 — 화면들은 이제 `/public/test/Board/...`를 직접 쓰지
 * 않고 이 상수 + 화면 고유의 나머지 부분만 이어붙인다. 서브트리 위치가
 * 바뀌면 이 4줄만 고치면 된다.
 *
 * URL(브라우저가 이동할 주소, `href`/`action`/`fetch`)과 파일경로(서버
 * 안에서 `require`할 대상)는 값이 같아 보여도 서로 다른 상수 계열이라는
 * 점에 유의 — `BOARD_BASE_URL`은 `APP_BASE_URL` 기반, `BOARD_BASE_DIR`은
 * `APP_BASE_DIR` 기반이다(md/2026-08-26-tlogin-로그인-Login-Auth.md의
 * 경로 하드코딩 논의 참고).
 */
if (!defined('LAYOUT_BASE_URL')) {
    define('LAYOUT_BASE_URL', APP_BASE_URL . '/public/test/Layout/test1');
}
if (!defined('LAYOUT_BASE_DIR')) {
    define('LAYOUT_BASE_DIR', APP_BASE_DIR . '/public/test/Layout/test1');
}
if (!defined('BOARD_BASE_URL')) {
    define('BOARD_BASE_URL', APP_BASE_URL . '/public/test/Board');
}
if (!defined('BOARD_BASE_DIR')) {
    define('BOARD_BASE_DIR', APP_BASE_DIR . '/public/test/Board');
}
/**
 * [2026-09-23 이동] public/test/Login -> public/amania/login (소문자).
 * amania/index.php가 로그인 성공 후 이동하는 첫 화면이 되면서(login_action.php의
 * $defaultUrl) 로그인 화면군 자체도 amania 쪽 소유로 옮겼다 — 원본 public/test/Login은
 * 더 이상 존재하지 않는다. 이 상수 하나만 바꾸면 login.php/login_action.php/
 * logout.php/welcome.php, Login.php(LOGIN_SCREEN_URL), layoutBT.php(로그아웃
 * 버튼) 등 LOGIN_BASE_URL을 쓰는 모든 곳이 새 위치를 따라간다(리터럴 경로를
 * 하드코딩한 곳이 없어 이 파일 하나만 고치면 됨).
 */
if (!defined('LOGIN_BASE_URL')) {
    define('LOGIN_BASE_URL', APP_BASE_URL . '/login');
}
if (!defined('DECO_BASE_URL')) {
    define('DECO_BASE_URL', APP_BASE_URL . '/public/test/deco');
}
if (!defined('DECO_BASE_DIR')) {
    define('DECO_BASE_DIR', APP_BASE_DIR . '/public/test/deco');
}

// 로그인 성공 후 첫 화면(index.php)이 있는 곳. bros에서는 그 index.php가
// 프로젝트 루트에 있으므로 APP_BASE_URL 그대로다(APP_BASE_URL이 이미
// '/bros'라서 '/bros'를 또 붙이면 /bros/bros/index.php가 된다).
if (!defined('AMANIA_BASE_URL')) {
    define('AMANIA_BASE_URL', APP_BASE_URL);
}


/**
 * public/amania/deco/ — public/test/deco/를 amania로 복사한 사본 전용
 * 기준점. DECO_BASE_URL/DECO_BASE_DIR(원본, public/test/deco)과는 값이
 * 다른 별개 상수 — 두 폴더가 같은 파일명(deco_entry.php 등)을 쓰므로
 * 하나의 상수를 공유하면 사본이 원본 경로를 가리키는 문제가 생긴다.
 */
if (!defined('AMANIA_DECO_BASE_URL')) {
    define('AMANIA_DECO_BASE_URL', APP_BASE_URL . '/public/amania/deco');
}
if (!defined('AMANIA_DECO_BASE_DIR')) {
    define('AMANIA_DECO_BASE_DIR', APP_BASE_DIR . '/public/amania/deco');
}
/**
 * public/amania/deco/basicdata/ — Layout(top=2 "기초자료") content2_1~18.php
 * 가 쓰던 Board/2_X/{config_*.php,fetch_*.php}를 번호(1~15)로 복사해온
 * 곳의 기준점. 원본 Board/2_X/*.php는 BOARD_BASE_URL(원래 위치) 기준
 * 절대 URL(예: BOARD_BASE_URL . '/2_1/fetch_로그인.php')을 하드코딩하고
 * 있어서, 사본이 이 상수로 자기 새 위치를 가리키게 해야 한다
 * (deco_basicdata.php/각 config_*.php 참고).
 */
if (!defined('AMANIA_BASICDATA_BASE_URL')) {
    define('AMANIA_BASICDATA_BASE_URL', APP_BASE_URL . '/public/amania/deco/basicdata');
}
if (!defined('AMANIA_BASICDATA_BASE_DIR')) {
    define('AMANIA_BASICDATA_BASE_DIR', APP_BASE_DIR . '/public/amania/deco/basicdata');
}

/**
 * 클래스명을 받아 SRC_DIR 밑에서 같은 이름 파일(예: 'Board' → 'Board.php')을 찾아
 * 불러온다. 여러 개를 한 번에 넘길 수 있다: include_custom('Database', 'Board', 'Str').
 * 이미 정의된 클래스는 다시 불러오지 않는다(중복 호출 안전).
 *
 * SRC_DIR 바로 밑(Layout/Router류)이면 그 자리에서 바로 찾고,
 * 없으면 SRC_DIR 하위 폴더(Str, db, Domain, Model/Board, Model/Crud, Model/SqlDialect, View,
 * Action류)를 전부 뒤져서 이름이 같은 파일을 찾는다 — 이 함수를 호출하는
 * 쪽(public/test/ 전역의 30여 개 진입 파일)은 그 클래스가 실제로 어느 폴더
 * 안에 있는지 몰라도 된다. src/ 안의 폴더 구조가 나중에 또 바뀌어도, 이
 * 함수를 부르는 쪽 코드는 손댈 필요가 없다(config/Path.php가 자기 위치를
 * 스스로 찾는 것과 같은 "self-locating" 원칙을 클래스 파일 탐색에도 적용).
 */
function include_custom(string ...$classNames): void
{
    foreach ($classNames as $className) {
        if (!class_exists($className, false)) {
            require_once locate_class_file($className);
        }
    }
}

/**
 * SRC_DIR 밑에서 "$className.php" 파일을 찾아 절대경로를 반환한다.
 * SRC_DIR 바로 밑을 먼저 보고(가장 흔한 경우라 빠름), 없으면 하위 폴더를
 * 재귀적으로 뒤진다. 못 찾으면 예외를 던진다(조용히 실패하지 않음).
 */
function locate_class_file(string $className): string
{
    $target = $className . '.php';

    $direct = SRC_DIR . $target;
    if (is_file($direct)) {
        return $direct;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(rtrim(SRC_DIR, '/\\'), FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getFilename() === $target) {
            return $file->getPathname();
        }
    }

    throw new RuntimeException("클래스 파일을 찾을 수 없습니다: {$className} ({$target})");
}
