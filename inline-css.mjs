// inline-css.mjs — dist/styles.css içeriğini index.html'e doğrudan gömer
// (ayrı bir render-blocking ağ isteği olmasın diye). npm run build:css'in
// bir parçası olarak otomatik çalışır; elle çalıştırmaya gerek yok, ama
// istersen: node inline-css.mjs
import { readFileSync, writeFileSync } from "node:fs";

const css = readFileSync("dist/styles.css", "utf8").trim();
const html = readFileSync("index.html", "utf8");

const startMarker = "<!-- TAILWIND_INLINE_START -->";
const endMarker = "<!-- TAILWIND_INLINE_END -->";
const startIdx = html.indexOf(startMarker);
const endIdx = html.indexOf(endMarker);

if (startIdx === -1 || endIdx === -1 || endIdx < startIdx) {
  console.error("HATA: index.html içinde TAILWIND_INLINE_START/END işaretleri bulunamadı.");
  process.exit(1);
}

const before = html.slice(0, startIdx + startMarker.length);
const after = html.slice(endIdx);
const updated = `${before}\n  <style id="tw-inline">${css}</style>\n  ${after}`;

writeFileSync("index.html", updated);
console.log(`OK  dist/styles.css (${(css.length / 1024).toFixed(1)} KB) index.html içine gömüldü.`);
