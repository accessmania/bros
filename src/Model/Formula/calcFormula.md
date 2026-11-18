# calcFormula

Access 수식(`tb수식_문의/수주/발주/견적`의 `수식` 칸)을 웹(PHP)에서 계산하기 위한 **구성 함수 모음**.
파일: `calcFormula.php` (class `calcFormula`, 정적 함수). 불러오기: `include_custom('DbContext', 'Crud', 'calcFormula')`.

현재 단계(2026-11-13): 구성 함수 + 수식 하나를 계산하는 `getCalcStr`. 화면이 부르는 창구, 시험 화면은 아직 없다(아래 "앞으로").

## calcAll — 수식 테이블 전체 계산 (화면에 돌려줄 값)

```php
$textFields = calcFormula::textFieldsOf($db, 't수주내역');            // 폼 테이블의 글자·날짜 필드
$r = calcFormula::calcAll($db, 'tb수식_수주', $폼값들, $textFields);   // 테이블 이름은 서버 코드에 고정한 것만
// $r['results'] — 계산한 필드만 [필드명 => 결과] (순서대로)  ← 화면은 이것을 칸에 넣는다
// $r['values']  — 보낸 폼 값 + 계산 결과(다음 계산·확인용)
echo json_encode(['success' => true, 'results' => $r['results']], JSON_UNESCAPED_UNICODE);
```

1. 수식 테이블에서 `순서, 필드명, 수식, 필드들`을 `순서` 오름차순으로 읽는다.
2. 수식마다 `getCalcStr` → 결과를 **폼 값[필드명]에 바로 넣는다** — 다음 수식이 그 값을 쓴다
   (Access `Me(필드명) = 결과`와 같음. 예: 공급가총액 → 미수금이 공급가총액을 씀).
   수식 칸이 빈 행은 건너뛴다(값을 바꾸지 않음).
3. 하나라도 실패하면 멈추고 `수식 n번(필드명): 이유`로 예외 — 일부만 계산된 값은 돌려주지 않는다.

## getCalcStr — 수식 하나 계산 (Access getCalcStr의 웹판)

```php
include_custom('DbContext', 'Crud', 'calcFormula');
$value = calcFormula::getCalcStr($db, $수식, $필드들, $폼값들);
// 예: getCalcStr($db, "IIf('[결제통화]'='KRW',Round([적용수량]*[판매단가]*1.1,0),0)",
//                "결제통화,적용수량,판매단가", ['결제통화' => 'KRW', '적용수량' => '150', '판매단가' => '4.78'])  → 789
```

순서는 Access와 같다:
1. 수식이나 필드들이 `isnull_`이면 `''`.
2. 필드들(쉼표 구분)의 값을 폼 값에서 꺼낸다 — **글자·날짜 필드는 그대로, 숫자 필드는 `cdata()`**(Null·글자는 0).
   폼 값에 없는 필드는 오류.
   - 글자 필드 목록은 5번째 인자 `$ptextFields`로 받는다. `Zone`은 늘 그대로.
   - 창구는 `textFieldsOf($db, 폼 테이블)`로 만든다 — 폼 테이블(t수주내역 등) 스키마의 type이 `NUMERIC_TYPES`가 아니면 글자 필드.
   - [2026-11-13] Access 견적 폼 코드는 "Zone만 그대로"였다. 그 규칙을 수주에 쓰면 `결제통화`('KRW')가 cdata로 0이 되어
     `'[결제통화]'='KRW'`가 늘 거짓 → 공급가총액이 0이 됐다. 그래서 필드 타입 기준으로 바꿨다.
3. 수식을 해석해 계산한다.

Access와 다른 점(결과는 같게, 더 안전하게):
- Access는 문장 전체를 글자로 바꾼 뒤 `Eval`한다. 웹은 **해석하면서** `[필드]`를 넣는다 — 따옴표 **밖**이면 값,
  따옴표 **안**(`'[Zone]'`, DLookup 조건 글자)이면 그 글자 안에서 값 글자로 바꾼다(필드들 순서, 대소문자 구분 — Access `Replace`와 같음).
  값에 `'`가 섞여도 수식이 깨지지 않는다.
- `Eval`이 아니므로 **`EXPR_FUNCTIONS` 목록의 함수만** 쓸 수 있다: `IIf Nz nz_ IsNull isnull_ cdata Round DLookup DSum DCount DAvg DMin DMax`.
  목록 밖 함수는 "지원하지 않는 함수"로 실패 — 늘리려면 상수와 `exprCall`에 한 줄씩.
