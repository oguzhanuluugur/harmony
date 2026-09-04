// ==========================================================================
// /api/blog — the site's "backend" for Blog & Yayınlar, without a database.
// Single source of truth: data/blog.json. See api/_lib/jsonStore.js for
// how reads/writes work locally vs. on Vercel.
// ==========================================================================

import { createJsonStore, send, readRequestBody, sanitizeText, MAX_IMAGE_LENGTH } from './_lib/jsonStore.js';

const store = createJsonStore('data/blog.json');
const PLACEHOLDER_IMAGE = 'https://placehold.co/600x400/f4f5f6/9aa2ad?text=G%C3%B6rsel';
const EXCERPT_LENGTH = 170;

function excerptFrom(body) {
  const firstParagraph = body[0] || '';
  if (firstParagraph.length <= EXCERPT_LENGTH) return firstParagraph;
  return firstParagraph.slice(0, EXCERPT_LENGTH).trim() + '...';
}

function normalizeBlogInput(body) {
  const errors = [];
  const title = sanitizeText(body.title);
  const category = sanitizeText(body.category);
  const date = sanitizeText(body.date);
  // Paragraphs are authored as one textarea, blank-line separated.
  const bodyText = typeof body.body === 'string' ? body.body : '';
  const paragraphs = bodyText
    .split(/\n\s*\n/)
    .map((p) => sanitizeText(p))
    .filter(Boolean);

  if (!title) errors.push('Başlık gerekli.');
  if (!category) errors.push('Kategori gerekli.');
  if (!date) errors.push('Tarih gerekli.');
  if (!paragraphs.length) errors.push('İçerik gerekli.');

  let image;
  if (typeof body.image === 'string' && body.image) {
    if (body.image.length > MAX_IMAGE_LENGTH) {
      errors.push('Görsel çok büyük. Lütfen daha küçük bir görsel seçin.');
    } else {
      image = body.image;
    }
  }

  return {
    errors,
    post: { title, category, date, body: paragraphs, excerpt: excerptFrom(paragraphs), image },
  };
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
      const { errors, post } = normalizeBlogInput(body);
      if (errors.length) return send(res, 400, { error: errors.join(' ') });

      const nextId = data.reduce((max, p) => Math.max(max, p.id), 0) + 1;
      const created = { id: nextId, ...post, image: post.image || PLACEHOLDER_IMAGE };
      const next = [created, ...data];

      await store.writeData(next, sha, `Add blog post: ${created.title}`);
      send(res, 201, created);
      return;
    }

    if (req.method === 'PUT') {
      if (!id) return send(res, 400, { error: 'id parametresi gerekli.' });
      const { data, sha } = await store.readData();
      const idx = data.findIndex((p) => String(p.id) === String(id));
      if (idx === -1) return send(res, 404, { error: 'Yazı bulunamadı.' });

      const body = await readRequestBody(req);
      const { errors, post } = normalizeBlogInput(body);
      if (errors.length) return send(res, 400, { error: errors.join(' ') });

      const updated = { ...data[idx], ...post, image: post.image || data[idx].image };
      const next = [...data];
      next[idx] = updated;

      await store.writeData(next, sha, `Update blog post: ${updated.title}`);
      send(res, 200, updated);
      return;
    }

    if (req.method === 'DELETE') {
      if (!id) return send(res, 400, { error: 'id parametresi gerekli.' });
      const { data, sha } = await store.readData();
      const idx = data.findIndex((p) => String(p.id) === String(id));
      if (idx === -1) return send(res, 404, { error: 'Yazı bulunamadı.' });

      const removed = data[idx];
      const next = data.filter((p) => String(p.id) !== String(id));

      await store.writeData(next, sha, `Delete blog post: ${removed.title}`);
      send(res, 200, { ok: true });
      return;
    }

    send(res, 405, { error: 'Desteklenmeyen metod.' });
  } catch (err) {
    console.error('[api/blog]', err);
    send(res, 500, { error: err.message || 'Sunucu hatası.' });
  }
}
