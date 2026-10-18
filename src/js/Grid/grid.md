# Grid.js — n x m 페이지 + addCell로 쌓이는 그리드

`Calendar.js`/`Popup.js`/`ChosungKeypad.js`와 동일한 원칙의 재사용 컴포넌트.
서버 통신을 전혀 모르고, `containerId` 컨테이너 안에 스스로를 그린다.
바탕화면 `매출\매출1.png`(룸살롱 룸 배치도, 5열x3행)를 일반화한 것이 계기
(md/2026-09-04-매출1-5-화면-설계논의.md, md/2026-09-05-grid-js-신설.md 참고).

## 핵심 동작

- `new Grid({ containerId, n?, m?, onCellClick? })` — 생성자는 내부적으로
  `action('init', options, ...)`을 부른다. `n`/`m`을 넘기면 그 모양으로
  정해지고, **`clear()`까지 함께 실행돼** 처음부터 "모양은 정해졌지만
  셀은 하나도 없는" 상태로 시작한다(화면엔 아무것도 안 보임).
- `init(n, m)` — 생성 직후와 똑같이 **모양(n x m)과 데이터를 한 번에
  리셋**한다(`action('init', ...)`을 다시 부르는 것과 동일). 완전히
  새로 시작하고 싶을 때 쓴다.
- `setNxM(n, m)` — 한 **페이지**에 열(n) x 행(m)개(=`n*m`칸)가 들어간다는
  것만 바꾼다. **`init()`/`clear()`와 달리 이미 쌓아둔 셀은 지우지
  않는다** — 페이지 크기만 다시 계산되고(현재 페이지가 범위를 벗어나면
  안으로 당겨짐), 화면은 새 모양으로 다시 그려진다.
- `clear()` — 모양(n, m)은 그대로 두고 쌓아둔 셀만 전부 지운다(화면도
  비워짐). "다시 처음부터 채우고 싶은데 그리드 모양은 그대로 두고 싶을
  때" 쓴다.
- `addCell(displayValue, dataValue, keyValue)` — 셀을 하나 끝에 추가하며
  값을 함께 설정한다(반환값은 그 셀의 **번호**, 1부터 누적). `Board`가
  레코드를 쌓는 것과 같은 감각 — 호출할 때마다 셀번호가 1씩 늘어난다.
  지금 보고 있는 페이지 분량(`n*m`개)을 넘는 만큼 추가된 셀은 화면엔
  당장 안 보이고, 그 셀이 속한 페이지로 넘어가야 보인다. 3번째 인수
  `keyValue`(선택)를 주면 생성과 동시에 키도 매겨진다(생략하면 키 없이
  생성 — 나중에 `setValue()`로 매길 수 있다).
- `setValue(cellNo, displayValue, dataValue, keyValue)` — **이미
  `addCell()`된** 셀의 값을 나중에 **통째로 덮어쓴다**(새 셀을 만들지
  않음 — 없는 번호를 주면 `console.warn` 후 무시). 4번째 인수
  `keyValue`(선택)로 그 셀에 임의의 "키"(방번호·직원코드 등)를 매길 수
  있다 — 생략하면(`undefined`) 기존 키를 안 건드린다.
- `setValueAdd(cellNo, displayValue, dataValue)` — `setValue()`와 달리
  **덮어쓰지 않고 이미 있는 값 뒤에 이어붙인다**. `displayValue`는
  문자열로 이어붙이고(로그가 쌓이는 느낌), `dataValue`는 배열에 계속
  `push`한다(기존 `data`가 배열이 아니면 먼저 그 값 하나짜리 배열로
  감싼 뒤 새 값을 넣는다). `setValue()`와 마찬가지로 이미 `addCell()`된
  셀에만 적용된다.
- `getValue(cellNo, kind)` — `kind`가 `'data'`면 dataValue, `'key'`면
  그 셀에 매겨진 키(없으면 `null`), 그 외(또는 생략)면 displayValue.
  한 번도 `addCell()` 안 된 번호는 `null`.
- `getValueAll()` — 그리드 전체를 `JSON.stringify()`한 문자열로 반환
  (`{n, m, scroll, currentPage, totalPages, totalCells, cells: [{cellNo,
  display, data, key}, ...]}`) — `Crud::toJson()`과 비슷한 감각.
