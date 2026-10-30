# bros 프로젝트

Access/VBA로 업무 프로그램을 만들던 개발자가 같은 방식(바운드 폼, 폼 이벤트, 모듈)을 웹으로
옮기는 프로젝트다. **목표는 개발자가 AI가 쓴 코드를 끝까지 읽고 고칠 수 있는 것(코드 장악)**이다.
짧고 영리한 코드보다, 조금 길어도 위에서 아래로 읽히는 코드를 쓴다.

## 원칙: 블랙박스 최소화
AI는 블랙박스를 만들지 않는다. 새 코드는 둘 중 하나여야 한다.
- **화면 코드** — 아래 VBA식 규칙대로 써서 개발자가 직접 읽고 고칠 수 있다.
- **부품·엔진 코드** — 그 폴더의 md에 사용법·원리·계약이 적혀 있고, 시험 화면(`*_entry.php`)으로 동작을
  확인할 수 있다.

외부 라이브러리처럼 속을 알 수 없는 것은 블랙박스로 인정하되, **쓰는 범위를 md에 적어 경계를 좁게** 가둔다.
목표는 "블랙박스 0"이 아니라 **"블랙박스가 어디 있는지 알고, 그 경계가 좁다"**이다.

이를 위해 AI는:
- 이미 있는 부품(md 참고)을 먼저 쓰고, 같은 일을 하는 코드를 새로 만들지 않는다.
- 부품으로 안 되는 요구가 오면 조용히 우회 코드를 쓰지 말고, **"부품을 늘릴지, 이 화면만 예외로 둘지"**를
  개발자에게 묻는다.
- 규칙을 벗어나야 하는 코드를 썼다면 어디를, 왜 벗어났는지 개발자에게 알린다.
- 부품 코드를 고치면 그 폴더의 md도 같이 고친다(코드와 md가 어긋나면 그 자체가 블랙박스다).

## 원칙: 업무 솔루션은 겉모양이 전부가 아니다
이 프로젝트는 홈페이지가 아니라 **업무 솔루션**이다. 실패가 화면에 보이지 않는다 — 금액이 틀리게 저장되고,
재고가 어긋나고, 권한이 새도 화면은 멀쩡하다. 그래서 "해보니 화면이 된다"는 완료 기준이 아니다.
**기준은 "데이터가 맞는가, 그리고 틀렸을 때 개발자가 고칠 수 있는가"**다.

추구하는 세 가지(하나로 이어진다 — "문제가 생겼을 때 AI 없이도 원인을 찾아 고칠 수 있는가"):
1. **개발자의 코드 장악력**을 최대한 높인다.
2. **블랙박스를 최소화**한다(위 원칙).
3. **디버깅을 개발자가 직접 할 수 있는 수준**으로 만든다.

이를 위해 코드에 디버깅 장치를 남긴다:
- 실패하면 **이유를 남긴다** — 화면에 "저장 실패: <이유>"(`getError()`, `ppara.error`), 서버는
  `{success:false, error:'…'}`. 오류를 조용히 묻지 않는다.
- 부품마다 **시험 화면**(`*_entry.php`)을 두어 따로 눌러 볼 수 있게 한다.
- 창 사이 흐름은 **`action` 한 통로** — 중단점 하나로 흐름이 보이게.
- 데이터를 바꾸는 작업(저장·삭제)은 누가·언제·무엇을 바꿨는지 **추적할 수 있게** 한다(서버 로그 등 — 추가 예정).

작업 순서: **시험은 빠르게, 운영 코드는 장악해서.** 실패해도 되는 곳(시험 화면, 연습 폴더)에서는 빨리 해보고
확인한다. 업무 화면·부품에 올리는 코드는 위 원칙으로 읽히고, 설명되고, 디버깅할 수 있어야 한다.

# VBA식 개발 규칙 (모든 AI 작업에 적용)

## 1. VBA식 JS — 화면 코드(entry, amd, 화면 전용 JS)는 반드시 이렇게 쓴다
| VBA | JS로 쓰는 법 |
|---|---|
| `Dim x` (맨 위) | `var x;` — 함수 맨 위에 모아 선언. `let`/`const` 안 씀 |
| `Sub` / `Function` | `function` — 화살표 함수(`=>`) 안 씀 |
| `Me` | 안쪽 function에서 자기 객체가 필요하면 `var self = this;` |
| 인자 | 매개변수 이름은 `p`로 시작(`pcmd`, `pparams`, `psender`) |
| `If … Else` | `if / else`로 풀어 쓴다 — 삼항(`a ? b : c`), `a && b()`, `!!x` 같은 줄임 안 씀 |
| `For i = …` | `for (var i = 0; i < n; i++)` — `forEach`/`map`/`filter` 안 씀 |
| `"a" & b` | `'a' + b` — 템플릿 문자열(`` `${}` ``) 안 씀 |
| 서버 기다리기 | `async / await` — `.then` 체인 안 씀 |
| `ByRef rError` | 객체에 담아 넘김 — `ppara.error = '…'` |
| `Cancel = True` | `return false` |

