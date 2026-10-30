# CrudJs

브라우저용 **id=필드명 자동 바인딩 CRUD 엔진**(`crudjs.js`의 `class CrudJs`).
화면 컨트롤의 `id`를 DB 필드명과 같게 지어 두면, CrudJs가 값을 읽고 쓰고, 서버의
`xxx_fetch.php`(안에서 `Crud.php` 사용)와 JSON으로 주고받아 조회/저장/삭제를 한다.
Access의 **바운드 폼**(컨트롤이 필드에 바로 묶이는 폼)을 웹으로 옮긴 것이다.

> 버튼줄·이벤트·팝업 연동까지 묶은 완성품은 `../crudbuttons/`(CrudButtons)다.
> 새 입력폼은 보통 CrudJs를 직접 쓰지 않고 crudbuttons를 쓴다(`../crudbuttons/crudbuttons.md`).
> CrudJs를 직접 쓰는 경우: 버튼줄이 필요 없는 화면, 여러 레코드(커서) 화면, headless 계산용.

## 파일

| 파일 | 역할 |
|---|---|
| `crudjs.js` | `CrudJs` 클래스 본체. |
| `crudjs_fetch.php` | 서버 엔드포인트 **예시**(t매출). 새 테이블은 이걸(또는 `../crudbuttons/fLogin_amd_fetch.php`) 복사해 만든다. |
| `crudjs_entry.php` | 연습/시험 화면 — 단일 레코드 + 커서(여러 건) 기능을 버튼으로 눌러 볼 수 있다. |
| `../Crud.php` / `../Crud.md` | 서버 CRUD 엔진(스키마 추출, insert/update/delete). CrudJs는 이것과만 교신한다. |

접근 주소: `http://192.168.0.40/bros/src/Model/Crud/crudjs/crudjs_entry.php`

---

## 원리

### 1. 세 층
```
화면(DOM)  <── transObj2Dom / transDom2Obj ──>  CrudJs._data  <── fetch(JSON) ──>  xxx_fetch.php → Crud.php → DB
 id=필드명 컨트롤                                 (값의 진짜 저장소)                  (테이블·필드 화이트리스트 고정)
```
- **값의 진짜 저장소는 `_data` 객체**다(서버 `Crud::$data`와 같은 역할). DOM은 그 값을 보여주는 곳일 뿐이다.
- `setValue(f, v)` → `_data[f] = v` → `transObj2Dom(f)`(화면에 씀).
- `getValue(f)` → `transDom2Obj(f)`(사용자가 친 값을 흡수) → `_data[f]` 반환.
- 화면에 **id 노드가 없는 필드**는 DOM에 만들지 않고 `_data`에만 있다(PK, 감춰야 할 필드 등).
  `showHidden: true`면 hidden input을 만들어 보여준다(디버깅용).
- `window`를 안 주면 화면 없이(headless) `_data`만으로 동작한다.

### 2. 스키마가 모든 것을 정한다
- 서버 `Crud::init(테이블, 화이트리스트)`가 information_schema에서 PK/타입/길이/NULL 여부를 읽어
  `{tableName, primaryKey, fields:{필드:{type, nullable, maxLength, default, isPrimaryKey…}}}`를 만든다.
- CrudJs는 `loadSchema()`(또는 생성자 `schema`)로 이걸 받아, **스키마에 있는 필드만** 다룬다.
  없는 필드에 `getValue/setValue`하면 예외.
- 그래서 **어떤 필드를 읽고 쓸 수 있는지는 서버(fetch 파일의 화이트리스트)가 정한다** — 클라이언트는 따라갈 뿐.

### 3. 서식은 CrudJs가 처리한다(화면 코드는 신경 안 씀)
| 타입 | 화면에 보일 때 | `_data`/서버로 보낼 때 |
|---|---|---|
| 숫자(int/decimal/money…) | 천단위 콤마 | 콤마 없는 숫자 |
| bit | 체크박스면 체크/해제(`1`/`'1'`/`true`/`-1` = 체크) | `1` / `0` |
| 날짜 | `YYYY-MM-DD`(시각 있으면 `YYYY-MM-DD HH:MM`), time은 `HH:MM` | 같은 형식. 서버의 dblib 형식(`Sep 30 2026 12:00:00:000AM`)은 받자마자 바꾼다 |

### 4. 저장 순서(save)
```
화면 값 전부 흡수(transDom2Obj) → validateSchema()(스키마 검사) → validate()(동기, 선택)
→ await save_before() → POST ?action=save → 받은 PK를 PK 필드에 넣음 → save_after()
```
- 중간에 하나라도 걸리면 서버로 안 보내고 `false`. 이유는 `getError()`.
- 서버는 **PK가 있으면 UPDATE, 없으면 INSERT**(`Crud::save()`).