- `getCell(keyValue)` — `setValue()`의 `keyValue`로 매겨둔 셀번호를
  **거꾸로 찾는다**(없으면 `null`). 내부적으로 `String(keyValue)`로
  정규화해 저장/조회하므로 `getCell(42)`와 `getCell('42')`는 같은 결과.
  같은 키를 다른 셀에 다시 매기면 그 키의 매핑은 나중 셀 쪽으로
  덮어써진다(이전 셀의 `key` 필드 자체는 안 지워지지만 `getCell()`로는
  더 이상 찾을 수 없다). `clear()`/`init()`이 셀을 지우면 이 매핑도
  함께 비워진다.
- `setValueKey(keyValue, displayValue, dataValue)` / `setValueKeyAdd(keyValue,
  displayValue, dataValue)` / `getValueKey(keyValue, kind)` —
  `setValue(cellNo, ...)`/`setValueAdd(cellNo, ...)`/`getValue(cellNo,
  ...)`를 셀번호 대신 키값으로 부르는 얇은 래퍼(내부적으로
  `getCell(keyValue)`로 셀번호를 먼저 찾아 그대로 위임 — `setValueKey`는
  덮어쓰기, `setValueKeyAdd`는 이어붙이기). 키 자체는 다시 매기지
  않는다(이미 그 키로 찾아낸 셀이니 바뀔 이유가 없음). 없는 키값이면
  `setValueKey()`/`setValueKeyAdd()`는 `console.warn` 후 무시,
  `getValueKey()`는 `null`.
- `clearCell(cellNo)` / `clearCellKey(keyValue)` — 그 셀의 `display`/
  `data`/`key`를 전부 비우고(키가 있었다면 `getCell()` 매핑도 함께
  지움) 화면에 보이는 중이면 빈 칸으로 다시 그린다. **셀 자체(슬롯,
  셀번호)는 없어지지 않는다** — `addCell()`이 매기는 번호 체계가 깨지지
  않도록 "삭제"가 아니라 "그 셀의 내용을 지우는 것"으로 구현했다.
  `clearCellKey()`는 `getCell(keyValue)`로 셀번호를 찾아 `clearCell()`
  에 위임(없는 키값이면 `console.warn` 후 무시).
- **셀번호**: `addCell()`이 호출된 순서대로 1부터 매겨지는 누적 일련번호
  (더는 "몇 행 몇 열"에서 역산하는 고정 좌표가 아니다). 그 번호가 속한
  페이지는 `Math.ceil(셀번호 / (n*m))`. 한 페이지 안에서는 왼쪽→오른쪽,
  위→아래로 `n`개씩 채워진다.
- `displayValue`는 그 셀에 그대로 그려지는 **HTML 문자열**(이스케이프
  안 함 — 스타일을 입힌 문구를 그대로 보여주기 위함, 신뢰 못 할 값은
  호출부가 직접 이스케이프). `dataValue`는 화면에 안 보이는 별도 저장값
  (문자열/숫자/객체 무엇이든 가능).

## 페이지 이동 — Crud/ScrollGrid와 같은 이름, 대상은 "페이지"

- `moveFirst()`/`moveNext()`/`movePrevious()`/`moveLast()` — 한 건의
  레코드가 아니라 **페이지 하나**를 오간다(그리드 한 화면 자체가 이미
  여러 칸을 보여줘서 "현재 레코드"라는 개념이 없다). 경계를 넘는 이동은
  조용히 그 자리에 머문다(예외를 던지지 않음).
- `bof()` — 지금이 첫 페이지인가. `eof()` — 지금이 마지막 페이지인가.
- `getPager()` — `{ currentPage, totalPages, totalCells, pageSize, label }`
  을 반환한다. `label`은 `'2/10'`처럼 바로 화면에 쓸 수 있는 문자열이다.

## setScroll(true|false) — 페이징 대신 스크롤로 전체 보기

