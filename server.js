#!/usr/bin/env node
'use strict';

console.error('Node-API больше не используется.');
console.error('Локально: php -S 0.0.0.0:8080 -t site');
console.error('На SpaceWeb залейте содержимое site/ в public_html (не перезаписывая живой config.php).');
process.exit(1);
