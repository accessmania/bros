# CrudJs 구상 (아이디어 메모 — 미구현)

**상태**: 설계 논의 단계, 코드 없음. "작업은 하지말고 생각만 해보지"라는
전제로 나눈 대화를 기록만 해둔 것 — 실제 구현 여부/시점은 별도 지시 대기.

## 지시 원문

> 현지 crud 는 php 파일로 서버단에서 실행되고있지
> js 로 crud 를 만들고 , 화면상에 그려진 모든 컨트롤의 id 값을 필드명으로
> 대응되게하고
> js crud 에서 setvalue , getvalue 를 하면 해당 id 로 값이 쓰고 읽게한다.
> js crud 에서 save , delete , selectRecord 를 하면 서버의 crud 가 그걸
> 받아서 쓰기,읽기를 한다.
> save , delete 를 js 가 하면 서버의 crud 가 받아서 쓰기를 하고
> js crud 에서 selectRecord 를 하면 서버의 crud 가 응답하여 selectRecord
> 즉 id =필드값 에 일치하는 곳에 값을 뿌려준다.
>
> js crud 는 직접 db 를 조작하는 것이 아니라 서버의 crud 와 교신하고
> 쓰기와 읽기를 중계하는 역할을 한다.
>
> js crud 의 setvalue 하면 바로 id=필드값 에 써지고
> getvalue 하면 id 의 노드의 값을 읽어들인다.
>
> 그래서 save, delete 하면 폼의 값들이 서버의 crud 로 전달된다.
>
> selectRecord 하면 서버의 selectRecord 를 호출하고 호출된 결과를
> js crud 가 받아 화면에 표시한다.
>
> ---
> js crud 를 쓴다면 t매출 의 다양한 update 를 하나의 js crud 로
> 통합가능하다고 보는데
>
> ---
> 의견을 말해본다면?

## 제안 요약

브라우저에 `CrudJs`(가칭) 클래스를 하나 두고:

- 화면의 모든 입력/표시 컨트롤은 **id = 실제 DB 필드명**으로 짓는다.
- `setValue(field, value)` → `#field` 노드에 즉시 값을 쓴다(컨트롤 타입에
  맞게 — `<input>`/`<textarea>`는 `.value`, checkbox는 `.checked`, 그 외
  표시용 요소는 `.textContent` 등).
- `getValue(field)` → `#field` 노드의 현재 값을 그대로 읽어온다.
- `save()`/`delete()` → 화면에 매핑된 모든 필드값을 모아 서버로 보낸다.
- `selectRecord(key)` → 서버의 `selectRecord`를 호출하고, 응답으로 받은
  레코드를 `id=필드명` 규칙에 따라 화면에 자동으로 뿌린다.
- `CrudJs` 자신은 DB를 직접 만지지 않는다 — 항상 서버의 CRUD와 교신하는
  **중계자**일 뿐이다.

## 이미 있는 것 — 서버 절반은 이미 존재한다

이 프로젝트엔 정확히 이 그림의 "서버 crud" 역할을 하는 범용 클래스가 이미
있다: **`src/Model/Crud/Crud.php`**(스펙은 `Crud.md` 참고).

- `init($table)` — information_schema에서 PK/필드/타입/제약조건을 자동으로
  읽어온다(테이블마다 새로 안 짜도 됨).
- `setValue($field, $value)` / `getValue($field)` — 스키마에 없는 필드면
  예외.
- `selectRecord($pk)` — PK로 레코드 1건 조회, 필드값을 내부에 채움.
- `save()` — PK 유무로 `insert()`/`update()` 자동 분기.
- `deleteRecord($pk)` — PK로 삭제.
- `loadPost()`/`loadGet()`/`loadArray($row)` — 슈퍼글로벌 또는 임의
  배열에서 "스키마에 실제로 있는 필드만" 골라 반영.
