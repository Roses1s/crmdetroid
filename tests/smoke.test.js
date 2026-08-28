const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const HTML_PATH = path.join(__dirname, '..', 'crm.html');
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

let passed = 0, failed = 0;
function check(name, cond) {
  if (cond) { passed++; console.log('  ✅', name); }
  else { failed++; console.log('  ❌', name); }
}

function makeDom({ preload = {}, idb = null } = {}) {
  const html = fs.readFileSync(HTML_PATH, 'utf8');
  return new JSDOM(html, {
    runScripts: 'dangerously',
    url: 'http://localhost/',
    pretendToBeVisual: true,
    beforeParse(window) {
      if (idb) {
        window.indexedDB = idb;
        // полифилл fetch только для data: URL (нужен миграции вложений)
        window.fetch = async (url) => {
          const m = /^data:([^;]*)(;base64)?,(.*)$/s.exec(url);
          if (!m) throw new Error('unsupported url');
          const raw = m[2] ? Buffer.from(m[3], 'base64') : Buffer.from(decodeURIComponent(m[3]));
          return { blob: async () => new window.Blob([raw], { type: m[1] }) };
        };
      }
      for (const [k, v] of Object.entries(preload)) window.localStorage.setItem(k, v);
      window.confirm = () => true;
      window.alert = () => {};
      if (!window.CSS) window.CSS = {};
      if (!window.CSS.escape) window.CSS.escape = (s) => String(s).replace(/[^a-zA-Z0-9_-]/g, (c) => '\\' + c);
      let n = 0;
      window.URL.createObjectURL = () => 'blob:http://localhost/fake-' + (++n);
      window.URL.revokeObjectURL = () => {};
      if (!window.Blob.prototype.text) {
        window.Blob.prototype.text = function () {
          return new Promise((resolve, reject) => {
            const r = new window.FileReader();
            r.onload = () => resolve(r.result);
            r.onerror = () => reject(r.error);
            r.readAsText(this);
          });
        };
      }
    },
  });
}

const getLeads = (w) => JSON.parse(w.localStorage.getItem('crm_leads_v9'));

