'use strict';
// Поиск: выпадающий список глобального поиска в шапке (liveSearch/renderSearchDrop, локальный + серверный), поиск по своим лидам и сотрудникам, поиск на дашборде. Вынесено из app.js (план CODE_REVIEW п. 10.9).
/* global $, Net, Store, debounce, esc */
/* exported checkLeadDupDebounced, clearSearch, initDashboardSearch, liveSearch */

function closeSearchDrop() {
  $('#search-drop')?.classList.remove('open');
  $('#dashboard-search-drop')?.classList.remove('open');
}

function localSearchEmployees(q) {
  if (Store.state.user?.role !== 'admin') return [];
  const query = String(q || '').trim().toLowerCase();
  if (!query) return [];
  return (Store.state.colleagues || []).filter(u => String(u.name || '').toLowerCase().includes(query)).slice(0, 20);
}

function localSearchLeads(q) {
  const query = String(q || '').trim().toLowerCase();
  const digits = query.replace(/\D/g, '');
  if (!query) return [];
  return Store.state.leads.filter(l => {
    const title = String(l.title || '').toLowerCase();
    const inn = String(l.inn || '').replace(/\D/g, '');
    return title.includes(query) || (digits.length >= 4 && inn.includes(digits));
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

function initDashboardSearch() {
  const inp = $('#dashboard-search');
  const clear = $('#dashboard-search-clear');
  const drop = $('#dashboard-search-drop');
  const wrap = $('#dashboard-search-wrap');
  if (!inp || !drop) return;
  if (inp.dataset.init) return;
  inp.dataset.init = '1';

  const runSearch = debounce(() => {
    const q = inp.value.trim();
    // Кнопка «×» показывается через .has-query (как у поиска доски в liveSearch) —
    // раньше класс здесь не ставился, и очистить поиск дашборда крестиком было нельзя
    wrap?.classList.toggle('has-query', !!q);
    if (q.length < 2) {
      drop.innerHTML = '';
      drop.classList.remove('open');
      return;
    }
    const results = localSearchLeads(q);
    if (!results.length) {
      drop.innerHTML = '<div class="search-empty">Ничего не найдено</div>';
      drop.classList.add('open');
      return;
    }
    // data-action="open-search-lead" — существующий обработчик (раньше стоял "open-lead",
    // для которого ветки в switch нет: открытие срабатывало лишь косвенно через смену href/hash)
    drop.innerHTML = results.map(r =>
      `<div class="search-item" data-action="open-search-lead" data-id="${esc(r.id)}">
        <span class="search-item-title">${esc(r.title || 'Без названия')}</span>
        <span class="search-item-meta">${esc(r.inn || '')}</span>
      </div>`
    ).join('');
    drop.classList.add('open');
  }, 150);

  inp.addEventListener('input', runSearch);
  inp.addEventListener('focus', () => { if (inp.value.trim().length >= 2) runSearch(); });
  
  if (clear) {
    clear.addEventListener('click', () => {
      inp.value = '';
      drop.innerHTML = '';
      drop.classList.remove('open');
      wrap?.classList.remove('has-query');
      inp.focus();
    });
  }

  document.addEventListener('click', e => {
    if (!e.target.closest('#dashboard-search-wrap') && !e.target.closest('#dashboard-search-drop')) {
      drop.classList.remove('open');
    }
  });
}

/*
 * Предупреждение о дубле в окне «Новый лид»: при вводе ИНН (от 10 цифр — валидная длина
 * начинается с 10) спрашиваем search_leads и показываем, у кого уже есть клиент с таким ИНН.
 * Свои лиды и чужие (intersections) — разные подсказки. Не блокирует создание: пересечения —
 * легальная ситуация (см. README «Права и правила»), цель — предупредить, а не запретить.
 */
let _dupGen = 0;

async function checkLeadDup(inn) {
  const warn = $('#m-inn-warn');
  if (!warn) return;
  const digits = String(inn || '').replace(/\D/g, '');
  const gen = ++_dupGen;
  if (digits.length < 10) { warn.classList.add('hidden'); warn.textContent = ''; return; }
  // Свои лиды ищем локально (доска уже в памяти) — мгновенно и без запроса
  const mine = (Store.state.leads || []).filter(l => String(l.inn || '').replace(/\D/g, '') === digits);
  const res = await Net.req('search_leads', { q: digits });
  if (gen !== _dupGen) return; // устаревший ответ (ИНН уже другой)
  const others = [];
  if (res && res.success) {
    (res.intersections || []).forEach(it => {
      if (String(it.inn || '').replace(/\D/g, '') !== digits) return;
      (it.users || []).forEach(n => { if (n && !others.includes(n)) others.push(n); });
    });
  }
  const parts = [];
  if (mine.length) parts.push(`у вас уже есть лид «${mine[0].title}»`);
  if (others.length) parts.push(`клиента уже ведёт: ${others.join(', ')}`);
  if (!parts.length) { warn.classList.add('hidden'); warn.textContent = ''; return; }
  warn.textContent = '⚠ Возможный дубль: ' + parts.join('; ');
  warn.classList.remove('hidden');
}

const checkLeadDupDebounced = debounce(() => checkLeadDup($('#m-inn')?.value), 350);
