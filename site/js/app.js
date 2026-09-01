'use strict';


const Theme = {
  key: 'crm-theme',
  get() { try { return localStorage.getItem(this.key) === 'dark' ? 'dark' : 'light'; } catch (e) { return 'light'; } },
  apply(t) {
    document.documentElement.setAttribute('data-theme', t);
    const btn = document.getElementById('theme-toggle');
    if (!btn) return;
    const dark = t === 'dark';
    btn.title = dark ? 'Светлая тема' : 'Тёмная тема';
    btn.setAttribute('aria-label', btn.title);
    btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
  },
  toggle() {
    const next = this.get() === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem(this.key, next); } catch (e) {}
    this.apply(next);
  }
};
const $  = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];
const uid = () => Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const escAttr = s => encodeURI(String(s ?? '')).replace(/"/g, '%22');
const debounce = (fn, ms) => { let t; const d = (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; d.cancel = () => clearTimeout(t); return d; };
const fmtTime = ts => new Date(ts).toLocaleString('ru-RU', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
const fmtBytes = b => { if (!b) return '0 B'; const k=1024, s=['B','KB','MB']; const i=Math.min(2, Math.floor(Math.log(b)/Math.log(k))); return `${(b/Math.pow(k,i)).toFixed(1)} ${s[i]}`; };
const isValidEmail = v => !v || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
const IMG_EXTS = ['png','jpg','jpeg','gif','webp','bmp'];
function attExt(a) {
  const n = String(a?.name || '');
  const u = String(a?.dataUrl || '');
  const m = n.match(/\.([a-z0-9]+)$/i) || u.match(/\.([a-z0-9]+)(?:$|[?&#])/i);
  return (m ? m[1] : '').toLowerCase();
}
function isImageAtt(a) {
  const t = String(a?.type || '').toLowerCase();
  if (t.startsWith('image/')) return true;
  return IMG_EXTS.includes(attExt(a));
}
function renderAttHtml(a) {
  const raw = String(a.dataUrl || '');
  const u = escAttr(raw), n = esc(a.name);
  if (isImageAtt(a) && raw) {
    return `<div class="att-image"><a href="${u}" target="_blank" rel="noopener" data-action="open-image" data-src="${u}"><img src="${u}" alt="${n}"></a></div>`;
  }
  return `<a class="att-file" href="${u}" target="_blank" rel="noopener">📄 ${n} <span class="att-size">${fmtBytes(a.size)}</span></a>`;
}
function openImageLightbox(src) {
  const box = $('#img-lightbox'), img = $('#img-lightbox-img');
  if (!box || !img || !src) return;
  img.src = src;
  box.classList.add('open');
}
function closeImageLightbox() {
  const box = $('#img-lightbox'), img = $('#img-lightbox-img');
  if (!box) return;
  box.classList.remove('open');
  if (img) img.removeAttribute('src');
}

function formatInnInput(inp) { inp.value = inp.value.replace(/\D/g, '').slice(0, 12); }
function setupPhoneMask(input) {
  if (!input || input.dataset.maskInit) return;
  input.dataset.maskInit = '1';
  let last = '';
  input.addEventListener('input', e => {
    const raw = input.value.trim();
    if (raw.startsWith('+') && !raw.startsWith('+7') && !raw.startsWith('+8')) return;
    let d = raw.replace(/\D/g, ''); if (!d) { input.value = ''; last = ''; return; }
    if (e.inputType === 'deleteContentBackward' && d === last && d.length > 0) d = d.slice(0, -1);
    if (!d || d === '7' || d === '8') { input.value = ''; last = ''; return; }
    const intl = d.length >= 11 && !d.startsWith('7') && !d.startsWith('8');
    if (intl) return;
    if (d.startsWith('8')) d = '7' + d.slice(1); else if (!d.startsWith('7')) d = '7' + d;
    d = d.slice(0, 11); last = d;
    let f = '+7'; if (d.length > 1) f += ' (' + d.slice(1, 4); if (d.length >= 5) f += ') ' + d.slice(4, 7);
    if (d.length >= 8) f += '-' + d.slice(7, 9); if (d.length >= 10) f += '-' + d.slice(9, 11);
    input.value = f;
  });
}

const Toast = {
  show(m, t='success', ms=2500) {
    const el = document.createElement('div'); el.className = `toast ${t}`; el.textContent = m;
    $('#toast-container').appendChild(el);
    setTimeout(() => { el.classList.add('hiding'); setTimeout(() => el.remove(), 250); }, ms);
  },
  success(m) { this.show(m, 'success'); },
  error(m) { this.show(m, 'error', 4000); }
};

const Modal = {
  open(id) { const el = $('#'+id); if (el) el.classList.add('open'); },
  closeAll() { $$('.modal-backdrop').forEach(m => m.classList.remove('open')); }
};

let _promptResolver = null, _confirmResolver = null;
function askPrompt(title, val='', msg='') {
  return new Promise(res => {
    if (_promptResolver) _promptResolver(null); _promptResolver = res;
    $('#prompt-title').textContent = title; $('#prompt-message').textContent = msg;
    $('#prompt-message').classList.toggle('hidden', !msg); $('#prompt-input').value = val;
    Modal.open('modal-prompt'); setTimeout(() => { $('#prompt-input').focus(); $('#prompt-input').select(); }, 50);
    $('#prompt-ok-btn').onclick = () => { Modal.closeAll(); res($('#prompt-input').value); };
  });
}
function askConfirm(title, msg='') {
  return new Promise(res => {
    if (_confirmResolver) _confirmResolver(false); _confirmResolver = res;
    $('#confirm-title').textContent = title; $('#confirm-message').textContent = msg;
    Modal.open('modal-confirm'); $('#confirm-ok-btn').onclick = () => { Modal.closeAll(); res(true); };
  });
}

const Net = {
  csrf: null, hash: null, online: true,
  setOnline(v) { this.online = v; $('#conn-dot')?.classList.toggle('offline', !v); },
  async req(action, data = null, isFormData = false) {
    try {
      let url = `api.php?action=${encodeURIComponent(action)}`;
      if (action === 'get_data' && this.hash) url += `&hash=${encodeURIComponent(this.hash)}`;
      const asActions = { get_data:1, search_leads:1, save_lead:1, move_lead:1, delete_lead:1, add_comment:1, edit_comment:1, delete_comment:1, save_stages:1, get_comments:1, get_lead:1 };
      if (Store.viewUserId && asActions[action]) url += `&as=${encodeURIComponent(Store.viewUserId)}`;
      if (action === 'search_leads' || action === 'get_directions') {
        url += `&q=${encodeURIComponent((data && data.q) || '')}`;
        data = null;
      }
      if (action === 'get_carriers' || action === 'get_carrier' || action === 'get_comments' || action === 'get_lead') {
        url += `&id=${encodeURIComponent((data && data.id) || '')}`;
        data = null;
      }
      const extra = arguments[3] || {};
      const opts = { method: data ? 'POST' : 'GET', headers: {} };
      if (extra.keepalive) opts.keepalive = true;
      if (this.csrf) opts.headers['X-CSRF-Token'] = this.csrf;
      if (isFormData) opts.body = data; else if (data) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(data); }
      const res = await fetch(url, opts); const json = await res.json();
      this.setOnline(true);
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
    const res = await Net.req('get_data');
    if (!res || !res.success) return;
    if (res.unchanged && !force) return;

    if (res.hash) Net.hash = res.hash;
    this.state.stages = res.stages || [];
    const prevMap = {};
    (this.state.leads || []).forEach(l => { prevMap[String(l.id)] = l; });
    this.state.leads = (res.leads || []).map(l => {
      const o = prevMap[String(l.id)];
      if (!o) return l;
      if (o._full && Number(o.updatedAt) === Number(l.updatedAt)) {
        return Object.assign({}, o, l, { _full: true, comments: o.comments, _editRev: o._editRev });
      }
      if (UI.formDirty && UI.leadId && String(l.id).trim() === String(UI.leadId).trim()) {
        l._editRev = o._editRev ?? o.updatedAt;
        l._full = o._full;
        ['email','cargo','format','payment','ati','logistName','logistPhone','comments'].forEach(k => { if (o[k] !== undefined) l[k] = o[k]; });
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
      updateLeadNav();
    }
    if (UI.currentView === 'users' && !usersTableBusy()) loadUsers();
    if (UI.currentView === 'routes') loadRoutes();
    if (UI.currentView === 'route' && UI.routeId) openRoute(UI.routeId, false);
    if (UI.currentView === 'carrier' && UI.carrierId && !UI.pendingFiles.length) openCarrier(UI.carrierId, false);
  },
  getLead(id) {
    if (!id) return null;
    return this.state.leads.find(l => String(l.id).trim() === String(id).trim());
  }
};

const UI = { leadId: null, routeId: null, carrierId: null, carrierRev: null, carrierComments: [], pendingFiles: [], drag: {}, currentView: 'kanban', formDirty: false, editingCommentId: null, lock: false, shellReady: false, appEvents: false };

function syncAdminNav(user) {
  const nav = $('#main-nav');
  let el = $('#nav-users');
  const admin = user?.role === 'admin';
  if (admin) {
    if (!el && nav) {
      el = document.createElement('span');
      el.className = 'nav-item';
      el.id = 'nav-users';
      el.dataset.action = 'go-users';
      el.textContent = 'Сотрудники';
      nav.appendChild(el);
    }
  } else if (el) el.remove();
}

async function ensureLeadFull(id) {
  const lead = Store.getLead(id);
  if (!lead) return null;
  if (lead._full) return lead;
  const res = await Net.req('get_lead', { id });
  if (!res || !res.success || !res.lead) return lead;
  const keepComments = lead.comments;
  Object.assign(lead, res.lead);
  if (keepComments && !res.lead.comments) lead.comments = keepComments;
  lead._full = true;
  if (lead._editRev == null) lead._editRev = lead.updatedAt;
  return lead;
}

function isSystemComment(c) {
  return String(c?.author || '').trim() === 'Система';
}
function isReservedUserName(name) {
  const n = String(name || '').trim().toLowerCase();
  return n === 'система' || n === 'system';
}
function persistOk(res) {
  if (res == null) return true;
  return res.success !== false;
}
function authorInitial(c) {
  const a = String(c?.author || '').trim();
  return a ? a[0].toUpperCase() : '?';
}
function canEditComment(c) {
  const user = Store.state.user;
  if (!user) return false;
  if (isSystemComment(c)) return false;
  if (user.role === 'admin') return true;
  const uid = Number(c.userId || 0);
  if (uid > 0) return uid === Number(user.id);
  return (c.author || '') === (user.name || '');
}
function canDeleteComment(c) {
  if (!Store.state.user) return false;
  if (isSystemComment(c)) return true;
  return canEditComment(c);
}
function logActionsHtml(c) {
  const bits = [];
  if (canEditComment(c)) bits.push(`<span class="log-btn" data-action="toggle-edit" data-cid="${esc(c.id)}">✎ Изменить</span>`);
  if (canDeleteComment(c)) bits.push(`<span class="log-btn del" data-action="del-comment" data-cid="${esc(c.id)}">🗑️</span>`);
  return bits.length ? `<div class="log-actions">${bits.join('')}</div>` : '';
}

function closeSearchDrop() { $('#search-drop')?.classList.remove('open'); }

function placeSearchDrop() {}

function localSearchEmployees(q) {
  if (Store.state.user?.role !== 'admin') return [];
  const query = String(q || '').trim().toLowerCase();
  if (!query) return [];
  return (Store.state.colleagues || []).filter(u => String(u.name || '').toLowerCase().includes(query)).slice(0, 20);
}

function updateSearchPlaceholder() {
  const inp = $('#board-search'); if (!inp) return;
  inp.placeholder = Store.state.user?.role === 'admin'
    ? 'Поиск по названию, ИНН или сотруднику'
    : 'Поиск по названию или ИНН';
}

function updateViewBanner() {
  const b = $('#view-user-banner'); if (!b) return;
  b.classList.toggle('show', !!Store.viewUserId);
  const n = $('#view-user-name'); if (n) n.textContent = Store.viewUserName || '';
}

async function viewUserBoard(id, name) {
  id = parseInt(id, 10);
  closeSearchDrop();
  clearSearch();
  if (!id || id === Store.state.user?.id) {
    await exitViewUser();
    return;
  }
  Store.viewUserId = id;
  Store.viewUserName = name || '';
  Net.hash = null; _lastBoardHash = null;
  updateViewBanner();
  if (UI.currentView !== 'kanban') goHome(true);
  await Store.load(true);
}

async function exitViewUser() {
  const had = !!Store.viewUserId;
  Store.viewUserId = null; Store.viewUserName = '';
  Net.hash = null; _lastBoardHash = null;
  updateViewBanner();
  if (UI.currentView !== 'kanban') goHome(true);
  if (had) await Store.load(true); else renderBoard();
}

function localSearchLeads(q) {
  const query = String(q || '').trim().toLowerCase();
  const digits = query.replace(/\D/g, '');
  if (!query) return [];
  return Store.state.leads.filter(l => {
    const title = String(l.title || '').toLowerCase();
    const inn = String(l.inn || '').replace(/\D/g, '');
    return title.includes(query) || (digits.length >= 2 && inn.includes(digits));
  }).slice(0, 40).map(l => ({ id: l.id, title: l.title, inn: l.inn, stage: l.stage, phone: l.phone }));
}

function ownersOf(lead, intersections) {
  const inn = String(lead.inn || '').replace(/\D/g, '');
  const title = String(lead.title || '').toLowerCase();
  const names = [];
  (intersections || []).forEach(it => {
    const iInn = String(it.inn || '').replace(/\D/g, '');
    const same = (inn && iInn && inn === iInn) || (!inn && String(it.title || '').toLowerCase() === title);
    if (same) (it.users || []).forEach(n => { if (n && !names.includes(n)) names.push(n); });
  });
  return names;
}

function renderSearchDrop(payload) {
  const box = $('#search-drop'); if (!box) return;
  const leads = payload.leads || [];
  const inter = payload.intersections || [];
  const emps = payload.employees || [];
  const used = new Set();
  let html = '';
  emps.forEach(u => {
    const mine = Store.state.user && +u.id === +Store.state.user.id;
    const watching = Store.viewUserId && +u.id === +Store.viewUserId;
    const meta = mine ? 'Ваша доска' : (watching ? 'Сейчас открыта' : 'Открыть доску сотрудника');
    html += `<div class="search-item emp" data-action="view-user-board" data-id="${esc(u.id)}" data-name="${esc(u.name)}"><div class="search-item-title">${esc(u.name)}</div><div class="search-item-meta">${meta}</div></div>`;
  });
  leads.forEach(l => {
    const names = ownersOf(l, inter);
    if (String(l.inn || '').replace(/\D/g, '')) used.add('inn:' + String(l.inn).replace(/\D/g, ''));
    else used.add('t:' + String(l.title || '').toLowerCase());
    const warn = names.length ? `<div class="search-item-warn">Есть пересечения, карточка у ${esc(names.join(', '))}</div>` : '';
    const meta = [l.inn ? 'ИНН ' + l.inn : '', l.stage || ''].filter(Boolean).join(' · ');
    html += `<div class="search-item" data-action="open-search-lead" data-id="${esc(l.id)}"><div class="search-item-title">${esc(l.title)}</div><div class="search-item-meta">${esc(meta)}</div>${warn}</div>`;
  });
  inter.forEach(it => {
    const inn = String(it.inn || '').replace(/\D/g, '');
    const key = inn ? ('inn:' + inn) : ('t:' + String(it.title || '').toLowerCase());
    if (used.has(key)) return;
    const names = (it.users || []).filter(Boolean);
    if (!names.length) return;
    const meta = it.inn ? 'ИНН ' + it.inn : '';
    html += `<div class="search-item other"><div class="search-item-title">${esc(it.title)}</div>${meta ? `<div class="search-item-meta">${esc(meta)}</div>` : ''}<div class="search-item-warn">Есть пересечения, карточка у ${esc(names.join(', '))}</div></div>`;
  });
  if (!html) html = '<div class="search-empty">Ничего не найдено</div>';
  box.innerHTML = html;
  placeSearchDrop();
  box.classList.add('open');
}

let _searchGen = 0;
async function liveSearch(q) {
  const wrap = $('#board-search-wrap');
  const query = String(q || '').trim();
  wrap?.classList.toggle('has-query', !!query);
  if (!query) { closeSearchDrop(); return; }
  const local = localSearchLeads(query);
  const localEmp = localSearchEmployees(query);
  renderSearchDrop({ leads: local, intersections: [], employees: localEmp });
  const gen = ++_searchGen;
  const res = await Net.req('search_leads', { q: query });
  if (gen !== _searchGen) return;
  if (res && res.success) {
    const leads = (res.leads && res.leads.length) ? res.leads : local;
    const employees = (res.employees && res.employees.length) ? res.employees : localEmp;
    renderSearchDrop({ leads, intersections: res.intersections || [], employees });
  }
}

function clearSearch() {
  const inp = $('#board-search'); if (inp) inp.value = '';
  $('#board-search-wrap')?.classList.remove('has-query');
  closeSearchDrop();
}

function handleLogoutUI(msg) {
  stopPolling();
  UI.leadId = null; UI.routeId = null; UI.carrierId = null; UI.carrierComments = []; UI.editingCommentId = null; UI.formDirty = false;
  Store.viewUserId = null; Store.viewUserName = '';
  Store.state.user = null; Store.state.leads = []; Store.state.stages = [];
  clearSearch();
  history.replaceState(null, '', location.pathname);
  $$('.view-section').forEach(el => el.classList.remove('active'));
  const kv = $('#kanban-view'); if (kv) kv.classList.add('active');
  document.body.classList.remove('booting');
  document.body.classList.add('guest');
  $('#login-overlay').classList.add('show');
  if (msg && msg !== 'Сессия истекла') Toast.error(msg);
  Net.req('csrf').then(tok => { if (tok && tok.csrf) Net.csrf = tok.csrf; });
}

function withLock(fn) { return async (...args) => { if (UI.lock) return; UI.lock = true; try { await fn(...args); } finally { UI.lock = false; } }; }

/* === НАВИГАЦИЯ (РОУТЕР) === */
function handleHashRouting() {
  const hash = window.location.hash;
  if (hash.startsWith('#lead/')) {
    const targetLeadId = decodeURIComponent(hash.replace('#lead/', ''));
    if (Store.getLead(targetLeadId)) { openLead(targetLeadId, false); return; }
  } else if (hash.startsWith('#carrier/')) {
    openCarrier(decodeURIComponent(hash.replace('#carrier/', '')), false); return;
  } else if (hash.startsWith('#route/')) {
    openRoute(decodeURIComponent(hash.replace('#route/', '')), false); return;
  } else if (hash === '#routes') {
    switchView('routes-view', false); return;
  } else if (hash === '#users' && Store.state.user?.role === 'admin') {
    switchView('users-view', false); return;
  }
  switchView('kanban-view', false);
}

// При нажатии кнопок Назад/Вперед в браузере
window.addEventListener('popstate', () => { if (Store.state.user) handleHashRouting(); });

async function switchView(viewId, updateHash = true) {
  if (UI.leadId && UI.formDirty) {
    const saved = await saveLeadForm(true);
    if (!persistOk(saved)) return;
    if (saved.transferred) await Store.load(true);
  }
  if (UI.carrierId && UI.formDirty) {
    const savedC = await saveCarrierForm(true);
    if (!persistOk(savedC)) return;
  }
  UI.leadId = null; UI.routeId = null; UI.carrierId = null; UI.carrierComments = []; UI.pendingFiles = []; UI.formDirty = false; UI.editingCommentId = null;
  UI.currentView = viewId.replace('-view', '');

  $$('.view-section').forEach(el => el.classList.remove('active'));
  $$('.nav-item').forEach(el => el.classList.remove('active'));
  const viewEl = $('#'+viewId); if (viewEl) viewEl.classList.add('active');

  if (viewId === 'kanban-view') {
    $('#nav-leads')?.classList.add('active');
    if (updateHash) history.pushState(null, '', '#kanban');
    renderBoard();
  } else if (viewId === 'users-view') {
    $('#nav-users')?.classList.add('active');
    if (updateHash) history.pushState(null, '', '#users');
    loadUsers();
  } else if (viewId === 'routes-view') {
    $('#nav-routes')?.classList.add('active');
    if (updateHash) history.pushState(null, '', '#routes');
    loadRoutes();
  }
}

function goHome(updateHash = true) { return switchView('kanban-view', updateHash); }

let _routesCache = [];
let _routesFilter = '';

async function loadRoutes() {
  const res = await Net.req('get_directions', { q: _routesFilter });
  if (!res || !res.success) return;
  _routesCache = res.directions || [];
  renderRoutes();
}

function renderRoutes() {
  const grid = $('#routes-grid'); if (!grid) return;
  const list = _routesCache;
  if (!list.length) {
    grid.innerHTML = `<div class="routes-empty">${_routesFilter ? 'Ничего не найдено' : 'Пока нет направлений. Добавьте первое — например Челябинск → Уфа.'}</div>`;
    return;
  }
  grid.innerHTML = list.map(d => `
    <div class="route-card" data-action="open-route" data-id="${esc(d.id)}">
      <div class="route-card-title">${esc(d.cityFrom)}<span class="route-card-arrow">→</span>${esc(d.cityTo)}</div>
      <div class="route-card-meta">${d.carriersCount} ${carrierWord(d.carriersCount)}</div>
    </div>`).join('');
}

function carrierWord(n) {
  n = Math.abs(n) % 100; const n1 = n % 10;
  if (n > 10 && n < 20) return 'перевозчиков';
  if (n1 === 1) return 'перевозчик';
  if (n1 >= 2 && n1 <= 4) return 'перевозчика';
  return 'перевозчиков';
}

async function openRoute(id, updateHash = true) {
  if (UI.leadId && UI.formDirty) {
    const saved = await saveLeadForm(true);
    if (!persistOk(saved)) return;
  }
  if (UI.carrierId && UI.formDirty) {
    const savedC = await saveCarrierForm(true);
    if (!persistOk(savedC)) return;
  }
  UI.leadId = null; UI.formDirty = false; UI.pendingFiles = [];
  const res = await Net.req('get_carriers', { id });
  if (!res || !res.success) { switchView('routes-view', updateHash); return; }
  UI.routeId = id; UI.currentView = 'route';
  $$('.view-section').forEach(el => el.classList.remove('active'));
  $$('.nav-item').forEach(el => el.classList.remove('active'));
  $('#route-view').classList.add('active');
  $('#nav-routes')?.classList.add('active');
  const d = res.direction;
  $('#route-crumb').textContent = `${d.cityFrom} → ${d.cityTo}`;
  if (updateHash && window.location.hash !== '#route/' + encodeURIComponent(id)) {
    history.pushState(null, '', '#route/' + encodeURIComponent(id));
  }
  renderCarriers(res.carriers || []);
}

let _carriersCache = [];

function renderCarriers(list) {
  _carriersCache = list || [];
  const tbody = $('#carriers-tbody'); if (!tbody) return;
  if (!list.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="cell-muted">Пока нет перевозчиков на этом направлении</td></tr>';
    return;
  }
  tbody.innerHTML = '';
  list.forEach(c => {
    const n = c.commentsCount || 0;
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><span class="name-link" data-action="open-carrier" data-id="${esc(c.id)}">${esc(c.name)}</span></td>
      <td>${esc(c.phone)}</td>
      <td>${esc(c.company)}</td>
      <td><span class="log-link" data-action="open-carrier" data-id="${esc(c.id)}">${n ? n + ' зап.' : 'Открыть лог'}</span></td>
      <td>${esc(c.createdByName)}</td>
      <td><button class="btn btn-danger btn-sm" data-action="delete-carrier" data-id="${esc(c.id)}">🗑️</button></td>`;
    tbody.appendChild(tr);
  });
}

async function openCarrier(id, updateHash = true) {
  if (UI.leadId && UI.formDirty) {
    const savedL = await saveLeadForm(true);
    if (!persistOk(savedL)) return;
  }
  if (UI.carrierId && UI.formDirty) {
    const savedC = await saveCarrierForm(true);
    if (!persistOk(savedC)) return;
  }
  const res = await Net.req('get_carrier', { id });
  if (!res || !res.success) {
    if (UI.routeId) { openRoute(UI.routeId, updateHash); return; }
    switchView('routes-view', updateHash); return;
  }
  UI.leadId = null;
  UI.carrierId = id;
  UI.currentView = 'carrier';
  UI.pendingFiles = [];
  UI.formDirty = false;
  UI.editingCommentId = null;
  UI.carrierComments = res.comments || [];
  const c = res.carrier, d = res.direction;
  if (c.directionId) UI.routeId = c.directionId;
  $$('.view-section').forEach(el => el.classList.remove('active'));
  $$('.nav-item').forEach(el => el.classList.remove('active'));
  $('#carrier-view').classList.add('active');
  $('#nav-routes')?.classList.add('active');
  $('#cf-name').value = c.name || '';
  $('#cf-phone').value = c.phone || '';
  $('#cf-company').value = c.company || '';
  if ($('#cf-note')) $('#cf-note').value = c.note || '';
  UI.carrierRev = c.updatedAt;
  $('#carrier-crumb').textContent = c.name || '';
  $('#carrier-dir-crumb').textContent = d ? `${d.cityFrom} → ${d.cityTo}` : 'Направление';
  setupPhoneMask($('#cf-phone'));
  if (updateHash && window.location.hash !== '#carrier/' + encodeURIComponent(id)) {
    history.pushState(null, '', '#carrier/' + encodeURIComponent(id));
  }
  renderCarrierLog();
  renderFiles();
  if (!_carriersCache.length || !_carriersCache.some(x => String(x.id).trim() === String(id).trim())) {
    if (UI.routeId) {
      const listRes = await Net.req('get_carriers', { id: UI.routeId });
      if (listRes && listRes.success) _carriersCache = listRes.carriers || [];
    }
  }
  updateCarrierNav();
}

function updateCarrierNav() {
  const prevBtn = $('#btn-prev-carrier'), nextBtn = $('#btn-next-carrier'), pos = $('#carrier-nav-pos');
  if (!prevBtn || !nextBtn || !pos) return;
  const list = _carriersCache || [];
  const n = list.length;
  let idx = list.findIndex(c => String(c.id).trim() === String(UI.carrierId).trim());
  if (idx < 0) idx = 0;
  pos.textContent = n ? `${idx + 1} / ${n}` : '';
  prevBtn.disabled = n < 2;
  nextBtn.disabled = n < 2;
}

function goNeighborCarrier(dir) {
  const list = _carriersCache || [];
  if (list.length < 2) return;
  let idx = list.findIndex(c => String(c.id).trim() === String(UI.carrierId).trim());
  if (idx < 0) idx = 0;
  const next = list[(idx + dir + list.length) % list.length];
  if (next) openCarrier(next.id, true);
}

function fillCarrierFromForm() {
  return {
    id: UI.carrierId,
    directionId: UI.routeId,
    name: $('#cf-name').value.trim() || 'Без названия',
    phone: $('#cf-phone').value.trim(),
    company: $('#cf-company').value.trim(),
    note: ($('#cf-note') && $('#cf-note').value.trim()) || '',
    updatedAt: UI.carrierRev
  };
}

let _carrierSaveChain = Promise.resolve();
async function saveCarrierForm(sync = false, keepalive = false) {
  if (!UI.carrierId) return null;
  const run = async () => {
    const patch = fillCarrierFromForm();
    $('#carrier-crumb').textContent = patch.name;
    const extra = keepalive ? { keepalive: true } : {};
    const res = await Net.req('save_carrier', patch, false, extra);
    if (res && res.success === false && res.error === 'Карточка изменена в другом месте') {
      Toast.error('Карточку изменили в другой вкладке — обновляю');
      if (UI.carrierId) await openCarrier(UI.carrierId, false);
      return res;
    }
    if (res && res.success && res.updatedAt) UI.carrierRev = res.updatedAt;
    if (res && res.success) UI.formDirty = false;
    else if (res && res.success === false) Toast.error(res.error || 'Ошибка');
    return res;
  };
  const job = _carrierSaveChain.then(run, run);
  _carrierSaveChain = job.catch(() => {});
  return sync ? job : job;
}
const saveCarrierDebounced = debounce(() => saveCarrierForm(false), 500);

function renderCarrierLog() {
  const log = $('#carrier-chatter-log'); if (!log) return;
  const comments = UI.carrierComments || [];
  if (!comments.length) { log.innerHTML = '<div class="log-empty">Лог пуст</div>'; return; }
  log.innerHTML = '';
  const frag = document.createDocumentFragment();
  [...comments].reverse().forEach(c => {
    const init = authorInitial(c);
    const atts = (c.attachments || []).map(renderAttHtml).join('');
    const el = document.createElement('div'); el.className = 'log-entry';
    el.innerHTML = `
      <div class="log-avatar">${esc(init)}</div>
      <div class="log-body">
        <div class="log-head">
          <div><div class="log-author">${esc(c.author)}</div><div>${esc(fmtTime(c.time))}${c.editedAt ? ` <span class="log-edited">изм. ${esc(fmtTime(c.editedAt))}</span>` : ''}</div></div>
          ${logActionsHtml(c)}
        </div>
        <div class="log-text" data-txt="${esc(c.id)}">${esc(c.text)}</div>
        <div class="inline-editor" data-edt="${esc(c.id)}">
          <textarea data-inp="${esc(c.id)}">${esc(c.text)}</textarea>
          <div class="inline-edit-btns"><button class="btn btn-secondary btn-sm" data-action="toggle-edit" data-cid="${esc(c.id)}">Отмена</button><button class="btn btn-primary btn-sm" data-action="save-comment" data-cid="${esc(c.id)}">Сохранить</button></div>
        </div>
        ${atts ? `<div class="attachments">${atts}</div>` : ''}
      </div>`;
    frag.appendChild(el);
  });
  log.appendChild(frag);
  if (UI.editingCommentId) {
    const edt = $(`[data-edt="${UI.editingCommentId}"]`), txt = $(`[data-txt="${UI.editingCommentId}"]`);
    if (edt && txt) { edt.classList.add('active'); txt.classList.add('hidden'); }
  }
}

async function openLead(id, updateHash = true) {
  await ensureLeadFull(id);
  const lead = Store.getLead(id); if (!lead || !lead._full) return goHome(updateHash);

  if (UI.leadId && UI.formDirty && UI.leadId !== id) {
    const saved = await saveLeadForm(true);
    if (!persistOk(saved)) return;
    if (saved && saved.transferred) await Store.load(true);
  }
  const still = Store.getLead(id); if (!still || !still._full) return goHome(updateHash);

  UI.leadId = id; UI.currentView = 'lead'; UI.pendingFiles = []; UI.formDirty = false;
  $$('.view-section').forEach(el => el.classList.remove('active'));
  $('#detail-view').classList.add('active');
  fillLeadForm(still, true);

  if (updateHash && window.location.hash !== '#lead/' + encodeURIComponent(id)) {
    history.pushState(null, '', '#lead/' + encodeURIComponent(id));
  }

  await loadLeadComments(id);
  renderDetailStages(); renderFiles(); renderLog();
  updateLeadNav();
}

function ownLeadsOrdered() {
  const stages = Store.state.stages || [];
  const leads = Store.state.leads || [];
  const ordered = [];
  stages.forEach(stage => {
    leads.forEach(l => { if (l.stage === stage) ordered.push(l); });
  });
  leads.forEach(l => { if (!stages.includes(l.stage)) ordered.push(l); });
  return ordered;
}

function updateLeadNav() {
  const prevBtn = $('#btn-prev-lead'), nextBtn = $('#btn-next-lead'), pos = $('#lead-nav-pos');
  if (!prevBtn || !nextBtn || !pos) return;
  const list = ownLeadsOrdered();
  const n = list.length;
  let idx = list.findIndex(l => String(l.id).trim() === String(UI.leadId).trim());
  if (idx < 0) idx = 0;
  pos.textContent = n ? `${idx + 1} / ${n}` : '';
  prevBtn.disabled = n < 2;
  nextBtn.disabled = n < 2;
}

function goNeighborLead(dir) {
  const list = ownLeadsOrdered();
  if (list.length < 2) return;
  let idx = list.findIndex(l => String(l.id).trim() === String(UI.leadId).trim());
  if (idx < 0) idx = 0;
  const next = list[(idx + dir + list.length) % list.length];
  if (next) openLead(next.id, true);
}

async function loadUsers() {
  const res = await Net.req('get_users'); if (!res || !res.success) return;
  const tbody = $('#users-tbody'); tbody.innerHTML = '';
  const currId = Store.state.user.id;
  res.users.forEach(u => {
    const tr = document.createElement('tr');
    const role = u.role === 'admin' ? 'admin' : 'user';
    tr.innerHTML = `
      <td>${u.id}</td>
      <td><input class="user-input" id="uname-${u.id}" value="${esc(u.name)}"></td>
      <td><input class="user-input" id="uemail-${u.id}" value="${esc(u.email)}"></td>
      <td><select class="user-input" id="urole-${u.id}"><option value="user"${role==='user'?' selected':''}>Сотрудник</option><option value="admin"${role==='admin'?' selected':''}>Админ</option></select></td>
      <td><input class="user-input" id="upass-${u.id}" type="password" placeholder="Пусто = не менять"></td>
      <td>
        <button class="btn btn-primary btn-sm" data-action="save-user" data-id="${u.id}">💾</button>
        ${u.id !== currId ? `<button class="btn btn-danger btn-sm" data-action="delete-user" data-id="${u.id}">🗑️</button>` : ''}
      </td>`;
    tbody.appendChild(tr);
  });
}

async function execLogout() {
  if (UI.formDirty && !await askConfirm('Есть несохранённые изменения', 'Выйти без сохранения?')) return;
  UI.formDirty = false;
  await Net.req('logout', {}); location.reload();
}

function fillLeadForm(lead, fromServer = false) {
  if (!lead || !lead._full) return;
  if (fromServer || lead._editRev == null) lead._editRev = lead.updatedAt;
  $('#f-title').value = lead.title;
  ['inn','phone','email','manager','cargo','format','payment','ati'].forEach(f => {
    const el = $(`#f-${f}`); if (el && document.activeElement !== el) el.value = lead[f] || '';
  });
  const ln = $('#f-logist-name'); if (ln && document.activeElement !== ln) ln.value = lead.logistName || '';
  const lp = $('#f-logist-phone'); if (lp && document.activeElement !== lp) lp.value = lead.logistPhone || '';
  if (document.activeElement !== $('#f-apps')) $('#f-apps').value = lead.applicationsCount || 0;
  $('#crumb-name').textContent = lead.title; setupPhoneMask($('#f-phone')); setupPhoneMask($('#f-logist-phone'));
}

let _leadSaveChain = Promise.resolve();
async function saveLeadForm(sync = false, keepalive = false, transfer = false) {
  if (!UI.leadId) return null; const lead = Store.getLead(UI.leadId); if (!lead || !lead._full) return null;
  const run = async () => {
    const cur = Store.getLead(UI.leadId); if (!cur || !cur._full) return null;
    const patch = { id: UI.leadId, title: $('#f-title').value.trim() || 'Без названия', inn: $('#f-inn').value.trim(), phone: $('#f-phone').value.trim(), email: $('#f-email').value.trim(), manager: $('#f-manager').value.trim(), logistName: ($('#f-logist-name')?.value || '').trim(), logistPhone: ($('#f-logist-phone')?.value || '').trim(), cargo: $('#f-cargo').value.trim(), format: $('#f-format').value.trim(), payment: $('#f-payment').value.trim(), ati: $('#f-ati').value.trim(), applicationsCount: parseInt($('#f-apps').value) || 0, stage: cur.stage, updatedAt: cur._editRev ?? cur.updatedAt };
    if (!isValidEmail(patch.email)) {
      Toast.error('Некорректный email');
      return { success: false, error: 'Некорректный email' };
    }
    $('#crumb-name').textContent = patch.title;
    if (transfer) patch.transfer = true;
    const extra = keepalive ? { keepalive: true } : {};
    const res = await Net.req('save_lead', patch, false, extra);
    if (res && res.transferred) {
      Toast.success('Лид передан: ' + res.to);
      UI.leadId = null;
      UI.formDirty = false;
      return res;
    }
    if (res && res.success === false) {
      if (res.error === 'Карточка изменена в другом месте') {
        Toast.error('Карточку изменили в другой вкладке — обновляю');
        await Store.load(true);
        if (UI.leadId) { await ensureLeadFull(UI.leadId); const fresh = Store.getLead(UI.leadId); if (fresh) { fresh._editRev = fresh.updatedAt; fillLeadForm(fresh, true); } }
      } else Toast.error(res.error || 'Ошибка');
      return res;
    }
    if (res && res.success) {
      UI.formDirty = false;
      Object.assign(cur, patch);
      if (res.updatedAt) { cur.updatedAt = res.updatedAt; cur._editRev = res.updatedAt; }
    }
    return res;
  };
  const job = _leadSaveChain.then(run, run);
  _leadSaveChain = job.catch(() => {});
  return job;
}
const saveLeadDebounced = debounce(() => saveLeadForm(false), 500);

let _lastBoardHash = null;
function renderBoard() {
  if (UI.currentView !== 'kanban') return;
  const dataHash = JSON.stringify([Store.state.stages, Store.state.leads.map(l => [l.id, l.stage, l.title, l.phone, l.manager, l.inn])]);
  if (dataHash === _lastBoardHash) return; _lastBoardHash = dataHash;

  const board = $('#board'); if (!board) return; board.innerHTML = ''; const frag = document.createDocumentFragment();
  Store.state.stages.forEach(stage => {
    const leads = Store.state.leads.filter(l => l.stage === stage);
    const col = document.createElement('div'); col.className = 'column'; col.dataset.stage = stage; col.draggable = true;
    col.innerHTML = `<div class="column-header"><div class="col-title"><span>${esc(stage)}</span><span class="col-edit" data-action="edit-stage">✎</span></div><span class="col-count">${leads.length}</span></div><div class="cards-container"></div>`;
    const cont = col.querySelector('.cards-container');
    leads.forEach(l => {
      const card = document.createElement('div'); card.className = 'card'; card.draggable = true; card.dataset.id = l.id;
      const innLine = l.inn ? `<span>ИНН ${esc(l.inn)}</span>` : '<span></span>';
      card.innerHTML = `<div class="card-title">${esc(l.title)}</div><div class="card-meta">${innLine}<span>📱 ${esc(l.phone || 'Нет')}</span></div>`;
      cont.appendChild(card);
    });
    frag.appendChild(col);
  });
  const add = document.createElement('div'); add.className = 'add-column'; add.textContent = '+ Добавить этап'; add.dataset.action = 'add-stage'; frag.appendChild(add);
  board.appendChild(frag);
}

function renderDetailStages() {
  const row = $('#stages-row'), lead = Store.getLead(UI.leadId); if (!lead) return; row.innerHTML = '';
  Store.state.stages.forEach(s => {
    const b = document.createElement('button'); b.className = 'stage-btn' + (s === lead.stage ? ' active' : '');
    b.dataset.action = 'set-stage'; b.dataset.stage = s; b.textContent = s; row.appendChild(b);
  });
}

function renderFiles() {
  const box = UI.currentView === 'carrier' ? $('#carrier-files-preview') : $('#files-preview');
  if (!box) return; box.innerHTML = '';
  UI.pendingFiles.forEach((f, i) => {
    const el = document.createElement('div'); el.className = 'file-chip';
    el.innerHTML = `<span>📎 ${esc(f.name)}</span> <span class="chip-remove" data-action="rm-file" data-idx="${i}">×</span>`;
    box.appendChild(el);
  });
}

function renderLog() {
  const lead = Store.getLead(UI.leadId); const log = $('#chatter-log');
  if (!log) return;
  if (!lead || !lead.comments || !lead.comments.length) { log.innerHTML = '<div class="log-empty">Лог пуст</div>'; return; }
  log.innerHTML = '';
  const frag = document.createDocumentFragment();

  [...lead.comments].reverse().forEach(c => {
    const isSys = isSystemComment(c);
    const init = isSys ? '⚙' : authorInitial(c);
    const atts = (c.attachments || []).map(renderAttHtml).join('');

    const el = document.createElement('div'); el.className = 'log-entry';
    el.innerHTML = `
      <div class="log-avatar${isSys ? ' sys' : ''}">${esc(init)}</div>
      <div class="log-body">
        <div class="log-head">
          <div><div class="log-author">${esc(c.author)}</div><div>${esc(fmtTime(c.time))}${c.editedAt ? ` <span class="log-edited">изм. ${esc(fmtTime(c.editedAt))}</span>` : ''}</div></div>
          ${logActionsHtml(c)}
        </div>
        <div class="log-text" data-txt="${esc(c.id)}">${esc(c.text)}</div>
        <div class="inline-editor" data-edt="${esc(c.id)}">
          <textarea data-inp="${esc(c.id)}">${esc(c.text)}</textarea>
          <div class="inline-edit-btns"><button class="btn btn-secondary btn-sm" data-action="toggle-edit" data-cid="${esc(c.id)}">Отмена</button><button class="btn btn-primary btn-sm" data-action="save-comment" data-cid="${esc(c.id)}">Сохранить</button></div>
        </div>
        ${atts ? `<div class="attachments">${atts}</div>` : ''}
      </div>`;
    frag.appendChild(el);
  });
  log.appendChild(frag);

  if (UI.editingCommentId) {
    const edt = $(`[data-edt="${UI.editingCommentId}"]`), txt = $(`[data-txt="${UI.editingCommentId}"]`);
    if (edt && txt) { edt.classList.add('active'); txt.classList.add('hidden'); }
  }
}

function initEvents() {
  $('#btn-login').addEventListener('click', execLogin);
  $('#login-password').addEventListener('keydown', e => { if (e.key === 'Enter') execLogin(); });
  $('#login-email').addEventListener('keydown', e => { if (e.key === 'Enter') $('#login-password').focus(); });
}

function initAppEvents() {
  if (UI.appEvents) return;
  UI.appEvents = true;
  setupPhoneMask($('#m-phone')); setupPhoneMask($('#f-phone')); setupPhoneMask($('#f-logist-phone'));
  $('#m-inn').addEventListener('input', e => formatInnInput(e.target));
  $('#f-inn').addEventListener('input', e => formatInnInput(e.target));

  const searchInp = $('#board-search');
  const runSearch = debounce(() => liveSearch(searchInp.value), 150);
  searchInp.addEventListener('input', runSearch);
  searchInp.addEventListener('focus', () => { if (searchInp.value.trim()) liveSearch(searchInp.value); });
  searchInp.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const first = $('#search-drop .search-item[data-action]');
      if (first) first.click();
    } else if (e.key === 'Escape' && (searchInp.value || $('#search-drop.open'))) {
      e.preventDefault(); e.stopPropagation();
      clearSearch();
    }
  });
  $('#board-search-clear').addEventListener('click', () => { clearSearch(); searchInp.focus(); });
  const routesSearch = $('#routes-search');
  const runRoutesSearch = debounce(() => { _routesFilter = routesSearch.value.trim(); loadRoutes(); }, 200);
  routesSearch.addEventListener('input', runRoutesSearch);
  setupPhoneMask($('#k-phone'));
  document.addEventListener('click', e => {
    if (!e.target.closest('#board-search-wrap') && !e.target.closest('#search-drop')) closeSearchDrop();
  });
  window.addEventListener('resize', () => { if ($('#search-drop.open')) placeSearchDrop(); });

  $('#btn-prev-lead').addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); goNeighborLead(-1); });
  $('#btn-next-lead').addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); goNeighborLead(1); });
  $('#btn-prev-carrier').addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); goNeighborCarrier(-1); });
  $('#btn-next-carrier').addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); goNeighborCarrier(1); });

  window.addEventListener('beforeunload', e => {
    if (!UI.formDirty) return;
    if (UI.carrierId) saveCarrierForm(false, true); else saveLeadForm(false, true);
    e.preventDefault();
    e.returnValue = '';
  });
  $('#detail-view').addEventListener('input', e => { if (e.target.matches('.form-input, .editable-title, #f-apps')) { UI.formDirty = true; saveLeadDebounced(); } });
  $('#f-manager').addEventListener('blur', async () => {
    if (!UI.leadId) return;
    saveLeadDebounced.cancel();
    const lead = Store.getLead(UI.leadId);
    const typed = ($('#f-manager').value || '').trim();
    const prev = (lead && lead.manager) || '';
    let transfer = false;
    if (typed && Store.state.colleagues) {
      const q = typed.toLowerCase();
      const hits = Store.state.colleagues.filter(u => {
        const n = String(u.name || '').toLowerCase();
        if (n === q) return true;
        return n.split(/\s+/).includes(q);
      });
      const me = Store.state.user && hits.length === 1 && +hits[0].id === +Store.state.user.id;
      if (hits.length === 1 && !me) {
        if (!await askConfirm('Передать лид?', 'Лид уйдёт сотруднику «' + hits[0].name + '»')) {
          $('#f-manager').value = prev;
          return;
        }
        transfer = true;
      }
    }
    const res = await saveLeadForm(true, false, transfer);
    if (res && res.transferred) { await Store.load(true); await goHome(true); }
  });
  $('#carrier-view').addEventListener('input', e => { if (e.target.matches('.form-input, .editable-title')) { UI.formDirty = true; saveCarrierDebounced(); } });

  $('#comment-input').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('[data-action="post-comment"]').click(); } });
  $('#carrier-comment-input').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('[data-action="post-carrier-comment"]').click(); } });
  const bindLogEnter = el => el.addEventListener('keydown', e => { if (e.target.matches('.inline-editor textarea') && e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $(`[data-action="save-comment"][data-cid="${e.target.dataset.inp}"]`).click(); } });
  bindLogEnter($('#chatter-log'));
  bindLogEnter($('#carrier-chatter-log'));

  const onPickFiles = e => {
    const allow = new Set(['png','jpg','jpeg','gif','webp','bmp','pdf','txt','csv','doc','docx','xls','xlsx','ppt','pptx','zip','7z']);
    [...e.target.files].forEach(f => {
      if (f.size > 5 * 1024 * 1024) return Toast.error(`Файл "${f.name}" > 5МБ`);
      const ext = (f.name.split('.').pop() || '').toLowerCase();
      if (!allow.has(ext) || /\.(php|phtml|phar|cgi|exe|js|htm|html|svg|shtml)(\.|$)/i.test(f.name)) {
        return Toast.error(`Файл "${f.name}" не разрешён`);
      }
      UI.pendingFiles.push({ name: f.name, size: f.size, type: f.type, rawFile: f });
    });
    e.target.value = ''; renderFiles();
  };
  $('#file-input').addEventListener('change', onPickFiles);
  $('#carrier-file-input').addEventListener('change', onPickFiles);

  document.body.addEventListener('click', async e => {
    const actEl = e.target.closest('[data-action]'); if (!actEl) return;
    const act = actEl.dataset.action;

    if (act === 'prompt-cancel') {
      const r = _promptResolver; _promptResolver = null;
      Modal.closeAll(); if (r) r(null);
      return;
    }
    if (act === 'confirm-cancel') {
      const r = _confirmResolver; _confirmResolver = null;
      Modal.closeAll(); if (r) r(false);
      return;
    }
    if (act === 'close-modals') { Modal.closeAll(); return; }
    if (act === 'close-lightbox') { closeImageLightbox(); return; }
    if (act === 'open-image') {
      e.preventDefault();
      openImageLightbox(actEl.dataset.src || actEl.getAttribute('href') || '');
      return;
    }

    await withLock(async () => {
    switch (act) {
      case 'open-search-lead':
        if (actEl.dataset.id) { closeSearchDrop(); await openLead(actEl.dataset.id, true); }
        break;
      case 'view-user-board':
        await viewUserBoard(actEl.dataset.id, actEl.dataset.name);
        break;
      case 'exit-view-user':
        await exitViewUser();
        break;
      case 'prev-lead': goNeighborLead(-1); break;
      case 'next-lead': goNeighborLead(1); break;
      case 'prev-carrier': goNeighborCarrier(-1); break;
      case 'next-carrier': goNeighborCarrier(1); break;
      case 'go-home': goHome(true); break;
      case 'go-routes': switchView('routes-view', true); break;
      case 'go-users': if (Store.state.user?.role === 'admin') switchView('users-view', true); break;
      case 'open-route': if (actEl.dataset.id) openRoute(actEl.dataset.id, true); break;

      case 'new-direction':
        $('#d-from').value = ''; $('#d-to').value = '';
        Modal.open('modal-direction'); setTimeout(() => $('#d-from').focus(), 50);
        break;
      case 'submit-direction': {
        const from = $('#d-from').value.trim(), to = $('#d-to').value.trim();
        if (!from || !to) return Toast.error('Укажите оба города');
        const resD = await Net.req('save_direction', { cityFrom: from, cityTo: to });
        if (resD?.success) { Modal.closeAll(); await loadRoutes(); openRoute(resD.id, true); }
        else Toast.error(resD?.error || 'Ошибка');
        break;
      }
      case 'delete-direction':
        if (!UI.routeId) return;
        if (!await askConfirm('Удалить направление?', 'Все перевозчики на нём тоже удалятся')) return;
        const resDD = await Net.req('delete_direction', { id: UI.routeId });
        if (resDD?.success) { UI.routeId = null; switchView('routes-view', true); }
        else Toast.error(resDD?.error || 'Ошибка');
        break;

      case 'new-carrier':
        if (!UI.routeId) return;
        $('#k-id').value = ''; $('#k-name').value = ''; $('#k-phone').value = ''; $('#k-company').value = '';
        Modal.open('modal-carrier'); setTimeout(() => $('#k-name').focus(), 50);
        break;
      case 'submit-carrier': {
        const name = $('#k-name').value.trim();
        if (!name) return Toast.error('Укажите имя или название');
        const payload = { directionId: UI.routeId, name, phone: $('#k-phone').value.trim(), company: $('#k-company').value.trim() };
        const resK = await Net.req('save_carrier', payload);
        if (resK?.success) { Modal.closeAll(); await openCarrier(resK.id, true); }
        else Toast.error(resK?.error || 'Ошибка');
        break;
      }
      case 'open-carrier':
        if (actEl.dataset.id) openCarrier(actEl.dataset.id, true);
        break;
      case 'back-carrier':
        if (UI.carrierId && UI.formDirty) {
          const savedB = await saveCarrierForm(true);
          if (!persistOk(savedB)) return;
        }
        if (UI.routeId) openRoute(UI.routeId, true); else switchView('routes-view', true);
        break;
      case 'delete-carrier': {
        const delId = actEl.dataset.id || UI.carrierId;
        if (!delId) return;
        if (!await askConfirm('Удалить перевозчика?', 'Лог тоже удалится')) return;
        const resDK = await Net.req('delete_carrier', { id: delId });
        if (resDK?.success) {
          UI.carrierId = null;
          if (UI.routeId) await openRoute(UI.routeId, true); else switchView('routes-view', true);
        } else Toast.error(resDK?.error || 'Ошибка');
        break;
      }
      case 'post-carrier-comment': {
        const txt = $('#carrier-comment-input').value.trim(); if (!txt && !UI.pendingFiles.length) return;
        if (!UI.carrierId) return;
        const fd = new FormData(); fd.append('carrier_id', UI.carrierId); fd.append('text', txt);
        UI.pendingFiles.forEach(f => fd.append('files[]', f.rawFile));
        const resPCC = await Net.req('add_carrier_comment', fd, true);
        if (resPCC?.success) { $('#carrier-comment-input').value = ''; UI.pendingFiles = []; await openCarrier(UI.carrierId, false); }
        else Toast.error(resPCC?.error || 'Ошибка');
        break;
      }
      case 'toggle-theme': Theme.toggle(); break;
      case 'submit-password': {
        const np = $('#pw-new')?.value || '', n2 = $('#pw-new2')?.value || '';
        if (np.length < 8) return Toast.error('Пароль мин. 8 символов');
        if (np !== n2) return Toast.error('Пароли не совпадают');
        const resPw = await Net.req('change_password', { password: np });
        if (resPw?.success) {
          Modal.closeAll();
          if ($('#pw-new')) $('#pw-new').value = '';
          if ($('#pw-new2')) $('#pw-new2').value = '';
          Toast.success('Пароль обновлён');
          await Store.load(true);
          handleHashRouting();
          startPolling();
        } else Toast.error(resPw?.error || 'Ошибка');
        break;
      }
      case 'logout': execLogout(); break;
      case 'new-lead': $$('#modal-create input').forEach(i => i.value = ''); Modal.open('modal-create'); setTimeout(() => $('#m-title').focus(), 50); break;
      case 'open-add-user': $$('#modal-add-user input').forEach(i => { if (i.type === 'checkbox') i.checked = false; else i.value = ''; }); Modal.open('modal-add-user'); setTimeout(() => $('#u-name').focus(), 50); break;

      case 'submit-lead':
        const t = $('#m-title').value.trim(), i = $('#m-inn').value.trim(), em = $('#m-email').value.trim();
        if (!t) return Toast.error('Введите название');
        if (i && i.length !== 10 && i.length !== 12) return Toast.error('ИНН 10 или 12 цифр');
        if (!isValidEmail(em)) return Toast.error('Некорректный email');
        const resL = await Net.req('save_lead', { id: 'l_'+uid(), title: t, inn: i, phone: $('#m-phone').value.trim(), email: em, manager: Store.state.user.name, stage: Store.state.stages[0], applicationsCount: 0 });
        if (resL?.success) { Modal.closeAll(); await Store.load(true); } else Toast.error(resL?.error || 'Ошибка');
        break;

      case 'submit-user':
        const n = $('#u-name').value.trim(), ue = $('#u-email').value.trim(), p = $('#u-pass').value;
        if (!n || !ue || !p) return Toast.error('Все поля');
        if (isReservedUserName(n)) return Toast.error('Это имя зарезервировано');
        if (!isValidEmail(ue)) return Toast.error('Некорректный email');
        if (p.length < 8) return Toast.error('Пароль мин. 8 символов');
        const resU = await Net.req('register_user', { name: n, email: ue, password: p, role: $('#u-admin')?.checked ? 'admin' : 'user' });
        if (resU?.success) { Toast.success('Добавлен'); Modal.closeAll(); loadUsers(); } else Toast.error(resU?.error || 'Ошибка');
        break;

      case 'save-user': {
        const id = actEl.dataset.id, un = $(`#uname-${id}`).value.trim(), uem = $(`#uemail-${id}`).value.trim(), up = $(`#upass-${id}`).value, ur = $(`#urole-${id}`)?.value || 'user';
        if (!un || !uem) return Toast.error('Обязательны Имя и Email');
        if (isReservedUserName(un)) return Toast.error('Это имя зарезервировано');
        if (!isValidEmail(uem)) return Toast.error('Некорректный email');
        if (up && up.length < 8) return Toast.error('Пароль мин. 8 символов');
        const resS = await Net.req('update_user', { id: +id, name: un, email: uem, password: up, role: ur });
        if (resS?.success) { Toast.success('Сохранено'); loadUsers(); } else Toast.error(resS?.error || 'Ошибка');
        break;
      }

      case 'delete-user':
        if (await askConfirm('Удалить сотрудника?')) { const resD = await Net.req('delete_user', { id: +actEl.dataset.id }); if (resD?.success) { Toast.success('Удален'); loadUsers(); Store.load(true); } }
        break;

      case 'add-stage':
        const stN = await askPrompt('Новый этап', '', 'Название этапа'); if (!stN || !stN.trim()) return;
        const resSt = await Net.req('save_stages', { stages: [...Store.state.stages, stN.trim()] });
        if (resSt?.success) await Store.load(true); else Toast.error(resSt?.error || 'Ошибка');
        break;

      case 'edit-stage':
        const oldSt = actEl.closest('.column').dataset.stage, newSt = await askPrompt('Изменить этап', oldSt, 'Пустое = удалить');
        if (newSt === null || newSt.trim() === oldSt) return;
        let ns = [...Store.state.stages];
        if (!newSt.trim()) {
          if (ns.length <= 1) return Toast.error('Нельзя удалить последний');
          const left = ns.filter(s => s !== oldSt);
          const nLeads = Store.state.leads.filter(l => l.stage === oldSt).length;
          const dest = left[0] || '';
          const msg = nLeads
            ? `${nLeads} лид(ов) будут перенесены в «${dest}». Отменить это будет нельзя.`
            : 'Этап пустой.';
          if (!await askConfirm('Удалить этап «' + oldSt + '»?', msg)) return;
          ns = left;
        } else {
          if (ns.includes(newSt.trim())) return Toast.error('Имя занято');
          ns[ns.indexOf(oldSt)] = newSt.trim();
        }
        const resESt = await Net.req('save_stages', { stages: ns });
        if (resESt?.success) await Store.load(true); else Toast.error(resESt?.error || 'Ошибка');
        break;

      case 'delete-lead':
        if (!await askConfirm('Удалить лид?', 'Навсегда')) return;
        const resDL = await Net.req('delete_lead', { id: UI.leadId });
        if (resDL?.success) { goHome(true); await Store.load(true); } else Toast.error(resDL?.error || 'Ошибка');
        break;

      case 'set-stage':
        const lead = Store.getLead(UI.leadId); if (!lead) return;
        if (UI.formDirty) await saveLeadForm(true);
        const resMS = await Net.req('move_lead', { id: UI.leadId, stage: actEl.dataset.stage, from: lead.stage, updatedAt: lead._editRev ?? lead.updatedAt });
        if (resMS?.success) await Store.load(true);
        else if (resMS?.error === 'Карточка изменена в другом месте') { Toast.error('Карточку изменили в другой вкладке — обновляю'); await Store.load(true); }
        else Toast.error(resMS?.error || 'Ошибка');
        break;

      case 'post-comment':
        const txt = $('#comment-input').value.trim(); if (!txt && !UI.pendingFiles.length) return;
        const fd = new FormData(); fd.append('lead_id', UI.leadId); fd.append('text', txt);
        UI.pendingFiles.forEach(f => fd.append('files[]', f.rawFile));
        const resPC = await Net.req('add_comment', fd, true);
        if (resPC?.success) {
          const L = Store.getLead(UI.leadId);
          if (L && resPC.updatedAt) { L.updatedAt = resPC.updatedAt; L._editRev = resPC.updatedAt; }
          $('#comment-input').value = ''; UI.pendingFiles = []; renderFiles(); await Store.load(true); await loadLeadComments(UI.leadId); renderLog();
        } else Toast.error(resPC?.error || 'Ошибка');
        break;

      case 'rm-file': UI.pendingFiles.splice(+actEl.dataset.idx, 1); renderFiles(); break;

      case 'toggle-edit':
        const cid = actEl.dataset.cid;
        const edt = $(`[data-edt="${cid}"]`), txtEl = $(`[data-txt="${cid}"]`), inp = $(`[data-inp="${cid}"]`);
        if (!edt) return; const active = edt.classList.contains('active');
        $$('.inline-editor.active').forEach(e => e.classList.remove('active')); $$('.log-text.hidden').forEach(e => e.classList.remove('hidden'));
        if (!active) { txtEl.classList.add('hidden'); edt.classList.add('active'); UI.editingCommentId = cid; inp.focus(); inp.setSelectionRange(inp.value.length, inp.value.length); }
        else { UI.editingCommentId = null; }
        break;

      case 'save-comment': {
        const v = $(`[data-inp="${actEl.dataset.cid}"]`).value.trim(); if (!v) return Toast.error('Пусто');
        const isCarrier = UI.currentView === 'carrier';
        const resSC = await Net.req(isCarrier ? 'edit_carrier_comment' : 'edit_comment', { id: actEl.dataset.cid, text: v });
        if (resSC?.success) {
          UI.editingCommentId = null;
          if (isCarrier) {
            if (resSC.updatedAt) UI.carrierRev = resSC.updatedAt;
            await openCarrier(UI.carrierId, false);
          } else {
            const L = Store.getLead(UI.leadId);
            if (L && resSC.updatedAt) { L.updatedAt = resSC.updatedAt; L._editRev = resSC.updatedAt; }
            await Store.load(true);
          }
        } else Toast.error(resSC?.error || 'Ошибка');
        break;
      }

      case 'del-comment': {
        if (!await askConfirm('Удалить комментарий?')) return;
        const isCarrier = UI.currentView === 'carrier';
        const delId = String(actEl.dataset.cid || '');
        const resDC = await Net.req(isCarrier ? 'delete_carrier_comment' : 'delete_comment', { id: delId });
        if (resDC?.success) {
          UI.editingCommentId = null;
          if (isCarrier) {
            if (resDC.updatedAt) UI.carrierRev = resDC.updatedAt;
            UI.carrierComments = (UI.carrierComments || []).filter(c => String(c.id) !== delId);
            renderCarrierLog();
            await openCarrier(UI.carrierId, false);
          } else {
            const L = Store.getLead(UI.leadId);
            if (L) {
              if (resDC.updatedAt) { L.updatedAt = resDC.updatedAt; L._editRev = resDC.updatedAt; }
              if (Array.isArray(L.comments)) L.comments = L.comments.filter(c => String(c.id) !== delId);
            }
            renderLog();
            await Store.load(true);
            if (UI.leadId) { await loadLeadComments(UI.leadId); renderLog(); }
          }
        } else Toast.error(resDC?.error || 'Ошибка');
        break;
      }
    }
    })();
  });

  $('#board').addEventListener('click', e => {
    if (e.target.closest('.col-edit') || e.target.closest('.add-column')) return;
    if (Date.now() < (UI.dragSuppressUntil || 0)) return;
    const card = e.target.closest('.card'); if (card) openLead(card.dataset.id, true);
  });

  document.addEventListener('keydown', e => {
    if ((UI.currentView === 'lead' || UI.currentView === 'carrier') && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
      const tag = (document.activeElement && document.activeElement.tagName) || '';
      if (!/^(INPUT|TEXTAREA|SELECT)$/.test(tag) && !e.altKey && !e.ctrlKey && !e.metaKey) {
        e.preventDefault();
        if (UI.currentView === 'carrier') goNeighborCarrier(e.key === 'ArrowLeft' ? -1 : 1);
        else goNeighborLead(e.key === 'ArrowLeft' ? -1 : 1);
        return;
      }
    }
    if (e.key === 'Escape') {
      if ($('#img-lightbox.open')) { closeImageLightbox(); return; }
      if ($('.inline-editor.active')) { $$('.inline-editor.active').forEach(el=>el.classList.remove('active')); $$('.log-text.hidden').forEach(el=>el.classList.remove('hidden')); UI.editingCommentId = null; }
      else if ($('.modal-backdrop.open')) {
        if ($('#modal-password.open')) return;
        const pr = _promptResolver; _promptResolver = null;
        const cr = _confirmResolver; _confirmResolver = null;
        if (pr) pr(null); if (cr) cr(false); Modal.closeAll();
      }
    }
  });

  const b = $('#board');
  b.addEventListener('dragstart', e => {
    const card = e.target.closest('.card'), col = e.target.closest('.column');
    if (card) { UI.drag = { t: 'card', id: card.dataset.id }; card.classList.add('dragging'); e.dataTransfer.setData('text/plain', card.dataset.id); e.stopPropagation(); }
    else if (col) { UI.drag = { t: 'col', s: col.dataset.stage }; col.classList.add('dragging'); e.dataTransfer.setData('text/plain', col.dataset.stage); }
  });
  b.addEventListener('dragend', () => {
    $$('.dragging, .drop-target').forEach(el => el.classList.remove('dragging', 'drop-target'));
    UI.dragSuppressUntil = Date.now() + 400;
    UI.drag = {};
  });
  b.addEventListener('dragover', e => { if (UI.drag.t && e.target.closest('.column')) e.preventDefault(); });
  b.addEventListener('dragenter', e => { const c = e.target.closest('.column'); if (c && UI.drag.t) c.classList.add('drop-target'); });
  b.addEventListener('dragleave', e => { const c = e.target.closest('.column'); if (c && !c.contains(e.relatedTarget)) c.classList.remove('drop-target'); });
  b.addEventListener('drop', withLock(async e => {
    const c = e.target.closest('.column'); if (!c) return; e.preventDefault(); const tgt = c.dataset.stage;
    if (UI.drag.t === 'card') {
      const lead = Store.getLead(UI.drag.id); if (!lead || lead.stage === tgt) return;
      const res = await Net.req('move_lead', { id: UI.drag.id, stage: tgt, from: lead.stage, updatedAt: lead.updatedAt });
      if (res?.success) Store.load(true);
      else { Toast.error(res?.error || 'Не удалось переместить'); Store.load(true); }
    } else if (UI.drag.t === 'col') {
      const ns = [...Store.state.stages], f = ns.indexOf(UI.drag.s), t = ns.indexOf(tgt);
      if (f >= 0 && t >= 0 && f !== t) { ns.splice(t, 0, ns.splice(f, 1)[0]); const res = await Net.req('save_stages', { stages: ns }); if (res?.success) Store.load(true); }
    }
  }));
}

