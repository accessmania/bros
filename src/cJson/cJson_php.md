# cJson PHP 이식 계획

C# `cJson`(`Json/cJson.cs`, `Json/cJson.Paging.cs`, `Json/cJsonWhere.cs`, 설명은 `Json/cjson.md`)을 PHP로 옮기는 계획과 구성요소 목록.
(2026-11-13 작성 — 아직 코드 없음, 계획 단계)

## 목표

- C# cJson을 **전부** PHP로 옮긴다 (메모리 레코드셋 + where + Pseudo + DB 읽기 + 페이징).
- 궁극적으로 **cJson이 `src/Model/Crud/Crud.php` 안으로 들어가고**, Crud 안의 겹치는 부분은 제거한다.
- 이식 기간 동안 Crud·Board와 기능이 겹치는 것은 허용한다.
- Crud에 최종적으로 남는 것: **쓰기·변경 추적·요청값 받기** (`insert`, `update`, `save`, `deleteRecord`,
  `saveBulk_crudjs`, `loadPost`/`loadGet`/`loadClient`, `normalizeValueForField`).

## 이식 시 주의 (PHP라서 생기는 차이)

1. **저장소** — C# `JsonObject` 대신 PHP 연관배열 `$doc` 하나.
   - 빈 `fields: {}`가 `json_encode`에서 `[]`로 나가지 않게 처리 (JS·C# 형식 유지).
   - null 필드 / 없는 필드 구분은 `array_key_exists` (`isset`은 둘 다 false).
   - 숫자 표기(`1000.0`, 큰 정수, `"0.5"`)가 C#과 같은 `getValue()` 문자열이 되는지 시험.
   - `JSON_UNESCAPED_UNICODE` (한글 이스케이프 안 함).
2. **where 해석기** — C#은 람다(클로저) 조립. PHP는 **트리 배열 + `evaluate($tree, $row)` 함수 하나**로
   바꾼다 (VBA식 규칙, 중단점 하나로 계산 과정 추적).
3. **SQL 안전** — `{n}` 자리표시는 PDO 파라미터로 바인딩. 값을 SQL에 이어 붙이지 않는다.
   (`Crud::selectRecords($pWhere)`의 문자열 이어 붙이기는 cJson으로 대체되며 사라진다.)
4. **스타일** — `declare(strict_types=1)`, 변수 맨 위 모아 선언, 삼항 안 씀 (C# 원본이 이미 VBA식에 가깝다).
5. **오버로드 없음** — PHP는 같은 이름 함수를 둘 둘 수 없어 이름을 나눈다 (아래 "미정").

## 구성요소

PHP 이름은 `Crud.php`처럼 camelCase. → 표시는 Crud에 넣을 때 **Crud에서 제거할 부분**.

### 0. 상수·상태
| C# | PHP | 내용 |
|---|---|---|
| `DefaultKeyName = "code"` | `const DEFAULT_KEY_NAME` | 키 필드 기본 이름 |
| `PseudoPrefix`, `NewKeyPrefix` | `const PSEUDO_PREFIX`, `NEW_KEY_PREFIX` | `"Pseudo"`, `"Pseudo0_"` |
| `MaxPageSize = 1000` | `const MAX_PAGE_SIZE` | 페이지 크기 상한 |
| `doc` | `private $doc` | **유일한 저장소** (배열) |
| `dbContext` | `private $db` | DbContext (JSON에 안 들어감) |
| `lastSql` | `private $lastSql` | 마지막 실행 SQL |
| `whereFilter` | `private $whereTree` | 해석된 where (트리 배열) |
| `newKeySeq` | `private $newKeySeq` | `addNew` 번호 |

→ Crud: `$recordSet`, `$cursor`, `$schema`, `$tableName`, `$primaryKey` 제거 (`$doc` 하나로).

### 1. 생성·로드·출력
`__construct($pJson = '', $pSchema = null, $pDb = null)`, `load($pJson)`, `loadFrom(cJson $pOther)`,
`toJson($pIndented)`, `toJsonWhere($pIndented)`, `currentToJson($pIndented)`

→ Crud: `toJson`, `fromJson`, `loadArray` 일부

### 2. 스키마
`loadSchema($pSchema)` (JSON 문자열·배열 모두), `getSchema()`, `unloadSchema()`, `isLoadedSchema()`,
`getSchemaJson()`, `getKeyName()` / `setKeyName()`, `getFieldNames()`, `existsField($pField)`

→ Crud: `initModelClass`의 스키마 조립, `getSchema`, `getPrimaryKey`, `assertField`

### 3. 값 쓰기
`setValue($pKey, $pField, $pValue)` / `setValueCurrent($pField, $pValue)`, `addNew()`

→ Crud: `setValue`, `$touchedFields` (변경 추적 방식은 합칠 때 재설계)

### 4. 값 읽기
`getValue` / `getValueCurrent`, `getDouble…`, `getBool…`, `isNullValue…` (키 지정판·현재 행판),
`existsKey($pKey)`, `getCurrentFields($pSep)`

→ Crud: `getValue`, `getAllValues`

### 5. 여러 레코드 반영
`merge(cJson $pOther, $pAddIfMissing)`, `appendRecords($pJson)` — 둘 다 전부 검증 후 반영

### 6. 커서
`moveFirst`, `moveLast`, `moveNext`, `movePrevious`, `moveToKey($pKey)`, `moveTo($pIndex)`,
`eof`, `bof`, `getCursor`, `getCurrentKey`, `count`, `isEmpty`

→ Crud: `bof`, `eof`, `move*`, `recordCount`, `getCursorPosition`, `loadCursorRecord`

### 7. 필터·삭제
`setWhere($pWhere)`, `clearWhere()`, `hasWhere()`, `getWhere()`, `delete($pKey)`, `deleteWhere()`, `clear()`

### 8. Pseudo
`loadPseudo($pType = '1')`, `isCurrentPseudo($pType)`, `deletePseudo($pType)` — type `"0"`은 새 행 전용

### 9. DB 읽기
`setDbContext`, `getDbContext`, `loadSql(...)`, `loadSqlSchema(...)`, `loadSqlQuery(...)`, `getLastSql()`

내부: `prepareSchema`, `buildSelectSql`, `replacePlaceholders`, `bindParams`, `readRows`, `dbValueToNode`, `commitLoaded`

→ Crud: `init`의 스키마 읽기, `stripExcludedFields`, `applyWhitelist`, `filterRowToSchema`,
`selectRecord`, `selectRecords`, `sanitizeUtf8Row`

### 10. 페이징 (C# `cJson.Paging.cs`)
`loadSqlTablePage(...)`, `loadSqlQueryPage(...)`, `getTableColumns($pTable)`, `getQueryColumns($pSql, $pParams)`,
`isPaged`, `getPage`, `getPageSize`, `getTotalRows`, `getTotalPages`

내부: `calcPage`, `makePager`, `executeCount`, `wrapSubQuery`, `readColumns`, `kindOfType`, `pager`, `pagerInt`

※ `Board.php`(`initFromQuery`, `probeQueryColumns`, pager 계산)와 겹친다. Board 정리는 이번 범위 밖.

### 11. 공용 변환 (static)
`toText($v)`, `toDouble($v)`, `toBool($v)`

### 12. 내부 도우미
| 묶음 | 함수 |
|---|---|
| 저장소 접근 | `schemaRef`, `fieldsRef`, `dataRef`, `crudData` (배열 참조 `&` 사용 여부 결정 필요) |
| 키·커서 | `keyNameOf`, `setCursorRaw`, `isCursorValid`, `matches`, `nextIndex`, `prevIndex`, `findIndex` |
| 읽기 | `tryGetNodeByKey`, `tryGetNodeAtCursor` |
| 검사·쓰기 | `assertKeyArgument`, `assertWritableField`, `writeField`, `removeIndexes`, `isPseudoKey`, `toNode` |
| 구조 | `createEmptySchema`, `createEmptyDoc`, `normalizeSchema`, `normalizeDoc`, `validateDoc`, `take` |
| where | `compileWhere` (해석 + 스키마 필드 검사) |
| JSON 옵션 | `json_encode` 플래그 상수 하나로 대체 |

### 13. where 해석기 (별도 파일 `cJsonWhere.php`)
- 토큰: `tokenize`, `isValueExpected`, `isNameChar`, `error`
- 값: `fromNode`, `fromLiteral`, `tryNumber`, `compare`, `likeToRegex`
- 파서: `parseAll`, `parseOr`, `parseAnd`, `parseNot`, `parsePrimary`, `parseFunction`, `parseCondition`,
  `parseOperand`, `isReserved`, `inList`, `valueToBool`
- 실행: `evaluate($tree, $row)` (PHP에서 새로 추가 — 클로저 대신)

## 시험·문서

- **공용 시험 JSON** — 같은 JSON·같은 조작에 C#과 PHP가 같은 `toJson()`을 내는지 비교 (C# 콘솔 시험 25항목 참고).
- **`cJson_entry.php`** — JSON 입력, where 적용, 커서 이동 결과를 눌러 보는 시험 화면.
- 이식이 끝나면 이 문서를 PHP판 사용법 md로 바꾼다.

## 진행 순서

1. 미정 항목 결정
2. 0~8 (데이터 부분) 이식 + 시험 (C#과 결과 비교)
3. 13 where 해석기 이식 + 시험
4. 9~10 DB 읽기·페이징
5. Crud에 합치고 겹치는 부분 제거 (crudbuttons, crudjs_fetch 등 쓰는 쪽 확인)

## 미정

- [ ] 오버로드 이름: `setValue` / `setValueCurrent`처럼 나눌지, 인자 개수로 한 함수에서 나눌지
- [ ] PHP 7 호환 유지 여부 (`Crud.php`는 PHP 7 호환)
- [ ] 저장소 접근에 배열 참조(`&`)를 쓸지
- [ ] Crud에 합칠 때 변경 추적(`$touchedFields`) 방식
