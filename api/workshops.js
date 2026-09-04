// ==========================================================================
// /api/workshops — the site's "backend" for Atölyeler, without a database.
// Single source of truth: data/workshops.json. See api/_lib/jsonStore.js
// for how reads/writes work locally vs. on Vercel.
// ==========================================================================

import { createJsonStore, send, readRequestBody, sanitizeText, MAX_IMAGE_LENGTH } from './_lib/jsonStore.js';

const store = createJsonStore('data/workshops.json');
const PLACEHOLDER_IMAGE = 'https://placehold.co/400x280/f4f5f6/9aa2ad?text=G%C3%B6rsel';

function normalizeWorkshopInput(body) {
  const errors = [];
  const name = sanitizeText(body.name);
  const age = sanitizeText(body.age);
  const month = sanitizeText(body.month);
  const date = sanitizeText(body.date);
  const time = sanitizeText(body.time);
  const price = sanitizeText(body.price);
  const status = body.status === 'passive' ? 'passive' : 'active';

  if (!name) errors.push('Atölye adı gerekli.');
  if (!age) errors.push('Yaş grubu gerekli.');
  if (!month) errors.push('Ay gerekli.');
  if (!date) errors.push('Tarih gerekli.');
  if (!time) errors.push('Saat gerekli.');
  if (!price) errors.push('Fiyat gerekli.');

  let image;
  if (typeof body.image === 'string' && body.image) {
    if (body.image.length > MAX_IMAGE_LENGTH) {
      errors.push('Görsel çok büyük. Lütfen daha küçük bir görsel seçin.');
    } else {
      image = body.image;
    }
  }

  return { errors, workshop: { name, age, month, date, time, price, status, image } };
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
      const { errors, workshop } = normalizeWorkshopInput(body);
      if (errors.length) return send(res, 400, { error: errors.join(' ') });

      const nextId = data.reduce((max, w) => Math.max(max, w.id), 0) + 1;
      const created = { id: nextId, ...workshop, image: workshop.image || PLACEHOLDER_IMAGE };
      const next = [created, ...data];

      await store.writeData(next, sha, `Add workshop: ${created.name}`);
      send(res, 201, created);
      return;
    }

    if (req.method === 'PUT') {
      if (!id) return send(res, 400, { error: 'id parametresi gerekli.' });
      const { data, sha } = await store.readData();
      const idx = data.findIndex((w) => String(w.id) === String(id));
      if (idx === -1) return send(res, 404, { error: 'Atölye bulunamadı.' });

      const body = await readRequestBody(req);
      const { errors, workshop } = normalizeWorkshopInput(body);
      if (errors.length) return send(res, 400, { error: errors.join(' ') });

      const updated = { ...data[idx], ...workshop, image: workshop.image || data[idx].image };
      const next = [...data];
      next[idx] = updated;

      await store.writeData(next, sha, `Update workshop: ${updated.name}`);
      send(res, 200, updated);
      return;
    }

    if (req.method === 'DELETE') {
      if (!id) return send(res, 400, { error: 'id parametresi gerekli.' });
      const { data, sha } = await store.readData();
      const idx = data.findIndex((w) => String(w.id) === String(id));
      if (idx === -1) return send(res, 404, { error: 'Atölye bulunamadı.' });

      const removed = data[idx];
      const next = data.filter((w) => String(w.id) !== String(id));

      await store.writeData(next, sha, `Delete workshop: ${removed.name}`);
      send(res, 200, { ok: true });
      return;
    }

    send(res, 405, { error: 'Desteklenmeyen metod.' });
  } catch (err) {
    console.error('[api/workshops]', err);
    send(res, 500, { error: err.message || 'Sunucu hatası.' });
  }
}
