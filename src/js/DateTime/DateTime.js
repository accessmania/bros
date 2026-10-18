/**
 * DateTime — MS Access VBA의 날짜/시간 관련 전역 함수(cdata/transMinuteHour/
 * mDateTime_a/mDateTime_get요일)를 옮긴 순수 함수 유틸리티. `Resize.js`와
 * 동일한 관례 — `class`가 아니라 인스턴스 없는 객체 리터럴 namespace이고,
 * 원본 VBA 함수 이름을 그대로 쓴다(이 함수들은 이미 Access 어휘 그대로라
 * `Resize.js`처럼 영문/한글 별칭을 따로 만들 필요가 없다).
 *
 * ## cdata(x) — 숫자형 변환
 * VBA 원본의 `On Error Resume Next`(변환 실패해도 죽지 않는다)와 같은
 * 취지로, `Number()`로 바꿀 수 없는 값(`null`/`undefined`/빈 문자열/
 * 숫자가 아닌 문자열 등)은 예외를 던지지 않고 `0`을 반환한다. 이 함수가
 * 아래 `transMinuteHour()`가 자기 인자를 방어적으로 다루는 데도 그대로
 * 쓰인다(원본 VBA도 동일).
 *
 * ## transMinuteHour(pmin, ph, pn) — 분을 "H시간N분" 문자열로
 * 원본 VBA는 `Format(totaln/60,"#,##0.0")`를 "."로 잘라 정수부(arr(0))를
 * 시간으로 삼았는데, 여기서는 `Math.floor(totalN/60)`로 동일한 결과를 더
 * 단순하게 얻는다(정상적인 정수 분 입력에서는 두 계산이 같은 값을 낸다).
 * 시간이 0이면 분만("N분"), 분이 0 이하면 시간만("H시간"), 둘 다 있으면
 * "H시간N분" — 원본 분기 로직 그대로.
 *
 * ## mDateTime_get요일(pDate, pType) — 날짜의 요일
 * `pType`을 생략하면(또는 원본 VBA의 매개변수 나열형 기본값 문자열
 * "한글,영어,한자,숫자"를 그대로 주면) "한글"로 취급한다 — VBA가 Optional
 * 매개변수의 기본값 자체를 "고를 수 있는 값들의 안내문"으로 써먹은 관용구를
 * 그대로 옮겼다. `pDate`를 생략하면 오늘 날짜를 쓴다(원본의
 * `If isnull_(p_date) Then p_date = Ldate`에 대응).
 *
 * ## mDateTime_a(pdate) — mDateTime_get요일()의 얇은 래퍼
 * 원본 VBA와 동일하게 아무 가공 없이 그대로 위임한다.
 *
 * ## diffTime(t1, t2) — [2026-09-19 추가] 두 시각의 분 차이
 * VBA `cDateTime` 클래스의 `.diffTime()` 메서드를 이식 — `deco_amd_amd_miss.php`의
 * `calcTime()`(VBA `f경과시간계산()` 이식)이 경과시간을 구하는 데 쓴다.
 * "HH:MM:SS" 문자열만 받고, 자정을 넘겨 t2<t1이면 24시간을 더해 보정한다.
 *
 * ## 알려진 차이
 * - VBA `Weekday()`는 1(일)~7(토)를 반환한다 — `mDateTime_get요일(...,
 *   '숫자')`도 이 값 그대로(1~7)를 돌려주도록 JS `Date.getDay()`(0~6)에
 *   +1을 해서 맞췄다.
 * - `pDate`에 문자열/타임스탬프/Date 인스턴스 어느 것을 넘겨도 되도록
 *   `new Date(pDate)`로 파싱한다 — 파싱에 실패하면(Invalid Date) 빈
 *   문자열을 반환한다(예외를 던지지 않는다).
 */
const DateTime = {};

/**
 * cdata(x) — x를 숫자형으로 바꾼다. 변환할 수 없으면 0을 반환한다
 * (예외를 던지지 않는다).
 */
DateTime.cdata = function (x) {
    if (x === null || x === undefined || x === '') {
        return 0;
    }
    const n = Number(x);
    return isNaN(n) ? 0 : n;
};

