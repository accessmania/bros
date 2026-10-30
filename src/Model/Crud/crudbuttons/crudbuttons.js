/**
 * CrudButtons — 폼(부모 창) 아래 iframe에 들어가는 재사용 CRUD 버튼줄.
 *
 * ## VBA식 JS — 이 폴더의 JS는 전부 이 규칙으로 쓴다
 *  1. 변수는 var만. 함수 맨 위에 모아 선언한다(VBA의 Dim처럼).
 *  2. 함수는 function만(화살표 => 안 씀). function 안에서 자기 객체가 필요하면
 *     var self = this; 로 담아 둔다(Me를 변수에 담아 두는 것과 같다).
 *  3. 매개변수 이름은 p로 시작한다(pcmd, psender, ppara …).
 *  4. 조건은 if / else로 풀어 쓴다(a ? b : c, a && b(), !!x 같은 줄임 안 씀).
 *  5. 반복은 for (var i = 0; i < n; i++) 로(forEach/map/filter 안 씀).
 *  6. 서버를 기다리는 작업은 async / await — 위에서 아래로 차례대로 읽힌다.
 *  7. 문자열은 + 로 잇는다(템플릿 문자열 안 씀).
 *
 * ## 역할 분담
 * - 부모 폼(amd): 테이블 필드와 같은 id의 컨트롤을 배치하고, CRUD 이벤트 함수를
 *   자기 페이지에 정의한다(Access 폼 모듈의 beforeCrudSave 등과 같다).
 *   정의하지 않은 이벤트는 그냥 건너뛴다("통과").
 * - 이 클래스(iframe 안): 저장/삭제/신규입력/창닫기 버튼을 그리고, CrudJs를 하나
 *   만들어 부모 창(window.parent)의 컨트롤에 연결해 실제 CRUD를 한다. 어느
 *   테이블인지는 생성자에 받은 fetchUrl(xxx_fetch.php, CrudJs 계약)이 정한다.
 *
 * ## 부모 폼에 정의하는 이벤트(전부 선택)
 *   isEnableAmdSave(psender, rerror)        isEnableAmdDelete(psender, rerror)
 *   beforeCrudSave(psender, ppara)          afterCrudSave(psender, ppara)
 *   beforeCrudDelete(psender, ppara)        afterCrudDelete(psender, ppara)
 *   beforeCrudClear(psender, ppara)         afterCrudClear(psender, ppara)
 *   beforeCrudSelectRecord(psender, ppara)  afterCrudSelectRecord(psender, ppara)
 *   beforeCrudClose(psender, ppara)  — 창닫기 전(false면 안 닫음)
 * - psender는 이 CrudButtons다 — psender.getCrud()로 CrudJs(getValue/setValue 등),
 *   psender.getPk()로 지금 PK, psender.isDirty()로 저장 안 한 값이 있는지 안다.
 * - amd를 띄운 쪽에 알리기/제목 바꾸기도 psender로 한다(amd는 자기가 팝업에 있는지
 *   iframe에 있는지 몰라도 된다):
 *     psender.report('saved', { code: … })  — 띄운 쪽의 action('amdReport')(팝업이든 iframe이든)
 *     psender.setCaption('…')               — 팝업 캡션 또는 문서 제목
 * - 창과 창 사이의 명령·보고는 전부 그 창의 action(pcmd, pparams, psender)으로 한다.
 *   위 이벤트들만 이름 있는 함수로 부른다(Access의 이벤트 프로시저처럼).
 * - isEnable…/before…가 false를 돌려주면 그 작업을 막는다. 이유를 보여주려면
 *   rerror.error / ppara.error에 글을 넣는다(Access의 ByRef rerror 대신 객체).
 *
 * ## 저장 순서
 *   스키마 검사(CrudJs) → isEnableAmdSave → beforeCrudSave(await) → 서버 저장 → afterCrudSave
 * - 저장 전 검사는 beforeCrudSave 한 곳에 위에서 아래로 쓴다. 서버에 물어봐야 하면
 *   async function으로 쓰면 된다(CrudJs가 기다린다). 가벼운 검사(필수 입력)를 먼저,
 *   서버 확인(id 중복)을 나중에 두면 쓸데없는 서버 왕복이 없다.
 * - beforeCrudSave/Delete/Clear/SelectRecord는 async 가능. isEnable…(버튼 상태 때마다
 *   불림)과 beforeCrudClose는 바로 답해야 한다(기다리지 않는다).
 * - 삭제도 같다: 확인창 → isEnableAmdDelete → beforeCrudDelete(await) → 서버 삭제 → …
 *
 * ## 저장 안 한 값
 * - 레코드를 열거나 / 비우거나 / 저장한 직후의 값을 "기준값"으로 기억해 두고, 지금 값이
 *   다르면 "수정됨"이다(isDirty). 수정됨이면 저장 버튼이 노랗게 보인다.
 * - 수정됨일 때 신규입력 / 다시 읽기(requery) / 다른 레코드 열기 / 창닫기를 하면 먼저 묻는다.
 * - amd를 단독으로 연 창(새 탭)이면 창을 닫거나 새로고침할 때 브라우저가 묻는다.
 *
 * 기타:
 * - isEnable…은 버튼 상태를 정할 때마다(폼 값이 바뀔 때마다) 불린다 — 값만 보고 답할 것.
 * - 삭제 버튼은 PK가 비어 있으면 isEnableAmdDelete와 무관하게 비활성이다.
 * - 삭제가 성공하면 폼을 비운다(clear — before/afterCrudClear도 함께 돈다).
 * - 창닫기: beforeCrudClose → (수정됨이면 묻기) → 띄운 쪽에 'closed' 보고 → 팝업이면
 *   닫고, 단독이면 창을 닫는다.
 *
 * 사용(crudbuttons.php가 한다):
 *   var buttons = new CrudButtons({ containerId: 'cb-root', fetchUrl: '...', pk: '12' });
 *   buttons.init();
 */
