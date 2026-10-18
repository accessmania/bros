# DateTime.js — MS Access VBA 날짜/시간 전역 함수 이식

- **위치**: `src/js/DateTime/DateTime.js`
- **성격**: 인스턴스 없는 순수 함수 namespace(객체 리터럴) — `Resize.js`와
  동일한 관례. DOM/네트워크를 전혀 안 쓴다.
- **자기 검증 페이지**: `src/js/DateTime/DateTime_entry.php`
  (`Grid_entry.php`/`Resize_entry.php`와 동일한 "코드 옆에 자기 검증
  페이지를 둔다" 관례). Layout 메뉴에서는 `?top=4&left=12`(제목:
  "날짜시간 연습")에서 iframe으로 임베드된다.
- **원본**: MS Access VBA 모듈(`transMinuteHour`/`mDateTime_a`/
  `mDateTime_get요일`, 그리고 이 함수들이 공통으로 쓰는 `cdata`)을 그대로
  옮겼다 — 함수 이름은 원본과 동일(이미 Access 어휘 그대로라 `Resize.js`
  처럼 영문/한글 별칭을 따로 만들 필요가 없었다).

## 핵심 동작

### `DateTime.cdata(x)`

`x`를 숫자형으로 바꾼다. `Number(x)`로 바꿀 수 없는 값(`null`/
`undefined`/빈 문자열/숫자가 아닌 문자열 등)은 예외를 던지지 않고 `0`을
반환한다 — VBA 원본의 `On Error Resume Next`(변환 실패해도 죽지 않는다)
와 같은 취지.

```js
DateTime.cdata('123');   // 123
DateTime.cdata('');      // 0
DateTime.cdata(null);    // 0
DateTime.cdata('abc');   // 0
```

### `DateTime.transMinuteHour(pmin, ph, pn)`

분 단위 총합(`pmin`)을 `"H시간N분"` 형태의 문자열로 바꾼다.

- `ph`(선택, 기본 `'시간'`) — 시간 단위 접미사.
- `pn`(선택, 기본 `'분'`) — 분 단위 접미사.
- 시간이 0이면 분만("N분"), 분이 0 이하면 시간만("H시간"), 둘 다 있으면
  "H시간N분" — 원본 VBA의 분기 로직 그대로.

```js
DateTime.transMinuteHour(45);   // "45분"
DateTime.transMinuteHour(60);   // "1시간"
DateTime.transMinuteHour(125);  // "2시간5분"
DateTime.transMinuteHour(120, '時間', '分'); // "2時間"
```

원본 VBA는 `Format(totaln/60,"#,##0.0")`를 `"."`로 잘라 정수부를 시간으로
삼았는데, 여기서는 `Math.floor(totalN/60)`로 동일한 값을 더 단순하게
구한다(정상적인 정수 분 입력에서는 두 계산 결과가 같다).

### `DateTime.mDateTime_get요일(pDate, pType)`

날짜(`pDate`, 생략하면 오늘)의 요일을 `pType`에 맞춰 반환한다.

- `pType`: `'한글'`(기본) | `'영어'` | `'한자'` | `'숫자'`. 생략하거나
  원본 VBA의 Optional 기본값 문자열("한글,영어,한자,숫자" — "고를 수
  있는 값들의 안내문"을 기본값 자리에 그대로 써먹은 VBA 관용구)을 그대로
  주면 `'한글'`로 취급한다.
- `pDate`: 문자열/타임스탬프/`Date` 인스턴스 무엇을 넘겨도
  `new Date(pDate)`로 파싱한다. 파싱에 실패하면(Invalid Date) 빈 문자열을
  반환한다(예외를 던지지 않는다).
- `'숫자'`는 VBA `Weekday()`와 동일하게 1(일)~7(토)을 반환한다(JS
  `Date.getDay()`는 0(일)~6(토)이라 +1을 해서 맞췄다).

```js
DateTime.mDateTime_get요일('2026-09-19');        // "토"
DateTime.mDateTime_get요일('2026-09-19', '영어'); // "Sat"
DateTime.mDateTime_get요일('2026-09-19', '숫자'); // 7
DateTime.mDateTime_get요일();                     // 오늘의 요일(한글)
```

### `DateTime.mDateTime_a(pdate)`

`mDateTime_get요일(pdate)`에 그대로 위임한다(원본 VBA와 동일, `pType`은
항상 기본값).

### `DateTime.diffTime(t1, t2)`

[2026-09-19 추가] VBA `cDateTime` 클래스의 `.diffTime()` 메서드를 이식 —
두 "HH:MM:SS" 시각(`t1`이 앞, `t2`가 뒤)의 차이를 분 단위로 반환한다.
`t2`가 `t1`보다 이르면 자정을 넘긴 것으로 보고 24시간을 더해 보정한다.
`deco_amd_amd_miss.php`의 `calcTime()`(VBA `f경과시간계산()` 이식)이 경과시간
계산에 쓴다. 둘 중 하나라도 "HH:MM:SS" 형식이 아니면 예외 없이 `0`을
반환한다.

```js
DateTime.diffTime('09:00:00', '10:30:00'); // 90
DateTime.diffTime('23:50:00', '00:10:00'); // 20 (자정 넘김)
```

## 사용 예

```html
<script src="/src/js/DateTime/DateTime.js"></script>
<script>
  var totalMinutes = 135;
  document.getElementById('elapsed').textContent =
      DateTime.transMinuteHour(totalMinutes); // "2시간15분"

  var weekday = DateTime.mDateTime_get요일(new Date());
</script>
```

## 알려진 한계

- `mDateTime_get요일()`의 날짜 파싱은 브라우저의 `Date` 생성자에
  의존한다 — `"YYYY-MM-DD"`처럼 ISO 형식은 안전하지만, 로캘에 따라
  다르게 해석될 수 있는 모호한 형식(`"09/19/2026"` 등)은 피하는 게
  안전하다.
- `cdata()`는 VBA의 `CData`/`CDbl` 계열이 구분하는 여러 숫자 서식(통화/
  천단위 콤마 등)까지는 흉내내지 않는다 — 순수 `Number()` 변환 + 실패 시
  `0` 폴백뿐이다. 콤마가 섞인 문자열(`"1,234"`)을 넘기면 `NaN` 판정으로
  `0`이 된다 — 필요해지면 `cdata()` 안에서 콤마를 먼저 제거하도록 확장
  가능.

## 관련 파일

- `src/js/DateTime/DateTime.js` — 본체.
- `src/js/DateTime/DateTime_entry.php` — 자기 검증 페이지(`diffTime()`은
  이 페이지의 시험 폼에는 아직 추가하지 않음).
- `public/test/Layout/test1/content4_12.php` — Layout 메뉴(`?top=4&left=12`)
  임베드 래퍼.
- `public/test/Layout/test1/menu.json` — `leftMenu["4"]`에 key=12
  "날짜시간 연습" 등록.
- `public/test/deco/deco_amd_amd_miss.php` — [2026-09-19] `calcTime()`이
  `DateTime.diffTime()`/`DateTime.transMinuteHour()`를 실제로 가져다 쓰는
  첫 업무 화면.
