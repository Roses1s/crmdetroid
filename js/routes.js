'use strict';
// Справочник маршрутов: список направлений, карточка направления, перевозчики (список, карточка, автосохранение), их лог. Вынесено из app.js (план CODE_REVIEW п. 10.9).
/* global $, $$, Net, Toast, UI, debounce, esc, navTo, persistOk, plural, renderFiles, renderLogInto, saveLeadForm, setupPhoneMask, switchView */
/* exported appsWord, goNeighborCarrier, loadRoutes, saveCarrierDebounced, setRoutesFilter */

let _routesCache = [];

let _routesFilter = '';
// Сеттер вместо присваивания из app.js: кросс-файловая запись в let ломается
// на no-unused-vars/prefer-const и прячет зависимость (единственный внешний потребитель — поиск по направлениям).
function setRoutesFilter(q) { _routesFilter = q; }

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

// Единое склонение — plural() объявлен ниже (hoisting); раньше были три копии одной логики
function carrierWord(n) { return plural(Math.abs(n), 'перевозчик', 'перевозчика', 'перевозчиков'); }

function appsWord(n) { return plural(Math.abs(n), 'заявка', 'заявки', 'заявок'); }

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
  $('#nav-dashboard')?.classList.remove('active');
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
  $('#nav-dashboard')?.classList.remove('active');
  $('#carrier-view').classList.add('active');
  $('#nav-routes')?.classList.add('active');
  $('#cf-name').value = c.name || '';
  $('#cf-phone').value = c.phone || '';
  $('#cf-company').value = c.company || '';
  if ($('#cf-note')) $('#cf-note').value = c.note || '';
  // Править карточку может создатель или админ (сервер проверяет can_manage_ref);
  // остальным поля показываем только для чтения — иначе автосейв сыпал бы ошибками при вводе.
  UI.carrierCanManage = !!c.canManage;
  ['#cf-name', '#cf-phone', '#cf-company', '#cf-note'].forEach(sel => {
    const el = $(sel); if (el) el.readOnly = !c.canManage;
  });
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
  // Поля read-only для тех, кто не может править карточку (создатель/админ) — не шлём запрос,
  // который сервер всё равно отклонит.
  if (!UI.carrierCanManage) { UI.formDirty = false; return null; }
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

function renderCarrierLog() { renderLogInto($('#carrier-chatter-log'), UI.carrierComments || []); }
