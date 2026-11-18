<?php
declare(strict_types=1);

/**
 * ECount — 이카운트(ECount) ERP Open API로 품목 재고를 조회한다. Access 폼 모듈(fAPI)의 웹판.
 * 저장소(2026-11-13):
 *   - 접속 정보(아이디, 회사코드, Zone, APIKEY)는 MSSQL tAPI 테이블의 첫 행 — Access init()과 같다.
 *   - 로그인해서 받은 세션 ID는 PHP 세션($_SESSION['ecount_session']) — Access의 tAPI_client 대신.
 *     사용자(브라우저)마다 처음 한 번 로그인하고 그 뒤로는 재사용한다.
 *   - 전체 재고는 tAPI_재고(품목코드, 수량)에 기록한다(트랜잭션).
 *
 * 함수 (Access 대응):
 *   loadConfig()           init()                       tAPI 첫 행 읽기
 *   zone()                 Command0_Click               회사코드의 Zone 조회
 *   login()                Command5_Click               로그인 → 세션을 PHP 세션에 보관
 *   session()              DLookup("session", …)        보관한 세션(없으면 login)
 *   inventoryOne(품목코드)  action단품/get단품/parse단품   품목 하나의 재고
 *   inventoryAll()         action전체재고/get…/parse…   전체 재고 → tAPI_재고 (트랜잭션)
 *   getLog()               —                            요청·응답 기록(시험 화면용, 인증키·세션은 가림)
 *
 * Access와 다른 점:
 *   - 실패는 이유를 담은 RuntimeException(HTTP 상태, 이카운트 Status/Error). On Error Resume Next처럼 묻지 않는다.
 *   - 세션으로 부른 API가 실패하면 한 번 다시 로그인하고 다시 부른다(세션 만료 대비).
 *   - 서버 주소의 Zone은 tAPI.Zone으로 만든다(주소에 BC를 박지 않음). 시험/실제 서버는 TEST_SERVER로 고른다.
 *   - 요청 JSON은 json_encode로 만든다(값에 따옴표가 섞여도 안전).
 *   - 인증키는 코드에 적지 않는다(tAPI.APIKEY). 인증키·세션은 서버에만 있고 화면에는 결과만 간다.
 *
 * 사용법·원리는 같은 폴더의 ECount.md, 시험 화면은 ECount_entry.php.
 * 불러오기: include_custom('DbContext', 'ECount'). PHP curl 확장이 필요하다.
 */
class ECount
{
    /**
     * true = 시험 서버(sboapi…), false = 실제 서버(oapi…).
     * [2026-11-13] tAPI.APIKEY가 실서버용 인증키라 실제 서버로 바꿨다(시험 서버에서는 "세션을 받지 못했습니다"로 로그인 실패).
     * 실제 서버라 조회는 실제 재고다 — 이 부품은 조회만 하고 이카운트에 쓰지 않는다.
     */
    const TEST_SERVER = false;

    /** API 경로 앞부분. */
    const API_PATH = '/OAPI/V2/';

    /** 응답을 기다리는 시간(초). */
    const TIMEOUT = 30;

    /** 기록(getLog)에 남길 응답 글자 수. */
    const LOG_BODY_LIMIT = 3000;

    /** 세션 ID를 보관하는 PHP 세션 키. */
    const SESSION_KEY = 'ecount_session';

    /** 접속 정보 테이블(MSSQL) — 첫 행의 아이디, 회사코드, Zone, APIKEY. */
    const CONFIG_TABLE = 'tAPI';

    /** 전체 재고를 기록하는 테이블(MSSQL) — 품목코드, 수량. inventoryAll()이 지우고 다시 넣는다. */
    const STOCK_TABLE = 'tAPI_재고';

    /** @var DbContext 접속 정보(tAPI) 읽기, 전체 재고(tAPI_재고) 기록에 쓴다. */
    private $db;

    /** @var array|null 접속 정보 — loadConfig()가 채운다. */
    private $config = null;

    /** @var array 요청·응답 기록. */
    private $log = [];

    public function __construct(DbContext $pdb)
    {
        $this->db = $pdb;
    }

    /** 요청·응답 기록 — [{url, request, httpStatus, response}] (인증키·세션은 가림). */
    public function getLog(): array
    {
        return $this->log;
    }

    // ==================================================================
    // 설정 / 세션
    // ==================================================================

