# Calendar.js

재사용 가능한 **날짜/기간 선택** 달력 컴포넌트. `AGGrid.js`/`ScrollGrid.js`/
`ChosungKeypad.js`와 마찬가지로 순수 DOM/이벤트만 다루고, 서버 통신이나
저장 로직은 전혀 모른다.

3_1의 `calendar.html`(`CalendarWidget`, 제목/내용/입력자로 일정을 CRUD하는
이벤트 캘린더)과는 다른 컴포넌트다 — 이 클래스는 "날짜 하나 또는 기간
(from~to) 하나를 고른다"는 것만 하는, 호텔 예약 화면의 date-range picker에
가까운 용도다.

## 핵심 동작

- **선택값**: `selectionMode: 'single'`(기본)이면 날짜 문자열
  `'YYYY-MM-DD'`|`null`. `'range'`면 `{from, to}`(각각 문자열|`null`) —
  기간 모드에서는 첫 클릭이 `from`을 잠정 확정하고(아직 콜백은 안 쏨),
  두 번째 클릭이 `to`를 확정하면서 콜백을 쏜다(두 값의 순서가 뒤바뀌면
  자동으로 맞바꿈). 그 다음 클릭은 새 기간을 처음부터 다시 고르는
  것으로 취급한다.
- **setValue()는 이벤트를 쏘지 않는다** — `ChosungKeypad::setValue()`와
  동일 원칙. 프로그램적 대입이 콜백을 다시 부르면 순환 호출 위험이
  있어서, 콜백은 오직 사용자가 실제로 날짜를 클릭했을 때만 호출된다.
- **[2026-09-11 수정] `sender.action(callback이름, {value, id}, window)`**
  — 예전엔 `onSelectDate(value, id)`라는 함수 콜백을 직접 받았으나,
  `ChosungGrid.js`가 이미 쓰는 이 프로젝트의 `action(pcmd, pparams,
  psender)` 관례로 통일했다. `sender`가 주어졌고 `sender.action`이
  함수이면 그 콜백을 부른다 — `sender`를 안 주면 콜백 자체가 안 간다
  (호출부가 값을 다른 방법으로 읽어야 함, 예: `getValue()`를 직접
  폴링). **`callback`(선택)** — pcmd 이름을 호출부가 직접 지정한다
  (생략하면 `'callback_달력'`) — 이 위젯도 여러 화면이 재사용하므로
  구체적인 이름은 위젯 자신이 미리 정해둘 수 없고, 그 이름이 실제로
  쓰일 화면(여는 쪽)이 지어서 넘긴다(md/2026-09-11-action-callback-
  이름부여-규칙.md 참고). `pparams.id`는 이 인스턴스의 식별자(생성자의
  `options.id`, 생략 시 `containerId`) — 화면에 같은 용도의 Calendar가
  여러 이유로 여러 개 떠 있어도(예: Popup.js로 "체크인"/"체크아웃"
  날짜 피커를 각각 띄운 경우), 같은 `callback` 이름을 여러 인스턴스가
  공유하면서 이 값만으로 구분할 수 있다(`Popup.action()`의 id 전달과
  동일한 설계).
