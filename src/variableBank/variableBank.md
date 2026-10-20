# variableBank (`src/variableBank/variableBank.js`)

앱이 끝날 때까지 살아 있는 **앱 전역 값 저장소**. Access에서 숨겨 두고 계속
열어두던 저장소 폼(텍스트박스 `ctlTxtXml`에 XML로 값을 쌓던
`getvalue`/`setvalue`/`exists`/`clear` 모듈)을 웹으로 옮긴 것이다.

이 문서는 시간순 일지가 아니라 **지금 시점의 스펙 요약**이다. API가 바뀌면
이 문서도 함께 갱신한다.

## 위치와 역할

```
탭 iframe ──parent.action('variableBank', {cmd,...})──▶ index ──▶ VariableBank (index 창 안의 JS 개체)
```

- index(`index.php`)가 `variableBank.js`를 불러 **index 창 안의 JS 개체**로
  둔다. index는 앱이 끝날 때까지 사라지지 않으므로 저장소도 그동안 유지된다.
- 탭 화면은 **index에만 말한다**(`parent.action`). index는 `action()`의
  default에서 저장소로 넘긴다.
- 같은 서버(origin)라 함수를 직접 불러 **그 자리에서 값을 돌려받는다**.
- 서버 처리는 없다. 서버가 판단해야 하는 값(권한 등)의 원본은 계속 PHP
  세션이다 — 이 저장소는 화면 사이에 값을 주고받는 용도다.

### 숨은 페이지(iframe)로 두지 않은 이유

처음엔 Access의 숨은 폼처럼 별도 페이지(`variableBank.php`)를 숨은 iframe으로
띄웠지만 걷어냈다.

- iframe은 index 안에 있어 **수명이 index와 같다** — 얻는 게 없다.
- 새로고침 복원은 iframe이 아니라 `sessionStorage` 덕분이다.
- 오히려 iframe이 늦게 준비돼서 준비 신호·대기열·"준비 전 읽기 실패" 처리가
  생겼고, 저장소가 탭을 찾으려고 index에 다시 묻는 왕복도 생겼다.

JS 모듈 하나로 두면 이것들이 전부 없어지고, 탭 화면 쪽 사용법은 같다.

## 셸(index)에 연결하기

```html
<script src="src/variableBank/variableBank.js"></script>   <!-- 1. 불러오기 -->
<iframe :data-tab-title="tab.title" ...>                    <!-- 2. 탭 iframe마다 제목 -->
```
```js
// 3. action()의 default에서 넘기기
if (VariableBank.handles(pcmd)) return VariableBank.action(pcmd, pparams, psender);
```

다른 셸 페이지도 이 세 가지만 갖추면 같은 저장소를 쓸 수 있다.

| 셸이 받는 pcmd | pparams | 반환 |
|---|---|---|
| `'variableBank'` | `{ cmd, ...저장소 인자 }` | 저장소 명령의 반환값. pparams 개체를 그대로 쓰므로 `preturn`/`preturnType`/`error`가 호출부에 보인다 |
| `'variableBankAvailable'` | — | `true` — 이 부모가 저장소를 가진 셸인지 확인용 |

셸 없이 한 페이지에서 직접 쓸 때는 `VariableBank.call(cmd, pparams, psender)`.

### 간편 함수 — `setbank` / `getbank` (index.php)

`setvalue`/`getvalue`를 인자 개체 없이 바로 부르는 index의 전역 함수.
**속성(`pattname`)을 생략하면 `'value'`**다.

| 함수 | 반환 |
|---|---|
| `setbank(별명, 값, 속성?)` | 성공하면 `true`, 실패하면 `null` |
| `getbank(별명, 속성?)` | 값(없거나 실패하면 `null`). 폼/컨트롤이면 지금 열린 창/요소 |

셸 `action()`으로도 부를 수 있다(`action_bank`가 위 함수로 넘긴다):

| 셸이 받는 pcmd | pparams | 반환 |
|---|---|---|
| `'setbank'` | `{ pnickname, pvalue, pattname? }` | `setbank()`와 같음 |
| `'getbank'` | `{ pnickname, pattname? }` | `getbank()`와 같음 |

```js
// 탭 화면(iframe)에서
parent.setbank('userName', '홍길동');                 // value에 저장
parent.setbank('pickedRoom', '창가', 'memo');          // memo 속성에 저장
parent.getbank('userName');                            // '홍길동'
parent.getbank('pickedRoom', 'memo');                  // '창가'
parent.action('getbank', { pnickname: 'userName' }, window); // action 경로도 같음
```

**index가 미리 넣어 두는 값**: index가 열릴 때(좌측 메뉴를 만들기 전,
`AUTH_STRING`을 심는 `<script>` 바로 아래 — 메뉴 필터가 `getauth`를 쓰므로)
`setbank('auth', authstring)`으로 로그인 사용자의 `tAuth.authstring`을
`'auth'` 별명에 저장한다(열 때마다 세션 값으로 덮어씀). 탭 화면에서는
`parent.getbank('auth')`로 읽는다. 권한 유무만 필요하면 index의
`getauth(권한명)`이 이 값으로 `true`/`false`를 돌려준다(`Auth::hasAuthority()`와
같은 `;권한명;` 규칙). 화면 표시용 복사본일 뿐 — 실제 권한
판단은 서버(PHP 세션, `Auth::hasAuthority()`)에서 한다.

