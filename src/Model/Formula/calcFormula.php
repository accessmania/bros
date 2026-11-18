<?php
declare(strict_types=1);

/**
 * calcFormula — Access 수식(tb수식_문의/수주/발주/견적의 "수식" 칸)을 웹에서 계산하기 위한 구성 함수들.
 *
 * Access는 getCalcStr()에서 [필드]를 폼 값으로 바꾼 뒤 Eval()로 계산한다. 웹에서는 Eval이 없으므로
 * Eval이 해 주던 함수·연산을 여기에 Access 규칙 그대로 만들어 둔다. (수식 문자열을 읽어 이 함수들을
 * 부르는 해석기와, 화면이 부르는 창구는 다음 단계 — calcFormula.md "앞으로" 참고.)
 *
 * 값 표현 (Access Variant → PHP):
 *   Null  → null        Empty / 빈 글자 → ''        숫자 → int|float        True/False → true/false
 *
 * 함수 (사용법·원리는 같은 폴더의 calcFormula.md):
 *   수식 테이블 전체 계산                calcAll(db, 수식 테이블, 폼 값들) — 순서대로 getCalcStr, 결과를 다음 수식이 씀
 *   수식 하나 계산(Access getCalcStr의 웹판) getCalcStr(db, 수식, 필드들, 폼 값들) — 해석기는 그 아래 private expr* 함수들
 *   사용자 함수(Access 모듈과 같은 규칙)  isnull_, nz_, cdata
 *   Access 함수                          iif, nz, round, isNull
 *   연산(Null 전파 포함)                  add(+) sub(-) mul(*) div(/) intDiv(\) mod(Mod) pow(^) neg(-x)
 *                                         concat(&) compare(= <> < > <= >=) like(Like) andOp orOp notOp
 *   도메인 함수(DB 조회, 허용 테이블만)    dlookup, dsum, dcount, davg, dmin, dmax
 *   도우미                               isNumeric, toNumber, toText, isTrue
 *
 * 실패는 RuntimeException으로 이유를 남긴다(형식 불일치, 0으로 나누기, 허용 안 된 테이블 등).
 * PHP 7 호환 문법만 쓴다(Crud.php와 같음).
 *
 * 불러오기: include_custom('DbContext', 'Crud', 'calcFormula') — 도메인 함수가 Crud(스키마 조회)를 쓴다.
 */
require_once __DIR__ . '/../Crud/Crud.php';

class calcFormula
{
    /**
     * 도메인 함수(DLookup 등)가 읽을 수 있는 테이블 — 이 목록 밖은 거절한다.
     * 수식은 화면에서 고칠 수 있으므로, 수식이 아무 테이블이나 읽지 못하게 막는다.
     * 수식에 새 테이블이 필요하면 여기에 한 줄 더하고 calcFormula.md에도 적는다.
     */
    const DOMAIN_TABLES = ['tb운임표'];
    // ==================================================================
    // 사용자 함수 — Access 모듈의 같은 이름 함수와 같은 규칙
    // ==================================================================

    /**
     * isnull_(x) — Null과 Empty(빈 글자)를 똑같이 "없음"으로 본다.
     * 0은 Null이 아니다(값이 있는 것). 공백 글자(' ')도 빈 글자가 아니다(Trim하지 않는다).
     */
    public static function isnull_($pvalue): bool
    {
        if ($pvalue === null) {
            return true;
        }
        if ($pvalue === '') {
            return true;
        }
        return false;
    }

    /**
     * cdata(x) — 숫자형으로 강제로 바꾼다. 숫자가 아니면 0. Null·빈 글자도 0.
     * 천단위 콤마가 있는 글자('1,000')는 숫자로 본다. True는 -1, False는 0(VBA와 같음).
     * @return int|float
     */
    public static function cdata($pvalue)
    {
        if (!self::isNumeric($pvalue)) {
            return 0;
        }
        return self::toNumber($pvalue);
    }

    /**
     * nz_(x, 기본값) — Nz와 같되 Null과 Empty(빈 글자)를 똑같이 본다(isnull_과 같은 판단).
     * isnull_(x)이면 기본값, 아니면 x 그대로. 기본값을 생략하면 ''(Nz와 같음). 0은 값이 있는 것이라 그대로.
     * @return mixed
     */
    public static function nz_($pvalue, $pdefault = '')
    {
        if (self::isnull_($pvalue)) {
            return $pdefault;
        }
        return $pvalue;
    }

    // ==================================================================
    // Access 함수
    // ==================================================================

    /**
     * IIf(조건, 참일 때, 거짓일 때) — 조건이 Null이면 거짓 쪽.
     * 이 함수는 이미 계산된 두 값 중 하나를 고르기만 한다. 해석기는 고른 쪽만 계산해서 넘긴다
     * (안 고른 쪽의 0 나누기 같은 오류로 수식 전체가 멈추지 않게 — calcFormula.md 참고).
     * @return mixed
     */
    public static function iif($pcondition, $ptrue, $pfalse)
    {
        if (self::isTrue($pcondition)) {
            return $ptrue;
        }
        return $pfalse;
    }

    /**
     * Nz(값, 기본값) — 값이 Null이면 기본값, 아니면 값 그대로. 기본값을 생략하면 ''.
     * Null만 바꾼다(빈 글자 ''는 그대로 — isnull_과 다르다).
     * @return mixed
     */
    public static function nz($pvalue, $pdefault = '')
    {
        if ($pvalue === null) {
            return $pdefault;
        }
        return $pvalue;
    }

    /** IsNull(값) — Access 기본 함수. Null만 참(빈 글자는 거짓 — isnull_과 다르다). */
    public static function isNull($pvalue): bool
    {
        return $pvalue === null;
    }

    /**
     * Round(숫자, 자리수) — 은행원 반올림(가운데 값은 짝수 쪽): Round(2.5)=2, Round(3.5)=4, Round(0.125,2)=0.12.
     * Null이면 Null. 자리수를 생략하면 0. 자리수가 음수면 오류(Access와 같음). 숫자가 아니면 오류.
     * @return int|float|null
     */
    public static function round($pvalue, $pdigits = 0)
    {
        $digits = 0;
        $number = 0;
        $result = 0;

        if ($pvalue === null) {
            return null;
        }
        $digits = (int) self::toNumber($pdigits);
        if ($digits < 0) {
            throw new RuntimeException('Round: 자리수는 0 이상이어야 합니다(' . $digits . ').');
        }
        $number = self::toNumber($pvalue);
        $result = round((float) $number, $digits, PHP_ROUND_HALF_EVEN);
        if ($digits === 0) {
            return (int) $result;
        }
        return $result;
    }

    // ==================================================================
    // 연산 — Access 규칙: 한쪽이라도 Null이면 결과 Null(& 제외)
    // ==================================================================

    /**
     * + — 둘 다 글자면 이어 붙이고(VBA와 같음), 아니면 숫자로 더한다. 숫자가 아닌 글자는 오류.
     * @return mixed
     */
    public static function add($pleft, $pright)
    {
        if ($pleft === null || $pright === null) {
            return null;
        }
        if (is_string($pleft) && is_string($pright)) {
            return $pleft . $pright;
        }
        return self::numberResult(self::toNumber($pleft) + self::toNumber($pright));
    }

    /** - @return int|float|null */
    public static function sub($pleft, $pright)
    {
        if ($pleft === null || $pright === null) {
            return null;
        }
        return self::numberResult(self::toNumber($pleft) - self::toNumber($pright));
    }

    /** * @return int|float|null */
    public static function mul($pleft, $pright)
    {
        if ($pleft === null || $pright === null) {
            return null;
        }
        return self::numberResult(self::toNumber($pleft) * self::toNumber($pright));
    }

    /** / — 결과는 늘 실수. 0으로 나누면 오류. @return float|null */
    public static function div($pleft, $pright)
    {
        $divisor = 0;

        if ($pleft === null || $pright === null) {
            return null;
        }
        $divisor = self::toNumber($pright);
        if ((float) $divisor === 0.0) {
            throw new RuntimeException('0으로 나눌 수 없습니다.');
        }
        return (float) self::toNumber($pleft) / (float) $divisor;
    }

    /**
     * \ (정수 나눗셈) — VBA처럼 두 값을 먼저 은행원 반올림으로 정수로 만든 뒤 나누고, 몫의 소수는 버린다.
     * @return int|null
     */
    public static function intDiv($pleft, $pright)
    {
        $left = 0;
        $right = 0;

        if ($pleft === null || $pright === null) {
            return null;
        }
        $left = (int) round((float) self::toNumber($pleft), 0, PHP_ROUND_HALF_EVEN);
        $right = (int) round((float) self::toNumber($pright), 0, PHP_ROUND_HALF_EVEN);
        if ($right === 0) {
            throw new RuntimeException('0으로 나눌 수 없습니다.');
        }
        return intdiv($left, $right);
    }