- **setValue()는 화면(보이는 달)도 옮긴다** — 주입된 값이 다른 달이면
  그 달로 자동 전환된다("달력이 뜰 때 가리키는 날짜가 주입되면 그 화면이
  표시된다").
- **요일 순서**는 `weekStart`(`0`=일요일 시작, `1`=월요일 시작)로 정하고,
  일요일/토요일은 몇 번째 칸에 있든 항상 빨강/파랑으로 표시한다(위치가
  아니라 실제 요일 기준).
- **`showYearMonthList`**(기본 `false`) — `true`면 바탕화면 `달력2.png`
  (MS Access "날짜를 선택하세요" 대화상자)처럼 좌측에 "년"/"월" 라벨과
  그 아래 `<select size="8">` 리스트박스가 추가된다. 이때 상단 툴바
  (화살표+월 라벨+오늘)는 리스트박스 열과 달력 열을 **아우르는 한 줄
  (전체 폭)**을 차지하고, 그 아래에서만 좌(년/월)/우(요일행+그리드)로
  나뉜다 — 그래서 "년"/"월" 라벨의 top이 요일행("일월화수목금토")의
  top과 정확히 나란하고, 그 아래 리스트박스의 top도 자연히 그리드의
  top과 나란해진다(라벨=요일행 높이, 리스트박스=그리드 높이로 매
  렌더마다 실측해 맞춤). 년도 범위는 `Calendar.YEAR_LIST_START`
  (`1900`)부터 오늘 연도+15까지, 생성 시점에 한 번만 고정 — 화면을
  넘겨도 목록 자체는 안 흔들리고 강조된 항목만 바뀐다. 리스트에서 고르는
  건 `showMonth()`와 같은 "화면(보이는 달) 이동"일 뿐 날짜 선택이
  아니다 — 실제 선택은 여전히 그리드를 클릭해야 한다. 리스트박스
  스크롤바는 평소엔 숨겨져 있다가 마우스를 올렸을 때만 보인다(Chromium/
  Firefox 대응, Safari 등은 폴백으로 항상 보일 수 있음).

## 사용법

```js
new Calendar({
    containerId: 'b43-calendar',   // 필수 — 이 id의 엘리먼트 안에 렌더링
    id: 'my-calendar',             // 선택, 생략 시 containerId 재사용 — Calendar.instances 키
    weekStart: 0,                  // 0(기본, 일요일 시작) | 1(월요일 시작)
    selectionMode: 'single',       // 'single'(기본) | 'range'
    paramPrefix: 'cal_',           // URL 상태 파라미터 접두어(기본 '')
    syncUrl: true,                 // 기본 true — false면 URL을 전혀 안 건드림
    initialView: { year: 2026, month: 8 },
    initialValue: '2026-08-19',    // selectionMode:'range'면 { from, to }
    showYearMonthList: false,      // true면 좌측에 년/월 리스트박스 추가
    sender: window,                // 콜백을 받을 창(보통 이 페이지 자신)
    callback: 'callback_달력_값선택됨', // (선택) 생략 시 'callback_달력'
});

function action(pcmd, pparams, psender) {
    switch (pcmd) {
        case 'callback_달력_값선택됨':
            // 단일모드: pparams.value는 'YYYY-MM-DD'|null
            // 기간모드: pparams.value는 { from, to }(둘 다 확정된 뒤에만 호출됨)
            // pparams.id: 이 인스턴스의 식별자 — 여러 Calendar가 콜백을 공유할 때 구분용
            break;
    }
}
```

## 공개 API

- `getValue()` — 지금 선택값(단일: 문자열|`null`, 기간: `{from, to}`)
- `setValue(value)` — 값을 강제로 대입 + 그 달로 화면 이동(이벤트는
  안 쏨)
- `showMonth(year, month1to12)` — 선택값은 그대로, 보이는 달만 이동
- `destroy()` — DOM/이벤트 정리 + `Calendar.instances`에서 제거
- `Calendar.instance(id)` — 정적 메서드, 등록된 인스턴스를 id로 조회
  (`AGGrid.instance(gridId)`와 동일 패턴)

## URL 상태 유지 (wave Layout GET 파라미터 방식)

`syncUrl:true`(기본값)이면 달을 넘기거나 날짜(기간)를 고를 때마다 페이지
재요청 없이 `history.replaceState()`로 주소창 쿼리스트링만 갱신한다.
갱신되는 키는 `{paramPrefix}view`(`'YYYY-MM'`, 지금 보여주는 달)와
단일모드의 `{paramPrefix}date` 또는 기간모드의 `{paramPrefix}from`/
`{paramPrefix}to`뿐 — 그 외 쿼리 파라미터(`top`/`left` 등)는 절대 안
건드린다(`Board::appendQuery()`가 기존 쿼리를 보존한 채 필요한 키만
갈아끼우는 것과 같은 원칙).

**이 클래스는 URL을 쓰기만 하고 읽지 않는다.** 실제로 새로고침(F5)이
일어났을 때 그 GET 값을 읽어 `initialView`/`initialValue`로 다시 넘겨
주는 것은 호출부(서버 쪽 PHP)의 책임이다 — `Board`가 페이지/정렬 상태를
화면단이 화이트리스트로 넘겨야 하게 만든 것과 같은 경계.

요일 순서(`weekStart`)/선택 모드(`selectionMode`)는 URL 자동 갱신 대상이
아니다 — 날짜 클릭만큼 자주 바뀌는 값이 아니라고 보고, 이 값을 바꾸고
싶은 화면은 새 옵션으로 페이지를 다시 여는(링크 재요청) 방식을 쓴다.

## 4_3(달력) 실사용 예

`public/test/Layout/test1/content4_3.php` — GET 파라미터
(`cal_weekStart`/`cal_mode`/`cal_view`/`cal_date`/`cal_from`/`cal_to`,
전부 정규식으로 형식 검증 후 사용)를 읽어 `Calendar` 생성자 옵션으로
그대로 전달한다. 요일순서/선택방식 전환은 지금 GET 값을 유지한 채 그
키만 바꾸는 평범한 링크(페이지 재요청) — Calendar.js가 스스로 갱신하는
URL 상태(달/선택값)와는 별개 채널이다. 두 인스턴스가 `callback_달력_값선택됨`
이름을 공유하고, 로컬 `action()`이 `pparams.id`(`b43-calendar`|
`b43-calendar-2`)로 구분해 화면 하단 읽기전용 문구에 `pparams.value`를
반영한다.