- **touchedFields** — 클라이언트가 실제로 건드린 필드만 SQL에 싣는다(안
  보낸 필드는 SET/INSERT 컬럼 목록에서 아예 빠짐 — 기존 값 보존).
- 한글 필드명 안전(positional placeholder), `ts`/`__ts__`(rowversion류)
  쓰기 자동 차단, nullable 컬럼의 빈 문자열→NULL 정규화까지 이미 처리됨.

다만 지금은 두 가지 형태로만 노출돼 있다:

1. **`ActionCrud`**(`src/Action/ActionCrud.php`) — 단일 레코드,
   `$_POST['action']`=`save`/`delete`/`new`, 응답은 페이지 리다이렉트
   (`ActionResponder`를 안 넘기면 `RedirectActionResponder` 기본값) — 고전
   Board 폼 화면(1_1/1_2/1_3/2_1)용. 생성자 주석에 "JsonActionResponder
   등 다른 구현체를 넘기면 된다"는 확장 지점이 **이미 예고**돼 있지만
   실제 구현체는 아직 없음.
2. **`ActionBulk`** — 다중 행, JSON body, `AGGrid.js`의 `save()`가 이미 이
   계약으로 씀(그리드 전체를 한 번에 저장).

즉 **"단일 레코드 + fetch(JSON) + id=필드 자동 바인딩"** 형태는 아직 어느
쪽에도 없다 — `CrudJs`가 채울 자리는 정확히 이 빈틈이다. 새 서버 엔진을
만들 필요는 없고, `Crud`를 감싸는 JSON 응답 래퍼(`ActionCrud`가 이미
예고해둔 "JsonActionResponder" 자리) + JS 쪽 얇은 클래스 하나만 있으면
된다 — 생각보다 작은 작업.

## t매출에 적용해보면

### 깔끔하게 흡수되는 것

- **`update매출필드`**(`deco_amd_fetch.php::updateMaechulField()`) — 지금도
  "화이트리스트 8개 컬럼짜리 미니 제너릭 update"라 사실상 이 패턴의
  축소판. 그대로 일반화하면 이 함수/action 자체가 없어진다.
- **`update매출웨이터`/`update매출영업`** — "픽커 하나에서 컬럼 2개
  (code+이름)를 한 번에 `setValue` 두 번 → `save()` 한 번"으로 자연스럽게
  흡수된다.
- **`add매출_내용`**(전표 승인) — `t매출_내용`을 대상으로 한 `Crud`
  인스턴스 하나로 그대로 대체 가능(행 하나 = 인스턴스 하나 = 지금
  `insertMaechulNaeyong()`가 하는 것과 같은 모양, `AGGrid.js`/`ActionBulk`
  가 이미 여러 행에 대해 하고 있는 것의 "행 1개짜리" 버전).
- **`selectRecord`** — `deco_amd_amd.php::selectRecord(code)`가 지금
  수동으로 하는 "서버 조회 → `showTop()`이 필드별 id에 뿌리기"를, 컨트롤
  id를 실제 컬럼명과 맞추면 `Crud::selectRecord()` + 자동 렌더링으로
  그대로 대체할 수 있다(`SHOW_매출_FIELD_MAP`이 통째로 필요 없어짐 —
  또는 "id ≠ 컬럼명"을 의도적으로 유지하고 싶다면 그 매핑표 자체가 id쪽
  스펙으로 남을 뿐, 사라지지는 않음).

### 흡수되지 않는 것 — "제네릭 relay의 한계"가 아니라 "도메인 규칙은 어딘가 남는다"

- **계산값**(`calc()`가 만드는 금액/금액_카드/잔금/잔금_카드) — 사용자가
  입력하는 값이 아니라 매출_내용을 합산해 계산해낸 값이라 id=필드
  바인딩으로는 절대 안 나온다. 계산 로직은 여전히 JS에 남고, 계산 후
  `crud.setValue(4개) + save()`로 **저장 단계만** 일반화된다.
