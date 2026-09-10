'use strict';
/* global $, $$, Modal, Net, Store, Theme, Toast, _confirmResolver:writable, _promptResolver:writable, activityShiftYear, askConfirm, askPrompt, checkLeadDupDebounced, clearSearch, closeImageLightbox, closeLeadAppModal, closeSearchDrop, confirmDeleteUser, debounce, deleteLeadApp, editingCommentAttCount, formatInnInput, formatMarginInput, goNeighborCarrier, goNeighborLead, initDashboardSearch, isValidEmail, leadAppsOf, liveSearch, loadActivity, loadLeadComments, loadRoutes, loadUsers, openCarrier, openDeleteUser, openImageLightbox, openLead, openLeadAppModal, openRoute, passwordError, persistOk, renderBoard, renderCarrierLog, renderFiles, renderLog, resetBoardCache, saveCarrierDebounced, saveCarrierForm, saveLeadAppFromModal, saveLeadDebounced, saveLeadForm, setActivityUser, updateLeadSaveUI, setRoutesFilter, setupPhoneMask, withLock */
/* exported syncAdminNav, updateSearchPlaceholder */

const UI = { leadId: null, routeId: null, carrierId: null, carrierRev: null, carrierCanManage: false, carrierComments: [], pendingFiles: [], editFiles: [], drag: {}, currentView: 'kanban', formDirty: false, editingCommentId: null, lock: false, shellReady: false, appEvents: false };

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
  // Плитка «Сотрудники» на дашборде — тоже только для админа
  $('#tile-users')?.classList.toggle('hidden', !admin);
}

function isReservedUserName(name) {
  const n = String(name || '').trim().toLowerCase();
  return n === 'система' || n === 'system';
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
  Net.hash = null; Store.since = 0; resetBoardCache();
  updateViewBanner();
  navTo(location.hash || '#kanban', true);
  if (UI.currentView !== 'kanban') await goHome(true);
  else renderBoard();
  await Store.load(true);
}

async function exitViewUser() {
  const had = !!Store.viewUserId;
  Store.viewUserId = null; Store.viewUserName = '';
  Net.hash = null; Store.since = 0; resetBoardCache();
  updateViewBanner();
  navTo(location.hash || '#kanban', true);
  if (UI.currentView !== 'kanban') await goHome(true);
  if (had) await Store.load(true); else renderBoard();
}

function handleLogoutUI(msg) {
  stopPolling();
  UI.leadId = null; UI.routeId = null; UI.carrierId = null; UI.carrierComments = []; UI.editingCommentId = null; UI.formDirty = false;
  Store.viewUserId = null; Store.viewUserName = '';
  Store.state.user = null; Store.state.leads = []; Store.state.stages = [];
  Net.hash = null; Store.since = 0; resetBoardCache();
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
  Net.hash = null; Store.since = 0;
  resetBoardCache();
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
  } else if (hash === '#dashboard') {
    switchView('dashboard-view', false); return;
  } else if (hash === '#routes') {
    switchView('routes-view', false); return;
  } else if (hash === '#activity') {
    switchView('activity-view', false); return;
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
  // Кнопка дашборда — не .nav-item: её подсветку снимаем отдельно, иначе она «залипала» активной
  $('#nav-dashboard')?.classList.remove('active');
  const viewEl = $('#'+viewId); if (viewEl) viewEl.classList.add('active');

  if (viewId === 'dashboard-view') {
    $('#nav-dashboard')?.classList.add('active');
    if (updateHash) navTo('#dashboard');
  } else if (viewId === 'kanban-view') {
    $('#nav-leads')?.classList.add('active');
    if (updateHash) navTo('#kanban');
    updateViewBanner();
    renderBoard();
  } else if (viewId === 'users-view') {
    $('#nav-users')?.classList.add('active');
    if (updateHash) navTo('#users');
    loadUsers();
  } else if (viewId === 'routes-view') {
    $('#nav-routes')?.classList.add('active');
    if (updateHash) navTo('#routes');
    loadRoutes();
  } else if (viewId === 'activity-view') {
    $('#nav-activity')?.classList.add('active');
    if (updateHash) navTo('#activity');
    loadActivity();
  }
}

function goHome(updateHash = true) { return switchView('kanban-view', updateHash); }

async function execLogout() {
  if (UI.formDirty && !await askConfirm('Есть несохранённые изменения', 'Выйти без сохранения?')) return;
  UI.formDirty = false;
  await Net.req('logout', {}); location.reload();
}

function initEvents() {
  $('#btn-login').addEventListener('click', execLogin);
  $('#login-password').addEventListener('keydown', e => { if (e.key === 'Enter') execLogin(); });
  $('#login-email').addEventListener('keydown', e => { if (e.key === 'Enter') $('#login-password').focus(); });
}

