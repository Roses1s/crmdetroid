'use strict';
// Админ-зона: список сотрудников (вкладка «Сотрудники»), удаление с передачей лидов, вкладка «Активность клиентов». Вынесено из app.js (план CODE_REVIEW п. 10.9).
/* global $, Loading, Modal, Net, Store, Toast, esc, fmtMoney, fmtTime, plural */
/* exported activityShiftYear, confirmDeleteUser, loadApps, openDeleteUser, setActivityUser, setAppsQuery, setAppsUser, usersTableBusy */

let _usersCache = [];

async function loadUsers() {
  const res = await Net.req('get_users'); if (!res || !res.success) return;
  _usersCache = res.users || [];
  const tbody = $('#users-tbody'); tbody.innerHTML = '';
  const currId = Store.state.user.id;
  // Empty state (#3): если в системе только текущий пользователь — показываем подсказку
  if (_usersCache.length <= 1) {
    const tr = document.createElement('tr');
    tr.innerHTML = '<td colspan="7" class="cell-muted">Добавьте сотрудников, чтобы распределять лиды и вести совместную работу.</td>';
    tbody.appendChild(tr);
    if (_usersCache.length === 0) return;
  }
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

function usersTableBusy() {
  const tbody = $('#users-tbody'); if (!tbody) return false;
  if (tbody.contains(document.activeElement)) return true;
  return [...tbody.querySelectorAll('input, select')].some(i => {
    if (i.type === 'password') return !!i.value;
    return i.value !== i.defaultValue;
  });
}

// === АКТИВНОСТЬ КЛИЕНТОВ ===
let _activityYear = new Date().getFullYear();

const _activityCache = {};

let _activityUserId = null; // локальный для вкладки «Активность», не влияет на «Лиды»
// Обёртки для обработчиков в app.js (кросс-файловая запись в let ломает ESLint и прячет зависимость):
// листание года стрелками и выбор сотрудника в селекте вкладки.
function activityShiftYear(delta) { return loadActivity(_activityYear + delta); }
function setActivityUser(id) { _activityUserId = (!id || id === Store.state.user?.id) ? null : id; }

async function loadActivity(year) {
  if (year != null) _activityYear = year;
  Loading.show();
  try {
    // Через Net.req: раньше здесь был голый fetch — need_login не разлогинивал,
    // ошибки терялись молча (см. код-ревью, п. 2.9)
    const res = await Net.req('get_activity', { year: _activityYear, as: _activityUserId || 0 });
    if (!res || !res.success) { if (res?.error) Toast.error(res.error); return; }
    _activityYear = res.year || _activityYear;
    // Кэш ограничен: старые годы/сотрудники вытесняются (раньше рос без предела всю сессию)
    const keys = Object.keys(_activityCache);
    if (keys.length > 24) delete _activityCache[keys[0]];
    _activityCache[_activityYear + ':' + (_activityUserId || 'me')] = res.clients || [];
    renderActivity();
  } finally {
    Loading.hide();
  }
}

function renderActivity() {
  const yearEl = $('#activity-year');
  if (yearEl) yearEl.textContent = String(_activityYear);
  // Select сотрудников (только для админов)
  const sel = $('#activity-employee');
  if (sel) {
    const isAdmin = Store.state.user?.role === 'admin';
    sel.style.display = isAdmin ? '' : 'none';
    if (isAdmin) {
      const curId = _activityUserId || Store.state.user?.id;
      const opts = (Store.state.colleagues || []).map(u =>
        `<option value="${esc(u.id)}">${esc(u.name)}</option>`
      ).join('');
      sel.innerHTML = opts;
      sel.value = String(curId);
    }
  }
  const tbody = $('#activity-tbody');
  if (!tbody) return;
  const cacheKey = _activityYear + ':' + (_activityUserId || 'me');
  let clients = _activityCache[cacheKey] || [];
  // Сортировка: сначала клиенты с поездками (по убыванию итого), потом без поездок
  clients = clients.slice().sort((a, b) => {
    const totalA = Object.values(a.months).reduce((s, v) => s + v, 0);
    const totalB = Object.values(b.months).reduce((s, v) => s + v, 0);
    return totalB - totalA;
  });
  tbody.innerHTML = '';
  if (!clients.length) {
    tbody.innerHTML = '<tr><td colspan="14" class="cell-muted">Нет поездок за этот год</td></tr>';
    return;
  }
  const frag = document.createDocumentFragment();
  clients.forEach(c => {
    const tr = document.createElement('tr');
    let totalTrips = 0;
    let monthCells = '';
    for (let m = 1; m <= 12; m++) {
      const count = c.months[m] || 0;
      totalTrips += count;
      if (count > 0) {
        monthCells += `<td class="activity-cell active" title="${count} ${plural(count, 'поездка', 'поездки', 'поездок')}" data-inn="${esc(c.inn)}" data-month="${m}">${count}</td>`;
      } else {
        monthCells += '<td class="activity-cell"></td>';
      }
    }
    tr.innerHTML = `<td class="activity-client"><span class="name-link" data-action="open-activity-client" data-inn="${esc(c.inn)}">${esc(c.title)}</span><div class="activity-inn">${esc(c.inn)}</div></td>${monthCells}<td class="activity-total">${totalTrips}</td>`;
    frag.appendChild(tr);
  });
  tbody.appendChild(frag);
}

/* === ВКЛАДКА «ЗАЯВКИ» (реестр заявок менеджера) === */
// Как «Активность»: выбор сотрудника локален для вкладки и не влияет на «Лиды».
let _appsUserId = null;
let _appsQuery = '';
let _appsCache = null; // последний ответ get_apps (список + итоги)

function setAppsUser(id) { _appsUserId = (!id || id === Store.state.user?.id) ? null : id; }
function setAppsQuery(q) { _appsQuery = q; }

async function loadApps() {
  Loading.show();
  try {
    const res = await Net.req('get_apps', { q: _appsQuery, as: _appsUserId || 0 });
    if (!res || !res.success) { if (res?.error) Toast.error(res.error); return; }
    _appsCache = res;
    renderApps();
  } finally {
    Loading.hide();
  }
}

function renderApps() {
  // Select сотрудников (только для админов) — по образцу вкладки «Активность»
  const sel = $('#apps-employee');
  if (sel) {
    const isAdmin = Store.state.user?.role === 'admin';
    sel.style.display = isAdmin ? '' : 'none';
    if (isAdmin) {
      const curId = _appsUserId || Store.state.user?.id;
      sel.innerHTML = (Store.state.colleagues || []).map(u =>
        `<option value="${esc(u.id)}">${esc(u.name)}</option>`
      ).join('');
      sel.value = String(curId);
    }
  }
  const res = _appsCache;
  const summary = $('#apps-summary');
  if (summary) {
    if (res && res.total > 0) {
      const parts = [`${res.total} ${plural(res.total, 'заявка', 'заявки', 'заявок')}`];
      if (res.sumRate) parts.push(`Ставки: ${fmtMoney(res.sumRate)} ₽`);
      if (res.sumMargin) parts.push(`Маржа: ${fmtMoney(res.sumMargin)} ₽`);
      summary.textContent = parts.join(' · ');
    } else {
      summary.textContent = '';
    }
  }
  const tbody = $('#apps-tbody');
  if (!tbody) return;
  const apps = res?.apps || [];
  if (!apps.length) {
    tbody.innerHTML = `<tr><td colspan="7" class="cell-muted">${_appsQuery ? 'Ничего не найдено' : 'Пока нет заявок'}</td></tr>`;
    return;
  }
  tbody.innerHTML = '';
  const frag = document.createDocumentFragment();
  apps.forEach(a => {
    const tr = document.createElement('tr');
    const route = (a.cityFrom || a.cityTo) ? `${esc(a.cityFrom || '?')} → ${esc(a.cityTo || '?')}` : '<span class="apps-dim">—</span>';
    const carrier = a.carrierCompany
      ? `${esc(a.carrierCompany)}${a.carrierInn ? `<div class="apps-sub">${esc(a.carrierInn)}</div>` : ''}`
      : '<span class="apps-dim">—</span>';
    tr.innerHTML = `<td class="apps-date">${esc(fmtTime(a.createdAt).slice(0, 10))}</td>`
      + `<td><span class="name-link" data-action="open-app-lead" data-id="${esc(a.leadId)}">${esc(a.leadTitle || '—')}</span>${a.leadInn ? `<div class="apps-sub">${esc(a.leadInn)}</div>` : ''}</td>`
      + `<td>${route}</td>`
      + `<td>${carrier}</td>`
      + `<td class="apps-money">${a.rate ? esc(fmtMoney(a.rate)) : '<span class="apps-dim">—</span>'}</td>`
      + `<td class="apps-money">${a.margin ? esc(fmtMoney(a.margin)) : '<span class="apps-dim">—</span>'}</td>`
      + `<td class="apps-vat">${Number(a.vat) ? 'с НДС' : 'без'}</td>`;
    frag.appendChild(tr);
  });
  tbody.appendChild(frag);
}
