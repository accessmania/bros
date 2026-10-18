# TimePicker.js

바탕화면 `매출/시분초.png`(Access "시분초" 대화상자)를 재현할 목적의
독립 JS 모듈. `NumberKeypad.js`/`ChosungKeypad.js`와 마찬가지로 순수
DOM/이벤트만 다루고, 고른 시각을 어디에 쓸지(입실시각/퇴실시각 등)는
전혀 모른다.

## 핵심 동작 — "입력 중" vs "확정"

`NumberKeypad`와 같은 원칙 — 시/분 리스트박스를 바꾸는 동안은 내부
상태만 갱신되고, 실제로 "이 시각을 쓰겠다"는 확정은 **"확인" 버튼**을
눌러야 한다(`confirm()`/`onConfirm`). "취소" 버튼은 `cancel()`/
`onCancel`로 값 없이 취소만 알린다.

## 리스트박스 — 시(0~23)/분(0~59), 네이티브 `<select size="10">`

시는 24분법(0~23), 분은 0~59 — 전부 두 자리 형식("00"~"23"/"00"~"59")
으로 표시된다. Access ListBox와 가장 가까운 네이티브 `<select
size="10">`(스크롤 가능한 리스트박스)로 구현했다 — `Calendar.js`의
년/월 리스트박스와 같은 선택.

## 초기값 — 없으면 현재 시각

`initial`(`{hour, minute}`)을 안 주거나 형식이 안 맞으면(시/분 중 하나만
있거나 숫자가 아니거나) 생성자가 곧바로 `new Date()`로 대체한다 —
"선택 컨트롤에 값이 있으면 그 값, 없으면 현재 시각"이라는 요구를
호출부가 매번 신경 쓸 필요 없이 위젯이 스스로 처리한다.

## 퀵버튼 — 현재시각/1시간전/30분전/10분전/5분전/1분전

**"현재시각"만 실제 현재 시각으로 즉시 대치**하고, 나머지 5개는
**지금 화면에 선택돼 있는 시각**에서 그만큼 뺀 값으로 이동한다(컴퓨터의
실제 현재 시각이 아니라, 리스트박스가 지금 가리키는 값이 기준 —
예를 들어 "1시간전"을 두 번 누르면 2시간 전으로 이동한다). 자정을
넘나드는 뺄셈은 1440분(24시간) 기준으로 순환(wrap-around) 계산해
항상 0~23시/0~59분 범위 안에 떨어진다. `TimePicker.QUICK_BUTTONS`
배열 하나에 정의돼 있어, 순서/라벨/오프셋을 바꾸려면 이 배열만 고치면
된다(`'5분전' 옆에 1분전 추가` 지시로 배열 맨 끝에 `{label:'1분전',
offset:'1'}`을 더한 것도 이 방식).

## `sender`/`callback` — 여는 쪽이 이름을 지어서 주입

`Calendar.js`/`NumberKeypad.js`/`ChosungGrid.js`와 동일한 관례
(`md/2026-09-11-action-callback-이름부여-규칙.md` 참고) — `sender`/
`callback` 옵션을 주면 "확인" 시
`sender.action(callback, {hour, minute}, window)`로 보고한다(기본
callback 이름은 `'callback_시분초'`). "취소"를 누르면
`sender.action(callback, {cancelled: true}, window)`로 취소를
보고한다. **팝업을 닫는 것은 항상 여는 쪽 책임** — 이 위젯은 자기가
Popup 안에 있는지도 모른다.

## 사용법 — 화면에 직접 얹기

```js
new TimePicker({
    containerId: 'tp-picker',   // 필수 — 이 id의 엘리먼트 안에 렌더링
    initial: null,               // 선택 — {hour, minute} 또는 없으면 현재 시각
    onConfirm: (value) => {      // "확인" 클릭 시 호출 — value = {hour, minute}
        save(value);
    },
    onCancel: () => {},          // "취소" 클릭 시 호출
});
```

## 공개 API

- `getValue()` — 지금 리스트박스에 선택돼 있는 `{hour, minute}`
- `setValue({hour, minute})` — 표시값만 강제로 맞춤(콜백 안 부름, 순수 동기화)
- `confirm()` — "확인" 버튼과 동일 동작을 코드로 실행(`onConfirm`/
  `sender.action(...)` 둘 다 수행)
- `cancel()` — "취소" 버튼과 동일 동작을 코드로 실행
- `destroy()` — DOM/이벤트 정리(팝업 닫을 때 등)

## `TimePicker.open(options)` — 팝업으로 값 하나만 받기

`ChosungGrid.open()`/`NumberKeypad.open()`과 완전히 같은 형태 —
`Popup.js`의 `iframeUrl`로 같은 폴더의 `TimePicker_entry.php`를 연다.