    /**
     * tAPI의 첫 행 — 아이디, 회사코드, Zone, APIKEY (Access init()과 같다).
     * 테이블을 못 읽거나, 행이 없거나, 값이 비었으면 이유를 담은 오류.
     * @return array{아이디: string, 회사코드: string, Zone: string, APIKEY: string}
     */
    public function loadConfig(): array
    {
        $dialect = $this->db->dialect();
        $names = ['아이디', '회사코드', 'Zone', 'APIKEY'];
        $columns = [];
        $result = [];
        $sql = '';
        $stmt = null;
        $row = false;
        $value = '';
        $i = 0;

        if ($this->config !== null) {
            return $this->config;
        }

        for ($i = 0; $i < count($names); $i++) {
            $columns[] = $dialect->quoteIdentifier($names[$i]);
        }
        $sql = 'SELECT ' . implode(', ', $columns) . ' FROM ' . $dialect->quoteIdentifier(self::CONFIG_TABLE);
        try {
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
        } catch (Throwable $e) {
            throw new RuntimeException('ECount: ' . self::CONFIG_TABLE . ' 읽기 실패 — ' . $e->getMessage(), 0, $e);
        }
        if ($row === false) {
            throw new RuntimeException('ECount: ' . self::CONFIG_TABLE . '에 접속 정보(행)가 없습니다.');
        }
        for ($i = 0; $i < count($names); $i++) {
            $value = trim((string) $row[$names[$i]]);
            if ($value === '') {
                throw new RuntimeException('ECount: ' . self::CONFIG_TABLE . '의 ' . $names[$i] . '이(가) 비어 있습니다.');
            }
            $result[$names[$i]] = $value;
        }
        $this->config = $result;
        return $this->config;
    }

    /** 회사코드의 Zone 조회(Access Command0_Click). 로그인 없이 부른다. */
    public function zone(): string
    {
        $config = $this->loadConfig();
        $json = $this->post($this->host(false) . 'Zone', ['COM_CODE' => $config['회사코드']]);

        if (!isset($json['Data']['ZONE']) || (string) $json['Data']['ZONE'] === '') {
            throw new RuntimeException('ECount Zone 조회: 응답에 ZONE이 없습니다.');
        }
        return (string) $json['Data']['ZONE'];
    }

    /** 로그인(Access Command5_Click) — 세션 ID를 받아 PHP 세션에 보관하고 돌려준다. */
    public function login(): string
    {
        $config = $this->loadConfig();
        $json = $this->post($this->host(true) . 'OAPILogin', [
            'COM_CODE' => $config['회사코드'],
            'USER_ID' => $config['아이디'],
            'API_CERT_KEY' => $config['APIKEY'],
            'ZONE' => $config['Zone'],
            'LAN_TYPE' => 'ko-KR',
        ]);
        $sessionId = '';

        if (isset($json['Data']['Datas']['SESSION_ID'])) {
            $sessionId = trim((string) $json['Data']['Datas']['SESSION_ID']);
        }
        if ($sessionId === '') {
            throw new RuntimeException('ECount 로그인 실패: 세션을 받지 못했습니다. ' . $this->errorText($json));
        }
        self::startPhpSession();
        $_SESSION[self::SESSION_KEY] = $sessionId;
        return $sessionId;
    }

    /** 지금 쓸 세션 — PHP 세션에 보관한 것, 없으면 login(). */
    public function session(): string
    {
        self::startPhpSession();
        if (isset($_SESSION[self::SESSION_KEY]) && (string) $_SESSION[self::SESSION_KEY] !== '') {
            return (string) $_SESSION[self::SESSION_KEY];
        }
        return $this->login();
    }

    /** PHP 세션이 아직 시작되지 않았으면 시작한다(보통은 Login::isAlive()가 이미 시작해 둔다). */
    private static function startPhpSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    // ==================================================================
    // 재고
    // ==================================================================

    /**
     * 품목 하나의 재고(Access action단품) — ['품목코드' => …, '수량' => …], 없으면 null.
     * 기준일은 오늘. 0재고·삭제 품목도 포함(Access와 같은 Y 플래그).
     * SAFE_FLAG만 N — [2026-11-18] Y로는 재고가 있는 FLT012A0Z(전체재고에는 32개)가 Status 200, Result []로 왔다.
     *   Y가 "안전재고 미달 품목만"으로 거르는 것으로 보고 N(거르지 않음)으로 바꿨다(전체재고 inventoryAll과 같은 값).
     * @return array{품목코드: string, 수량: string}|null
     */
    public function inventoryOne(string $pproductCode): ?array
    {
        $productCode = trim($pproductCode);
        $json = [];

        if ($productCode === '') {
            throw new RuntimeException('ECount 단품 재고: 품목코드가 비어 있습니다.');
        }
        $json = $this->callWithSession('InventoryBalance/ViewInventoryBalanceStatus', [
            'BASE_DATE' => date('Ymd'),
            'PROD_CD' => $productCode,
            'ZERO_FLAG' => 'Y',
            'BAL_FLAG' => 'Y',
            'DEL_GUBUN' => 'Y',
            'SAFE_FLAG' => 'N', // Y면 안전재고 미달 품목만 오는 것으로 보인다(위 주석)
        ]);

        if (!isset($json['Data']['Result']) || !is_array($json['Data']['Result'])) {
            throw new RuntimeException('ECount 단품 재고: 응답에 Data.Result가 없습니다.');
        }
        if (count($json['Data']['Result']) === 0) {
            return null;
        }
        return [
            '품목코드' => (string) $json['Data']['Result'][0]['PROD_CD'],
            '수량' => (string) $json['Data']['Result'][0]['BAL_QTY'],
        ];
    }

