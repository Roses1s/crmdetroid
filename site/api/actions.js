'use strict';

const { fail, needLogin } = require('./errors');
const { randomId } = require('./crypto');
const { verifyPassword } = require('./crypto');
const v = require('./validate');

function applyLeadPatch(lead, body, config) {
  if (body.title != null) lead.title = v.str(body.title, { max: config.maxTitleChars, fallback: 'Без названия' });
  if (body.inn != null) lead.inn = v.inn(body.inn);
  if (body.phone != null) lead.phone = v.str(body.phone, { max: 40 });
  if (body.email != null) lead.email = v.str(body.email, { max: 120 }).toLowerCase();
  if (body.manager != null) lead.manager = v.str(body.manager, { max: 80 });
  if (body.cargo != null) lead.cargo = v.str(body.cargo, { max: config.maxFieldChars });
  if (body.format != null) lead.format = v.str(body.format, { max: config.maxFieldChars });
  if (body.payment != null) lead.payment = v.str(body.payment, { max: config.maxFieldChars });
  if (body.ati != null) lead.ati = v.str(body.ati, { max: config.maxFieldChars });
  if (body.applicationsCount != null) lead.applicationsCount = v.nonNegInt(body.applicationsCount);
  if (body.stage != null) lead.stage = v.str(body.stage, { max: config.maxStageChars, fallback: lead.stage });
}

async function requireUser(store, session) {
  if (!session.userId) throw needLogin();
  const user = await store.getUserById(session.userId);
  if (!user) throw needLogin();
  return user;
}

function requireAdmin(user) {
  if (user.role !== 'admin') throw fail('Нет прав');
}

function canEditComment(user, comment) {
  if (comment.author === 'Система') return false;
  return user.role === 'admin' || comment.author === user.name;
}

