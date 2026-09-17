'use strict';
// Лиды: доска (renderBoard), карточка лида, заявки лида (модалка, статистика), лог комментариев с вложениями (общий рендер renderLogInto используют и перевозчики), автосохранение формы. Вынесено из app.js (план CODE_REVIEW п. 10.9).
/* global $, $$, Modal, Net, Store, Toast, UI, appsWord, askConfirm, debounce, esc, fmtBytes, fmtMoney, fmtTime, goHome, isImageAtt, isValidEmail, moneyNum, moneyToInput, navTo, renderSaveStatus, safeAttUrl, saveCarrierForm, setupPhoneMask, vatLabel */
/* exported APP_STATUS_LABELS, addTagFromModal, closeLeadAppModal, closeLeadTagsModal, deleteLeadApp, deleteTagFromModal, editingCommentAttCount, ensureLeadFull, goNeighborLead, initClientsEvents, loadAppComments, loadClients, openApp, openLeadAppModal, openLeadTagsModal, pickTagColor, refreshAppLog, removeLeadAppCache, renderAppLog, renderAppStatus, renderBoard, resetBoardCache, saveAppDebounced, saveAppForm, saveLeadAppFromModal, submitLeadTags, syncLeadAppCache, toggleTagInModal, updateAppSaveUI, updateLeadSaveUI */

function renderAttHtml(a, c) {
  const raw = String(a.dataUrl || '');
  const u = safeAttUrl(raw), n = esc(a.name);
  if (!u) return `<span class="att-file-wrap"><span class="att-file">📄 ${n} <span class="att-size">${fmtBytes(a.size)}</span></span></span>`;
  const id = a.id != null ? String(a.id) : '';
  const del = (id && c && canEditComment(c))
    ? `<button type="button" class="att-del" data-action="del-att" data-id="${esc(id)}" title="Удалить вложение">×</button>`
    : '';
  if (isImageAtt(a) && raw) {
    return `<div class="att-image"><a href="${u}" target="_blank" rel="noopener" data-action="open-image" data-src="${u}"><img src="${u}" alt="${n}"></a>${del}</div>`;
  }
  return `<span class="att-file-wrap"><a class="att-file" href="${u}" target="_blank" rel="noopener">📄 ${n} <span class="att-size">${fmtBytes(a.size)}</span></a>${del}</span>`;
}