```js
const popup = TimePicker.open({
    initial: { hour: 18, minute: 30 },     // 선택 — 없으면 위젯이 현재 시각으로 대체
    callback: 'callback_입실시각',          // 선택 — 생략 시 'callback_시분초'
    sender: window,                        // 필수 — 결과를 받을 이 창
    ptitle: '입실시각 선택',                 // 선택 — Popup 제목
});
```

여는 쪽의 전역 `action(pcmd, pparams, psender)`가 `case
'callback_입실시각':` 안에서 `pparams.hour`/`pparams.minute`(취소면
`pparams.cancelled === true`)를 받아 처리한 뒤, `popup.close()`로 직접
닫는다(`content4_8.php`가 이 화면과 동일한 조합을 데모로 보여준다).

## `TimePicker_entry.php` — 모듈 자기 검증 페이지

`ChosungGrid_entry.php`/`NumberKeypad_entry.php`와 동일한 "두 가지
방식으로 다 열어볼 수 있게" 관례:

1. **직접 열기**(주소창에 바로 입력) — `sender`가 없어 로컬 로그
   영역에만 결과가 남는다.
2. **Popup(iframe)으로 열기** — `TimePicker.open({...})`으로 열리면
   `window.parent.action(callback이름, ...)`이 실제로 호출된다.

`?callback=`/`?initialHour=`/`?initialMinute=` 쿼리스트링으로 바꿔볼 수
있다(시/분 둘 다 유효한 정수로 와야 초기값으로 인정 — 하나만 오면
무시하고 위젯이 현재 시각으로 대체).

## `4_8`(시분초) 실사용 예

`public/test/Layout/test1/content4_8.php` — 위 두 사용법(화면에 직접
얹기 / 팝업으로 값 하나만 받기)을 나란히 보여준다. `menu.json`의
`leftMenu["4"]`에 `{"key":"8","label":"시분초"}`를 추가해 상단메뉴
4(프로그램) 안에서 좌측메뉴로 노출된다(key=7은 이미 "메모연습"이 쓰고
있어 8을 대신 씀).

## 리스트박스 중앙정렬 + 팝업 스크롤바 제거 (2026-09-12 추가)

`.tp-quick-row`(퀵버튼 6개, `flex-wrap`으로 줄바꿈)의 "줄바꿈이 없다고
가정한" 최대폭(max-content) 기준으로 `.tp-picker`(inline-block)의
shrink-to-fit 너비가 잡혀, 리스트박스 2열(178px)보다 훨씬 넓은 박스가
만들어진다 — 그 결과 고정폭인 `.tp-columns`가 그 넓은 박스 안에서
기본값(왼쪽 정렬)대로 붙어 보였다. `.tp-columns`에 `justify-content:
center`를 줘서 실제로 그 박스 가운데로 오게 했다.

`TimePicker_entry.php`는 `html`/`body`에 `overflow: hidden`을 걸어
스크롤바 자체가 생길 여지를 없앴다 — 대신 `TimePicker.open()`의 기본
`iframeWidth`/`iframeHeight`(300×480, 퀵버튼이 5개→6개로 늘어난 만큼
넉넉하게 잡음)가 내용을 다 담지 못하면 스크롤 대신 **잘려서** 보인다.
실제로 보고 부족하면 그 두 숫자만 키우면 된다.

## 리스트박스 안에서 선택 항목을 세로로도 가운데에 (2026-09-12 추가)

`scrollIntoView({block:'nearest'})`는 "화면에 보이기만 하면" 그치는
최소 이동이라, 선택된 시/분이 목록의 맨 위/맨 아래 줄에 붙어 보이는
경우가 흔했다 — `_centerSelectedOption()`이 옵션의 실측 높이
(`scrollHeight ÷ 옵션개수`)로 "선택 항목이 `size=10` 안의 정중앙에
오는" `scrollTop`을 직접 계산해 대입한다. 목록 맨 앞/맨 끝 근처라 다
채울 수 없으면 브라우저가 `scrollTop`을 유효 범위로 알아서 잘라내
자연스럽게 그 끝에 붙는다. 리스트박스 값이 바뀌는 모든 경로(초기
렌더/리스트박스 직접 선택/퀵버튼/`setValue()`)가 공통으로 거치는
`_syncSelectUI()`에서 호출돼, 어느 경로로 값이 바뀌든 항상 같은
방식으로 가운데 정렬된다.

## 알려진 한계

- 참고 이미지의 "값없음"(선택을 완전히 비우는) 버튼은 이번 구현에
  없다 — 지시 원문에 없었고, 이 위젯은 시/분이 항상 유효한 값을 갖는
  것을 전제로 설계돼 있어(리스트박스 자체가 "선택 안 됨" 상태를 표현
  못 함) 필요해지면 별도 설계가 필요하다.
- 퀵버튼의 "N분전"은 컴퓨터의 실제 현재 시각이 아니라 **지금 리스트박스가
  가리키는 값** 기준이다 — Access 원본과 같은 동작으로 판단해 그대로
  따랐다.
