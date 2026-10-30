# crudbuttons

입력폼(amd) 아래에 iframe으로 붙이는 **재사용 CRUD 버튼줄**.
저장 / 삭제 / 신규입력 / 창닫기 버튼을 제공하고, 안에 **CrudJs**를 하나 들고 부모 폼의
컨트롤에 바인딩해 실제 CRUD를 한다. 업무 규칙(이벤트)은 **입력폼(amd)이 정의**하고,
crudbuttons는 그 이벤트를 **불러줄 뿐**이다 — Access에서 폼 모듈에 `beforeCrudSave`
등을 두고 공용 crud 모듈이 그걸 부르던 구조와 같다.

이 폴더의 JS는 전부 **VBA식 JS**로 쓴다(규칙은 `crudbuttons.js` 맨 위 주석).

## 먼저 읽을 것 — AI·개발자가 이 폴더를 쓰거나 고칠 때 지킬 규칙

1. **VBA식 JS** — `var`만(함수 맨 위에 모아 선언), `function`만(화살표 함수 안 씀), 매개변수는 `p`로 시작,
   조건은 `if / else`(삼항·`&&` 줄임 안 씀), 반복은 `for (var i = 0; …)`, 문자열은 `+`로 잇기,
   서버를 기다리는 일은 `async / await`. (`crudjs.js`만 예외 — 원래 스타일을 따른다.)
2. **창과 창 사이: 명령·보고는 `action`, 이벤트는 이름 있는 함수.**
   - 명령(시키기)·보고(알리기) → 상대 창의 `action(pcmd, pparams, psender)`.
   - 이벤트(묻기·알림: `beforeCrudSave` 등) → crudbuttons가 입력폼의 함수를 이름으로 찾아 부른다
     (Access의 이벤트 프로시저와 같다). 없으면 통과.
3. **`action`은 나눠 넘기기만 한다.** case를 겹쳐(VBA `Case "a", "b"`) 종류별 하위 함수
   `action_xxx(pcmd, pparams, psender)`로 넘기고, 실제 일은 하위 함수에서 한다. (4절 예시)
4. **입력폼 → 띄운 쪽 보고는 `psender.report(ptype, payload)` 하나.** 띄운 쪽은 팝업이든 iframe이든
   `action('amdReport', {ptype, payload, popupId}, amd창)`로 받는다. Popup의 `onAction`은 쓰지 않는다.
5. **저장 전 검사는 `beforeCrudSave` 한 곳**에 위에서 아래로 쓴다. 서버 확인이 필요하면
   `async function`으로(CrudJs가 기다린다). 가벼운 검사(필수 입력)를 먼저, 서버 확인을 나중에.
   `isEnable…`과 `beforeCrudClose`는 기다리지 않으니 바로 답한다.
6. **화면 전용 함수·변수에는 화면 접두사**를 붙인다 — fLogin_amd.php는 `fl`/`FL_`
   (`flButtons`, `FL_FETCH_URL`), crudbuttons_entry.php는 `ce`/`CE_`, crudbuttons의 CSS는 `cb-`.
   전역 이름 충돌(특히 이름으로 찾아 부르는 이벤트·`action`, `window.close` 같은 내장)을 막고,
   "이 화면의 도우미"임을 이름만으로 알게 한다. 이벤트 함수와 `action`/`action_xxx`에는 붙이지 않는다.
7. **컨트롤 id = 테이블 필드명.** 값 읽고 쓰기는 `psender.getCrud().getValue/setValue`로
   (DOM을 직접 만지지 않는다). CrudJs 자체 설명은 `../crudjs/Crudjs.md`.

## 접근 주소

```
http://192.168.0.40/bros/src/Model/Crud/crudbuttons/crudbuttons_entry.php          ← 시험 화면
http://192.168.0.40/bros/src/Model/Crud/crudbuttons/fLogin_amd.php?code=1          ← 시범 입력폼 단독(code 생략 시 신규)
http://192.168.0.40/bros/src/Model/Crud/crudbuttons/fLogin_amd_fetch.php?action=schema ← 스키마 확인
```

## 파일