`preturnType`이나 실패 원인(`error`)이 필요하면 기존처럼
`action('variableBank', { cmd: 'getvalue', ... })`를 쓴다 — 간편 함수는 값만
돌려준다(원인은 `console.warn('[variableBank]', ...)`에 남는다).

## 자료 구조 (XML → JSON)

VBA판 `cXml`(별명마다 노드 하나 + 속성들)을 JSON 개체 하나로 옮겼다.

```json
{
  "userName":   { "value": "홍길동", "typename": "string", "isControl": "0", "isForm": "0" },
  "pickedRoom": { "value": 12, "memo": "창가", "typename": "string", "isControl": "0", "isForm": "0" },
  "focusInput": { "value": "{\"tab\":\"variableBank\",\"sub\":[],\"id\":\"vbDemoInput\"}",
                  "typename": "control", "isControl": "1", "isForm": "0" },
  "openerForm": { "value": "{\"tab\":\"권한정보\",\"sub\":[],\"href\":\"/bros/src/Auth/auth_entry.php\"}",
                  "typename": "form", "isControl": "0", "isForm": "1" }
}
```

- 최상위 키 = **별명(pnickname)**. 별명마다 `value` 외에 이름 붙은 속성을
  더 달 수 있다(`pattname`).
- `typename`/`isForm`/`isControl`은 `value`를 저장할 때 붙는 표시(VBA와 같은
  이름, 같은 `'0'`/`'1'` 문자열) — **저장된 문자열을 어떻게 되살릴지**를
  알려준다(아래 "폼/컨트롤").
- 그 밖의 값은 JSON 값 그대로 저장한다 — VBA는 `CStr`로 전부 문자열화했지만
  JSON은 숫자/불린/배열/개체도 담을 수 있다(`typename`은 VBA와 맞춰 `'string'`).

## 저장소 명령 (`cmd`)

인자 이름은 VBA판과 같다.

| VBA | cmd | pparams | 반환 |
|---|---|---|---|
| `getxmlstring()` | `'getJsonString'` | — | 저장소 전체 JSON 문자열 |
| `getxml()` | `'getJson'` | — | 저장소 전체(복사본 개체) |
| `exists(pnickname)` | `'exists'` | `{ pnickname }` | `value`가 있으면 `true` |
| `getvalue(pnickname, preturn, pattname, preturnType)` | `'getvalue'` | `{ pnickname, pattname? }` | 값. **pparams에 `preturn`/`preturnType`('string'/'form'/'control')도 써준다**(VBA ByRef 대응) |
| `setvalue(pnickname, p_string, pattname)` | `'setvalue'` | `{ pnickname, pvalue, pattname? }` | `true` |
| `clear(pkey)` | `'clear'` | `{ pkey? }` | `true` — `pkey`가 있으면 그 별명만, 없으면 전체 |

`pattname`을 생략하면 `'value'`.

## 폼/컨트롤 — `isForm` / `isControl`

VBA판의 핵심 원칙을 그대로 따른다: **개체를 들고 있지 않고 다시 찾는
방법을 문자열로 저장**하고, 꺼낼 때 그 시점에 열려 있는 창/요소를 새로
찾는다. 열쇠는 Access의 폼 이름처럼 **고정된 논리 이름**이다.

| Access | 웹 | 열쇠 |
|---|---|---|
| 폼(폼 이름) | 탭 화면 창(iframe) | **탭 제목**(index 메뉴의 `title`, 예: `'권한정보'`) |
| 컨트롤(폼 이름 + 컨트롤 이름) | 그 창 안의 요소 | 탭 제목 + **요소 id** |

| `pvalue` | 판별 | 저장 내용 | `getvalue` 결과 |
|---|---|---|---|
| 창(Window) | `v.window === v` | `{ tab, sub, href }` | 지금 열려 있는 그 탭의 창(`preturnType: 'form'`) |
| 요소(Element) | `v.nodeType === 1` | `{ tab, sub, id }` | 지금 그 창의 그 id 요소(`'control'`) |
| 그 밖 | — | JSON 값 그대로 | 복사본(`'string'`) |

- `tab` — 그 창(또는 그 위의 창)이 속한 탭의 제목. `sub` — 탭 창에서 그
  창까지 더 내려가는 iframe 경로(탭 화면 자체면 `[]`). 경로의 각 단계는
  iframe `id`(없으면 부모 안에서의 순번).
- 탭은 셸 페이지의 `iframe[data-tab-title]`로 찾는다 — 저장소가 셸 창 안에
  있으므로 묻지 않고 바로 찾는다. 탭은 닫았다 다시 열면 새 iframe이 되지만
  제목은 같으므로 **같은 별명으로 새 창/요소를 다시 찾는다**(Access에서 폼을
  다시 연 뒤 같은 이름으로 찾는 것과 같다).
