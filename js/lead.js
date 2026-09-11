'use strict';
// Лиды: доска (renderBoard), карточка лида, заявки лида (модалка, статистика), лог комментариев с вложениями (общий рендер renderLogInto используют и перевозчики), автосохранение формы. Вынесено из app.js (план CODE_REVIEW п. 10.9).
/* global $, $$, Modal, Net, Store, Toast, UI, appsWord, askConfirm, debounce, esc, fmtBytes, fmtMoney, fmtTime, goHome, isImageAtt, isValidEmail, moneyNum, moneyToInput, navTo, renderSaveStatus, safeAttUrl, setupPhoneMask */
/* exported addTagFromModal, closeLeadAppModal, deleteLeadApp, deleteTagFromModal, editingCommentAttCount, goNeighborLead, openLeadAppModal, openLeadTagsModal, pickTagColor, renderBoard, resetBoardCache, saveLeadAppFromModal, submitLeadTags, toggleTagInModal, updateLeadSaveUI */

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
  updateLeadSaveUI('saved');
  $$('.view-section').forEach(el => el.classList.remove('active'));
  // Синхронизируем подсветку навигации: карточка лида относится к разделу «Лиды»
  // (иначе при открытии лида из поиска дашборда кнопка дашборда оставалась подсвеченной)
  $$('.nav-item').forEach(el => el.classList.remove('active'));
  $('#nav-dashboard')?.classList.remove('active');
  $('#nav-leads')?.classList.add('active');
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
  // updatedAt для оптимистической блокировки: если заявку изменили в другой вкладке,
  // сервер ответит «Заявка изменена в другом месте» вместо молчаливой перезаписи.
  const editApp = ($('#la-id').value || '').trim()
    ? leadAppsOf(Store.getLead(UI.leadId)).find(a => String(a.id) === String($('#la-id').value || ''))
    : null;
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
    `<span class="tag-color${c === _tagModalColor ? ' selected' : ''}" data-action="pick-tag-color" data-color="${c}" style="background:${c}" role="button" aria-label="Цвет ${c}"></span>`
  ).join('') + `<button type="button" class="btn btn-secondary btn-sm" data-action="add-tag">+ Добавить</button>`;
}

function renderTagList() {
  const list = $('#tags-list');
  if (!list) return;
  const tags = Store.state.tags || [];
  if (!tags.length) { list.innerHTML = '<div class="tags-empty">Тегов пока нет — создайте первый ниже</div>'; return; }
  list.innerHTML = tags.map(t => {
    const on = _tagModalSelected.has(Number(t.id));
    const c = /^#[0-9a-f]{6}$/i.test(String(t.color || '')) ? t.color : '#6366f1';
    return `<div class="tag-row">
      <span class="tag-chip tag-pick${on ? ' selected' : ''}" data-action="toggle-tag" data-id="${t.id}" style="background:${c}" role="checkbox" aria-checked="${on}">${on ? '✓ ' : ''}${esc(t.name)}</span>
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

let _leadSaveChain = Promise.resolve();

// См. saveCarrierForm: цепочка _leadSaveChain, первый аргумент ни на что не влияет.
async function saveLeadForm(_sync = false, keepalive = false, transferTo = 0) {
  if (!UI.leadId) return null; const lead = Store.getLead(UI.leadId); if (!lead || !lead._full) return null;
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

// Чипы тегов лида (канбан-карточка и карточка лида). Цвет приходит из фиксированной
// палитры (сервер отбрасывает произвольные строки — см. crm_tag_color), но на всякий
// случай в style попадает только строгий hex.
function tagChipsHtml(tags, extraClass = '') {
  if (!tags || !tags.length) return '';
  const chips = tags.map(t => {
    const c = /^#[0-9a-f]{6}$/i.test(String(t.color || '')) ? t.color : '#6366f1';
    return `<span class="tag-chip ${extraClass}" style="background:${c}">${esc(t.name)}</span>`;
  }).join('');
  return `<div class="tag-chips">${chips}</div>`;
}

function leadTagsOf(id) {
  const m = Store.state.leadTags || {};
  return m[String(id)] || [];
}

function renderBoard() {
  if (UI.currentView !== 'kanban') return;
  const dataHash = JSON.stringify([Store.state.stages, Store.state.leads.map(l => [l.id, l.stage, l.title, l.logistPhone, l.manager, l.inn, l.applicationsCount, leadTagsOf(l.id)])]);
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
      card.innerHTML = `${tagChipsHtml(leadTagsOf(l.id))}<div class="card-title">${esc(l.title)}</div><div class="card-meta">${innLine}<span>📱 ${esc(l.logistPhone || 'Нет')}</span></div>${appsLine}`;
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
  let c;
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