class CrudButtons {
    /**
     * @param {{containerId: string, fetchUrl: string, pk?: (string|number|null),
     *   target?: Window}} poptions target 생략 시 window.parent(부모 폼).
     */
    constructor(poptions) {
        if (!poptions) {
            poptions = {};
        }
        if (!poptions.fetchUrl) {
            throw new Error('CrudButtons: fetchUrl이 필요합니다.');
        }

        this.fetchUrl = poptions.fetchUrl;

        // 처음 열 레코드의 PK — 없으면 신규(빈 폼)로 시작한다.
        if (poptions.pk === null || poptions.pk === undefined || poptions.pk === '') {
            this.initialPk = null;
        } else {
            this.initialPk = String(poptions.pk);
        }

        // 연결할 폼 — 지정 안 하면 이 iframe을 담은 부모 창.
        if (poptions.target) {
            this.target = poptions.target;
        } else {
            this.target = window.parent;
        }

        this.container = document.getElementById(poptions.containerId);
        this.crud = null;        // CrudJs — init()에서 만든다.
        this.busy = false;       // 작업 중이면 true(그동안 버튼을 막는다).
        this.buttons = {};       // { save, delete, clear, close } — 버튼 엘리먼트
        this.messageEl = null;   // 결과 문구 칸
        this.savedValues = null; // 기준값 — 마지막으로 열거나/비우거나/저장한 때의 값

        this.buildButtons();
    }

    // ==================================================================
    // 공개 함수 — crudbuttons.php의 action()이 이것들을 부른다.
    // ==================================================================

    /** 시작 — 부모 폼이 다 그려지면 CrudJs를 연결하고, 첫 레코드(PK 없으면 빈 폼)를 연다. */
    async init() {
        var ok;

        try {
            await this.waitParentReady();

            this.crud = new CrudJs({ fetchUrl: this.fetchUrl, window: this.target });
            this.wireCrudEvents();
            this.watchParentInput();
            this.watchParentUnload();

            ok = await this.crud.loadSchema();
            if (ok === false) {
                throw new Error(this.crud.getError());
            }

            if (this.initialPk === null) {
                await this.clearForm();
            } else {
                await this.loadRecord(this.initialPk);
            }
        } catch (err) {
            this.showMessage(err.message, true);
        }

        this.refresh();
    }

    /** 안의 CrudJs — 부모 이벤트가 psender.getCrud()로 쓴다. */
    getCrud() {
        return this.crud;
    }

    /** 지금 폼의 PK 값(없으면 null). */
    getPk() {
        var pkField;
        var pk;

        if (this.crud === null) {
            return null;
        }
        pkField = this.crud.schema.primaryKey;
        if (!pkField) {
            return null;
        }

        pk = this.crud.getValue(pkField);
        if (pk === null || pk === undefined || pk === '') {
            return null;
        }
        return pk;
    }

