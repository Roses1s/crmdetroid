#!/usr/bin/env node
'use strict';

const { createServer } = require('./api/http');

const PORT = Number(process.env.PORT || 8080);
const HOST = process.env.HOST || '0.0.0.0';

const server = createServer();
server.listen(PORT, HOST, () => {
  const email = process.env.CRM_ADMIN_EMAIL || 'admin@detroid.local';
  const pass = process.env.CRM_ADMIN_PASS || 'admin123';
  console.log(`CRM Детроид API: http://${HOST}:${PORT}/`);
  console.log(`Логин: ${email} / ${pass}`);
});