### 5. 이벤트 훅 — 인스턴스에 함수를 대입한다
```js
crud.save_before = function () { ... };   // false를 돌려주면 취소
crud.save_after  = function () { ... };
```
| 훅 | 부르는 메서드 |
|---|---|
| `save_before` / `save_after` | `save()` |
| `delete_before` / `delete_after` | `deleteRecord()`, `deleteBulk()` |
| `selectRecord_before` / `selectRecord_after` | `selectRecord()`, `loadRecord()` |
| `clear_before` / `clear_after` | `clear()` |
| `validate` (+ `crud._validationMessage`) | `save()` — 스키마 검사 다음, save_before 앞 |

- **`_before`는 `await`로 기다린다** — 동기 함수도, `async function`도 된다. 결과가 `=== false`일 때만
  취소(`undefined`/`true`는 통과). 서버에 물어보는 검사(중복 확인 등)도 `_before` 안에서 하면 된다.
- 취소 이유를 보여주려면 `_before` 안에서 `crud._lastError = '…'`를 넣고 `false`. 안 넣으면 기본 문구.
- `_after`와 `validate`는 기다리지 않는다(동기).
- `selectRecords()`, `saveBulk()`, `moveXxx()`에는 훅이 없다.

### 6. 모든 서버 작업은 Promise<boolean>
`save / deleteRecord / selectRecord / loadRecord / clear / loadSchema / selectRecords`는
`Promise<boolean>`을 돌려준다(`clear`·`loadRecord`도 `_before`를 기다리느라 Promise다).
`false`면 `getError()`로 이유를 본다.
```js
ok = await crud.save();
if (!ok) {
    alert('저장 실패: ' + crud.getError());
}
```

---

## 사용법

### 1. 화면 — id = 필드명
```html
<input type="text" id="code" readonly>      <!-- PK -->
<input type="text" id="이름">
<input type="checkbox" id="사용여부">       <!-- bit -->
<input type="date" id="입사일">
<textarea id="메모"></textarea>
```

### 2. 만들고 연결하기
```js
var crud = new CrudJs({
    fetchUrl: '<?= APP_BASE_URL ?>/workspace/xxx/xxx_fetch.php',
    window: window                 // 이 페이지의 컨트롤에 연결. iframe 엘리먼트를 주면 로드를 기다렸다 연결
});

async function xxStart() {
    if (!(await crud.loadSchema())) {
        alert(crud.getError());
        return;
    }
    await crud.selectRecord(12);   // PK 12를 화면에 채운다. 신규면 await crud.clear();
}
```

### 3. 주요 메서드
| 메서드 | 하는 일 | 돌려줌 |
|---|---|---|
| `loadSchema()` | 서버에서 스키마를 받는다 | Promise<boolean> |
| `getValue(f)` / `setValue(f, v)` | 값 읽기/쓰기(화면과 자동 동기) | 값 / 없음 |
| `getAllValues()` | 스키마의 모든 필드 값 `{필드: 값}` | 객체 |
| `selectRecord(pk)` | PK로 한 건 조회 → 화면에 채움 | Promise<boolean> |
| `loadRecord(row)` | 이미 가진 행 객체를 서버 왕복 없이 화면에 채움 | Promise<boolean> |
| `save()` | 저장(PK 있으면 UPDATE, 없으면 INSERT) | Promise<boolean> |
| `deleteRecord()` | 지금 PK 레코드 삭제 | Promise<boolean> |
| `clear()` | 모든 값을 비움(신규 상태) | Promise<boolean> |
| `validateSchema()` | 스키마 검사만 돌림 | 오류 문구 배열 |
| `getError()` | 마지막 실패 이유 | 문자열 |
| `getJson()` | 지금 들고 있는 값 `{schema, cursor, data}` JSON(디버깅) | 문자열 |
| `setWindow(win 또는 iframe)` | 연결할 화면을 바꾸거나 늦게 연결 | 없음 |

### 4. 여러 레코드(커서) — ADO Recordset과 같은 개념
```js
await crud.selectRecords(매출code);   // 서버 액션이 그 값으로 거른 여러 건. 첫 행이 화면에 뜬다
crud.moveNext();                       // moveFirst / moveLast / movePrevious, bof() / eof()
crud.markDeleted();                    // 지금 행을 삭제 표시(서버엔 아직 안 감)
var result = await crud.saveBulk();    // 고친 행(dirty) + 삭제 표시 행을 한 번에 → {results, deleteResults} 또는 null
```
- 커서를 옮길 때 화면에서 고친 값이 있으면 그 행이 자동으로 dirty가 된다(`isDirty()`).
- `recordCount()`, `getCursorPosition()`, `isDeleted()`, `getRecordSet()`, `deleteBulk(pks)`도 있다.

