'use strict';

const path = require('path');

function createConfig(overrides = {}) {
  const root = overrides.rootDir || path.join(__dirname, '..');
  const dataDir = overrides.dataDir || process.env.CRM_DATA_DIR || path.join(root, 'data');
  const uploadDir = overrides.uploadDir || process.env.CRM_UPLOAD_DIR || path.join(root, 'uploads');

  return {
    rootDir: root,
    dataDir,
    uploadDir,
    mysql: {
      host: overrides.mysqlHost || process.env.CRM_DB_HOST || 'localhost',
      port: Number(overrides.mysqlPort || process.env.CRM_DB_PORT || 3306),
      user: overrides.mysqlUser || process.env.CRM_DB_USER || 'crm_detroid',
      password: overrides.mysqlPassword || process.env.CRM_DB_PASS || 'CHANGE_ME',
      database: overrides.mysqlDatabase || process.env.CRM_DB_NAME || 'crm_detroid',
    },
    cookieName: 'CRMSESSID',
    maxUploadBytes: 5 * 1024 * 1024,
    maxCommentChars: 20000,
    maxTitleChars: 200,
    maxStageChars: 80,
    maxFieldChars: 300,
    sessionTtlMs: 7 * 24 * 3600 * 1000,
    loginWindowMs: 15 * 60 * 1000,
    loginMaxFails: 8,
    adminEmail: (process.env.CRM_ADMIN_EMAIL || 'admin@detroid.local').toLowerCase(),
    adminPassword: process.env.CRM_ADMIN_PASS || 'admin123',
    adminName: process.env.CRM_ADMIN_NAME || 'Администратор',
    defaultStages: [
      'Новый',
      'Вышел на ЛПР',
      'Потенциальный клиент',
      'Сделали просчет',
      'Разместили заявку',
      'Уехали, ждем заявку',
    ],
    allowedUploadExt: new Set([
      '.png', '.jpg', '.jpeg', '.gif', '.webp', '.bmp',
      '.pdf', '.txt', '.csv', '.doc', '.docx', '.xls', '.xlsx',
      '.ppt', '.pptx', '.zip', '.7z',
    ]),
  };
}

module.exports = { createConfig };