function initAppEvents() {
  if (UI.appEvents) return;
  UI.appEvents = true;
  setupPhoneMask($('#m-phone')); setupPhoneMask($('#f-logist-phone'));
  $('#m-inn').addEventListener('input', e => { formatInnInput(e.target); checkLeadDupDebounced(); });
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
  const runRoutesSearch = debounce(() => { setRoutesFilter(routesSearch.value.trim()); loadRoutes(); }, 200);
  routesSearch.addEventListener('input', runRoutesSearch);
  setupPhoneMask($('#k-phone'));
  document.addEventListener('click', e => {
    if (!e.target.closest('#board-search-wrap') && !e.target.closest('#search-drop')) closeSearchDrop();
  });

  $('#btn-prev-lead').addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); goNeighborLead(-1); });
  $('#btn-next-lead').addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); goNeighborLead(1); });
  $('#btn-prev-carrier').addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); goNeighborCarrier(-1); });
  $('#btn-next-carrier').addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); goNeighborCarrier(1); });
  $('#activity-prev-year')?.addEventListener('click', () => activityShiftYear(-1));
  $('#activity-next-year')?.addEventListener('click', () => activityShiftYear(1));
  $('#activity-employee')?.addEventListener('change', async (e) => {
    setActivityUser(parseInt(e.target.value, 10));
    loadActivity();
  });

  window.addEventListener('beforeunload', e => {
    if (!UI.formDirty) return;
    if (UI.carrierId) saveCarrierForm(false, true); else saveLeadForm(false, true);
    e.preventDefault();
    e.returnValue = '';
  });
  $('#detail-view').addEventListener('input', e => { if (e.target.matches('.form-input, .editable-title')) { UI.formDirty = true; updateLeadSaveUI('dirty'); saveLeadDebounced(); } });
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
      case 'go-dashboard': switchView('dashboard-view', true); break;
      case 'go-kanban': switchView('kanban-view', true); break;
      case 'go-routes': switchView('routes-view', true); break;
      case 'go-users': if (Store.state.user?.role === 'admin') switchView('users-view', true); break;
      case 'go-activity': switchView('activity-view', true); break;
      case 'open-activity-client': {
        const inn = actEl.dataset.inn;
        if (!inn) break;
        await switchView('kanban-view', true);
        const searchInp = $('#board-search');
        if (searchInp) { searchInp.value = inn; liveSearch(inn); }
        break;
      }
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
      case 'delete-direction': {
        if (!UI.routeId) return;
        if (!await askConfirm('Удалить направление?', 'Все перевозчики на нём тоже удалятся')) return;
        const resDD = await Net.req('delete_direction', { id: UI.routeId });
        if (resDD?.success) { UI.routeId = null; switchView('routes-view', true); }
        else Toast.error(resDD?.error || 'Ошибка');
        break;
      }

      case 'new-carrier':
        if (!UI.routeId) return;
        $('#k-name').value = ''; $('#k-phone').value = ''; $('#k-company').value = '';
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
      case 'new-lead': {
        $$('#modal-create input').forEach(i => i.value = '');
        const dupWarn = $('#m-inn-warn'); if (dupWarn) { dupWarn.classList.add('hidden'); dupWarn.textContent = ''; }
        Modal.open('modal-create'); setTimeout(() => $('#m-title').focus(), 50); break;
      }
      case 'open-add-user': $$('#modal-add-user input').forEach(i => { if (i.type === 'checkbox') i.checked = false; else i.value = ''; }); Modal.open('modal-add-user'); setTimeout(() => $('#u-name').focus(), 50); break;

      case 'submit-lead': {
        const t = $('#m-title').value.trim(), i = $('#m-inn').value.trim(), em = $('#m-email').value.trim();
        if (!t) return Toast.error('Введите название');
        if (i && i.length !== 10 && i.length !== 12) return Toast.error('ИНН 10 или 12 цифр');
        if (!isValidEmail(em)) return Toast.error('Некорректный email');
        // id новому лиду выдаёт сервер
        // Телефон из окна создания сразу попадает и в «Контакт логиста» (logistPhone):
        // карточка лида и доска показывают именно его. В phone тоже сохраняем — как раньше.
        const mPhone = $('#m-phone').value.trim();
        const resL = await Net.req('save_lead', {
          title: t, inn: i, phone: mPhone, email: em,
          ati: ($('#m-ati')?.value || '').trim(),
          logistName: ($('#m-logist-name')?.value || '').trim(),
          logistPhone: mPhone,
          stage: Store.state.stages[0]
        });
        if (resL?.success) { Modal.closeAll(); await Store.load(true); } else Toast.error(resL?.error || 'Ошибка');
        break;
      }

      case 'submit-user': {
        const n = $('#u-name').value.trim(), ue = $('#u-email').value.trim(), p = $('#u-pass').value;
        if (!n || !ue || !p) return Toast.error('Все поля');
        if (isReservedUserName(n)) return Toast.error('Это имя зарезервировано');
        if (!isValidEmail(ue)) return Toast.error('Некорректный email');
        const pErr = passwordError(p); if (pErr) return Toast.error(pErr);
        const resU = await Net.req('register_user', { name: n, email: ue, password: p, role: $('#u-admin')?.checked ? 'admin' : 'user' });
        if (resU?.success) { Toast.success('Добавлен'); Modal.closeAll(); loadUsers(); } else Toast.error(resU?.error || 'Ошибка');
        break;
      }

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

      case 'add-stage': {
        const stN = await askPrompt('Новый этап', '', 'Название этапа'); if (!stN || !stN.trim()) return;
        const resSt = await Net.req('save_stages', { stages: [...Store.state.stages, stN.trim()] });
        if (resSt?.success) await Store.load(true); else Toast.error(resSt?.error || 'Ошибка');
        break;
      }

      case 'edit-stage': {
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
      }

      case 'save-lead-now': {
        if (!UI.leadId) return;
        // Немедленное сохранение без ожидания debounce (кнопка активна только при formDirty)
        saveLeadDebounced.cancel();
        await saveLeadForm(true);
        break;
      }

      case 'delete-lead': {
        if (!await askConfirm('Удалить лид?', 'Навсегда')) return;
        const delLead = Store.getLead(UI.leadId);
        const delRev = delLead ? (delLead._editRev ?? delLead.updatedAt) : 0;
        const resDL = await Net.req('delete_lead', { id: UI.leadId, updatedAt: delRev });
        if (resDL?.success) { goHome(true); await Store.load(true); }
        else if (resDL?.error === 'Карточка изменена в другом месте') { Toast.error('Карточку изменили в другой вкладке — обновляю'); await Store.load(true); }
        else Toast.error(resDL?.error || 'Ошибка');
        break;
      }

      case 'set-stage': {
        const lead = Store.getLead(UI.leadId); if (!lead) return;
        if (UI.formDirty) await saveLeadForm(true);
        const resMS = await Net.req('move_lead', { id: UI.leadId, stage: actEl.dataset.stage, updatedAt: lead._editRev ?? lead.updatedAt });
        if (resMS?.success) await Store.load(true);
        else if (resMS?.error === 'Карточка изменена в другом месте') { Toast.error('Карточку изменили в другой вкладке — обновляю'); await Store.load(true); }
        else Toast.error(resMS?.error || 'Ошибка');
        break;
      }

      case 'post-comment': {
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
      }

      case 'rm-file': (UI.editingCommentId ? UI.editFiles : UI.pendingFiles).splice(+actEl.dataset.idx, 1); renderFiles(); break;

      case 'toggle-edit': {
        const cid = actEl.dataset.cid;
        const edt = $(`[data-edt="${cid}"]`), txtEl = $(`[data-txt="${cid}"]`), inp = $(`[data-inp="${cid}"]`);
        if (!edt) return; const active = edt.classList.contains('active');
        $$('.inline-editor.active').forEach(e => e.classList.remove('active')); $$('.log-text.hidden').forEach(e => e.classList.remove('hidden'));
        UI.editFiles = [];
        if (!active) { txtEl.classList.add('hidden'); edt.classList.add('active'); UI.editingCommentId = cid; inp.focus(); inp.setSelectionRange(inp.value.length, inp.value.length); renderFiles(); }
        else { UI.editingCommentId = null; renderFiles(); }
        break;
      }

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

  initKeyboardShortcuts();

  initDashboardSearch();

  initDragDrop();
}

/** Глобальные клавиатурные сокращения (#16: вынесено из initAppEvents). */
function initKeyboardShortcuts() {
  document.addEventListener('keydown', e => {
    // Ctrl+S / Cmd+S на карточке лида — немедленное сохранение (вместо «Сохранить страницу» браузера)
    if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S' || e.key === 'ы' || e.key === 'Ы')) {
      if (UI.currentView === 'lead' && UI.leadId) {
        e.preventDefault();
        if (UI.formDirty) { saveLeadDebounced.cancel(); saveLeadForm(true); }
        return;
      }
    }
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
}

/** Drag-n-drop для карточек и колонок канбан-доски (#16: вынесено из initAppEvents). */
function initDragDrop() {
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
      const res = await Net.req('move_lead', { id: UI.drag.id, stage: tgt, updatedAt: lead.updatedAt });
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
    // Defense-in-depth: ui.html не должен содержать inline-скриптов (CSP и так их блокирует,
    // но если заголовок потеряется — это второй слой). При обнаружении — отказываемся грузить shell.
    if (/<script\b/i.test(html)) {
      console.error('CRM: ui.html содержит <script> — shell не загружен');
      handleLogoutUI('Ошибка загрузки интерфейса');
      return false;
    }
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