| 파일 | 역할 |
|---|---|
| `crudbuttons.js` | `CrudButtons` 클래스 — 버튼을 그리고, CrudJs를 만들어 부모 폼에 연결하고, CrudJs 이벤트 자리에 부모 폼의 이벤트를 단다. |
| `crudbuttons.php` | iframe에 들어가는 페이지. `?fetch=<xxx_fetch.php>&pk=<처음 열 PK>`를 받아 `crudjs.js` + `crudbuttons.js`를 띄운다. |
| `fLogin_amd.php` | 시범 입력폼(tLogin). `id=필드명` 컨트롤 + 이벤트 함수 + 아래쪽 crudbuttons iframe. |
| `fLogin_amd_fetch.php` | 시범 입력폼의 서버 쪽 — CrudJs 계약(schema/selectRecord/selectRecords/save/delete). |
| `crudbuttons_entry.php` | 시험 화면 — tLogin 목록, 버튼 11개, 입력폼을 Popup으로 띄우고 콜백을 로그로 보여준다. |

관련 파일(이 폴더 밖):
- `src/Model/Crud/crudjs/crudjs.js` — CrudJs(클라이언트 CRUD 엔진). `crudjs/Crudjs.md` 참고.
- `src/js/Popup/Popup.js` — Popup(레이어 팝업). `popup.md` 참고.

---

## 전체 관계

```
entry (crudbuttons_entry.php)                       ← 목록/버튼, 입력폼을 띄우는 쪽
 │  new Popup({ iframeUrl: 'fLogin_amd.php?code=…', onClose })   ← 보고는 entry의 action('amdReport')로
 │
 └─ Popup (레이어 팝업, entry 문서 안의 <div>)
     └─ <iframe> amd (fLogin_amd.php)               ← 입력폼: 필드 컨트롤 + 이벤트 함수
         │  컨트롤: <input id="id">, <input id="이름"> … (id = 테이블 필드명)
         │  이벤트: isEnableAmdSave, beforeCrudSave, afterCrudSave …
         │
         └─ <iframe> crudbuttons (crudbuttons.php?fetch=fLogin_amd_fetch.php&pk=…)
             ├─ CrudButtons (crudbuttons.js)        ← 버튼 + 이벤트 연결
             └─ CrudJs (crudjs.js)                  ← 값 바인딩 + 서버 CRUD
                  │  window = amd 창 → amd의 id=필드명 컨트롤을 직접 읽고 쓴다
                  └─ fetch → fLogin_amd_fetch.php   ← 서버(Crud.php로 DB)
```

### 각자 아는 것 / 모르는 것

| | 아는 것 | 모르는 것 |
|---|---|---|
| **entry** | 어떤 입력폼을 어떤 code로 띄울지, 콜백을 받으면 뭘 할지(목록 requery 등) | 입력폼 안의 필드, CRUD 방법 |
| **Popup** | 박스를 띄우고 닫기, 캡션 | 안에 무엇이 들었는지(입력폼의 보고는 Popup을 거치지 않는다) |
| **amd(입력폼)** | 필드 배치, 업무 규칙(이벤트), 언제 무엇을 보고할지 | 버튼, 서버 통신, 자기가 팝업/iframe/단독 중 어디에 있는지 |
| **crudbuttons** | 버튼, 언제 어떤 이벤트를 부를지, 버튼 활성/비활성, amd를 감싼 쪽(팝업/iframe/단독)에 보고·닫기 | 어떤 테이블인지(fetch 주소로만 앎), 업무 규칙 |
| **CrudJs** | 스키마, 화면↔값 동기화, 기본 스키마 검사, 서버 호출 | 버튼, 부모 폼의 이벤트 이름 |

### CrudJs와 crudbuttons의 관계

- crudbuttons가 CrudJs를 **하나 만들어 들고 있다**(`this.crud`).
  ```js
  this.crud = new CrudJs({ fetchUrl: this.fetchUrl, window: this.target }); // target = amd 창
  ```
- CrudJs는 `window`로 받은 **amd 창의 `id=필드명` 컨트롤**을 직접 읽고 쓴다. 그래서
  amd에는 값을 채우거나 모으는 코드가 필요 없다.
