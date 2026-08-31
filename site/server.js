#!/usr/bin/env node
'use strict';

const { createServer } = require('./api/http');

const PORT = Number(process.env.PORT || 8080);
const HOST = process.env.HOST || '0.0.0.0';

const server = createServer();
server.listen(PORT, HOST, () => {
  console.log(`CRM Детроид API: http://${HOST}:${PORT}/`);
});