- `IIf`는 조건을 먼저 계산하고 **고른 쪽만** 계산한다(안 고른 쪽의 0 나누기 등으로 멈추지 않게).
- 따옴표 안에 넣는 Null(Zone이 비었을 때)은 `''`. Access는 이때 `Replace`가 오류를 낼 수 있다 — Access 동작 확인 필요.
- 필드들에 없는 `[필드]`가 수식에 있으면 "[X]가 필드들 목록에 없습니다"로 실패(Access는 Eval 오류).

수식 문법(키워드·함수 이름 대소문자 무시). 우선순위 높은 것부터:

```
^  →  -x  →  * /  →  \  →  Mod  →  + -  →  &  →  = <> < > <= >= Like Is [Not] Null  →  Not  →  And  →  Or
값: 숫자 | '글자' | "글자" | #날짜# | [필드] | True | False | Null | 함수(인자, …) | ( 식 )
```

실패하면 `RuntimeException('수식 계산 실패: 이유 — 수식: …')`. 문법 오류는 "n번째 글자: …"로 위치를 알려준다.

구조(파일 안, 위에서 아래로): `getCalcStr` → `exprParse`(→ `exprTokenize` 토큰, `exprOr…exprPrimary` 트리) →
`exprEval`(노드 종류마다 switch) → `exprBinary` / `exprCall` → 위의 연산·함수들.

## Access에서는 어떻게 계산하나 (옮기는 기준)

Access 폼 모듈의 `getCalcStr(p수식, pfields)`:
1. `pfields`(수식 테이블의 `필드들` 칸, 쉼표 구분)의 필드마다 폼 값을 읽는다 — `Zone`은 그대로, 나머지는 `cdata()`.
2. 수식 글자 안의 `[필드]`를 그 값 **글자로** 바꾼다(따옴표 안이든 밖이든). 그래서 글자 필드는 `'[결제통화]'`처럼 따옴표로 감싼다.
3. 완성된 문장을 `Eval()`로 계산한다 — `IIf`, `Round`, `DLookup` 같은 Access 함수와 모듈의 Public 함수(`isnull_`, `cdata`)를 쓴다.

웹은 화면(JS)이 폼 값을 서버로 보내고, 서버가 이 함수들로 계산해 돌려준다(화면 값은 JS만 알기 때문).

## 값 표현

| Access (Variant) | PHP |
|---|---|
| Null | `null` |
| Empty / 빈 글자 | `''` |
| 숫자 | `int` / `float` |
| True / False | `true` / `false` (숫자로 바꾸면 -1 / 0) |

## 함수

### 사용자 함수 — Access 모듈과 같은 규칙
| 함수 | 규칙 |
|---|---|
| `isnull_(x)` | Null과 Empty(`''`)를 똑같이 참. **0은 Null이 아님**. 공백 `' '`도 빈 글자 아님(Trim 안 함) |
| `nz_(x, 기본값 = '')` | `Nz`와 같되 **Null과 Empty(`''`)를 똑같이** 기본값으로(`isnull_`과 같은 판단). 0은 그대로 |
| `cdata(x)` | 숫자로 강제 변환. 숫자가 아니면 0, **Null도 0**. `'1,000'`은 1000. True는 -1 |

### Access 함수
| 함수 | 규칙 |
|---|---|
| `iif(조건, 참, 거짓)` | 조건이 Null이면 거짓 쪽. 두 값은 이미 계산된 것을 받는다(해석기는 고른 쪽만 계산해 넘길 예정 — 안 고른 쪽의 0 나누기 오류로 멈추지 않게) |
| `nz(x, 기본값 = '')` | Null만 기본값으로(빈 글자는 그대로 — `nz_`와 다름) |
| `isNull(x)` | Null만 참(빈 글자는 거짓 — `isnull_`과 다름) |
| `round(x, 자리수 = 0)` | **은행원 반올림**: `round(2.5)=2`, `round(3.5)=4`, `round(0.125,2)=0.12`. Null이면 Null. 자리수 음수는 오류 |

### 연산 (해석기가 부른다)
| 함수 | 연산 | 규칙 |
|---|---|---|
| `add` | `+` | 둘 다 글자면 이어 붙임(VBA와 같음), 아니면 숫자 덧셈 |
| `sub` `mul` `div` `pow` `neg` | `- * / ^ -x` | `/`는 늘 실수, 0으로 나누면 오류 |
| `intDiv` `mod` | `\ Mod` | 두 값을 먼저 은행원 반올림으로 정수로 만든 뒤 계산 |
| `concat` | `&` | Null은 `''`로 잇는다. 둘 다 Null이면 Null |
| `compare(a, op, b)` | `= <> < > <= >=` | 둘 다 글자면 **대소문자 무시**(`'krw'='KRW'` 참). 숫자끼리는 숫자 비교. 숫자 vs 숫자 아닌 글자는 오류 |
| `like(x, 패턴)` | `Like` | `*` `?` `#` `[abc]` `[!abc]`, 대소문자 무시 |
| `andOp` `orOp` `notOp` | `And Or Not` | 3값 논리(Null 포함) |

