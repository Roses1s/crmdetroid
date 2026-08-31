'use strict';

const http = require('http');
const fs = require('fs');
const os = require('os');
const path = require('path');
const mysql = require('mysql2/promise');
const { createServer } = require('../site/api/http');

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'crm-api-'));
const dbCfg = {
  host: process.env.CRM_DB_HOST || '127.0.0.1',
  port: Number(process.env.CRM_DB_PORT || 3306),
  user: process.env.CRM_DB_USER || 'root',
  password: process.env.CRM_DB_PASS || '',
  database: process.env.CRM_DB_NAME || 'crm_detroid_test',
};

let passed = 0, failed = 0;
function check(name, cond) {
  if (cond) { passed++; console.log('  ✅', name); }
  else { failed++; console.log('  ❌', name); }
}

function request(port, { method = 'GET', path: p = '/', headers = {}, body = null, cookie = '' }) {
  return new Promise((resolve, reject) => {
    const req = http.request({
      hostname: '127.0.0.1', port, method, path: p,
      headers: {
        ...(body ? { 'Content-Length': Buffer.byteLength(body) } : {}),
        ...(cookie ? { Cookie: cookie } : {}),
        ...headers,
      },
    }, res => {
      const chunks = [];
      res.on('data', c => chunks.push(c));
      res.on('end', () => {
        const raw = Buffer.concat(chunks).toString('utf8');
        const setCookie = res.headers['set-cookie'] || [];
        const sid = (setCookie.join(';').match(/CRMSESSID=([^;]+)/) || [])[1] || '';
        let json = null;
        try { json = JSON.parse(raw); } catch { /* not json */ }
        resolve({ status: res.statusCode, raw, json, cookie: sid ? `CRMSESSID=${sid}` : cookie, headers: res.headers });
      });
    });
    req.on('error', reject);
    if (body) req.write(body);
    req.end();
  });
}

function jsonReq(port, action, { method, cookie, csrf, body } = {}) {
  const q = `/api.php?action=${encodeURIComponent(action)}`;
  return request(port, {
    method: method || (body ? 'POST' : 'GET'),
    path: q,
    cookie,
    headers: {
      ...(body ? { 'Content-Type': 'application/json' } : {}),
      ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
    },
    body: body ? JSON.stringify(body) : null,
  });
}

