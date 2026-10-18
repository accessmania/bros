<?php
declare(strict_types=1);

/**
 * [Calendar.js 전용 서버 엔드포인트]
 *
 * `ChosungGrid_fetch.php`와 동일한 "모듈 옆에 전용 fetch를 둔다" 관례.
 * 클라이언트는 `cmd=<이름>`만 넘기고, 그 이름으로 무엇을 조회/저장할지는
 * 이 파일이 정한다(브라우저가 테이블/컬럼을 직접 고르지 않게 하는 원칙).
 *
 * `cal_cmd_map()`에 항목을 추가해 확장한다. 응답은 항상 JSON이고, 알 수
 * 없는 cmd/잘못된 값은 400, 서버 오류는 500으로 `{error}`를 돌려준다.
 * `cmd`는 쿼리스트링/POST 본문 어느 쪽으로 와도 된다(`$_REQUEST`).
 *
 * 대상 테이블은 `tCalendar_schedule`(접속 이름 `mssql`) — PK `[code]`,
 * 날짜 `[날짜]`, 메모 `[메모]`. 하루 1행(`[날짜]`가 사실상 키)이라는
 * 전제 — 사용자 확인값. `[날짜]`는 date/datetime 컬럼이라고 보고, 비교/
 * 저장 값은 `SET DATEFORMAT`과 무관하게 해석되는 'YYYYMMDD' 형태로 넘긴다.
 *
 * ## cmd=...
 *   - `'show'`(GET) — `year=YYYY&month=M` 달의 행을
 *     `[{date: 'YYYY-MM-DD', code, memo}, ...]` 배열로 반환한다.
 *     `from`/`to`('YYYY-MM-DD', 양끝 포함)를 같이 주면 그 범위로 넓힌다
 *     (달력 화면의 앞뒤 달 칸까지 6주)
 *     (Calendar.js의 `action('show', {year, month})`가 부른다).
 *   - `'save'`(POST) — `date`, `code`(없으면 빈 값), `memo`(순수 문장).
 *     code가 있으면 그 행을 UPDATE, 없으면 그 날짜의 행을 찾아 UPDATE하고
 *     그것도 없으면 INSERT한다 → `{ok: true, code}`.
 *   - `'delete'`(POST) — `code`의 행을 DELETE한다 → `{ok: true}`.
 *
 * 메모 저장 형식: DB의 `[메모]`는 HTML(에디터 저장값)이고 달력은 순수
 * 문장을 다루므로(Calendar.js `_htmlToText()`), 저장할 때는 문장을
 * HTML 이스케이프하고 줄바꿈을 `<br>`로 바꿔 넣는다 — 다시 show하면
 * `_htmlToText()`가 그대로 줄바꿈으로 되돌린다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);

// SP.php가 DbContext.php를 대신 require해준다(decoItem_fetch.php와 동일 관례).
require_once APP_BASE_DIR . '/src/Model/SP/SP.php';

/**
 * cmd 이름 → 처리 함수 매핑표.
 * @return array<string, callable(): mixed>
 */
function cal_cmd_map(): array
{
    return [
        'show' => 'cal_show',
        'save' => 'cal_save',
        'delete' => 'cal_delete',
    ];
}

/**
 * 지정된 달의 일정 메모를 [{date, code, memo}, ...]로 반환한다(code는
 * tCalendar_schedule의 PK — 저장/삭제 때 행을 집기 위함) — 메모가
 * 비어 있는 행은 셀에 표시할 게 없으므로 뺀다.
 */
