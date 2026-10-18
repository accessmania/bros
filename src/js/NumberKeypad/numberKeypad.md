# NumberKeypad.js

바탕화면 `매출/숫자키패드.png`를 재현할 목적의 독립 JS 모듈.
`ChosungKeypad.js`와 마찬가지로 순수 DOM/이벤트만 다루고, 서버 통신이나
입력한 값의 쓰임(가격/수량 등)은 전혀 모른다.

> **참고 이미지 미확인** — 이 세션에는 `숫자키패드.png`가 직접 전달되지
> 않아, 일반적인 숫자 키패드 배열(7·8·9/4·5·6/1·2·3/·(소수점)·0·←(지우기))
> 과 `ChosungKeypad`의 기존 관례(디스플레이+Clear+Quit)를 따라 우선
> 구현했다. 실제 이미지와 배열/버튼 구성이 다르면 이후 지시로 조정한다.

## `ChosungKeypad`와의 핵심 차이 — "입력 중" vs "확정"

`ChosungKeypad`는 누를 때마다 즉시 `onInput`으로 통보한다(검색어처럼
"입력 중인 상태가 곧 결과"이기 때문). `NumberKeypad`는 숫자 버튼을
누르면 디스플레이에만 누적되고, 실제로 "이 값을 쓰겠다"는 확정은
**"확인" 버튼**을 눌러야 한다(`confirm()`/`onConfirm`) — 금액/수량처럼
다 입력한 뒤에야 의미가 있는 값이기 때문. `clear()`는 디스플레이를
통째로 비운다(`ChosungKeypad`와 동일한 동작이지만, 아래처럼 지금은
이 동작을 부르는 전용 버튼이 하단 액션행에 없다).

## 하단 액션행 — Quit/확인 두 개만, 균등 분할 (2026-09-11 수정)

처음엔 액션행이 Clear/Quit(선택)/확인 3개(또는 2개)였으나, "맨 하단의
Clear를 없애고 Quit와 확인 두 개가 행을 균등하게 차지하게" 지시로
Clear 버튼 자체를 없앴다. 남은 버튼(둘 다 `flex:1`)이 이제 자연스럽게
폭을 절반씩 나눠 갖는다 — `showQuitButton:false`(기본값)면 이 행엔
"확인" 하나만 남아 전체 폭을 혼자 차지한다. `clear()` 메서드/`onClear`
콜백 자체는 그대로 남아있고, 코드로 직접 부르거나(`popup.instance(id)`
등으로 얻은 인스턴스에서) 아래 `C` 키를 통해서만 호출된다.

## 그리드 안 클리어 버튼 — `C` (2026-09-11 추가, 같은 날 재수정)

`allowDecimal:false`일 때 소수점 자리(0 왼쪽)가 빈 칸으로 남던 것을,
`C`(클리어) 버튼으로 채운다 — `clear()`를 그 자리에서 바로 누를 수
있게 한 것뿐이라 별도 옵션은 없다. `allowDecimal:true`면 그 자리를
소수점(`.`)이 이미 쓰고 있어 `C`는 나타나지 않는다. **하단 액션행의
Clear 버튼이 없어진 뒤로는, `allowDecimal:false`일 때 이 `C`가 지우기의
유일한 UI 진입점이다** — `allowDecimal:true`면 지우기 버튼 자체가
없고(`←`로 한 글자씩 지우거나 처음부터 다시 입력), `clear()`를 코드로
직접 부르는 것만 가능하다.

## 단축 버튼 — `0`/`00`/`000`/`0.1`/`0.5`/`1.5` (2026-09-11 추가)

기본 숫자 키패드 아래 두 줄로 추가된 POS용 단축 버튼:

- **`0`/`00`/`000`**(첫 줄, `mode: 'append'`) — 일반 숫자 키와 완전히
  같은 "이어붙이기" 동작. 큰 금액을 빠르게 입력할 때(예: "1" 누른 뒤
  "000" → "1000") 쓰는 계산기 관용구.
- **`0.1`/`0.5`/`1.5`**(둘째 줄, `mode: 'set'`, `allowDecimal:false`
  이면 이 줄 자체가 안 그려짐) — 이어붙이지 않고 **값 전체를 그 숫자로
  교체**한다. 자주 쓰는 소수량(예: 술병 반 병=0.5)을 한 번에 선택하는
  프리셋 — 이어붙이는 방식이면 "12" + "0.5" = "120.5"처럼 의미 없는
  값이 될 수 있어 전체 교체로 설계했다.

### `0`계열 키는 "값이 비어있거나 이미 0일 때" 무반응 (2026-09-11 수정)

**메인 키패드의 `0`, 단축 버튼의 `0`/`00`/`000` 전부** — 값이
비어있거나("") 이미 `"0"`뿐인 상태에서 누르면 아무 반응도 없다("화면은
항상 숫자가 나와야 한다"는 전제 아래, "0"/"00"/"000" 같은 의미 없는
값이 그대로 표시되는 것을 막는다). 이미 유효한 숫자가 있을 때만
정상적으로 자릿수가 늘어난다(예: `"1"` 다음 `"000"` → `"1000"`).
소수점(`.`)/프리셋(`0.1`/`0.5`/`1.5`)/1~9 숫자는 이 제한과 무관하게
항상 그대로 동작한다.