/**
 * transMinuteHour(pmin, ph, pn) — 분 단위 총합(pmin)을 "H시간N분" 형태의
 * 문자열로 바꾼다. `ph`(시간 단위 접미사, 기본 "시간")/`pn`(분 단위
 * 접미사, 기본 "분")로 표시 문구를 바꿀 수 있다.
 */
DateTime.transMinuteHour = function (pmin, ph, pn) {
    if (ph === undefined || ph === null) {
        ph = '시간';
    }
    if (pn === undefined || pn === null) {
        pn = '분';
    }

    const totalN = DateTime.cdata(pmin);
    const h = Math.floor(totalN / 60);
    let n;
    if (h === 0) {
        n = totalN;
    } else {
        n = totalN - (h * 60);
    }

    let str;
    if (h === 0) {
        str = n + pn;
    } else if (DateTime.cdata(n) <= 0) {
        str = h + ph;
    } else {
        str = h + ph + n + pn;
    }
    return str;
};

/**
 * mDateTime_get요일(pDate, pType) — 날짜(pDate, 생략 시 오늘)의 요일을
 * `pType`(`'한글'`(기본)|`'영어'`|`'한자'`|`'숫자'`)에 맞춰 반환한다.
 * `pType`을 생략하거나 원본 VBA 기본값 문자열("한글,영어,한자,숫자")을
 * 그대로 주면 "한글"로 취급한다.
 */
DateTime.mDateTime_get요일 = function (pDate, pType) {
    if (pType === undefined || pType === null || pType === '한글,영어,한자,숫자') {
        pType = '한글';
    }

    let d;
    if (pDate === undefined || pDate === null || pDate === '') {
        d = new Date();
    } else if (pDate instanceof Date) {
        d = pDate;
    } else {
        d = new Date(pDate);
    }
    if (isNaN(d.getTime())) {
        return '';
    }

    const weekday = d.getDay(); // 0(일)~6(토) — VBA Weekday()는 1(일)~7(토).
    const 한글 = ['일', '월', '화', '수', '목', '금', '토'];
    const 영어 = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const 한자 = ['日', '月', '火', '水', '木', '金', '土'];

    if (pType === '한글') {
        return 한글[weekday];
    }
    if (pType === '영어') {
        return 영어[weekday];
    }
    if (pType === '한자') {
        return 한자[weekday];
    }
    if (pType === '숫자') {
        return weekday + 1; // VBA Weekday()와 동일하게 1(일)~7(토)로 맞춤.
    }
    return '';
};

/** mDateTime_a(pdate) — mDateTime_get요일()에 그대로 위임(원본 VBA와 동일). */
DateTime.mDateTime_a = function (pdate) {
    return DateTime.mDateTime_get요일(pdate);
};

/**
 * [2026-09-19 추가] diffTime(t1, t2) — VBA `cDateTime` 클래스의
 * `.diffTime()` 메서드를 이식. "HH:MM:SS" 문자열 두 개(t1이 앞, t2가 뒤)의
 * 차이를 분 단위로 반환한다. t2가 t1보다 이르면(자정을 넘긴 경우로 보고)
 * 24시간을 더해 보정한다. 둘 중 하나라도 "HH:MM:SS" 형식이 아니면(원본의
 * `On Error Resume Next`와 같은 취지로) 예외를 던지지 않고 0을 반환한다.
 */
DateTime.diffTime = function (t1, t2) {
    function parse(t) {
        if (typeof t !== 'string') {
            return null;
        }
        var m = /^(\d{1,2}):(\d{2}):(\d{2})$/.exec(t.trim());
        if (!m) {
            return null;
        }
        var d = new Date();
        d.setHours(Number(m[1]), Number(m[2]), Number(m[3]), 0);
        return d;
    }

    var d1 = parse(t1);
    var d2 = parse(t2);
    if (!d1 || !d2) {
        return 0;
    }

    var diffMs = d2.getTime() - d1.getTime();
    if (diffMs < 0) {
        diffMs += 24 * 60 * 60 * 1000;
    }
    return Math.floor(diffMs / 60000);
};
