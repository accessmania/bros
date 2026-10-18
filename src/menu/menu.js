/**
 * 좌측 메뉴 정의 — index.php가 이 파일을 불러오고, _initMenu()가 이 값을
 * 그대로 주입받는다(메뉴를 바꿀 때 index.php는 손대지 않고 이 파일만 고친다).
 *
 * 구조:
 *  - tabMenuAutoHideDelayMs  탭 메뉴 자동 축소까지의 시간(ms)
 *  - categories[]            좌측 아코디언 한 칸
 *      name   표시 이름
 *      group  같은 group끼리만 탭이 공존한다(다른 group 메뉴를 열면 나머지 탭은 닫힘)
 *      items[]
 *        title        표시 이름 — 탭 재사용/선택 표시/variableBank 폼 찾기의 열쇠라
 *                     **메뉴 전체에서 유일해야 한다**
 *        ribbon       이 메뉴를 볼 수 있는 권한 — tAuth.authstring의 리본 메뉴
 *                     항목 중 버튼 이름(예: 'tabBROSPOWER;group업무;button송금'의
 *                     'button송금'). 로그인 사용자의 리본 버튼 목록(index.php가
 *                     Auth::getRibbonButtons()로 넘김)에 있어야 보인다. 생략하면
 *                     권한과 상관없이 항상 보인다(개발용 화면 등).
 *                     마스터 권한이면 ribbon과 상관없이 모든 메뉴가 보인다.
 *                     보이는 항목이 하나도 없는 분류는 분류째 안 보인다.
 *        src          열 화면 경로(index.php 기준). null이면 즉석 데모 화면
 *        themeParam   false면 src 뒤에 ?theme=를 붙이지 않는다(기본 붙임)
 *        autoHideMenu true면 탭/좌측 메뉴 자동 숨김 규칙 적용
 */
var MENU_JSON = {
  tabMenuAutoHideDelayMs: 7000,
  categories: [
    {
      name: '업무',
      group: '업무',
      items: [
        { title: '문의', ribbon: 'button업무', src: null },
        { title: '문의내용', ribbon: 'button업무', src: null },
        { title: '견적', ribbon: 'button업무', src: null },
        { title: '수주', ribbon: 'button업무', src: null },
        { title: '발주', ribbon: 'button업무', src: null },
        { title: '선직미입고', ribbon: 'button업무', src: null },
        { title: '납품출하대기', ribbon: 'button업무', src: null },
      ],
    },
    {
      name: '송금',
      group: '업무',
      items: [
        { title: '송금전체', ribbon: 'button송금', src: null },
        { title: '원화송금관리', ribbon: 'button송금', src: null },
        { title: '외화송금관리', ribbon: 'button송금', src: null },
      ],
    },
    {
      name: '송금B',
      group: '업무',
      items: [
        { title: '송금B전체', ribbon: 'button송금B', src: null },
        { title: '원화송금관리B', ribbon: 'button송금B', src: null },
        { title: '외화송금관리B', ribbon: 'button송금B', src: null },
      ],
    },
    {
      name: '미수금',
      group: '업무',
      items: [
        { title: '미수금전체', ribbon: 'button미수금', src: null },
        { title: '미수금>0', ribbon: 'button미수금', src: null },
        { title: 'USD미수금>0', ribbon: 'button미수금', src: null },
      ],
    },
    {
      name: '미수금B',
      group: '업무',
      items: [
        { title: '미수금B전체', ribbon: 'button미수금B', src: null },
        { title: '미수금B>0', ribbon: 'button미수금B', src: null },
        { title: 'USD미수금B>0', ribbon: 'button미수금B', src: null },
      ],
    },
    {
      name: '일정 및 기타',
      group: '업무',
      items: [
        { title: '일정', ribbon: 'button일정', src: null },
        { title: '고객사목록', ribbon: 'button고객사', src: null },
        { title: '제조사목록', ribbon: 'button제조사', src: null },
        { title: 'ECount', ribbon: 'buttonECount', src: null },
      ],
    },
    {
      name: '기초자료',
      group: '기초자료',
      items: [
        { title: '로그인', ribbon: 'button기초자료', src: null },
        { title: '권한', ribbon: 'button기초자료', src: null },
        { title: '제품', ribbon: 'button기초자료', src: null },
        { title: '고객사', ribbon: 'button기초자료', src: null },
        { title: '고객사 직원목록', ribbon: 'button기초자료', src: null },
        { title: '제조사', ribbon: 'button기초자료', src: null },
        { title: '상태', ribbon: 'button기초자료', src: null },
        { title: 'API', ribbon: 'button기초자료', src: null },
        { title: '운임표', ribbon: 'button기초자료', src: null },
        { title: '필드감추기', ribbon: 'button기초자료', src: null },
        { title: '통화단위', ribbon: 'button기초자료', src: null },
      ],
    },
    {
      // 연습(자기 검증) 화면 모음 — 업무 화면과 함께 열어 둘 수 있게 group은 '업무'.
      name: '개발',
      group: '업무',
      items: [
        { title: '권한정보', src: 'src/Auth/auth_entry.php', themeParam: false },
        { title: 'variableBank', src: 'src/variableBank/variableBank_entry.php', themeParam: false },
      ],
    },
  ],
};

/**
 * 로그인 사용자의 리본 버튼 목록(ribbonButtons)으로 메뉴를 거른 새 메뉴를
 * 돌려준다(원본 MENU_JSON은 건드리지 않는다).
 *  - isMaster(마스터 권한)면 거르지 않고 모든 메뉴를 그대로 돌려준다.
 *  - ribbon이 있는 항목은 ribbonButtons에 그 값이 있을 때만 남긴다.
 *  - ribbon이 없는 항목은 항상 남긴다.
 *  - 남은 항목이 하나도 없는 분류는 분류째 뺀다.
 *
 * 화면에서 숨기는 것뿐이다 — 각 화면 PHP/fetch는 서버에서 따로 권한을 확인해야 한다.
 */
function filterMenuByRibbon(menu, ribbonButtons, isMaster) {
  if (isMaster === true) {
    return menu;
  }
  var allowed = Array.isArray(ribbonButtons) ? ribbonButtons : [];
  var categories = [];
  menu.categories.forEach(function (cat) {
    var items = cat.items.filter(function (item) {
      return !item.ribbon || allowed.indexOf(item.ribbon) >= 0;
    });
    if (items.length) {
      categories.push(Object.assign({}, cat, { items: items }));
    }
  });
  return Object.assign({}, menu, { categories: categories });
}
