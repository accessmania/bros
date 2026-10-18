<?php
/**
 * tLogin AGGrid 프론트엔드 config — 컬럼 정의/이벤트 규칙.
 * 컨테이너 페이지(test.php)는 이 파일을 require만 한다.
 */
$tLoginFetchUrl = APP_BASE_URL . '/test/fetch_tLogin.php';
?>
<script>
new AGGrid({
  gridId: 'tLogin',
  fetchUrl: <?= json_encode($tLoginFetchUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  primaryKey: 'code',
  columns: [
    { field: 'id', headerName: 'ID', editable: true, dataType: 'string' },
    { field: 'pw', headerName: '비밀번호', editable: true, dataType: 'string' },
    { field: '이름', headerName: '이름', editable: true },
    { field: '부서', headerName: '부서', editable: true },
    { field: '직급', headerName: '직급', editable: true },
    { field: '유선전화', headerName: '유선전화', editable: true, dataType: 'string' },
    { field: '핸드폰', headerName: '핸드폰', editable: true, dataType: 'string' },
    { field: '이메일', headerName: '이메일', editable: true },
    { field: '권한code', headerName: '권한code', editable: true, dataType: 'string' },
    { field: '권한', headerName: '권한', editable: true },
    { field: '사용여부', headerName: '사용여부', editable: true, align: 'center' },
    { field: '지분률', headerName: '지분률', editable: true, dataType: 'number' },
    { field: '메모', headerName: '메모', editable: true },
  ],
  fetchMode: 'paged',
  pageSize: 100,
  autoFitBottomGap: 40,
  minHeight: 200,
});
</script>
