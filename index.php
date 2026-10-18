<?php
/* Tailwind CLI로 빌드한 CSS를 filemtime() 기반 캐시버스팅과 함께 로드한다
 * (deco_entry_asset_url() 등 이 프로젝트 전역의 관례와 동일) — 이게 없으면
 * 브라우저가 예전 tailwind.css를 계속 캐시해서 새로 빌드해도 반영이 안
 * 될 수 있다. */
$amaniaCssPath = __DIR__ . '/src/css/amania/tailwind.css';
$amaniaCssVersion = is_file($amaniaCssPath) ? filemtime($amaniaCssPath) : time();

/**
 * amania/index.php가 이제 로그인 성공 후 이동하는 첫 화면(login_action.php의
 * $defaultUrl = AMANIA_BASE_URL . '/index.php')이 됐으므로, Layout의
 * index.php와 동일한 원칙으로 이 파일 맨 위에서 Login::isAlive()를 건다 —
 * 로그인 안 된 채로 직접 이 URL에 들어오면 로그인 화면으로 돌려보낸다.
 * 좌측 메뉴 "BROS POWER" 옆에 보여줄 로그인 사용자 이름도 여기서 함께 구한다.
 */
for ($__bootDir = __DIR__; !is_file($__bootDir . '/config/Path.php'); $__bootDir = dirname($__bootDir)) {
    if ($__bootDir === dirname($__bootDir)) {
        throw new RuntimeException('프로젝트 루트(config/Path.php)를 찾을 수 없습니다.');
    }
}
require_once $__bootDir . '/config/Path.php';
unset($__bootDir);
include_custom('Login');
Login::isAlive();
$amaniaCurrentUserName = (string) Login::getValue('이름');
$amaniaLogoutUrl = LOGIN_BASE_URL . '/logout.php';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>BROS POWER</title>

<link rel="stylesheet" href="src/css/amania/tailwind.css?v=<?= $amaniaCssVersion ?>" />