- 예외: 엔진·라이브러리 코드(`src/Model/Crud/crudjs/crudjs.js`, `src/js/` 부품, vendor)는 원래 스타일을
  따른다. 고칠 때는 주변 스타일에 맞추고, 대신 **사용법·원리를 md에 적어** 장악한다.
- 규칙은 **허용 목록**이다 — 위 표에 없는 새 문법은 쓰지 않는다. 개발자가 이해하고 허용하면 그때 이 표에
  한 줄씩 추가한다. 기준은 "VBA에 있었나"가 아니라 **"개발자가 읽고 바로 고칠 수 있나"**다.

## 1-1. VBA식 예외 — 안전장치는 VBA에 없어도 반드시 쓴다 (PHP / JS / .NET 공통)
VBA식으로 쓰더라도 아래는 문법 취향이 아니라 사고를 막는 장치라 **항상** 지킨다.
| 장치 | 규칙 | 이유 |
|---|---|---|
| 엄격 비교 (JS) | `===` / `!==`만 쓴다. `==` / `!=` 안 씀 | `==`는 `'0' == false`가 참이 되는 등 VBA 암묵 형변환보다 더 엉뚱하다 |
| SQL 자리표시자 (PHP / .NET) | 값은 항상 `?`(prepared statement)로 넘긴다. SQL에 값을 `+`/`.`로 이어 붙이지 않는다. 테이블·컬럼명은 서버 코드에 고정(사용자 입력 금지) | SQL 인젝션 방지 — `Crud.php`가 이미 이렇게 한다 |
| 출력 이스케이프 | PHP에서 HTML에 값을 찍을 때 `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`. JS에서 사용자 값은 `textContent`로 넣고 `innerHTML`에 이어 붙이지 않는다. PHP 값을 JS에 넘길 땐 `json_encode` | XSS 방지 — 남이 입력한 글이 화면에 나온다 |
| 타입 엄격 (PHP / .NET) | PHP 파일 맨 위 `declare(strict_types=1);`. .NET은 타입을 명시(`Option Strict On` / C# 명시 타입) | VBA `Option Explicit`보다 한 단계 더 — 타입 실수를 실행 전에 잡는다 |
| 오류 처리 | 실패할 수 있는 곳은 `try / catch`로 잡아 **이유를 남기거나 보여준다**. `On Error Resume Next`처럼 오류를 조용히 묻지 않는다(빈 catch 금지 — 묻을 때는 왜 묻는지 주석) | 웹은 여러 창·서버에 걸쳐 있어 묻힌 오류는 원인을 찾기 어렵다 |
| 로그인·권한 (서버) | 데이터를 읽고 쓰는 서버 파일(`*_fetch.php` 등)은 `Login::isAlive()`로 시작한다 | 주소만 알면 누구나 부를 수 있다 |


## 2. 창(페이지) 사이 소통
- **명령(시키기)·보고(알리기)는 상대 창의 `action(pcmd, pparams, psender)`로만** 한다.
- **이벤트(묻기·알림)는 이름 있는 함수**로 둔다 — Access 폼 이벤트 프로시저처럼
  (`beforeCrudSave`, `afterCrudSave` …). 정의 안 하면 "통과".
- **`action`은 나눠 넘기기만** 한다. case를 겹쳐(VBA `Case "a", "b"`) 종류별 하위 함수
  `action_xxx(pcmd, pparams, psender)`로 넘기고, 실제 일은 하위 함수에서 한다.
  ```js
  function action(pcmd, pparams, psender) {
      if (!pparams) {
          pparams = {};
      }
      switch (pcmd) {
          case 'openNew':
          case 'openSelected':
              return action_open(pcmd, pparams, psender);
          default:
              console.warn('xxx.action: 알 수 없는 pcmd - ' + pcmd);
      }
  }
  ```
- 아래로 명령: 대상 iframe의 `contentWindow.action(pcmd)`. 그냥 `action('x')`는 자기 페이지.

## 3. 이름
- **화면 전용 함수·변수에는 화면 접두사**를 붙인다 — 예: `fLogin_amd.php` → `flButtons`, `FL_FETCH_URL`,
  `crudbuttons_entry.php` → `ceOpen`, `CE_FORM_URL`. 전역 이름 충돌을 막고, 이름만 보고 "이 화면의 도우미"임을
  알게 한다. 이벤트 함수와 `action`/`action_xxx`에는 붙이지 않는다(이름으로 찾아 부르기 때문).
- 컨트롤 `id` = 테이블 필드명(CrudJs 바인딩).

## 4. 입력폼(CRUD) 만들기
- 입력폼은 `src/Model/Crud/crudbuttons/`(버튼줄 + CrudJs)를 쓴다. 먼저 `crudbuttons/crudbuttons.md`의
  "먼저 읽을 것"과 `crudjs/Crudjs.md`를 읽는다. 시범 예: `crudbuttons/fLogin_amd.php`.
- 저장 전 검사는 `beforeCrudSave` 한 곳에 위에서 아래로(가벼운 검사 먼저, 서버 확인은 `await`로 나중에).
- 입력폼 → 띄운 쪽 보고는 `psender.report(ptype, payload)` → 띄운 쪽 `action('amdReport', …)`.

## 5. 작업 방식
- 새 부품·규칙을 만들면 **그 폴더의 md에 사용법과 원리**를 적는다(다른 AI도 md만 읽고 알 수 있게).
- 코드를 쓴 뒤 위 규칙을 지켰는지 스스로 확인한다. JS는 `node --check`로 문법 검사.


# 권한 및 명령 실행 규칙

## 자동 승인 항목 (사용자 확인/컨펌 필요 없음)
- **파일 작업:** 현재 프로젝트 루트 디렉터리(`./`) 내부의 파일 읽기, 수정, 생성, 삭제 작업.
- **프로젝트 내부 명령:** 로컬 빌드, 테스트 및 패키지 실행 명령 (예: `dotnet test`, `dotnet build`, `npm test`, `npm run`).
- **PowerShell 명령:** 승인 없이 진행. 단, 아래 "위험 명령"은 예외로 확인을 받는다.

## 승인 필요 항목 (반드시 사용자에게 Confirm 요청)
- **외부 파일 접근:** 현재 프로젝트 루트 범위 밖의 파일 읽기 또는 수정.
- **원격 저장소 및 Git 작업:** `git push`, `git publish` 등 원격 저장소에 영향을 주는 작업.
- **시스템 명령:** 전역 패키지/도구 설치, 외부 네트워크 요청, OS 시스템 설정 변경.

## 실제 적용 위치 (`.claude/settings.local.json`)
위 규칙은 이 문서만으로는 Claude Code의 확인 창에 반영되지 않는다 — 실제 자동승인은
`.claude/settings.local.json`의 `permissions`가 결정한다. 규칙을 바꾸면 두 파일을 함께 고친다.
변경 후에는 Claude Code를 재시작해야 적용된다.

- **기본 모드:** `defaultMode: "acceptEdits"` — 파일 생성/수정은 확인 없이 진행.
- **allow (자동승인):**
  - 파일: `Read(/**)`, `Edit(/**)`, `Glob`, `Grep` — `/`는 프로젝트 루트 기준.
  - 문법 검사: `Bash(node --check *)`
  - 빌드/테스트(Bash): `dotnet build *`, `dotnet test *`, `npm test *`, `npm run *`
  - PowerShell: 모든 명령 (`PowerShell`) — 단, 아래 ask 목록이 우선한다.
- **ask (항상 확인):**
  - Bash·PowerShell 모두: `git push *`, `npm install -g *`
  - PowerShell 위험 명령:
    - Git 파괴적 작업: `git reset --hard`, `git clean`
    - 삭제: `Remove-Item`, `rm`, `del`, `erase`, `rd`, `rmdir`, `ri`, `Clear-Content`
    - 레지스트리/시스템 설정: `Set-ItemProperty`, `New-ItemProperty`, `Remove-ItemProperty`, `Set-ExecutionPolicy`
    - 네트워크: `Invoke-WebRequest`, `iwr`, `Invoke-RestMethod`, `irm`, `curl`, `wget`, `Start-BitsTransfer`
    - 임의 실행/프로세스/서비스: `Invoke-Expression`, `iex`, `Start-Process`, `Stop-Process`, `kill`, `Stop-Service`, `Set-Service`, `Restart-Computer`, `Stop-Computer`
    - 설치: `Install-Module`, `Install-Package`, `winget`, `choco`
- 목록에 없는 Bash 명령, 프로젝트 밖 파일 접근은 기본 동작대로 확인을 받는다.


## 응답 스타일

- 항상 존댓말로 응답한다.
