'use strict';

class ApiError extends Error {
  constructor(message, { needLogin = false } = {}) {
    super(message);
    this.name = 'ApiError';
    this.needLogin = needLogin;
  }
}

function fail(message, opts) {
  return new ApiError(message, opts);
}

function needLogin(message = 'Сессия истекла') {
  return new ApiError(message, { needLogin: true });
}

module.exports = { ApiError, fail, needLogin };