- CrudJs는 작업 앞뒤에 **이벤트 자리**(`save_before`, `save_after` …)를 부른다. 그 자리에
  "amd의 이벤트를 부르는 함수"를 넣는 것이 crudbuttons의 `wireCrudEvents()`다.

  | CrudJs 이벤트 자리 | crudbuttons가 부르는 amd 이벤트 |
  |---|---|
  | `save_before` | `isEnableAmdSave` → `beforeCrudSave` |
  | `save_after` | `afterCrudSave` |
  | `delete_before` | `isEnableAmdDelete` → `beforeCrudDelete` |
  | `delete_after` | `afterCrudDelete` |
  | `clear_before` | `beforeCrudClear` |
  | `clear_after` | `afterCrudClear` |
  | `selectRecord_before` | `beforeCrudSelectRecord` |
  | `selectRecord_after` | `afterCrudSelectRecord` |

- **검사는 두 층**이다.
  1. **기본 스키마 검사** — CrudJs `validateSchema()`. NOT NULL, 최대 길이, 숫자형(정수/소수/bit),
     날짜 형식. 모든 화면 공통이라 amd는 아무것도 안 해도 된다. 저장 때 가장 먼저 돈다.
  2. **사용자 정의 조건** — amd의 `isEnableAmd…` / `beforeCrud…`. 업무 규칙만 넣는다.

---

## amd(입력폼)에 있어야 할 것

### 1. 컨트롤 — `id` = 테이블 필드명

```html
<input type="text" id="code" readonly>   <!-- PK -->
<input type="text" id="id">
<input type="text" id="이름">
<select id="권한code">…</select>
```
- fetch 쪽 화이트리스트에 있는 필드만 다뤄진다. 화면에 없는 필드는 CrudJs 안에만 값이 있다.
- 숫자 필드는 CrudJs가 천단위 콤마를 붙여 보여주고, 읽을 때 뗀다.
- **bit 필드는 체크박스**(`<input type="checkbox" id="필드">`)를 쓰면 된다 — CrudJs가
  `1`/`'1'`/`true`/`-1`은 체크, `0`/`'0'`/빈 값은 해제로 보이고, 저장할 때는 `1`/`0`으로 보낸다.
- **날짜 필드**는 CrudJs가 `YYYY-MM-DD`(시각이 있으면 `YYYY-MM-DD HH:MM`)로 보여준다 —
  서버가 dblib 형식(`Sep 30 2026 12:00:00:000AM`)으로 보내도 마찬가지. 일반 `text`,
  `<input type="date">`, `<input type="datetime-local">` 모두 맞는 형식으로 채워진다.
  time 타입은 `HH:MM`.

### 2. crudbuttons iframe

```php
$fetchUrl = APP_BASE_URL . '/…/xxx_fetch.php';
$buttonsSrc = APP_BASE_URL . '/src/Model/Crud/crudbuttons/crudbuttons.php?fetch=' . rawurlencode($fetchUrl) . '&pk=' . rawurlencode($pk);
```
```html
<iframe class="cb-frame" id="cb-frame" src="<?= htmlspecialchars($buttonsSrc, ENT_QUOTES, 'UTF-8') ?>"></iframe>
```
- `pk`가 비어 있으면 신규(빈 폼)로 시작한다.
- `fetch`는 이 앱 안의 주소(`APP_BASE_URL`로 시작)만 받는다.

### 3. 이벤트 함수 — 전부 선택(없으면 "통과")

모두 `(psender, 두 번째 인자)`를 받는다. `psender`는 CrudButtons다.
- `psender.getCrud()` — CrudJs(`getValue('필드')`, `setValue('필드', 값)` …)
- `psender.getPk()` — 지금 PK(신규면 null)
- `psender.isDirty()` — 저장 안 한 값이 있는지(true/false)
- `psender.report(ptype, ppayload)` — amd를 띄운 쪽에 알린다(아래 5절)
- `psender.setCaption(ptext)` — 제목을 바꾼다(팝업이면 팝업 캡션, 아니면 문서 제목)

