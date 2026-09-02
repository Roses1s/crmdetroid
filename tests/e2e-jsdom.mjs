// E2E-проверки клиентской логики (js/app.js) в jsdom против локального тестового стенда.
//
// Нужно: node ≥ 20, `npm i jsdom` (в любом каталоге; путь к node_modules задаётся NODE_PATH),
// запущенный стенд с тестовой БД (см. tests/api-smoke.sh) и пользователи:
//   admin (ADMIN_EMAIL/ADMIN_PASS), сотрудник ivan@x.ru / IvanPass123 и второй сотрудник «Пётр Сидоров».
// Запуск из корня репозитория:
//   CRM_URL=http://127.0.0.1:8089 ADMIN_PASS='...' NODE_PATH=/tmp/e2e/node_modules node tests/e2e-jsdom.mjs
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
const require = createRequire(import.meta.url);
const { JSDOM, CookieJar } = require('jsdom');

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const BASE = (process.env.CRM_URL || 'http://127.0.0.1:8089').replace(/\/?$/, '/');
const SESS_DIR = process.env.CRM_SESSION_DIR || path.join(ROOT, 'data', 'sessions');
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'admin@detroid.local';
const ADMIN_PASS = process.env.ADMIN_PASS || '';
const html = fs.readFileSync(path.join(ROOT, 'index.html'), 'utf8').replace(/<script[^>]*src="[^"]*"[^>]*><\/script>/g, '');
const appJs = fs.readFileSync(path.join(ROOT, 'js', 'app.js'), 'utf8')
  + "\n;window.Store=Store;window.Net=Net;window.UI=UI;window.openLead=openLead;window.loadUsers=loadUsers;window.stopPolling=stopPolling;window.__pollActive=()=>!!pollTimer;";
const themeJs = fs.readFileSync(path.join(ROOT, 'js', 'theme.js'), 'utf8');

const results = [];
const ok = (name, cond, extra = '') => { results.push([cond ? 'PASS' : 'FAIL', name, extra]); };
const sleep = ms => new Promise(r => setTimeout(r, ms));

async function makeWindow(hash = '') {
  const jar = new CookieJar();
  const dom = new JSDOM(html, { url: BASE + hash, runScripts: 'outside-only', pretendToBeVisual: true, cookieJar: jar });
  const w = dom.window;
  // fetch с cookie-jar (jsdom не даёт fetch)
  w.fetch = async (url, opts = {}) => {
    const abs = new URL(url, BASE).href;
    const cookie = await jar.getCookieString(abs);
    const headers = { ...(opts.headers || {}), cookie };
    const r = await fetch(abs, { ...opts, headers, redirect: 'manual' });
    const setc = r.headers.getSetCookie ? r.headers.getSetCookie() : [];
    for (const c of setc) await jar.setCookie(c, abs).catch(() => {});
    return r;
  };
  w.confirm = () => true;
  w.scrollTo = () => {};
  w.HTMLElement.prototype.scrollIntoView = function () {};
  w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  w.eval(themeJs);
  w.eval(appJs);
  await sleep(50);
  return { w, dom, jar };
}

function autoConfirm(w) {
  const st = { count: 0 };
  st.timer = setInterval(() => {
    const btn = w.document.querySelector('#confirm-ok-btn');
    const open = w.document.querySelector('#modal-confirm')?.classList.contains('open');
    if (open && btn) { st.count++; btn.click(); }
  }, 30);
  return st;
}
async function loginAs(w, email, pass) {
  w.document.querySelector('#login-email').value = email;
  w.document.querySelector('#login-password').value = pass;
  w.document.querySelector('#btn-login').click();
  for (let i = 0; i < 100; i++) { await sleep(50); if (w.Store?.state?.user && !w.UI?.lock) break; }
  return w.Store.state.user;
}

