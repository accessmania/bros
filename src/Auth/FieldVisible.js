/**
 * FieldVisible.js — 필드감추기 적용(화면 JS 쪽). 사용법·원리는 FieldVisible.md.
 *
 * 서버의 FieldVisible::init()이 만든 {success, error, recs}를 받아, recs 가운데
 * nickname이 화면 이름과 같고 visiblefalse가 참인 fieldname 컬럼을 그리드에서 숨긴다.
 * Access의 applyDs("문의내용", Me.subds.Form)와 같은 일(인자 순서만 다름).
 */

/**
 * Applyds(pgrid, pnickname, pfieldvisible)
 *  - pgrid         AGGrid 인스턴스(onReady의 grid). gridApi가 있어야 한다.
 *  - pnickname     화면 이름(tfieldvisible.nickname) — 예: '문의', '문의내용'
 *  - pfieldvisible FieldVisible::init()의 결과를 json_encode한 값
 * 돌려주는 값: 숨긴 필드명 배열. init이 실패했으면 false(콘솔에 이유).
 * 그리드에 없는 fieldname은 건너뛰고 콘솔에 경고한다.
 */
function Applyds(pgrid, pnickname, pfieldvisible) {
    var recs;
    var rec;
    var hideFields;
    var i;

    if (!pfieldvisible || pfieldvisible.success !== true) {
        if (pfieldvisible) {
            console.warn('필드감추기 적용 안 됨(' + pnickname + '): ' + pfieldvisible.error);
        } else {
            console.warn('필드감추기 적용 안 됨(' + pnickname + '): 필드감추기 자료가 없습니다.');
        }
        return false;
    }

    recs = pfieldvisible.recs;
    hideFields = [];
    for (i = 0; i < recs.length; i++) {
        rec = recs[i];
        if (rec.nickname !== pnickname) {
            continue;
        }
        if (rec.visiblefalse !== true) {
            continue;
        }
        if (pgrid.gridApi.getColumn(rec.fieldname) === null) {
            console.warn('필드감추기(' + pnickname + '): 그리드에 없는 필드 - ' + rec.fieldname);
            continue;
        }
        hideFields.push(rec.fieldname);
    }

    if (hideFields.length > 0) {
        pgrid.gridApi.setColumnsVisible(hideFields, false);
    }
    return hideFields;
}