| 이벤트 | 언제 | 돌려줄 값 |
|---|---|---|
| `isEnableAmdSave(psender, rerror)` | ① 버튼 상태를 정할 때마다 ② 저장 누른 순간(before 앞) | `false`면 저장 버튼 비활성 / 저장 취소 |
| `isEnableAmdDelete(psender, rerror)` | 위와 같음(삭제) | `false`면 삭제 버튼 비활성 / 삭제 취소 |
| `beforeCrudSave(psender, ppara)` **async 가능** | 저장 직전(스키마 검사·isEnable 통과 후). 저장 전 검사는 전부 여기 — 필수 입력, 서버 확인(id 중복 등) | `false`면 취소(서버로 안 감) |
| `afterCrudSave(psender, ppara)` | 저장 성공 뒤(새 PK가 폼에 들어간 뒤) | 없음 |
| `beforeCrudDelete(psender, ppara)` **async 가능** | 삭제 직전(확인창 "예" 뒤) — 딸린 자료 확인 등 | `false`면 취소 |
| `afterCrudDelete(psender, ppara)` | 삭제 성공 뒤(폼을 비우기 전 — PK가 아직 있다) | 없음 |
| `beforeCrudClear(psender, ppara)` **async 가능** | 신규입력(폼 비우기) 직전 — 삭제 뒤에도 돈다 | `false`면 취소 |
| `afterCrudClear(psender, ppara)` | 폼을 비운 뒤 — 기본값 넣기 좋은 자리 | 없음 |
| `beforeCrudSelectRecord(psender, ppara)` **async 가능** | 레코드를 읽기 직전 | `false`면 취소 |
| `afterCrudSelectRecord(psender, ppara)` | 레코드를 폼에 채운 뒤 | 없음 |
| `beforeCrudClose(psender, ppara)` | 창닫기 직전 — 저장 안 한 값 확인 등 | `false`면 안 닫음 |

- **막을 이유 보여주기**: `rerror.error = '…'` / `ppara.error = '…'`에 글을 넣고 `false`를
  돌려주면 버튼 옆에 "저장 실패: …"로 보인다(Access의 ByRef `rerror` 대신 객체).
- **before…는 `async function`으로 써도 된다** — CrudJs가 `await`로 기다린다. 저장 전
  검사는 `beforeCrudSave` 한 곳에 위에서 아래로 쓰고, 가벼운 검사(필수 입력)를 먼저,
  서버 확인(id 중복)을 나중에 둔다(빈 값일 때 서버에 묻지 않게).
- **`isEnable…`과 `beforeCrudClose`는 바로 답해야 한다**(기다리지 않는다).
- **저장 안 한 값 경고는 crudbuttons가 한다** — amd는 아무것도 안 해도 된다(아래 "저장 안 한 값").
- **`isEnable…`은 자주 불린다**(폼 값이 바뀔 때마다). 값만 보고 true/false를 돌려주고,
  포커스 이동·메시지 같은 부수 동작은 넣지 않는다.
- 이벤트 안에서 오류(예외)가 나면 crudbuttons는 `false`(막음)로 본다.

이벤트 틀(VBA식 JS):
```js
function isEnableAmdSave(psender, rerror) {
    return true;
}

function isEnableAmdDelete(psender, rerror) {
    return true;
}

async function beforeCrudSave(psender, ppara) {     // async — 서버 확인을 await할 수 있다
    var crud = psender.getCrud();
    var res;
    var data;

    // ① 가벼운 검사 먼저 — 필수 입력(flText = null이면 '', 앞뒤 공백 제거)
    if (flText(crud.getValue('이름')) === '') {
        ppara.error = '이름을 입력하세요.';
        return false;
    }

    // ② 서버에 물어보는 검사는 나중에
    res = await fetch(FL_FETCH_URL + '?action=checkId&id=' + encodeURIComponent(crud.getValue('id')));
    data = await res.json();
    if (data.exists) {
        ppara.error = '이미 쓰고 있는 id입니다.';
        return false;
    }
    return true;
}

function afterCrudSave(psender, ppara) {
    psender.report('saved', { code: psender.getPk() });   // 띄운 쪽(entry)에 알리기
}

function beforeCrudDelete(psender, ppara) { return true; }
function afterCrudDelete(psender, ppara) { psender.report('deleted', { code: psender.getPk() }); }
function beforeCrudClear(psender, ppara) { return true; }
function afterCrudClear(psender, ppara) { psender.getCrud().setValue('사용여부', '1'); }  // 기본값
function beforeCrudSelectRecord(psender, ppara) { return true; }
function afterCrudSelectRecord(psender, ppara) { }
```