let pollTimer = null, pollDelay = 15000, unchangedStreak = 0;
function startPolling() {
  stopPolling();
  const tick = async () => {
    if (!document.hidden && !UI.drag.t && !UI.formDirty && !$('.modal-backdrop.open') && !$('.inline-editor.active')) {
      const before = Net.hash;
      await Store.load(false);
      if (Net.hash && Net.hash === before) {
        unchangedStreak++;
        pollDelay = unchangedStreak >= 3 ? 30000 : 15000;
      } else {
        unchangedStreak = 0;
        pollDelay = 15000;
      }
    }
    pollTimer = setTimeout(tick, pollDelay);
  };
  pollTimer = setTimeout(tick, pollDelay);
}
function stopPolling() { if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; } }
document.addEventListener('visibilitychange', () => { if (!document.hidden && Store.state.user) Store.load(false); });

async function loadLeadComments(id) {
  const lead = Store.getLead(id);
  if (!lead) return;
  const res = await Net.req('get_comments', { id });
  if (res && res.success) lead.comments = res.comments || [];
}

async function loadAppShell() {
  if (UI.shellReady) {
    document.body.classList.remove('guest');
    return true;
  }
  try {
    const opts = { method: 'GET', headers: {}, keepalive: true };
    if (Net.csrf) opts.headers['X-CSRF-Token'] = Net.csrf;
    const res = await fetch('api.php?action=ui', opts);
    const ctype = (res.headers.get('content-type') || '');
    if (!res.ok || ctype.includes('application/json')) {
      handleLogoutUI();
      return false;
    }
    const html = await res.text();
    const root = $('#app-root');
    if (!root) return false;
    root.innerHTML = html;
    UI.shellReady = true;
    document.body.classList.remove('guest');
    $('#login-overlay').classList.remove('show');
    initAppEvents();
    return true;
  } catch (e) {
    Net.setOnline(false);
    return false;
  }
}

