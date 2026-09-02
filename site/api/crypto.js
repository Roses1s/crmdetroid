'use strict';

const crypto = require('crypto');
const bcrypt = require('bcryptjs');

function randomId(bytes = 16) {
  return crypto.randomBytes(bytes).toString('hex');
}

function hashPassword(password) {
  return bcrypt.hashSync(String(password), 10);
}

function verifyPassword(password, stored) {
  if (!stored || typeof stored !== 'string') return false;
  if (stored.startsWith('$2a$') || stored.startsWith('$2b$') || stored.startsWith('$2y$')) {
    try { return bcrypt.compareSync(String(password), stored); } catch { return false; }
  }
  if (stored.startsWith('scrypt$')) {
    const parts = stored.split('$');
    if (parts.length !== 3) return false;
    const [, salt, hash] = parts;
    const check = crypto.scryptSync(String(password), salt, 64);
    try { return crypto.timingSafeEqual(Buffer.from(hash, 'hex'), check); } catch { return false; }
  }
  if (stored.includes(':') && !stored.includes('$')) {
    const [salt, hash] = stored.split(':');
    const check = crypto.scryptSync(String(password), salt, 64);
    try { return crypto.timingSafeEqual(Buffer.from(hash, 'hex'), check); } catch { return false; }
  }
  return false;
}

function sha256(value) {
  return crypto.createHash('sha256').update(value).digest('hex');
}

module.exports = { randomId, hashPassword, verifyPassword, sha256 };