    /** Mod — 두 값을 정수로 만든 뒤 나머지. 부호는 나뉘는 수를 따른다(VBA와 같음). @return int|null */
    public static function mod($pleft, $pright)
    {
        $left = 0;
        $right = 0;

        if ($pleft === null || $pright === null) {
            return null;
        }
        $left = (int) round((float) self::toNumber($pleft), 0, PHP_ROUND_HALF_EVEN);
        $right = (int) round((float) self::toNumber($pright), 0, PHP_ROUND_HALF_EVEN);
        if ($right === 0) {
            throw new RuntimeException('0으로 나눌 수 없습니다.');
        }
        return $left % $right;
    }

    /** ^ (거듭제곱) @return int|float|null */
    public static function pow($pleft, $pright)
    {
        if ($pleft === null || $pright === null) {
            return null;
        }
        return self::numberResult(pow(self::toNumber($pleft), self::toNumber($pright)));
    }

    /** -x (부호 바꾸기) @return int|float|null */
    public static function neg($pvalue)
    {
        if ($pvalue === null) {
            return null;
        }
        return self::numberResult(0 - self::toNumber($pvalue));
    }

    /**
     * & (글자 잇기) — Null은 ''로 보고 잇는다. 둘 다 Null이면 Null(VBA와 같음).
     * @return string|null
     */
    public static function concat($pleft, $pright)
    {
        if ($pleft === null && $pright === null) {
            return null;
        }
        return self::toText($pleft) . self::toText($pright);
    }

    /**
     * 비교 = <> < > <= >= — 한쪽이라도 Null이면 Null(조건에서는 거짓으로 쓰인다).
     *  - 둘 다 숫자(또는 숫자 + 숫자로 읽히는 글자)면 숫자로 비교한다.
     *  - 둘 다 글자면 대소문자를 구분하지 않고 비교한다(Access: 'krw' = 'KRW'는 참).
     *  - 숫자와 숫자가 아닌 글자를 비교하면 오류(형식 불일치).
     * @return bool|null
     */
    public static function compare($pleft, string $pop, $pright)
    {
        $result = 0;

        if ($pleft === null || $pright === null) {
            return null;
        }
        if (is_string($pleft) && is_string($pright)) {
            $result = strcmp(mb_strtolower($pleft, 'UTF-8'), mb_strtolower($pright, 'UTF-8'));
        } elseif (self::isNumeric($pleft) && self::isNumeric($pright)) {
            $result = self::spaceship((float) self::toNumber($pleft), (float) self::toNumber($pright));
        } else {
            throw new RuntimeException('비교할 수 없는 값입니다(형식 불일치): ' . self::toText($pleft) . ' ' . $pop . ' ' . self::toText($pright));
        }

        switch ($pop) {
            case '=':
                return $result === 0;
            case '<>':
                return $result !== 0;
            case '<':
                return $result < 0;
            case '>':
                return $result > 0;
            case '<=':
                return $result <= 0;
            case '>=':
                return $result >= 0;
            default:
                throw new RuntimeException('알 수 없는 비교 연산자입니다: ' . $pop);
        }
    }

    /**
     * Like — Access 패턴: * 여러 글자, ? 한 글자, # 숫자 한 개, [abc] 그중 한 글자, [!abc] 그 밖의 한 글자.
     * 대소문자 구분 없음. 한쪽이 Null이면 Null.
     * @return bool|null
     */
    public static function like($pvalue, $ppattern)
    {
        $pattern = '';
        $regex = '^';
        $length = 0;
        $i = 0;
        $c = '';
        $close = 0;
        $inner = '';

        if ($pvalue === null || $ppattern === null) {
            return null;
        }
        $pattern = self::toText($ppattern);
        $length = mb_strlen($pattern, 'UTF-8');
        for ($i = 0; $i < $length; $i++) {
            $c = mb_substr($pattern, $i, 1, 'UTF-8');
            if ($c === '*') {
                $regex .= '.*';
            } elseif ($c === '?') {
                $regex .= '.';
            } elseif ($c === '#') {
                $regex .= '[0-9]';
            } elseif ($c === '[') {
                $close = mb_strpos($pattern, ']', $i + 1, 'UTF-8');
                if ($close === false) {
                    throw new RuntimeException('Like 패턴의 [ 가 닫히지 않았습니다: ' . $pattern);
                }
                $inner = mb_substr($pattern, $i + 1, $close - $i - 1, 'UTF-8');
                if (mb_substr($inner, 0, 1, 'UTF-8') === '!') {
                    $regex .= '[^' . preg_quote(mb_substr($inner, 1, null, 'UTF-8'), '/') . ']';
                } else {
                    $regex .= '[' . preg_quote($inner, '/') . ']';
                }
                $i = $close;
            } else {
                $regex .= preg_quote($c, '/');
            }
        }
        $regex .= '$';
        return preg_match('/' . $regex . '/iu', self::toText($pvalue)) === 1;
    }

    /** And — 거짓이 하나라도 있으면 거짓, 아니면 Null이 있으면 Null, 아니면 참(3값 논리). @return bool|null */
    public static function andOp($pleft, $pright)
    {
        $left = self::toLogic($pleft);
        $right = self::toLogic($pright);

        if ($left === false || $right === false) {
            return false;
        }
        if ($left === null || $right === null) {
            return null;
        }
        return true;
    }

    /** Or — 참이 하나라도 있으면 참, 아니면 Null이 있으면 Null, 아니면 거짓(3값 논리). @return bool|null */
    public static function orOp($pleft, $pright)
    {
        $left = self::toLogic($pleft);
        $right = self::toLogic($pright);

        if ($left === true || $right === true) {
            return true;
        }
        if ($left === null || $right === null) {
            return null;
        }
        return false;
    }

    /** Not — Null이면 Null. @return bool|null */
    public static function notOp($pvalue)
    {
        $value = self::toLogic($pvalue);

        if ($value === null) {
            return null;
        }
        return !$value;
    }

    // ==================================================================
    // 도메인 함수 — DLookup/DSum/DCount/DAvg/DMin/DMax
    //   식(pexpr)   : 필드 이름 하나('중량코드' 또는 '[중량코드]'). DCount만 '*' 가능.
    //   테이블(pdomain): DOMAIN_TABLES에 있는 것만.
    //   조건(pcriteria): Access 조건 문자열 — 해석해서 ? 자리표시로 바꿔 실행한다(값을 SQL에 잇지 않는다).
    //                    문법은 parseCriteria 위 주석과 calcFormula.md.
    // ==================================================================

    /** DLookup — 조건에 맞는 첫 행의 값. 없으면 Null. @return mixed */
    public static function dlookup(DbContext $pdb, string $pexpr, string $pdomain, string $pcriteria = '')
    {
        return self::domain($pdb, 'DLookup', $pexpr, $pdomain, $pcriteria);
    }

    /** DSum — 합계. 대상이 없으면 Null. @return mixed */
    public static function dsum(DbContext $pdb, string $pexpr, string $pdomain, string $pcriteria = '')
    {
        return self::domain($pdb, 'DSum', $pexpr, $pdomain, $pcriteria);
    }

    /** DCount — 건수('*'면 행 수, 필드면 그 필드가 Null이 아닌 행 수). 대상이 없으면 0. @return int */
    public static function dcount(DbContext $pdb, string $pexpr, string $pdomain, string $pcriteria = ''): int
    {
        return (int) self::domain($pdb, 'DCount', $pexpr, $pdomain, $pcriteria);
    }

    /** DAvg — 평균. 대상이 없으면 Null. @return mixed */
    public static function davg(DbContext $pdb, string $pexpr, string $pdomain, string $pcriteria = '')
    {
        return self::domain($pdb, 'DAvg', $pexpr, $pdomain, $pcriteria);
    }

    /** DMin — 최소. 대상이 없으면 Null. @return mixed */
    public static function dmin(DbContext $pdb, string $pexpr, string $pdomain, string $pcriteria = '')
    {
        return self::domain($pdb, 'DMin', $pexpr, $pdomain, $pcriteria);
    }

    /** DMax — 최대. 대상이 없으면 Null. @return mixed */
    public static function dmax(DbContext $pdb, string $pexpr, string $pdomain, string $pcriteria = '')
    {
        return self::domain($pdb, 'DMax', $pexpr, $pdomain, $pcriteria);
    }

