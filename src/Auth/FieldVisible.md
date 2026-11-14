# FieldVisible — 필드감추기 (권한별로 그리드 컬럼 숨기기)

Access `init_fieldvisible()`을 옮긴 부품이다. 로그인한 계정의 권한에 따라 화면 그리드에서 특정 필드를 숨긴다.

## 데이터
| 테이블 | 역할 |
|---|---|
| `tAuth.fieldvisiblecode` | 이 권한이 쓰는 필드감추기 그룹. 로그인할 때 세션(`Auth::getValue('fieldvisiblecode')`)에 담긴다 |
| `tfieldvisible_parent` | 필드감추기 그룹(명칭/메모). `code` = 위 `fieldvisiblecode` |
| `tfieldvisible` | 그룹의 필드 한 줄씩: `tablename`, `fieldname`, `nickname`(화면 이름), `visiblefalse`(참=숨김), `parentcode` |

관리 화면: 메뉴 "기초자료 > 필드감추기"(`basicdata/fieldvisible/fieldvisible_entry.php`).

## 사용법 (두 단계)
1. **init (서버, 화면 PHP 맨 위)** — `FieldVisible::init($db)`가 `tfieldvisible WHERE parentcode = 세션의 fieldvisiblecode`
   레코드 **전부**를 돌려준다. 화면은 이것을 `json_encode`로 JS 변수에 넣는다.
   ```php
   include_custom('FieldVisible');
   $fieldVisible = FieldVisible::init($db);   // ['success', 'error', 'recs']
   ```
   ```js
   var XX_FIELDVISIBLE = <?= json_encode($fieldVisible, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
   ```
2. **Applyds (공용 JS, `src/Auth/FieldVisible.js`)** — 화면이 `<script src=".../src/Auth/FieldVisible.js">`로
   불러오고, 그리드가 만들어진 뒤(`onReady`) `Applyds(grid, '화면 nickname', XX_FIELDVISIBLE)`를 부른다.
   recs 가운데 `nickname`이 같고 `visiblefalse`가 참인 `fieldname` 컬럼을 숨긴다(`gridApi.setColumnsVisible`).
   돌려주는 값은 숨긴 필드명 배열(실패하면 `false`). Access `applyDs("문의내용", Me.subds.Form)`와 같은 일이다.
   ```js
   onReady: function (pgrid) {
       Applyds(pgrid, '문의내용', IDT_FIELDVISIBLE);
   },
   ```

## 계약
- `init`은 실패해도 예외를 던지지 않는다. `success:false` + `error`에 이유를 담는다:
  - 계정에 `fieldvisiblecode`가 없음(VBA의 "필드보기권한이 설정되지 않았습니다")
  - DB 읽기 오류
  이때 화면은 그대로 뜨고 감추기만 안 된다. `Applyds`가 콘솔에 이유를 남긴다.
- `fieldname`은 그리드 컬럼의 `field`(= 테이블 필드명)와 같아야 한다. 그리드에 없는 필드명은 건너뛰고 콘솔에 경고한다.
- 세션은 로그인할 때만 채워진다. 권한의 `fieldvisiblecode`를 바꾸면 다시 로그인해야 반영된다.
  `tfieldvisible`의 숨김 체크는 화면을 다시 열면 바로 반영된다(화면을 열 때마다 읽는다).

## 한계 (중요)
**화면에서 숨기는 것일 뿐 권한 보안이 아니다.** 숨긴 컬럼의 값도 `*_fetch.php` 응답에는 그대로 들어 있어서
개발자도구로 볼 수 있다. 또 전체 필드 검색(`?q=`)은 숨긴 필드로도 걸린다. 진짜로 막아야 하는 필드(원가, 마진 등)는
fetch가 응답에서 그 필드를 빼야 한다(추가 예정).

## 쓰는 화면
| 화면 | nickname |
|---|---|
| `workspace/inquiry/inquiry_문의_entry.php` (문의 목록) | `문의` |
| `workspace/inquiry/inquiry_문의내용_entry.php` (문의내용 목록, v문의내역) | `문의내용` |
| `workspace/inquiry/inquiry_견적_entry.php` (견적 목록, v문의_견적산출) | `견적` |
| `workspace/inquiry/inquiry_수주_entry.php` (수주 목록, v수주내역) | `수주` |
| `workspace/inquiry/inquiry_발주_entry.php` (발주 목록, v발주내역) | `발주` |
| `workspace/inquiry/inquiry_선적미입고_entry.php` (선적미입고 목록, v선적미입고) | `선적미입고` |
| `workspace/inquiry/inquiry_납품출하대기_entry.php` (납품출하대기 목록, v납품출하대기) | `납품출하대기` |
| `workspace/inquiry/inquiry_송금_entry.php` (송금전체·원화송금관리·외화송금관리, v송금관리) | `발주` (Access와 같음) |
| `workspace/inquiry/inquiry_미수금_entry.php` (미수금전체·미수금>0·USD미수금>0, v미수금관리) | `수주` (Access와 같음) |
| 같은 화면 `?g=B…` (미수금B 3종 — 위 그리드만, 아래 그리드는 안 함) | `수주` (Access 미수금B는 적용 안 함 — 웹은 위 그리드에 적용) |
