'use strict';

const { fail } = require('./errors');

function str(value, { max = 300, fallback = '' } = {}) {
  let s = value == null ? '' : String(value);
  s = s.replace(/\u0000/g, '').trim();
  if (s.length > max) s = s.slice(0, max);
  return s || fallback;
}

function email(value, { required = false } = {}) {
  const s = str(value, { max: 120 }).toLowerCase();
  if (!s) {
    if (required) throw fail('Укажите e-mail');
    return '';
  }
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s)) throw fail('Некорректный email');
  return s;
}

function inn(value) {
  const digits = String(value || '').replace(/\D/g, '').slice(0, 12);
  return digits;
}

function password(value, { required = true } = {}) {
  const s = String(value || '');
  if (!s) {
    if (required) throw fail('Укажите пароль');
    return '';
  }
  if (s.length < 6) throw fail('Пароль мин. 6 символов');
  if (s.length > 200) throw fail('Пароль слишком длинный');
  return s;
}

function nonNegInt(value) {
  const n = parseInt(value, 10);
  if (!Number.isFinite(n) || n < 0) return 0;
  return Math.min(n, 1e9);
}

function idStr(value) {
  const s = str(value, { max: 80 });
  if (!s) throw fail('Нет id');
  return s;
}

module.exports = { str, email, inn, password, nonNegInt, idStr };
