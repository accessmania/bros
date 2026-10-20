# bros 프로젝트


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