- **서버 강제값**(아가씨여부/밴드여부/아가씨퇴실여부 = 항상 0) — 순수
  제네릭 relay는 "클라이언트가 못 바꾸게 서버가 강제/거부"하는 규칙을
  표현할 자리가 없다. 테이블별 후크나 화이트리스트 계층이 결국 하나 더
  필요하다(지금 `insertMaechulNaeyong()`이 SQL에 `?` 자리 자체를 안 둬서
  막는 방식과 동등한 것을 어딘가엔 둬야 함).
- **여러 단계 오케스트레이션**("add매출_내용 하고 나서 calc_save 호출")
  — 이건 CRUD가 제네릭해져도 그대로 JS(또는 서버 액션 조합)에 남는
  워크플로우 로직이다.
- **컨트롤 타입 다양성** — checkbox의 `checked`, `<input>`의 `value`,
  텍스트만 뿌리는 요소의 `textContent`, ag-grid 셀처럼 애초에 단일
  스칼라가 아닌 것 — `getValue(id)`/`setValue(id, v)`는 태그/타입별 분기가
  필요하다(어렵지 않지만 "id 하나로 완전 무설정"은 아님).
- **표시 전용/계산 전용 요소**는 저장 대상에서 명시적으로 빼야 한다(전부
  자동 수집하면 안 됨) — 완전 zero-config는 아니고, "이 id들만 저장
  대상"이라는 최소한의 선언은 필요.

## 결론 / 다음 단계 제안(실행은 안 함)

- 방향은 타당하고, 이 프로젝트(Access→PHP 전환)의 결이 원래 "바운드 폼"
  방식이라 잘 맞는다 — Access 폼의 컨트롤이 필드에 직접 바인딩되던 것과
  같은 그림을 웹으로 그대로 옮기는 셈.
- "t매출의 여러 update를 하나로 통합 가능"은 맞는 방향이나, 정확히는
  "여러 개의 bespoke 서버 함수/action이 하나의 제네릭 엔진 호출로
  줄어든다"는 뜻 — 계산/검증/오케스트레이션 로직까지 사라지는 건 아니다.
- 실제로 손대게 되면 제안하는 순서:
  1. `ActionCrud`에 JSON 응답용 `ActionResponder` 구현체 하나 추가(서버
     쪽 마지막 빈틈).
  2. JS `CrudJs` 클래스 신설(fetch 래퍼 + id 기반 get/set + 컨트롤 타입
     분기 + save/delete/selectRecord).
  3. 첫 적용 대상은 `update매출필드`(이미 이 패턴의 8할) — 컬럼 1개
     사례로 검증.
  4. 웨이터/영업(컬럼 2개 조합, `setValue` 두 번 + `save` 한 번)으로
     확장.
  5. 전표(`add매출_내용`)/calc 흐름은 오케스트레이션이 얽혀 있어 마지막에.

## 관련 파일

- `src/Model/Crud/Crud.php` / `Crud.md` — 이미 있는 서버 crud 엔진.
- `src/Action/ActionCrud.php` — 단일 레코드 write action(현재는
  redirect 응답만, JSON 응답 확장 지점이 이미 설계돼 있음).
- `src/js/aggrid/AGGrid.js`(`save()`) / `ActionBulk` — 다중 행 JSON 저장의
  선례(계약 형태 참고용).
- `public/test/deco/deco_amd_amd.php` — 이 아이디어가 가장 먼저 들어맞을
  실사용 화면(현재 `SHOW_매출_FIELD_MAP` + 필드별 `_setValueXxx()`/
  `_getValueXxx()`를 손으로 짜둔 상태).
- `public/test/deco/deco_amd_fetch.php` — t매출 관련 현재 bespoke
  action들(`update매출필드`/`update매출웨이터`/`update매출영업`/
  `add매출_내용`/`calc_save`)이 모여 있는 곳 — 통합 대상.
❯ 테이블내의 모든 필드를 입력폼에 다 나열하고,
  안보여야하는 필드는 hidden 처리한다.