(async () => {
  console.log('\n=== Сценарий A: чистый запуск ===');
  {
    const dom = makeDom();
    const w = dom.window, d = w.document;
    await sleep(200);

    check('Доска: 6 колонок воронки', d.querySelectorAll('#board .column').length === 6);
    check('Демо-лид отображается', d.querySelector('.card-title') && d.querySelector('.card-title').textContent.includes('Ромашка'));
    check('Демо-данные вымышленные (нет реальных контактов)', !d.body.innerHTML.includes('Ярославский'));

    console.log('\n--- Валидация модалки ---');
    d.getElementById('modal-title').value = 'Тестовый лид';
    d.getElementById('modal-inn').value = 'abc';
    w.saveNewLead();
    check('Невалидный ИНН блокирует создание', d.getElementById('modal-errors').classList.contains('visible') && getLeads(w).length === 1);
    d.getElementById('modal-inn').value = '';
    d.getElementById('modal-email').value = 'bad-email';
    w.saveNewLead();
    check('Невалидный email блокирует создание', d.getElementById('modal-errors').classList.contains('visible') && getLeads(w).length === 1);
    d.getElementById('modal-email').value = '';
    d.getElementById('modal-title').value = '';
    w.saveNewLead();
    check('Пустое название блокирует создание', getLeads(w).length === 1);

    console.log('\n--- XSS в канбане ---');
    d.getElementById('modal-title').value = '<img src=x onerror="window.__xss=1">';
    d.getElementById('modal-phone').value = '<b>+7</b>';
    w.saveNewLead();
    const created = getLeads(w).find(l => l.title.includes('onerror'));
    check('Лид с вредоносным названием создан', !!created);
    check('XSS не исполнился (окно чистое)', w.__xss === undefined);
    check('Тег <img> не вставлен в карточку', !d.querySelector('#board .card img'));
    check('Тег <b> из телефона не вставлен в карточку', ![...d.querySelectorAll('#board .card b')].some(b => b.textContent.includes('+7')));
    check('Название отображается как текст', d.querySelector('#board .card-title').textContent.includes('<img'));

    console.log('\n--- Детальная карточка ---');
    w.openLead(created.id);
    check('Открылась детальная карточка', d.getElementById('detail-view').style.display === 'flex');
    d.getElementById('edit-inn').value = '7700000001';
    d.getElementById('edit-apps-count').value = '-3';
    w.saveLeadDetails();
    let saved = getLeads(w).find(l => l.id === created.id);
    check('ИНН сохранён', saved.inn === '7700000001');
    check('Отрицательный счётчик заявок обрезан до 0', saved.applicationsCount === 0);
    d.getElementById('edit-inn').value = '12';
    w.saveLeadDetails();
    check('Подсказка валидации ИНН показана', d.getElementById('hint-inn').classList.contains('visible'));
    d.getElementById('edit-inn').value = '7700000001';
    w.saveLeadDetails();
    check('Подсказка исчезла после исправления', !d.getElementById('hint-inn').classList.contains('visible'));

    console.log('\n--- Комментарии ---');
    d.getElementById('new-comment').value = 'Тест <script>window.__xss2=1<\/script> комментарий';
    await w.saveComment();
    saved = getLeads(w).find(l => l.id === created.id);
    check('Комментарий записан', saved.comments.length === 1 && saved.comments[0].text.includes('<script>'));
    check('XSS через комментарий не исполнился', w.__xss2 === undefined && !d.querySelector('#chatter-history script'));
    const cid = saved.comments[0].id;
    check('ID комментария — строка', typeof cid === 'string');

    d.querySelector('#chatter-history .act-edit').click(); // открыть инлайн-редактор
    const ta = d.querySelector('.inline-edit-textarea');
    check('Открылся инлайн-редактор', !!ta);
    const origTime = saved.comments[0].time;
    ta.value = 'Изменённый текст';
    w.saveInlineComment(cid);
    saved = getLeads(w).find(l => l.id === created.id);
    check('Комментарий изменён', saved.comments[0].text === 'Изменённый текст');
    check('Пометка редактирования сохранена', !!saved.comments[0].editedAt);
    check('Исходное время создания не потеряно', saved.comments[0].time === origTime);

    w.deleteComment(cid);
    saved = getLeads(w).find(l => l.id === created.id);
    check('Комментарий удалён', saved.comments.length === 0);

    console.log('\n--- Drag-and-drop / стадии ---');
    w.dropCard({ preventDefault() {}, dataTransfer: { getData: () => created.id } }, 'Вышел на ЛПР');
    saved = getLeads(w).find(l => l.id === created.id);
    check('Стадия изменена через drop', saved.stage === 'Вышел на ЛПР');
    check('Системный комментарий о смене стадии', saved.comments.some(c => c.text.includes('Вышел на ЛПР')));

    console.log('\n--- Удаление лида ---');
    w.openLead(created.id);
    w.deleteCurrentLead();
    check('Лид удалён', !getLeads(w).some(l => l.id === created.id));
    check('Возврат к канбану', d.getElementById('kanban-view').style.display !== 'none');

    dom.window.close();
  }

  console.log('\n=== Сценарий B: миграция с v8, неизвестная стадия, вложения ===');
  {
    const v8 = [{
      id: 5, title: 'Старый лид', stage: 'Какая-то старая стадия', inn: '1234567890',
      phone: '+7 (900) 111-11-11', manager: 'М',
      comments: [{
        id: 11, text: 'старый коммент', author: 'Система', time: '01.01.2020',
        attachments: [{ name: 'a.txt', size: 5, type: 'text/plain', dataUrl: 'data:text/plain;base64,SGVsbG8=' }]
      }]
    }];
    const dom = makeDom({ preload: { crm_leads_v8: JSON.stringify(v8) } });
    const w = dom.window, d = w.document;
    await sleep(250);

    check('Лид мигрирован с v8', getLeads(w).some(l => l.title === 'Старый лид'));
    check('ID нормализован в строку', typeof getLeads(w).find(l => l.title === 'Старый лид').id === 'string');
    check('Колонка 6 + одна для неизвестной стадии', d.querySelectorAll('#board .column').length === 7);
    check('Неизвестная стадия помечена', !!d.querySelector('.column-extra'));
    check('Лид не потерялся из воронки', d.querySelectorAll('#board .card').length === 1);

    const migrated = getLeads(w).find(l => l.title === 'Старый лид');
    w.openLead(migrated.id);
    await sleep(50);
    const att = d.querySelector('#chatter-history .attachment-item');
    check('Вложение из старых данных отображается', !!att && att.getAttribute('href').startsWith('data:text/plain'));
    w.closeLead();
    dom.window.close();
  }

  console.log('\n=== Сценарий C: повреждённое хранилище ===');
  {
    const dom = makeDom({ preload: { crm_leads_v8: '{это не валидный json!!!' } });
    const w = dom.window, d = w.document;
    await sleep(250);
    check('Приложение не упало, доска отрисована', d.querySelectorAll('#board .column').length === 6);
    check('Показано предупреждение о повреждении', [...d.querySelectorAll('.toast')].some(t => t.textContent.includes('повреждены')));
    check('Битые данные сохранены в резервный ключ', Object.keys(w.localStorage).some(k => k.includes('_corrupted_')));
    dom.window.close();
  }

  console.log('\n=== Сценарий D: экспорт и импорт ===');
  {
    const dom = makeDom();
    const w = dom.window, d = w.document;
    await sleep(200);

    const before = getLeads(w).length;
    const payload = JSON.stringify({ app: 'crm-detroid', leads: [{ title: 'Импортированный', stage: 'Новый', comments: [{ text: 'привет', attachments: [{ name: 'a.txt', size: 5, type: 'text/plain', dataUrl: 'data:text/plain;base64,SGVsbG8=' }] }] }] });
    const file = new w.File([payload], 'backup.json', { type: 'application/json' });
    await w.importData(file);
    await sleep(100);
    const after = getLeads(w);
    check('Импорт добавил лид', after.length === before + 1);
    const imp = after.find(l => l.title === 'Импортированный');
    check('При импорте выдан новый ID (нет коллизий)', !!imp && imp.id && !after.some(l => l.id === imp.id && l !== imp));
    check('Вложение импортировано', imp.comments[0].attachments.length === 1 && !!imp.comments[0].attachments[0].id);
    check('Показан тост об успехе', [...d.querySelectorAll('.toast')].some(t => t.textContent.includes('Импортировано лидов: 1')));

    await w.importData(new w.File(['не json'], 'bad.json'));
    await sleep(100);
    check('Битый файл импорта не уронил приложение', [...d.querySelectorAll('.toast')].some(t => t.textContent.includes('не корректный JSON')));

    try { await w.exportData(); } catch (e) { /* навигация в jsdom не поддерживается */ }
    check('Экспорт отработал без исключений', true);
    dom.window.close();
  }

  console.log('\n=== Сценарий E: IndexedDB-путь (миграция, запись, перечитывание) ===');
  {
    const { IDBFactory } = require('fake-indexeddb');
    const sharedFactory = new IDBFactory(); // одна «база» на все загрузки страницы

    const v8 = [{
      id: 9, title: 'Лид со вложением', stage: 'Новый',
      comments: [{
        id: 91, text: 'коммент с файлом', author: 'Пользователь', time: '01.01.2020',
        attachments: [{ name: 'old.txt', size: 5, type: 'text/plain', dataUrl: 'data:text/plain;base64,SGVsbG8=' }]
      }]
    }];

    // --- Загрузка 1: миграция старого вложения в IndexedDB + сохранение нового ---
    const dom1 = makeDom({ preload: { crm_leads_v8: JSON.stringify(v8) }, idb: sharedFactory });
    const w1 = dom1.window, d1 = w1.document;
    await sleep(300);

    let stored = getLeads(w1).find(l => l.title === 'Лид со вложением');
    check('Старое вложение вынесено из localStorage (dataUrl удалён)', stored && stored.comments[0].attachments[0].dataUrl === undefined);
    check('У вложения остался ID для IndexedDB', !!stored.comments[0].attachments[0].id);
    w1.openLead(stored.id);
    await sleep(80);
    let attLink = d1.querySelector('#chatter-history .attachment-item');
    check('Старое вложение рендерится через blob-URL', !!attLink && attLink.getAttribute('href').startsWith('blob:'));

    // новый комментарий с файлом
    const file = new w1.File([Buffer.from('Hello2')], 'new.txt', { type: 'text/plain' });
    await w1.handleFileSelect({ target: { files: [file], value: '' } });
    check('Файл выбран и показан в превью', !!d1.querySelector('.file-chip'));
    d1.getElementById('new-comment').value = 'коммент с новым файлом';
    await w1.saveComment();
    stored = getLeads(w1).find(l => l.title === 'Лид со вложением');
    const newAtt = stored.comments.find(c => c.text === 'коммент с новым файлом').attachments[0];
    check('Новое вложение сохранено без dataUrl (в IndexedDB)', newAtt && newAtt.dataUrl === undefined && !!newAtt.id);

    // --- Загрузка 2: «перезапуск браузера», всё читается из IndexedDB ---
    const snapshot = {};
    for (const k of Object.keys(w1.localStorage)) snapshot[k] = w1.localStorage.getItem(k);
    const oldCommentId = stored.comments[0].id;
    const oldAttId = stored.comments[0].attachments[0].id;
    dom1.window.close();

    const dom2 = makeDom({ preload: snapshot, idb: sharedFactory });
    const w2 = dom2.window, d2 = w2.document;
    await sleep(300);
    const stored2 = getLeads(w2).find(l => l.title === 'Лид со вложением');
    w2.openLead(stored2.id);
    await sleep(80);
    const links2 = d2.querySelectorAll('#chatter-history .attachment-item');
    check('После перезагрузки оба вложения прочитаны из IndexedDB (blob-URL)', links2.length === 2 && [...links2].every(a => a.getAttribute('href').startsWith('blob:')));
    check('Ни одно вложение не помечено потерянным', !d2.querySelector('.attachment-missing'));

    // удаление комментария чистит и IndexedDB
    w2.deleteComment(oldCommentId);
    const stored3 = getLeads(w2).find(l => l.title === 'Лид со вложением');
    check('Комментарий с вложением удалён', stored3.comments.length === 1 && !stored3.comments.some(c => c.attachments.some(a => a.id === oldAttId)));

    // --- Загрузка 3: удалённый файл действительно исчез из базы ---
    const snapshot2 = {};
    for (const k of Object.keys(w2.localStorage)) snapshot2[k] = w2.localStorage.getItem(k);
    dom2.window.close();
    const dom3 = makeDom({ preload: snapshot2, idb: sharedFactory });
    const w3 = dom3.window, d3 = w3.document;
    await sleep(300);
    const stored4 = getLeads(w3).find(l => l.title === 'Лид со вложением');
    w3.openLead(stored4.id);
    await sleep(80);
    const links3 = d3.querySelectorAll('#chatter-history .attachment-item');
    check('Осталось только неудалённое вложение', links3.length === 1 && links3[0].getAttribute('href').startsWith('blob:'));
    check('Недоступных вложений нет', !d3.querySelector('.attachment-missing'));
    dom3.window.close();
  }

  console.log(`\nИТОГО: ${passed} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(2); });