### 4. action(pcmd, pparams, psender)

모든 페이지에 `action`을 두고 코드는 항상 그리로 흐른다. amd가 받으면 좋은 명령
(바깥에서 이 폼을 움직일 때 — 전부 `action_buttons`가 버튼 iframe에 맡긴다):

| pcmd | 누가 보냄 | 할 일 |
|---|---|---|
| `'requery'` | entry 등 바깥 | 버튼 iframe의 `action('requery')`에 맡긴다. |
| `'selectRecord'` `{pk}` | entry 등 바깥 | 버튼 iframe의 `action('selectRecord', {pk})`에 맡긴다. |
| `'close'` | entry 등 바깥 | 버튼 iframe의 `action('close')`에 맡긴다(창닫기 버튼과 같은 길). |

창닫기 버튼 자체는 amd의 action을 거치지 않는다 — crudbuttons가 직접 닫는다(5절).

**action 쓰는 법 — 종류별 하위 함수 `action_xxx`**: `action`에는 업무 코드를 넣지 않고,
case를 겹쳐(VBA의 `Case "a", "b"`처럼) 종류별 하위 함수로 넘기기만 한다. switch가
커지지 않고, 명령 목록이 한눈에 보인다.
```js
function action(pcmd, pparams, psender) {
    if (!pparams) {
        pparams = {};
    }

    switch (pcmd) {
        case 'close':
        case 'requery':
        case 'selectRecord':
            return action_buttons(pcmd, pparams, psender);

        default:
            console.warn('xxx_amd.action: 알 수 없는 pcmd - ' + pcmd);
    }
}

function action_buttons(pcmd, pparams, psender) {   // 하위 함수도 인자는 action과 같다
    ...
}
```

### 5. 띄운 쪽에 알리기 / 닫기 — crudbuttons가 한다

amd는 자기가 **팝업 / 다른 화면의 iframe / 단독** 중 어디에 있는지 몰라도 된다.
`psender.report()`, `psender.setCaption()`만 부르면 crudbuttons가 판단한다.

| amd가 있는 곳 | `psender.report('saved', {...})` | `psender.setCaption('…')` | 창닫기 |
|---|---|---|---|
| **팝업 안** | 띄운 쪽의 `action('amdReport', {ptype, payload, popupId: 팝업id}, amd창)` | 팝업 캡션 | `'closed'` 보고 → 팝업을 닫는다 |
| **다른 화면의 iframe 안** | 띄운 쪽의 `action('amdReport', {ptype, payload, popupId: ''}, amd창)` | 문서 제목 | `'closed'` 보고만(숨길지 지울지는 띄운 쪽이 정한다) |
| **단독(주소창/새 탭)** | 보고할 곳 없음 | 문서 제목(브라우저 탭 글자) | amd 창을 닫는다(스크립트가 연 창일 때만 브라우저가 닫아 준다) |

- 창닫기 순서: `beforeCrudClose`(false면 그만) → `'closed'` 보고 → 위 표대로 닫기.
- 띄우는 화면은 팝업이든 iframe이든 `action`에 `case 'amdReport':` 하나만 두고
  `pparams.ptype`으로 처리한다. 여러 개를 띄웠으면 `pparams.popupId`(팝업) 또는
  세 번째 인자(amd 창)로 어느 것인지 구분한다. Popup의 `onAction`은 쓰지 않는다.
- 팝업을 찾는 방법: amd 창의 부모(`amd.parent`)에 `Popup`이 있으면
  `amd.parent.Popup.ofWindow(amd)`. `Popup.js`는 `window.Popup = Popup;`으로 자기를 window에
  단다(최상위 `class`는 window 속성이 안 되므로) — 그래야 iframe 안에서 보인다.

---

## 통신 방향 — 누가 누구의 무엇을 부르나