    /** 저장 — (CrudJs 안에서) 스키마 검사 → isEnableAmdSave → beforeCrudSave(await) → 서버 → afterCrudSave. */
    async save() {
        var self = this;
        var ok;

        ok = await this.runWork('저장', '저장했습니다.', function () {
            return self.crud.save();
        });

        if (ok) {
            this.rememberValues();
        }
        return ok;
    }

    /** 삭제 — 확인창 → (CrudJs 안에서) isEnableAmdDelete → beforeCrudDelete(await) → 서버 → 폼 비우기. */
    async remove() {
        var self = this;
        var pk = this.getPk();
        var ok;

        if (pk === null) {
            return false;
        }
        if (!confirm('이 레코드(' + pk + ')를 삭제할까요? 되돌릴 수 없습니다.')) {
            return false;
        }

        ok = await this.runWork('삭제', '삭제했습니다.', function () {
            return self.crud.deleteRecord();
        });

        // 지운 레코드가 화면에 남지 않게 폼을 비운다(지웠으니 물을 것 없다).
        if (ok) {
            await this.clearForm();
        }
        return ok;
    }

    /** 신규입력 버튼 — 저장 안 한 값이 있으면 묻고 폼을 비운다. */
    async newRecord() {
        if (!this.confirmDiscard('새로 입력할까요?')) {
            return false;
        }
        return await this.clearForm();
    }

    /** 다른 레코드 열기(바깥에서) — 저장 안 한 값이 있으면 묻는다. */
    async selectRecord(ppk) {
        if (!this.confirmDiscard('다른 레코드를 열까요?')) {
            return false;
        }
        return await this.loadRecord(ppk);
    }

    /** 지금 레코드를 서버 값으로 다시 읽는다 — 저장 안 한 값이 있으면 묻는다(PK가 없으면 아무것도 안 함). */
    async requery() {
        var pk = this.getPk();

        if (pk === null) {
            return false;
        }
        if (!this.confirmDiscard('다시 읽을까요?')) {
            return false;
        }
        return await this.loadRecord(pk);
    }

    /** 저장 안 한 값이 있는지 — 지금 값이 기준값과 하나라도 다르면 true. */
    isDirty() {
        var now;
        var names;
        var i;

        if (this.crud === null || this.savedValues === null) {
            return false;
        }

        now = this.crud.getAllValues();
        names = Object.keys(now);
        for (i = 0; i < names.length; i++) {
            if (this.sameText(now[names[i]]) !== this.sameText(this.savedValues[names[i]])) {
                return true;
            }
        }
        return false;
    }

    /** 버튼 활성/비활성을 다시 정한다 — 작업 중 여부, PK 유무, 부모의 isEnable…, 수정됨 표시. */
    refresh() {
        var ready;
        var canSave;
        var canDelete;

        // 준비됨 = CrudJs가 있고, 지금 작업 중이 아님.
        ready = (this.crud !== null && this.busy === false);

        // 저장: 준비됐고, 부모가 막지 않으면.
        canSave = false;
        if (ready) {
            if (this.callParent('isEnableAmdSave', { error: '' }) !== false) {
                canSave = true;
            }
        }

        // 삭제: 준비됐고, PK가 있고, 부모가 막지 않으면.
        canDelete = false;
        if (ready && this.getPk() !== null) {
            if (this.callParent('isEnableAmdDelete', { error: '' }) !== false) {
                canDelete = true;
            }
        }

        this.buttons.save.disabled = !canSave;
        this.buttons.delete.disabled = !canDelete;
        this.buttons.clear.disabled = !ready;

        // 저장 안 한 값이 있으면 저장 버튼을 눈에 띄게.
        if (ready && this.isDirty()) {
            this.buttons.save.classList.add('cb-dirty');
            this.buttons.save.title = '저장하지 않은 내용이 있습니다.';
        } else {
            this.buttons.save.classList.remove('cb-dirty');
            this.buttons.save.title = '';
        }
    }

    // ==================================================================
    // amd를 감싼 쪽(호스트) — 팝업 / iframe / 단독 세 경우를 여기서 다 처리한다.
    // amd는 psender.report() / psender.setCaption()만 부르면 된다.
    // ==================================================================