async function api(jar, action, data, csrf, as) {
  const url = BASE + 'api.php?action=' + action + (as ? '&as=' + as : '');
  const cookie = await jar.getCookieString(url);
  const r = await fetch(url, { method: data ? 'POST' : 'GET', headers: { cookie, 'X-CSRF-Token': csrf || '', 'Content-Type': 'application/json' }, body: data ? JSON.stringify(data) : undefined });
  return r.json();
}

// ---------- 16: битый hash не роняет запуск ----------
{
  const { w } = await makeWindow('#lead/%E0');
  let user = await loginAs(w, 'ivan@x.ru', 'IvanPass123');
  ok('16 битый #lead/%E0: приложение запустилось и вошло', !!user && w.Store.state.leads.length > 0, `leads=${w.Store.state.leads.length}`);
  ok('16 polling запущен после битого hash', w.__pollActive());
  w.stopPolling();
}

// ---------- 5: повторный вход в той же вкладке ----------
{
  const { w, jar } = await makeWindow();
  const user = await loginAs(w, 'ivan@x.ru', 'IvanPass123');
  ok('5 первый вход', !!user);
  const n1 = w.Store.state.leads.length;
  const hash1 = w.Net.hash;
  // Сервер: убиваем сессию удалением cookie-файла — имитируем истёкшую сессию
  const cookies = await jar.getCookies(BASE);
  const sid = cookies.find(c => c.key === 'CRMSESSID')?.value;
  fs.rmSync(path.join(SESS_DIR, 'sess_' + sid), { force: true });
  // Любой запрос → need_login → handleLogoutUI
  await w.Store.load(false);
  await sleep(100);
  ok('5 после истечения — экран входа, hash сброшен', w.Store.state.user === null && w.Net.hash === null, `hash=${w.Net.hash}`);
  const user2 = await loginAs(w, 'ivan@x.ru', 'IvanPass123');
  ok('5 повторный вход в той же вкладке: доска загружена', !!user2 && w.Store.state.leads.length === n1, `leads=${w.Store.state.leads.length} (было ${n1})`);
  ok('5 шапка с именем не упала', (w.document.querySelector('#user-name')?.textContent || '').includes('Иван') || !!user2?.name);
  w.stopPolling?.();
}

// ---------- 21: передача только по полному имени ----------
{
  const { w, jar } = await makeWindow();
  await loginAs(w, 'ivan@x.ru', 'IvanPass123');
  const csrf = w.Net.csrf;
  const created = await api(jar, 'save_lead', { title: 'E2E передача' }, csrf);
  await w.Store.load(true);
  await w.openLead(created.id, false);
  await sleep(100);
  const fm = w.document.querySelector('#f-manager');
  ok('21 карточка открыта', !!fm && w.UI.leadId === created.id);
  // одна фамилия — НЕ передаёт
  const ac = autoConfirm(w); const confirmsOf = () => ac.count;
  fm.value = 'Сидоров'; fm.dispatchEvent(new w.Event('blur'));
  await sleep(400);
  const chk1 = await api(jar, 'get_lead&id=' + created.id, null, csrf);
  ok('21 «Сидоров» (одна фамилия) не передал лид', chk1.success === true && confirmsOf() === 0, `confirms=${confirmsOf()} manager=${chk1.lead?.manager}`);
  // полное имя — передаёт
  fm.value = 'пётр сидоров'; fm.dispatchEvent(new w.Event('blur'));
  await sleep(600);
  const chk2 = await api(jar, 'get_lead&id=' + created.id, null, csrf);
  ok('21 полное имя (без регистра) → передан', chk2.success === false && confirmsOf() === 1, `confirms=${confirmsOf()} resp=${JSON.stringify(chk2).slice(0, 60)}`);
  clearInterval(ac.timer);
  w.stopPolling?.();
}

