'use strict';

const fs = require('fs');
const path = require('path');
const mysql = require('mysql2/promise');
const { hashPassword, sha256, randomId } = require('./crypto');
const { fail } = require('./errors');

const MIGRATE = [
  `CREATE TABLE IF NOT EXISTS crm_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    email VARCHAR(120) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(16) NOT NULL DEFAULT 'user',
    created_at BIGINT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS crm_stages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    position INT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stage_name (name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS crm_leads (
    id VARCHAR(80) NOT NULL,
    title VARCHAR(200) NOT NULL,
    inn VARCHAR(12) NOT NULL DEFAULT '',
    phone VARCHAR(40) NOT NULL DEFAULT '',
    email VARCHAR(120) NOT NULL DEFAULT '',
    manager VARCHAR(80) NOT NULL DEFAULT '',
    cargo VARCHAR(300) NOT NULL DEFAULT '',
    format VARCHAR(300) NOT NULL DEFAULT '',
    payment VARCHAR(300) NOT NULL DEFAULT '',
    ati VARCHAR(300) NOT NULL DEFAULT '',
    applications_count INT NOT NULL DEFAULT 0,
    stage VARCHAR(80) NOT NULL,
    created_at BIGINT NOT NULL,
    PRIMARY KEY (id),
    KEY idx_stage (stage)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS crm_comments (
    id VARCHAR(80) NOT NULL,
    lead_id VARCHAR(80) NOT NULL,
    text MEDIUMTEXT NOT NULL,
    author VARCHAR(80) NOT NULL,
    time BIGINT NOT NULL,
    edited_at BIGINT NULL,
    PRIMARY KEY (id),
    KEY idx_lead (lead_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS crm_attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    comment_id VARCHAR(80) NOT NULL,
    name VARCHAR(255) NOT NULL,
    size INT UNSIGNED NOT NULL DEFAULT 0,
    type VARCHAR(120) NOT NULL DEFAULT '',
    data_url VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_comment (comment_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS crm_login_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(120) NOT NULL,
    attempted_at BIGINT NOT NULL,
    PRIMARY KEY (id),
    KEY idx_email_time (email, attempted_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
];

function stageRenames(old, ns) {
  const a = [...old].sort();
  const b = [...ns].sort();
  if (a.length === b.length && a.every((x, i) => x === b[i])) return [];
  const renames = [];
  if (old.length !== ns.length) return renames;
  for (let i = 0; i < old.length; i++) {
    const from = old[i];
    const to = ns[i];
    if (!to || to === from) continue;
    if (!old.includes(to) && !ns.includes(from)) renames.push([from, to]);
  }
  return renames;
}

function rowLead(r) {
  return {
    id: r.id,
    title: r.title,
    inn: r.inn,
    phone: r.phone,
    email: r.email,
    manager: r.manager,
    cargo: r.cargo,
    format: r.format,
    payment: r.payment,
    ati: r.ati,
    applicationsCount: Number(r.applications_count) || 0,
    stage: r.stage,
    createdAt: Number(r.created_at) || 0,
    comments: [],
  };
}

function createStore(config) {
  fs.mkdirSync(config.uploadDir, { recursive: true });
  let poolPromise = null;

  async function init() {
    try {
      const pool = mysql.createPool({
        host: config.mysql.host,
        port: config.mysql.port,
        user: config.mysql.user,
        password: config.mysql.password,
        database: config.mysql.database,
        waitForConnections: true,
        connectionLimit: 10,
        charset: 'utf8mb4',
      });
      for (const sql of MIGRATE) await pool.query(sql);
      const [users] = await pool.query('SELECT COUNT(*) AS n FROM crm_users');
      if (!Number(users[0].n)) {
        await pool.execute(
          'INSERT INTO crm_users (id, name, email, password, role, created_at) VALUES (1,?,?,?,?,?)',
          [config.adminName, config.adminEmail, hashPassword(config.adminPassword), 'admin', Date.now()]
        );
        try { await pool.query('ALTER TABLE crm_users AUTO_INCREMENT = 2'); } catch { /* ignore */ }
      }
      const [stages] = await pool.query('SELECT COUNT(*) AS n FROM crm_stages');
      if (!Number(stages[0].n)) {
        const ins = 'INSERT INTO crm_stages (name, position) VALUES (?,?)';
        for (let i = 0; i < config.defaultStages.length; i++) {
          await pool.execute(ins, [config.defaultStages[i], i]);
        }
      }
      return pool;
    } catch (e) {
      const msg = (e && (e.sqlMessage || e.message)) || '';
      throw fail('Не удалось подключиться к MySQL. Проверьте CRM_DB_* в config (' + msg + ')');
    }
  }

  function pool() {
    if (!poolPromise) poolPromise = init();
    return poolPromise;
  }

  function publicUser(u) {
    if (!u) return null;
    return { id: Number(u.id), name: u.name, email: u.email, role: u.role };
  }

  async function getUserById(id) {
    const p = await pool();
    const [rows] = await p.execute('SELECT * FROM crm_users WHERE id = ?', [id]);
    return rows[0] || null;
  }
  async function getUserByEmail(email) {
    const p = await pool();
    const [rows] = await p.execute('SELECT * FROM crm_users WHERE email = ?', [String(email).toLowerCase()]);
    return rows[0] || null;
  }
  async function listUsers() {
    const p = await pool();
    const [rows] = await p.query('SELECT id, name, email FROM crm_users ORDER BY id ASC');
    return rows.map(u => ({ id: Number(u.id), name: u.name, email: u.email }));
  }
  async function createUser({ name, email, password, role = 'user' }) {
    const p = await pool();
    const [res] = await p.execute(
      'INSERT INTO crm_users (name, email, password, role, created_at) VALUES (?,?,?,?,?)',
      [name, email, hashPassword(password), role, Date.now()]
    );
    return Number(res.insertId);
  }
  async function updateUser(id, { name, email, password }) {
    const p = await pool();
    await p.execute('UPDATE crm_users SET name = ?, email = ? WHERE id = ?', [name, email, id]);
    if (password) await p.execute('UPDATE crm_users SET password = ? WHERE id = ?', [hashPassword(password), id]);
  }
  async function deleteUser(id) {
    const p = await pool();
    const [res] = await p.execute('DELETE FROM crm_users WHERE id = ?', [id]);
    return res.affectedRows > 0;
  }

  async function listStages() {
    const p = await pool();
    const [rows] = await p.query('SELECT name FROM crm_stages ORDER BY position ASC, id ASC');
    return rows.map(r => r.name);
  }

  async function saveStages(ns) {
    const p = await pool();
    const conn = await p.getConnection();
    try {
      await conn.beginTransaction();
      const [oldRows] = await conn.query('SELECT name FROM crm_stages ORDER BY position ASC, id ASC');
      const old = oldRows.map(r => r.name);
      for (const [from, to] of stageRenames(old, ns)) {
        await conn.execute('UPDATE crm_leads SET stage = ? WHERE stage = ?', [to, from]);
      }
      await conn.query('DELETE FROM crm_stages');
      for (let i = 0; i < ns.length; i++) {
        await conn.execute('INSERT INTO crm_stages (name, position) VALUES (?,?)', [ns[i], i]);
      }
      const ph = ns.map(() => '?').join(',');
      await conn.execute(`UPDATE crm_leads SET stage = ? WHERE stage NOT IN (${ph})`, [ns[0], ...ns]);
      await conn.commit();
    } catch (e) {
      await conn.rollback();
      throw e;
    } finally {
      conn.release();
    }
  }

  async function listLeads() {
    const p = await pool();
    const [leadRows] = await p.query('SELECT * FROM crm_leads ORDER BY created_at ASC');
    const leads = {};
    for (const r of leadRows) leads[r.id] = rowLead(r);
    const [coms] = await p.query('SELECT * FROM crm_comments ORDER BY time ASC');
    const byC = {};
    for (const c of coms) {
      const item = {
        id: c.id, text: c.text, author: c.author, time: Number(c.time), attachments: [],
      };
      if (c.edited_at != null) item.editedAt = Number(c.edited_at);
      byC[c.id] = item;
      if (leads[c.lead_id]) leads[c.lead_id].comments.push(item);
    }
    const [atts] = await p.query('SELECT * FROM crm_attachments ORDER BY id ASC');
    for (const a of atts) {
      if (!byC[a.comment_id]) continue;
      byC[a.comment_id].attachments.push({
        name: a.name, size: Number(a.size), type: a.type, dataUrl: a.data_url,
      });
    }
    return Object.values(leads);
  }

  async function getLead(id) {
    const p = await pool();
    const [rows] = await p.execute('SELECT * FROM crm_leads WHERE id = ?', [id]);
    return rows[0] ? rowLead(rows[0]) : null;
  }

  async function createLead(lead) {
    const p = await pool();
    await p.execute(
      'INSERT INTO crm_leads (id,title,inn,phone,email,manager,cargo,format,payment,ati,applications_count,stage,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [lead.id, lead.title, lead.inn, lead.phone, lead.email, lead.manager, lead.cargo, lead.format, lead.payment, lead.ati, lead.applicationsCount, lead.stage, lead.createdAt || Date.now()]
    );
  }

  async function updateLead(id, lead) {
    const p = await pool();
    await p.execute(
      'UPDATE crm_leads SET title=?,inn=?,phone=?,email=?,manager=?,cargo=?,format=?,payment=?,ati=?,applications_count=?,stage=? WHERE id=?',
      [lead.title, lead.inn, lead.phone, lead.email, lead.manager, lead.cargo, lead.format, lead.payment, lead.ati, lead.applicationsCount, lead.stage, id]
    );
  }

  async function deleteLead(id) {
    const p = await pool();
    const [coms] = await p.execute('SELECT id FROM crm_comments WHERE lead_id = ?', [id]);
    if (coms.length) {
      const ids = coms.map(c => c.id);
      const ph = ids.map(() => '?').join(',');
      await p.execute(`DELETE FROM crm_attachments WHERE comment_id IN (${ph})`, ids);
      await p.execute('DELETE FROM crm_comments WHERE lead_id = ?', [id]);
    }
    const [res] = await p.execute('DELETE FROM crm_leads WHERE id = ?', [id]);
    return res.affectedRows > 0;
  }

  async function addSysComment(leadId, text) {
    const p = await pool();
    await p.execute(
      'INSERT INTO crm_comments (id, lead_id, text, author, time, edited_at) VALUES (?,?,?,?,?,NULL)',
      ['c_' + randomId(6), leadId, text, 'Система', Date.now()]
    );
  }

  async function addComment(leadId, { id, text, author, attachments }) {
    const p = await pool();
    const cid = id || ('c_' + randomId(6));
    await p.execute(
      'INSERT INTO crm_comments (id, lead_id, text, author, time, edited_at) VALUES (?,?,?,?,?,NULL)',
      [cid, leadId, text, author, Date.now()]
    );
    for (const a of attachments || []) {
      await p.execute(
        'INSERT INTO crm_attachments (comment_id, name, size, type, data_url) VALUES (?,?,?,?,?)',
        [cid, a.name, a.size, a.type || '', a.dataUrl]
      );
    }
    return cid;
  }

  async function getComment(id) {
    const p = await pool();
    const [rows] = await p.execute('SELECT * FROM crm_comments WHERE id = ?', [id]);
    return rows[0] || null;
  }

  async function updateComment(id, text) {
    const p = await pool();
    await p.execute('UPDATE crm_comments SET text = ?, edited_at = ? WHERE id = ?', [text, Date.now(), id]);
  }

  async function deleteComment(id) {
    const p = await pool();
    await p.execute('DELETE FROM crm_attachments WHERE comment_id = ?', [id]);
    const [res] = await p.execute('DELETE FROM crm_comments WHERE id = ?', [id]);
    return res.affectedRows > 0;
  }

  async function dataHash() {
    const stages = await listStages();
    const leads = await listLeads();
    return sha256(JSON.stringify({ s: stages, l: leads })).slice(0, 32);
  }

  function saveUpload(buffer, originalName, mime) {
    const ext = path.extname(originalName || '').toLowerCase();
    if (buffer.length > config.maxUploadBytes) throw fail('Файл больше 5 МБ');
    if (!ext || !config.allowedUploadExt.has(ext)) throw fail('Этот тип файла не разрешён');
    if (/\.(php|phtml|phar|cgi|exe|js|htm|html|svg|shtml)(\.|$)/i.test(originalName || '')) {
      throw fail('Этот тип файла не разрешён');
    }
    const fname = (randomId(8) + ext).slice(0, 120);
    fs.writeFileSync(path.join(config.uploadDir, fname), buffer);
    return { name: originalName || ('file' + ext), size: buffer.length, type: mime || '', dataUrl: 'uploads/' + fname };
  }

  return {
    ready: () => pool().then(() => undefined),
    publicUser,
    getUserById,
    getUserByEmail,
    listUsers,
    createUser,
    updateUser,
    deleteUser,
    listStages,
    saveStages,
    listLeads,
    getLead,
    createLead,
    updateLead,
    deleteLead,
    addSysComment,
    addComment,
    getComment,
    updateComment,
    deleteComment,
    dataHash,
    saveUpload,
  };
}

module.exports = { createStore, stageRenames };