| 방향 | 호출 | 도착 |
|---|---|---|
| entry → Popup | `new Popup({...})`, `popup.close()`, `popup.setCaption()` | Popup |
| entry → amd (아래로 명령) | `popup.iframeEl.contentWindow.action('requery')` | amd의 `action` |
| amd → crudbuttons (아래로 명령) | `document.getElementById('cb-frame').contentWindow.action('requery')` | crudbuttons의 `action` |
| crudbuttons 버튼 클릭 | `window.action('save')` 등 | crudbuttons 자기 `action` → `CrudButtons.save()` |
| crudbuttons → CrudJs | `crud.save()`, `crud.deleteRecord()`, `crud.clear()`, `crud.selectRecord()` | CrudJs |
| CrudJs → crudbuttons | `crud.save_before()` 등 이벤트 자리 | crudbuttons가 넣어 둔 함수 |
| crudbuttons → amd (이벤트) | `window.parent.beforeCrudSave(psender, ppara)` 등 | amd의 이벤트 함수 |
| CrudJs ↔ amd (값) | amd 문서의 `id=필드명` 컨트롤을 직접 읽고 씀 | amd의 컨트롤 |
| CrudJs → 서버 | `fetch(xxx_fetch.php?action=…)` | fetch PHP |
| amd → 띄운 쪽 (위로 보고) | 이벤트 안에서 `psender.report('saved', {...})` | 띄운 쪽의 `action('amdReport')`(팝업이든 iframe이든) |
| crudbuttons → 팝업 (창닫기) | `popup.close()` | Popup |
| Popup 닫힘 → entry | (Popup이 자동으로) | entry의 `onClose` → entry `action('popupClosed')` |

- **규칙: 창과 창 사이의 명령·보고는 전부 `action`으로 한다. 이벤트(`beforeCrudSave` 등)만
  이름 있는 함수로 부른다** — Access에서 폼의 공개 메서드와 이벤트 프로시저를 나누는 것과 같다.
- **위로 보고**는 `psender.report()` 하나로 한다. 띄운 쪽은 항상 `action('amdReport')`로 받고,
  `pparams.popupId`(팝업)나 세 번째 인자(amd 창)로 같은 입력폼을 여러 개 띄워도 구분된다.
  보고 이름이 `'amdReport'`로 정해져 있어 입력폼이 띄운 쪽의 다른 명령 이름에 묶이지 않는다.
- **아래로 명령**은 대상 iframe의 `contentWindow.action(pcmd)`로 한다.
- 그냥 `action('xxx')`라고만 부르면 **자기 페이지의 action**이다.

---

## 동작 순서

### 처음 열릴 때
1. amd가 뜨고, 아래 crudbuttons iframe이 로드된다.
2. crudbuttons가 amd 문서가 다 그려질 때까지 기다린다(이벤트 함수들이 정의된 뒤여야 하므로).
3. CrudJs를 만들어 amd 창에 연결 → 이벤트 자리 연결 → `loadSchema()`.
4. `pk`가 있으면 `selectRecord(pk)`(→ before/afterCrudSelectRecord),
   없으면 `clear()`(→ before/afterCrudClear).
5. 버튼 상태를 정한다(`refresh`).

### 저장
1. `isEnableAmdSave`가 false면 버튼이 이미 비활성.
2. 누르면: CrudJs `validateSchema()`(기본 검사) → `isEnableAmdSave` →
   `beforeCrudSave`(await). 하나라도 걸리면 중단하고 이유를 버튼 옆에 보인다
   (서버 저장은 안 한다).
3. 서버 저장 → 새 PK가 폼의 PK 칸에 들어간다 → `afterCrudSave`.
4. 지금 값을 기준값으로 기억(수정됨 표시가 꺼진다), 버튼 상태를 다시 정한다(삭제 활성).

### 삭제
1. PK가 없거나 `isEnableAmdDelete`가 false면 버튼이 비활성.
2. 누르면: 확인창 → `isEnableAmdDelete` → `beforeCrudDelete`(await) →
   서버 삭제 → `afterCrudDelete`.
3. 폼을 비운다(묻지 않음) → `beforeCrudClear` → `afterCrudClear`(신규입력 상태).

### 신규입력
(수정됨이면 묻기) → `beforeCrudClear` → 폼 비우기 → `afterCrudClear`(기본값 넣기) →
지금 값(기본값 포함)을 기준값으로 기억. PK가 없어지니 삭제 비활성.