// ---------- 1: удаление вложения шлёт kind ----------
{
  const { w, jar } = await makeWindow();
  await loginAs(w, 'ivan@x.ru', 'IvanPass123');
  const sent = [];
  const orig = w.Net.req.bind(w.Net);
  w.Net.req = async (a, d, f, e) => { if (a === 'delete_attachment') sent.push(d); return orig(a, d, f, e); };
  const ac1 = autoConfirm(w);
  // создаём лид и грузим в него PNG, чтобы тест не зависел от содержимого базы
  const csrf = w.Net.csrf;
  const made = await api(jar, 'save_lead', { title: 'E2E вложение' }, csrf);
  const fd = new FormData();
  fd.append('lead_id', made.id); fd.append('text', 'f');
  fd.append('files[]', new Blob([Uint8Array.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])], { type: 'image/png' }), 'e2e.png');
  const upUrl = BASE + 'api.php?action=add_comment';
  await fetch(upUrl, { method: 'POST', headers: { cookie: await jar.getCookieString(upUrl), 'X-CSRF-Token': csrf }, body: fd });
  let target = null;
  { const c = await api(jar, 'get_comments&id=' + made.id, null, csrf); const withAtt = (c.comments || []).find(x => (x.attachments || []).length); if (withAtt) target = { lead: made.id, att: withAtt.attachments[0].id }; }
  await w.Store.load(true);
  if (target) {
    await w.openLead(target.lead, false); await sleep(150);
    const btn = w.document.querySelector(`[data-action="del-att"][data-id="${target.att}"]`);
    ok('1 кнопка удаления вложения найдена', !!btn);
    btn?.click(); await sleep(400);
    ok('1 клиент отправил kind=lead', sent.length === 1 && sent[0].kind === 'lead', JSON.stringify(sent));
  } else ok('1 (не удалось загрузить вложение для теста)', false);
  await api(jar, 'delete_lead', { id: made.id }, csrf);
  clearInterval(ac1.timer);
  w.stopPolling?.();
}

// ---------- 13: пароль в таблице сотрудников уходит только при ручном вводе ----------
{
  const { w, jar } = await makeWindow();
  await loginAs(w, ADMIN_EMAIL, ADMIN_PASS);
  await w.loadUsers(); await sleep(200);
  const sent = [];
  const orig = w.Net.req.bind(w.Net);
  w.Net.req = async (a, d, f, e) => { if (a === 'update_user') { sent.push(d); return { success: true }; } return orig(a, d, f, e); };
  // любой сотрудник кроме самого админа
  const rowId = [...w.document.querySelectorAll('[data-action="save-user"]')].map(b => b.dataset.id).find(id => +id !== +w.Store.state.user.id);
  const pw = w.document.querySelector('#upass-' + rowId);
  ok('13 поле пароля имеет autocomplete=new-password', pw?.getAttribute('autocomplete') === 'new-password');
  pw.value = 'AutofilledPass1'; // как автозаполнение: без keydown
  pw.dispatchEvent(new w.Event('input'));
  w.document.querySelector(`[data-action="save-user"][data-id="${rowId}"]`).click(); await sleep(300);
  ok('13 автозаполненный пароль НЕ отправлен', sent.length === 1 && !('password' in sent[0]), JSON.stringify(sent[0]));
  // после сохранения таблица перерисована — берём поле заново
  const pw2 = w.document.querySelector('#upass-' + rowId);
  pw2.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'a' }));
  pw2.value = 'AutofilledPass1'; pw2.dispatchEvent(new w.Event('input'));
  w.document.querySelector(`[data-action="save-user"][data-id="${rowId}"]`).click(); await sleep(300);
  ok('13 набранный руками пароль отправлен', sent.length === 2 && sent[1].password === 'AutofilledPass1', JSON.stringify(sent[1]));
  w.stopPolling?.();
}

for (const [s, n, e] of results) console.log(s, '|', n, e ? '|' + e : '');
const fails = results.filter(r => r[0] === 'FAIL').length;
console.log(fails ? `\n${fails} FAILED` : '\nALL PASSED');
process.exit(fails ? 1 : 0);