### 값은 항상 "유효한 숫자형" — 선행 0 제거, "05" 금지 (2026-09-11 추가)

정수부에 의미 없는 선행 0이 남는 걸 두 지점에서 막는다.

1. **입력 도중** — 지금 값이 정확히 `"0"`뿐인 상태에서 1~9 숫자를
   누르면, 뒤에 이어붙이지 않고(`"0"+"5"`="05"가 되는 걸 막음) 그
   숫자로 통째로 교체한다(`"0"` → `"5"`). 이미 소수점을 입력해
   `"0."` 상태라면 이 규칙과 무관하게 정상적으로 이어붙는다(`"0."` +
   `"5"` = `"0.5"`).
2. **`initial`/`setValue()`로 값이 주입될 때** — `_normalizeNumericString()`이
   정수부의 선행 0을 정리한다(`"05"`→`"5"`, `"007"`→`"7"`). `"0"`
   자체나 `"0.5"`처럼 소수부가 있는 값은 그대로 유지한다. 숫자 형태가
   아닌 문자열이 들어오면 정규화하지 않고 그대로 돌려준다(방어적
   처리 — 예외를 던지지 않음).

## 디스플레이 배색/천단위 콤마 (2026-09-11 추가)

디스플레이는 **검은 배경 + 노란색(`#ffeb3b`) 볼드**로 표시된다 —
`deco_main.php`의 `get매출style()`/`setStats()`가 이미 쓰는 배지
배색(검은 배경+노란 글자)과 통일. 정수부는 3자리마다 콤마가 붙는다
(`deco_main.php`의 `dmFormatNumber()`와 동일한 정규식 재사용) —
**표시만** 이렇게 꾸미고, `getValue()`/`confirm()`이 넘기는 실제 값은
콤마 없는 순수 숫자 문자열 그대로다(예: 디스플레이엔 "1,234"가
보여도 `getValue()`는 `"1234"`를 돌려줌). [2026-09-16 수정] 이 문단이
설명하는 동작은 이제 `displayMode`의 기본값(`'number'`)일 때만
적용된다 — 아래 "디스플레이 모드" 참고.

## 디스플레이 모드 — `displayMode` (2026-09-16 추가)

"입력 매개변수에 따라 정렬/콤마/비밀번호 표시를 조절 가능하게" 지시로,
디스플레이의 정렬과 포맷을 옵션 하나(`displayMode`)로 고를 수 있다.
정렬(우측/가운데)과 포맷(콤마/없음/마스킹)이 항상 1:1로 묶여 있어
옵션을 둘로 쪼개지 않았다 — 값 세 가지:

| `displayMode` | 정렬 | 표시 | 비고 |
|---|---|---|---|
| `'number'`(생략 시 기본값) | 우측 | 3자리마다 콤마 | 지금까지의 유일했던 동작, 정보가 없을 때(옵션 생략/화이트리스트 밖 값) 항상 이 값으로 떨어짐 |
| `'plain'` | 가운데 | 콤마 없이 입력한 그대로 | 전화번호/코드처럼 3자리 묶음이 의미 없는 값 |
| `'password'` | 가운데 | 입력한 문자 수만큼 `●`로 마스킹 | 값이 비어있으면 다른 모드처럼 `"0"`이 아니라 빈 문자열 표시(비밀번호 칸에서 "0"은 "이미 한 글자 입력됨"으로 오해될 수 있어 제외) |

`this.value`(=`getValue()`/`confirm()`이 돌려주는 실제 값)는 세 모드
모두 항상 평문 그대로다 — `'password'`도 **표시만** 마스킹하고 값
자체는 숨기지 않는다. `NumberKeypad.open()`에도 같은 이름으로 넘길 수
있고, 화면에 직접 얹을 때(`new NumberKeypad({...})`)도 동일하게
동작한다. 잘못된 값(위 세 개 밖)을 주면 생성자가 조용히 `'number'`로
되돌린다(`NumberKeypad._DISPLAY_MODES` 화이트리스트).

## 캡션 — 디스플레이 위 안내 문구 (2026-09-11 추가)

`caption` 옵션(선택)을 주면 디스플레이 바로 위에 문구가 표시된다 —
"무엇을 입력하는 중인지" 안내하는 용도(예: `'입력할 금액을
선택하세요'`). **`ptitle`(아래 `open()`의 Popup 창 제목표시줄)과는
완전히 별개** — `caption`은 위젯 내부에, `ptitle`은 그 위젯을 담은
Popup 창의 제목표시줄에 뜬다. `setCaption(caption)`으로 나중에 바꿀 수
있다.

## 사용법 — 화면에 직접 얹기

