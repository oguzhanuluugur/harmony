// optimize-images.mjs — PNG/JPG dosyalarını WebP'ye çevirir + en fazla 1600px
// genişliğe küçültür. Orijinaller silinmez. Her CSS/görsel eklemesinden sonra
// tekrar çalıştır:  node optimize-images.mjs
import sharp from "sharp";
import { readdir } from "node:fs/promises";
import path from "node:path";

// Dizine göre hedef genişlik/kalite — her dizindeki görsellerin sayfada
// gerçekte gösterildiği en büyük boyuta göre ayarlandı (gereksiz büyük
// dosya göndermemek için; bkz. PageSpeed "Resim yayınlamayı kolaylaştırın").
const DIR_CONFIG = {
  "gallery-photos": { width: 1000, quality: 68 },
  "atölyecalismalari": { width: 900, quality: 65 },
  brand: { width: 1600, quality: 72 },
};

for (const [dir, { width: MAX_W, quality }] of Object.entries(DIR_CONFIG)) {
  let files;
  try {
    files = await readdir(dir);
  } catch {
    continue;
  }
  for (const file of files) {
    if (!/\.(png|jpe?g)$/i.test(file)) continue;
    const input = path.join(dir, file);
    const out = path.join(dir, file.replace(/\.(png|jpe?g)$/i, ".webp"));
    try {
      const info = await sharp(input)
        .resize({ width: MAX_W, withoutEnlargement: true })
        .webp({ quality })
        .toFile(out);
      console.log(
        `OK  ${out}  ${info.width}x${info.height}  ${(info.size / 1024).toFixed(0)} KB`
      );
    } catch (err) {
      console.error(`FAIL ${input}: ${err.message}`);
    }
  }
}