function revealApp() {
  document.body.classList.remove('booting', 'guest');
  $('#login-overlay')?.classList.remove('show');
}

function revealLogin() {
  document.body.classList.remove('booting');
  document.body.classList.add('guest');
  $('#login-overlay')?.classList.add('show');
}

function usersTableBusy() {
  const tbody = $('#users-tbody'); if (!tbody) return false;
  if (tbody.contains(document.activeElement)) return true;
  return [...tbody.querySelectorAll('input, select')].some(i => {
    if (i.type === 'password') return !!i.value;
    return i.value !== i.defaultValue;
  });
}

function showMustChangePassword() {
  const box = $('#modal-password');
  if (!box) return;
  Modal.open('modal-password');
  setTimeout(() => $('#pw-new')?.focus(), 50);
}

async function afterLogin(mustChange) {
  if (!await loadAppShell()) { revealLogin(); return; }
  revealApp();
  if (mustChange) {
    showMustChangePassword();
    return;
  }
  await Store.load(true);
  handleHashRouting();
  startPolling();
}

async function bootApp() {
  const check = await Net.req('check_auth');
  if (check?.success) {
    Net.csrf = check.csrf;
    await afterLogin(!!check.mustChangePassword);
  } else {
    const tok = await Net.req('csrf');
    if (tok && tok.csrf) Net.csrf = tok.csrf;
    revealLogin();
    setTimeout(() => $('#login-email')?.focus(), 50);
  }
}

async function execLogin() {
  const email = $('#login-email').value.trim(), password = $('#login-password').value;
  $('#login-err').textContent = '';
  if (!email || !password) { $('#login-err').textContent = 'Заполните поля'; return; }
  $('#btn-login').disabled = true;
  let res = await Net.req('login', { email, password });
  if (res?.error === 'CSRF') {
    const tok = await Net.req('csrf');
    if (tok && tok.csrf) Net.csrf = tok.csrf;
    res = await Net.req('login', { email, password });
  }
  $('#btn-login').disabled = false;
  if (res && res.success) {
    Net.csrf = res.csrf;
    $('#login-password').value = '';
    await afterLogin(!!res.mustChangePassword);
  } else {
    $('#login-err').textContent = res?.error || 'Ошибка входа';
  }
}

Theme.apply(Theme.get());
initEvents();
bootApp();
