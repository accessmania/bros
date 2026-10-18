# talk.js

MSSQL `ttalk` 테이블을 쓰는 **카톡 모양 채팅 위젯**입니다. 특정 외부 데이터
(`type` + `parentcode`)에 달리는 대화를 보여주고 입력받습니다.
(참고 이미지: 사용자 바탕화면의 `카톡.png`, `카톡2.png`)

- 위쪽 **채팅영역**: 파란 배경 `#9bbbd4`
  - 내것(`whocode` = 로그인 code): 오른쪽 노란 말풍선, 이름 없음(툴팁도 없음)
  - 남것: 왼쪽 흰 말풍선, 위에 이름
  - 날짜가 바뀌는 지점마다 가운데에 날짜 구분선(`2026년 10월 1일 목요일`)
  - 말풍선 옆 시간: `오전 10:30` / `오후 3:12`
- 아래쪽 **입력영역**: 흰 배경
  - textarea(placeholder "메시지 입력"). Enter는 **줄바꿈**이고, 전송은 버튼으로만 합니다.
  - 하단 바: 새로고침(왼쪽), 전송(오른쪽). 전송은 글자가 있을 때만 노랗게 활성화됩니다.

## 파일

| 파일 | 역할 |
|---|---|
| `talk.js` | 위젯 클래스 `Talk`. 스타일은 `<style id="talk-default-styles">`(`@layer talk-defaults`)로 자동 주입되므로 CSS 파일이 따로 필요 없습니다. |
| `talk_fetch.php` | 조회/추가 엔드포인트. `mode=select`(GET, 기본) / `mode=insert`(POST). DB 접속은 `new DbContext('mssql')`입니다. |
| `talk_entry.php` | 연습용(자기 검증) 페이지. 로그인code/이름/type/parentcode/polling을 텍스트로 넣고 init하며, 각 pcmd 테스트 버튼과 로그창이 있습니다. |

`talk.js`는 자기 `<script src>` 경로(`document.currentScript`)를 기준으로
`talk_fetch.php`를 찾습니다. 그래서 어느 폴더의 화면에서 불러도 그대로 동작합니다.

## 특정 화면에 삽입하는 방법

```php
<?php
// 화면 상단(부트스트랩 이후) — 로그인 정보는 세션에서
$loginCode = (string) Login::getValue('code');
$loginName = (string) Login::getValue('이름');
$talkJsVersion = filemtime(SRC_DIR . '/js/talk/talk.js'); // 캐시 무효화용. 경로 상수는 화면에 맞게
?>
<!-- 높이가 정해진 컨테이너가 필요하다(위젯은 height:100%로 채움) -->
<div id="talk-root" style="width:400px; height:600px;"></div>

<script src="<?= APP_BASE_URL ?>/src/js/talk/talk.js?v=<?= $talkJsVersion ?>"></script>
<script>
    const talk = new Talk('talk-root');          // 컨테이너 id
    talk.action('init', {
        type: '견적',                              // ttalk.type: 채팅 종류
        parentcode: <?= json_encode((string) $parentCode) ?>, // ttalk.parentcode: 관련 외부데이터 code
        logincode: <?= json_encode($loginCode) ?>,
        loginname: <?= json_encode($loginName) ?>,
        pollSeconds: 0,                            // 자동갱신 주기(초). 0/생략 = 안 함(아직 미구현)
    });
</script>
```

- `init`을 하면 화면 틀을 그리고 곧바로 `show`(조회 + 그리기)까지 실행합니다.
  반환값은 `Promise<rows>`입니다.
- 같은 위젯에서 parentcode를 바꿔 다시 보여주려면 `action('init', {...})`을
  새 값으로 다시 호출합니다. 틀을 다시 그리므로 안전합니다.
- 한 화면에 여러 개를 띄울 때는 컨테이너 id를 다르게 해서 `new Talk(...)`을
  여러 번 만듭니다.

## 외부 API — `action(pcmd, pparams, psender)`

외부에서는 프로젝트 관례대로 `action()` 하나로만 호출합니다. 이름이 `_`로
시작하는 함수는 내부 전용입니다.

| pcmd | pparams | 동작 / 반환 |
|---|---|---|
| `init` | `{type, parentcode, logincode, loginname, pollSeconds}` | 틀 그리기(`_initDisplay`) 후 `show`. `Promise<rows>` |
| `show` | - | `_getrs`(조회) → `_showrs`(다시 그리기). `Promise<rows>` |
| `setvalue` | `{whocode, whoname, datetime, comment}` | 한 건을 채팅영역 끝에 추가합니다. 마지막 날짜와 다르면 날짜 구분선을 먼저 넣습니다. DB에는 저장하지 **않습니다**(표시만). |
| `setdate` | `{datetime}` | 날짜 구분선을 채팅영역 끝에 추가합니다. |
| `getmystyle` | `{name, time, comment}` | 내것 말풍선 html 문자열을 반환합니다(그리지는 않음). |
| `getyourstyle` | `{name, time, comment}` | 남것 말풍선 html 문자열을 반환합니다(그리지는 않음). |
| `clear` | - | 채팅영역을 비웁니다(마지막 날짜 기억도 초기화). |

