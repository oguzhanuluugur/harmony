// ==========================================================================
// Shared "backend": a JSON file as the single source of truth, no database.
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
//
// This file is under api/_lib/ (underscore-prefixed) so Vercel does NOT
// expose it as its own route — it's only ever imported by other handlers.
// ==========================================================================

import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const isVercel = Boolean(process.env.VERCEL);

const GITHUB_OWNER = process.env.GITHUB_OWNER || 'oguzhanuluugur';
const GITHUB_REPO = process.env.GITHUB_REPO || 'harmony';
const GITHUB_BRANCH = process.env.GITHUB_BRANCH || 'main';

// relativeDataPath e.g. 'data/workshops.json'
export function createJsonStore(relativeDataPath) {
  const localFile = path.join(process.cwd(), relativeDataPath);

  async function readFromGitHub() {
    const url = `https://api.github.com/repos/${GITHUB_OWNER}/${GITHUB_REPO}/contents/${relativeDataPath}?ref=${GITHUB_BRANCH}`;
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
    const url = `https://api.github.com/repos/${GITHUB_OWNER}/${GITHUB_REPO}/contents/${relativeDataPath}`;
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
    const content = await readFile(localFile, 'utf-8');
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
    await writeFile(localFile, JSON.stringify(data, null, 2) + '\n', 'utf-8');
  }

  return { readData, writeData };
}

// ---- request/response helpers shared by every /api/* handler ----

export function send(res, status, payload) {
  res.statusCode = status;
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.end(JSON.stringify(payload));
}

export async function readRequestBody(req) {
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

export function sanitizeText(value) {
  return typeof value === 'string' ? value.trim().replace(/[<>]/g, '') : '';
}

export const MAX_IMAGE_LENGTH = 700_000; // ~700KB of base64 — keeps commits/API payloads sane
