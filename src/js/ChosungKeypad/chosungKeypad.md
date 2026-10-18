# ChosungKeypad.js

바탕화면 `초성검색.png`(Access "초성검색" 화면 좌측 하단)의 가상 키패드를
재현한 독립 JS 모듈. `AGGrid.js`/`ScrollGrid.js`와 마찬가지로 순수
DOM/이벤트만 다루고, 서버 통신이나 그리드 재조회는 전혀 모른다.

## 핵심 동작

버튼을 연속으로 누르면 **"ㄱㄷ"처럼 문자열이 계속 이어붙는다**
(2026-08-26 최종 확정 — 레거시 Access 원본 `fFirstSoundSearch_select`의
`getWhereFirstSoundSearch`/`getNextChar`가 검색어를 한 글자씩 순회하며
"N번째 글자가 이 문자로 시작하는가"를 AND로 엮던 것과 동일한 모델. 처음엔
"매번 마지막 키로 교체"로 설계했다가, 원본 VBA 코드를 확인한 뒤 누적
방식으로 바로잡았다). "Clear"를 누르면 그 문자열을 통째로 비운다.

실제 검색/재조회는 이 모듈의 책임이 아니다 — `onInput` 콜백에 **지금까지
누적된 전체 문자열**을 그대로 넘기고, 그걸로 무엇을 할지(AGGrid 재조회
등)는 호출부가 정한다("그리드 쪽 책임").

검색 결과 건수 표시("0/287(0%)" 같은 카운터)도 이 모듈이 다루지
않는다 — 검색을 실제로 수행하는 화면(그리드) 쪽 몫이다.

## 사용법

```js
new ChosungKeypad({
    containerId: 'ck-keypad',   // 필수 — 이 id의 엘리먼트 안에 렌더링
    initialTab: 'korean',       // 'korean'(기본) | 'english'
    showQuitButton: false,      // 기본 숨김 — 팝업으로 띄우는 화면에서만 true
    onInput: (value) => {       // 클릭마다 호출 — value = 지금까지 누적된 전체 문자열
        onChosungInput(value);  // 호출부가 원하는 이름 무엇이든 가능 — 4_1은 이 이름을 씀
    },
    onClear: () => {            // "Clear" 클릭 시 호출 — 보통 필터 해제
        onChosungInput('');
    },
    onQuit: () => {             // showQuitButton:true일 때만 의미 있음
        closePopup();
    },
});
```

## 공개 API

- `getValue()` — 지금까지 누적된 검색 문자열(빈 문자열 = 초기화 상태)
- `setValue(value)` — 표시값만 강제로 맞춤(콜백 안 부름, 순수 동기화)
- `clear()` — "Clear" 버튼과 동일 동작을 코드로 실행(`onClear` 호출됨)
- `setMode('korean'|'english')` — 탭 전환
- `destroy()` — DOM/이벤트 정리(팝업 닫을 때 등)

버튼 자체의 "선택됨" 강조는 없다 — 값이 누적 문자열이라 특정 버튼
하나를 강조할 기준이 없고, 원본 VBA에도 그런 개념이 없었다. 누른
결과는 `ck-display` 영역(누적 문자열 표시)로만 피드백한다.

## 4_1(초성검색) 실사용 예 — 서버 쪽 SQL 조립

`public/test/Layout/test1/content4_1.php` — 좌측 `ChosungKeypad`,
우측 `AGGrid`(t직원, 기본형) 배치. 키패드 `onInput`이
`onChosungInput(value)`(2026-08-26 — 원래 이름 `search()`에서 변경,
"클릭 이벤트"가 아니라 "초성검색 값이 바뀌었을 때"에 반응하는
함수라는 의미를 담음)를 부르면, 이 함수는 `grid.setExtraQuery({
chosung: value })`로 AGGrid를 다시 그린다(`setExtraQuery()`가
내부적으로 `requery()`까지 호출 — `extraQuery`는 2_5의 라디오 필터가
이미 쓰던 것과 동일한 메커니즘).

서버(`Board/4_1/fetch_직원.php`)는 `$_GET['chosung']`(누적 문자열)을
한 글자씩 순회하며 "N번째 글자가 이 문자로 시작하는가" 조건을 AND로
엮는다(레거시 VBA `getWhereFirstSoundSearch()`와 동일한 구조) — 다만
그 글자 하나를 실제 SQL 조건으로 바꾸는 일은 이 파일이 직접 하지 않고
`SqlDialect::chosungCondition($column, $position, $char)`에 위임한다.

**왜 SqlDialect에 위임하는가**: "문자 하나의 유니코드 코드포인트를
구하는 SQL 함수"가 DB 엔진마다 다르기 때문이다 — MSSQL은
`UNICODE(SUBSTRING(...))`, MySQL은 `ORD(CONVERT(SUBSTRING(...) USING
utf32))`. 레거시 VBA 원본은 Access/Jet 콜레이션에 의존하는 문자열
리터럴(`'ㄱ*' and '낗*'` 같은 BETWEEN 경계값)을 썼는데, 이건 MySQL/MSSQL
어느 쪽으로도 그대로 옮길 수 없어서, "완성형 한글은 초성마다 코드포인트
588개(21 중성 × 28 종성)씩 연속 구간을 이룬다"는 유니코드 자체의
수학적 성질로 재구현했다 — `Crud::init()`이 스키마 조회 방식을
`SqlDialect::loadSchema()`에 맡기는 것과 완전히 같은 원칙(엔진마다
다른 SQL 함수/문법은 SqlDialect 뒤로 숨기고, 화면 쪽 코드는 그 결과만
받아 쓴다).