- 탭이 닫혀 있으면 `null`(자동으로 다시 열지 않는다 — 열려면 index의
  `selectLeftMenu`).
- 컨트롤은 **id가 있어야** 저장된다(없으면 오류).
- 탭 제목을 열쇠로 쓰므로 탭 제목은 메뉴 안에서 유일해야 한다(index는 이미
  제목이 같으면 같은 탭을 재사용한다).
- 탭 iframe이 없는 페이지에서 쓰면(셸 없이 단독) `tab: null`이고 `sub`는 최상위
  창부터의 경로다.

## 오류 처리

VBA의 `On Error Resume Next`처럼 죽지 않는다 — 실패하면 `null`을 돌려주고,
원인은 `pparams.error`와 `console.warn('[variableBank]', ...)`에 남긴다.
모르는 `cmd`도 같은 방식으로 실패를 알린다.

## 값은 복사본으로

탭 화면(다른 창)과 주고받는 값은 넣을 때도 꺼낼 때도 JSON 복사본이다 —
꺼낸 개체를 바깥에서 고쳐도 저장소 안은 바뀌지 않는다(바꾸려면 `setvalue`).

## 보관 — sessionStorage

- 바뀔 때마다(`saveJson()`) 저장소 전체 JSON을 `sessionStorage['variableBank']`에
  쓴다 — index를 새로고침(F5)해도 같은 브라우저 탭이면 값이 복원된다.
  브라우저 탭을 닫으면 사라진다. VBA의 `ctlTxtXml` 텍스트박스 역할이다.
- 처음 쓸 때(`readyJson()`) sessionStorage에서 읽는다(지연 로드).
- 저장소 내용을 눈으로 보려면 `getJsonString`(연습 화면이 항상 보여준다).

## 사용 예시 (탭 화면에서)

```js
function bank(cmd, p) {
    p.cmd = cmd;
    return window.parent.action('variableBank', p, window);
}

bank('setvalue', { pnickname: 'userName', pvalue: '홍길동' });
bank('setvalue', { pnickname: 'pickedRoom', pattname: 'memo', pvalue: '창가' });
bank('setvalue', { pnickname: 'returnTo', pvalue: document.getElementById('출근시각') }); // 컨트롤
bank('setvalue', { pnickname: 'opener', pvalue: window });                              // 폼(이 탭)

bank('exists', { pnickname: 'userName' });          // true

// (다른 탭에서) 나를 연 입력칸에 값 돌려주기 — VBA의 대표 용례
var p = { pnickname: 'returnTo' };
var el = bank('getvalue', p);                      // 그 탭이 열려 있으면 그 입력칸, 닫혔으면 null
if (el) { el.value = '21:30'; }

bank('clear', { pkey: 'userName' });                // 하나만
bank('clear', {});                                  // 전체
```

## VBA판과 다른 점

| VBA | variableBank | 이유 |
|---|---|---|
| 숨은 폼 + 텍스트박스 | 셸 창 안의 JS 개체 + sessionStorage | 웹에선 JS 개체가 전역 저장 공간이고, 새로고침해도 복원 |
| XML(`cXml`) | JSON | 웹에서 바로 다루는 형식 |
| 값을 `CStr`로 문자열화 | JSON 값 그대로(숫자/불린/배열/개체) | 타입을 잃지 않게 |
| 폼/컨트롤 = Access 폼 이름/컨트롤 이름 | 폼 = 탭 제목(+탭 안 iframe 경로), 컨트롤 = 그 + 요소 id | 웹에서 고정된 논리 이름 |
| ByRef `preturn`/`preturnType` | `pparams.preturn`/`pparams.preturnType` | JS엔 ByRef가 없어 인자 개체에 써줌 |
| 오류를 조용히 삼킴 | `null` + `pparams.error` + `console.warn` | 실패 원인을 추적할 수 있게 |

## 연습 화면

`src/variableBank/variableBank_entry.php` — index 메뉴 **현장업무 >
variableBank**로 연다.

- **index 안에서 열면**(실제 앱 경로): index의 저장소를
  `parent.action('variableBank', ...)`로 쓴다. 저장소 JSON이 항상 텍스트 칸에
  보인다. 값 종류로 문자열·숫자·JSON·컨트롤(데모 입력칸)·폼(이 탭)·폼(다른
  탭 — 탭 제목 입력)을 고를 수 있다. 화면의 "다시 찾기 확인" 순서대로
  컨트롤을 저장 → 이 탭을 닫았다 다시 열기 → getvalue하면 새로 열린 화면의
  데모 입력칸이 노란 테두리로 표시된다.
- **index 밖에서 직접 열면**: 화면에 불러온 `variableBank.js`를
  `VariableBank.call()`로 직접 부른다(탭이 없어 폼/컨트롤은 `tab: null` +
  최상위 창 기준 경로).

## 관련 파일

- `src/variableBank/variableBank.js` — 저장소
- `src/variableBank/variableBank_entry.php` — 연습 화면
- `index.php` — 연결(script, 탭 iframe의 `data-tab-title`, action() default), 메뉴,
  간편 함수 `setbank`/`getbank`와 action의 `setbank`/`getbank`(`action_bank`)
