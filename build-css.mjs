// build-css.mjs — Tailwind'i derler ve sonucu index.html'e gömer (inline).
// Tek komutta ikisini de yapar (npm script'lerinde "&&" PowerShell'de
// çalışmadığı için burada Node'un kendi child_process'i üzerinden
// sırayla çalıştırıyoruz — hangi kabuktan çağrılırsa çağrılsın çalışır).
import { execFileSync } from "node:child_process";

execFileSync(
  "npx",
  ["--yes", "tailwindcss@3.4.17", "-c", "tailwind.config.js", "-i", "src/input.css", "-o", "dist/styles.css", "--minify"],
  { stdio: "inherit", shell: true }
);

execFileSync("node", ["inline-css.mjs"], { stdio: "inherit" });
