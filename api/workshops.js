// ==========================================================================
// /api/workshops — the site's "backend", without a database.
//
// Single source of truth: data/workshops.json (an array of workshop
// objects). Both the public site (index.html) and the admin panel
// (admin.html) read this endpoint; the admin panel also writes to it here.
//
// Persistence strategy depends on where this runs:
//   - Locally (via `node serve.mjs`): read/write the JSON file on disk
//     directly. Instant, no setup needed.
//   - On Vercel: the deployed filesystem is read-only / ephemeral, so a
//     local file write would silently vanish on the next request or
//     redeploy. Instead we commit the updated JSON straight to the GitHub
//     repo (main branch) via the GitHub REST API. Vercel is connected to
//     that repo, so the commit triggers an automatic redeploy — but every
//     GET below already reads live from GitHub's API, so updated data is
//     visible immediately, without waiting for the redeploy to finish.
//
// This requires a GITHUB_TOKEN environment variable to be set in the
// Vercel project (Settings → Environment Variables): a GitHub Personal
// Access Token — fine-grained, scoped to just this repo, with
// "Contents: Read and write" permission. Nothing is required locally.
// ==========================================================================

import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const isVercel = Boolean(process.env.VERCEL);

const GITHUB_OWNER = process.env.GITHUB_OWNER || 'oguzhanuluugur';
const GITHUB_REPO = process.env.GITHUB_REPO || 'harmony';
const GITHUB_BRANCH = process.env.GITHUB_BRANCH || 'main';
const GITHUB_DATA_PATH = 'data/workshops.json';

const LOCAL_DATA_FILE = path.join(process.cwd(), 'data', 'workshops.json');

const PLACEHOLDER_IMAGE = 'https://placehold.co/400x280/f4f5f6/9aa2ad?text=G%C3%B6rsel';
const MAX_IMAGE_LENGTH = 700_000; // ~700KB of base64 — keeps commits/API payloads sane

// ---- storage: local file (dev) or GitHub Contents API (production) ------

async function readFromGitHub() {
  const url = `https://api.github.com/repos/${GITHUB_OWNER}/${GITHUB_REPO}/contents/${GITHUB_DATA_PATH}?ref=${GITHUB_BRANCH}`;
  const response = await fetch(url, {
    headers: {
      Authorization: `Bearer ${process.env.GITHUB_TOKEN}`,
      Accept: 'application/vnd.github+json',
    },
  });
  if (!response.ok) {
    throw new Error(`GitHub'dan veri okunamadı (${response.status}).`);
  }
  const json = await response.json();
  const content = Buffer.from(json.content, 'base64').toString('utf-8');
  return { data: JSON.parse(content), sha: json.sha };
}

async function writeToGitHub(data, sha, message) {
  const url = `https://api.github.com/repos/${GITHUB_OWNER}/${GITHUB_REPO}/contents/${GITHUB_DATA_PATH}`;
  const response = await fetch(url, {
    method: 'PUT',
    headers: {
      Authorization: `Bearer ${process.env.GITHUB_TOKEN}`,
      Accept: 'application/vnd.github+json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      message,
      content: Buffer.from(JSON.stringify(data, null, 2) + '\n').toString('base64'),
      sha,
      branch: GITHUB_BRANCH,
    }),
  });
  if (!response.ok) {
    const errText = await response.text().catch(() => '');
    throw new Error(`GitHub'a yazılamadı (${response.status}). ${errText}`.trim());
  }
}

async function readData() {
  if (isVercel) return readFromGitHub();
  const content = await readFile(LOCAL_DATA_FILE, 'utf-8');
  return { data: JSON.parse(content), sha: null };
}

async function writeData(data, sha, message) {
  if (isVercel) {
    if (!process.env.GITHUB_TOKEN) {
      throw new Error(
        'GITHUB_TOKEN ortam değişkeni tanımlı değil. Vercel proje ayarlarından (Settings → Environment Variables) eklemeniz gerekiyor.'
      );
    }
    await writeToGitHub(data, sha, message);
    return;
  }
  await writeFile(LOCAL_DATA_FILE, JSON.stringify(data, null, 2) + '\n', 'utf-8');
}

// ---- request helpers ------------------------------------------------------

