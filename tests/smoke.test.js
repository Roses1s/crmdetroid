'use strict';

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const root = path.join(__dirname, '..');
let passed = 0, failed = 0;
function check(name, cond) {
  if (cond) { passed++; console.log('  ✅', name); }
  else { failed++; console.log('  ❌', name); }
}

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const isValidEmail = v => !v || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);

console.log('\n=== Файлы продакшена ===');
check('нет crm.html', !fs.existsSync(path.join(root, 'crm.html')));
check('есть site/index.html', fs.existsSync(path.join(root, 'site/index.html')));
check('есть site/app.css (канон)', fs.existsSync(path.join(root, 'site/app.css')));
check('нет дубля site/css/app.css', !fs.existsSync(path.join(root, 'site/css/app.css')));

const index = fs.readFileSync(path.join(root, 'site/index.html'), 'utf8');
const appjs = fs.readFileSync(path.join(root, 'site/js/app.js'), 'utf8');
const ui = fs.readFileSync(path.join(root, 'site/ui.html'), 'utf8');
const api = fs.readFileSync(path.join(root, 'site/api.php'), 'utf8');
const dbphp = fs.readFileSync(path.join(root, 'site/db.php'), 'utf8');
const ht = fs.readFileSync(path.join(root, 'site/.htaccess'), 'utf8');
const pkg = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));

check('CSP в index.html', index.includes("default-src 'self'") && index.includes("style-src 'self'"));
check('index подключает app.css, не css/app.css', index.includes('href="app.css') && !index.includes('css/app.css'));
check('isValidEmail используется', appjs.includes('if (!isValidEmail('));
check('зарезервированное имя', appjs.includes('isReservedUserName'));
check('beforeunload спрашивает', appjs.includes('e.returnValue'));
check('drag не открывает карточку', appjs.includes('dragSuppressUntil'));
check('npm start — PHP', String(pkg.scripts.start).includes('php -S'));
check('редирект HTTP→HTTPS как у SpaceWeb', ht.includes('RewriteCond %{HTTP:HTTPS} !=on') && ht.includes('https://%{SERVER_NAME}%{REQUEST_URI}'));
check('HSTS не из htaccess', !ht.includes('Strict-Transport-Security'));
check('Permissions-Policy в htaccess', ht.includes('Permissions-Policy'));
check('schema v8 заявки', dbphp.includes('CRM_SCHEMA_VERSION = 8') && dbphp.includes('crm_lead_apps'));
check('API save_lead_app', api.includes("case 'save_lead_app'") && api.includes("case 'delete_lead_app'"));
check('карточка: список заявок', ui.includes('data-action="new-lead-app"') && ui.includes('id="modal-lead-app"'));
check('счётчик заявок не поле ввода', ui.includes('<b id="f-apps">') && !ui.includes('id="f-apps" min'));

console.log('\n=== XSS / email ===');
check('esc экранирует тег', esc('<img src=x onerror=1>') === '&lt;img src=x onerror=1&gt;');
check('esc кавычки', esc(`a"b'c`) === 'a&quot;b&#39;c');
check('пустой email ок', isValidEmail(''));
check('нормальный email', isValidEmail('a@b.c'));
check('битый email', !isValidEmail('bad-email'));

const php = spawnSync('php', ['tests/php-smoke.php'], { cwd: root, encoding: 'utf8' });
if (php.error && php.error.code === 'ENOENT') {
  console.log('\n  ⚠ php нет в PATH — php-smoke пропущен');
} else {
  process.stdout.write(php.stdout || '');
  process.stderr.write(php.stderr || '');
  check('php-smoke exit 0', php.status === 0);
}

console.log(`\nИТОГО: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