function createActions({ store, sessions, config, limiter }) {
  return {
    async check_auth({ session }) {
      const user = await requireUser(store, session);
      return { csrf: session.csrf, user: store.publicUser(user) };
    },

    async login({ body, req, res }) {
      const email = v.email(body.email, { required: true });
      const password = String(body.password || '');
      if (!password) throw fail('Заполните поля');
      if (limiter && !limiter.allow(email)) throw fail('Слишком много попыток. Подождите 15 минут');
      const user = await store.getUserByEmail(email);
      if (!user || !verifyPassword(password, user.password)) {
        if (limiter) limiter.fail(email);
        throw fail('Неверный e-mail или пароль');
      }
      if (limiter) limiter.ok(email);
      sessions.destroy(req);
      const rec = sessions.create(Number(user.id));
      sessions.attach(res, rec);
      return { csrf: rec.csrf, user: store.publicUser(user) };
    },

    async logout({ req, res }) {
      sessions.destroy(req);
      sessions.attach(res, null);
      return {};
    },

    async search_leads({ session, url }) {
      const user = await requireUser(store, session);
      const q = v.str(url.searchParams.get('q') || '', { max: 120 });
      return store.searchLeads(Number(user.id), q);
    },

    async get_data({ session, url }) {
      const user = await requireUser(store, session);
      const uid = Number(user.id);
      const hash = await store.dataHash(uid);
      const clientHash = url.searchParams.get('hash') || '';
      if (clientHash && clientHash.length === hash.length && clientHash === hash) {
        return { unchanged: true, hash };
      }
      return {
        hash,
        stages: await store.listStages(uid),
        leads: await store.listLeads(uid),
        user: store.publicUser(user),
      };
    },

    async save_lead({ session, body }) {
      const user = await requireUser(store, session);
      const uid = Number(user.id);
      const stages = await store.listStages(uid);
      const id = v.str(body.id, { max: 80 }) || ('l_' + randomId(6));
      let lead = await store.getLead(id, uid);
      if (!lead) {
        lead = {
          id,
          title: 'Без названия',
          inn: '', phone: '', email: '', manager: user.name,
          cargo: '', format: '', payment: '', ati: '',
          applicationsCount: 0,
          stage: stages[0] || 'Новый',
          createdAt: Date.now(),
        };
        applyLeadPatch(lead, body, config);
        if (!stages.includes(lead.stage)) lead.stage = stages[0] || 'Новый';
        await store.createLead(lead, uid);
        await store.addSysComment(lead.id, 'Лид создан');
      } else {
        const prevStage = lead.stage;
        applyLeadPatch(lead, body, config);
        if (lead.stage && !stages.includes(lead.stage)) lead.stage = prevStage;
        await store.updateLead(id, lead, uid);
      }
      return { id: lead.id };
    },

    async move_lead({ session, body }) {
      const user = await requireUser(store, session);
      const uid = Number(user.id);
      const id = v.idStr(body.id);
      const stage = v.str(body.stage, { max: config.maxStageChars });
      const stages = await store.listStages(uid);
      if (!stage || !stages.includes(stage)) throw fail('Нет такого этапа');
      const lead = await store.getLead(id, uid);
      if (!lead) throw fail('Лид не найден');
      const from = v.str(body.from, { max: config.maxStageChars }) || lead.stage;
      if (lead.stage !== stage) {
        lead.stage = stage;
        await store.updateLead(id, lead, uid);
        await store.addSysComment(id, `Статус изменен: ${from} ➔ ${stage}`);
      }
      return {};
    },

    async delete_lead({ session, body }) {
      const user = await requireUser(store, session);
      const id = v.idStr(body.id);
      const okDel = await store.deleteLead(id, Number(user.id));
      if (!okDel) throw fail('Лид не найден');
      return {};
    },

    async add_comment({ session, fields, files }) {
      const user = await requireUser(store, session);
      const leadId = v.idStr(fields.lead_id);
      const text = v.str(fields.text, { max: config.maxCommentChars });
      const lead = await store.getLead(leadId, Number(user.id));
      if (!lead) throw fail('Лид не найден');
      const attachments = [];
      for (const f of files || []) {
        if (!f.filename || !f.data || !f.data.length) continue;
        attachments.push(store.saveUpload(f.data, f.filename, f.type));
      }
      if (!text && !attachments.length) throw fail('Пусто');
      await store.addComment(leadId, { text, author: user.name, attachments });
      return {};
    },

    async edit_comment({ session, body }) {
      const user = await requireUser(store, session);
      const cid = v.idStr(body.id);
      const text = v.str(body.text, { max: config.maxCommentChars });
      if (!text) throw fail('Пусто');
      const c = await store.getComment(cid, Number(user.id));
      if (!c) throw fail('Комментарий не найден');
      if (!canEditComment(user, c)) throw fail('Нет прав');
      await store.updateComment(cid, text);
      return {};
    },

    async delete_comment({ session, body }) {
      const user = await requireUser(store, session);
      const cid = v.idStr(body.id);
      const c = await store.getComment(cid, Number(user.id));
      if (!c) throw fail('Комментарий не найден');
      if (!canEditComment(user, c)) throw fail('Нет прав');
      await store.deleteComment(cid);
      return {};
    },

    async save_stages({ session, body }) {
      const user = await requireUser(store, session);
      if (!Array.isArray(body.stages)) throw fail('Пустой список этапов');
      const ns = body.stages.map(s => v.str(s, { max: config.maxStageChars })).filter(Boolean);
      if (!ns.length) throw fail('Пустой список этапов');
      if (new Set(ns).size !== ns.length) throw fail('Имя занято');
      await store.saveStages(ns, Number(user.id));
      return {};
    },

    async get_users({ session }) {
      const user = await requireUser(store, session);
      requireAdmin(user);
      return { users: await store.listUsers() };
    },

    async register_user({ session, body }) {
      const actor = await requireUser(store, session);
      requireAdmin(actor);
      const name = v.str(body.name, { max: 80 });
      const emailAddr = v.email(body.email, { required: true });
      const pass = v.password(body.password, { required: true });
      if (!name) throw fail('Все поля');
      if (await store.getUserByEmail(emailAddr)) throw fail('E-mail уже занят');
      const id = await store.createUser({ name, email: emailAddr, password: pass, role: 'user' });
      return { id };
    },

    async update_user({ session, body }) {
      const actor = await requireUser(store, session);
      requireAdmin(actor);
      const id = v.nonNegInt(body.id);
      const name = v.str(body.name, { max: 80 });
      const emailAddr = v.email(body.email, { required: true });
      if (!name) throw fail('Обязательны Имя и Email');
      const user = await store.getUserById(id);
      if (!user) throw fail('Сотрудник не найден');
      const other = await store.getUserByEmail(emailAddr);
      if (other && Number(other.id) !== id) throw fail('E-mail уже занят');
      const pass = v.password(body.password, { required: false });
      await store.updateUser(id, { name, email: emailAddr, password: pass || undefined });
      return {};
    },

    async delete_user({ session, body }) {
      const actor = await requireUser(store, session);
      requireAdmin(actor);
      const id = v.nonNegInt(body.id);
      if (id === 1) throw fail('Нельзя удалить основного администратора');
      if (id === Number(actor.id)) throw fail('Нельзя удалить себя');
      const okDel = await store.deleteUser(id);
      if (!okDel) throw fail('Сотрудник не найден');
      return {};
    },
  };
}

function createLimiter(config) {
  const fails = new Map();
  return {
    allow(email) {
      const rec = fails.get(email);
      if (!rec) return true;
      if (Date.now() - rec.t > config.loginWindowMs) { fails.delete(email); return true; }
      return rec.n < config.loginMaxFails;
    },
    fail(email) {
      const rec = fails.get(email) || { n: 0, t: Date.now() };
      rec.n += 1;
      rec.t = Date.now();
      fails.set(email, rec);
    },
    ok(email) { fails.delete(email); },
  };
}

module.exports = { createActions, createLimiter };