<!-- variableBank(앱 전역 값 저장소) — action()의 default가 넘긴다. -->
<script src="src/variableBank/variableBank.js?v=<?= filemtime(__DIR__ . '/src/variableBank/variableBank.js') ?>"></script>
<!-- 좌측 메뉴 정의(MENU_JSON) — _initMenu()가 주입받는다. -->
<script src="src/menu/menu.js?v=<?= filemtime(__DIR__ . '/src/menu/menu.js') ?>"></script>
<!-- 로그인 사용자의 리본 버튼 목록/마스터 여부(세션의 tAuth.authstring에서) — 메뉴 표시용 복사본. -->
<script>
var AUTH_RIBBON_BUTTONS = <?= json_encode(Auth::getRibbonButtons(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var AUTH_IS_MASTER = <?= Auth::isMaster() ? 'true' : 'false' ?>;
</script>

<script>
/**
 * amania 메인 셸(좌측 메뉴 + 탭바 + iframe 영역)의 Alpine 데이터.
 *
 * 이 프로젝트의 deco 화면군(deco_main.php/RoomBoard.js/SplitView.js 등)이
 * 쓰는 관례를 그대로 따른다 — 화면 안에서 일어나는 일은 전부
 * action(pcmd, pparams, psender) 하나를 거치고, action()은 실제 로직을
 * 갖지 않는 라우터로만 남아 기능별로 뭉친 하위 함수(action_menu/
 * action_tab)에 위임한다. initMenu/selectLeftMenu/selectTabMenu는 템플릿
 * (@click 등)에서 그대로 부를 수 있는 얇은 공개 래퍼이자, 동시에
 * action()의 pcmd 이름이기도 하다 — 둘 중 어느 경로로 불러도 결과가
 * 같다(deco_main.php의 openForm()/action('openForm',...) 관계와 동일).
 *
 * initMenu — 메뉴 주입 지점. 좌측 메뉴 구조와 각 항목이 열 iframe의
 *   출처(src)를 같은 JSON 하나(categories) 안에 함께 둔다(예전의 별도
 *   pageMap을 없애고 items 배열의 각 항목에 흡수). src가 없는 항목은
 *   selectLeftMenu가 즉석 데모 페이지로 대체한다.
 * selectLeftMenu — 좌측 메뉴 항목 선택 시 탭을 열고(이미 열려 있으면
 *   포커스만) iframe에 그 항목의 src를 채운다. 좌측 메뉴를 선택 시점에
 *   자동으로 닫지는 않는다 — 대신 탭 메뉴가 숨겨져 있었다면 항상 다시
 *   보이게 한다(tabMenuShow()). 좌측/탭 메뉴를 함께 숨기는 경로는 (a)
 *   7초 무호버 자동축소 타이머, (b) 우측 플로트 버튼 클릭 두 가지뿐이다
 *   (아래 tabMenuHide/tabMenuShow 절 참고).
 * selectTabMenu — 이미 열려 있는 탭을 선택해 화면(iframe)을 전환한다.
 *   이것도 좌측 메뉴를 자동으로 닫지 않는다(예전엔 닫았으나 제거됨).
 * leftMenuHide/leftMenuShow — 좌측 메뉴(사이드바)를 감추거나 보인다.
 *   <aside>가 이미 갖고 있는 width 트랜지션을 그대로 타므로 슬라이딩
 *   애니메이션이 자동으로 적용된다.
 * tabMenuHide/tabMenuShow — 상단 탭 메뉴(탭바 툴바 전체)를 감추거나
 *   보인다. x-collapse(이미 로드된 Alpine collapse 플러그인)로 높이가
 *   접히며 사라지는 애니메이션을 준다. 7초 무호버 자동축소 타이머가
 *   시간이 다 되면 leftMenuHide()도 함께 불러 좌측 메뉴까지 같이 숨긴다
 *   (_scheduleTabMenuAutoHide() 참고 — 이름은 여전히 tabMenu 기준이지만
 *   실제로 숨기는 건 좌측 메뉴까지 함께다).
 */
function amaniaShell() {
  // 탭 메뉴(상단 탭바) 자동 축소 타이머 — Alpine 반응형 상태로 안 두고
  // 클로저 변수로만 둔다(타이머 id 자체는 화면에 표시할 값이 아니라
  // 반응형으로 추적할 이유가 없다).
  let tabMenuAutoHideTimer = null;

  return {
    // ── 상태 ──
    // sidebarOpen/theme는 여기서 새로 선언하지 않는다 — <body>의 바깥
    // x-data가 이미 갖고 있는 상태를 그대로 물려받아 쓴다(중첩 x-data의
    // 프로토타입 체인 상속). 여기서 같은 이름으로 다시 선언하면 바깥
    // 상태를 가려버려(shadowing) 테마 토글이 <body>의 $watch('theme')에
    // 더 이상 안 닿는 문제가 생긴다.
    categories: [],
    openCategory: null,
    tabs: [],
    activeTab: null,
    tabMenuOpen: true,
    // _initMenu()가 메뉴 JSON에서 실제 값을 주입하기 전까지의 기본값 —
    // initMenu()보다 먼저 스케줄이 걸릴 일은 없지만(순서는 init() 참고)
    // 방어적으로 둔다.
    tabMenuAutoHideDelayMs: 7000,

    // Alpine이 컴포넌트 생성 직후 자동으로 한 번 호출한다(Access의
    // Form_Load와 같은 역할, md/2026-09-05-adaptAccess-설계.md 참고).
    init() {
      // 다른 문서(iframe 등)가 window.parent.action(...)으로 이 셸을
      // 부를 수 있게 전역에 노출 — deco 화면군의 관례와 동일.
      window.action = (pcmd, pparams, psender) => this.action(pcmd, pparams, psender);
      this.initMenu();
      // 페이지를 열자마자도 3초 무호버 자동 축소 카운트가 시작되게 한다.
      this._scheduleTabMenuAutoHide();
    },

    // ── 공개 래퍼(action()을 거쳐서만 실제 로직을 실행) ──
    initMenu() {
      return this.action('initMenu', {}, this);
    },
    selectLeftMenu(title) {
      return this.action('selectLeftMenu', { title }, this);
    },
    selectTabMenu(id) {
      return this.action('selectTabMenu', { id }, this);
    },
    closeTab(id) {
      return this.action('closeTab', { id }, this);
    },
    closeAllTabs() {
      return this.action('closeAllTabs', {}, this);
    },
    leftMenuHide() {
      return this.action('leftMenuHide', {}, this);
    },
    leftMenuShow() {
      return this.action('leftMenuShow', {}, this);
    },
    tabMenuHide() {
      return this.action('tabMenuHide', {}, this);
    },
    tabMenuShow() {
      return this.action('tabMenuShow', {}, this);
    },
    toggleTabMenu() {
      return this.action('toggleTabMenu', {}, this);
    },
    toggleLeftMenu() {
      return this.action('toggleLeftMenu', {}, this);
    },

    activeTitle() {
      const t = this.tabs.find(t => t.id === this.activeTab);
      if (t) return t.title;
      return null;
    },
    syncCategory(title) {
      const cat = this.categories.find(c => c.items.some(it => it.title === title));
      if (cat) this.openCategory = cat.name;
    },

    // ── action 라우터 — 실제 로직 없이 기능별 하위 함수에만 위임한다 ──
    action(pcmd, pparams, psender) {
      switch (pcmd) {
        case 'initMenu':
        case 'selectLeftMenu':
          return this.action_menu(pcmd, pparams, psender);
        case 'selectTabMenu':
        case 'closeTab':
        case 'closeAllTabs':
          return this.action_tab(pcmd, pparams, psender);
        case 'leftMenuHide':
        case 'leftMenuShow':
        case 'tabMenuHide':
        case 'tabMenuShow':
        case 'toggleTabMenu':
        case 'toggleLeftMenu':
          return this.action_layout(pcmd, pparams, psender);
        default:
          // 저장소(variableBank) 관련 pcmd는 전부 variableBank.js가 처리한다.
          if (VariableBank.handles(pcmd)) {
            return VariableBank.action(pcmd, pparams, psender);
          }
          console.warn('amania.action: 알 수 없는 pcmd - ' + pcmd);
      }
    },

    // ── "메뉴" 그룹 — 메뉴 주입 + 좌측 메뉴 선택 ──
    action_menu(pcmd, pparams, psender) {
      switch (pcmd) {
        case 'initMenu':
          return this._initMenu();
        case 'selectLeftMenu':
          return this._selectLeftMenu(pparams);
      }
    },
    /**
     * 메뉴 JSON(src/menu/menu.js의 MENU_JSON) 하나에 화면 구조(categories)뿐 아니라 동작 설정
     * (tabMenuAutoHideDelayMs)까지 함께 담는다 — 7초라는 값을
     * _scheduleTabMenuAutoHide()에 하드코딩하지 않고, 이 JSON에서
     * this.tabMenuAutoHideDelayMs로 뽑아 쓴다(아래 참고).
     */
    _initMenu() {
      // 메뉴 정의는 src/menu/menu.js(MENU_JSON)에 있다 — 메뉴를 바꿀 땐 그 파일만 고친다.
      // 로그인 사용자의 리본 버튼(AUTH_RIBBON_BUTTONS)에 없는 메뉴는 뺀다(마스터면 전부 보임).
      const menuJson = filterMenuByRibbon(MENU_JSON, AUTH_RIBBON_BUTTONS, AUTH_IS_MASTER);
      this.tabMenuAutoHideDelayMs = menuJson.tabMenuAutoHideDelayMs;
      this.categories = menuJson.categories;
      this.openCategory = this.categories.length ? this.categories[0].name : null;
    },
    _selectLeftMenu(pparams) {
      const title = pparams.title;
      const cat = this._findCategory(title);
      let categoryName = null;
      let categoryGroup = null;
      if (cat) {
        categoryName = cat.name;
        categoryGroup = cat.group;
      }
      const item = this._findMenuItem(title);
      // (7)(8) "메뉴 법칙"(자동 표시/숨김 전반)이 적용되는 메뉴인지는
      // 하드코딩하지 않고 메뉴 JSON의 autoHideMenu:true 값으로만 정한다
      // — 지금(src/menu/menu.js)은 true인 항목이 없다.
      const autoHideMenu = !!(item && item.autoHideMenu === true);
      this._closeTabsOutsideGroup(categoryGroup);
      this.syncCategory(title);
      const existing = this.tabs.find(t => t.title === title);
      if (existing) {
        this.activeTab = existing.id;
        // (1) 좌측 메뉴 선택 시 탭 메뉴는 항상 보이게 한다(숨겨져 있었다면
        // 복원) — 단 이 항목이 autoHideMenu:true인 경우에만. tabs.length가
        // 이미 확정된 뒤에 불러야 _scheduleTabMenuAutoHide()의 "탭 없으면
        // 타이머 안 돌림" 판단이 정확해진다.
        if (autoHideMenu) {
          this.tabMenuShow();
        }
        return;
      }
      const id = 't' + Date.now() + Math.random().toString(36).slice(2, 6);
      let src;
      if (item && item.src) {
        if (item.themeParam === false) {
          src = item.src;
        } else {
          src = item.src + '?theme=' + this.theme;
        }
      } else {
        src = this._buildDemoSrc(title);
      }
      this.tabs.push({ id, title, src, category: categoryName, group: categoryGroup, autoHideMenu });
      this.activeTab = id;
      if (autoHideMenu) {
        this.tabMenuShow();
      }
    },
    /**
     * 열린 탭들의 상호 배타 여부는 하드코딩된 카테고리 이름이 아니라
     * 각 카테고리가 JSON(_initMenu의 categories)에 직접 들고 있는
     * `group` 필드로 판단한다 — group 값이 다른 탭들끼리는 서로
     * 배타적으로 열리고(한쪽을 열면 반대쪽은 전부 닫힘), 같은 group
     * 값을 가진 탭들끼리는 공존한다. 카테고리가 늘어나거나 group
     * 분류 자체가 바뀌어도 이 함수는 손댈 필요 없이 _initMenu()의
     * group 값만 조정하면 된다.
     */
    _closeTabsOutsideGroup(group) {
      const keep = this.tabs.filter(t => t.group === group);
      if (keep.length === this.tabs.length) return;
      const activeStillOpen = keep.some(t => t.id === this.activeTab);
      this.tabs = keep;
      if (!activeStillOpen) {
        if (keep.length) {
          this.activeTab = keep[keep.length - 1].id;
        } else {
          this.activeTab = null;
        }
      }
    },
    _findCategory(title) {
      const cat = this.categories.find(c => c.items.some(it => it.title === title));
      if (cat) return cat;
      return null;
    },
    _findMenuItem(title) {
      for (const cat of this.categories) {
        const found = cat.items.find(it => it.title === title);
        if (found) return found;
      }
      return null;
    },
    _buildDemoSrc(title) {
      const html = `<!doctype html><html data-theme='${this.theme}'><head><meta charset='utf-8'><style>
        :root{--bg:#030712;--fg:#e5e7eb;--muted:#6b7280;--border:#374151;--field:#111827;}
        html[data-theme='light']{--bg:#ffffff;--fg:#111827;--muted:#6b7280;--border:#e5e7eb;--field:#f9fafb;}
        /* user-select:none — 이 iframe(데모 탭)은 별도 문서라 부모의 select-none이 안 미쳐서 여기 따로 건다. input은 브라우저 기본 동작상 이 규칙과 무관하게 계속 선택/입력 가능. */
        body{margin:0;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;font-family:'Pretendard','Malgun Gothic',system-ui,sans-serif;background:var(--bg);color:var(--fg);transition:background .3s,color .3s;user-select:none;}
        h1{font-size:20px;font-weight:600;margin:0;}
        p{font-size:13px;color:var(--muted);margin:0;text-align:center;max-width:320px;line-height:1.6;}
        input{margin-top:8px;padding:9px 16px;border-radius:8px;border:1px solid var(--border);background:var(--field);color:var(--fg);font-size:13px;outline:none;width:260px;}
        input:focus{border-color:#10b981;}
      </style></head><body>
        <h1>${title}</h1>
        <p>이 화면은 독립된 iframe으로 렌더링됩니다.<br/>아래에 입력한 값은 다른 탭으로 이동했다가 돌아와도 그대로 유지됩니다.</p>
        <input type='text' placeholder='여기에 입력해보세요' />
        <script>
          window.addEventListener('message', function (e) { if (e.data && e.data.type === 'wave-theme') { document.documentElement.setAttribute('data-theme', e.data.theme); } });
          document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
        <\/script>
      </body></html>`;
      return 'data:text/html;charset=utf-8,' + encodeURIComponent(html);
    },

    // ── "탭" 그룹 — 이미 열린 탭 사이의 전환 + 탭 닫기 ──
    action_tab(pcmd, pparams, psender) {
      switch (pcmd) {
        case 'selectTabMenu':
          return this._selectTabMenu(pparams);
        case 'closeTab':
          return this._closeTab(pparams);
        case 'closeAllTabs':
          return this._closeAllTabs();
      }
    },
    _selectTabMenu(pparams) {
      this.activeTab = pparams.id;
      const tab = this.tabs.find(t => t.id === pparams.id);
      if (tab) this.syncCategory(tab.title);
      this._requeryTabIframe(pparams.id);
      // 방금 활성화된 탭이 autoHideMenu 탭인지 아닌지에 따라 타이머를
      // 다시 판단한다 — _scheduleTabMenuAutoHide()가 그 판단(활성 탭
      // 없음/autoHideMenu 아님이면 스킵)을 전담하므로 그냥 다시 부르면 된다.
      this._scheduleTabMenuAutoHide();
    },
    /**
     * 탭이 옮겨질 때(selectTabMenu) 그 탭의 iframe 안 페이지에
     * action('requery', {}, window)를 호출해준다 — deco_main.php 등이
     * 이미 갖고 있는 'requery'(재조회) pcmd를 그대로 재사용하는 관례.
     * 같은 출처(same-origin) iframe이라 postMessage 없이 직접 함수
     * 호출이 가능하다(deco_main.php의 dmRelayToRightGrid()와 동일 원리).
     * 그 페이지가 action()을 아예 안 갖고 있으면(데모 탭 등) 조용히 무시한다.
     */
    _requeryTabIframe(id) {
      const iframe = document.querySelector('[data-tab-iframe="' + id + '"]');
      if (!iframe) return;
      try {
        if (iframe.contentWindow && typeof iframe.contentWindow.action === 'function') {
          iframe.contentWindow.action('requery', {}, window);
        }
      } catch (e) {
        // cross-origin 등으로 접근 불가하면 조용히 무시.
      }
    },
    _closeTab(pparams) {
      const idx = this.tabs.findIndex(t => t.id === pparams.id);
      if (idx === -1) return;
      this.tabs.splice(idx, 1);
      if (this.activeTab === pparams.id) {
        if (this.tabs.length) {
          this.activeTab = this.tabs[Math.max(0, idx - 1)].id;
        } else {
          this.activeTab = null;
        }
      }
      if (this.tabs.length === 0) {
        // 마지막 탭까지 닫히면 이미 걸려 있던 타이머도 즉시 멈춘다 —
        // 그대로 두면 나중에 발동해 좌측/탭 메뉴를 숨기는데, 그 시점엔
        // 플로트 버튼도 없어(x-show="tabs.length") 되살릴 방법이 없다.
        this._clearTabMenuAutoHide();
      }
    },
    _closeAllTabs() {
      this.tabs = [];
      this.activeTab = null;
      this._clearTabMenuAutoHide();
    },

    // ── "레이아웃" 그룹 — 좌측 메뉴/탭 메뉴 표시 제어 ──
    action_layout(pcmd, pparams, psender) {
      switch (pcmd) {
        case 'leftMenuHide':
          return this._leftMenuHide();
        case 'leftMenuShow':
          return this._leftMenuShow();
        case 'tabMenuHide':
          return this._tabMenuHide();
        case 'tabMenuShow':
          return this._tabMenuShow();
        case 'toggleTabMenu':
          return this._toggleTabMenu();
        case 'toggleLeftMenu':
          return this._toggleLeftMenu();
      }
    },
    _leftMenuHide() {
      // sidebarOpen은 <body>의 바깥 x-data 소유(상단 "상태" 주석 참고) —
      // 여기서도 프로토타입 체인을 통해 그대로 쓴다.
      this.sidebarOpen = false;
    },
    _leftMenuShow() {
      this.sidebarOpen = true;
    },
    _tabMenuHide() {
      this.tabMenuOpen = false;
      this._clearTabMenuAutoHide();
    },
    _tabMenuShow() {
      this.tabMenuOpen = true;
      // 다시 보일 때마다(최초 로드 포함) 7초 무호버 자동 축소 카운트를 새로 건다.
      this._scheduleTabMenuAutoHide();
    },
    /**
     * (7)(8) 지금 활성화된 탭이 "메뉴 법칙"(자동 표시/숨김) 대상인지 —
     * 탭 생성 시 _selectLeftMenu()가 심어둔 tab.autoHideMenu(메뉴 JSON의
     * autoHideMenu:true에서 온 값)를 그대로 읽는다. 활성 탭이 없거나
     * (tabs.length===0) 그 탭이 이 법칙 대상이 아니면 false.
     */
    _activeTabAutoHideMenu() {
      const tab = this.tabs.find(t => t.id === this.activeTab);
      return !!(tab && tab.autoHideMenu === true);
    },
    /**
     * "상단메뉴(탭 메뉴)는 7초 정도 마우스 호버가 없으면 좌측 메뉴와
     * 함께 자동으로 축소" — 새 hide/show 로직을 따로 안 만들고 기존
     * leftMenuHide()/tabMenuHide()를 그대로 재사용한다. 툴바의
     * @mouseenter가 카운트를 취소하고, @mouseleave가 다시 건다 — 즉
     * "호버가 없는 시간이 tabMenuAutoHideDelayMs(메뉴 JSON에서 주입,
     * 기본 7초)만큼 연속으로 쌓이면" 둘 다 같이 축소된다. 시간값 자체는
     * 여기 하드코딩하지 않고 _initMenu()가 채운 this.tabMenuAutoHideDelayMs
     * 를 그대로 읽는다.
     *
     * 탭이 하나도 없거나(this.tabs.length === 0), 지금 활성 탭이
     * autoHideMenu:true 메뉴가 아니면(_activeTabAutoHideMenu()) 타이머
     * 자체를 돌리지 않는다 — (7)(8) "법칙이 듣는 메뉴만 자동숨김".
     * 플로트 버튼은 tabs.length가 있을 때만 보이므로, 탭이 없는 상태에서
     * 타이머가 발동해 좌측/탭 메뉴가 숨겨지면 되살릴 방법이 아예 없어지는
     * 사각지대도 함께 막는다.
     */
    _scheduleTabMenuAutoHide() {
      this._clearTabMenuAutoHide();
      if (this.tabs.length === 0 || !this._activeTabAutoHideMenu()) {
        return;
      }
      tabMenuAutoHideTimer = setTimeout(() => {
        this.leftMenuHide();
        this.tabMenuHide();
      }, this.tabMenuAutoHideDelayMs);
    },
    _clearTabMenuAutoHide() {
      if (tabMenuAutoHideTimer) {
        clearTimeout(tabMenuAutoHideTimer);
        tabMenuAutoHideTimer = null;
      }
    },
    /**
     * 우측상단 플로트 버튼이 부르는 토글 — 상태를 보고 hide()/show()를
     * 그대로 호출한다. (4) 좌측 메뉴를 함께 숨기는 것도 (7)(8)처럼
     * 활성 탭이 autoHideMenu:true일 때만 한다 — 법칙이 안 듣는 메뉴를
     * 보는 중에는 탭 메뉴만 단독으로 접힌다.
     */
    _toggleTabMenu() {
      if (this.tabMenuOpen) {
        this._tabMenuHide();
        if (this._activeTabAutoHideMenu()) {
          this._leftMenuHide();
        }
      } else {
        this._tabMenuShow();
      }
    },
    /** 좌측상단 플로트 버튼이 부르는 토글 — 상태를 보고 hide()/show()를 그대로 호출한다. */
    _toggleLeftMenu() {
      if (this.sidebarOpen) {
        this._leftMenuHide();
      } else {
        this._leftMenuShow();
      }
    },
  };
}
</script>

<script defer src="src/js/vendor/alpinejs/alpine-collapse.min.js"></script>
<script defer src="src/js/vendor/alpinejs/alpine.min.js"></script>

<link rel="stylesheet" as="style"
      href="src/fonts/pretendard/pretendard.css" />

<style>
  body { font-family: 'Pretendard', 'Malgun Gothic', system-ui, sans-serif; }
  [x-cloak] { display: none !important; }
</style>
</head>

<!-- select-none: 드래그로 문장(텍스트)이 선택되는 것을 막음.
     주의: iframe 안 콘텐츠는 별도 문서라 여기 select-none이 안 미침 —
     data: URL 데모 탭 템플릿과 page5_*.html에도 각자 따로 걸어야 함. -->
<body class="bg-white text-gray-900 h-screen flex flex-col overflow-hidden select-none"
      x-data="{ sidebarOpen: true, theme: 'light' }"
      @contextmenu.prevent>

  <div class="flex-1 flex min-h-0"
       x-data="amaniaShell()">


    <!-- 좌측 아코디언 메뉴 : 사이드바 자체 축소/확대.
         상단 탭바와 동일한 자동축소 타이머를 공유한다 — 이 영역에 마우스가
         있는 동안도 "호버 있음"으로 쳐서 7초 카운트를 취소/리셋한다. -->
    <aside :class="sidebarOpen ? 'w-72' : 'w-0'"
           @mouseenter="_clearTabMenuAutoHide()"
           @mouseleave="_scheduleTabMenuAutoHide()"
           class="relative shrink-0 bg-gray-50 dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 overflow-hidden transition-[width] duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]">

      <!-- 사이드바 여닫기 핸들 (테두리에 부착) -->
      <button @click="sidebarOpen = !sidebarOpen"
              class="absolute top-1/2 -right-3 -translate-y-1/2 z-20 w-6 h-6 rounded-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-md flex items-center justify-center cursor-pointer text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-700 transition-all">
        <svg :class="sidebarOpen ? '' : 'rotate-180'" class="w-3.5 h-3.5 transition-transform duration-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.5L8.25 12l6.75-7.5" />
        </svg>
      </button>

      <!-- 폭 고정 내부 래퍼 : 축소 중에도 텍스트가 찌그러지지 않도록 -->
      <div class="w-72 h-full overflow-y-auto" style="overflow-x: hidden;">
        <div class="px-4 py-4 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between">
          <div class="flex items-center gap-2.5">
            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">BROS POWER</p>
            <?php if ($amaniaCurrentUserName !== ''): ?>
            <span class="text-xs text-gray-400 dark:text-gray-600">|</span>
            <span class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($amaniaCurrentUserName, ENT_QUOTES, 'UTF-8') ?>님</span>
            <?php endif; ?>
          </div>

          <div class="flex items-center gap-1">
            <button x-show="tabs.length" x-cloak
                    @click="closeAllTabs()"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    title="열린 탭 모두 닫기"
                    class="shrink-0 w-6 h-6 flex items-center justify-center rounded-md cursor-pointer text-gray-400 dark:text-gray-500 hover:text-red-500 dark:hover:text-red-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
              </svg>
            </button>
            <a href="<?= htmlspecialchars($amaniaLogoutUrl, ENT_QUOTES, 'UTF-8') ?>"
               class="shrink-0 w-6 h-6 flex items-center justify-center rounded-md cursor-pointer text-gray-400 dark:text-gray-500 hover:text-red-500 dark:hover:text-red-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
               title="로그아웃">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0110.5 3h6a2.25 2.25 0 012.25 2.25v13.5A2.25 2.25 0 0116.5 21h-6a2.25 2.25 0 01-2.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3H15" />
              </svg>
            </a>
          </div>
        </div>

        <template x-for="cat in categories" :key="cat.name">
          <div class="border-b border-gray-200 dark:border-gray-800">
            <button @click="openCategory = (openCategory === cat.name ? null : cat.name)"
                    class="w-full flex items-center justify-between px-4 py-3 text-left cursor-grab active:cursor-grabbing hover:bg-gray-100 dark:hover:bg-gray-800/60 transition-colors">
              <span class="text-sm font-bold tracking-widest text-gray-600 dark:text-gray-300 uppercase" x-text="cat.name"></span>
              <svg :class="openCategory === cat.name ? 'rotate-180' : ''" class="w-3.5 h-3.5 text-gray-400 dark:text-gray-500 transition-transform duration-200 shrink-0"
                   fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
              </svg>
            </button>

            <div x-show="openCategory === cat.name" x-collapse>
              <ul class="pb-2">
                <template x-for="item in cat.items" :key="item.title">
                  <li style="padding: 0 4px;">
                  <button @click="selectLeftMenu(item.title)"
                          :class="activeTitle() === item.title
                            ? 'bg-gradient-to-b from-emerald-400 to-emerald-600 text-white shadow-[0_2px_4px_rgba(16,185,129,.35),0_8px_18px_-6px_rgba(16,185,129,.55),inset_0_1px_0_rgba(255,255,255,.35)] ring-1 ring-emerald-300/40'
                            : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800/60 hover:text-gray-900 dark:hover:text-gray-100'"
                          class="w-full flex items-center gap-2.5 pl-6 pr-4 py-2.5 text-base font-bold rounded-lg cursor-grab active:cursor-grabbing transition-all">
                    <span :class="activeTitle() === item.title ? 'bg-white' : 'bg-gray-300 dark:bg-gray-600'"
                          class="w-1.5 h-1.5 rounded-full shrink-0 transition-colors"></span>
                    <span class="truncate text-left" x-text="item.title"></span>
                  </button>
                </li>
              </template>
            </ul>
          </div>
        </div>
        </template>
      </div>
    </aside>

    <!-- 중앙 탭 영역 -->
    <main class="flex-1 min-w-0 flex flex-col">

      <!-- 상단 툴바(=탭 메뉴) : 사이드바 토글 + 둥근 세그먼트 필 탭.
           x-collapse가 tabMenuOpen에 따라 높이를 접으며 슬라이딩 애니메이션을 준다.
           이 영역과 좌측 <aside>(사이드바) 둘 중 어디든 마우스가 있으면
           "호버 있음"으로 친다 — 7초 동안 두 영역 모두에 호버가 없으면
           자동으로 함께 축소된다(@mouseenter가 취소, @mouseleave가 재개
           — _scheduleTabMenuAutoHide()/_clearTabMenuAutoHide()). -->
      <div x-show="tabMenuOpen" x-collapse
           @mouseenter="_clearTabMenuAutoHide()"
           @mouseleave="_scheduleTabMenuAutoHide()"
           class="relative flex items-center gap-2 px-3 py-2.5 bg-white/80 dark:bg-gray-900/80 backdrop-blur-md shadow-[0_1px_0_rgba(0,0,0,.04),0_4px_12px_-4px_rgba(0,0,0,.08)] dark:shadow-[0_1px_0_rgba(255,255,255,.04),0_4px_16px_-4px_rgba(0,0,0,.4)] shrink-0 z-10">

        <!-- 좌측 메뉴 확장/축소 버튼 -->
        <button @click="sidebarOpen = !sidebarOpen"
                class="shrink-0 w-8 h-8 flex items-center justify-center rounded-md cursor-pointer text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-white transition-colors">
          <svg :class="sidebarOpen ? '' : 'rotate-180'" class="w-4 h-4 transition-transform duration-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7M18 19l-7-7 7-7" />
          </svg>
        </button>

        <div class="w-px h-5 bg-gradient-to-b from-transparent via-gray-300 dark:via-gray-700 to-transparent shrink-0"></div>

        <!-- 탭 목록 -->
        <div class="relative flex-1 min-w-0 flex items-center gap-1.5 overflow-x-auto overflow-y-hidden py-1.5"
             x-data="{
               pillLeft: 0, pillWidth: 0, draggingId: null,
               movePill() {
                 const el = this.$el.querySelector(`[data-tab-btn=&quot;${this.activeTab}&quot;]`);
                 if (!el) { this.pillWidth = 0; return; }
                 this.pillLeft = el.offsetLeft;
                 this.pillWidth = el.offsetWidth;
               },
               onTabDragStart(e, tabId) {
                 this.draggingId = tabId;
                 e.dataTransfer.effectAllowed = 'move';
                 e.dataTransfer.setData('text/plain', tabId);
               },
               onTabDragOver(e, tabId) {
                 e.preventDefault();
                 e.dataTransfer.dropEffect = 'move';
                 if (!this.draggingId || this.draggingId === tabId) return;
                 const fromIdx = this.tabs.findIndex(t => t.id === this.draggingId);
                 const toIdx = this.tabs.findIndex(t => t.id === tabId);
                 if (fromIdx === -1 || toIdx === -1 || fromIdx === toIdx) return;
                 const [moved] = this.tabs.splice(fromIdx, 1);
                 this.tabs.splice(toIdx, 0, moved);
               },
               onTabDragEnd() { this.draggingId = null; },
             }"
             x-init="$nextTick(() => movePill())"
             x-effect="activeTab; tabs.length; $nextTick(() => movePill())">

          <!-- 슬라이딩 필 배경 : 에메랄드 그라디언트 + 컬러 글로우로 선택 탭을 뚜렷하게 강조 -->
          <div class="absolute top-1.5 bottom-1.5 rounded-full
                      bg-gradient-to-b from-emerald-400 to-emerald-600
                      shadow-[0_2px_4px_rgba(16,185,129,.35),0_10px_24px_-6px_rgba(16,185,129,.55),inset_0_1px_0_rgba(255,255,255,.35)]
                      ring-1 ring-emerald-300/40
                      transition-all duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]"
               :style="`left: ${pillLeft}px; width: ${pillWidth}px; opacity: ${pillWidth ? 1 : 0}`"></div>

          <template x-for="tab in tabs" :key="tab.id">
            <div :data-tab-btn="tab.id"
                 draggable="true"
                 @click="selectTabMenu(tab.id)"
                 @dragstart="onTabDragStart($event, tab.id)"
                 @dragover="onTabDragOver($event, tab.id)"
                 @drop.prevent
                 @dragend="onTabDragEnd()"
                 :class="(activeTab === tab.id ? 'text-white font-semibold tracking-[0.01em] ' : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 ') + (draggingId === tab.id ? 'opacity-40' : '')"
                 x-transition:enter="transition ease-[cubic-bezier(0.16,1,0.3,1)] duration-300"
                 x-transition:enter-start="opacity-0 scale-90"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-90"
                 class="group relative z-10 flex items-center gap-2 pl-4 pr-2.5 py-1.5 text-sm rounded-full cursor-grab active:cursor-grabbing whitespace-nowrap transition-all duration-300">
              <span x-text="tab.title"></span>
              <button @click.stop="closeTab(tab.id)"
                      :class="activeTab === tab.id ? 'text-emerald-50 hover:bg-white/25 hover:text-white' : 'text-gray-400 dark:text-gray-500 hover:bg-gray-200 dark:hover:bg-gray-600 hover:text-gray-700 dark:hover:text-white'"
                      class="w-4 h-4 flex items-center justify-center rounded-full transition-colors">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </template>
        </div>
      </div>

      <!-- 탭 내용 : 탭마다 iframe이 계속 DOM에 남아있고 x-show(=display 토글)로만 숨김 -->
      <div class="flex-1 relative bg-white dark:bg-gray-950 overflow-hidden">

        <!-- iframe 영역 자기 자신의 좌측상단 모서리에 탭 메뉴(상하로 접히는
             상단 탭바) 토글 버튼 하나만 둔다 — 좌측 메뉴(좌우로 슬라이딩)
             토글 버튼은 제거했다(대신 이 버튼이 숨길 때 좌측도 함께 숨김,
             (4) 참고). z-30으로 iframe(absolute inset-0)보다 위에 뜬다.
             (6) 색상은 상태를 그대로 드러낸다 — 메뉴가 펼쳐져 있으면
             (tabMenuOpen) 흰색 배경(아이콘이 또렷하게 보임), 접혀 있으면
             "누르면 다시 펼쳐진다"는 걸 알리는 에메랄드 그린 배경으로
             바뀐다. -->
        <button x-show="tabs.length" x-cloak
                @click="toggleTabMenu()"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-90"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-90"
                :title="tabMenuOpen ? '탭+좌측 메뉴 숨기기' : '탭 메뉴 보이기'"
                :class="tabMenuOpen
                  ? 'bg-white/90 dark:bg-gray-800/90 backdrop-blur-md border border-gray-200 dark:border-gray-700 shadow-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-gray-700 dark:hover:text-gray-200'
                  : 'bg-gradient-to-b from-emerald-400 to-emerald-600 text-white shadow-[0_2px_4px_rgba(16,185,129,.35),0_8px_18px_-6px_rgba(16,185,129,.55),inset_0_1px_0_rgba(255,255,255,.35)] ring-1 ring-emerald-300/40 hover:from-emerald-300 hover:to-emerald-500'"
                class="absolute top-2 left-2 z-30 w-9 h-9 flex items-center justify-center rounded-full cursor-pointer transition-colors">
          <svg :class="tabMenuOpen ? 'rotate-180' : ''" class="w-4 h-4 transition-transform duration-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
          </svg>
        </button>

        <!-- 탭이 하나도 없을 때 -->
        <template x-if="!tabs.length">
          <div class="absolute inset-0 flex flex-col items-center justify-center text-center px-6">
            <svg class="w-10 h-10 text-gray-300 dark:text-gray-700 mb-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" />
            </svg>
            <p class="text-sm text-gray-400 dark:text-gray-600">좌측 메뉴에서 항목을 선택하면<br />이곳에 탭이 생성됩니다</p>
          </div>
        </template>

        <!-- 탭별 iframe : activeTab이 바뀌어도 DOM에서 제거되지 않고 숨겨지기만 함.
             data-tab-iframe는 _requeryTabIframe()이 탭 전환 시 그 iframe을
             찾아 contentWindow.action('requery', ...)를 부르기 위한 앵커.
             data-tab-title은 variableBank.js가 폼/컨트롤을 탭 제목으로
             찾기 위한 앵커. -->
        <template x-for="tab in tabs" :key="tab.id">
          <div x-show="activeTab === tab.id" x-cloak class="absolute inset-0">
            <iframe :src="tab.src"
                    :data-tab-iframe="tab.id"
                    :data-tab-title="tab.title"
                    @load="try { $el.contentWindow.postMessage({ type: 'wave-theme', theme: theme }, '*') } catch (e) {}"
                    class="w-full h-full border-0 block" title="tab-frame"></iframe>
          </div>
        </template>

      </div>
    </main>

  </div>

</body>
</html>
