# Str (`src/Str/Str.php`)

문자열 출력(`escape`)과 **값 변환**(숫자/정수/불린/날짜/서식)을 모아둔 정적
유틸 클래스. 변환 함수들은 VBA 시절 str 모듈의 강제 변환 함수 `cdata()`를
PHP로 옮긴 것이다.

이 문서는 시간순 일지가 아니라 **지금 시점의 스펙 요약**이다. API가 바뀌면
이 문서도 함께 갱신한다.

## 왜 한 곳에 모으나

DB 값이나 사용자 입력은 같은 "참"이라도 `1`/`'1'`/`true`/`'True'`/`-1`
(Access Yes/No)처럼 여러 모양으로 온다. 판단 규칙을 화면/클래스마다 따로
두면 서로 다르게 판정한다 — 실제로 로그인(`Login.php`)과 로그인 관리
그리드(`config_로그인.php`)가 각자 `v === 1 || v === '1' || v === true`를
갖고 있다가 둘 다 `-1`을 놓쳐 "사용 정지"로 오판한 일이 있었다. 이제
판정은 이 클래스 한 곳의 규칙을 쓴다.

## 공통 원칙

- **예외를 던지지 않는다**(VBA `On Error Resume Next`와 같은 취지) — 변환할
  수 없으면 호출부가 넘긴 `$default`를 돌려준다. 예외는 `cdata()`에 모르는
  타입 이름을 줬을 때 하나뿐(오타가 조용히 넘어가지 않게).
- **실패를 그럴듯한 값으로 바꾸지 않는다** — 빈 날짜를 오늘로, 0을 null로
  바꾸는 일은 호출부가 원할 때만 명시적으로 한다.
- PHP 7 호환 문법만 사용.

## API

| 함수 | 반환 | 규칙 |
|---|---|---|
| `toNumber($v, ?float $default = 0.0)` | `?float` | 앞뒤 공백·천 단위 콤마(`"1,234"`) 무시. `true`/`false`→1/0. 변환 불가(null, `''`, `'abc'`)→`$default` |
| `toInt($v, ?int $default = 0)` | `?int` | `toNumber()` 후 반올림(0.5는 0에서 먼 쪽). 변환 불가→`$default` |
| `zeroToNull($v)` | `?float` | 0이거나 변환 불가면 null, 아니면 그 숫자 (VBA `0toNull`) |
| `toBool($v, bool $default = false)` | `bool` | 아래 "불린 규칙" |
| `toYesNo($v)` | `string` | `toBool()` 결과를 `'Yes'`/`'No'`로 (VBA `불린yn`, 표시용) |
| `toDate($v, ?string $default = null, string $format = 'Y-m-d')` | `?string` | 아래 "날짜 규칙" |
| `formatNumber($v, int $decimals = 0)` | `string` | `#,##0` 서식 (VBA `숫자표준`, 표시용). 변환 불가는 `0` |
| `cdata($v, string $type = '숫자')` | 타입별 | VBA식 이름의 창구 — 아래 표 |
| `escape($v)` | `string` | HTML 출력용 이스케이프(기존 기능) |

### 불린 규칙 (`toBool`)

| 결과 | 값 |
|---|---|
| 참 | `true`, 0이 아닌 숫자(`-1` 포함, `'-1'`·`'2'` 같은 숫자 문자열도), `TRUE_STRINGS`: `'true'` `'y'` `'yes'` `'on'` |
| 거짓 | `false`, `null`, `''`, `0`, `'0'`, `FALSE_STRINGS`: `'false'` `'n'` `'no'` `'off'` |
| `$default` | 그 밖의 값(예: `'abc'`, 배열) |

문자열은 앞뒤 공백을 지우고 대소문자를 무시해 비교한다(`' TRUE '` → 참).
VBA판은 "숫자가 아닌 문자열은 전부 True"였지만 그러면 `'N'`/`'no'`까지
참이 되므로 목록을 명시했다.

### 날짜 규칙 (`toDate`)

- `DateTimeInterface`는 그대로 `$format`으로.
- 문자열은 `DATE_INPUT_FORMATS`를 차례로 시도: `Y-m-d`, `Y-m-d H:i:s`,
  `Y-m-d H:i:s.u`, `Y-m-d H:i`, `Y-m-d\TH:i(:s)`(HTML datetime-local),
  `Y/m/d`, `Y/m/d H:i:s`, `Y.m.d`, `Ymd`, 그리고 **MSSQL을 pdo_dblib로 읽을
  때 오는 형식** `M j Y g:i:s:vA`(예: `'Oct 12 2026 12:00:00:000AM'`).
  연속 공백은 하나로 줄여서 본다(dblib는 `'Oct  2 2026'`처럼 두 칸).