    /**
     * amd를 감싸고 있는 팝업 박스(Popup) — 팝업이 아니면 null.
     * (amd 창 = this.target, 그 부모 = amd를 띄운 화면)
     */
    findPopup() {
        var amd = this.target;
        var host = amd.parent;

        // amd가 iframe 안이 아니면(주소창에 직접 열었으면) 팝업이 없다.
        if (host === amd) {
            return null;
        }

        try {
            // 띄운 화면에 Popup.js가 없으면 팝업이 아니다.
            if (!host.Popup) {
                return null;
            }
            // 띄운 화면의 팝업들 중 amd 창을 담고 있는 것.
            return host.Popup.ofWindow(amd);
        } catch (err) {
            return null;
        }
    }

    /**
     * amd를 띄운 쪽에 보고한다 — ptype: 'saved' / 'deleted' / 'closed' 등.
     * 팝업으로 띄웠든 iframe으로 넣었든 항상 띄운 쪽의
     *   action('amdReport', { ptype, payload, popupId }, amd창)
     * 하나로 간다. popupId는 팝업 안이면 그 팝업 id, 아니면 ''.
     * 단독이면 보고할 곳이 없다.
     */
    report(ptype, ppayload) {
        var amd = this.target;
        var host = amd.parent;
        var popup;
        var popupId;

        if (!ppayload) {
            ppayload = {};
        }

        // 단독(주소창/새 탭)이거나 띄운 쪽에 action이 없으면 보고할 곳이 없다.
        if (host === amd) {
            return;
        }
        if (typeof host.action !== 'function') {
            return;
        }

        popup = this.findPopup();
        if (popup !== null) {
            popupId = popup.id;
        } else {
            popupId = '';
        }

        host.action('amdReport', { ptype: ptype, payload: ppayload, popupId: popupId }, amd);
    }

    /** 제목을 바꾼다 — 팝업이면 팝업 캡션, 아니면 amd 문서 제목(브라우저 탭 글자). */
    setCaption(ptext) {
        var popup = this.findPopup();

        if (popup !== null) {
            popup.setCaption(ptext);
        } else {
            this.target.document.title = ptext;
        }
    }

    /**
     * 창닫기 — amd의 beforeCrudClose가 false면 그만둔다. 저장 안 한 값이 있으면 묻는다.
     * 띄운 쪽에 'closed'를 보고한 뒤:
     * - 팝업 안이면 팝업을 닫는다.
     * - iframe 안이면 보고만 한다(숨길지 지울지는 띄운 쪽이 정한다).
     * - 단독이면 amd 창을 닫는다(스크립트가 연 창일 때만 브라우저가 닫아 준다).
     */
    close() {
        var amd = this.target;
        var ppara = { error: '' };
        var popup;

        if (this.callParent('beforeCrudClose', ppara) === false) {
            if (ppara.error) {
                this.showMessage(ppara.error, true);
            }
            return;
        }

        if (!this.confirmDiscard('닫을까요?')) {
            return;
        }

        this.report('closed', {});

        popup = this.findPopup();
        if (popup !== null) {
            popup.close();
        } else if (amd.parent === amd) {
            // 이미 물어봤으니 브라우저가 또 묻지 않게 기준값을 지금 값으로.
            this.rememberValues();
            amd.close();
        }
    }

    // ==================================================================
    // 안에서만 쓰는 함수
    // ==================================================================

    /** 폼을 비운다(묻지 않는다) — 처음 열 때, 삭제 뒤, 신규입력 버튼이 쓴다. */
    async clearForm() {
        var self = this;
        var ok;

        ok = await this.runWork('신규입력', '', function () {
            return self.crud.clear();
        });
        if (ok) {
            this.rememberValues();
        }
        return ok;
    }

    /** PK로 레코드 하나를 연다(묻지 않는다). */
    async loadRecord(ppk) {
        var self = this;
        var ok;

        ok = await this.runWork('조회', '', function () {
            return self.crud.selectRecord(ppk);
        });
        if (ok) {
            this.rememberValues();
        }
        return ok;
    }

    /** 지금 폼 값을 기준값으로 기억한다(이 뒤로 바뀌면 "수정됨"). */
    rememberValues() {
        if (this.crud !== null) {
            this.savedValues = this.crud.getAllValues();
        }
        this.refresh();
    }

    /**
     * 저장 안 한 값이 있으면 "저장하지 않은 내용이 있습니다. 버리고 <pquestion>"을 묻는다.
     * 수정된 게 없거나, 사용자가 확인을 누르면 true(계속), 취소면 false.
     * pquestion: '새로 입력할까요?' / '닫을까요?' 처럼 물음 전체.
     */
    confirmDiscard(pquestion) {
        if (!this.isDirty()) {
            return true;
        }
        return confirm('저장하지 않은 내용이 있습니다.\n버리고 ' + pquestion);
    }