### 창닫기
`beforeCrudClose`(false면 그만) → (수정됨이면 묻기) → 띄운 쪽에 `'closed'` 보고 →
팝업이면 팝업을 닫는다(→ entry의 `onClose`), iframe이면 보고만, 단독이면 창을 닫는다.

### 저장 안 한 값
- **기준값**: 레코드를 열거나 / 폼을 비우거나 / 저장한 직후의 폼 값. 지금 값이 이것과 하나라도
  다르면 "수정됨"(`psender.isDirty()`). 다시 원래 값으로 고치면 수정됨이 아니다.
- **수정됨이면**: 저장 버튼이 노랗게 보인다. 신규입력 / `requery` / `selectRecord` / 창닫기
  전에 "저장하지 않은 내용이 있습니다. 버리고 …할까요?"를 묻는다.
- **단독으로 연 창**(새 탭)이면 창을 닫거나 새로고침할 때 브라우저도 묻는다.
- 삭제는 묻지 않는다(삭제 확인창이 따로 있다).

### 버튼 상태(refresh)가 다시 정해지는 때
처음 열릴 때, 각 작업 전후, amd 폼에서 값이 바뀔 때(input/change)마다.
- 저장: CrudJs 준비됨 + 작업 중 아님 + `isEnableAmdSave` ≠ false
- 삭제: 위 + PK 있음 + `isEnableAmdDelete` ≠ false
- 신규입력: CrudJs 준비됨 + 작업 중 아님
- 창닫기: 항상 활성

---

## xxx_fetch.php 계약 (CrudJs 계약)

`fLogin_amd_fetch.php`를 본뜬다(원본 예시는 `src/Model/Crud/crudjs/crudjs_fetch.php`).

| action | 메서드 | 응답 |
|---|---|---|
| `schema` | GET | `{success, schema}` |
| `selectRecord&pk=` | GET | `{success, schema, cursor:0, data:[행]}` |
| `selectRecords` | GET | `{success, schema, cursor, data:[...]}` (목록용, 선택) |
| `save` | POST(JSON 행) | `{success, pk}` — PK 있으면 update, 없으면 insert |
| `delete&pk=` | POST | `{success, affected}` |

그 밖의 action(예: `fLogin_amd_fetch.php`의 `checkId` — id 중복 확인)은 amd의
`beforeCrudSave` 같은 이벤트가 직접 부르는 것이라 화면마다 자유롭게 둔다.

실패는 전부 `{success:false, error:'…'}`.
- 테이블 이름은 fetch 파일 안에 고정한다(클라이언트가 고르지 못하게).
- `Crud::init(테이블, 화이트리스트)`로 다룰 필드를 좁힌다(PK는 자동 포함).
- `Login::isAlive()`로 로그인을 확인한다.

## 새 입력폼 만들기 체크리스트

1. `xxx_fetch.php` — `fLogin_amd_fetch.php` 복사 → 테이블명, 화이트리스트 바꾸기.
2. `xxx_amd.php` — 컨트롤을 `id=필드명`으로 배치, 아래에 crudbuttons iframe(`fetch`, `pk`).
3. 필요한 이벤트만 정의(없는 건 통과). 띄운 쪽에 알릴 일은 `psender.report()`로.
4. 띄우는 쪽:
   - 팝업으로: `new Popup({ iframeUrl: 'xxx_amd.php?code=…', onClose })`
   - iframe으로: `<iframe src="xxx_amd.php?code=…">`
   - 어느 쪽이든 자기 `action`에 `case 'amdReport':`를 두고 보고를 받는다.

## 주의

- **날짜**: 서버는 MSSQL 날짜를 `Sep 30 2026 12:00:00:000AM`(dblib) 형식 그대로 보내지만,
  CrudJs가 받자마자(`_normalizeLoadedRows`) `YYYY-MM-DD`로 바꿔 화면과 저장에 쓴다.
  fetch에서 따로 변환할 필요 없다.
- **삭제 뒤 clear**: 삭제가 성공하면 crudbuttons가 바로 `clear()`를 부른다 —
  `afterCrudDelete` 안에서는 PK가 아직 있지만, 그 뒤 clear 이벤트에서는 없다.
- CrudJs의 `saveBulk` / `selectRecords`에는 이벤트 자리가 없다(이 버튼 4개는 안 쓴다).