- 없는 날짜(`2026-02-30`)는 다음 달로 넘기지 않고 거부 → `$default`.
- 빈 값/변환 불가 → `$default`(기본 null). **오늘 날짜로 바꾸지 않는다** —
  오늘이 필요하면 `Str::toDate($v, date('Y-m-d'))`.
- 시각까지 필요하면 `$format`을 `'Y-m-d H:i:s'`로 준다.

### `cdata()` 타입 이름

| `$type` | 넘기는 함수 |
|---|---|
| `'숫자'`, `'n'` (기본) | `toNumber()` |
| `'정수'`, `'i'` | `toInt()` |
| `'불린'`, `'b'` | `toBool()` |
| `'불린yn'` | `toYesNo()` |
| `'날짜'`, `'d'` | `toDate()` |
| `'숫자표준'`, `'f'` | `formatNumber()` |
| 그 밖 | `InvalidArgumentException` |

새 코드는 반환 타입이 분명한 개별 함수를 직접 쓰는 것을 권장한다.

## VBA `cdata()`와 다른 점

| VBA | Str | 이유 |
|---|---|---|
| 숫자 기본 `0toNull`(0→Null) | 0은 0. 필요하면 `zeroToNull()` | 금액/수량 0이 조용히 null이 되지 않게 |
| 숫자 아닌 문자열 → True | 참/거짓 목록 밖이면 `$default` | `'N'`/`'no'`/`'off'`가 참이 되는 문제 |
| 빈 날짜 → 오늘(`nz_(v, Date)`) | `$default`(기본 null) | 잘못된 입력이 오늘로 저장돼도 모르게 됨 |
| 타입 문자열 오타 → 원래 값 반환 | `cdata()`는 예외, 개별 함수는 오타 불가 | 오타가 조용히 넘어가지 않게 |
| `숫자표준`/`불린yn`도 같은 함수 | `formatNumber()`/`toYesNo()`로 분리 | 변환이 아니라 표시용 서식 |

## 사용 예시

```php
Str::toBool($crud->getValue('사용여부'));   // -1, '1', 'True' → true
Str::toNumber($_POST['금액'] ?? null);       // '1,500' → 1500.0, '' → 0.0
Str::toNumber($_POST['금액'] ?? null, null); // '' → null (입력 없음을 구분할 때)
Str::toInt('3.6');                           // 4
Str::zeroToNull('0');                        // null
Str::toDate('Oct 12 2026 12:00:00:000AM');   // '2026-10-12'
Str::toDate('2026-02-30');                   // null
Str::formatNumber(1234567);                  // '1,234,567'
Str::cdata('-1', '불린yn');                  // 'Yes'
```

## JS 쪽과 규칙 맞추기

화면(JS)에서 같은 값을 판정할 때 규칙이 다르면 같은 버그가 다시 생긴다.
현재 적용된 곳:

- `basicdata/로그인/config_로그인.php` — 사용여부 체크박스와 id 강조가
  `b21ToBool()`을 쓴다. 참 문자열 목록은 `Str::TRUE_STRINGS`를
  `json_encode()`로 그대로 심어서 PHP와 목록이 어긋나지 않는다.

`src/js/DateTime/DateTime.js`의 `DateTime.cdata()`는 숫자 변환만 하는 별개의
JS 이식본이다(변환 불가 → 0). 여러 화면에서 JS 불린/날짜 변환이 필요해지면
같은 규칙의 공용 JS 모듈로 묶는 것을 검토한다.

## 적용 상태

- `Login::authentication()`의 사용여부 검사 → `Str::toBool()` 직접 호출
- `config_로그인.php`의 사용여부 판정 → `b21ToBool()`(같은 규칙)

## 관련 파일

- `src/Str/Str.php` — 이 클래스
- `src/Login/Login.php` — 사용여부 판정
- `basicdata/로그인/config_로그인.php` — 그리드 체크박스 판정
- `src/js/DateTime/DateTime.js` — JS `DateTime.cdata()`(숫자 변환)