// force=true (ревью §37, F1): перепроверить даже _full. Поллинг карточки корзины —
// лид могли забрать или стереть, пока она открыта. null + транзита нет в сторе → закрывать.
async function ensureLeadFull(id, force = false) {
  let lead = Store.getLead(id);
  if (lead && lead._full && !force) return lead;
  if (!lead) {
    // Чужих активных в сторе нет и быть не должно; удалённый подгружаем
    // напрямую (§35) транзитом — на доску он не попадёт (фильтр _deleted).
    const res = await Net.req('get_lead', { id });
    if (!res?.success || !res.lead?.deleted) return null;
    lead = { ...res.lead, _full: true, _deleted: true, _editRev: res.lead.updatedAt };
    Store.state.leads.push(lead);
    return lead;
  }
  const res = await Net.req('get_lead', { id });
  if (!res || !res.success || !res.lead) {
    // Лид исчез (стёрт из корзины или угнан): транзит убираем, карточку закрывать.
    if (lead._deleted) Store.state.leads = Store.state.leads.filter(l => l !== lead);
    return lead._deleted ? null : lead;
  }
  if (lead._deleted && !res.lead.deleted) {
    // Корзину забрали в работу: транзит больше не нужен (актуальная версия уже
    // в сторе из get_data — или подтянется следующим поллингом).
    Store.state.leads = Store.state.leads.filter(l => l !== lead);
    return null;
  }
  const keepComments = lead.comments;
  Object.assign(lead, res.lead);
  if (keepComments && !res.lead.comments) lead.comments = keepComments;
  lead._full = true;
  if (lead._editRev == null) lead._editRev = lead.updatedAt;
  return lead;
}
// Системность определяется сервером (crm_is_sys_comment) и передаётся в поле isSystem.
// Раньше клиент проверял author === 'Система' — это рассинхронизировалось с сервером,
// который проверял user_id = 0. Теперь один источник правды — сервер.
function isSystemComment(c) {
  return !!c?.isSystem;
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

// Один рендер лога для лида, перевозчика и заявки (раньше две одинаковые копии расходились при правках).
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
          <div class="inline-edit-btns"><label class="composer-btn composer-attach" title="Прикрепить файлы" aria-label="Прикрепить файлы"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg><input type="file" class="edit-file-input" multiple hidden accept=".png,.jpg,.jpeg,.gif,.webp,.bmp,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.7z"></label><button class="btn btn-secondary btn-sm" data-action="toggle-edit" data-cid="${esc(c.id)}">Отмена</button><button class="btn btn-primary btn-sm" data-action="save-comment" data-cid="${esc(c.id)}">Сохранить</button></div>
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

function renderLog() { const lead = Store.getLead(UI.leadId); renderLogInto($('#chatter-log'), (lead && lead.comments) || []); }

async function openLead(id, updateHash = true) {
  await ensureLeadFull(id);
  const lead = Store.getLead(id); if (!lead || !lead._full) return goHome(updateHash);

  if (UI.leadId && UI.formDirty && UI.leadId !== id) {
    const saved = await saveLeadForm(true);
    if (!persistOk(saved)) return;
    if (saved && saved.transferred) await Store.load(true);
  }
  // Симметрично openRoute/openCarrier: уходя с карточки перевозчика (например, кнопкой
  // «Назад» браузера), сохраняем и её форму, а контекст чистим — иначе formDirty сбрасывался,
  // UI.carrierId висел на виде лида, а правки спасало только висящее debounce (ревью, п. 2.1).
  if (UI.carrierId && UI.formDirty) {
    const savedC = await saveCarrierForm(true);
    if (!persistOk(savedC)) return;
  }
  if (UI.appId && UI.formDirty) {
    const savedA = await saveAppForm(true);
    if (!persistOk(savedA)) return;
  }
  const still = Store.getLead(id); if (!still || !still._full) return goHome(updateHash);

  UI.leadId = id; UI.currentView = 'lead'; UI.pendingFiles = []; UI.formDirty = false;
  UI.carrierId = null; UI.carrierComments = []; UI.editingCommentId = null;
  UI.appId = null; UI.appRev = null; UI.appLeadId = null; UI.appLeadTitle = ''; UI.appComments = [];
  updateLeadSaveUI('saved');
  $$('.view-section').forEach(el => el.classList.remove('active'));
  // Снимаем подсветку кнопки дашборда: карточка лида — не дашборд
  // (иначе при открытии лида из поиска дашборда кнопка оставалась подсвеченной)
  $('#nav-dashboard')?.classList.remove('active');
  $('#detail-view').classList.add('active');
  fillLeadForm(still, true);
  renderLeadApps();

  if (updateHash) navTo('lead/' + encodeURIComponent(id));

  await loadLeadComments(id);
  renderDetailStages(); renderFiles(); renderLog();
  updateLeadNav();
}

function ownLeadsOrdered() {
  const stages = Store.state.stages || [];
  const leads = Store.state.leads || [];
  const ordered = [];
  stages.forEach(stage => {
    leads.forEach(l => { if (l.stage === stage && !l._deleted) ordered.push(l); });
  });
  leads.forEach(l => { if (!stages.includes(l.stage) && !l._deleted) ordered.push(l); });
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

function fillLeadForm(lead, fromServer = false) {
  if (!lead || !lead._full) return;
  if (fromServer || lead._editRev == null) lead._editRev = lead.updatedAt;
  $('#f-title').value = lead.title;
  ['inn','ati','email','manager'].forEach(f => {
    const el = $(`#f-${f}`); if (el && document.activeElement !== el) el.value = lead[f] || '';
  });
  const ln = $('#f-logist-name'); if (ln && document.activeElement !== ln) ln.value = lead.logistName || '';
  const lp = $('#f-logist-phone'); if (lp && document.activeElement !== lp) lp.value = lead.logistPhone || '';
  $('#crumb-name').textContent = lead.title; setupPhoneMask($('#f-logist-phone'));
  renderLeadTagsRow();
  renderLeadApps();
  applyDeletedMode(lead);
}

function applyDeletedMode(lead) {
  const isDel = !!(lead.deleted || lead._deleted);
  const bar = $('#deleted-bar');
  if (bar) {
    bar.hidden = !isDel;
    if (isDel) {
      const who = lead.deletedBy || '—';
      const when = lead.deletedAt ? new Date(lead.deletedAt).toLocaleString('ru-RU') : '';
      $('#deleted-bar-text').textContent = `Лид удалён · ${who}${when ? ` · ${when}` : ''}`;
      const purgeBtn = $('#btn-purge-lead');
      if (purgeBtn) purgeBtn.hidden = (Store.state.user?.role || '') !== 'admin';
    }
  }
  $('#detail-view')?.classList.toggle('lead-deleted', isDel);
  document.querySelectorAll('#detail-view .form-input, #detail-view .editable-title').forEach(el => { el.disabled = isDel; });
}

function leadAppsOf(lead) {
  return Array.isArray(lead?.applications) ? lead.applications : [];
}

function updateAppsCount(n) {
  const el = $('#f-apps');
  if (el) el.textContent = String(n || 0);
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
    // esc() вокруг vatLabel — defense-in-depth (ревью v19, п. 2): значение серверное
    // (int|null), но подпись уходит в innerHTML, и экранирование здесь обязательно.
    const vat = esc(vatLabel(a.vat));
    const rateLine = rate ? `${esc(rate)} ₽ · ${vat}` : vat;
    // Строка «Перевозчику» — только если есть ставка или выбранный налог перевозчика
    const crate = fmtMoney(a.carrierRate);
    const hasCVat = a.carrierVat !== null && a.carrierVat !== undefined && a.carrierVat !== '';
    const costLine = (crate || hasCVat)
      ? `<div class="lead-app-cost">Перевозчику: ${crate ? esc(crate) + ' ₽ · ' : ''}${esc(vatLabel(a.carrierVat))}</div>` : '';
    const mar = fmtMoney(a.margin);
    const marLine = mar ? `маржа ${esc(mar)} ₽` : '';
    const who = [a.carrierCompany, a.carrierName].filter(Boolean).join(' · ');
    const inn = a.carrierInn ? 'ИНН ' + a.carrierInn : '';
    const phone = a.carrierPhone || '';
    const meta = [who, inn, phone].filter(Boolean).join(' · ');
    const num = a.number ? `<span class="lead-app-num">№ ${esc(a.number)}</span> · ` : '';
    return `<div class="lead-app-card">
      <div class="lead-app-main">
        <div class="lead-app-route name-link" data-action="open-app" data-id="${esc(a.id)}">${num}${esc(route)}</div>
        <div class="lead-app-rate">${rateLine}${marLine ? ' · ' + marLine : ''}</div>
        ${costLine}
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
    number: $('#la-number')?.value || '',
    from: $('#la-from')?.value || '',
    to: $('#la-to')?.value || '',
    rate: $('#la-rate')?.value || '',
    vat: $('#la-vat')?.value || '',
    carrierRate: $('#la-carrier-rate')?.value || '',
    carrierVat: $('#la-carrier-vat')?.value || '',
    margin: $('#la-margin')?.value || '',
    company: $('#la-company')?.value || '',
    inn: $('#la-inn')?.value || '',
    name: $('#la-name')?.value || '',
    phone: $('#la-phone')?.value || '',
    loadAddress: $('#la-load-address')?.value || '',
    loadContact: $('#la-load-contact')?.value || '',
    loadDateFrom: $('#la-load-date-from')?.value || '',
    loadDateTo: $('#la-load-date-to')?.value || '',
    loadTime: $('#la-load-time')?.value || '',
    unloadAddress: $('#la-unload-address')?.value || '',
    unloadContact: $('#la-unload-contact')?.value || '',
    unloadDateFrom: $('#la-unload-date-from')?.value || '',
    unloadDateTo: $('#la-unload-date-to')?.value || '',
    unloadTime: $('#la-unload-time')?.value || ''
  });
}

let _leadAppSnap = '';

function openLeadAppModal(app) {
  $('#la-id').value = app?.id || '';
  $('#lead-app-modal-title').textContent = app?.id ? 'Заявка' : 'Новая заявка';
  $('#la-number').value = app?.number || '';
  $('#la-from').value = app?.cityFrom || '';
  $('#la-to').value = app?.cityTo || '';
  // Ставка и маржа — одинаковый денежный формат (копейки через запятую); раньше ставка «1234.50»
  // из БД показывалась как 123450
  $('#la-rate').value = moneyToInput(app?.rate || '');
  if ($('#la-margin')) $('#la-margin').value = moneyToInput(app?.margin || '');
  // Новая заявка — НДС 22% с обеих сторон (дефолт пользователя); у сохранённой — как
  // было записано. ??, а не ||: НДС 0% — валидный режим и не должен падать в «Без НДС».
  $('#la-vat').value = app ? (app.vat ?? '') : '22';
  $('#la-carrier-rate').value = moneyToInput(app?.carrierRate || '');
  $('#la-carrier-vat').value = app ? (app.carrierVat ?? '') : '22';
  $('#la-company').value = app?.carrierCompany || '';
  $('#la-inn').value = app?.carrierInn || '';
  $('#la-name').value = app?.carrierName || '';
  $('#la-phone').value = app?.carrierPhone || '';
  $('#la-load-address').value = app?.loadAddress || '';
  $('#la-load-contact').value = app?.loadContact || '';
  $('#la-load-date-from').value = app?.loadDateFrom || '';
  $('#la-load-date-to').value = app?.loadDateTo || '';
  $('#la-load-time').value = app?.loadTime || '';
  $('#la-unload-address').value = app?.unloadAddress || '';
  $('#la-unload-contact').value = app?.unloadContact || '';
  $('#la-unload-date-from').value = app?.unloadDateFrom || '';
  $('#la-unload-date-to').value = app?.unloadDateTo || '';
  $('#la-unload-time').value = app?.unloadTime || '';
  setupPhoneMask($('#la-phone'));
  Modal.open('modal-lead-app');
  _leadAppSnap = leadAppSnapshot();
  setTimeout(() => $('#la-from')?.focus(), 50);
}

async function closeLeadAppModal(force = false) {
  const box = $('#modal-lead-app');
  if (!box || !box.classList.contains('open')) return true;
  if (!force && leadAppSnapshot() !== _leadAppSnap) {
    if (!await askConfirm('Закрыть заявку?', 'Изменения не сохранятся', 'primary')) return false;
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
  // updatedAt для оптимистической блокировки: если заявку изменили в другой вкладке,
  // сервер ответит «Заявка изменена в другом месте» вместо молчаливой перезаписи.
  const editApp = ($('#la-id').value || '').trim()
    ? leadAppsOf(Store.getLead(UI.leadId)).find(a => String(a.id) === String($('#la-id').value || ''))
    : null;
  const payload = {
    id: ($('#la-id').value || '').trim(),
    leadId: UI.leadId,
    number: ($('#la-number').value || '').trim(),
    cityFrom: from,
    cityTo: to,
    rate: ($('#la-rate').value || '').trim(),
    margin: ($('#la-margin')?.value || '').trim(),
    vat: $('#la-vat').value,
    carrierRate: ($('#la-carrier-rate').value || '').trim(),
    carrierVat: $('#la-carrier-vat').value,
    carrierCompany: ($('#la-company').value || '').trim(),
    carrierInn: inn,
    carrierName: ($('#la-name').value || '').trim(),
    carrierPhone: ($('#la-phone').value || '').trim(),
    loadAddress: $('#la-load-address').value || '',
    loadContact: $('#la-load-contact').value || '',
    loadDateFrom: $('#la-load-date-from').value || '',
    loadDateTo: $('#la-load-date-to').value || '',
    loadTime: $('#la-load-time').value || '',
    unloadAddress: $('#la-unload-address').value || '',
    unloadContact: $('#la-unload-contact').value || '',
    unloadDateFrom: $('#la-unload-date-from').value || '',
    unloadDateTo: $('#la-unload-date-to').value || '',
    unloadTime: $('#la-unload-time').value || ''
  };
  if (editApp) payload.updatedAt = editApp.updatedAt;
  const res = await Net.req('save_lead_app', payload);
  if (!res?.success) {
    if (res?.error === 'Заявка изменена в другом месте') {
      Toast.error('Заявку изменили в другой вкладке — обновляю');
      if (UI.leadId) await ensureLeadFull(UI.leadId);
      renderLeadApps();
    } else Toast.error(res?.error || 'Ошибка');
    return;
  }
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
  // Передаём updatedAt для оптимистической блокировки: если заявку изменили
  // в другой вкладке, сервер ответит «Заявка изменена в другом месте».
  const appToDelete = leadAppsOf(Store.getLead(UI.leadId)).find(a => String(a.id) === String(id));
  const payload = { id, leadId: UI.leadId };
  if (appToDelete) payload.updatedAt = appToDelete.updatedAt;
  const res = await Net.req('delete_lead_app', payload);
  if (!res?.success) {
    if (res?.error === 'Заявка изменена в другом месте') {
      Toast.error('Заявку изменили в другой вкладке — обновляю');
      if (UI.leadId) await ensureLeadFull(UI.leadId);
      renderLeadApps();
    } else Toast.error(res?.error || 'Ошибка');
    return;
  }
  const lead = Store.getLead(UI.leadId);
  if (lead) {
    lead.applications = leadAppsOf(lead).filter(a => String(a.id) !== String(id));
    if (res.applicationsCount != null) lead.applicationsCount = res.applicationsCount;
    applyAppsStats(lead, res.appsStats);
    if (res.updatedAt) { lead.updatedAt = res.updatedAt; lead._editRev = res.updatedAt; }
  }
  renderLeadApps();
}

/*
 * Статус сохранения карточки лида (тулбар): «● Есть изменения» / «Сохраняю…» / «✓ Сохранено HH:MM».
 * Кнопка «💾 Сохранить» активна только при несохранённых изменениях — автосохранение
 * (saveLeadDebounced) остаётся основным механизмом, кнопка и Ctrl+S просто не ждут 500 мс.
 * Общий рендер (renderSaveStatus в util.js) используют и лид, и перевозчик.
 */
function updateLeadSaveUI(state, ts = 0) {
  renderSaveStatus($('#lead-save-status'), $('#btn-save-lead'), state, ts);
}

/*
 * Теги лида (v17). Палитра дублирует серверную CRM_TAG_COLORS (db.php): сервер всё равно
 * отбрасывает цвет не из списка, здесь она нужна только для рисования кружков в модалке.
 */
const TAG_COLORS = ['#ef4444', '#f97316', '#f59e0b', '#22c55e', '#14b8a6', '#3b82f6', '#6366f1', '#a855f7', '#ec4899', '#64748b'];
let _tagModalSelected = new Set(); // id тегов, отмеченных в модалке
let _tagModalInitial = new Set(); // снимок на момент открытия — для dirty-гарда (п. 2.7)
let _tagModalColor = TAG_COLORS[6];

// Строка тегов в карточке лида (под названием). Зовётся из fillLeadForm и после сохранения модалки.
function renderLeadTagsRow() {
  const box = $('#lead-tags-chips');
  if (!box) return;
  box.innerHTML = UI.leadId ? tagChipsHtml(leadTagsOf(UI.leadId)) : '';
}

function renderTagPalette() {
  const pal = $('#tag-palette');
  if (!pal) return;
  pal.innerHTML = TAG_COLORS.map(c =>
    `<span class="tag-color ${tagColorClass(c)}${c === _tagModalColor ? ' selected' : ''}" data-action="pick-tag-color" data-color="${c}" role="button" aria-label="Цвет ${c}"></span>`
  ).join('') + `<button type="button" class="btn btn-secondary btn-sm" data-action="add-tag">+ Добавить</button>`;
}

function renderTagList() {
  const list = $('#tags-list');
  if (!list) return;
  const tags = Store.state.tags || [];
  if (!tags.length) { list.innerHTML = '<div class="tags-empty">Тегов пока нет — создайте первый ниже</div>'; return; }
  list.innerHTML = tags.map(t => {
    const on = _tagModalSelected.has(Number(t.id));
    return `<div class="tag-row">
      <span class="tag-chip tag-pick ${tagColorClass(t.color)}${on ? ' selected' : ''}" data-action="toggle-tag" data-id="${t.id}" role="checkbox" aria-checked="${on}">${on ? '✓ ' : ''}${esc(t.name)}</span>
      <span class="tag-del" data-action="del-tag" data-id="${t.id}" title="Удалить тег из справочника">×</span>
    </div>`;
  }).join('');
}

function pickTagColor(c) {
  if (TAG_COLORS.includes(c)) { _tagModalColor = c; renderTagPalette(); }
}

function toggleTagInModal(id) {
  const n = Number(id);
  if (_tagModalSelected.has(n)) _tagModalSelected.delete(n); else _tagModalSelected.add(n);
  renderTagList();
}

function openLeadTagsModal() {
  if (!UI.leadId) return;
  _tagModalSelected = new Set(leadTagsOf(UI.leadId).map(t => Number(t.id)));
  _tagModalInitial = new Set(_tagModalSelected);
  _tagModalColor = TAG_COLORS[6];
  const inp = $('#tag-new-name'); if (inp) inp.value = '';
  renderTagList(); renderTagPalette();
  Modal.open('modal-tags');
}

// Создать тег из полей модалки (название + выбранный цвет). Тег сразу отмечается выбранным.
async function addTagFromModal() {
  const inp = $('#tag-new-name');
  const name = (inp?.value || '').trim();
  if (!name) return Toast.error('Укажите название тега');
  const res = await Net.req('save_tag', { name, color: _tagModalColor });
  if (!res?.success) return Toast.error(res?.error || 'Ошибка');
  Store.state.tags = res.tags || Store.state.tags;
  if (res.tag) _tagModalSelected.add(Number(res.tag.id));
  if (inp) inp.value = '';
  renderTagList();
}

async function deleteTagFromModal(id) {
  if (!await askConfirm('Удалить тег?', 'Он снимется со всех лидов')) return;
  // Кнопка «ОК» подтверждения делает Modal.closeAll() и закрывает и окно тегов —
  // возвращаем его на место, чтобы не терять отмеченные галочки.
  Modal.open('modal-tags');
  const res = await Net.req('delete_tag', { id: Number(id) });
  if (!res?.success) return Toast.error(res?.error || 'Ошибка');
  Store.state.tags = res.tags || [];
  _tagModalSelected.delete(Number(id));
  // Убираем тег из локальной карты, не дожидаясь следующего get_data
  const m = Store.state.leadTags || {};
  Object.keys(m).forEach(k => { m[k] = (m[k] || []).filter(t => Number(t.id) !== Number(id)); });
  renderTagList(); renderLeadTagsRow(); resetBoardCache();
}

async function submitLeadTags() {
  if (!UI.leadId) return;
  const res = await Net.req('set_lead_tags', { leadId: UI.leadId, tagIds: [..._tagModalSelected] });
  if (!res?.success) return Toast.error(res?.error || 'Ошибка');
  Store.state.leadTags = Store.state.leadTags || {};
  Store.state.leadTags[String(UI.leadId)] = res.leadTags || [];
  Modal.close('modal-tags');
  renderLeadTagsRow(); resetBoardCache();
}

// Dirty-guard закрытия модалки тегов (ревью, п. 2.7): симметрично closeLeadAppModal —
// неотправленные отметки подтверждаем, а не роняем молча (Отмена/Escape).
async function closeLeadTagsModal() {
  const box = $('#modal-tags');
  if (!box || !box.classList.contains('open')) return true;
  const dirty = _tagModalSelected.size !== _tagModalInitial.size
    || [..._tagModalSelected].some(id => !_tagModalInitial.has(id))
    || (($('#tag-new-name')?.value || '').trim() !== '');
  if (dirty && !await askConfirm('Закрыть без сохранения?', 'Отметки тегов не сохранятся', 'primary')) return false;
  box.classList.remove('open');
  return true;
}

let _leadSaveChain = Promise.resolve();

// См. saveCarrierForm: цепочка _leadSaveChain, первый аргумент ни на что не влияет.
async function saveLeadForm(_sync = false, keepalive = false, transferTo = 0) {
  if (!UI.leadId) return null; const lead = Store.getLead(UI.leadId); if (!lead || !lead._full) return null;
  if (lead.deleted || lead._deleted) { Toast.error('Лид удалён — сначала возьмите его в работу'); return null; }
  const run = async () => {
    const cur = Store.getLead(UI.leadId); if (!cur || !cur._full) return null;
    if (UI.currentView === 'lead') updateLeadSaveUI('saving');
    const innDigits = ($('#f-inn').value || '').replace(/\D/g, '');
    const patch = {
      id: UI.leadId,
      title: $('#f-title').value.trim() || 'Без названия',
      inn: innDigits,
      ati: ($('#f-ati')?.value || '').trim(),
      email: $('#f-email').value.trim(),
      manager: $('#f-manager').value.trim(),
      logistName: ($('#f-logist-name')?.value || '').trim(),
      logistPhone: ($('#f-logist-phone')?.value || '').trim(),
      stage: cur.stage,
      updatedAt: cur._editRev ?? cur.updatedAt
    };
    if (innDigits && innDigits.length !== 10 && innDigits.length !== 12) {
      Toast.error('ИНН 10 или 12 цифр');
      if (UI.currentView === 'lead') updateLeadSaveUI('dirty');
      return { success: false, error: 'ИНН 10 или 12 цифр' };
    }
    if (!isValidEmail(patch.email)) {
      Toast.error('Некорректный email');
      if (UI.currentView === 'lead') updateLeadSaveUI('dirty');
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
        // После перечитывания форма совпадает с сервером — несохранённых изменений больше нет
        UI.formDirty = false;
        if (UI.currentView === 'lead') updateLeadSaveUI('saved', Date.now());
      } else {
        Toast.error(res.error || 'Ошибка');
        if (UI.currentView === 'lead') updateLeadSaveUI('dirty');
      }
      return res;
    }
    if (res && res.success) {
      UI.formDirty = false;
      Object.assign(cur, patch);
      if (res.updatedAt) { cur.updatedAt = res.updatedAt; cur._editRev = res.updatedAt; }
      if (UI.currentView === 'lead') updateLeadSaveUI('saved', Date.now());
    } else if (res == null && UI.currentView === 'lead') {
      // Сбой сети (Net.req вернул null/ошибку без success) — честно показываем «не сохранено»
      updateLeadSaveUI('dirty');
    }
    return res;
  };
  const job = _leadSaveChain.then(run, run);
  _leadSaveChain = job.catch(() => {});
  return job;
}

const saveLeadDebounced = debounce(() => saveLeadForm(false), 500);

let _lastBoardHash = null;
// Сброс кэша рендера доски (вместо кросс-файловой записи в _lastBoardHash из app.js):
// зовётся при входе/выходе и смене просматриваемого сотрудника, чтобы renderBoard не съел рендер.
function resetBoardCache() { _lastBoardHash = null; }

// Чипы тегов лида (канбан-карточка и карточка лида). Цвет задаётся CSS-классом
// .tag-c-<hex> (см. app.css): CSP style-src 'self' запрещает inline-атрибут style,
// поэтому style="background:..." браузер отбрасывал — чипы оставались без фона.
// Класс существует только для цветов палитры → мусор из БД сводится к дефолту.
function tagColorClass(color) {
  const c = String(color || '').toLowerCase();
  return 'tag-c-' + (TAG_COLORS.includes(c) ? c.slice(1) : '6366f1');
}

function tagChipsHtml(tags, extraClass = '') {
  if (!tags || !tags.length) return '';
  const chips = tags.map(t =>
    `<span class="tag-chip ${tagColorClass(t.color)} ${extraClass}">${esc(t.name)}</span>`
  ).join('');
  return `<div class="tag-chips">${chips}</div>`;
}

function leadTagsOf(id) {
  const m = Store.state.leadTags || {};
  return m[String(id)] || [];
}

function renderBoard() {
  if (UI.currentView !== 'kanban') return;
  const dataHash = JSON.stringify([Store.state.stages, Store.state.leads.filter(l => !l._deleted).map(l => [l.id, l.stage, l.title, l.logistPhone, l.manager, l.inn, l.applicationsCount, leadTagsOf(l.id)])]);
  if (dataHash === _lastBoardHash) return; _lastBoardHash = dataHash;

  const board = $('#board'); if (!board) return; board.innerHTML = ''; const frag = document.createDocumentFragment();
  Store.state.stages.forEach(stage => {
    const leads = Store.state.leads.filter(l => l.stage === stage && !l._deleted);
    const col = document.createElement('div'); col.className = 'column'; col.dataset.stage = stage; col.draggable = true;
    col.innerHTML = `<div class="column-header"><div class="col-title"><span>${esc(stage)}</span><span class="col-edit" data-action="edit-stage">✎</span></div><span class="col-count">${leads.length}</span></div><div class="cards-container"></div>`;
    const cont = col.querySelector('.cards-container');
    leads.forEach(l => {
      const card = document.createElement('div'); card.className = 'card'; card.draggable = true; card.dataset.id = l.id;
      const innLine = l.inn ? `<span>ИНН ${esc(l.inn)}</span>` : '<span></span>';
      const nApps = Number(l.applicationsCount || 0);
      const appsLine = nApps ? `<div class="card-apps">${nApps} ${appsWord(nApps)}</div>` : '';
      card.innerHTML = `${tagChipsHtml(leadTagsOf(l.id))}<div class="card-title">${esc(l.title)}</div><div class="card-meta">${innLine}<span>📱 ${esc(l.logistPhone || 'Нет')}</span></div>${appsLine}`;
      cont.appendChild(card);
    });
    frag.appendChild(col);
  });
  const add = document.createElement('div'); add.className = 'add-column'; add.textContent = '+ Добавить этап'; add.dataset.action = 'add-stage'; frag.appendChild(add);
  board.appendChild(frag);
}

/* === ОБЩИЙ РЕЕСТР «КЛИЕНТЫ» (§34) === */
// Все лиды всех менеджеров, но БЕЗ контактов: название + ИНН + владелец на hover.
// Свои карточки кликабельны (mine с сервера), чужие — статичны.
let _clientsCache = [];
let _clientsTotal = 0;

async function loadClients() {
  const res = await Net.req('get_clients');
  _clientsCache = res?.success ? (res.clients || []) : [];
  _clientsTotal = res?.success ? (res.total || 0) : 0;
  renderClients();
}

function clientIconSvg(title) {
  const isPerson = /^ип\s/i.test((title || '').trim());
  if (isPerson) return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="7.5" r="3.5"/><path d="M12 12.8c-4 0-7 2-7 4.5V19h14v-1.7c0-2.5-3-4.5-7-4.5z"/></svg>';
  return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5 3h11a1 1 0 0 1 1 1v17h-2.5v-2.5h-6V21H4V4a1 1 0 0 1 1-1zM7 7h2v2H7zM11 7h2v2h-2zM7 11h2v2H7zM11 11h2v2h-2z"/></svg>';
}

function renderClients() {
  if (UI.currentView !== 'clients') return;
  const grid = $('#clients-grid'); if (!grid) return;
  const q = ($('#clients-search')?.value || '').trim().toLowerCase();
  const list = _clientsCache.filter(c => !q || c.title.toLowerCase().includes(q) || c.inn.toLowerCase().includes(q));
  grid.innerHTML = list.map(c => {
    const innLine = c.inn ? `ИНН ${esc(c.inn)}` : '';
    const ownerTip = c.mine ? `${c.owner} (ваш лид)` : c.owner;
    return `<div class="client-card${c.mine ? ' mine' : ''}" data-id="${esc(c.id)}" data-owner="${esc(ownerTip)}"><div class="client-icon">${clientIconSvg(c.title)}</div><div class="client-body"><div class="client-title">${esc(c.title)}</div><div class="client-inn">${innLine}</div></div></div>`;
  }).join('');
  const cnt = $('#clients-count');
  if (cnt) cnt.textContent = _clientsTotal > _clientsCache.length ? `Показаны ${_clientsCache.length} из ${_clientsTotal}` : `Всего: ${_clientsTotal}`;
}

function initClientsEvents() {
  $('#clients-search')?.addEventListener('input', renderClients);
  $('#clients-search-clear')?.addEventListener('click', () => { const i = $('#clients-search'); if (i) { i.value = ''; renderClients(); } });
  $('#clients-grid')?.addEventListener('click', e => {
    const card = e.target.closest('.client-card.mine');
    if (card) openLead(card.dataset.id, true);
  });
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
  let c;
  if (UI.currentView === 'carrier') c = (UI.carrierComments || []).find(x => String(x.id) === String(UI.editingCommentId));
  else if (UI.currentView === 'app') c = (UI.appComments || []).find(x => String(x.id) === String(UI.editingCommentId));
  else {
    const L = Store.getLead(UI.leadId);
    c = L && Array.isArray(L.comments) ? L.comments.find(x => String(x.id) === String(UI.editingCommentId)) : null;
  }
  return (c && c.attachments) ? c.attachments.length : 0;
}

function renderFiles() {
  const editBox = $('.inline-editor.active .edit-files-preview');
  const box = editBox || (UI.currentView === 'carrier' ? $('#carrier-files-preview') : UI.currentView === 'app' ? $('#app-files-preview') : $('#files-preview'));
  const list = editBox ? (UI.editFiles || []) : UI.pendingFiles;
  if (!box) return; box.innerHTML = '';
  list.forEach((f, i) => {
    const el = document.createElement('div'); el.className = 'file-chip';
    el.innerHTML = `<span>📎 ${esc(f.name)}</span> <span class="chip-remove" data-action="rm-file" data-idx="${i}">×</span>`;
    box.appendChild(el);
  });
}

async function loadLeadComments(id) {
  const lead = Store.getLead(id);
  if (!lead) return;
  const res = await Net.req('get_comments', { id });
  if (res && res.success) lead.comments = res.comments || [];
}

function persistOk(res) {
  if (res == null) return true;
  return res.success !== false;
}

/* === СТРАНИЦА ЗАЯВКИ (v20) === */
// Подписи статусов — зеркало серверного crm_app_statuses (db.php); при добавлении
// статуса править оба места. Индекс элемента = значение crm_lead_apps.status.
const APP_STATUS_LABELS = ['В работе', 'Машина загрузилась', 'Машина выгрузилась'];

async function openApp(id, updateHash = true) {
  if (UI.leadId && UI.formDirty) {
    const savedL = await saveLeadForm(true);
    if (!persistOk(savedL)) return;
    if (savedL && savedL.transferred) await Store.load(true);
  }
  if (UI.carrierId && UI.formDirty) {
    const savedC = await saveCarrierForm(true);
    if (!persistOk(savedC)) return;
  }
  if (UI.appId && UI.formDirty && UI.appId !== id) {
    const savedA = await saveAppForm(true);
    if (!persistOk(savedA)) return;
  }
  const res = await Net.req('get_app', { id });
  if (!res || !res.success || !res.application) {
    if (UI.appLeadId) { openLead(UI.appLeadId, updateHash); return; }
    goHome(updateHash); return;
  }
  // Повторный вход на ту же заявку с несохранёнными правками (обновление по поллингу
  // или повторный клик): форму не трогаем, иначе ввод затирался бы серверной версией.
  // Ревизия при этом остаётся старой — следующее сохранение честно конфликтнёт.
  // Лог при этом живой, как в лиде: обновляем независимо от грязи формы.
  if (UI.formDirty && UI.appId === id) { await refreshAppLog(); return; }
  UI.leadId = null; UI.routeId = null; UI.carrierId = null; UI.carrierComments = [];
  UI.appId = id; UI.currentView = 'app';
  UI.pendingFiles = []; UI.formDirty = false; UI.editingCommentId = null;
  const a = res.application;
  UI.appRev = a.updatedAt;
  UI.appLeadId = a.leadId;
  UI.appLeadTitle = res.leadTitle || '';
  $$('.view-section').forEach(el => el.classList.remove('active'));
  $('#nav-dashboard')?.classList.remove('active');
  $('#app-view').classList.add('active');
  fillAppForm(a, res);
  await loadAppComments(id);
  renderAppLog();
  updateAppSaveUI('saved');
  if (updateHash) navTo('app/' + encodeURIComponent(id));
}

function updateAppCrumb(a) {
  const route = [a.cityFrom, a.cityTo].filter(Boolean).join(' → ');
  const num = a.number ? `№ ${a.number}` : 'Без номера';
  $('#app-crumb').textContent = route ? `${num} · ${route}` : num;
  $('#app-lead-crumb').textContent = UI.appLeadTitle || 'Лид';
}

function fillAppForm(a, res) {
  if (!a) return;
  const set = (sel, v) => { const el = $(sel); if (el && document.activeElement !== el) el.value = v ?? ''; };
  set('#ap-number', a.number || '');
  set('#ap-cust-company', res?.leadTitle || '');
  set('#ap-cust-inn', res?.leadInn || '');
  set('#ap-cust-name', res?.leadLogistName || '');
  set('#ap-cust-phone', res?.leadLogistPhone || '');
  set('#ap-from', a.cityFrom || '');
  set('#ap-to', a.cityTo || '');
  set('#ap-rate', moneyToInput(a.rate || ''));
  set('#ap-margin', moneyToInput(a.margin || ''));
  // ??, а не ||: НДС 0% — валидный режим и не должен падать в «Без НДС» (как в модалке).
  const vat = $('#ap-vat'); if (vat) vat.value = a.vat ?? '';
  set('#ap-carrier-rate', moneyToInput(a.carrierRate || ''));
  const cvat = $('#ap-carrier-vat'); if (cvat) cvat.value = a.carrierVat ?? '';
  set('#ap-company', a.carrierCompany || '');
  set('#ap-inn', a.carrierInn || '');
  set('#ap-name', a.carrierName || '');
  set('#ap-phone', a.carrierPhone || '');
  set('#ap-load-address', a.loadAddress || '');
  set('#ap-load-contact', a.loadContact || '');
  set('#ap-load-date-from', a.loadDateFrom || '');
  set('#ap-load-date-to', a.loadDateTo || '');
  set('#ap-load-time', a.loadTime || '');
  set('#ap-unload-address', a.unloadAddress || '');
  set('#ap-unload-contact', a.unloadContact || '');
  set('#ap-unload-date-from', a.unloadDateFrom || '');
  set('#ap-unload-date-to', a.unloadDateTo || '');
  set('#ap-unload-time', a.unloadTime || '');
  setupPhoneMask($('#ap-phone'));
  updateAppCrumb(a);
  renderAppStatus(a.status);
}

// Переключатель статуса — теми же кнопками, что этапы лида (renderDetailStages).
function renderAppStatus(status) {
  const row = $('#app-status-row'); if (!row) return;
  row.innerHTML = '';
  APP_STATUS_LABELS.forEach((label, i) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'stage-btn' + (Number(status) === i ? ' active' : '');
    b.dataset.action = 'set-app-status';
    b.dataset.status = String(i);
    b.textContent = label;
    row.appendChild(b);
  });
}

function updateAppSaveUI(state, ts = 0) {
  renderSaveStatus($('#app-save-status'), $('#btn-save-app'), state, ts);
}

// Лог заявки (v20, очередь 3): как loadLeadComments, но хранилище — UI.appComments
// (у перевозчика так же: UI.carrierComments). Системные записи рисует общий рендер.
async function loadAppComments(id) {
  if (!id) return;
  const res = await Net.req('get_app_comments', { id });
  if (res && res.success) UI.appComments = res.comments || [];
}

function renderAppLog() { renderLogInto($('#app-chatter-log'), UI.appComments || []); }

// Обновление лога после действий, пишущих системные записи (смена статуса,
// сохранение формы): данные — всегда, перерисовка — кроме момента правки
// комментария (renderLogInto пересоздаёт редактор с серверным текстом
// и съел бы набранное; лог подтянется следующим поллингом).
async function refreshAppLog() {
  if (!UI.appId) return;
  await loadAppComments(UI.appId);
  if (UI.currentView === 'app' && !UI.editingCommentId) renderAppLog();
}

let _appSaveChain = Promise.resolve();

// Сохранение карточки заявки: цепочка, оптимистическая блокировка по UI.appRev.
// Клиентская валидация — как в модалке (маршрут + ИНН; деньги проверяет сервер).
async function saveAppForm(_sync = false, keepalive = false) {
  if (!UI.appId) return null;
  const run = async () => {
    if (!UI.appId) return null;
    if (UI.currentView === 'app') updateAppSaveUI('saving');
    const from = ($('#ap-from').value || '').trim();
    const to = ($('#ap-to').value || '').trim();
    if (!from || !to) {
      Toast.error('Укажите откуда и куда');
      if (UI.currentView === 'app') updateAppSaveUI('dirty');
      return { success: false, error: 'Укажите откуда и куда' };
    }
    const inn = ($('#ap-inn').value || '').replace(/\D/g, '');
    if (inn && inn.length !== 10 && inn.length !== 12) {
      Toast.error('ИНН 10 или 12 цифр');
      if (UI.currentView === 'app') updateAppSaveUI('dirty');
      return { success: false, error: 'ИНН 10 или 12 цифр' };
    }
    const patch = {
      id: UI.appId,
      number: ($('#ap-number').value || '').trim(),
      cityFrom: from,
      cityTo: to,
      rate: ($('#ap-rate').value || '').trim(),
      margin: ($('#ap-margin').value || '').trim(),
      vat: $('#ap-vat').value,
      carrierRate: ($('#ap-carrier-rate').value || '').trim(),
      carrierVat: $('#ap-carrier-vat').value,
      carrierCompany: ($('#ap-company').value || '').trim(),
      carrierInn: inn,
      carrierName: ($('#ap-name').value || '').trim(),
      carrierPhone: ($('#ap-phone').value || '').trim(),
      loadAddress: ($('#ap-load-address').value || '').trim(),
      loadContact: ($('#ap-load-contact').value || '').trim(),
      loadDateFrom: ($('#ap-load-date-from').value || '').trim(),
      loadDateTo: ($('#ap-load-date-to').value || '').trim(),
      loadTime: ($('#ap-load-time').value || '').trim(),
      unloadAddress: ($('#ap-unload-address').value || '').trim(),
      unloadContact: ($('#ap-unload-contact').value || '').trim(),
      unloadDateFrom: ($('#ap-unload-date-from').value || '').trim(),
      unloadDateTo: ($('#ap-unload-date-to').value || '').trim(),
      unloadTime: ($('#ap-unload-time').value || '').trim(),
      updatedAt: UI.appRev
    };
    const extra = keepalive ? { keepalive: true } : {};
    const res = await Net.req('save_app', patch, false, extra);
    if (res && res.success === false && res.error === 'Заявка изменена в другом месте') {
      Toast.error('Заявку изменили в другой вкладке — обновляю');
      // Форма сейчас совпадёт с сервером — грязь снимаем ДО перечитывания,
      // иначе openApp пропустит заливку (защита от затирания ввода выше).
      UI.formDirty = false;
      if (UI.appId) await openApp(UI.appId, false);
      return res;
    }
    if (res && res.success && res.application) {
      UI.formDirty = false;
      UI.appRev = res.application.updatedAt;
      syncLeadAppCache(UI.appLeadId, res);
      updateAppCrumb(res.application);
      // Сохранение могло записать системные записи (ставки/перевозчик) —
      // подтягиваем лог сразу, без обновления страницы.
      await refreshAppLog();
      if (UI.currentView === 'app') updateAppSaveUI('saved', Date.now());
    } else if (res && res.success === false) {
      Toast.error(res.error || 'Ошибка');
      if (UI.currentView === 'app') updateAppSaveUI('dirty');
    } else if (res == null && UI.currentView === 'app') {
      // Сбой сети — честно показываем «не сохранено»
      updateAppSaveUI('dirty');
    }
    return res;
  };
  const job = _appSaveChain.then(run, run);
  _appSaveChain = job.catch(() => {});
  return job;
}

const saveAppDebounced = debounce(() => saveAppForm(false), 500);

// Копия заявки в кэше лида (Store) после сохранения/статуса со страницы: назад по
// back-app открывается без перечитывания (ensureLeadFull), и список заявок лида
// должен быть свежим. Ответы save_app/set_app_status/delete_lead_app несут всё нужное.
function syncLeadAppCache(leadId, res) {
  const lead = Store.getLead(leadId);
  if (!lead || !res) return;
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

function removeLeadAppCache(leadId, appId, res) {
  const lead = Store.getLead(leadId);
  if (lead) lead.applications = leadAppsOf(lead).filter(a => String(a.id) !== String(appId));
  syncLeadAppCache(leadId, res);
}