- **Null 전파**: `&`를 뺀 연산과 비교는 한쪽이라도 Null이면 결과 Null. 조건 자리의 Null은 거짓.
- 형식이 안 맞으면(숫자 자리에 `'abc'` 등) `RuntimeException`으로 이유를 남긴다.

### 도메인 함수 — DB 조회
`dlookup / dsum / dcount / davg / dmin / dmax ($db, 식, 테이블, 조건 = '')`

- **테이블은 `calcFormula::DOMAIN_TABLES`에 있는 것만**(현재 `tb운임표` — Access의 `T3_운임표`가
  웹 DB에서는 `tb운임표`라서 수식도 `tb운임표`로 쓴다). 수식은 화면에서 고칠 수 있으므로
  아무 테이블이나 읽지 못하게 막는다. 새 테이블이 필요하면 상수에 한 줄 더하고 이 표에도 적는다.
- **식**: 필드 하나(`중량코드` 또는 `[중량코드]`). `'*'`는 `dcount`만.
- **조건**: Access 조건 글자를 해석해서 `?` 자리표시로 실행한다 — 값을 SQL에 이어 붙이지 않는다.
  필드 이름은 테이블의 실제 필드 목록에서 찾은 것만 들어간다(`from`, `to` 같은 예약어도 방언이 감싼다).
- **결과**: `dlookup`은 맞는 첫 행의 값(없으면 Null). `dcount`는 없으면 0, 나머지는 없으면 Null.
- 실패하면 `DLookup("중량코드", "tb운임표"): 이유`로 예외.

조건 문법(키워드 대소문자 무시):

| 구분 | 문법 |
|---|---|
| 비교 | `값 = <> < > <= >= 값` (값 = 필드, `'글자'`, `"글자"`, 숫자, `#날짜#`, True/False) |
| 논리 | `And` `Or` `Not`, 괄호 |
| 패턴 | `필드 [Not] Like '패턴'` — `*` `?` `#` (SQL `%` `_`는 쓸 수 없음) |
| 범위·목록 | `필드 [Not] Between 값 And 값`, `필드 [Not] In (값, …)` |
| Null | `필드 Is [Not] Null` |
| 날짜 | `#2026-11-13#`, `#11/13/2026#`(Access처럼 월/일/년), 시각 포함 가능 |

예: `from <=12.5 and to > 12.5 and 지역 ='A'` → `([from] <= ? AND [to] > ?) AND [지역] = ?` + 값 `[12.5, 12.5, 'A']`

### 도우미
`isNumeric(x)`(VBA IsNumeric), `toNumber(x)`(숫자 아니면 오류), `toText(x)`(Null은 `''`, True는 `'True'`),
`isTrue(x)`(조건 값 → 참/거짓, Null은 거짓), `parseCriteria(조건, 필드목록, 방언)`(조건 해석만 — 시험용으로도 쓸 수 있게 public).

## 주의

- **Round와 부동소수**: PHP와 VBA 모두 실수(double)라 `2.675` 같은 값은 내부적으로 2.67499…일 수 있다.
  경계값은 시험 화면에서 Access 결과와 맞춰 볼 것.
- **Zone이 Null일 때**: Access의 `Replace(…, Null)`은 보통 오류(94)를 낸다. Access가 실제로 어떻게 동작하는지
  확인한 뒤 해석기의 치환 규칙을 정한다.
- **php -l 미확인**: 작성한 PC에 PHP가 없어 문법 검사를 하지 못했다. 처음 쓸 때 확인할 것.

## 앞으로

1. ~~수식 해석기~~ — `getCalcStr`로 만듦(2026-11-13).
2. ~~순서대로 전체 계산~~ — `calcAll`로 만듦(2026-11-13).
3. **창구** 엔드포인트 — `{kind, values}`를 받아 kind → 수식 테이블(고정 목록)로 `calcAll`을 부르고
   `{success:true, results}` 또는 `{success:false, error}`를 JSON으로 돌려준다(`Login::isAlive()`로 시작).
3. **시험 화면** `calcFormula_entry.php` — 수식과 값을 넣고 결과·치환 문장·DLookup SQL을 보여준다.