    /**
     * 도메인 함수 공통 — 테이블·필드 확인 → 조건 해석 → SELECT 실행(? 바인딩) → 값 하나.
     * 실패하면 "DLookup(…): 이유"로 예외.
     * @return mixed
     */
    private static function domain(DbContext $pdb, string $pkind, string $pexpr, string $pdomain, string $pcriteria)
    {
        $label = $pkind . '("' . $pexpr . '", "' . $pdomain . '")';
        $table = '';
        $fields = [];
        $column = '';
        $selectExpr = '';
        $where = ['sql' => '', 'params' => []];
        $sql = '';
        $stmt = null;
        $row = false;
        $crud = null;
        $i = 0;

        // 1. 테이블 — 허용 목록에 있는 것만(이름은 목록의 표기로).
        for ($i = 0; $i < count(self::DOMAIN_TABLES); $i++) {
            if (mb_strtolower(self::DOMAIN_TABLES[$i], 'UTF-8') === mb_strtolower(self::stripBrackets($pdomain), 'UTF-8')) {
                $table = self::DOMAIN_TABLES[$i];
            }
        }
        if ($table === '') {
            throw new RuntimeException($label . ': 허용되지 않은 테이블입니다(calcFormula::DOMAIN_TABLES).');
        }

        try {
            // 2. 테이블의 필드 목록 — 식과 조건의 필드 이름은 여기 있는 것만.
            $crud = new Crud($pdb);
            $crud->init($table);
            $fields = array_keys($crud->getSchema()['fields']);

            // 3. 식 — '*'(DCount만) 또는 필드 하나.
            if (trim($pexpr) === '*') {
                if ($pkind !== 'DCount') {
                    throw new RuntimeException("'*'는 DCount에서만 쓸 수 있습니다.");
                }
                $selectExpr = 'COUNT(*)';
            } else {
                $column = self::matchField(self::stripBrackets($pexpr), $fields);
                $selectExpr = self::aggregateSql($pkind, $pdb->dialect()->quoteIdentifier($column));
            }

            // 4. 조건 — 해석해서 SQL 조각 + ? 값 목록으로.
            if (trim($pcriteria) !== '') {
                $where = self::parseCriteria($pcriteria, $fields, $pdb->dialect());
            }

            $sql = 'SELECT ' . $selectExpr . ' FROM ' . $pdb->dialect()->quoteIdentifier($table);
            if ($where['sql'] !== '') {
                $sql .= ' WHERE ' . $where['sql'];
            }

            // 5. 실행 — 첫 행의 첫 값(DLookup은 맞는 행이 여럿이면 처음 것, Access와 같음).
            $stmt = $pdb->pdo()->prepare($sql);
            $stmt->execute($where['params']);
            $row = $stmt->fetch(PDO::FETCH_NUM);
            $stmt->closeCursor();
        } catch (Throwable $e) {
            throw new RuntimeException($label . ': ' . $e->getMessage(), 0, $e);
        }

        if ($row === false) {
            if ($pkind === 'DCount') {
                return 0;
            }
            return null;
        }
        return $row[0];
    }

    /** 도메인 함수 종류 → SELECT 식. */
    private static function aggregateSql(string $pkind, string $pquotedColumn): string
    {
        switch ($pkind) {
            case 'DLookup':
                return $pquotedColumn;
            case 'DSum':
                return 'SUM(' . $pquotedColumn . ')';
            case 'DCount':
                return 'COUNT(' . $pquotedColumn . ')';
            case 'DAvg':
                return 'AVG(' . $pquotedColumn . ')';
            case 'DMin':
                return 'MIN(' . $pquotedColumn . ')';
            case 'DMax':
                return 'MAX(' . $pquotedColumn . ')';
            default:
                throw new RuntimeException('알 수 없는 도메인 함수입니다: ' . $pkind);
        }
    }

    // ==================================================================
    // 도메인 함수의 조건 해석 — Access 조건 문자열 → SQL 조각 + ? 값
    //
    // 문법(키워드는 대소문자 무시):
    //   식      : 항 (Or 항)*
    //   항      : 인자 (And 인자)*
    //   인자    : Not 인자 | ( 식 ) | 비교
    //   비교    : 값 비교연산자 값                    비교연산자: = <> < > <= >=
    //           | 필드 [Not] Like '패턴'               * ? # (Access 패턴 → SQL % _ [0-9])
    //           | 필드 [Not] Between 값 And 값
    //           | 필드 [Not] In (값, 값, ...)
    //           | 필드 Is [Not] Null
    //   값      : 필드 | '글자' | "글자" | 숫자 | #날짜# | True | False
    //   필드    : 이름(한글 가능) | [이름]  — 테이블에 있는 필드만. from/to 같은 예약어도 그대로 쓸 수 있다
    //             (SQL에는 방언의 quoteIdentifier로 감싸 넣는다).
    // 값은 전부 ? 자리표시로 넘긴다. 필드 이름은 테이블의 실제 필드 목록에서 찾은 것만 SQL에 들어간다.
    // ==================================================================

    /**
     * 조건 문자열을 해석한다.
     * @param array<int, string> $pfields 테이블의 필드 이름들
     * @return array{sql: string, params: array<int, mixed>}
     */
    public static function parseCriteria(string $pcriteria, array $pfields, SqlDialect $pdialect): array
    {
        $state = [
            'text' => $pcriteria,
            'tokens' => self::tokenizeCriteria($pcriteria),
            'pos' => 0,
            'fields' => $pfields,
            'dialect' => $pdialect,
            'params' => [],
        ];
        $sql = self::critOr($state);

        if (self::critPeek($state)['kind'] !== 'end') {
            throw new RuntimeException('조건의 "' . self::critPeek($state)['text'] . '" 앞에서 끝나야 합니다(And/Or가 빠졌는지 확인). 조건: ' . $pcriteria);
        }
        return ['sql' => $sql, 'params' => $state['params']];
    }

