# Memo.js

큰 텍스트영역 하나로 메모를 입력/수정하는 팝업 전용 위젯. `ChosungKeypad.js`/
`NumberKeypad.js`와 마찬가지로 순수 DOM/이벤트만 다루고, 입력한 값의 쓰임
(어느 레코드의 메모인지 등)은 전혀 모른다. `NumberKeypad`보다 구조가
단순하다 — 키패드 없이 `<textarea>` 하나 + 하단 버튼 두 개(취소/저장)뿐.

## 핵심 동작

- 생성 시 `initial`을 텍스트영역에 그대로 채운다("그 지점의 값을 창이
  띄워질 때 화면에 보여지고" 지시).
- "저장" 클릭(`save()`) — `onSave(value)`(로컬) +
  `sender.action(callback, {value}, window)`(있으면).
- "취소" 클릭(`cancel()`) — `onCancel()`(로컬) + **같은** `callback`으로
  `sender.action(callback, {value: null, cancelled: true}, window)`(있으면).
  지시 원문은 "저장을 누르면 콜백"만 요구했지만, 팝업을 닫는 것은 항상
  여는 쪽 책임이라는 이 계열 위젯의 원칙상 취소 경로도 같은 창구로 알려야
  여는 쪽이 팝업을 닫을 수 있다(`ChosungGrid`/`NumberKeypad`의 Quit과 동일
  이유).

## 사용법 — 화면에 직접 얹기

```js
new Memo({
    containerId: 'memo-box',   // 필수 — 이 id의 엘리먼트 안에 렌더링
    caption: '',                // 텍스트영역 위 안내 문구(선택)
    initial: '',                 // 초기 표시값(선택)
    onSave: (value) => {         // "저장" 클릭 시 호출 — value = 지금 텍스트영역 값
        save(value);
    },
    onCancel: () => {},          // "취소" 클릭 시 호출
});
```

## 공개 API

- `getValue()` — 지금 텍스트영역에 있는(아직 저장 전인) 값
- `setValue(value)` — 표시값만 강제로 맞춤(콜백 안 부름, 순수 동기화)
- `setCaption(caption)` — 캡션(안내 문구)만 나중에 바꿈
- `save()` — "저장" 버튼과 동일 동작을 코드로 실행
- `cancel()` — "취소" 버튼과 동일 동작을 코드로 실행
- `destroy()` — DOM/이벤트 정리(팝업 닫을 때 등)

## `sender`/`callback` — 여는 쪽이 이름을 지어서 주입

`Calendar.js`/`ChosungGrid.js`/`NumberKeypad.js`와 동일한 관례
(`md/2026-09-11-action-callback-이름부여-규칙.md` 참고) — `sender`/
`callback` 옵션을 주면 저장/취소 둘 다 **같은** `callback` 이름으로
보고한다(기본 이름은 `'callback_Memo_메모'`, 지시 원문 그대로).
"callback은 띄우기전에 주입하게" — `Memo.open()`의 `callback` 옵션이 그
자리다. **팝업을 닫는 것은 항상 여는 쪽 책임** — 이 위젯은 자기가
Popup 안에 있는지도 모른다.

## `Memo.open(options)` — 팝업으로 메모 하나만 받기

`ChosungGrid.open()`/`NumberKeypad.open()`과 완전히 같은 형태 —
`Popup.js`의 `iframeUrl`로 같은 폴더의 `Memo_entry.php`를 연다.

```js
const popup = Memo.open({
    caption: '메모를 입력하세요',        // 선택 — ptitle과 별개(위젯 내부 안내 문구)
    initial: row.메모,                   // 선택 — 그 지점의 현재 값
    callback: 'callback_Memo_메모',      // 선택 — 생략 시 'callback_Memo_메모'
    sender: window,                     // 필수 — 결과를 받을 이 창
    ptitle: '메모 입력',                 // 선택 — Popup 제목
});
```

여는 쪽의 전역 `action(pcmd, pparams, psender)`가 `case
'callback_Memo_메모':` 안에서 `pparams.value`(저장값, 취소면
`pparams.cancelled === true`이고 `value`는 `null`)를 받아 처리한 뒤,
`popup.close()`로 직접 닫는다.

## `Memo_entry.php` — 모듈 자기 검증 페이지

`ChosungGrid_entry.php`/`NumberKeypad_entry.php`와 동일한 "두 가지
방식으로 다 열어볼 수 있게" 관례:

1. **직접 열기**(주소창에 바로 입력) — `sender`가 없어 로컬 로그
   영역에만 결과가 남는다.
2. **Popup(iframe)으로 열기** — `Memo.open({...})`으로 열리면
   `window.parent.action(callback이름, ...)`이 실제로 호출된다.

`?callback=`/`?caption=`/`?initial=` 쿼리스트링으로 바꿔볼 수 있다.

## `4_7`(메모연습) 실사용 예

`public/test/Layout/test1/content4_7.php` — 위 두 사용법(화면에 직접
얹기 / 팝업으로 메모 하나만 받기)을 나란히 보여준다. `menu.json`의
`leftMenu["4"]`에 `{"key":"7","label":"메모연습"}`을 추가해 상단메뉴
4(프로그램) 안에서 좌측메뉴로 노출된다.
