// ==========================================================================
// /api/messages — İletişim formundan gelen mesajların "backend"i, veritabanı
// yok. Tek doğruluk kaynağı: data/messages.json. Bkz. api/_lib/jsonStore.js.
//
// Public site (index.html) yalnızca POST atar (yeni mesaj gönderir).
// Admin panel (admin.html) GET (listeler) ve DELETE (siler) kullanır — bu
// bir içerik yönetim ekranı değil, gelen kutusu; PUT/edit yok.
// ==========================================================================

import { createJsonStore, send, readRequestBody, sanitizeText } from './_lib/jsonStore.js';

const store = createJsonStore('data/messages.json');
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function normalizeMessageInput(body) {
  const errors = [];
  const name = sanitizeText(body.name);
  const email = sanitizeText(body.email);
  const phone = sanitizeText(body.phone);
  const message = sanitizeText(body.message);

  if (!name) errors.push('Ad Soyad gerekli.');
  if (!email) errors.push('E-posta gerekli.');
  else if (!EMAIL_RE.test(email)) errors.push('E-posta adresi geçerli değil.');
  if (!phone) errors.push('Telefon numarası gerekli.');
  if (!message) errors.push('Mesaj gerekli.');

  return { errors, entry: { name, email, phone, message } };
}

export default async function handler(req, res) {
  try {
    const requestUrl = new URL(req.url, 'http://localhost');
    const id = requestUrl.searchParams.get('id');

    if (req.method === 'GET') {
      const { data } = await store.readData();
      send(res, 200, data);
      return;
    }

    if (req.method === 'POST') {
      const { data, sha } = await store.readData();
      const body = await readRequestBody(req);
      const { errors, entry } = normalizeMessageInput(body);
      if (errors.length) return send(res, 400, { error: errors.join(' ') });

      const nextId = data.reduce((max, m) => Math.max(max, m.id), 0) + 1;
      const created = { id: nextId, ...entry, createdAt: new Date().toISOString() };
      const next = [created, ...data];

      await store.writeData(next, sha, `New contact message from ${created.name}`);
      send(res, 201, created);
      return;
    }

    if (req.method === 'DELETE') {
      if (!id) return send(res, 400, { error: 'id parametresi gerekli.' });
      const { data, sha } = await store.readData();
      const idx = data.findIndex((m) => String(m.id) === String(id));
      if (idx === -1) return send(res, 404, { error: 'Mesaj bulunamadı.' });

      const removed = data[idx];
      const next = data.filter((m) => String(m.id) !== String(id));

      await store.writeData(next, sha, `Delete contact message from ${removed.name}`);
      send(res, 200, { ok: true });
      return;
    }

    send(res, 405, { error: 'Desteklenmeyen metod.' });
  } catch (err) {
    console.error('[api/messages]', err);
    send(res, 500, { error: err.message || 'Sunucu hatası.' });
  }
}