    /**
     * 전체 재고(Access action전체재고) — 이카운트에서 받아 tAPI_재고(품목코드, 수량)를 바꾸고, 넣은 행 수를 돌려준다.
     * 기준일은 오늘. 0재고·삭제 품목은 빼고 받는다(Access와 같은 N 플래그).
     * 지우기와 넣기는 한 트랜잭션 — 받기나 넣기가 중간에 실패하면 되돌려 예전 재고가 그대로 남는다
     * (Access는 지운 뒤 실패하면 비거나 일부만 남았다).
     */
    public function inventoryAll(): int
    {
        $json = $this->callWithSession('InventoryBalance/GetListInventoryBalanceStatus', [
            'BASE_DATE' => date('Ymd'),
            'ZERO_FLAG' => 'N',
            'BAL_FLAG' => 'N',
            'DEL_GUBUN' => 'N',
            'SAFE_FLAG' => 'N',
        ]);
        $rows = [];
        $pdo = $this->db->pdo();
        $dialect = $this->db->dialect();
        $table = $dialect->quoteIdentifier(self::STOCK_TABLE);
        $insert = null;
        $i = 0;

        if (!isset($json['Data']['Result']) || !is_array($json['Data']['Result'])) {
            throw new RuntimeException('ECount 전체 재고: 응답에 Data.Result가 없습니다.');
        }
        $rows = $json['Data']['Result'];

        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM ' . $table);
            $insert = $pdo->prepare('INSERT INTO ' . $table . ' (' . $dialect->quoteIdentifier('품목코드') . ', '
                . $dialect->quoteIdentifier('수량') . ') VALUES (?, ?)');
            for ($i = 0; $i < count($rows); $i++) {
                $insert->execute([(string) $rows[$i]['PROD_CD'], self::toIntQty($rows[$i]['BAL_QTY'], (string) $rows[$i]['PROD_CD'])]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException('ECount 전체 재고 저장 실패(' . self::STOCK_TABLE . '는 예전 재고 그대로): '
                . ($i + 1) . '번째 행 — ' . $e->getMessage(), 0, $e);
        }
        return count($rows);
    }

    /**
     * 이카운트 수량('810.0000000000') → 정수 810. tAPI_재고.수량이 int라서 글자 그대로는 MSSQL이 못 바꾼다(변환 오류).
     * 소수 부분이 실제로 있으면(예: 2.5) 오류 — 잘라 넣으면 재고가 조용히 틀어지므로.
     * 그런 품목이 생기면 tAPI_재고.수량을 decimal로 바꾸고 이 함수를 고친다.
     */
    private static function toIntQty($pvalue, string $pproductCode): int
    {
        $text = str_replace(',', '', trim((string) $pvalue));
        $number = 0.0;

        if ($text === '') {
            return 0;
        }
        if (!is_numeric($text)) {
            throw new RuntimeException('품목 ' . $pproductCode . '의 수량이 숫자가 아닙니다: "' . $text . '"');
        }
        $number = (float) $text;
        if (floor($number) !== $number) {
            throw new RuntimeException('품목 ' . $pproductCode . '의 수량에 소수가 있어(' . $text . ') 정수 열(' . self::STOCK_TABLE
                . '.수량)에 넣을 수 없습니다 — 수량 열을 decimal로 바꿔야 합니다.');
        }
        return (int) $number;
    }

    // ==================================================================
    // HTTP
    // ==================================================================

    /**
     * 세션이 필요한 API — SESSION_ID를 주소와 본문에 붙여 부른다.
     * 실패하면(세션 만료 등) 한 번 다시 로그인하고 다시 부른다. 두 번째도 실패하면 그 이유로 오류.
     */
    private function callWithSession(string $ppath, array $pbody): array
    {
        $body = $pbody;
        $sessionId = $this->session();

        $firstError = '';

        try {
            $body['SESSION_ID'] = $sessionId;
            return $this->post($this->host(true) . $ppath . '?SESSION_ID=' . rawurlencode($sessionId), $body);
        } catch (RuntimeException $e) {
            // 세션 만료일 수 있다 — 새로 로그인해서 한 번만 다시. 첫 실패 이유는 두 번째도 실패하면 함께 알린다.
            $firstError = $e->getMessage();
        }

        try {
            $sessionId = $this->login();
            $body['SESSION_ID'] = $sessionId;
            return $this->post($this->host(true) . $ppath . '?SESSION_ID=' . rawurlencode($sessionId), $body);
        } catch (RuntimeException $e) {
            throw new RuntimeException($e->getMessage() . ' (다시 로그인하기 전 첫 시도: ' . $firstError . ')', 0, $e);
        }
    }

    /**
     * 서버 주소 — https://(sboapi|oapi)[Zone].ecount.com/OAPI/V2/
     * pwithZone = false는 Zone 조회용(Zone을 모를 때).
     */
    private function host(bool $pwithZone): string
    {
        $prefix = 'oapi';
        $zone = '';

        if (self::TEST_SERVER) {
            $prefix = 'sboapi';
        }
        if ($pwithZone) {
            $zone = $this->loadConfig()['Zone'];
        }
        return 'https://' . $prefix . $zone . '.ecount.com' . self::API_PATH;
    }

    /**
     * POST(JSON) — 응답 JSON을 배열로. HTTP 상태가 200이 아니거나, JSON이 아니거나,
     * 이카운트 Status가 "200"이 아니면 이유를 담아 오류. 요청·응답은 getLog()에 남긴다.
     */
    private function post(string $purl, array $pbody): array
    {
        $payload = json_encode($pbody, JSON_UNESCAPED_UNICODE);
        $curl = null;
        $response = '';
        $httpStatus = 0;
        $curlError = '';
        $json = null;

        if (!function_exists('curl_init')) {
            throw new RuntimeException('ECount: PHP curl 확장이 없습니다(php.ini에서 extension=curl).');
        }

        $curl = curl_init($purl);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, self::TIMEOUT);
        $response = curl_exec($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($response === false) {
            $curlError = curl_error($curl);
            $response = '';
        }
        // curl_close()는 쓰지 않는다 — PHP 8.0부터 아무 일도 안 하고 8.5에서 deprecated다.
        // 핸들은 변수를 비우면 바로 풀린다.
        unset($curl);

        $this->log[] = [
            'url' => $this->mask($purl),
            'request' => $this->mask((string) $payload),
            'httpStatus' => $httpStatus,
            'response' => mb_substr($this->mask((string) $response), 0, self::LOG_BODY_LIMIT, 'UTF-8'),
        ];

        if ($curlError !== '') {
            throw new RuntimeException('ECount 통신 실패: ' . $curlError);
        }
        if ($httpStatus !== 200) {
            throw new RuntimeException('ECount 응답 오류: HTTP ' . $httpStatus);
        }
        $json = json_decode((string) $response, true);
        if (!is_array($json)) {
            throw new RuntimeException('ECount 응답을 읽지 못했습니다(JSON 아님).');
        }
        if (isset($json['Status']) && (string) $json['Status'] !== '200') {
            throw new RuntimeException('ECount 오류(Status ' . $json['Status'] . '): ' . $this->errorText($json));
        }
        return $json;
    }

    /** 응답의 오류 설명 — Error.Message, Errors[].Message, Data.Message 중 있는 것. */
    private function errorText(array $pjson): string
    {
        $parts = [];
        $i = 0;

        if (isset($pjson['Error']['Message']) && (string) $pjson['Error']['Message'] !== '') {
            $parts[] = (string) $pjson['Error']['Message'];
        }
        if (isset($pjson['Errors']) && is_array($pjson['Errors'])) {
            for ($i = 0; $i < count($pjson['Errors']); $i++) {
                if (isset($pjson['Errors'][$i]['Message'])) {
                    $parts[] = (string) $pjson['Errors'][$i]['Message'];
                }
            }
        }
        if (isset($pjson['Data']['Message']) && (string) $pjson['Data']['Message'] !== '') {
            $parts[] = (string) $pjson['Data']['Message'];
        }
        if (count($parts) === 0) {
            return '(이유 없음)';
        }
        return implode(' / ', $parts);
    }

    /** 기록용 — 인증키와 세션 ID를 가린다(앞 4글자만 남김). */
    private function mask(string $ptext): string
    {
        $text = $ptext;

        if ($this->config !== null && strlen($this->config['APIKEY']) > 4) {
            $text = str_replace($this->config['APIKEY'], substr($this->config['APIKEY'], 0, 4) . '****', $text);
        }
        // 세션 ID(본문의 "SESSION_ID":"…", 주소의 ?SESSION_ID=…)를 가린다.
        $text = (string) preg_replace('/("SESSION_ID"\s*:\s*")([^"]{4})[^"]*"/', '$1$2****"', $text);
        return (string) preg_replace('/(SESSION_ID=)([^&]{4})[^&]*/', '$1$2****', $text);
    }
}