    /** 값 비교용 글자 — null/undefined는 '', 나머지는 글자로. */
    sameText(pvalue) {
        if (pvalue === null || pvalue === undefined) {
            return '';
        }
        return String(pvalue);
    }

    /** 버튼 4개 + 결과 문구 칸을 그린다. 버튼을 누르면 이 페이지의 action(pcmd)로 간다. */
    buildButtons() {
        var bar;
        var spacer;

        bar = document.createElement('div');
        bar.className = 'cb-bar';

        // 왼쪽: 저장 / 삭제 / 신규입력
        this.buttons.save = this.makeButton(bar, 'save', '\u{1F4BE} 저장');
        this.buttons.delete = this.makeButton(bar, 'delete', '✕ 삭제');
        this.buttons.clear = this.makeButton(bar, 'clear', '✚ 신규입력');

        // 가운데: 빈 칸 + 결과 문구
        spacer = document.createElement('span');
        spacer.className = 'cb-spacer';
        bar.appendChild(spacer);

        this.messageEl = document.createElement('span');
        this.messageEl.className = 'cb-message';
        bar.appendChild(this.messageEl);

        // 오른쪽 끝: 창닫기
        this.buttons.close = this.makeButton(bar, 'close', '\u{1F6AA} 창닫기');

        // 창닫기 말고는 CrudJs가 준비될 때까지 막아 둔다(refresh()가 풀어준다).
        this.buttons.save.disabled = true;
        this.buttons.delete.disabled = true;
        this.buttons.clear.disabled = true;

        this.container.appendChild(bar);
    }

    /** 버튼 하나를 만들어 pbar에 붙인다 — 누르면 action(pcmd). */
    makeButton(pbar, pcmd, plabel) {
        var btn = document.createElement('button');

        btn.type = 'button';
        btn.className = 'cb-btn cb-btn-' + pcmd;
        btn.textContent = plabel;
        btn.addEventListener('click', function () {
            if (typeof window.action === 'function') {
                window.action(pcmd, {}, window);
            }
        });

        pbar.appendChild(btn);
        return btn;
    }

    /** 부모 폼이 다 그려질 때까지 기다린다(부모의 이벤트 함수들이 정의된 뒤여야 한다). */
    async waitParentReady() {
        var doc = this.target.document;

        if (doc.readyState !== 'loading') {
            return;
        }
        await new Promise(function (resolve) {
            doc.addEventListener('DOMContentLoaded', resolve, { once: true });
        });
    }

    /**
     * 부모 폼의 이벤트 함수를 부른다 — 부모함수(psender = 이 CrudButtons, parg).
     * 그런 함수가 없으면 undefined(= 통과). 부모 함수에서 오류가 나면 false(= 막음).
     */
    callParent(pname, parg) {
        var fn = this.target[pname];

        if (typeof fn !== 'function') {
            return undefined;
        }

        try {
            return fn.call(this.target, this, parg);
        } catch (err) {
            console.error(err);
            if (parg && typeof parg === 'object' && !parg.error) {
                parg.error = pname + ' 오류: ' + err.message;
            }
            return false;
        }
    }

    /**
     * CrudJs에 이벤트를 단다 — CrudJs는 저장/삭제/신규/조회 앞뒤에
     * crud.save_before 같은 자리를 부르는데, 그 자리마다 "부모 폼의 이벤트를
     * 부르는 함수"를 넣어 둔다.
     *   ..._before → askParent(허용여부 함수, before 함수) — false면 작업 취소(CrudJs가 await)
     *   ..._after  → callParent(after 함수)
     */
    wireCrudEvents() {
        var self = this;
        var crud = this.crud;

        // 저장
        crud.save_before = function () { return self.askParent('isEnableAmdSave', 'beforeCrudSave'); };
        crud.save_after = function () { self.callParent('afterCrudSave', {}); };

        // 삭제
        crud.delete_before = function () { return self.askParent('isEnableAmdDelete', 'beforeCrudDelete'); };
        crud.delete_after = function () { self.callParent('afterCrudDelete', {}); };

        // 신규입력(폼 비우기) — 허용여부 함수는 없다.
        crud.clear_before = function () { return self.askParent(null, 'beforeCrudClear'); };
        crud.clear_after = function () { self.callParent('afterCrudClear', {}); };

        // 조회 — 허용여부 함수는 없다.
        crud.selectRecord_before = function () { return self.askParent(null, 'beforeCrudSelectRecord'); };
        crud.selectRecord_after = function () { self.callParent('afterCrudSelectRecord', {}); };
    }

