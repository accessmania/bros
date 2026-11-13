# ECount

이카운트(ECount) ERP Open API로 **품목 하나의 재고**를 조회하고, **전체 재고**를 `tAPI_재고`에 기록하는 부품. Access 폼 모듈(fAPI — `init`, `Command5_Click`,
`action단품` …)의 웹판. (2026-11-13)

| 파일 | 내용 |
|---|---|
| `ECount.php` | class `ECount` — 함수 본체 |
| `ECount_fetch.php` | 시험 화면의 창구(POST `?action=config\|zone\|login\|one`) |
| `ECount_entry.php` | 시험 화면 — 함수를 하나씩 눌러 결과와 요청·응답 기록을 본다 |

불러오기: `include_custom('DbContext', 'ECount')`. **PHP curl 확장**이 필요하다(`extension=curl`).

## 접속 정보 — MSSQL `tAPI` 테이블

Access `init()`과 같이 **`tAPI`의 첫 행**에서 읽는다(`ECount::CONFIG_TABLE`).

| 컬럼 | 내용 |
|---|---|
| 아이디 | API 사용자 아이디 |
| 회사코드 | 이카운트 회사코드 |
| Zone | 예: BC — 시험 화면 "Zone 조회"로 확인 |
| APIKEY | 인증키(시험 서버용 키가 따로면 시험용). **코드에 적지 않는다** |

테이블을 못 읽거나(`ECount: tAPI 읽기 실패 — …`), 행이 없거나, 값이 비었으면 이유를 담은 오류가 난다.
DB는 접속 정보 읽기(`tAPI`)와 전체 재고 기록(`tAPI_재고` — 품목코드, 수량)에 쓴다. 세션은 DB에 두지 않는다.

## 세션 — PHP 세션

로그인해서 받은 세션 ID는 `$_SESSION['ecount_session']`에 둔다(Access의 `tAPI_client` 대신).
- 사용자(브라우저)마다 처음 한 번 로그인하고 그 뒤로는 재사용한다. 값은 서버에만 있다(브라우저에는 PHP 세션 쿠키만).
- 세션으로 부른 API가 실패하면(세션 만료 등) 한 번 다시 로그인하고 다시 부른다. 두 번째도 실패하면 두 이유를 함께 알린다.
- 같은 API 아이디로 여러 사람이 로그인할 때 이카운트가 이전 세션을 끊는다면 로그인이 잦아진다 — 그때는 서버 공용 보관(파일)으로 바꾼다.

## 함수

```php
$ec = new ECount($db);                  // $db = new DbContext(null)
$ec->loadConfig();                      // tAPI 첫 행 [아이디, 회사코드, Zone, APIKEY]
$ec->zone();                            // 회사코드의 Zone (로그인 없이)
$ec->login();                           // 로그인 → 세션 ID (PHP 세션에 보관)
$ec->session();                         // 보관한 세션, 없으면 login()
$ec->inventoryOne('QAE100-110S24W');    // ['품목코드' => …, '수량' => …] 또는 null(이카운트에 없음)
$ec->inventoryAll();                    // 전체 재고 → tAPI_재고(지우고 다시 넣음, 트랜잭션), 넣은 행 수
$ec->getLog();                          // 요청·응답 기록 [{url, request, httpStatus, response}] — 인증키·세션은 가림
```

| ECount | Access | API |
|---|---|---|
| `zone()` | `Command0_Click` | `Zone` |
| `login()` | `Command5_Click` | `OAPILogin` (COM_CODE, USER_ID, API_CERT_KEY, ZONE, LAN_TYPE=ko-KR) |
| `inventoryOne()` | `action단품` / `get단품` / `parse단품` | `InventoryBalance/ViewInventoryBalanceStatus` (기준일 오늘, 플래그 Y) |
| `inventoryAll()` | `action전체재고` / `get전체재고` / `parse전체재고` | `InventoryBalance/GetListInventoryBalanceStatus` (기준일 오늘, 플래그 N) |

