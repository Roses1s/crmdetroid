'use strict';
// Утилиты без доменной логики: DOM-хелперы, экранирование, форматирование, маски ввода, Toast/Loading/Modal, prompt/confirm. Вынесено из app.js (план CODE_REVIEW п. 10.9). Файлы js/ — обычные скрипты (не модули): top-level объявления видны всем следующим <script> (порядок — в index.html).
/* global UI */
/* exported Loading, Theme, Toast, askConfirm, askPrompt, autoGrowComposer, closeImageLightbox, debounce, fmtBytes, fmtMoney, fmtTime, formatInnInput, formatMarginInput, isImageAtt, isValidEmail, moneyToInput, openImageLightbox, passwordError, plural, renderSaveStatus, safeAttUrl, setupPhoneMask, withLock */

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

// Полноценное экранирование HTML-атрибутов (раньше использовался encodeURI, который не кодирует '&',
// что позволяло инъекцию через HTML-entities). Та же функция, что esc — для двойных кавычек достаточно.
const escAttr = s => esc(s);

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

/**
 * Проверка URL вложения: принимаем только URL, которые генерирует сервер (api.php?action=file).
 * Defense-in-depth: даже если серверный формат сломается, javascript:/data: URL не попадут в DOM.
 */
function safeAttUrl(raw) {
  const s = String(raw || '');
  if (s.startsWith('api.php?action=file&f=') || s.startsWith('api.php?action=file&amp;f=')) return escAttr(s);
  return '';
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

/**
 * Автовысота textarea в духе composer'а Odoo: поле начинается с одной строки
 * и растёт под текст до max-height из CSS (дальше — внутренняя прокрутка).
 * Вызывать при input и после программной очистки поля (сброс к одной строке).
 */
function autoGrowComposer(el) {
  if (!el) return;
  el.style.height = 'auto';
  el.style.height = el.scrollHeight + 'px';
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

// Loading overlay: показывается при первичной загрузке и долгих операциях (#2).
// Анти-дрожжание: показываем не сразу, а через 300ms — быстрые запросы не мерцают.
const Loading = {
  _timer: null, _count: 0,
  show() {
    this._count++;
    if (this._timer) return;
    this._timer = setTimeout(() => {
      const el = $('#loading-overlay');
      if (el) { el.classList.add('show'); el.setAttribute('aria-hidden', 'false'); }
    }, 300);
  },
  hide() {
    this._count = Math.max(0, this._count - 1);
    if (this._count > 0) return;
    if (this._timer) { clearTimeout(this._timer); this._timer = null; }
    const el = $('#loading-overlay');
    if (el) { el.classList.remove('show'); el.setAttribute('aria-hidden', 'true'); }
  }
};

// Focus-trap для модалок (#4): Tab/Shift+Tab циклически ходят внутри открытой модалки.
function trapFocus(modalEl) {
  const focusable = modalEl.querySelectorAll('input, select, textarea, button, [tabindex]:not([tabindex="-1"])');
  if (!focusable.length) return;
  const first = focusable[0], last = focusable[focusable.length - 1];
  first.focus();
  modalEl._trapHandler = (e) => {
    if (e.key !== 'Tab') return;
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  };
  modalEl.addEventListener('keydown', modalEl._trapHandler);
}

function releaseFocusTrap(modalEl) {
  if (modalEl && modalEl._trapHandler) {
    modalEl.removeEventListener('keydown', modalEl._trapHandler);
    delete modalEl._trapHandler;
  }
}

const Modal = {
  open(id) {
    const el = $('#'+id);
    if (!el) return;
    el.classList.add('open');
    // A11y (#4): role="dialog", aria-modal, focus-trap
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    const title = el.querySelector('h2');
    if (title && !el.hasAttribute('aria-labelledby')) {
      if (!title.id) title.id = id + '-title';
      el.setAttribute('aria-labelledby', title.id);
    }
    setTimeout(() => trapFocus(el), 50);
  },
  close(id) {
    const el = $('#'+id);
    if (!el) return;
    el.classList.remove('open');
    releaseFocusTrap(el);
  },
  closeAll() {
    $$('.modal-backdrop').forEach(m => { m.classList.remove('open'); releaseFocusTrap(m); });
  }
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

function plural(n, one, few, many) {
  const m10 = n % 10, m100 = n % 100;
  if (m10 === 1 && m100 !== 11) return one;
  if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return few;
  return many;
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

function withLock(fn) { return async (...args) => { if (UI.lock) return; UI.lock = true; try { await fn(...args); } finally { UI.lock = false; } }; }

/*
 * Общий рендер статуса сохранения карточки (лид и перевозчик):
 * «● Есть изменения» / «Сохраняю…» / «✓ Сохранено HH:MM». Кнопка активна только в dirty.
 */
function renderSaveStatus(el, btn, state, ts = 0) {
  if (!el || !btn) return;
  el.classList.remove('dirty', 'saving', 'saved');
  if (state === 'dirty') {
    el.classList.add('dirty');
    el.textContent = '● Есть изменения';
    btn.disabled = false;
  } else if (state === 'saving') {
    el.classList.add('saving');
    el.textContent = 'Сохраняю…';
    btn.disabled = true;
  } else { // 'saved'
    el.classList.add('saved');
    const t = ts ? new Date(ts).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) : '';
    el.textContent = '✓ Сохранено' + (t ? ' ' + t : '');
    btn.disabled = true;
  }
}
