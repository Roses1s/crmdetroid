'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');
const { URL } = require('url');
const { createConfig } = require('./config');
const { createStore } = require('./store');
const { createSessions } = require('./session');
const { createActions, createLimiter } = require('./actions');
const { ApiError } = require('./errors');
const { parseMultipart, readBody, parseJson } = require('./multipart');

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.txt': 'text/plain; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.svg': 'image/svg+xml',
  '.pdf': 'application/pdf',
  '.zip': 'application/zip',
  '.csv': 'text/csv; charset=utf-8',
  '.doc': 'application/msword',
  '.docx': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  '.xls': 'application/vnd.ms-excel',
  '.xlsx': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
};

const PUBLIC_GET = new Set(['check_auth', 'login', 'logout', 'get_data', 'get_users', 'search_leads']);
const PUBLIC_NO_AUTH = new Set(['login', 'logout', 'check_auth']);
const CSRF_EXEMPT = new Set(['login', 'logout', 'check_auth', 'get_data', 'get_users', 'search_leads']);

function json(res, obj) {
  if (res.headersSent) return;
  res.statusCode = 200;
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.setHeader('Cache-Control', 'no-store');
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.end(JSON.stringify(obj));
}

function createApp(overrides = {}) {
  const config = createConfig(overrides);
  const store = createStore(config);
  const sessions = createSessions(config);
  const limiter = createLimiter(config);
  const actions = createActions({ store, sessions, config, limiter });

  async function handleApi(req, res) {
    const url = new URL(req.url, 'http://localhost');
    const action = url.searchParams.get('action') || '';
    const session = sessions.get(req);
    const ct = req.headers['content-type'] || '';

    try {
      await store.ready();
      if (!actions[action]) {
        json(res, { success: false, error: 'Неизвестное действие' });
        return;
      }

      const isPost = req.method === 'POST';
      if (isPost && !CSRF_EXEMPT.has(action)) {
        const sent = req.headers['x-csrf-token'] || '';
        if (!session.csrf || !sent || sent !== session.csrf) {
          json(res, { success: false, error: 'CSRF' });
          return;
        }
      }

      let body = {};
      let fields = {};
      let files = [];
      if (isPost) {
        const buf = await readBody(req, config.maxUploadBytes * 6 + 1024 * 1024);
        if (ct.includes('multipart/form-data')) {
          const mp = parseMultipart(buf, ct);
          fields = mp.fields;
          files = mp.files;
        } else {
          body = parseJson(buf);
        }
      }

      const result = await actions[action]({
        req, res, url, session, body, fields, files, store, config,
      });
      json(res, { success: true, ...(result || {}) });
    } catch (e) {
      if (e instanceof ApiError && e.needLogin) {
        json(res, { success: false, error: e.message, need_login: true });
        return;
      }
      const message = e instanceof ApiError ? e.message : 'Серверная ошибка';
      if (!(e instanceof ApiError)) console.error(e);
      json(res, { success: false, error: message });
    }
  }

  function safeJoin(urlPath) {
    const decoded = decodeURIComponent((urlPath || '/').split('?')[0]);
    const rel = decoded.replace(/^\/+/, '') || 'index.html';
    const abs = path.normalize(path.join(config.rootDir, rel));
    if (!abs.startsWith(config.rootDir)) return null;
    if (abs === config.dataDir || abs.startsWith(config.dataDir + path.sep)) return null;
    if (abs.includes(path.sep + 'api' + path.sep) || abs.endsWith(path.sep + 'api')) return null;
    const base = path.basename(abs).toLowerCase();
    if (['server.js', 'config.php', 'api.php', '.htaccess', 'package.json'].includes(base)) return null;
    if (path.extname(abs).toLowerCase() === '.js') return null;
    return abs;
  }

  function serveStatic(req, res, urlPath) {
    const decoded = decodeURIComponent((urlPath || '/').split('?')[0]);
    if (decoded === '/uploads' || decoded.startsWith('/uploads/')) {
      const name = path.basename(decoded);
      if (!name || name === 'uploads' || name.startsWith('.')) {
        res.writeHead(403); res.end('Forbidden'); return;
      }
      const absUpload = path.normalize(path.join(config.uploadDir, name));
      if (!absUpload.startsWith(config.uploadDir) || !fs.existsSync(absUpload) || !fs.statSync(absUpload).isFile()) {
        res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
        res.end('Not found');
        return;
      }
      const ext = path.extname(absUpload).toLowerCase();
      res.writeHead(200, { 'Content-Type': MIME[ext] || 'application/octet-stream' });
      fs.createReadStream(absUpload).pipe(res);
      return;
    }
    let abs = safeJoin(urlPath);
    if (!abs) { res.writeHead(403); res.end('Forbidden'); return; }
    if (fs.existsSync(abs) && fs.statSync(abs).isDirectory()) abs = path.join(abs, 'index.html');
    if (!fs.existsSync(abs) || !fs.statSync(abs).isFile()) {
      res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end('Not found');
      return;
    }
    const ext = path.extname(abs).toLowerCase();
    res.writeHead(200, { 'Content-Type': MIME[ext] || 'application/octet-stream' });
    fs.createReadStream(abs).pipe(res);
  }

  async function handler(req, res) {
    try {
      const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
      if (url.pathname === '/api.php' || url.pathname === '/api.php/') {
        await handleApi(req, res);
        return;
      }
      if (url.pathname === '/') {
        serveStatic(req, res, '/index.html');
        return;
      }
      serveStatic(req, res, url.pathname);
    } catch (e) {
      console.error(e);
      if (!res.headersSent) json(res, { success: false, error: 'Серверная ошибка' });
    }
  }

  handler.config = config;
  handler.store = store;
  handler.sessions = sessions;
  return handler;
}

function createServer(overrides) {
  const handler = createApp(overrides);
  return http.createServer(handler);
}

module.exports = { createApp, createServer, PUBLIC_GET, PUBLIC_NO_AUTH };