    /** 조건 문자열 → 토큰 목록. 토큰 = ['kind' => name|field|string|number|date|op|lparen|rparen|comma|end, 'text' => …] */
    private static function tokenizeCriteria(string $ptext): array
    {
        $tokens = [];
        $chars = preg_split('//u', $ptext, -1, PREG_SPLIT_NO_EMPTY);
        $count = count($chars);
        $i = 0;
        $c = '';
        $two = '';
        $buffer = '';
        $quote = '';

        while ($i < $count) {
            $c = $chars[$i];

            if (trim($c) === '') {
                $i++;
                continue;
            }

            if ($c === '(') {
                $tokens[] = ['kind' => 'lparen', 'text' => '('];
                $i++;
            } elseif ($c === ')') {
                $tokens[] = ['kind' => 'rparen', 'text' => ')'];
                $i++;
            } elseif ($c === ',') {
                $tokens[] = ['kind' => 'comma', 'text' => ','];
                $i++;
            } elseif ($c === '=' || $c === '<' || $c === '>') {
                $two = $c;
                if ($i + 1 < $count) {
                    $two = $c . $chars[$i + 1];
                }
                if ($two === '<>' || $two === '<=' || $two === '>=') {
                    $tokens[] = ['kind' => 'op', 'text' => $two];
                    $i += 2;
                } else {
                    $tokens[] = ['kind' => 'op', 'text' => $c];
                    $i++;
                }
            } elseif ($c === "'" || $c === '"') {
                // 글자 — 같은 따옴표 두 번은 따옴표 하나.
                $quote = $c;
                $buffer = '';
                $i++;
                while (true) {
                    if ($i >= $count) {
                        throw new RuntimeException('조건의 글자가 닫히지 않았습니다. 조건: ' . $ptext);
                    }
                    if ($chars[$i] === $quote) {
                        if ($i + 1 < $count && $chars[$i + 1] === $quote) {
                            $buffer .= $quote;
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $buffer .= $chars[$i];
                    $i++;
                }
                $tokens[] = ['kind' => 'string', 'text' => $buffer];
            } elseif ($c === '#') {
                // 날짜 — #2026-11-13# 또는 #11/13/2026#(Access는 월/일/년).
                $buffer = '';
                $i++;
                while ($i < $count && $chars[$i] !== '#') {
                    $buffer .= $chars[$i];
                    $i++;
                }
                if ($i >= $count) {
                    throw new RuntimeException('조건의 날짜(#…#)가 닫히지 않았습니다. 조건: ' . $ptext);
                }
                $i++;
                $tokens[] = ['kind' => 'date', 'text' => $buffer];
            } elseif ($c === '[') {
                $buffer = '';
                $i++;
                while ($i < $count && $chars[$i] !== ']') {
                    $buffer .= $chars[$i];
                    $i++;
                }
                if ($i >= $count) {
                    throw new RuntimeException('조건의 [ 가 닫히지 않았습니다. 조건: ' . $ptext);
                }
                $i++;
                $tokens[] = ['kind' => 'field', 'text' => $buffer];
            } elseif (preg_match('/[0-9.]/', $c) === 1 || ($c === '-' && $i + 1 < $count && preg_match('/[0-9.]/', $chars[$i + 1]) === 1 && self::critValueExpected($tokens))) {
                // 숫자(앞의 - 는 값 자리일 때만 부호).
                $buffer = $c;
                $i++;
                while ($i < $count && preg_match('/[0-9.]/', $chars[$i]) === 1) {
                    $buffer .= $chars[$i];
                    $i++;
                }
                if (!is_numeric($buffer)) {
                    throw new RuntimeException('조건의 숫자 형식이 올바르지 않습니다: ' . $buffer);
                }
                $tokens[] = ['kind' => 'number', 'text' => $buffer];
            } elseif (preg_match('/[\p{L}\p{N}_]/u', $c) === 1) {
                $buffer = '';
                while ($i < $count && preg_match('/[\p{L}\p{N}_]/u', $chars[$i]) === 1) {
                    $buffer .= $chars[$i];
                    $i++;
                }
                $tokens[] = ['kind' => 'name', 'text' => $buffer];
            } else {
                throw new RuntimeException('조건에 알 수 없는 글자가 있습니다: "' . $c . '". 조건: ' . $ptext);
            }
        }

        $tokens[] = ['kind' => 'end', 'text' => ''];
        return $tokens;
    }

    /** '-'를 음수 부호로 볼지 — 앞이 비었거나 연산자·괄호·쉼표·키워드면 값 자리. */
    private static function critValueExpected(array $ptokens): bool
    {
        $last = null;

        if (count($ptokens) === 0) {
            return true;
        }
        $last = $ptokens[count($ptokens) - 1];
        if ($last['kind'] === 'op' || $last['kind'] === 'lparen' || $last['kind'] === 'comma') {
            return true;
        }
        if ($last['kind'] === 'name' && in_array(strtolower($last['text']), ['and', 'or', 'not', 'between', 'in'], true)) {
            return true;
        }
        return false;
    }

    private static function critPeek(array &$pstate): array
    {
        return $pstate['tokens'][$pstate['pos']];
    }

    private static function critNext(array &$pstate): array
    {
        $token = $pstate['tokens'][$pstate['pos']];

        if ($token['kind'] !== 'end') {
            $pstate['pos']++;
        }
        return $token;
    }

    /** 키워드인지(대소문자 무시) — name 토큰만. */
    private static function critIsWord(array $ptoken, string $pword): bool
    {
        return $ptoken['kind'] === 'name' && strtolower($ptoken['text']) === strtolower($pword);
    }

    private static function critExpect(array &$pstate, string $pkind, string $pwhat): void
    {
        if (self::critPeek($pstate)['kind'] !== $pkind) {
            throw new RuntimeException('조건에서 ' . $pwhat . '가 와야 합니다. 조건: ' . $pstate['text']);
        }
        self::critNext($pstate);
    }

    private static function critOr(array &$pstate): string
    {
        $sql = self::critAnd($pstate);

        while (self::critIsWord(self::critPeek($pstate), 'or')) {
            self::critNext($pstate);
            $sql = '(' . $sql . ' OR ' . self::critAnd($pstate) . ')';
        }
        return $sql;
    }

    private static function critAnd(array &$pstate): string
    {
        $sql = self::critNot($pstate);

        while (self::critIsWord(self::critPeek($pstate), 'and')) {
            self::critNext($pstate);
            $sql = '(' . $sql . ' AND ' . self::critNot($pstate) . ')';
        }
        return $sql;
    }

    private static function critNot(array &$pstate): string
    {
        $sql = '';

        if (self::critIsWord(self::critPeek($pstate), 'not')) {
            self::critNext($pstate);
            return '(NOT ' . self::critNot($pstate) . ')';
        }
        if (self::critPeek($pstate)['kind'] === 'lparen') {
            self::critNext($pstate);
            $sql = self::critOr($pstate);
            self::critExpect($pstate, 'rparen', "')'");
            return '(' . $sql . ')';
        }
        return self::critCompare($pstate);
    }

    /** 비교 하나 — 값 연산자 값 / Like / Between / In / Is Null. */
    private static function critCompare(array &$pstate): string
    {
        $left = self::critOperand($pstate);
        $token = self::critPeek($pstate);
        $negate = false;
        $op = '';
        $pattern = '';
        $low = '';
        $high = '';
        $items = [];

        if ($token['kind'] === 'op') {
            $op = self::critNext($pstate)['text'];
            return $left . ' ' . $op . ' ' . self::critOperand($pstate);
        }

        if (self::critIsWord($token, 'is')) {
            self::critNext($pstate);
            if (self::critIsWord(self::critPeek($pstate), 'not')) {
                self::critNext($pstate);
                $negate = true;
            }
            if (!self::critIsWord(self::critPeek($pstate), 'null')) {
                throw new RuntimeException("조건에서 Is 다음에는 Null 또는 Not Null이 와야 합니다. 조건: " . $pstate['text']);
            }
            self::critNext($pstate);
            if ($negate) {
                return $left . ' IS NOT NULL';
            }
            return $left . ' IS NULL';
        }

        if (self::critIsWord($token, 'not')) {
            self::critNext($pstate);
            $negate = true;
            $token = self::critPeek($pstate);
        }

        if (self::critIsWord($token, 'like')) {
            self::critNext($pstate);
            if (self::critPeek($pstate)['kind'] !== 'string') {
                throw new RuntimeException("조건에서 Like 다음에는 '패턴' 글자가 와야 합니다. 조건: " . $pstate['text']);
            }
            $pattern = self::accessLikeToSql(self::critNext($pstate)['text']);
            $pstate['params'][] = $pattern;
            if ($negate) {
                return $left . ' NOT LIKE ?';
            }
            return $left . ' LIKE ?';
        }

        if (self::critIsWord($token, 'between')) {
            self::critNext($pstate);
            $low = self::critOperand($pstate);
            if (!self::critIsWord(self::critPeek($pstate), 'and')) {
                throw new RuntimeException('조건에서 Between 값 다음에 And가 와야 합니다. 조건: ' . $pstate['text']);
            }
            self::critNext($pstate);
            $high = self::critOperand($pstate);
            if ($negate) {
                return $left . ' NOT BETWEEN ' . $low . ' AND ' . $high;
            }
            return $left . ' BETWEEN ' . $low . ' AND ' . $high;
        }

        if (self::critIsWord($token, 'in')) {
            self::critNext($pstate);
            self::critExpect($pstate, 'lparen', "In 다음에 '('");
            $items[] = self::critOperand($pstate);
            while (self::critPeek($pstate)['kind'] === 'comma') {
                self::critNext($pstate);
                $items[] = self::critOperand($pstate);
            }
            self::critExpect($pstate, 'rparen', "')'");
            if ($negate) {
                return $left . ' NOT IN (' . implode(', ', $items) . ')';
            }
            return $left . ' IN (' . implode(', ', $items) . ')';
        }

        throw new RuntimeException('조건에서 비교연산자(= <> < > <= >=, Like, Between, In, Is Null)가 와야 합니다. 조건: ' . $pstate['text']);
    }

    /** 값 하나 — 필드면 감싼 이름, 값이면 ? (값은 params에 쌓는다). */
    private static function critOperand(array &$pstate): string
    {
        $token = self::critNext($pstate);

        if ($token['kind'] === 'string') {
            $pstate['params'][] = $token['text'];
            return '?';
        }
        if ($token['kind'] === 'number') {
            $pstate['params'][] = self::toNumber($token['text']);
            return '?';
        }
        if ($token['kind'] === 'date') {
            $pstate['params'][] = self::accessDateToSql($token['text']);
            return '?';
        }
        if (self::critIsWord($token, 'true')) {
            $pstate['params'][] = 1;
            return '?';
        }
        if (self::critIsWord($token, 'false')) {
            $pstate['params'][] = 0;
            return '?';
        }
        if ($token['kind'] === 'field' || $token['kind'] === 'name') {
            if ($token['kind'] === 'name' && in_array(strtolower($token['text']), ['and', 'or', 'not', 'like', 'between', 'in', 'is', 'null'], true)) {
                throw new RuntimeException('조건의 "' . $token['text'] . '" 자리에는 필드나 값이 와야 합니다. 조건: ' . $pstate['text']);
            }
            return $pstate['dialect']->quoteIdentifier(self::matchField($token['text'], $pstate['fields']));
        }
        if ($token['kind'] === 'end') {
            throw new RuntimeException('조건이 덜 끝났습니다. 조건: ' . $pstate['text']);
        }
        throw new RuntimeException('조건의 "' . $token['text'] . '" 자리에는 필드나 값이 와야 합니다. 조건: ' . $pstate['text']);
    }

    /** Access Like 패턴(* ? #) → SQL(% _ [0-9]). SQL 쪽 특수문자 % _ 는 지원하지 않는다(오류). */
    private static function accessLikeToSql(string $ppattern): string
    {
        if (strpos($ppattern, '%') !== false || strpos($ppattern, '_') !== false) {
            throw new RuntimeException("Like 패턴에 % 나 _ 는 쓸 수 없습니다(Access 패턴 * ? 를 쓰세요): " . $ppattern);
        }
        return str_replace(['*', '?', '#'], ['%', '_', '[0-9]'], $ppattern);
    }

    /** Access 날짜 글자(#…# 안쪽) → 'Y-m-d H:i:s'. 2026-11-13, 11/13/2026(월/일/년), 시각 포함 가능. */
    private static function accessDateToSql(string $ptext): string
    {
        $formats = ['Y-m-d', 'Y-m-d H:i:s', 'Y-m-d H:i', 'm/d/Y', 'm/d/Y H:i:s', 'm/d/Y H:i'];
        $date = false;
        $i = 0;

        for ($i = 0; $i < count($formats); $i++) {
            $date = DateTime::createFromFormat('!' . $formats[$i], trim($ptext));
            if ($date !== false) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        throw new RuntimeException('날짜 형식이 올바르지 않습니다: #' . $ptext . '#');
    }

    // ==================================================================
    // getCalcStr — Access 폼 모듈 getCalcStr(p수식, pfields)의 웹판
    //
    // Access: [필드]를 폼 값 글자로 바꾼 뒤 Eval(문장).
    // 웹    : 수식을 토큰 → 트리로 읽고, 트리를 위에서 아래로 계산한다(Eval 없음, 허용한 함수만).
    //         [필드]는 따옴표 밖이면 값으로, 따옴표 안이면 그 글자 안에서 값 글자로 바꿔 넣는다.
    //         정상 값이면 Access와 결과가 같고, 값에 따옴표가 섞여도 수식이 깨지지 않는다.
    //
    // 수식 문법(키워드·함수 이름은 대소문자 무시) — 우선순위 높은 것부터:
    //   ^  →  -x  →  * /  →  \  →  Mod  →  + -  →  &  →  = <> < > <= >= Like Is [Not] Null  →  Not  →  And  →  Or
    //   값: 숫자 | '글자' | "글자" | #날짜# | [필드] | True | False | Null | 함수(인자, …) | ( 식 )
    //   함수: EXPR_FUNCTIONS 목록에 있는 것만.
    // ==================================================================

    /**
     * 수식에서 쓸 수 있는 함수 — [소문자 이름 => [최소 인자 수, 최대 인자 수]].
     * 목록 밖의 함수가 수식에 있으면 "지원하지 않는 함수"로 실패한다. 늘리려면 여기와 exprCall에 한 줄씩.
     */
    const EXPR_FUNCTIONS = [
        'iif' => [3, 3],
        'nz' => [1, 2],
        'nz_' => [1, 2],
        'isnull' => [1, 1],
        'isnull_' => [1, 1],
        'cdata' => [1, 1],
        'round' => [1, 2],
        'dlookup' => [2, 3],
        'dsum' => [2, 3],
        'dcount' => [2, 3],
        'davg' => [2, 3],
        'dmin' => [2, 3],
        'dmax' => [2, 3],
    ];

    /**
     * getCalcStr(db, 수식, 필드들, 값들, 글자 필드들) — Access getCalcStr와 같은 순서로 계산한다.
     *  1. 수식이나 필드들이 isnull_이면 '' (Access와 같음).
     *  2. 필드들(쉼표 구분)의 값을 $pvalues에서 꺼낸다 — 글자 필드(Zone, $ptextFields)는 그대로,
     *     나머지(숫자 필드)는 cdata(). 폼 값에 없는 필드면 오류(Access의 Me(t)도 오류).
     *     [2026-11-13] Access 견적 폼 코드는 "Zone만 그대로"였다. 수주 폼의 결제통화('KRW') 같은 글자 필드에
     *     cdata를 하면 0이 되어 '[결제통화]'='KRW'가 늘 거짓이 됐다 — 그래서 폼 테이블의 필드 타입으로
     *     글자 필드를 받는다(calcAll의 호출부가 넘긴다).
     *  3. 수식을 해석해 계산한다. 필드들에 없는 [필드]가 수식에 있으면 오류.
     *
     * $pdb       : 도메인 함수(DLookup 등)가 DB를 읽을 때 쓴다.
     * $pformula  : 수식 테이블의 수식 칸.  $pfields: 수식 테이블의 필드들 칸.
     * $pvalues   : 폼의 현재 값 [필드 => 값] (화면이 보낸 것).
     * $ptextFields: cdata 없이 그대로 넣을 필드 이름들(글자·날짜 필드). Zone은 늘 그대로.
     * 실패하면 RuntimeException('수식 계산 실패: 이유 — 수식: …').
     * @return mixed
     */
    public static function getCalcStr(DbContext $pdb, $pformula, $pfields, array $pvalues, array $ptextFields = [])
    {
        $map = [];
        $names = [];
        $name = '';
        $context = [];
        $tree = [];
        $i = 0;

        if (self::isnull_($pformula) || self::isnull_($pfields)) {
            return '';
        }

        try {
            // 2. 필드들 → [필드 => 넣을 값] — 글자 필드(Zone, ptextFields)는 그대로, 숫자 필드는 cdata.
            $names = explode(',', (string) $pfields);
            for ($i = 0; $i < count($names); $i++) {
                $name = trim($names[$i]);
                if (self::isnull_($name)) {
                    continue;
                }
                if (!array_key_exists($name, $pvalues)) {
                    throw new RuntimeException('폼 값에 ' . $name . '이(가) 없습니다(필드들 목록의 필드).');
                }
                if (strtolower($name) === 'zone' || in_array($name, $ptextFields, true)) {
                    $map[$name] = $pvalues[$name];
                } else {
                    $map[$name] = self::cdata($pvalues[$name]);
                }
            }

            // 3. 해석 → 계산.
            $tree = self::exprParse((string) $pformula);
            $context = ['db' => $pdb, 'map' => $map];
            return self::exprEval($tree, $context);
        } catch (Throwable $e) {
            throw new RuntimeException('수식 계산 실패: ' . $e->getMessage() . ' — 수식: ' . (string) $pformula, 0, $e);
        }
    }

    /**
     * calcAll(db, 수식 테이블, 폼 값들) — 수식 테이블의 수식을 순서대로 모두 계산한다(Access에서 getCalcStr를 부르는 쪽).
     *  1. 수식 테이블에서 순서·필드명·수식·필드들을 순서 오름차순으로 읽는다.
     *  2. 수식마다 getCalcStr로 계산하고, 결과를 폼 값[필드명]에 바로 넣는다
     *     — 다음 수식이 그 값을 쓴다(Access의 Me(필드명) = 결과와 같음. 예: 공급가총액 → 미수금).
     *     수식 칸이 비어 있는 행은 건너뛴다(값을 바꾸지 않는다).
     *  3. 하나라도 실패하면 그 자리에서 멈추고 "수식 n번(필드명): 이유"로 예외 — 일부만 계산된 값은 돌려주지 않는다.
     *
     * $ptable : 수식 테이블 이름. 호출하는 서버 코드에 고정한 이름만 넘긴다(사용자 입력 금지 —
     *           예: inquiryContent_수식_tables.php의 FORMULA_TABLES).
     * $pvalues: 폼의 현재 값 [필드 => 값] (화면이 보낸 것).
     * $ptextFields: cdata 없이 그대로 넣을 글자·날짜 필드 이름들 — 호출부가 폼 테이블의 필드 타입으로 만든다
     *               (textFieldsOf). 그대로 getCalcStr에 넘긴다.
     * 돌려주는 값:
     *   'values'  => 계산이 끝난 폼 값 전체(보낸 값 + 계산 결과로 바뀐 필드)
     *   'results' => 계산한 필드만 [필드명 => 결과] (순서대로) — 화면은 이것만 칸에 넣으면 된다
     * @return array{values: array<string, mixed>, results: array<string, mixed>}
     */
    public static function calcAll(DbContext $pdb, string $ptable, array $pvalues, array $ptextFields = []): array
    {
        $values = $pvalues;
        $results = [];
        $rows = [];
        $row = [];
        $sql = '';
        $stmt = null;
        $dialect = $pdb->dialect();
        $label = '';
        $value = null;
        $i = 0;

        // 1. 수식 읽기 — 테이블·컬럼명은 서버 코드에 고정, 값 없음.
        $sql = 'SELECT ' . $dialect->quoteIdentifier('순서') . ', ' . $dialect->quoteIdentifier('필드명') . ', '
            . $dialect->quoteIdentifier('수식') . ', ' . $dialect->quoteIdentifier('필드들')
            . ' FROM ' . $dialect->quoteIdentifier($ptable)
            . ' ORDER BY ' . $dialect->quoteIdentifier('순서') . ' ASC';
        try {
            $stmt = $pdb->pdo()->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            throw new RuntimeException($ptable . '의 수식을 읽지 못했습니다: ' . $e->getMessage(), 0, $e);
        }

        // 2. 순서대로 계산 — 결과는 바로 values에 넣어 다음 수식이 쓴다.
        for ($i = 0; $i < count($rows); $i++) {
            $row = $rows[$i];
            $label = '수식 ' . self::toText($row['순서']) . '번(' . self::toText($row['필드명']) . ')';

            if (self::isnull_($row['수식'])) {
                continue;
            }
            if (self::isnull_($row['필드명'])) {
                throw new RuntimeException($label . ': 필드명이 비어 있어 결과를 넣을 곳이 없습니다.');
            }

            try {
                $value = self::getCalcStr($pdb, $row['수식'], $row['필드들'], $values, $ptextFields);
            } catch (Throwable $e) {
                throw new RuntimeException($label . ': ' . $e->getMessage(), 0, $e);
            }

            $values[$row['필드명']] = $value;
            $results[$row['필드명']] = $value;
        }

        return ['values' => $values, 'results' => $results];
    }

    /** 숫자 타입(DB DATA_TYPE) — 이 타입의 필드만 cdata로 바꾼다. 나머지(글자·날짜 등)는 그대로 넣는다. */
    const NUMERIC_TYPES = ['int', 'bigint', 'smallint', 'tinyint', 'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney', 'bit', 'double'];

    /**
     * 폼 테이블(t수주내역 등)의 글자·날짜 필드 이름들 — calcAll/getCalcStr의 $ptextFields로 넘긴다.
     * 스키마의 type이 NUMERIC_TYPES가 아니면 글자 필드로 본다. 테이블 이름은 서버 코드에 고정한 것만.
     * @return array<int, string>
     */
    public static function textFieldsOf(DbContext $pdb, string $ptable): array
    {
        $crud = new Crud($pdb);
        $result = [];
        $fields = [];
        $names = [];
        $type = '';
        $i = 0;

        $crud->init($ptable);
        $fields = $crud->getSchema()['fields'];
        $names = array_keys($fields);
        for ($i = 0; $i < count($names); $i++) {
            $type = '';
            if (isset($fields[$names[$i]]['type'])) {
                $type = strtolower((string) $fields[$names[$i]]['type']);
            }
            if (!in_array($type, self::NUMERIC_TYPES, true)) {
                $result[] = $names[$i];
            }
        }
        return $result;
    }

    /** 수식 글자 → 트리(배열). 문법이 틀리면 "n번째 글자" 위치와 함께 오류. */
    private static function exprParse(string $ptext): array
    {
        $state = ['text' => $ptext, 'tokens' => self::exprTokenize($ptext), 'pos' => 0];
        $tree = self::exprOr($state);

        if (self::exprPeek($state)['kind'] !== 'end') {
            throw new RuntimeException(self::exprWhere($state) . '"' . self::exprPeek($state)['text'] . '" 앞에서 식이 끝나야 합니다(괄호·쉼표 확인).');
        }
        return $tree;
    }

    /**
     * 수식 글자 → 토큰 목록. 토큰 = ['kind' => number|string|date|field|name|op|lparen|rparen|comma|end, 'text', 'at'(글자 위치)].
     */
    private static function exprTokenize(string $ptext): array
    {
        $tokens = [];
        $chars = preg_split('//u', $ptext, -1, PREG_SPLIT_NO_EMPTY);
        $count = count($chars);
        $i = 0;
        $start = 0;
        $c = '';
        $two = '';
        $buffer = '';
        $quote = '';

        while ($i < $count) {
            $c = $chars[$i];
            $start = $i;

            if (trim($c) === '') {
                $i++;
                continue;
            }

            if ($c === '(') {
                $tokens[] = ['kind' => 'lparen', 'text' => '(', 'at' => $start];
                $i++;
            } elseif ($c === ')') {
                $tokens[] = ['kind' => 'rparen', 'text' => ')', 'at' => $start];
                $i++;
            } elseif ($c === ',') {
                $tokens[] = ['kind' => 'comma', 'text' => ',', 'at' => $start];
                $i++;
            } elseif ($c === '<' || $c === '>') {
                $two = $c;
                if ($i + 1 < $count) {
                    $two = $c . $chars[$i + 1];
                }
                if ($two === '<>' || $two === '<=' || $two === '>=') {
                    $tokens[] = ['kind' => 'op', 'text' => $two, 'at' => $start];
                    $i += 2;
                } else {
                    $tokens[] = ['kind' => 'op', 'text' => $c, 'at' => $start];
                    $i++;
                }
            } elseif (strpos('=+-*/\\^&', $c) !== false) {
                $tokens[] = ['kind' => 'op', 'text' => $c, 'at' => $start];
                $i++;
            } elseif ($c === "'" || $c === '"') {
                // 글자 — 같은 따옴표 두 번은 따옴표 하나. 안의 [필드]는 계산할 때 바꾼다(exprSubstitute).
                $quote = $c;
                $buffer = '';
                $i++;
                while (true) {
                    if ($i >= $count) {
                        throw new RuntimeException(($start + 1) . '번째 글자에서 시작한 글자(따옴표)가 닫히지 않았습니다.');
                    }
                    if ($chars[$i] === $quote) {
                        if ($i + 1 < $count && $chars[$i + 1] === $quote) {
                            $buffer .= $quote;
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $buffer .= $chars[$i];
                    $i++;
                }
                $tokens[] = ['kind' => 'string', 'text' => $buffer, 'at' => $start];
            } elseif ($c === '#') {
                $buffer = '';
                $i++;
                while ($i < $count && $chars[$i] !== '#') {
                    $buffer .= $chars[$i];
                    $i++;
                }
                if ($i >= $count) {
                    throw new RuntimeException(($start + 1) . '번째 글자에서 시작한 날짜(#…#)가 닫히지 않았습니다.');
                }
                $i++;
                $tokens[] = ['kind' => 'date', 'text' => $buffer, 'at' => $start];
            } elseif ($c === '[') {
                $buffer = '';
                $i++;
                while ($i < $count && $chars[$i] !== ']') {
                    $buffer .= $chars[$i];
                    $i++;
                }
                if ($i >= $count) {
                    throw new RuntimeException(($start + 1) . '번째 글자의 [ 가 닫히지 않았습니다.');
                }
                $i++;
                $tokens[] = ['kind' => 'field', 'text' => $buffer, 'at' => $start];
            } elseif (preg_match('/[0-9.]/', $c) === 1) {
                $buffer = '';
                while ($i < $count && preg_match('/[0-9.]/', $chars[$i]) === 1) {
                    $buffer .= $chars[$i];
                    $i++;
                }
                if (!is_numeric($buffer)) {
                    throw new RuntimeException(($start + 1) . '번째 글자의 숫자 형식이 올바르지 않습니다: ' . $buffer);
                }
                $tokens[] = ['kind' => 'number', 'text' => $buffer, 'at' => $start];
            } elseif (preg_match('/[\p{L}_]/u', $c) === 1) {
                $buffer = '';
                while ($i < $count && preg_match('/[\p{L}\p{N}_]/u', $chars[$i]) === 1) {
                    $buffer .= $chars[$i];
                    $i++;
                }
                $tokens[] = ['kind' => 'name', 'text' => $buffer, 'at' => $start];
            } else {
                throw new RuntimeException(($start + 1) . '번째 글자 "' . $c . '"를 알 수 없습니다.');
            }
        }

        $tokens[] = ['kind' => 'end', 'text' => '', 'at' => $count];
        return $tokens;
    }

    private static function exprPeek(array &$pstate): array
    {
        return $pstate['tokens'][$pstate['pos']];
    }

    private static function exprNext(array &$pstate): array
    {
        $token = $pstate['tokens'][$pstate['pos']];

        if ($token['kind'] !== 'end') {
            $pstate['pos']++;
        }
        return $token;
    }

    /** 지금 토큰이 연산자 pop인지. */
    private static function exprIsOp(array &$pstate, string $pop): bool
    {
        $token = self::exprPeek($pstate);

        return $token['kind'] === 'op' && $token['text'] === $pop;
    }

    /** 지금 토큰이 키워드 pword인지(대소문자 무시). */
    private static function exprIsWord(array &$pstate, string $pword): bool
    {
        $token = self::exprPeek($pstate);

        return $token['kind'] === 'name' && strtolower($token['text']) === $pword;
    }

    /** 오류 글의 앞머리 — "n번째 글자: ". */
    private static function exprWhere(array &$pstate): string
    {
        return (self::exprPeek($pstate)['at'] + 1) . '번째 글자: ';
    }

    private static function exprBin(string $pop, array $pleft, array $pright): array
    {
        return ['k' => 'bin', 'op' => $pop, 'l' => $pleft, 'r' => $pright];
    }

    private static function exprOr(array &$pstate): array
    {
        $left = self::exprAnd($pstate);

        while (self::exprIsWord($pstate, 'or')) {
            self::exprNext($pstate);
            $left = self::exprBin('or', $left, self::exprAnd($pstate));
        }
        return $left;
    }

    private static function exprAnd(array &$pstate): array
    {
        $left = self::exprNot($pstate);

        while (self::exprIsWord($pstate, 'and')) {
            self::exprNext($pstate);
            $left = self::exprBin('and', $left, self::exprNot($pstate));
        }
        return $left;
    }

    private static function exprNot(array &$pstate): array
    {
        if (self::exprIsWord($pstate, 'not')) {
            self::exprNext($pstate);
            return ['k' => 'not', 'e' => self::exprNot($pstate)];
        }
        return self::exprCompare($pstate);
    }

    /** 비교 — = <> < > <= >=, Like, Is [Not] Null. */
    private static function exprCompare(array &$pstate): array
    {
        $left = self::exprConcat($pstate);
        $token = [];
        $negate = false;

        while (true) {
            $token = self::exprPeek($pstate);
            if ($token['kind'] === 'op' && in_array($token['text'], ['=', '<>', '<', '>', '<=', '>='], true)) {
                self::exprNext($pstate);
                $left = self::exprBin($token['text'], $left, self::exprConcat($pstate));
            } elseif (self::exprIsWord($pstate, 'like')) {
                self::exprNext($pstate);
                $left = self::exprBin('like', $left, self::exprConcat($pstate));
            } elseif (self::exprIsWord($pstate, 'is')) {
                self::exprNext($pstate);
                $negate = false;
                if (self::exprIsWord($pstate, 'not')) {
                    self::exprNext($pstate);
                    $negate = true;
                }
                if (!self::exprIsWord($pstate, 'null')) {
                    throw new RuntimeException(self::exprWhere($pstate) . 'Is 다음에는 Null 또는 Not Null이 와야 합니다.');
                }
                self::exprNext($pstate);
                $left = ['k' => 'isnull', 'e' => $left, 'negate' => $negate];
            } else {
                return $left;
            }
        }
    }

    private static function exprConcat(array &$pstate): array
    {
        $left = self::exprAdd($pstate);

        while (self::exprIsOp($pstate, '&')) {
            self::exprNext($pstate);
            $left = self::exprBin('&', $left, self::exprAdd($pstate));
        }
        return $left;
    }

    private static function exprAdd(array &$pstate): array
    {
        $left = self::exprMod($pstate);
        $op = '';

        while (self::exprIsOp($pstate, '+') || self::exprIsOp($pstate, '-')) {
            $op = self::exprNext($pstate)['text'];
            $left = self::exprBin($op, $left, self::exprMod($pstate));
        }
        return $left;
    }

    private static function exprMod(array &$pstate): array
    {
        $left = self::exprIntDiv($pstate);

        while (self::exprIsWord($pstate, 'mod')) {
            self::exprNext($pstate);
            $left = self::exprBin('mod', $left, self::exprIntDiv($pstate));
        }
        return $left;
    }

    private static function exprIntDiv(array &$pstate): array
    {
        $left = self::exprMul($pstate);

        while (self::exprIsOp($pstate, '\\')) {
            self::exprNext($pstate);
            $left = self::exprBin('\\', $left, self::exprMul($pstate));
        }
        return $left;
    }

    private static function exprMul(array &$pstate): array
    {
        $left = self::exprUnary($pstate);
        $op = '';

        while (self::exprIsOp($pstate, '*') || self::exprIsOp($pstate, '/')) {
            $op = self::exprNext($pstate)['text'];
            $left = self::exprBin($op, $left, self::exprUnary($pstate));
        }
        return $left;
    }

    /** 부호 -x, +x — ^보다 낮다(VBA: -2^2 = -4). */
    private static function exprUnary(array &$pstate): array
    {
        if (self::exprIsOp($pstate, '-')) {
            self::exprNext($pstate);
            return ['k' => 'neg', 'e' => self::exprUnary($pstate)];
        }
        if (self::exprIsOp($pstate, '+')) {
            self::exprNext($pstate);
            return self::exprUnary($pstate);
        }
        return self::exprPow($pstate);
    }

    /** ^ — 왼쪽부터(VBA와 같음). 오른쪽에는 부호가 올 수 있다(2^-1). */
    private static function exprPow(array &$pstate): array
    {
        $left = self::exprPrimary($pstate);
        $right = [];

        while (self::exprIsOp($pstate, '^')) {
            self::exprNext($pstate);
            if (self::exprIsOp($pstate, '-')) {
                self::exprNext($pstate);
                $right = ['k' => 'neg', 'e' => self::exprPrimary($pstate)];
            } else {
                $right = self::exprPrimary($pstate);
            }
            $left = self::exprBin('^', $left, $right);
        }
        return $left;
    }

    /** 값 하나 — 숫자, 글자, 날짜, [필드], True/False/Null, 함수(…), ( 식 ). */
    private static function exprPrimary(array &$pstate): array
    {
        $where = self::exprWhere($pstate);
        $token = self::exprNext($pstate);
        $lower = '';
        $args = [];
        $inner = [];
        $limits = [];

        switch ($token['kind']) {
            case 'number':
                return ['k' => 'value', 'v' => self::toNumber($token['text'])];
            case 'string':
                return ['k' => 'string', 'v' => $token['text']];
            case 'date':
                return ['k' => 'value', 'v' => self::accessDateToSql($token['text'])];
            case 'field':
                return ['k' => 'field', 'name' => $token['text']];
            case 'lparen':
                $inner = self::exprOr($pstate);
                if (self::exprPeek($pstate)['kind'] !== 'rparen') {
                    throw new RuntimeException(self::exprWhere($pstate) . "')'가 와야 합니다(괄호가 덜 닫힘).");
                }
                self::exprNext($pstate);
                return $inner;
            case 'name':
                $lower = strtolower($token['text']);
                if ($lower === 'true') {
                    return ['k' => 'value', 'v' => true];
                }
                if ($lower === 'false') {
                    return ['k' => 'value', 'v' => false];
                }
                if ($lower === 'null') {
                    return ['k' => 'value', 'v' => null];
                }
                if (self::exprPeek($pstate)['kind'] !== 'lparen') {
                    throw new RuntimeException($where . '"' . $token['text'] . '"를 알 수 없습니다(필드는 [' . $token['text'] . ']처럼 대괄호로).');
                }
                if (!array_key_exists($lower, self::EXPR_FUNCTIONS)) {
                    throw new RuntimeException($where . '지원하지 않는 함수입니다: ' . $token['text'] . '(calcFormula::EXPR_FUNCTIONS).');
                }
                // 인자 목록.
                self::exprNext($pstate);
                if (self::exprPeek($pstate)['kind'] !== 'rparen') {
                    $args[] = self::exprOr($pstate);
                    while (self::exprPeek($pstate)['kind'] === 'comma') {
                        self::exprNext($pstate);
                        $args[] = self::exprOr($pstate);
                    }
                }
                if (self::exprPeek($pstate)['kind'] !== 'rparen') {
                    throw new RuntimeException(self::exprWhere($pstate) . $token['text'] . "의 ')'가 와야 합니다(괄호가 덜 닫혔거나 쉼표가 빠짐).");
                }
                self::exprNext($pstate);
                $limits = self::EXPR_FUNCTIONS[$lower];
                if (count($args) < $limits[0] || count($args) > $limits[1]) {
                    throw new RuntimeException($where . $token['text'] . '의 인자 수가 맞지 않습니다(' . count($args) . '개, ' . $limits[0] . '~' . $limits[1] . '개여야 함).');
                }
                return ['k' => 'call', 'name' => $lower, 'label' => $token['text'], 'args' => $args];
            case 'end':
                throw new RuntimeException($where . '식이 덜 끝났습니다.');
            default:
                throw new RuntimeException($where . '"' . $token['text'] . '" 자리에는 값이 와야 합니다.');
        }
    }

    /**
     * 트리 계산 — 노드 종류마다 하나씩. pcontext = ['db' => DbContext, 'map' => [필드 => 값]].
     * @return mixed
     */
    private static function exprEval(array $pnode, array &$pcontext)
    {
        $value = null;

        switch ($pnode['k']) {
            case 'value':
                return $pnode['v'];
            case 'string':
                return self::exprSubstitute($pnode['v'], $pcontext['map']);
            case 'field':
                if (!array_key_exists($pnode['name'], $pcontext['map'])) {
                    throw new RuntimeException('[' . $pnode['name'] . ']가 필드들 목록에 없습니다.');
                }
                return $pcontext['map'][$pnode['name']];
            case 'neg':
                return self::neg(self::exprEval($pnode['e'], $pcontext));
            case 'not':
                return self::notOp(self::exprEval($pnode['e'], $pcontext));
            case 'isnull':
                $value = self::exprEval($pnode['e'], $pcontext);
                if ($pnode['negate']) {
                    return $value !== null;
                }
                return $value === null;
            case 'bin':
                return self::exprBinary($pnode['op'], self::exprEval($pnode['l'], $pcontext), self::exprEval($pnode['r'], $pcontext));
            case 'call':
                return self::exprCall($pnode, $pcontext);
            default:
                throw new RuntimeException('알 수 없는 노드입니다: ' . $pnode['k']);
        }
    }

    /** 글자 안의 [필드]를 값 글자로(Access의 Replace와 같음 — 필드들 순서대로, 대소문자 구분). Null은 ''. */
    private static function exprSubstitute(string $ptext, array $pmap): string
    {
        $text = $ptext;

        foreach ($pmap as $name => $value) {
            $text = str_replace('[' . $name . ']', self::toText($value), $text);
        }
        return $text;
    }

    /** 두 값 연산 — 위 연산 함수에 맡긴다. @return mixed */
    private static function exprBinary(string $pop, $pleft, $pright)
    {
        switch ($pop) {
            case '+':
                return self::add($pleft, $pright);
            case '-':
                return self::sub($pleft, $pright);
            case '*':
                return self::mul($pleft, $pright);
            case '/':
                return self::div($pleft, $pright);
            case '\\':
                return self::intDiv($pleft, $pright);
            case 'mod':
                return self::mod($pleft, $pright);
            case '^':
                return self::pow($pleft, $pright);
            case '&':
                return self::concat($pleft, $pright);
            case 'like':
                return self::like($pleft, $pright);
            case 'and':
                return self::andOp($pleft, $pright);
            case 'or':
                return self::orOp($pleft, $pright);
            case '=':
            case '<>':
            case '<':
            case '>':
            case '<=':
            case '>=':
                return self::compare($pleft, $pop, $pright);
            default:
                throw new RuntimeException('알 수 없는 연산자입니다: ' . $pop);
        }
    }

    /**
     * 함수 호출 — IIf는 조건을 먼저 계산하고 고른 쪽만 계산한다. 나머지는 인자를 모두 계산한 뒤 부른다.
     * @return mixed
     */
    private static function exprCall(array $pnode, array &$pcontext)
    {
        $args = [];
        $criteria = '';
        $i = 0;

        if ($pnode['name'] === 'iif') {
            if (self::isTrue(self::exprEval($pnode['args'][0], $pcontext))) {
                return self::exprEval($pnode['args'][1], $pcontext);
            }
            return self::exprEval($pnode['args'][2], $pcontext);
        }

        for ($i = 0; $i < count($pnode['args']); $i++) {
            $args[] = self::exprEval($pnode['args'][$i], $pcontext);
        }

        switch ($pnode['name']) {
            case 'nz':
                if (count($args) === 2) {
                    return self::nz($args[0], $args[1]);
                }
                return self::nz($args[0]);
            case 'nz_':
                if (count($args) === 2) {
                    return self::nz_($args[0], $args[1]);
                }
                return self::nz_($args[0]);
            case 'isnull':
                return self::isNull($args[0]);
            case 'isnull_':
                return self::isnull_($args[0]);
            case 'cdata':
                return self::cdata($args[0]);
            case 'round':
                if (count($args) === 2) {
                    return self::round($args[0], $args[1]);
                }
                return self::round($args[0]);
            case 'dlookup':
            case 'dsum':
            case 'dcount':
            case 'davg':
            case 'dmin':
            case 'dmax':
                $criteria = '';
                if (count($args) === 3) {
                    $criteria = self::toText($args[2]);
                }
                return self::exprDomain($pnode['name'], $pcontext['db'], self::toText($args[0]), self::toText($args[1]), $criteria);
            default:
                throw new RuntimeException('지원하지 않는 함수입니다: ' . $pnode['label']);
        }
    }

    /** 도메인 함수 이름 → 해당 함수. @return mixed */
    private static function exprDomain(string $pname, DbContext $pdb, string $pexpr, string $pdomain, string $pcriteria)
    {
        switch ($pname) {
            case 'dlookup':
                return self::dlookup($pdb, $pexpr, $pdomain, $pcriteria);
            case 'dsum':
                return self::dsum($pdb, $pexpr, $pdomain, $pcriteria);
            case 'dcount':
                return self::dcount($pdb, $pexpr, $pdomain, $pcriteria);
            case 'davg':
                return self::davg($pdb, $pexpr, $pdomain, $pcriteria);
            case 'dmin':
                return self::dmin($pdb, $pexpr, $pdomain, $pcriteria);
            default:
                return self::dmax($pdb, $pexpr, $pdomain, $pcriteria);
        }
    }

    // ==================================================================
    // 도우미
    // ==================================================================

    /** VBA IsNumeric과 같은 판단 — 숫자, True/False, 숫자로 읽히는 글자('1,000' 포함). Null·''은 아니다. */
    public static function isNumeric($pvalue): bool
    {
        $text = '';

        if (is_int($pvalue) || is_float($pvalue) || is_bool($pvalue)) {
            return true;
        }
        if (!is_string($pvalue)) {
            return false;
        }
        $text = str_replace(',', '', trim($pvalue));
        if ($text === '') {
            return false;
        }
        return is_numeric($text);
    }

    /**
     * 숫자로 — 숫자가 아니면 오류(형식 불일치). True는 -1, False는 0(VBA와 같음).
     * 숫자로 읽히는 글자는 정수면 int, 아니면 float.
     * @return int|float
     */
    public static function toNumber($pvalue)
    {
        $text = '';

        if (is_int($pvalue) || is_float($pvalue)) {
            return $pvalue;
        }
        if (is_bool($pvalue)) {
            if ($pvalue) {
                return -1;
            }
            return 0;
        }
        if (!self::isNumeric($pvalue)) {
            throw new RuntimeException('숫자가 아닌 값입니다(형식 불일치): "' . self::toText($pvalue) . '"');
        }
        $text = str_replace(',', '', trim((string) $pvalue));
        if (preg_match('/^-?[0-9]+$/', $text) === 1) {
            return (int) $text;
        }
        return (float) $text;
    }

    /** 글자로 — Null은 '', True/False는 'True'/'False'(VBA와 같음), 숫자는 PHP 기본 표기. */
    public static function toText($pvalue): string
    {
        if ($pvalue === null) {
            return '';
        }
        if (is_bool($pvalue)) {
            if ($pvalue) {
                return 'True';
            }
            return 'False';
        }
        return (string) $pvalue;
    }

    /**
     * 조건 값이 참인지 — Null은 거짓. 숫자는 0이 아니면 참. 글자 'True'/'False'(대소문자 무시)와
     * 숫자로 읽히는 글자도 받는다. 그 밖의 글자는 오류(형식 불일치, Access와 같음).
     */
    public static function isTrue($pvalue): bool
    {
        $logic = self::toLogic($pvalue);

        if ($logic === true) {
            return true;
        }
        return false;
    }

    /** 3값 논리용 — true / false / null(Null). @return bool|null */
    private static function toLogic($pvalue)
    {
        $lower = '';

        if ($pvalue === null) {
            return null;
        }
        if (is_bool($pvalue)) {
            return $pvalue;
        }
        if (is_string($pvalue)) {
            $lower = strtolower(trim($pvalue));
            if ($lower === 'true') {
                return true;
            }
            if ($lower === 'false') {
                return false;
            }
        }
        if (self::isNumeric($pvalue)) {
            return (float) self::toNumber($pvalue) !== 0.0;
        }
        throw new RuntimeException('참/거짓으로 쓸 수 없는 값입니다(형식 불일치): "' . self::toText($pvalue) . '"');
    }

    /** 계산 결과 — 정수로 딱 떨어지면 int(JSON에 1000.0 대신 1000), 아니면 float. @return int|float */
    private static function numberResult($pnumber)
    {
        if (is_float($pnumber) && floor($pnumber) === $pnumber && abs($pnumber) < PHP_INT_MAX) {
            return (int) $pnumber;
        }
        return $pnumber;
    }

    /** 숫자 비교 — 작으면 -1, 같으면 0, 크면 1. */
    private static function spaceship(float $pleft, float $pright): int
    {
        if ($pleft < $pright) {
            return -1;
        }
        if ($pleft > $pright) {
            return 1;
        }
        return 0;
    }

    /** '[이름]' → '이름'. */
    private static function stripBrackets(string $pname): string
    {
        $name = trim($pname);

        if (mb_substr($name, 0, 1, 'UTF-8') === '[' && mb_substr($name, -1, 1, 'UTF-8') === ']') {
            return mb_substr($name, 1, mb_strlen($name, 'UTF-8') - 2, 'UTF-8');
        }
        return $name;
    }

    /** 필드 이름을 테이블 필드 목록에서 찾는다(대소문자 무시, 목록의 표기로 돌려준다). 없으면 오류. */
    private static function matchField(string $pname, array $pfields): string
    {
        $i = 0;

        for ($i = 0; $i < count($pfields); $i++) {
            if (mb_strtolower($pfields[$i], 'UTF-8') === mb_strtolower($pname, 'UTF-8')) {
                return $pfields[$i];
            }
        }
        throw new RuntimeException('테이블에 없는 필드입니다: ' . $pname);
    }
}
