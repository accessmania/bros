<?php

declare(strict_types=1);

/**
 * 문자열 출력과 값 변환 관련 공통 유틸리티.
 *
 * 변환 함수들(toNumber/toInt/toBool/toDate/...)은 VBA 시절의 `cdata()`(str
 * 모듈의 강제 변환 함수)를 PHP로 옮긴 것이다 — DB 값이나 사용자 입력을
 * 숫자/불린/날짜로 판단해야 할 때, 화면/클래스마다 `=== 1 || === '1'`
 * 같은 판정을 따로 두지 않고 이 한 곳의 규칙을 쓴다(자세한 규칙은
 * 같은 폴더의 Str.md 참고).
 *
 * 공통 원칙: 예외를 던지지 않는다(VBA의 On Error Resume Next와 같은 취지).
 * 변환할 수 없는 값이면 호출부가 넘긴 $default를 돌려준다 — 실패를 0이나
 * 오늘 날짜로 조용히 바꾸지 않고, 무엇을 돌려받을지 호출부가 정한다.
 *
 * PHP 7 호환 문법만 사용 (union type / mixed 등 8.0 전용 문법 미사용).
 */
class Str
{
    /** toBool()이 참으로 보는 문자열(소문자, trim 후 비교). 숫자 문자열은 별도 규칙(0이 아니면 참). */
    const TRUE_STRINGS = ['true', 'y', 'yes', 'on'];

    /** toBool()이 거짓으로 보는 문자열(소문자, trim 후 비교). 빈 문자열과 '0'도 거짓. */
    const FALSE_STRINGS = ['false', 'n', 'no', 'off'];

    /**
     * toDate()가 문자열을 해석할 때 차례로 시도하는 형식. 앞의 '!'는
     * 지정하지 않은 부분(시각 등)을 현재 시각이 아니라 0으로 채우게 한다.
     * 'M j Y g:i:s:vA'는 MSSQL을 pdo_dblib로 읽을 때 오는 날짜 형식
     * (예: 'Oct 12 2026 12:00:00:000AM')이다.
     */
    const DATE_INPUT_FORMATS = [
        '!Y-m-d',
        '!Y-m-d H:i:s',
        '!Y-m-d H:i:s.u',
        '!Y-m-d H:i',
        '!Y-m-d\TH:i:s',
        '!Y-m-d\TH:i',
        '!Y/m/d',
        '!Y/m/d H:i:s',
        '!Y.m.d',
        '!Ymd',
        '!M j Y g:i:s:vA',
        '!M j Y g:iA',
    ];

    /**
     * HTML 출력용으로 안전하게 이스케이프한다.
     * ENT_QUOTES로 홑/겹따옴표까지 이스케이프해 속성값에 넣어도 안전하게 하고,
     * 'UTF-8'을 명시해 서버 php.ini의 default_charset 설정과 무관하게 동작하게 한다.
     *
     * @param mixed $value
     */
    public static function escape($value): string
    {
        // (string) $value        : 정수/실수/null 등 문자열이 아닌 값이 들어와도
        //                           htmlspecialchars()가 타입 오류 없이 처리하도록 문자열로 캐스팅.
        // htmlspecialchars(...)  : 문자열 안의 HTML 특수문자를 엔티티로 치환해 그대로 출력해도
        //                           브라우저가 태그/속성으로 해석하지 않게 만든다(XSS 방지).
        //                           & → &amp;  " → &quot;  ' → &#039;  < → &lt;  > → &gt;
        // ENT_QUOTES              : 겹따옴표(")뿐 아니라 홑따옴표(')까지 함께 이스케이프한다.
        //                           기본값(ENT_QUOTES 미지정 시 ENT_HTML401 = 겹따옴표만)로는
        //                           <input value='...'>처럼 홑따옴표로 감싼 속성에 넣을 때
        //                           값에 홑따옴표가 섞이면 그대로 속성이 깨질 수 있어 안전하지 않음.
        // 'UTF-8'                 : 인코딩을 명시적으로 지정. 생략하면 php.ini의 default_charset을
        //                           따르는데, 이 서버는 그 값이 깨진 값(euckr 계열)으로 설정돼 있어
        //                           경고가 나거나 한글이 깨질 수 있어(md/2026-08-09-crud-class.md
        //                           참고) 항상 UTF-8로 고정해 서버 설정과 무관하게 동작시킨다.
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 숫자로 바꾼다. 문자열의 앞뒤 공백과 천 단위 콤마("1,234")는 무시한다.
     * true/false는 1/0. 변환할 수 없으면(null, '', 'abc' 등) $default.
     *
     * VBA cdata의 "0toNull" 기본 동작은 옮기지 않았다 — 금액/수량 0이
     * 조용히 null이 되지 않게, 필요한 곳에서 zeroToNull()을 명시적으로 쓴다.
     *
     * @param mixed $value
     */
    public static function toNumber($value, ?float $default = 0.0): ?float
    {
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return $default;
        }

        $text = str_replace(',', '', trim($value));
        if ($text === '' || !is_numeric($text)) {
            return $default;
        }
        return (float) $text;
    }

