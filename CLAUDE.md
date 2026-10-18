# \\192.168.0.40\web\bros 프로젝트


# 권한 및 명령 실행 규칙

## 자동 승인 항목 (사용자 확인/컨펌 필요 없음)
- **파일 작업:** 현재 프로젝트 루트 디렉터리(`./`) 내부의 파일 읽기, 수정, 생성, 삭제 작업.
- **프로젝트 내부 명령:** 로컬 빌드, 테스트 및 패키지 실행 명령 (예: `dotnet test`, `dotnet build`, `npm test`, `npm run`).

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
  - 빌드/테스트(Bash·PowerShell 모두): `dotnet build *`, `dotnet test *`, `npm test *`, `npm run *`
- **ask (항상 확인):** `git push *`, `npm install -g *` (Bash·PowerShell 모두)
- 목록에 없는 쉘 명령, 프로젝트 밖 파일 접근, 외부 네트워크 요청은 기본 동작대로 확인을 받는다.


## 응답 스타일

- 항상 존댓말로 응답한다.