```js
new NumberKeypad({
    containerId: 'nk-keypad',   // 필수 — 이 id의 엘리먼트 안에 렌더링
    caption: '',                 // 디스플레이 위 안내 문구(선택)
    initial: '',                 // 초기 표시값(선택)
    maxLength: 12,                // 입력 가능한 최대 자릿수(기본 12)
    allowDecimal: true,           // 소수점 허용 여부(기본 true)
    displayMode: 'number',        // 'number'(기본)/'plain'/'password' — 위 "디스플레이 모드" 참고
    showQuitButton: false,        // 기본 숨김 — 팝업으로 띄우는 화면에서만 true
    onInput: (value) => {},       // 누를 때마다(확정 전) 중간 상태를 알고 싶을 때만
    onConfirm: (value) => {       // "확인" 클릭 시 호출 — value = 확정된 문자열
        save(value);
    },
    onClear: () => {},            // clear() 호출 시(allowDecimal:false면 그리드 안 C 키, 또는 코드로 직접 clear() 호출) 통지
    onQuit: () => {},             // showQuitButton:true일 때만 의미 있음
});
```

## 공개 API

- `getValue()` — 지금까지 누적된(아직 확정 전인) 문자열
- `setValue(value)` — 표시값만 강제로 맞춤(콜백 안 부름, 순수 동기화)
- `setCaption(caption)` — 캡션(안내 문구)만 나중에 바꿈
- `clear()` — 값을 비움(`onClear` 호출됨) — 하단 액션행에 전용 버튼은
  없고, `allowDecimal:false`일 때 그리드 안 `C` 키가 이를 호출한다
- `confirm()` — "확인" 버튼과 동일 동작을 코드로 실행(`onConfirm`/
  `sender.action(...)` 둘 다 수행)
- `destroy()` — DOM/이벤트 정리(팝업 닫을 때 등)

## `sender`/`callback` — 여는 쪽이 이름을 지어서 주입 (2026-09-11 추가)

`Calendar.js`/`ChosungGrid.js`와 동일한 관례
(`md/2026-09-11-action-callback-이름부여-규칙.md` 참고) — `sender`/
`callback` 옵션을 주면 "확인" 시
`sender.action(callback, {value}, window)`로 보고한다(기본 callback
이름은 `'callback_숫자키패드'`). "Quit"을 누르면
`sender.action(callback, {value: null, cancelled: true}, window)`로
취소를 보고한다(`ChosungGrid.open()`의 취소 보고 방식과 동일). **팝업을
닫는 것은 항상 여는 쪽 책임** — 이 위젯은 자기가 Popup 안에 있는지도
모른다.

## `NumberKeypad.open(options)` — 팝업으로 값 하나만 받기

`ChosungGrid.open()`과 완전히 같은 형태 — `Popup.js`의 `iframeUrl`로
같은 폴더의 `NumberKeypad_entry.php`를 연다.

```js
const popup = NumberKeypad.open({
    caption: '입력할 금액을 선택하세요',        // 선택 — ptitle과 별개(위젯 내부 안내 문구)
    initial: '',                              // 선택
    maxLength: 12,                            // 선택
    allowDecimal: true,                       // 선택 — false면 소수점 버튼이 빈 칸
    displayMode: 'number',                    // 선택 — 'number'(기본)/'plain'/'password'
    callback: 'callback_금액입력',             // 선택 — 생략 시 'callback_숫자키패드'
    sender: window,                           // 필수 — 결과를 받을 이 창
    ptitle: '금액 입력',                        // 선택 — Popup 제목
});
```

여는 쪽의 전역 `action(pcmd, pparams, psender)`가 `case
'callback_금액입력':` 안에서 `pparams.value`(확정값, 취소면
`pparams.cancelled === true`)를 받아 처리한 뒤, `popup.close()`로 직접
닫는다(`content4_6.php`가 이 화면과 동일한 조합을 데모로 보여준다).

## `NumberKeypad_entry.php` — 모듈 자기 검증 페이지

`ChosungGrid_entry.php`와 동일한 "두 가지 방식으로 다 열어볼 수 있게"
관례:

1. **직접 열기**(주소창에 바로 입력) — `sender`가 없어 로컬 로그
   영역에만 결과가 남는다.
2. **Popup(iframe)으로 열기** — `NumberKeypad.open({...})`으로 열리면
   `window.parent.action(callback이름, ...)`이 실제로 호출된다.

`?callback=`/`?caption=`/`?initial=`/`?maxLength=`/`?allowDecimal=0`/
`?displayMode=number|plain|password` 쿼리스트링으로 바꿔볼 수 있다.

## `4_6`(숫자키패드) 실사용 예

`public/test/Layout/test1/content4_6.php` — 위 두 사용법(화면에 직접
얹기 / 팝업으로 값 하나만 받기)을 나란히 보여준다. `menu.json`의
`leftMenu["4"]`에 `{"key":"6","label":"숫자키패드"}`를 추가해 상단메뉴
4(프로그램) 안에서 좌측메뉴로 노출된다.