    /**
     * 정수로 바꾼다. 소수는 반올림(0.5는 0에서 먼 쪽으로). 변환할 수 없으면 $default.
     *
     * @param mixed $value
     */
    public static function toInt($value, ?int $default = 0): ?int
    {
        $number = self::toNumber($value, null);
        if ($number === null) {
            return $default;
        }
        return (int) round($number);
    }

    /**
     * 0이면 null, 아니면 그 숫자 — VBA cdata의 "0toNull" 옵션에 해당.
     * 변환할 수 없는 값도 null이다.
     *
     * @param mixed $value
     */
    public static function zeroToNull($value): ?float
    {
        $number = self::toNumber($value, null);
        return ($number === null || $number == 0) ? null : $number;
    }

    /**
     * 불린으로 바꾼다.
     *  - 참: true, 0이 아닌 숫자(Access Yes/No의 -1 포함), TRUE_STRINGS
     *  - 거짓: false, null, '', 0, '0', FALSE_STRINGS
     *  - 그 밖의 값(예: 'abc'): $default
     *
     * VBA cdata는 "숫자가 아닌 문자열은 전부 True"였지만, 그러면 'N'/'no'/
     * 'off'까지 참이 되므로 참/거짓 목록을 명시하고 나머지는 $default로 뒀다.
     *
     * @param mixed $value
     */
    public static function toBool($value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return false;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }
        if (!is_string($value)) {
            return $default;
        }

        $text = strtolower(trim($value));
        if ($text === '') {
            return false;
        }
        if (is_numeric($text)) {
            return (float) $text != 0;
        }
        if (in_array($text, self::TRUE_STRINGS, true)) {
            return true;
        }
        if (in_array($text, self::FALSE_STRINGS, true)) {
            return false;
        }
        return $default;
    }

    /**
     * toBool() 결과를 'Yes'/'No'로 — VBA cdata의 "불린yn"에 해당(표시용).
     *
     * @param mixed $value
     */
    public static function toYesNo($value): string
    {
        return self::toBool($value) ? 'Yes' : 'No';
    }

    /**
     * 날짜 문자열로 바꾼다($format, 기본 'Y-m-d'). DateTimeInterface와
     * DATE_INPUT_FORMATS의 문자열(dblib 형식 포함)을 받는다. 변환할 수
     * 없거나 없는 날짜(2026-02-30 등)면 $default.
     *
     * VBA cdata는 빈 값을 오늘 날짜로 바꿨지만, 잘못된 입력이 오늘로
     * 저장돼도 모르게 되므로 옮기지 않았다 — 오늘이 필요하면 호출부가
     * $default로 date('Y-m-d')를 넘긴다.
     *
     * @param mixed $value
     */
    public static function toDate($value, ?string $default = null, string $format = 'Y-m-d'): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format($format);
        }
        if (!is_string($value)) {
            return $default;
        }

        // dblib는 한 자리 일자를 'Oct  2 2026'처럼 공백 두 칸으로 채운다.
        $text = preg_replace('/\s+/', ' ', trim($value));
        if ($text === '') {
            return $default;
        }

        foreach (self::DATE_INPUT_FORMATS as $inputFormat) {
            $date = DateTime::createFromFormat($inputFormat, $text);
            if ($date === false) {
                continue;
            }
            // createFromFormat()은 2026-02-30을 03-02로 넘겨버리므로 경고가 있으면 거부한다.
            $errors = DateTime::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }
            return $date->format($format);
        }
        return $default;
    }

    /**
     * 숫자를 천 단위 콤마 형식(#,##0)으로 — VBA cdata의 "숫자표준"에
     * 해당(표시용 서식). 변환할 수 없는 값은 0으로 표시한다.
     *
     * @param mixed $value
     */
    public static function formatNumber($value, int $decimals = 0): string
    {
        return number_format((float) self::toNumber($value, 0.0), $decimals);
    }

    /**
     * VBA cdata(p_value, p_type)과 같은 이름의 창구 — 위 함수들로 넘긴다.
     * 새 코드는 반환 타입이 분명한 개별 함수(toBool 등)를 직접 쓰는 것을
     * 권장한다. 모르는 $type이면 오타가 조용히 넘어가지 않도록 예외를 던진다.
     *
     * $type: '숫자'|'n', '정수'|'i', '불린'|'b', '불린yn', '날짜'|'d', '숫자표준'|'f'
     *
     * @param mixed $value
     * @return mixed
     */
    public static function cdata($value, string $type = '숫자')
    {
        switch ($type) {
            case '숫자':
            case 'n':
                return self::toNumber($value);
            case '정수':
            case 'i':
                return self::toInt($value);
            case '불린':
            case 'b':
                return self::toBool($value);
            case '불린yn':
                return self::toYesNo($value);
            case '날짜':
            case 'd':
                return self::toDate($value);
            case '숫자표준':
            case 'f':
                return self::formatNumber($value);
            default:
                throw new InvalidArgumentException("Str::cdata()가 모르는 타입입니다: {$type}");
        }
    }
}