    /**
     * 작업 전에 부모에게 묻는다 — ① 허용여부 함수(isEnable…, null이면 건너뜀, 동기)
     * ② before 함수(async 가능 — 결과를 기다린다).
     * 둘 중 하나라도 false면 false를 돌려줘 작업을 취소한다.
     * 이때 부모가 ppara.error에 넣은 이유를 CrudJs 오류 문구로 남긴다
     * (버튼 옆에 "저장 실패: <이유>"로 보인다).
     */
    async askParent(pisEnableName, pbeforeName) {
        var ppara = { error: '' };
        var fn;
        var result;

        // ① 허용여부 — 버튼 상태(refresh)에서도 부르니 동기로만 쓴다.
        if (pisEnableName !== null) {
            if (this.callParent(pisEnableName, ppara) === false) {
                if (ppara.error) {
                    this.crud._lastError = ppara.error;
                } else {
                    this.crud._lastError = '지금은 할 수 없습니다(' + pisEnableName + ').';
                }
                return false;
            }
        }

        // ② before — 없으면 통과. async면 끝날 때까지 기다린다.
        fn = this.target[pbeforeName];
        if (typeof fn !== 'function') {
            return true;
        }

        try {
            result = await fn.call(this.target, this, ppara);
        } catch (err) {
            console.error(err);
            result = false;
            if (!ppara.error) {
                ppara.error = pbeforeName + ' 오류: ' + err.message;
            }
        }

        if (result === false) {
            if (ppara.error) {
                this.crud._lastError = ppara.error;
            } else {
                this.crud._lastError = '취소되었습니다(' + pbeforeName + ').';
            }
            return false;
        }

        return true;
    }

    /** 부모 폼에서 값을 바꾸면 버튼 상태(수정됨 표시 포함)를 다시 정한다. */
    watchParentInput() {
        var self = this;
        var doc = this.target.document;

        doc.addEventListener('input', function () { self.refresh(); });
        doc.addEventListener('change', function () { self.refresh(); });
    }

    /**
     * amd를 단독으로 연 창(새 탭)이면, 저장 안 한 값이 있을 때 창을 닫거나 새로고침하면
     * 브라우저가 묻게 한다. 팝업/iframe 안이면 창 자체가 안 닫히니 필요 없다.
     */
    watchParentUnload() {
        var self = this;
        var amd = this.target;

        amd.addEventListener('beforeunload', function (event) {
            if (amd.parent !== amd) {
                return;
            }
            if (self.isDirty()) {
                event.preventDefault();
                event.returnValue = ''; // 옛 브라우저는 이 줄이 있어야 묻는다(새 브라우저는 위 줄로 충분).
            }
        });
    }

    /**
     * 작업 하나를 실행한다 — 도는 동안 버튼을 막고, 끝나면 결과 문구를 보여주고
     * 버튼 상태를 다시 정한다. pwork()는 CrudJs 함수를 부르고 그 결과(true/false)를 돌려준다.
     */
    async runWork(plabel, pokMessage, pwork) {
        var ok;
        var reason;

        if (this.crud === null) {
            return false;
        }

        this.busy = true;
        this.refresh();
        this.showMessage(plabel + ' 중...', false);

        try {
            ok = await pwork();
            if (ok) {
                this.showMessage(pokMessage, false);
            } else {
                reason = this.crud.getError();
                if (!reason) {
                    reason = '알 수 없는 오류';
                }
                this.showMessage(plabel + ' 실패: ' + reason, true);
            }
        } catch (err) {
            ok = false;
            this.showMessage(plabel + ' 실패: ' + err.message, true);
        }

        this.busy = false;
        this.refresh();
        return ok;
    }

    /** 버튼 옆 결과 문구 — pisError면 빨간 글씨. */
    showMessage(ptext, pisError) {
        if (!ptext) {
            ptext = '';
        }
        this.messageEl.textContent = ptext;
        this.messageEl.title = ptext;
        if (pisError) {
            this.messageEl.classList.add('cb-error');
        } else {
            this.messageEl.classList.remove('cb-error');
        }
    }
}
