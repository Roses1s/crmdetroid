'use strict';

const { randomId } = require('./crypto');

function parseCookies(req) {
  const out = {};
  const raw = req.headers.cookie || '';
  for (const part of raw.split(';')) {
    const i = part.indexOf('=');
    if (i <= 0) continue;
    const k = part.slice(0, i).trim();
    const v = part.slice(i + 1).trim();
    try { out[k] = decodeURIComponent(v); } catch { out[k] = v; }
  }
  return out;
}

function createSessions(config) {
  const map = new Map();

  function get(req) {
    prune();
    const sid = parseCookies(req)[config.cookieName];
    if (sid && map.has(sid)) {
      const rec = map.get(sid);
      rec.seen = Date.now();
      return rec;
    }
    return { id: null, userId: null, csrf: null, seen: Date.now() };
  }

  function create(userId) {
    const rec = {
      id: randomId(16),
      userId,
      csrf: randomId(16),
      seen: Date.now(),
    };
    map.set(rec.id, rec);
    return rec;
  }

  function destroy(req) {
    const sid = parseCookies(req)[config.cookieName];
    if (sid) map.delete(sid);
  }

  function attach(res, rec) {
    if (!rec || !rec.id) {
      res.setHeader('Set-Cookie', `${config.cookieName}=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0`);
      return;
    }
    res.setHeader('Set-Cookie', `${config.cookieName}=${rec.id}; Path=/; HttpOnly; SameSite=Lax`);
  }

  function prune() {
    const now = Date.now();
    for (const [id, rec] of map) {
      if (now - rec.seen > config.sessionTtlMs) map.delete(id);
    }
  }

  return { get, create, destroy, attach, _map: map };
}

module.exports = { createSessions, parseCookies };
