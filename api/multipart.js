'use strict';

function parseMultipart(buf, contentType) {
  const fields = {};
  const files = [];
  const m = /boundary=(?:"([^"]+)"|([^;]+))/i.exec(contentType || '');
  if (!m || !buf || !buf.length) return { fields, files };
  const boundary = Buffer.from('--' + (m[1] || m[2]).trim());
  let start = buf.indexOf(boundary);
  if (start === -1) return { fields, files };
  start += boundary.length;

  while (start < buf.length) {
    if (buf.slice(start, start + 2).toString() === '--') break;
    if (buf.slice(start, start + 2).toString('ascii') === '\r\n') start += 2;
    const headerEnd = buf.indexOf('\r\n\r\n', start);
    if (headerEnd === -1) break;
    const header = buf.slice(start, headerEnd).toString('utf8');
    const next = buf.indexOf(boundary, headerEnd + 4);
    if (next === -1) break;
    let body = buf.slice(headerEnd + 4, next);
    if (body.length >= 2 && body.slice(-2).toString('ascii') === '\r\n') {
      body = body.slice(0, -2);
    }
    const nameM = /name="([^"]*)"/i.exec(header);
    const fileM = /filename="([^"]*)"/i.exec(header);
    const typeM = /Content-Type:\s*([^\r\n]+)/i.exec(header);
    const name = nameM ? nameM[1] : '';
    if (fileM && fileM[1]) {
      files.push({
        field: name,
        filename: fileM[1],
        type: typeM ? typeM[1].trim() : '',
        data: body,
      });
    } else {
      fields[name] = body.toString('utf8');
    }
    start = next + boundary.length;
  }
  return { fields, files };
}

function readBody(req, limit) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let n = 0;
    req.on('data', c => {
      n += c.length;
      if (n > limit) {
        reject(new Error('too large'));
        req.destroy();
        return;
      }
      chunks.push(c);
    });
    req.on('end', () => resolve(Buffer.concat(chunks)));
    req.on('error', reject);
  });
}

function parseJson(buf) {
  if (!buf || !buf.length) return {};
  try {
    const v = JSON.parse(buf.toString('utf8'));
    return v && typeof v === 'object' && !Array.isArray(v) ? v : {};
  } catch {
    return {};
  }
}

module.exports = { parseMultipart, readBody, parseJson };
