// optimize-images.mjs — PNG/JPG dosyalarını WebP'ye çevirir. Bazı dizinler
// birden fazla genişlikte (srcset için) varyant üretir. Orijinaller silinmez.
// Her görsel eklemesinden/değişikliğinden sonra tekrar çalıştır:
//   node optimize-images.mjs
import sharp from "sharp";
import { readdir } from "node:fs/promises";
import path from "node:path";

// Dizine göre kalite + üretilecek varyantlar (["dosya-eki", genişlik]).
// En büyük varyant eksiz (orijinal) dosya adını alır — mevcut <img src="...">
// referansları bu yüzden bozulmaz. Genişlikler, o dizindeki görsellerin
// sayfada gerçekte gösterildiği en büyük boyuta göre seçildi (bkz. PageSpeed
// "Resim yayınlamayı kolaylaştırın" / "Properly size images").
const DIR_CONFIG = {
  // Hero galerisi: mobilde tek sütun (~365px), sm+'da 2-3 sütun, masaüstünde
  // en fazla ~560px — srcset için 3 kademe.
  "gallery-photos": {
    quality: 50,
    variants: [
      ["-xs", 480],
      ["-sm", 640],
      ["-md", 780],
      ["", 1000],
    ],
  },
  "atölyecalismalari": { quality: 58, variants: [["", 900]] },
  brand: { quality: 48, variants: [["", 1600]] },
};

// brand/ içindeki logo dosyaları küçük ikonlar olarak kullanılıyor
// (nav/footer/admin — en fazla ~80px gösterim, 2x DPR için 160px hedefliyoruz —
// PageSpeed'in "properly sized" hesabı DPR'ı yok sayıp 1x varsayıyor, bu yüzden
// hâlâ "büyük" diyor, ama gerçek retina telefonlarda 160px gerekli; boyutu
// küçültmüyoruz, sadece sıkıştırmayı sıkılaştırıyoruz). Genel "brand" ayarını
// (1600px) burada geçersiz kılmazsak PNG boyutunda gereksiz büyük WebP
// üretilir (bkz. PageSpeed "Serve images in next-gen formats").
// alphaQuality: logolar saydam (alfa kanallı) PNG — sharp varsayılan olarak
// alfa kanalını neredeyse kayıpsız (100) kodluyor, bu da dosyanın büyük
// kısmını oluşturuyordu. Düşürünce gözle fark edilmeden ciddi yer kazanılıyor.
const FILE_OVERRIDES = {
  "brand/harmony-logo-transparent.png": { quality: 70, alphaQuality: 65, variants: [["", 160]] },
  "brand/harmony-logo.png": { quality: 70, alphaQuality: 65, variants: [["", 160]] },
};

for (const [dir, { quality, variants }] of Object.entries(DIR_CONFIG)) {
  let files;
  try {
    files = await readdir(dir);
  } catch {
    continue;
  }
  for (const file of files) {
    if (!/\.(png|jpe?g)$/i.test(file)) continue;
    const input = path.join(dir, file);
    const base = file.replace(/\.(png|jpe?g)$/i, "");
    const override = FILE_OVERRIDES[input.replace(/\\/g, "/")];
    const effQuality = override?.quality ?? quality;
    const effVariants = override?.variants ?? variants;
    const webpOptions = { quality: effQuality };
    if (override?.alphaQuality) webpOptions.alphaQuality = override.alphaQuality;
    for (const [suffix, width] of effVariants) {
      const out = path.join(dir, `${base}${suffix}.webp`);
      try {
        const info = await sharp(input)
          .resize({ width, withoutEnlargement: true })
          .webp(webpOptions)
          .toFile(out);
        console.log(
          `OK  ${out}  ${info.width}x${info.height}  ${(info.size / 1024).toFixed(0)} KB`
        );
      } catch (err) {
        console.error(`FAIL ${input} -> ${out}: ${err.message}`);
      }
    }
  }
}