function send(res, status, payload) {
  res.statusCode = status;
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.end(JSON.stringify(payload));
}

async function readRequestBody(req) {
  // Some runtimes (Vercel) may already have parsed the body onto req.body.
  if (req.body !== undefined && req.body !== null) {
    return typeof req.body === 'string' ? JSON.parse(req.body || '{}') : req.body;
  }
  return new Promise((resolve, reject) => {
    let raw = '';
    req.on('data', (chunk) => { raw += chunk; });
    req.on('end', () => {
      if (!raw) return resolve({});
      try { resolve(JSON.parse(raw)); } catch (err) { reject(new Error('Geçersiz JSON gövdesi.')); }
    });
    req.on('error', reject);
  });
}

function sanitizeText(value) {
  return typeof value === 'string' ? value.trim().replace(/[<>]/g, '') : '';
}

function normalizeWorkshopInput(body) {
  const errors = [];
  const name = sanitizeText(body.name);
  const age = sanitizeText(body.age);
  const month = sanitizeText(body.month);
  const date = sanitizeText(body.date);
  const price = sanitizeText(body.price);
  const status = body.status === 'passive' ? 'passive' : 'active';

  if (!name) errors.push('Atölye adı gerekli.');
  if (!age) errors.push('Yaş grubu gerekli.');
  if (!month) errors.push('Ay gerekli.');
  if (!date) errors.push('Tarih gerekli.');
  if (!price) errors.push('Fiyat gerekli.');

  let image;
  if (typeof body.image === 'string' && body.image) {
    if (body.image.length > MAX_IMAGE_LENGTH) {
      errors.push('Görsel çok büyük. Lütfen daha küçük bir görsel seçin.');
    } else {
      image = body.image;
    }
  }

  return { errors, workshop: { name, age, month, date, price, status, image } };
}

// ---- handler ----------------------------------------------------------

export default async function handler(req, res) {
  try {
    const requestUrl = new URL(req.url, 'http://localhost');
    const id = requestUrl.searchParams.get('id');

    if (req.method === 'GET') {
      const { data } = await readData();
      send(res, 200, data);
      return;
    }

    if (req.method === 'POST') {
      const { data, sha } = await readData();
      const body = await readRequestBody(req);
      const { errors, workshop } = normalizeWorkshopInput(body);
      if (errors.length) return send(res, 400, { error: errors.join(' ') });

      const nextId = data.reduce((max, w) => Math.max(max, w.id), 0) + 1;
      const created = { id: nextId, ...workshop, image: workshop.image || PLACEHOLDER_IMAGE };
      const next = [created, ...data];

      await writeData(next, sha, `Add workshop: ${created.name}`);
      send(res, 201, created);
      return;
    }

    if (req.method === 'PUT') {
      if (!id) return send(res, 400, { error: 'id parametresi gerekli.' });
      const { data, sha } = await readData();
      const idx = data.findIndex((w) => String(w.id) === String(id));
      if (idx === -1) return send(res, 404, { error: 'Atölye bulunamadı.' });

      const body = await readRequestBody(req);
      const { errors, workshop } = normalizeWorkshopInput(body);
      if (errors.length) return send(res, 400, { error: errors.join(' ') });

      const updated = { ...data[idx], ...workshop, image: workshop.image || data[idx].image };
      const next = [...data];
      next[idx] = updated;

      await writeData(next, sha, `Update workshop: ${updated.name}`);
      send(res, 200, updated);
      return;
    }

    if (req.method === 'DELETE') {
      if (!id) return send(res, 400, { error: 'id parametresi gerekli.' });
      const { data, sha } = await readData();
      const idx = data.findIndex((w) => String(w.id) === String(id));
      if (idx === -1) return send(res, 404, { error: 'Atölye bulunamadı.' });

      const removed = data[idx];
      const next = data.filter((w) => String(w.id) !== String(id));

      await writeData(next, sha, `Delete workshop: ${removed.name}`);
      send(res, 200, { ok: true });
      return;
    }

    send(res, 405, { error: 'Desteklenmeyen metod.' });
  } catch (err) {
    console.error('[api/workshops]', err);
    send(res, 500, { error: err.message || 'Sunucu hatası.' });
  }
}