function cal_show(): array
{
    $year = isset($_GET['year']) ? (int) $_GET['year'] : 0;
    $month = isset($_GET['month']) ? (int) $_GET['month'] : 0;
    if ($year < 1900 || $year > 9999 || $month < 1 || $month > 12) {
        throw new InvalidArgumentException('year/month 값이 올바르지 않습니다.');
    }
    $from = sprintf('%04d%02d01', $year, $month);
    $to = $month === 12 ? sprintf('%04d0101', $year + 1) : sprintf('%04d%02d01', $year, $month + 1);

    // 달력 화면은 앞뒤 달 칸까지 6주를 보여주므로, from/to('YYYY-MM-DD',
    // 양끝 포함)가 오면 그 범위로 넓힌다 — 안 오면 그 달만.
    $rangeFrom = cal_parse_iso($_GET['from'] ?? null);
    $rangeTo = cal_parse_iso($_GET['to'] ?? null);
    if ($rangeFrom !== null && $rangeTo !== null && $rangeFrom <= $rangeTo) {
        $from = $rangeFrom->format('Ymd');
        $to = $rangeTo->modify('+1 day')->format('Ymd');
    }

    $pdo = (new DbContext('mssql'))->pdo();
    $stmt = $pdo->prepare(
        'SELECT CONVERT(char(10), [날짜], 23) AS [date], [code], [메모] AS [memo]'
        . ' FROM [tCalendar_schedule]'
        . ' WHERE [날짜] >= ? AND [날짜] < ? AND [메모] IS NOT NULL'
        . ' ORDER BY [날짜] ASC'
    );
    $stmt->execute([$from, $to]);
    return SP::sanitizeUtf8($stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * 메모 한 건을 저장한다 — code가 있으면 그 행, 없으면 그 날짜의 행을
 * UPDATE하고, 그 날짜에 행이 없으면 INSERT한다(하루 1행 유지).
 * @return array{ok: bool, code: mixed}
 */
function cal_save(): array
{
    cal_require_post();
    $ymd = cal_post_ymd();
    $code = isset($_POST['code']) ? trim((string) $_POST['code']) : '';
    $memo = isset($_POST['memo']) ? trim((string) $_POST['memo']) : '';
    if ($memo === '') {
        throw new InvalidArgumentException('memo가 비어 있습니다 — 지우려면 delete를 쓰세요.');
    }
    $html = cal_text_to_html($memo);

    $pdo = (new DbContext('mssql'))->pdo();

    if ($code === '') {
        $find = $pdo->prepare('SELECT TOP 1 [code] FROM [tCalendar_schedule] WHERE CONVERT(char(8), [날짜], 112) = ? ORDER BY [code] ASC');
        $find->execute([$ymd]);
        $found = $find->fetchColumn();
        if ($found !== false) {
            $code = (string) $found;
        }
    }

    if ($code !== '') {
        $stmt = $pdo->prepare('UPDATE [tCalendar_schedule] SET [메모] = ? WHERE [code] = ?');
        $stmt->execute([$html, $code]);
        return ['ok' => true, 'code' => $code];
    }

    $stmt = $pdo->prepare('INSERT INTO [tCalendar_schedule] ([날짜], [메모]) OUTPUT INSERTED.[code] VALUES (?, ?)');
    $stmt->execute([$ymd, $html]);
    return ['ok' => true, 'code' => $stmt->fetchColumn()];
}

/**
 * code의 행을 지운다.
 * @return array{ok: bool}
 */
function cal_delete(): array
{
    cal_require_post();
    $code = isset($_POST['code']) ? trim((string) $_POST['code']) : '';
    if ($code === '') {
        throw new InvalidArgumentException('code가 비어 있습니다.');
    }
    $pdo = (new DbContext('mssql'))->pdo();
    $stmt = $pdo->prepare('DELETE FROM [tCalendar_schedule] WHERE [code] = ?');
    $stmt->execute([$code]);
    return ['ok' => true];
}

function cal_require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new InvalidArgumentException('POST로만 호출할 수 있습니다.');
    }
}

/** POST의 date('YYYY-MM-DD')를 검증해 'YYYYMMDD'로 바꾼다. */
function cal_post_ymd(): string
{
    $date = isset($_POST['date']) ? (string) $_POST['date'] : '';
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        throw new InvalidArgumentException("date 값이 올바르지 않습니다: {$date}");
    }
    return $m[1] . $m[2] . $m[3];
}

/** 'YYYY-MM-DD'를 날짜로 — 형식이 틀리거나 없는 날짜면 null. */
function cal_parse_iso($value): ?DateTimeImmutable
{
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)
        || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    return new DateTimeImmutable($value);
}

/** 순수 문장을 [메모]에 넣을 HTML로 — 이스케이프 후 줄바꿈을 <br>로. */
function cal_text_to_html(string $text): string
{
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return str_replace(["\r\n", "\r", "\n"], '<br>', $escaped);
}

header('Content-Type: application/json; charset=utf-8');

$cmd = isset($_REQUEST['cmd']) ? (string) $_REQUEST['cmd'] : '';
$map = cal_cmd_map();

if (!isset($map[$cmd])) {
    http_response_code(400);
    echo json_encode(['error' => "알 수 없는 cmd입니다: {$cmd}"], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    echo json_encode($map[$cmd](), JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