(async () => {
  try {
    const conn = await mysql.createConnection({
      host: dbCfg.host, port: dbCfg.port, user: dbCfg.user, password: dbCfg.password,
    });
    await conn.query(`DROP DATABASE IF EXISTS \`${dbCfg.database}\``);
    await conn.query(`CREATE DATABASE \`${dbCfg.database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
    await conn.end();
  } catch (e) {
    console.log('SKIP API MySQL-тесты: нет сервера MySQL (' + e.message + ')');
    console.log('На SpaceWeb укажите CRM_DB_* в site/config.php — таблицы создадутся сами.');
    process.exit(0);
  }

  const server = createServer({
    rootDir: path.join(__dirname, '..', 'site'),
    uploadDir: path.join(tmp, 'uploads'),
    mysqlHost: dbCfg.host,
    mysqlPort: dbCfg.port,
    mysqlUser: dbCfg.user,
    mysqlPassword: dbCfg.password,
    mysqlDatabase: dbCfg.database,
  });

  await new Promise(res => server.listen(0, '127.0.0.1', res));
  const port = server.address().port;
  console.log('\n=== API с нуля, порт', port, '===');

  console.log('\n--- Авторизация ---');
  let r = await jsonReq(port, 'check_auth');
  check('Без сессии: need_login', r.json && r.json.need_login === true);

  r = await jsonReq(port, 'login', { body: { email: 'admin@detroid.local', password: 'wrong' } });
  check('Неверный пароль', r.json && r.json.success === false);

  r = await jsonReq(port, 'login', { body: { email: 'admin@detroid.local', password: 'admin123' } });
  check('Успешный вход', r.json && r.json.success === true && r.json.csrf && r.json.user.role === 'admin');
  const cookie = r.cookie;
  const csrf = r.json.csrf;

  r = await jsonReq(port, 'check_auth', { cookie });
  check('check_auth после входа', r.json && r.json.success && r.json.user.email === 'admin@detroid.local');

  r = await jsonReq(port, 'get_data', { cookie });
  check('get_data: 6 стадий', r.json && r.json.success && r.json.stages.length === 6);
  check('get_data: пустые лиды', r.json && Array.isArray(r.json.leads) && r.json.leads.length === 0);
  const hash = r.json.hash;

  r = await jsonReq(port, 'get_data', { cookie });
  // second call without hash in URL - jsonReq doesn't pass hash. manually:
  r = await request(port, { path: `/api.php?action=get_data&hash=${hash}`, cookie });
  check('get_data unchanged', r.json && r.json.unchanged === true);

  console.log('\n--- CSRF ---');
  r = await jsonReq(port, 'save_lead', { cookie, body: { id: 'l_x', title: 'X' } });
  check('POST без CSRF отклонён', r.json && r.json.success === false && r.json.error === 'CSRF');

  console.log('\n--- Лиды ---');
  r = await jsonReq(port, 'save_lead', { cookie, csrf, body: { id: 'l_test', title: 'ООО Тест', inn: '7700000000', phone: '+7 (900) 000-00-00', email: 'a@b.c', manager: 'Администратор', stage: 'Новый', applicationsCount: 0 } });
  check('Создание лида', r.json && r.json.success);

  r = await jsonReq(port, 'get_data', { cookie });
  const lead = r.json.leads.find(l => l.id === 'l_test');
  check('Лид в выдаче', !!lead && lead.title === 'ООО Тест');
  check('Системный комментарий «создан»', lead && lead.comments.some(c => c.author === 'Система'));

  r = await jsonReq(port, 'save_lead', { cookie, csrf, body: { id: 'l_test', title: 'ООО Тест 2', inn: '7700000001' } });
  r = await jsonReq(port, 'get_data', { cookie });
  check('Обновление лида', r.json.leads[0].title === 'ООО Тест 2' && r.json.leads[0].inn === '7700000001');

  r = await jsonReq(port, 'move_lead', { cookie, csrf, body: { id: 'l_test', stage: 'Вышел на ЛПР', from: 'Новый' } });
  check('Перенос лида', r.json && r.json.success);
  r = await jsonReq(port, 'get_data', { cookie });
  check('Стадия сменилась', r.json.leads[0].stage === 'Вышел на ЛПР');
  check('Комментарий о смене стадии', r.json.leads[0].comments.some(c => String(c.text).includes('Вышел на ЛПР')));

  console.log('\n--- Комментарии и файлы ---');
  r = await jsonReq(port, 'add_comment', { cookie, csrf, body: { lead_id: 'l_test', text: 'привет' } });
  // add_comment expects multipart, JSON body won't have fields.lead_id
  check('Комментарий JSON без multipart — пустой lead_id', r.json && r.json.success === false);

  const boundary = '----testharnesstest';
  const mp = [
    `--${boundary}\r\nContent-Disposition: form-data; name="lead_id"\r\n\r\nl_test\r\n`,
    `--${boundary}\r\nContent-Disposition: form-data; name="text"\r\n\r\nКомментарий с XSS <script>\r\n`,
    `--${boundary}\r\nContent-Disposition: form-data; name="files[]"; filename="note.txt"\r\nContent-Type: text/plain\r\n\r\nhello\r\n`,
    `--${boundary}--\r\n`,
  ].join('');
  r = await request(port, {
    method: 'POST',
    path: '/api.php?action=add_comment',
    cookie,
    headers: { 'Content-Type': `multipart/form-data; boundary=${boundary}`, 'X-CSRF-Token': csrf },
    body: mp,
  });
  check('Комментарий с файлом', r.json && r.json.success);
  r = await jsonReq(port, 'get_data', { cookie });
  const cmt = r.json.leads[0].comments.find(c => c.text.includes('XSS'));
  check('Текст комментария сохранён', !!cmt);
  check('Вложение сохранено', cmt && cmt.attachments[0] && cmt.attachments[0].dataUrl.startsWith('uploads/'));
  const fileUrl = '/' + cmt.attachments[0].dataUrl;
  const fileRes = await request(port, { path: fileUrl });
  check('Файл отдаётся статикой', fileRes.raw === 'hello');

  r = await jsonReq(port, 'edit_comment', { cookie, csrf, body: { id: cmt.id, text: 'исправлено' } });
  check('Редактирование комментария', r.json && r.json.success);
  r = await jsonReq(port, 'get_data', { cookie });
  const edited = r.json.leads[0].comments.find(c => c.id === cmt.id);
  check('editedAt проставлен', !!edited.editedAt && edited.text === 'исправлено');

  r = await jsonReq(port, 'delete_comment', { cookie, csrf, body: { id: cmt.id } });
  check('Удаление комментария', r.json && r.json.success);

  console.log('\n--- Этапы ---');
  r = await jsonReq(port, 'save_stages', { cookie, csrf, body: { stages: ['Новый', 'В работе', 'Готово'] } });
  check('Сохранение этапов', r.json && r.json.success);
  r = await jsonReq(port, 'get_data', { cookie });
  check('Этапы обновлены', r.json.stages.join('|') === 'Новый|В работе|Готово');
  check('Лид с неизвестной стадией перенесён', r.json.leads[0].stage === 'Новый');

  console.log('\n--- Сотрудники ---');
  r = await jsonReq(port, 'register_user', { cookie, csrf, body: { name: 'Иван', email: 'ivan@detroid.local', password: 'secret1' } });
  check('Регистрация сотрудника', r.json && r.json.success);
  r = await jsonReq(port, 'get_users', { cookie });
  check('Список: 2 пользователя', r.json.users && r.json.users.length === 2);
  const ivan = r.json.users.find(u => u.email === 'ivan@detroid.local');

  r = await jsonReq(port, 'update_user', { cookie, csrf, body: { id: ivan.id, name: 'Иван П.', email: 'ivan@detroid.local', password: '' } });
  check('Обновление имени', r.json && r.json.success);

  r = await jsonReq(port, 'delete_user', { cookie, csrf, body: { id: 1 } });
  check('Нельзя удалить админа id=1', r.json && r.json.success === false);

  r = await jsonReq(port, 'delete_user', { cookie, csrf, body: { id: ivan.id } });
  check('Удаление сотрудника', r.json && r.json.success);

  r = await jsonReq(port, 'register_user', { cookie, csrf, body: { name: 'X', email: 'bad', password: 'secret1' } });
  check('Плохой email отклонён', r.json && r.json.success === false);

  console.log('\n--- Права менеджера ---');
  r = await jsonReq(port, 'register_user', { cookie, csrf, body: { name: 'Менеджер', email: 'm@detroid.local', password: 'secret1' } });
  const loginM = await jsonReq(port, 'login', { body: { email: 'm@detroid.local', password: 'secret1' } });
  check('Вход менеджера', loginM.json && loginM.json.success && loginM.json.user.role === 'user');
  const mc = loginM.cookie, mcsrf = loginM.json.csrf;
  r = await jsonReq(port, 'get_users', { cookie: mc });
  check('Менеджер не видит сотрудников', r.json && r.json.success === false);
  r = await jsonReq(port, 'get_data', { cookie: mc });
  check('Менеджер не видит чужие лиды', r.json && r.json.success && r.json.leads.length === 0);
  check('У менеджера свои этапы по умолчанию', r.json && r.json.stages.length === 6);
  r = await jsonReq(port, 'save_stages', { cookie: mc, csrf: mcsrf, body: { stages: ['A', 'B'] } });
  check('Менеджер меняет свои этапы', r.json && r.json.success);
  r = await jsonReq(port, 'get_data', { cookie: mc });
  check('Этапы менеджера свои', r.json && r.json.stages.join('|') === 'A|B');
  r = await jsonReq(port, 'get_data', { cookie });
  check('Этапы админа не затронуты', r.json && r.json.stages.join('|') === 'Новый|В работе|Готово');

  r = await jsonReq(port, 'delete_lead', { cookie, csrf, body: { id: 'l_test' } });
  check('Удаление лида', r.json && r.json.success);
  r = await jsonReq(port, 'get_data', { cookie });
  check('Лид исчез', r.json.leads.length === 0);

  r = await jsonReq(port, 'logout', { cookie });
  check('Выход', r.json && r.json.success);
  r = await jsonReq(port, 'get_data', { cookie });
  check('После выхода сессия мертва', r.json && r.json.need_login === true);

  r = await jsonReq(port, 'nope');
  check('Неизвестное действие', r.json && r.json.error === 'Неизвестное действие');

  server.close();
  console.log(`\nИТОГО API: ${passed} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(2); });