- 기본은 `false`(페이징). `setScroll(true)`면 페이지 구분이 사라지고
  지금까지 `addCell()`된 **모든** 셀을 한 번에 그린 뒤 컨테이너 자체가
  세로로 스크롤된다. **셀 크기(행 높이)는 페이징 모드였을 때와 똑같이
  고정된다** — 컨테이너 높이를 `m`으로 나눈 값을 모든 행에 그대로
  적용하고(`grid-auto-rows`), 열 수(`n`)도 그대로 유지한다. 즉 셀
  하나하나의 크기는 안 바뀌고 행 개수만 늘어나며, 컨테이너 높이를
  넘는 만큼을 스크롤로 보게 된다(`1fr`을 그대로 쓰면 브라우저가 행
  높이를 계속 줄여 항상 컨테이너 안에 욱여넣어버려 애초에 스크롤이
  안 생기므로, 고정 픽셀 값으로 강제해야 한다). 행 높이를 잴 때
  컨테이너 높이에서 `Grid.SCROLL_BOTTOM_GAP`(기본 `12`px)만큼 미리 빼고
  그 몫을 `padding-bottom`으로 돌려줘, 스크롤을 끝까지 내려도 마지막
  행이 하단에 바로 붙어 가려 보이지 않게 여백을 남긴다.
  `getPager().totalPages`는 항상 `1`, `bof()`/`eof()`도 항상 `true`가
  된다(페이지가 하나뿐이니까).
- `init()`/`clear()`/`setNxM()`은 이 스크롤 여부를 안 건드리는 완전히
  별개의 설정이다 — `setScroll()`을 직접 불러야만 바뀐다.

## setStyle(null|'default'|'card') — 기본형과 카드형 사이 외형 토글

- 바탕화면 `매출\카드.png`(지갑 앱 UI, 둥근 모서리+그림자+여백이 있는
  카드형 위젯)를 참고한 순수 표면 스타일 전환. `null`/`'default'`(기본,
  생략해도 동일)는 지금까지의 표 느낌(칸끼리 딱 붙어 테두리로만 구분)을
  유지하고, `'card'`면 칸끼리 간격(`gap`)을 두고 떨어진 채 모서리가
  둥글고(`border-radius`) 그림자가 진(`box-shadow`) 개별 카드처럼
  그려진다.
- `addCell`/`setValue`/`setValueAdd`/키값 버전 등 데이터를 다루는 규칙은
  기본형과 완전히 동일 — `displayValue`가 그대로 셀 안에 그려지고
  `dataValue`도 똑같이 저장된다. 바뀌는 건 칸의 겉모습뿐이다.
- `n`/`m`/스크롤 여부/데이터 어느 것과도 무관한 독립 설정이라, 페이징
  모드든 스크롤 모드든 그대로 적용된다. `clear()`/`setNxM()`/`init()`/
  `setScroll()`은 이 값을 안 건드린다 — `setStyle()`을 직접 불러야만
  바뀐다.

## 내부 구조

이 클래스 안에서 일어나는 모든 일은 `action(pcmd, pparams, psender)`
하나를 거쳐 `_setNxM()`/`_clear()`/`_addCell()`/`_setValue()`/
`_setValueAdd()`/`_setValueKey()`/`_setValueKeyAdd()`/`_clearCell()`/
`_clearCellKey()`/`_getValue()`/`_getValueKey()`/`_getValueAll()`/
`_getCell()`/`_moveToPage()`/`_setScroll()`/`_setStyle()` 같은 private
함수로 분기된다(RoomBoard.js/SplitView.js가 쓰는 것과 같은 관용구). 키값
버전(`_setValueKey`/`_setValueKeyAdd`/`_clearCellKey`/`_getValueKey`)은
`_getCell()`로 셀번호를 찾아 그 cellNo 버전에 그대로 위임할 뿐 로직을
따로 갖지 않는다. 공개 메서드는 전부 이 `action()`을 부르는 얇은
래퍼다. `action('init', ...)`은 `_setNxM()`과 `_clear()`를 이 순서로
함께 호출한다(모양을 먼저 정하고 지운다). `_render()`가 서버 fetch
없이 항상 동기로 끝나므로(로드할 외부 라이브러리가 없음), 생성자/각
메서드는 별도 콜백 없이 그 자리에서 바로 결과를 반영한다.

## 사용 예

