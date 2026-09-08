'use strict';
// Сетевой слой и клиентское хранилище: Net (запросы к api.php, CSRF, offline-индикатор) и Store (стейт доски: user/stages/leads, загрузка get_data). Вынесено из app.js (план CODE_REVIEW п. 10.9).
/* global $, Loading, UI, ensureLeadFull, esc, fillLeadForm, goHome, handleLogoutUI, loadActivity, loadLeadComments, loadRoutes, loadUsers, openCarrier, openRoute, renderBoard, renderDetailStages, renderLeadApps, renderLog, showMustChangePassword, syncAdminNav, updateLeadNav, updateSearchPlaceholder, usersTableBusy */

const Net = {
  csrf: null, hash: null, online: true,
  setOnline(v) { this.online = v; $('#conn-dot')?.classList.toggle('offline', !v); },
  async req(action, data = null, isFormData = false) {
    try {
      let url = `api.php?action=${encodeURIComponent(action)}`;
      if (action === 'get_data' && this.hash) url += `&hash=${encodeURIComponent(this.hash)}`;
      const asActions = { get_data:1, search_leads:1, save_lead:1, move_lead:1, delete_lead:1, add_comment:1, edit_comment:1, delete_comment:1, delete_attachment:1, save_stages:1, get_comments:1, get_lead:1, save_lead_app:1, delete_lead_app:1 };
      if (Store.viewUserId && asActions[action]) url += `&as=${encodeURIComponent(Store.viewUserId)}`;
      if (action === 'search_leads' || action === 'get_directions') {
        url += `&q=${encodeURIComponent((data && data.q) || '')}`;
        data = null;
      }
      if (action === 'get_carriers' || action === 'get_carrier' || action === 'get_comments' || action === 'get_lead') {
        url += `&id=${encodeURIComponent((data && data.id) || '')}`;
        data = null;
      }
      if (action === 'get_activity') {
        // year обязателен; as — локальный выбор сотрудника на вкладке «Активность»
        // (не Store.viewUserId, чтобы не влиять на «Лиды»)
        url += `&year=${encodeURIComponent((data && data.year) || '')}`;
        if (data && data.as) url += `&as=${encodeURIComponent(data.as)}`;
        data = null;
      }
      const extra = arguments[3] || {};
      const opts = { method: data ? 'POST' : 'GET', headers: {} };
      if (extra.keepalive) opts.keepalive = true;
      // Таймаут: зависший запрос раньше держал withLock-блокировку бесконечно, и UI
      // молча переставал реагировать. keepalive-запросы (beforeunload) не абортим.
      // FormData (загрузка до 8 файлов × 5 МБ) на медленном канале легально идёт минуты —
      // для неё таймаут щедрее (ревью, п. 12.1: 20 с обрывали большие загрузки).
      if (!extra.keepalive && typeof AbortSignal !== 'undefined' && AbortSignal.timeout) {
        opts.signal = AbortSignal.timeout(isFormData ? 180000 : 20000);
      }
      if (this.csrf) opts.headers['X-CSRF-Token'] = this.csrf;
      if (isFormData) opts.body = data; else if (data) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(data); }
      const res = await fetch(url, opts);
      this.setOnline(true);
      // Если сервер вернул не-JSON (например, 502 от прокси или HTML ошибку) — не бросаем
      // непонятное исключение, а показываем осмысленное сообщение.
      const ctype = (res.headers.get('content-type') || '');
      if (!ctype.includes('application/json')) {
        if (res.status === 401) { handleLogoutUI('Сессия истекла'); return null; }
        return { success: false, error: res.ok ? 'Некорректный ответ сервера' : `Ошибка сервера (${res.status})` };
      }
      const json = await res.json();
      if (json.need_login) { handleLogoutUI(json.error); return null; }
      if (json.must_change_password) { showMustChangePassword(); return json; }
      return json;
    } catch (e) { this.setOnline(false); return { success: false, error: 'Сбой сети' }; }
  }
};

const Store = {
  viewUserId: null, viewUserName: '',
  state: { stages: [], leads: [], user: null, colleagues: [] },
  async load(force = false) {
    if (force) Loading.show();
    try {
    const res = await Net.req('get_data');
    if (!res || !res.success) return;
    if (res.unchanged) {
      if (!force) return;
      Net.hash = null;
      return this.load(true);
    }

    if (res.hash) Net.hash = res.hash;
    this.state.stages = res.stages || [];
    const prevMap = {};
    (this.state.leads || []).forEach(l => { prevMap[String(l.id)] = l; });
    this.state.leads = (res.leads || []).map(l => {
      const o = prevMap[String(l.id)];
      if (!o) return l;
      if (o._full && Number(o.updatedAt) === Number(l.updatedAt)) {
        return Object.assign({}, o, l, { _full: true, comments: o.comments, applications: o.applications, _editRev: o._editRev });
      }
      if (UI.formDirty && UI.leadId && String(l.id).trim() === String(UI.leadId).trim()) {
        l._editRev = o._editRev ?? o.updatedAt;
        l._full = o._full;
        ['email','logistName','logistPhone','comments','applications','appsStats'].forEach(k => { if (o[k] !== undefined) l[k] = o[k]; });
      }
      return l;
    });
    this.state.user = res.user;
    if (res.colleagues) {
      this.state.colleagues = res.colleagues;
      const dl = $('#colleagues-list');
      if (dl) dl.innerHTML = res.colleagues.map(u => `<option value="${esc(u.name)}"></option>`).join('');
    }

    const nameEl = $('#user-display-name'); if (nameEl) nameEl.textContent = res.user.name;
    $('#login-overlay')?.classList.remove('show');
    syncAdminNav(res.user);
    updateSearchPlaceholder();

    if (UI.currentView === 'kanban') renderBoard();
    if (UI.currentView === 'lead' && UI.leadId) {
      const lead = this.getLead(UI.leadId);
      if (!lead) { goHome(true); return; }
      await ensureLeadFull(UI.leadId);
      await loadLeadComments(UI.leadId);
      renderDetailStages(); renderLog();
      if (!UI.formDirty) fillLeadForm(lead);
      renderLeadApps();
      updateLeadNav();
    }
    if (UI.currentView === 'users' && !usersTableBusy()) loadUsers();
    if (UI.currentView === 'routes') loadRoutes();
    if (UI.currentView === 'activity') loadActivity();
    if (UI.currentView === 'route' && UI.routeId) openRoute(UI.routeId, false);
    if (UI.currentView === 'carrier' && UI.carrierId && !UI.pendingFiles.length) openCarrier(UI.carrierId, false);
    } finally { if (force) Loading.hide(); }
  },
  getLead(id) {
    if (!id) return null;
    return this.state.leads.find(l => String(l.id).trim() === String(id).trim());
  }
};
