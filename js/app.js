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
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const escAttr = s => encodeURI(String(s ?? '')).replace(/"/g, '%22');
const debounce = (fn, ms) => { let t; const d = (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; d.cancel = () => clearTimeout(t); return d; };
const fmtTime = ts => new Date(ts).toLocaleString('ru-RU', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
const fmtBytes = b => { if (!b) return '0 B'; const k=1024, s=['B','KB','MB']; const i=Math.min(2, Math.floor(Math.log(b)/Math.log(k))); return `${(b/Math.pow(k,i)).toFixed(1)} ${s[i]}`; };
const isValidEmail = v => !v || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
// Те же правила, что на сервере (crm_pass_ok): 8–64 символа, считаем символы, а не байты.
function passwordError(p) {
  const n = [...String(p || '')].length;
  if (n < 8) return 'Пароль мин. 8 символов';
  if (n > 64) return 'Пароль не длиннее 64 символов';
  return null;
}
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
function renderAttHtml(a, c) {
  const raw = String(a.dataUrl || '');
  const u = escAttr(raw), n = esc(a.name);
  const id = a.id != null ? String(a.id) : '';
  const del = (id && c && canEditComment(c))
    ? `<button type="button" class="att-del" data-action="del-att" data-id="${esc(id)}" title="Удалить вложение">×</button>`
    : '';
  if (isImageAtt(a) && raw) {
    return `<div class="att-image"><a href="${u}" target="_blank" rel="noopener" data-action="open-image" data-src="${u}"><img src="${u}" alt="${n}"></a>${del}</div>`;
  }
  return `<span class="att-file-wrap"><a class="att-file" href="${u}" target="_blank" rel="noopener">📄 ${n} <span class="att-size">${fmtBytes(a.size)}</span></a>${del}</span>`;
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
function applyPhoneMask(input, e) {
  if (!input) return;
  const raw = input.value.trim();
  if (raw.startsWith('+') && !raw.startsWith('+7') && !raw.startsWith('+8')) return;
  let d = raw.replace(/\D/g, '');
  if (!d) { input.value = ''; input.dataset.phoneLast = ''; return; }
  if (e && e.inputType === 'deleteContentBackward' && d === (input.dataset.phoneLast || '') && d.length > 0) d = d.slice(0, -1);
  if (!d || d === '7' || d === '8') { input.value = ''; input.dataset.phoneLast = ''; return; }
  if (d.startsWith('8')) d = '7' + d.slice(1);
  else if (d.startsWith('9')) d = '7' + d;
  else if (!d.startsWith('7')) return;
  d = d.slice(0, 11);
  input.dataset.phoneLast = d;
  let f = '+7';
  if (d.length > 1) f += ' (' + d.slice(1, 4);
  if (d.length >= 5) f += ') ' + d.slice(4, 7);
  if (d.length >= 8) f += '-' + d.slice(7, 9);
  if (d.length >= 10) f += '-' + d.slice(9, 11);
  input.value = f;
}
function setupPhoneMask(input) {
  if (!input) return;
  if (!input.dataset.maskInit) {
    input.dataset.maskInit = '1';
    input.addEventListener('input', e => applyPhoneMask(input, e));
  }
  applyPhoneMask(input, null);
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
  close(id) { const el = $('#'+id); if (el) el.classList.remove('open'); },
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
    if (res.unchanged) {
      if (!force) return;
      // Принудительная загрузка при совпавшем хэше (например, повторный вход в той же вкладке
      // после истечения сессии): у сервера нет данных в ответе, а состояние уже сброшено —
      // запрашиваем без хэша, иначе доска останется пустой.
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
    if (UI.currentView === 'route' && UI.routeId) openRoute(UI.routeId, false);
    if (UI.currentView === 'carrier' && UI.carrierId && !UI.pendingFiles.length) openCarrier(UI.carrierId, false);
  },
  getLead(id) {
    if (!id) return null;
    return this.state.leads.find(l => String(l.id).trim() === String(id).trim());
  }
};

const UI = { leadId: null, routeId: null, carrierId: null, carrierRev: null, carrierComments: [], pendingFiles: [], editFiles: [], drag: {}, currentView: 'kanban', formDirty: false, editingCommentId: null, lock: false, shellReady: false, appEvents: false };

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
// Зеркало серверных can_edit_comment / can_delete_comment (решает сервер; здесь — только показ кнопок)
function canEditComment(c) {
  const user = Store.state.user;
  if (!user) return false;
  if (isSystemComment(c)) return false;
  if (user.role === 'admin') return true;
  const uid = Number(c.userId || 0);
  return uid > 0 && uid === Number(user.id);
}
function canDeleteComment(c) {
  const user = Store.state.user;
  if (!user) return false;
  if (isSystemComment(c)) return user.role === 'admin';
  return canEditComment(c);
}
function logActionsHtml(c) {
  const bits = [];
  if (canEditComment(c)) bits.push(`<span class="log-btn" data-action="toggle-edit" data-cid="${esc(c.id)}">✎ Изменить</span>`);
  if (canDeleteComment(c)) bits.push(`<span class="log-btn del" data-action="del-comment" data-cid="${esc(c.id)}">🗑️</span>`);
  return bits.length ? `<div class="log-actions">${bits.join('')}</div>` : '';
}

function closeSearchDrop() { $('#search-drop')?.classList.remove('open'); }

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
  navTo(location.hash || '#kanban', true);
  if (UI.currentView !== 'kanban') await goHome(true);
  else renderBoard();
  await Store.load(true);
}

async function exitViewUser() {
  const had = !!Store.viewUserId;
  Store.viewUserId = null; Store.viewUserName = '';
  Net.hash = null; _lastBoardHash = null;
  updateViewBanner();
  navTo(location.hash || '#kanban', true);
  if (UI.currentView !== 'kanban') await goHome(true);
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
  Net.hash = null; _lastBoardHash = null;
  clearSearch();
  history.replaceState(null, '', location.pathname);
  $$('.view-section').forEach(el => el.classList.remove('active'));
  const kv = $('#kanban-view'); if (kv) kv.classList.add('active');
  document.body.classList.remove('booting');
  document.body.classList.add('guest');
  $('#login-overlay').classList.add('show');
  if (msg && msg !== 'Сессия истекла') Toast.error(msg);
  // Токен для входа не запрашиваем заранее: он одноразовый и живёт 15 минут,
  // поэтому его берёт сам execLogin() непосредственно перед отправкой формы.
  Net.csrf = null;
}

function withLock(fn) { return async (...args) => { if (UI.lock) return; UI.lock = true; try { await fn(...args); } finally { UI.lock = false; } }; }

function readAsParam() {
  return parseInt(new URLSearchParams(location.search).get('as') || '0', 10) || 0;
}
function appUrl(hash) {
  hash = hash || '';
  if (hash && hash[0] !== '#') hash = '#' + hash;
  const as = Store.viewUserId;
  const q = as ? ('?as=' + encodeURIComponent(as)) : '';
  return location.pathname + q + hash;
}
function navTo(hash, push = true) {
  const url = appUrl(hash);
  const now = location.pathname + location.search + location.hash;
  if (now === url) return;
  if (push) history.pushState(null, '', url);
  else history.replaceState(null, '', url);
}
async function syncViewUserFromUrl() {
  if (!Store.state.user || Store.state.user.role !== 'admin') {
    if (Store.viewUserId) {
      Store.viewUserId = null;
      Store.viewUserName = '';
      updateViewBanner();
    }
    return false;
  }
  const as = readAsParam();
  const cur = Store.viewUserId ? +Store.viewUserId : 0;
  if (as === cur) {
    if (as) {
      const col = (Store.state.colleagues || []).find(u => +u.id === as);
      if (col) Store.viewUserName = col.name;
      updateViewBanner();
    }
    return false;
  }
  if (as && as !== +Store.state.user.id) {
    Store.viewUserId = as;
    const col = (Store.state.colleagues || []).find(u => +u.id === as);
    Store.viewUserName = col?.name || 'Сотрудник';
  } else {
    Store.viewUserId = null;
    Store.viewUserName = '';
  }
  Net.hash = null;
  _lastBoardHash = null;
  updateViewBanner();
  await Store.load(true);
  return true;
}

/* === НАВИГАЦИЯ (РОУТЕР) === */
// decodeURIComponent бросает URIError на битом хэше (#lead/%E0) — тогда просто идём на доску.
function hashParam(hash, prefix) {
  try { return decodeURIComponent(hash.slice(prefix.length)); } catch (e) { return ''; }
}
function handleHashRouting() {
  const hash = window.location.hash;
  if (hash.startsWith('#lead/')) {
    const targetLeadId = hashParam(hash, '#lead/');
    if (targetLeadId && Store.getLead(targetLeadId)) { openLead(targetLeadId, false); return; }
  } else if (hash.startsWith('#carrier/')) {
    const cid = hashParam(hash, '#carrier/');
    if (cid) { openCarrier(cid, false); return; }
  } else if (hash.startsWith('#route/')) {
    const rid = hashParam(hash, '#route/');
    if (rid) { openRoute(rid, false); return; }
  } else if (hash === '#routes') {
    switchView('routes-view', false); return;
  } else if (hash === '#users' && Store.state.user?.role === 'admin') {
    switchView('users-view', false); return;
  }
  switchView('kanban-view', false);
}

// При нажатии кнопок Назад/Вперед в браузере
window.addEventListener('popstate', async () => {
  if (!Store.state.user) return;
  await syncViewUserFromUrl();
  handleHashRouting();
});

async function switchView(viewId, updateHash = true) {
  if (UI.leadId && UI.formDirty) {
    const saved = await saveLeadForm(true);
    if (!persistOk(saved)) return;
    if (saved?.transferred) await Store.load(true);
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
    if (updateHash) navTo('#kanban');
    renderBoard();
  } else if (viewId === 'users-view') {
    $('#nav-users')?.classList.add('active');
    if (updateHash) navTo('#users');
    loadUsers();
  } else if (viewId === 'routes-view') {
    $('#nav-routes')?.classList.add('active');
    if (updateHash) navTo('#routes');
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
function appsWord(n) {
  n = Math.abs(n) % 100; const n1 = n % 10;
  if (n > 10 && n < 20) return 'заявок';
  if (n1 === 1) return 'заявка';
  if (n1 >= 2 && n1 <= 4) return 'заявки';
  return 'заявок';
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
  // Удалять направление может создатель или админ — остальным кнопку не показываем (сервер проверяет сам)
  $('[data-action="delete-direction"]')?.classList.toggle('hidden', !d.canManage);
  if (updateHash) navTo('#route/' + encodeURIComponent(id));
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
      <td>${c.canManage ? `<button class="btn btn-danger btn-sm" data-action="delete-carrier" data-id="${esc(c.id)}">🗑️</button>` : ''}</td>`;
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
  $('#carrier-view [data-action="delete-carrier"]')?.classList.toggle('hidden', !c.canManage);
  $('#carrier-crumb').textContent = c.name || '';
  $('#carrier-dir-crumb').textContent = d ? `${d.cityFrom} → ${d.cityTo}` : 'Направление';
  setupPhoneMask($('#cf-phone'));
  if (updateHash) navTo('#carrier/' + encodeURIComponent(id));
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
// Сохранения выстраиваются в цепочку (_carrierSaveChain), поэтому вызов всегда возвращает промис
// своего результата; первый аргумент оставлен для совместимости вызовов и ни на что не влияет.
async function saveCarrierForm(_sync = false, keepalive = false) {
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
  return job;
}
const saveCarrierDebounced = debounce(() => saveCarrierForm(false), 500);

// Один рендер лога для лида и перевозчика (раньше две одинаковые копии расходились при правках).
function renderLogInto(log, comments) {
  if (!log) return;
  if (!comments || !comments.length) { log.innerHTML = '<div class="log-empty">Лог пуст</div>'; return; }
  log.innerHTML = '';
  const frag = document.createDocumentFragment();
  [...comments].reverse().forEach(c => {
    const isSys = isSystemComment(c);
    const init = isSys ? '⚙' : authorInitial(c);
    const atts = (c.attachments || []).map(a => renderAttHtml(a, c)).join('');
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
          <div class="files-preview edit-files-preview"></div>
          <div class="inline-edit-btns"><label class="file-label">📎 Прикрепить<input type="file" class="edit-file-input" multiple hidden accept=".png,.jpg,.jpeg,.gif,.webp,.bmp,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.7z"></label><button class="btn btn-secondary btn-sm" data-action="toggle-edit" data-cid="${esc(c.id)}">Отмена</button><button class="btn btn-primary btn-sm" data-action="save-comment" data-cid="${esc(c.id)}">Сохранить</button></div>
        </div>
        ${atts ? `<div class="attachments">${atts}</div>` : ''}
      </div>`;
    frag.appendChild(el);
  });
  log.appendChild(frag);
  if (UI.editingCommentId) {
    const edt = $(`[data-edt="${UI.editingCommentId}"]`), txt = $(`[data-txt="${UI.editingCommentId}"]`);
    if (edt && txt) { edt.classList.add('active'); txt.classList.add('hidden'); renderFiles(); }
  }
}
function renderCarrierLog() { renderLogInto($('#carrier-chatter-log'), UI.carrierComments || []); }
function renderLog() { const lead = Store.getLead(UI.leadId); renderLogInto($('#chatter-log'), (lead && lead.comments) || []); }

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
  renderLeadApps();

  if (updateHash) navTo('#lead/' + encodeURIComponent(id));

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

let _usersCache = [];
async function loadUsers() {
  const res = await Net.req('get_users'); if (!res || !res.success) return;
  _usersCache = res.users || [];
  const tbody = $('#users-tbody'); tbody.innerHTML = '';
  const currId = Store.state.user.id;
  _usersCache.forEach(u => {
    const tr = document.createElement('tr');
    const role = u.role === 'admin' ? 'admin' : 'user';
    tr.innerHTML = `
      <td>${u.id}</td>
      <td><input class="user-input" id="uname-${u.id}" value="${esc(u.name)}"></td>
      <td><input class="user-input" id="uemail-${u.id}" value="${esc(u.email)}"></td>
      <td><select class="user-input" id="urole-${u.id}"><option value="user"${role==='user'?' selected':''}>Сотрудник</option><option value="admin"${role==='admin'?' selected':''}>Админ</option></select></td>
      <td class="td-leads">${Number(u.leads) || 0}</td>
      <td><input class="user-input" id="upass-${u.id}" type="password" autocomplete="new-password" placeholder="Пусто = не менять"></td>
      <td>
        <button class="btn btn-primary btn-sm" data-action="save-user" data-id="${u.id}">💾</button>
        ${u.id !== currId ? `<button class="btn btn-danger btn-sm" data-action="delete-user" data-id="${u.id}">🗑️</button>` : ''}
      </td>`;
    // Отмечаем ручной ввод пароля: событие input от автозаполнения браузера isTrusted, но без
    // нажатий клавиш; проверяем keydown/paste в самом поле.
    const passEl = tr.querySelector(`#upass-${u.id}`);
    if (passEl) {
      const mark = () => { passEl.dataset.typed = '1'; };
      passEl.addEventListener('keydown', mark);
      passEl.addEventListener('paste', mark);
      passEl.addEventListener('input', () => { if (!passEl.value) delete passEl.dataset.typed; });
    }
    tbody.appendChild(tr);
  });
}

function plural(n, one, few, many) {
  const m10 = n % 10, m100 = n % 100;
  if (m10 === 1 && m100 !== 11) return one;
  if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return few;
  return many;
}

// Модалка удаления сотрудника: показывает, сколько у него лидов, и предлагает передать их
// другому сотруднику (по умолчанию) либо удалить безвозвратно вместе с логами и файлами.
let _deleteUserId = null;
function openDeleteUser(id) {
  const u = _usersCache.find(x => x.id === id);
  if (!u) return;
  _deleteUserId = id;
  const n = Number(u.leads) || 0;
  const sel = $('#du-transfer');
  const others = _usersCache.filter(x => x.id !== id);
  const me = Store.state.user.id;
  sel.innerHTML = others.map(o => `<option value="${o.id}"${o.id === me ? ' selected' : ''}>Передать: ${esc(o.name)}${o.id === me ? ' (мне)' : ''}</option>`).join('')
    + '<option value="0">Удалить безвозвратно (лиды, лог, файлы, заявки)</option>';
  $('#du-message').textContent = n
    ? `${u.name}: ${n} ${plural(n, 'лид', 'лида', 'лидов')} на доске.`
    : `${u.name}: лидов на доске нет.`;
  $('#du-transfer-field').classList.toggle('hidden', n === 0);
  const hint = $('#du-hint');
  const upd = () => {
    const del = sel.value === '0';
    hint.textContent = del ? 'Отменить будет нельзя.' : 'Этап сохранится, если он есть у получателя; в лог каждого лида добавится запись о передаче.';
    hint.classList.toggle('danger', del);
  };
  sel.onchange = upd; upd();
  Modal.open('modal-delete-user');
}

async function confirmDeleteUser() {
  const id = _deleteUserId; if (!id) return;
  const u = _usersCache.find(x => x.id === id);
  const n = Number(u?.leads) || 0;
  const transferTo = n ? Number($('#du-transfer').value) || 0 : 0;
  const res = await Net.req('delete_user', { id, transferTo });
  if (!res?.success) { Toast.error(res?.error || 'Ошибка'); return; }
  Modal.closeAll(); _deleteUserId = null;
  const moved = Number(res.transferred) || 0;
  Toast.success(moved ? `Удалён, передано ${moved} ${plural(moved, 'лид', 'лида', 'лидов')}` : 'Удалён');
  loadUsers(); Store.load(true);
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
  ['inn','phone','email','manager'].forEach(f => {
    const el = $(`#f-${f}`); if (el && document.activeElement !== el) el.value = lead[f] || '';
  });
  const ln = $('#f-logist-name'); if (ln && document.activeElement !== ln) ln.value = lead.logistName || '';
  const lp = $('#f-logist-phone'); if (lp && document.activeElement !== lp) lp.value = lead.logistPhone || '';
  $('#crumb-name').textContent = lead.title; setupPhoneMask($('#f-phone')); setupPhoneMask($('#f-logist-phone'));
  renderLeadApps();
}

function leadAppsOf(lead) {
  return Array.isArray(lead?.applications) ? lead.applications : [];
}

function updateAppsCount(n) {
  const el = $('#f-apps');
  if (el) el.textContent = String(n || 0);
}

function moneyNum(s) {
  const t = String(s ?? '').replace(/\s/g, '').replace(',', '.');
  if (!t) return 0;
  const n = Number(t);
  return Number.isFinite(n) ? n : 0;
}
function fmtMoney(s) {
  const raw = String(s ?? '').replace(/\s/g, '');
  if (!raw) return '';
  const n = moneyNum(raw);
  const parts = Math.round(n * 100);
  const neg = parts < 0;
  const abs = Math.abs(parts);
  const int = Math.floor(abs / 100);
  const frac = abs % 100;
  let out = String(int).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  if (frac) out += ',' + String(frac).padStart(2, '0');
  return (neg ? '-' : '') + out;
}
function moneyToInput(s) {
  const t = String(s ?? '').trim().replace(/\s/g, '');
  if (!t) return '';
  return t.replace('.', ',');
}
function formatMarginInput(inp) {
  if (!inp) return;
  let v = inp.value.replace(/[^\d.,]/g, '').replace(/\./g, ',');
  const i = v.indexOf(',');
  if (i >= 0) {
    const a = v.slice(0, i).replace(/,/g, '').slice(0, 12);
    const b = v.slice(i + 1).replace(/,/g, '').slice(0, 2);
    v = a + ',' + b;
  } else {
    v = v.slice(0, 12);
  }
  inp.value = v;
}
function applyAppsStats(lead, stats) {
  if (lead && stats) lead.appsStats = stats;
}
function renderAppsStats() {
  const lead = Store.getLead(UI.leadId);
  const apps = leadAppsOf(lead);
  const localCount = Array.isArray(lead?.applications) ? apps.length : Number(lead?.applicationsCount || 0);
  const localMargin = apps.reduce((s, a) => s + moneyNum(a.margin), 0);
  const st = lead?.appsStats || {};
  const count = Number(st.clientCount != null ? st.clientCount : (st.count != null ? st.count : localCount));
  const margin = Number(st.clientMargin != null ? st.clientMargin : (st.margin != null ? st.margin : localMargin));
  const countEl = $('#apps-stat-count');
  const marginEl = $('#apps-stat-margin');
  if (countEl) countEl.textContent = String(count || 0);
  if (marginEl) marginEl.textContent = (fmtMoney(margin) || '0') + ' ₽';
  const note = $('#lead-apps-stats-note');
  if (note) {
    const extra = count > localCount || margin > localMargin;
    note.hidden = !extra;
  }
}

function renderLeadApps() {
  const box = $('#lead-apps-list');
  const lead = Store.getLead(UI.leadId);
  const apps = leadAppsOf(lead);
  const n = apps.length || Number(lead?.applicationsCount || 0);
  updateAppsCount(Array.isArray(lead?.applications) ? apps.length : n);
  renderAppsStats();
  if (!box) return;
  if (!apps.length) {
    box.innerHTML = '<div class="lead-apps-empty">Пока нет заявок</div>';
    return;
  }
  box.innerHTML = apps.map(a => {
    const route = [a.cityFrom, a.cityTo].filter(Boolean).join(' → ') || 'Без маршрута';
    const rate = fmtMoney(a.rate);
    const vat = Number(a.vat) ? 'с НДС' : 'без НДС';
    const rateLine = rate ? `${esc(rate)} ₽ · ${vat}` : vat;
    const mar = fmtMoney(a.margin);
    const marLine = mar ? `маржа ${esc(mar)} ₽` : '';
    const who = [a.carrierCompany, a.carrierName].filter(Boolean).join(' · ');
    const inn = a.carrierInn ? 'ИНН ' + a.carrierInn : '';
    const phone = a.carrierPhone || '';
    const meta = [who, inn, phone].filter(Boolean).join(' · ');
    return `<div class="lead-app-card">
      <div class="lead-app-main">
        <div class="lead-app-route">${esc(route)}</div>
        <div class="lead-app-rate">${rateLine}${marLine ? ' · ' + marLine : ''}</div>
        ${meta ? `<div class="lead-app-meta">${esc(meta)}</div>` : ''}
      </div>
      <div class="lead-app-actions">
        <button type="button" class="btn btn-secondary btn-sm" data-action="edit-lead-app" data-id="${esc(a.id)}">Изменить</button>
        <button type="button" class="btn btn-danger btn-sm" data-action="delete-lead-app" data-id="${esc(a.id)}">🗑️</button>
      </div>
    </div>`;
  }).join('');
}

function leadAppSnapshot() {
  return JSON.stringify({
    id: $('#la-id')?.value || '',
    from: $('#la-from')?.value || '',
    to: $('#la-to')?.value || '',
    rate: $('#la-rate')?.value || '',
    margin: $('#la-margin')?.value || '',
    vat: $('input[name="la-vat"]:checked')?.value || '0',
    company: $('#la-company')?.value || '',
    inn: $('#la-inn')?.value || '',
    name: $('#la-name')?.value || '',
    phone: $('#la-phone')?.value || ''
  });
}
let _leadAppSnap = '';
function openLeadAppModal(app) {
  $('#la-id').value = app?.id || '';
  $('#lead-app-modal-title').textContent = app?.id ? 'Заявка' : 'Новая заявка';
  $('#la-from').value = app?.cityFrom || '';
  $('#la-to').value = app?.cityTo || '';
  // Ставка и маржа — одинаковый денежный формат (копейки через запятую); раньше ставка «1234.50»
  // из БД показывалась как 123450
  $('#la-rate').value = moneyToInput(app?.rate || '');
  if ($('#la-margin')) $('#la-margin').value = moneyToInput(app?.margin || '');
  const vat = Number(app?.vat) ? '1' : '0';
  $$('input[name="la-vat"]').forEach(r => { r.checked = r.value === vat; });
  $('#la-company').value = app?.carrierCompany || '';
  $('#la-inn').value = app?.carrierInn || '';
  $('#la-name').value = app?.carrierName || '';
  $('#la-phone').value = app?.carrierPhone || '';
  setupPhoneMask($('#la-phone'));
  Modal.open('modal-lead-app');
  _leadAppSnap = leadAppSnapshot();
  setTimeout(() => $('#la-from')?.focus(), 50);
}
async function closeLeadAppModal(force = false) {
  const box = $('#modal-lead-app');
  if (!box || !box.classList.contains('open')) return true;
  if (!force && leadAppSnapshot() !== _leadAppSnap) {
    if (!await askConfirm('Закрыть заявку?', 'Изменения не сохранятся')) return false;
  }
  box.classList.remove('open');
  return true;
}

async function saveLeadAppFromModal() {
  if (!UI.leadId) return;
  saveLeadDebounced.cancel();
  if (UI.formDirty) {
    const saved = await saveLeadForm(true);
    if (!persistOk(saved)) return;
  }
  const from = ($('#la-from').value || '').trim();
  const to = ($('#la-to').value || '').trim();
  if (!from || !to) return Toast.error('Укажите откуда и куда');
  const inn = ($('#la-inn').value || '').replace(/\D/g, '');
  if (inn && inn.length !== 10 && inn.length !== 12) return Toast.error('ИНН 10 или 12 цифр');
  const vatEl = $('input[name="la-vat"]:checked');
  const payload = {
    id: ($('#la-id').value || '').trim(),
    leadId: UI.leadId,
    cityFrom: from,
    cityTo: to,
    rate: ($('#la-rate').value || '').trim(),
    margin: ($('#la-margin')?.value || '').trim(),
    vat: vatEl && vatEl.value === '1' ? 1 : 0,
    carrierCompany: ($('#la-company').value || '').trim(),
    carrierInn: inn,
    carrierName: ($('#la-name').value || '').trim(),
    carrierPhone: ($('#la-phone').value || '').trim()
  };
  const res = await Net.req('save_lead_app', payload);
  if (!res?.success) return Toast.error(res?.error || 'Ошибка');
  _leadAppSnap = leadAppSnapshot();
  Modal.close('modal-lead-app');
  const lead = Store.getLead(UI.leadId);
  if (lead) {
    if (res.application) {
      const apps = leadAppsOf(lead).slice();
      const i = apps.findIndex(a => String(a.id) === String(res.application.id));
      if (i >= 0) apps[i] = res.application; else apps.push(res.application);
      lead.applications = apps;
    }
    if (res.applicationsCount != null) lead.applicationsCount = res.applicationsCount;
    applyAppsStats(lead, res.appsStats);
    if (res.updatedAt) { lead.updatedAt = res.updatedAt; lead._editRev = res.updatedAt; }
  }
  renderLeadApps();
}

async function deleteLeadApp(id) {
  if (!id || !UI.leadId) return;
  if (!await askConfirm('Удалить заявку?')) return;
  saveLeadDebounced.cancel();
  if (UI.formDirty) {
    const saved = await saveLeadForm(true);
    if (!persistOk(saved)) return;
  }
  const res = await Net.req('delete_lead_app', { id, leadId: UI.leadId });
  if (!res?.success) return Toast.error(res?.error || 'Ошибка');
  const lead = Store.getLead(UI.leadId);
  if (lead) {
    lead.applications = leadAppsOf(lead).filter(a => String(a.id) !== String(id));
    if (res.applicationsCount != null) lead.applicationsCount = res.applicationsCount;
    applyAppsStats(lead, res.appsStats);
    if (res.updatedAt) { lead.updatedAt = res.updatedAt; lead._editRev = res.updatedAt; }
  }
  renderLeadApps();
}

let _leadSaveChain = Promise.resolve();
// См. saveCarrierForm: цепочка _leadSaveChain, первый аргумент ни на что не влияет.
async function saveLeadForm(_sync = false, keepalive = false, transferTo = 0) {
  if (!UI.leadId) return null; const lead = Store.getLead(UI.leadId); if (!lead || !lead._full) return null;
  const run = async () => {
    const cur = Store.getLead(UI.leadId); if (!cur || !cur._full) return null;
    const innDigits = ($('#f-inn').value || '').replace(/\D/g, '');
    const patch = { id: UI.leadId, title: $('#f-title').value.trim() || 'Без названия', inn: innDigits, phone: $('#f-phone').value.trim(), email: $('#f-email').value.trim(), manager: $('#f-manager').value.trim(), logistName: ($('#f-logist-name')?.value || '').trim(), logistPhone: ($('#f-logist-phone')?.value || '').trim(), stage: cur.stage, updatedAt: cur._editRev ?? cur.updatedAt };
    if (innDigits && innDigits.length !== 10 && innDigits.length !== 12) {
      Toast.error('ИНН 10 или 12 цифр');
      return { success: false, error: 'ИНН 10 или 12 цифр' };
    }
    if (!isValidEmail(patch.email)) {
      Toast.error('Некорректный email');
      return { success: false, error: 'Некорректный email' };
    }
    $('#crumb-name').textContent = patch.title;
    if (transferTo) patch.transferTo = transferTo;
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
  const dataHash = JSON.stringify([Store.state.stages, Store.state.leads.map(l => [l.id, l.stage, l.title, l.phone, l.manager, l.inn, l.applicationsCount])]);
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
      const nApps = Number(l.applicationsCount || 0);
      const appsLine = nApps ? `<div class="card-apps">${nApps} ${appsWord(nApps)}</div>` : '';
      card.innerHTML = `<div class="card-title">${esc(l.title)}</div><div class="card-meta">${innLine}<span>📱 ${esc(l.phone || 'Нет')}</span></div>${appsLine}`;
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

function editingCommentAttCount() {
  if (!UI.editingCommentId) return 0;
  let c = null;
  if (UI.currentView === 'carrier') c = (UI.carrierComments || []).find(x => String(x.id) === String(UI.editingCommentId));
  else {
    const L = Store.getLead(UI.leadId);
    c = L && Array.isArray(L.comments) ? L.comments.find(x => String(x.id) === String(UI.editingCommentId)) : null;
  }
  return (c && c.attachments) ? c.attachments.length : 0;
}
function renderFiles() {
  const editBox = $('.inline-editor.active .edit-files-preview');
  const box = editBox || (UI.currentView === 'carrier' ? $('#carrier-files-preview') : $('#files-preview'));
  const list = editBox ? (UI.editFiles || []) : UI.pendingFiles;
  if (!box) return; box.innerHTML = '';
  list.forEach((f, i) => {
    const el = document.createElement('div'); el.className = 'file-chip';
    el.innerHTML = `<span>📎 ${esc(f.name)}</span> <span class="chip-remove" data-action="rm-file" data-idx="${i}">×</span>`;
    box.appendChild(el);
  });
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
  $('#detail-view').addEventListener('input', e => { if (e.target.matches('.form-input, .editable-title')) { UI.formDirty = true; saveLeadDebounced(); } });
  $('#la-inn')?.addEventListener('input', e => formatInnInput(e.target));
  $('#la-rate')?.addEventListener('input', e => formatMarginInput(e.target));
  $('#la-margin')?.addEventListener('input', e => formatMarginInput(e.target));
  $('#f-manager').addEventListener('blur', async () => {
    if (!UI.leadId) return;
    saveLeadDebounced.cancel();
    const lead = Store.getLead(UI.leadId);
    const typed = ($('#f-manager').value || '').trim();
    const prev = (lead && lead.manager) || '';
    // Передача — только при полном совпадении с именем сотрудника из подсказки (без регистра).
    // Одна фамилия больше не переводит лид: «Иванов» могло уйти не тому Иванову.
    let transferTo = 0;
    if (typed && typed !== prev && Store.state.colleagues) {
      const q = typed.toLowerCase().replace(/\s+/g, ' ');
      const hits = Store.state.colleagues.filter(u => String(u.name || '').toLowerCase().replace(/\s+/g, ' ') === q);
      const ownerId = Store.viewUserId || (Store.state.user && Store.state.user.id);
      if (hits.length === 1 && +hits[0].id !== +ownerId) {
        if (!await askConfirm('Передать лид?', 'Лид уйдёт сотруднику «' + hits[0].name + '» вместе с логом и файлами')) {
          $('#f-manager').value = prev;
          return;
        }
        transferTo = +hits[0].id;
      }
    }
    const res = await saveLeadForm(true, false, transferTo);
    if (res && res.transferred) { await Store.load(true); await goHome(true); }
  });
  $('#carrier-view').addEventListener('input', e => { if (e.target.matches('.form-input, .editable-title')) { UI.formDirty = true; saveCarrierDebounced(); } });

  $('#comment-input').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('[data-action="post-comment"]').click(); } });
  $('#carrier-comment-input').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('[data-action="post-carrier-comment"]').click(); } });
  const bindLogEnter = el => el.addEventListener('keydown', e => { if (e.target.matches('.inline-editor textarea') && e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $(`[data-action="save-comment"][data-cid="${e.target.dataset.inp}"]`).click(); } });
  bindLogEnter($('#chatter-log'));
  bindLogEnter($('#carrier-chatter-log'));

  const allowExt = new Set(['png','jpg','jpeg','gif','webp','bmp','pdf','txt','csv','doc','docx','xls','xlsx','ppt','pptx','zip','7z']);
  const mimeExt = { 'image/png':'png', 'image/jpeg':'jpg', 'image/jpg':'jpg', 'image/gif':'gif', 'image/webp':'webp', 'image/bmp':'bmp' };
  function fileExtOf(f) {
    const fromName = ((f.name || '').split('.').pop() || '').toLowerCase();
    if (fromName && fromName !== (f.name || '').toLowerCase()) return fromName;
    return mimeExt[(f.type || '').toLowerCase()] || fromName;
  }
  function addPendingFiles(list) {
    const editing = !!UI.editingCommentId;
    const bucket = editing ? (UI.editFiles || (UI.editFiles = [])) : UI.pendingFiles;
    const used = (editing ? editingCommentAttCount() : 0) + bucket.length;
    if (used >= 8) return Toast.error('Максимум 8 файлов');
    [...list].forEach(f => {
      if (!f) return;
      if ((editing ? editingCommentAttCount() : 0) + bucket.length >= 8) return;
      if (f.size > 5 * 1024 * 1024) return Toast.error(`Файл "${f.name || 'скриншот'}" > 5МБ`);
      let ext = fileExtOf(f);
      let name = f.name || '';
      if (!name || name === 'image.png' || name === 'image.jpg') {
        ext = ext || 'png';
        name = 'screenshot-' + new Date().toISOString().slice(0,19).replace(/[:T]/g, '-') + '.' + ext;
      }
      if (!allowExt.has(ext) || /\.(php|phtml|phar|cgi|exe|js|htm|html|svg|shtml)(\.|$)/i.test(name)) {
        return Toast.error(`Файл "${name}" не разрешён`);
      }
      const file = (name !== f.name) ? new File([f], name, { type: f.type || ('image/' + (ext === 'jpg' ? 'jpeg' : ext)) }) : f;
      bucket.push({ name: file.name, size: file.size, type: file.type, rawFile: file });
    });
    renderFiles();
  }
  const onPickFiles = e => {
    addPendingFiles(e.target.files || []);
    e.target.value = '';
  };
  $('#file-input').addEventListener('change', onPickFiles);
  $('#carrier-file-input').addEventListener('change', onPickFiles);
  document.addEventListener('change', e => {
    if (e.target && e.target.classList && e.target.classList.contains('edit-file-input')) onPickFiles(e);
  });
  document.addEventListener('paste', e => {
    if (UI.currentView !== 'lead' && UI.currentView !== 'carrier') return;
    if ($('.modal-backdrop.open')) return;
    const tag = ((e.target && e.target.tagName) || '').toUpperCase();
    if (tag === 'INPUT') return;
    const dt = e.clipboardData;
    if (!dt) return;
    const files = [];
    if (dt.files && dt.files.length) files.push(...dt.files);
    else if (dt.items) {
      [...dt.items].forEach(it => {
        if (it.kind === 'file') {
          const f = it.getAsFile();
          if (f) files.push(f);
        }
      });
    }
    const imgs = files.filter(f => /^image\//i.test(f.type || '') || /\.(png|jpe?g|gif|webp|bmp)$/i.test(f.name || ''));
    if (!imgs.length) return;
    e.preventDefault();
    addPendingFiles(imgs);
  });

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
      Modal.close('modal-confirm'); if (r) r(false);
      return;
    }
    if (act === 'close-modals') {
      if ($('#modal-lead-app.open')) { await closeLeadAppModal(); return; }
      Modal.closeAll();
      return;
    }
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
        const npErr = passwordError(np); if (npErr) return Toast.error(npErr);
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
      case 'new-lead-app':
        if (!UI.leadId) return;
        openLeadAppModal(null);
        break;
      case 'edit-lead-app': {
        const app = leadAppsOf(Store.getLead(UI.leadId)).find(a => String(a.id) === String(actEl.dataset.id || ''));
        if (!app) return;
        openLeadAppModal(app);
        break;
      }
      case 'delete-lead-app':
        await deleteLeadApp(actEl.dataset.id);
        break;
      case 'submit-lead-app':
        await saveLeadAppFromModal();
        break;
      case 'new-lead': $$('#modal-create input').forEach(i => i.value = ''); Modal.open('modal-create'); setTimeout(() => $('#m-title').focus(), 50); break;
      case 'open-add-user': $$('#modal-add-user input').forEach(i => { if (i.type === 'checkbox') i.checked = false; else i.value = ''; }); Modal.open('modal-add-user'); setTimeout(() => $('#u-name').focus(), 50); break;

      case 'submit-lead':
        const t = $('#m-title').value.trim(), i = $('#m-inn').value.trim(), em = $('#m-email').value.trim();
        if (!t) return Toast.error('Введите название');
        if (i && i.length !== 10 && i.length !== 12) return Toast.error('ИНН 10 или 12 цифр');
        if (!isValidEmail(em)) return Toast.error('Некорректный email');
        // id новому лиду выдаёт сервер
        const resL = await Net.req('save_lead', { title: t, inn: i, phone: $('#m-phone').value.trim(), email: em, stage: Store.state.stages[0] });
        if (resL?.success) { Modal.closeAll(); await Store.load(true); } else Toast.error(resL?.error || 'Ошибка');
        break;

      case 'submit-user':
        const n = $('#u-name').value.trim(), ue = $('#u-email').value.trim(), p = $('#u-pass').value;
        if (!n || !ue || !p) return Toast.error('Все поля');
        if (isReservedUserName(n)) return Toast.error('Это имя зарезервировано');
        if (!isValidEmail(ue)) return Toast.error('Некорректный email');
        const pErr = passwordError(p); if (pErr) return Toast.error(pErr);
        const resU = await Net.req('register_user', { name: n, email: ue, password: p, role: $('#u-admin')?.checked ? 'admin' : 'user' });
        if (resU?.success) { Toast.success('Добавлен'); Modal.closeAll(); loadUsers(); } else Toast.error(resU?.error || 'Ошибка');
        break;

      case 'save-user': {
        const id = actEl.dataset.id, un = $(`#uname-${id}`).value.trim(), uem = $(`#uemail-${id}`).value.trim(), up = $(`#upass-${id}`).value, ur = $(`#urole-${id}`)?.value || 'user';
        if (!un || !uem) return Toast.error('Обязательны Имя и Email');
        if (isReservedUserName(un)) return Toast.error('Это имя зарезервировано');
        if (!isValidEmail(uem)) return Toast.error('Некорректный email');
        if (up) { const upErr = passwordError(up); if (upErr) return Toast.error(upErr); }
        // Пароль отправляем только если поле трогали руками: автозаполнение браузера могло
        // подставить пароль админа в чужую строку, а «💾» тихо сменил бы сотруднику пароль.
        const passEl = $(`#upass-${id}`);
        const payloadU = { id: +id, name: un, email: uem, role: ur };
        if (up && passEl?.dataset.typed === '1') payloadU.password = up;
        const resS = await Net.req('update_user', payloadU);
        if (resS?.success) { Toast.success('Сохранено'); loadUsers(); } else Toast.error(resS?.error || 'Ошибка');
        break;
      }

      case 'delete-user':
        openDeleteUser(+actEl.dataset.id);
        break;

      case 'confirm-delete-user':
        await confirmDeleteUser();
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

      case 'rm-file': (UI.editingCommentId ? UI.editFiles : UI.pendingFiles).splice(+actEl.dataset.idx, 1); renderFiles(); break;

      case 'toggle-edit':
        const cid = actEl.dataset.cid;
        const edt = $(`[data-edt="${cid}"]`), txtEl = $(`[data-txt="${cid}"]`), inp = $(`[data-inp="${cid}"]`);
        if (!edt) return; const active = edt.classList.contains('active');
        $$('.inline-editor.active').forEach(e => e.classList.remove('active')); $$('.log-text.hidden').forEach(e => e.classList.remove('hidden'));
        UI.editFiles = [];
        if (!active) { txtEl.classList.add('hidden'); edt.classList.add('active'); UI.editingCommentId = cid; inp.focus(); inp.setSelectionRange(inp.value.length, inp.value.length); renderFiles(); }
        else { UI.editingCommentId = null; renderFiles(); }
        break;

      case 'save-comment': {
        const v = $(`[data-inp="${actEl.dataset.cid}"]`).value.trim();
        const extra = UI.editFiles || [];
        if (!v && !extra.length && !editingCommentAttCount()) return Toast.error('Пусто');
        const isCarrier = UI.currentView === 'carrier';
        const fd = new FormData();
        fd.append('id', actEl.dataset.cid);
        fd.append('text', v);
        extra.forEach(f => fd.append('files[]', f.rawFile));
        const resSC = await Net.req(isCarrier ? 'edit_carrier_comment' : 'edit_comment', fd, true);
        if (resSC?.success) {
          UI.editingCommentId = null;
          UI.editFiles = [];
          if (isCarrier) {
            if (resSC.updatedAt) UI.carrierRev = resSC.updatedAt;
            await openCarrier(UI.carrierId, false);
          } else {
            const L = Store.getLead(UI.leadId);
            if (L && resSC.updatedAt) { L.updatedAt = resSC.updatedAt; L._editRev = resSC.updatedAt; }
            await Store.load(true);
            if (UI.leadId) { await loadLeadComments(UI.leadId); renderLog(); }
          }
        } else Toast.error(resSC?.error || 'Ошибка');
        break;
      }

      case 'del-att': {
        if (!await askConfirm('Удалить вложение?')) return;
        const attId = String(actEl.dataset.id || '');
        if (!attId) return;
        // kind обязателен: номера вложений лидов и перевозчиков независимы и могут совпадать
        const resDA = await Net.req('delete_attachment', { id: +attId, kind: UI.currentView === 'carrier' ? 'carrier' : 'lead' });
        if (!resDA?.success) { Toast.error(resDA?.error || 'Ошибка'); break; }
        const dropAtt = list => (list || []).map(c => Object.assign({}, c, {
          attachments: (c.attachments || []).filter(a => String(a.id) !== attId)
        }));
        if (UI.currentView === 'carrier') {
          if (resDA.updatedAt) UI.carrierRev = resDA.updatedAt;
          UI.carrierComments = dropAtt(UI.carrierComments);
          renderCarrierLog();
        } else {
          const L = Store.getLead(UI.leadId);
          if (L) {
            if (resDA.updatedAt) { L.updatedAt = resDA.updatedAt; L._editRev = resDA.updatedAt; }
            if (Array.isArray(L.comments)) L.comments = dropAtt(L.comments);
          }
          renderLog();
        }
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
    if (e.key === 'Enter' && $('#modal-lead-app.open') && !$('#modal-confirm.open') && !$('#modal-prompt.open') && !$('#modal-password.open')) {
      const tag = (document.activeElement && document.activeElement.tagName) || '';
      if (tag !== 'TEXTAREA' && document.activeElement && document.activeElement.closest('#modal-lead-app')) {
        e.preventDefault();
        saveLeadAppFromModal();
        return;
      }
    }
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
      if ($('.inline-editor.active')) { $$('.inline-editor.active').forEach(el=>el.classList.remove('active')); $$('.log-text.hidden').forEach(el=>el.classList.remove('hidden')); UI.editingCommentId = null; UI.editFiles = []; renderFiles(); }
      else if ($('.modal-backdrop.open')) {
        if ($('#modal-password.open')) return;
        if ($('#modal-confirm.open')) {
          const cr = _confirmResolver; _confirmResolver = null;
          Modal.close('modal-confirm'); if (cr) cr(false); return;
        }
        if ($('#modal-lead-app.open')) { closeLeadAppModal(); return; }
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

let pollTimer = null, pollDelay = 15000, unchangedStreak = 0, authBeat = null;
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
  authBeat = setInterval(async () => {
    if (document.hidden || !Store.state.user) return;
    await Net.req('check_auth');
  }, 4 * 60 * 1000);
}
function stopPolling() {
  if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
  if (authBeat) { clearInterval(authBeat); authBeat = null; }
}
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

async function afterLogin(mustChange, user) {
  if (!await loadAppShell()) { revealLogin(); return; }
  revealApp();
  if (mustChange) {
    showMustChangePassword();
    return;
  }
  const as = readAsParam();
  const role = user?.role || Store.state.user?.role;
  const uid = +(user?.id || Store.state.user?.id || 0);
  if (role === 'admin' && as && as !== uid) Store.viewUserId = as;
  await Store.load(true);
  await syncViewUserFromUrl();
  handleHashRouting();
  startPolling();
}

async function bootApp() {
  const check = await Net.req('check_auth');
  if (check?.success) {
    Net.csrf = check.csrf;
    await afterLogin(!!check.mustChangePassword, check.user);
  } else {
    // Ответ с need_login уже показал форму входа через handleLogoutUI; токен для входа
    // берём лениво в execLogin() — один запрос csrf на одну попытку, а не два на загрузку.
    revealLogin();
    setTimeout(() => $('#login-email')?.focus(), 50);
  }
}

async function fetchLoginCsrf() {
  const tok = await Net.req('csrf');
  if (tok && tok.csrf) { Net.csrf = tok.csrf; return null; }
  return (tok && tok.error) || 'Сервер недоступен, попробуйте ещё раз';
}

async function execLogin() {
  const email = $('#login-email').value.trim(), password = $('#login-password').value;
  $('#login-err').textContent = '';
  if (!email || !password) { $('#login-err').textContent = 'Заполните поля'; return; }
  $('#btn-login').disabled = true;
  // Одноразовый login-токен: свежий на каждую попытку входа.
  const tokErr = await fetchLoginCsrf();
  let res = tokErr ? { success: false, error: tokErr } : await Net.req('login', { email, password });
  if (res?.error === 'CSRF' && !(await fetchLoginCsrf())) {
    res = await Net.req('login', { email, password });
  }
  $('#btn-login').disabled = false;
  if (res && res.success) {
    Net.csrf = res.csrf;
    $('#login-password').value = '';
    await afterLogin(!!res.mustChangePassword, res.user);
  } else {
    $('#login-err').textContent = res?.error || 'Ошибка входа';
  }
}

Theme.apply(Theme.get());
initEvents();
bootApp();