```js
const grid = new Grid({ containerId: 'my-grid', n: 5, m: 3 }); // init: 5x3 + clear, 화면엔 아직 아무것도 없음

const no1 = grid.addCell(
    '<b>1T(5)</b><br><span style="background:#ffe58a;">손님3</span>',
    { room: '1T', capacity: 5 },
    '1T' // 생성과 동시에 키 매기기(선택)
);
grid.getValue(no1, 'display'); // '<b>1T(5)</b>...'
grid.getValue(no1, 'data');    // { room: '1T', capacity: 5 }
grid.getValue(no1, 'key');     // '1T'
grid.getCell('1T');            // no1

grid.setValue(no1, '<b>1T(5)</b><br>퇴장', { room: '1T', capacity: 5 }); // 값만 갱신(덮어쓰기, 키 유지)
grid.setValueAdd(no1, '<br>다시입장', null); // 기존 값 뒤에 이어붙임

grid.setValueKey('1T', '<b>1T(5)</b><br>다시퇴장', null); // 키로 지정해 덮어쓰기
grid.setValueKeyAdd('1T', '<br>추가문구', null); // 키로 지정해 이어붙이기
grid.getValueKey('1T', 'display'); // '<b>1T(5)</b><br>다시퇴장<br>추가문구'
grid.clearCellKey('1T'); // display/data/key 전부 비움(셀번호 자리는 남음)
grid.clearCell(no1);     // 셀번호로 직접 비워도 동일

for (let i = 0; i < 20; i++) { grid.addCell('셀 ' + (i + 2), null); }
grid.getPager(); // { currentPage: 1, totalPages: 2, totalCells: 21, pageSize: 15, label: '1/2' }
grid.moveNext();
grid.getPager().label; // '2/2'

grid.setNxM(3, 3);  // 모양만 3x3으로(데이터 21칸은 그대로 남음, 페이지 수만 재계산)
grid.clear();       // 모양(3x3)은 그대로, 데이터만 전부 지움
grid.init(5, 3);    // 모양+데이터를 한 번에 리셋(생성 직후와 동일한 상태)

grid.setScroll(true);   // 페이징 대신 스크롤로 전체를 한 번에
grid.getValueAll();     // '{"n":5,"m":3,"scroll":true,...,"cells":[...]}'

grid.setStyle('card');  // 칸 외형만 카드형으로(addCell/setValue 규칙은 그대로)
grid.setStyle(null);    // 다시 기본형으로('default'를 줘도 동일)
```

`Grid_entry.php`(이 폴더)가 init/setNxM/clear, setScroll, setStyle,
addCell(키값 포함), setValue(키값 포함)/setValueAdd/setValueKey/
setValueKeyAdd, clearCell/clearCellKey, getValue/getValueKey, getValueAll,
getCell, 페이지 이동 도구와 그리드
표시를 한 페이지에서 시연한다(제목/설명 문구는 화면에 없음 — fieldset의
legend만으로 용도를 표시). 글자 크기는 그리드 전체 설정이 아니라
addCell/setValue의 스타일 조립 옵션 중 하나(배경색/글자색/굵게와 같은
자리)로, 셀마다 다르게 줄 수 있다. `Layout` 메뉴 `?top=5&left=2`
("그리드연습")가 이 파일을 iframe으로 얹는다.

## 알려진 한계

- `displayValue`를 이스케이프하지 않는다 — 사용자 입력을 그대로 넣으면
  XSS 위험이 있다. 신뢰된 서버/코드가 만든 문자열만 넘길 것.
- 진짜 셀 삭제/순서 변경 기능은 없다 — `addCell()`로 뒤에 계속 쌓이기만
  한다. `clearCell()`/`clearCellKey()`는 그 셀의 내용만 비울 뿐, 슬롯
  (셀번호) 자체를 없애 뒤의 번호를 당기지는 않는다.
- 셀 정렬/선택 강조/드래그 같은 부가 상호작용은 없음 — 필요하면 호출부가
  `onCellClick`과 `setValue()`로 직접 구현.
- 스크롤 모드의 행 높이 계산(`container.clientHeight / m`)은 실제
  브라우저 레이아웃(컨테이너가 이미 자리를 잡고 높이가 확정된 상태)을
  전제로 한다 — 컨테이너가 아직 화면에 자리 잡기 전(예: `display:none`
  상태)이면 `clientHeight`가 `0`이라 고정 높이 대신 `auto`로 폴백한다.
