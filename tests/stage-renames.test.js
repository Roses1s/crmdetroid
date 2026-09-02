'use strict';

const { stageRenames } = require('../site/api/store');

let passed = 0, failed = 0;
function check(name, cond) {
  if (cond) { passed++; console.log('  ✅', name); }
  else { failed++; console.log('  ❌', name); }
}
function same(a, b) {
  return JSON.stringify(a) === JSON.stringify(b);
}

console.log('=== Переименование стадий ===');
check('перестановка колонок не трогает лиды', same(stageRenames(['A', 'B'], ['B', 'A']), []));
check('три колонки: сдвиг', same(stageRenames(['A', 'B', 'C'], ['C', 'A', 'B']), []));
check('переименование одной', same(stageRenames(['Новый', 'B'], ['Новые', 'B']), [['Новый', 'Новые']]));
check('добавление этапа', same(stageRenames(['A', 'B'], ['A', 'B', 'C']), []));
check('удаление этапа', same(stageRenames(['A', 'B', 'C'], ['A', 'B']), []));
check('тот же список', same(stageRenames(['A', 'B'], ['A', 'B']), []));

console.log(`\nИТОГО: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