버튼 동작은 다음과 같습니다.
- **전송**: `_send()` → `talk_fetch.php` `mode=insert`(POST) → 입력란 비우기 → `show`
  - 빈 내용은 보내지 않습니다.
  - 응답 전에 연타해도 한 번만 들어갑니다(`_sending`).
- **새로고침**: `show`
- 버튼 오류는 화면에 표시하지 않고 `console.error`에만 남깁니다.

## 내부 구조 (talk.js)

- `_getrs()`: `talk_fetch.php?mode=select&type=&parentcode=` → `this.rs`
- `_showrs()`: `clear` 후 `this.rs`를 순서대로 `_appendWithDate`
- `_appendWithDate(row)`: 날짜(`_ymd`)가 `this._lastDay`와 다르면 `setdate` 후 `_append`
- `_append(row)`: `_ismy(row)` ? `getmystyle` : `getyourstyle` → `insertAdjacentHTML`
- `_ismy(row)`: `String(row.whocode) === this.logincode`
  - DB의 whocode는 int, logincode는 문자열이라 문자열로 바꿔 비교합니다.
- `_date(p)`: `'yyyy-mm-dd hh:mi:ss'` → `오전/오후 h:mm`(0시는 `오전 12:..`, 12시는 `오후 12:..`)
- 모든 출력은 `_escape()`로 HTML 이스케이프합니다. 줄바꿈은 `white-space: pre-wrap`으로 살립니다.

## ttalk 테이블 (2026-10-02 확인)

| 컬럼 | 타입 | 비고 |
|---|---|---|
| `code` | int **identity**, PK | insert에서 제외합니다. 새 값은 `lastInsertId()`로 받습니다. |
| `type` | nvarchar(255) | 채팅 종류 |
| `parentcode` | int | 관련 외부데이터 code |
| `datetime` | **nvarchar**(255) | 날짜 타입이 아닙니다. `'yyyy-mm-dd hh:mi:ss'` 문자열로 저장하며, insert 시 `CONVERT(nvarchar(19), GETDATE(), 120)`(서버 시각)을 씁니다. |
| `whocode` | int | 작성자 로그인 code |
| `whoname` | nvarchar(255) | 작성자 이름 |
| `comment` | ntext | 내용 |
| `ts` | timestamp(rowversion) | 바이너리입니다. **select 결과에서 반드시 제거**합니다(아래 주의 참고). |

## talk_fetch.php

- `mode=select`: `SELECT * FROM ttalk WHERE type=? AND parentcode=? ORDER BY code ASC`
  - 결과는 JSON 배열입니다.
- `mode=insert`(POST 전용): `type, parentcode, whocode, whoname, comment`
  - 결과는 `{ok:true, code}`입니다.
- 오류 응답은 `{error}` 형태입니다. 잘못된 요청은 400, 서버 오류는 500을 돌려줍니다.
  - 400이 나는 경우: GET으로 insert, 빈 comment, 알 수 없는 mode

## 주의 / 겪은 문제

- **`ts` 컬럼 때문에 빈 응답이 나왔던 버그**
  - 증상: `select *`에 바이너리 `ts`가 섞이면 `json_encode`가 실패해서 `false`를 반환합니다. 그 결과 HTTP 200에 빈 응답이 나갔습니다.
  - 해결: `ts`를 제거하고, `JSON_THROW_ON_ERROR`로 실패를 500 에러로 드러나게 했습니다.
- **보안(실제 화면에 붙일 때 TODO)**
  - 지금은 insert의 `whocode/whoname`을 클라이언트가 보낸 값(init의 logincode/loginname) 그대로 저장합니다. 연습용이라 이렇게 두었습니다.
  - 실제 운영에서는 `talk_fetch.php`가 `Login::getValue('code')`, `Login::getValue('이름')` 세션 값으로 기록하도록 바꾸는 것이 안전합니다. 그대로 두면 위조가 가능합니다.
- 스크롤바 처리(thumb만 표시)
  - Chrome/Edge는 `::-webkit-scrollbar`로 처리합니다.
  - Firefox는 `@supports not selector(::-webkit-scrollbar)` 안에서만 `scrollbar-color`를 씁니다. Chrome에 표준 속성을 주면 webkit 스타일이 무시되기 때문입니다.

## 스타일 요약 (사용자가 지정한 값)

- 내것 말풍선: margin 5px, padding 7px, `#fef01b`, 13px 맑은 고딕, radius 7px, 오른쪽 위에 꼬리
- 남것 말풍선: margin-left 5px, padding 7px, `#ffffff`, 13px 맑은 고딕, radius 7px, 왼쪽 위에 꼬리
- 글자 크기
  - 이름 11px
  - 시간 11px `#333`(남것은 말풍선과 5px 간격)
  - 날짜 구분선 12px. 흰색 35% 반투명 알약(padding 6px 16px)에 달력 아이콘
- 전송 버튼: 활성 `#fee500`, 비활성 회색. 새로고침 버튼: 흰 바탕에 회색 테두리. 두 버튼 모두 70×30px

## 미구현 / 다음 단계

- `pollSeconds` 자동갱신: 값은 `this.pollSeconds`에 저장만 하고, 동작은 아직 구현하지 않았습니다.
- insert 작성자를 세션 기준으로 기록하기(위 보안 항목)