### 5. 생성자 옵션
| 옵션 | 기본 | 뜻 |
|---|---|---|
| `fetchUrl` | (필수) | 서버 엔드포인트 주소 |
| `schema` | 빈 스키마 | 페이지를 그릴 때 서버가 스키마를 심어 주면 `loadSchema()` 생략 가능 |
| `window` | 없음(headless) | 연결할 화면 — `Window` 또는 `<iframe>` 엘리먼트(같은 출처만) |
| `containerId` | body | `showHidden` 노드를 붙일 곳 |
| `showHidden` | false | 화면에 없는 필드를 hidden input으로 만듦(디버깅) |
| `actionNames` | 없음 | `action=` 이름 바꾸기 — 예 `{save: 'save매출', schema: 'getSchema매출'}` (fetch 파일 하나를 여러 테이블이 나눠 쓸 때) |
| `selectRecordParam` | `'pk'` | `selectRecord(key)`가 실어 보낼 파라미터 이름 |
| `selectRecordsParam` | selectRecordParam | `selectRecords(값)`이 실어 보낼 파라미터 이름 |

---

## 서버 계약 — xxx_fetch.php

| action | 메서드 | 받는 것 | 응답 |
|---|---|---|---|
| `schema` | GET | – | `{success, schema}` |
| `selectRecord` | GET | `&pk=` | `{success, schema, cursor:0, data:[행]}` |
| `selectRecords` | GET | (선택) `&<selectRecordsParam>=값` | `{success, schema, cursor, data:[…]}` |
| `save` | POST | JSON 행 `{필드: 값}` | `{success, pk}` |
| `delete` | POST | `&pk=` | `{success, affected}` |
| `saveBulk` | POST | `{data:[…], dirty:[인덱스…], deleted:[PK…]}` | `{success, results:[…], deleteResults:[…]}` |
| `deleteBulk` | POST | `{deleted:[PK…]}` | `{success, deleteResults:[…]}` |

실패는 전부 `{success:false, error:'…'}`. 쓰지 않는 action은 안 만들어도 된다 — crudbuttons는
`schema / selectRecord / save / delete` 네 개만 쓴다. (예시 `crudjs_fetch.php`에는 `deleteBulk`가 없다.)

**서버 규칙(보안)**
- **테이블명은 fetch 파일 안에 고정**한다. 클라이언트가 고르게 하지 않는다.
- `Crud::init(테이블, 화이트리스트)`로 다룰 필드를 좁힌다(PK는 자동 포함). 스키마에 없는 키는 `loadArray()`가 무시한다.
- 클라이언트가 보낸 **SQL 조각(WHERE 등)은 절대 받지 않는다** — 값 하나만 받고, 어느 컬럼에 어떻게 쓸지는 서버가 정한다.
- `Login::isAlive()`로 로그인을 확인한다. (예시 `crudjs_fetch.php`는 연습용이라 로그인·레코드 단위 권한 검사가
  빠져 있다 — 실사용 fetch는 `../crudbuttons/fLogin_amd_fetch.php`를 본뜬다.)

---

## AI·개발자가 고칠 때 지킬 것

- **값은 `_data`가 기준**이다. DOM을 직접 읽고 쓰지 말고 `getValue/setValue`를 쓴다.
- **훅 규칙을 깨지 않는다**: `_before`는 `await` 후 `=== false`만 취소. 새 메서드에 `_before`를 부르면 같은 방식으로.
- 서버 작업 메서드는 `Promise<boolean>`(벌크는 결과 객체 또는 null)을 유지한다. 실패 이유는 `_lastError`에 넣는다.
- 스키마/서식(콤마·날짜·bit) 처리는 CrudJs 안에서 끝낸다 — 화면 코드에 서식 코드를 넣지 않는다.
- 이 파일(crudjs.js)은 원래 스타일(`.then` 체인, `var`, `forEach` 일부)로 쓰여 있다 — 고칠 때 주변 스타일을 따른다.
  화면 쪽(entry/amd) JS는 VBA식 JS 규칙(`../crudbuttons/crudbuttons.js` 맨 위 주석)으로 쓴다.
- 경로: 이 폴더는 `src/Model/Crud/crudjs/`. PHP에서 Crud.php는 `dirname(__DIR__) . '/Crud.php'`,
  스크립트는 `APP_BASE_URL . '/src/Model/Crud/crudjs/crudjs.js'`.

## 변경 기록
- 2026-09-14 구현(설계: `md/2026-09-14-crudjs-구현.md`). 저장소를 DOM에서 `_data`로 옮김, 커서/벌크 추가.
- 2026-09-15 숫자 콤마, `selectRecordParam`/`selectRecordsParam`.
- 2026-10-30 `src/Model/Crud/crudjs/` 폴더로 이동. `_before` 훅을 `await`하도록 변경(`clear`·`loadRecord`도 Promise 반환).