- **수량은 정수로 바꿔 넣는다**(`toIntQty`): 이카운트는 `'810.0000000000'`처럼 주는데 `tAPI_재고.수량`은 int라
  글자 그대로는 MSSQL 변환 오류가 난다(2026-11-13). 소수 부분이 실제로 있으면(예: 2.5) 잘라 넣지 않고 오류로 멈춘다 —
  그런 품목이 생기면 수량 열을 decimal로 바꾸고 `toIntQty`를 고친다.
- **전체 재고 기록은 트랜잭션**: `DELETE` + `INSERT`를 묶는다. 받기·넣기가 중간에 실패하면 되돌려 예전 재고가 그대로 남는다(Access는 지운 뒤 실패하면 비거나 일부만 남았다).

## 원리 / Access와 다른 점

- **서버 주소**: `https://(sboapi|oapi){Zone}.ecount.com/OAPI/V2/…` — Zone은 `tAPI.Zone`(Access는 주소에 `BC`를 박았다).
  Zone 조회만 Zone 없이 `https://(sboapi|oapi).ecount.com/OAPI/V2/Zone`.
- **시험/실제 서버**: `ECount::TEST_SERVER` — `true`면 시험 서버(`sboapi`), `false`면 실제(`oapi`).
  **지금은 실제 서버(`false`)** — `tAPI.APIKEY`가 실서버용 인증키라 시험 서버에서는 로그인이 안 됐다(2026-11-13).
  이 부품은 조회만 하므로 실제 서버여도 이카운트 데이터를 바꾸지 않는다.
- **실패는 이유를 남긴다**(Access의 `On Error Resume Next` 대신): 통신 실패, HTTP 상태 ≠ 200, JSON 아님,
  이카운트 `Status` ≠ "200"(이유는 `Error.Message` / `Errors[].Message` / `Data.Message`) → `RuntimeException`.
- **안전**: 요청 JSON은 `json_encode`(품목코드에 따옴표가 있어도 안전). 인증키·세션은 서버에만 있다.
  기록(`getLog`)과 시험 화면에는 앞 4글자만 보인다.
- `Utf8ToEuckr`는 필요 없다 — PHP는 UTF-8 응답을 그대로 읽는다.

## 시험

`src/Model/ECount/ECount_entry.php` — 로그인한 상태로 연다.
1. 설정 읽기 → 2. Zone 조회 → 3. 로그인 → 4. 단품 재고(품목코드 입력). 결과·실패 이유와 요청·응답 기록이 아래에 나온다.

## 쓰는 곳

- **amd 화면의 ECount 버튼**(문의내용·수주·발주·견적) — 폼의 품목코드로 `inventoryOne`을 불러 버튼 옆에
  빨간 글로 `제품코드 : 수량`을 보인다(수량은 소수점 없이 반올림, 세 자리 콤마. 실패는 이유를 같은 자리에).
  창구: `workspace/inquiry/inquiryContent_ECount_fetch.php`. 화면 함수: `amdEcountStock` / `amdEcountShow`.

- **문의 탭의 ECount 버튼**(`inquiryContent_문의내용_entry.php`) — 문의확인(`inquiryContent_entry.php`)의
  `action('openEcountStock')`이 **ECount (전체재고)** 팝업(비모달·이동 가능, `inquiryContent_ECount재고_entry.php`)을 띄운다.
  팝업은 열자마자 `inventoryAll`로 tAPI_재고를 갱신하고(창구 `inquiryContent_ECount재고_fetch.php?action=refresh`),
  품목코드·수량 그리드(품목코드 순, 수량은 소수점 없이 콤마)를 보인다. 위쪽 검색칸(품목코드 일부 + Enter/🔍)으로 거른다.
  갱신이 실패하면 빨간 글로 이유를 보이고 지난번 재고를 보여준다. 이미 떠 있으면 앞으로 가져와 다시 받는다.

## 아직

- 작성한 PC에 PHP가 없어 **실행해 보지 못했다** — 처음 쓸 때 시험 화면으로 확인할 것.
- 이카운트 응답 형식(`Status`, `Error`, `Data.Result`)은 Access 코드와 공개 문서 기준이다. 실제 응답이 다르면 `post()`/`errorText()`를 맞춘다.
- 없는 품목일 때 이카운트가 빈 결과를 주는지 오류를 주는지 확인 필요(오류면 지금은 한 번 다시 로그인한 뒤 실패로 알린다).
