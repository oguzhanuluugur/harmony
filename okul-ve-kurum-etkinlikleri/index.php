<?php
// ==========================================================================
// /okul-ve-kurum-etkinlikleri/ — Okul ve kurum etkinlikleri sayfası.
// İçerik sunucuda HTML olarak üretilir (JavaScript gerekmez), böylece arama
// motorları ve yapay zekâ tarayıcıları listeyi doğrudan okuyabilir.
// Veri yönetim panelinden girilir: api/events.php → data/events.json.
// Üst menü / alt bilgi: includes/site-header.html, includes/site-footer.html.
// ==========================================================================

require __DIR__ . '/../api/_lib/events_store.php';

const SITE_URL = 'https://harmonyplanlama.com';
const PAGE_PATH = '/okul-ve-kurum-etkinlikleri/';

function h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

// mbstring eklentisi olmayan sunucularda da çalışsın diye küçük UTF-8 yardımcıları
function u_lower($s)
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function u_len($s)
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : (int) preg_match_all('/./us', $s);
}

function img_url($src)
{
    return preg_match('#^https?://#', $src) ? $src : '/' . ltrim($src, '/');
}

[$upcoming, $past] = events_public_split();
$all = array_merge($upcoming, $past);

// Kurumlar: şehir planındaki parseller (en yeni etkinliği olandan başlayarak, tekil)
$institutions = [];
foreach (array_merge($upcoming, $past) as $e) {
    $key = u_lower($e['institutionName']);
    if (!isset($institutions[$key])) {
        $institutions[$key] = ['name' => $e['institutionName'], 'type' => $e['institutionType'] ?? 'diger', 'district' => $e['district']];
    }
}
$institutions = array_values($institutions);

// Geçmiş etkinlikleri yıllara göre grupla
$pastByYear = [];
foreach ($past as $e) $pastByYear[substr($e['startDate'], 0, 4)][] = $e;

// ---- Yapısal veri (schema.org) ----
$org = [
    '@type' => 'EducationalOrganization',
    '@id' => SITE_URL . '/#organization',
    'name' => 'Harmony Çocuk Atölyesi',
    'url' => SITE_URL . '/',
];
$eventLd = [];
foreach ($all as $i => $e) {
    $item = [
        '@type' => 'Event',
        'name' => $e['title'],
        'description' => $e['description'],
        'startDate' => $e['startDate'],
        'eventStatus' => 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'location' => [
            '@type' => 'Place',
            'name' => $e['institutionName'],
            'address' => array_filter([
                '@type' => 'PostalAddress',
                'addressLocality' => $e['district'],
                'streetAddress' => $e['neighborhood'] ?: null,
                'addressRegion' => 'İstanbul',
                'addressCountry' => 'TR',
            ]),
        ],
        'organizer' => $org,
        'url' => SITE_URL . PAGE_PATH . '#etkinlik-' . $e['id'],
    ];
    if (!empty($e['endDate'])) $item['endDate'] = $e['endDate'];
    if (!empty($e['images'])) $item['image'] = array_map(fn($im) => SITE_URL . img_url($im['src']), $e['images']);
    if (!empty($e['ageGroup'])) $item['audience'] = ['@type' => 'PeopleAudience', 'audienceType' => 'Çocuklar (' . $e['ageGroup'] . ')'];
    $eventLd[] = ['@type' => 'ListItem', 'position' => $i + 1, 'item' => $item];
}
$ld = [
    '@context' => 'https://schema.org',
    '@graph' => array_values(array_filter([
        [
            '@type' => 'CollectionPage',
            '@id' => SITE_URL . PAGE_PATH,
            'url' => SITE_URL . PAGE_PATH,
            'name' => 'Okul ve Kurum Etkinlikleri | Harmony Çocuk Atölyesi',
            'inLanguage' => 'tr-TR',
            'about' => $org,
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Ana Sayfa', 'item' => SITE_URL . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Okul ve Kurum Etkinlikleri', 'item' => SITE_URL . PAGE_PATH],
            ],
        ],
        $eventLd ? ['@type' => 'ItemList', 'name' => 'Harmony okul ve kurum etkinlikleri', 'itemListElement' => $eventLd] : null,
    ])),
];

// ---- Kurum türü simgeleri (planda ve listede) ----
function type_icon($type)
{
    $paths = [
        'okul' => '<path d="M3 10 12 5l9 5"/><path d="M5 11v8h14v-8"/><path d="M10 19v-4h4v4"/>',
        'anaokulu' => '<path d="M4 19v-7l8-6 8 6v7z"/><circle cx="12" cy="13" r="2"/>',
        'kutuphane' => '<path d="M5 5h4v14H5z"/><path d="M10 5h4v14h-4z"/><path d="m15.5 6 3.4-1 3 13.4-3.4 1z"/>',
        'belediye' => '<path d="M3 20h18"/><path d="M5 20V10h14v10"/><path d="M12 4 4 10h16z"/><path d="M9 20v-6M15 20v-6"/>',
        'muze' => '<path d="M3 20h18"/><path d="M4 9h16"/><path d="m12 4 8 5H4z"/><path d="M6 9v11M10 9v11M14 9v11M18 9v11"/>',
        'diger' => '<rect x="5" y="5" width="14" height="14" rx="2"/><path d="M9 12h6"/>',
    ];
    return $paths[$type] ?? $paths['diger'];
}

// Şehir planı: iki sabit yerleşim (masaüstü geniş, telefon dikey). Kurumlar
// sırayla parsellere yerleşir; en fazla PLAN_MAX kurum gösterilir ve ilk boş
// parsel "sıradaki parsel: sizin kurumunuz" olur.
const PLAN_LAYOUTS = [
    'wide' => [
        'w' => 480, 'h' => 300,
        'streets' => [[0, 132, 480, 20, true], [168, 0, 8, 300, false], [312, 0, 8, 300, false]],
        'plots' => [[24, 24, 136, 96], [184, 24, 120, 96], [328, 24, 128, 96],
                    [24, 164, 136, 112], [184, 164, 120, 112], [328, 164, 128, 112]],
    ],
    'tall' => [
        'w' => 320, 'h' => 468,
        'streets' => [[0, 148, 320, 18, true], [0, 302, 320, 18, true], [156, 0, 8, 468, false]],
        'plots' => [[18, 18, 130, 118], [172, 18, 130, 118], [18, 176, 130, 116],
                    [172, 176, 130, 116], [18, 330, 130, 120], [172, 330, 130, 120]],
    ],
];
const PLAN_MAX = 5;

function plan_svg($institutions, $layoutKey, $titleId)
{
    $L = PLAN_LAYOUTS[$layoutKey];
    $shown = array_slice($institutions, 0, PLAN_MAX);
    $title = $institutions
        ? 'Harmony\'nin birlikte çalıştığı kurumlar: ' . implode(', ', array_column($institutions, 'name'))
        : 'Harmony kurum iş birlikleri planı';
    ob_start(); ?>
    <svg class="ev-plan-<?= $layoutKey ?>" viewBox="0 0 <?= $L['w'] ?> <?= $L['h'] ?>" role="img" aria-labelledby="<?= $titleId ?>">
      <title id="<?= $titleId ?>"><?= h($title) ?></title>
      <rect width="<?= $L['w'] ?>" height="<?= $L['h'] ?>" rx="18" fill="var(--ev-plan)" />
      <?php foreach ($L['streets'] as [$x, $y, $w, $hgt, $main]): ?>
        <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $w ?>" height="<?= $hgt ?>" fill="var(--ev-street)" />
        <?php if ($main): ?><line x1="<?= $x ?>" y1="<?= $y + $hgt / 2 ?>" x2="<?= $x + $w ?>" y2="<?= $y + $hgt / 2 ?>" stroke="#E4E6E9" stroke-dasharray="8 8" /><?php endif; ?>
      <?php endforeach; ?>
      <?php foreach ($L['plots'] as $i => [$x, $y, $w, $hgt]):
        $inst = $shown[$i] ?? null; ?>
        <?php if ($inst):
          // Uzun kurum adlarını iki satıra böl
          $words = preg_split('/\s+/u', $inst['name']); $lines = ['']; $max = max(10, (int) floor(($w - 24) / 6.6));
          foreach ($words as $wd) {
              $cur = end($lines);
              if ($cur !== '' && u_len($cur . ' ' . $wd) > $max) {
                  if (count($lines) === 2) { $lines[1] .= '…'; break; }
                  $lines[] = $wd;
              } else {
                  $lines[count($lines) - 1] = trim($cur . ' ' . $wd);
              }
          } ?>
          <g class="ev-plot-built" style="animation-delay: <?= 120 + $i * 110 ?>ms">
            <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $w ?>" height="<?= $hgt ?>" rx="8" fill="#fff" stroke="var(--ev-clay)" stroke-width="1.5" />
            <g transform="translate(<?= $x + 12 ?>, <?= $y + 12 ?>)" fill="none" stroke="var(--ev-clay)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><?= type_icon($inst['type']) ?></g>
            <?php foreach ($lines as $li => $line): ?>
              <text class="ev-plot-label" x="<?= $x + 12 ?>" y="<?= $y + 54 + $li * 15 ?>"><?= h($line) ?></text>
            <?php endforeach; ?>
            <text class="ev-plot-sub" x="<?= $x + 12 ?>" y="<?= $y + $hgt - 12 ?>"><?= h(events_type_label($inst['type']) . ' · ' . $inst['district']) ?></text>
          </g>
        <?php else: ?>
          <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $w ?>" height="<?= $hgt ?>" rx="8" fill="none" stroke="#C3C8CF" stroke-width="1.2" stroke-dasharray="5 5" />
          <?php if ($i === count($shown)): ?>
            <text class="ev-plot-empty" x="<?= $x + $w / 2 ?>" y="<?= $y + $hgt / 2 - 3 ?>" text-anchor="middle">Sıradaki parsel:</text>
            <text class="ev-plot-empty" x="<?= $x + $w / 2 ?>" y="<?= $y + $hgt / 2 + 12 ?>" text-anchor="middle">sizin kurumunuz</text>
          <?php endif; ?>
        <?php endif; ?>
      <?php endforeach; ?>
    </svg>
    <?php return ob_get_clean();
}
$title = 'Okul ve Kurum Etkinlikleri | Tuzla Çocuk Atölyeleri | Harmony';
$desc = 'Harmony\'nin Tuzla ve İstanbul\'da okullar, kütüphaneler ve belediyelerle düzenlediği çocuk mimarlık ve şehir tasarımı atölyeleri: tarihler, kurumlar, yaş grupları ve fotoğraflar.';
$ogImage = SITE_URL . '/og-image.jpg';
foreach ($all as $e) { if (!empty($e['images'])) { $ogImage = SITE_URL . img_url($e['images'][0]['src']); break; } }
?>
<!doctype html>
<html lang="tr">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="theme-color" content="#1A2E40" />
  <title><?= h($title) ?></title>
  <meta name="description" content="<?= h($desc) ?>" />
  <link rel="canonical" href="<?= SITE_URL . PAGE_PATH ?>" />
  <meta property="og:type" content="website" />
  <meta property="og:url" content="<?= SITE_URL . PAGE_PATH ?>" />
  <meta property="og:title" content="Okul ve Kurum Etkinlikleri | Harmony Çocuk Atölyesi" />
  <meta property="og:description" content="<?= h($desc) ?>" />
  <meta property="og:image" content="<?= h($ogImage) ?>" />
  <meta property="og:locale" content="tr_TR" />
  <meta name="twitter:card" content="summary_large_image" />
  <script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>

  <link rel="icon" type="image/png" href="/brand/harmony-logo-transparent.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link rel="stylesheet"
    href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700;800&display=swap" />
  <link rel="stylesheet" href="/dist/styles.css?v=20261005" />

  <style>
    html { scroll-behavior: smooth; }
    body { font-family: 'Manrope', sans-serif; }
    .font-display { font-family: 'Fraunces', serif; }
    .nav-link { position: relative; }
    .nav-link::after { content: ""; position: absolute; left: 0; bottom: -4px; width: 0; height: 2px; background: #d1652c; transition: width .3s cubic-bezier(.22, 1, .36, 1); }
    .nav-link:hover::after, .nav-link.active::after { width: 100%; }
    .cta-btn { transition: transform .25s cubic-bezier(.22, 1, .36, 1), box-shadow .25s cubic-bezier(.22, 1, .36, 1); }
    .cta-btn:hover { transform: translateY(-2px); }
    .cta-btn:active { transform: translateY(0) scale(.98); }

    /* ---- Etkinlikler sayfası ---- */
    :root {
      --ev-ink: #14171D; --ev-ink-2: #3A414C; --ev-ink-3: #6B7480; --ev-rule: #E4E6E9;
      --ev-paper: #FBF8F4; --ev-plan: #F4EFE7; --ev-street: #FFFFFF;
      --ev-clay: #B04F20; --ev-clay-soft: #FAE6D9; --ev-sage: #476D54; --ev-sage-soft: #DFEADF;
      --ev-navy: #1A2E40;
    }
    .ev-wrap { max-width: 72rem; margin: 0 auto; padding: 0 1.5rem; }
    @media (min-width: 1024px) { .ev-wrap { padding: 0 2.5rem; } }

    .ev-hero { padding: 3.5rem 0 4rem; border-bottom: 1px solid var(--ev-rule); }
    .ev-hero-grid { display: grid; gap: 3rem; align-items: center; }
    @media (min-width: 960px) { .ev-hero-grid { grid-template-columns: 1fr 1.05fr; gap: 4rem; } .ev-hero { padding: 5rem 0 5.5rem; } }
    .ev-crumb { font-size: .875rem; color: var(--ev-ink-3); }
    .ev-crumb a { color: var(--ev-ink-3); text-decoration: underline; text-underline-offset: 3px; }
    .ev-crumb a:hover { color: var(--ev-clay); }
    .ev-h1 { margin-top: 1.25rem; font-family: 'Fraunces', serif; font-weight: 600; font-size: clamp(2.25rem, 5vw, 3.5rem); line-height: 1.05; letter-spacing: -0.03em; color: var(--ev-ink); }
    .ev-lead { margin-top: 1.5rem; max-width: 34rem; font-size: 1.0625rem; line-height: 1.7; color: var(--ev-ink-2); }
    .ev-actions { margin-top: 2rem; display: flex; flex-wrap: wrap; gap: .75rem; }
    .ev-btn { display: inline-flex; align-items: center; gap: .5rem; border-radius: 999px; padding: .875rem 1.5rem; font-size: .9375rem; font-weight: 600; text-decoration: none; cursor: pointer; border: 0; font-family: inherit;
      transition: transform .25s cubic-bezier(.22, 1, .36, 1), background-color .2s; }
    .ev-btn:hover { transform: translateY(-2px); }
    .ev-btn:active { transform: translateY(0) scale(.98); }
    .ev-btn:focus-visible { outline: 2px solid var(--ev-clay); outline-offset: 3px; }
    .ev-btn-primary { background: var(--ev-clay); color: #fff; }
    .ev-btn-primary:hover { background: #8C3E1C; }
    .ev-btn-ghost { background: transparent; color: var(--ev-ink); box-shadow: inset 0 0 0 1.5px var(--ev-rule); }
    .ev-btn-ghost:hover { box-shadow: inset 0 0 0 1.5px var(--ev-ink-3); }

    /* Şehir planı */
    .ev-plan { position: relative; }
    .ev-plan svg { display: block; width: 100%; height: auto; }
    .ev-plan .ev-plan-tall { display: none; max-width: 26rem; }
    @media (max-width: 559px) { .ev-plan .ev-plan-wide { display: none; } .ev-plan .ev-plan-tall { display: block; } }
    .ev-plan-caption { margin-top: .875rem; font-size: .8125rem; color: var(--ev-ink-3); }
    .ev-plot-label { font-family: 'Manrope', sans-serif; font-size: 11.5px; font-weight: 700; fill: var(--ev-ink); }
    .ev-plot-sub { font-family: 'Manrope', sans-serif; font-size: 10px; font-weight: 500; fill: var(--ev-ink-3); }
    .ev-plot-empty { font-family: 'Manrope', sans-serif; font-size: 10.5px; font-weight: 600; fill: #9AA2AD; }
    @media (prefers-reduced-motion: no-preference) {
      .ev-plot-built { opacity: 0; transform: translateY(6px); animation: evBuild .6s cubic-bezier(.22, 1, .36, 1) forwards; }
      @keyframes evBuild { to { opacity: 1; transform: none; } }
    }

    /* Liste */
    .ev-section { padding: 4.5rem 0; }
    .ev-section + .ev-section { padding-top: 0; }
    .ev-h2 { font-family: 'Fraunces', serif; font-weight: 600; font-size: clamp(1.75rem, 3.2vw, 2.25rem); letter-spacing: -0.02em; line-height: 1.15; color: var(--ev-ink); }
    .ev-year { margin-top: 3rem; display: flex; align-items: baseline; gap: 1rem; }
    .ev-year span { font-family: 'Fraunces', serif; font-size: 1.25rem; font-weight: 600; color: var(--ev-ink-3); }
    .ev-year::after { content: ""; flex: 1; height: 1px; background: var(--ev-rule); }

    .ev-item { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem 2.5rem; padding: 2.5rem 0; border-bottom: 1px solid var(--ev-rule); scroll-margin-top: 6rem; }
    .ev-item:last-child, .ev-item:has(+ .ev-year) { border-bottom: 0; }
    @media (min-width: 768px) { .ev-item { grid-template-columns: 11rem minmax(0, 1fr); } }
    .ev-when { font-size: .9375rem; font-weight: 700; color: var(--ev-ink); line-height: 1.4; }
    .ev-when small { display: block; margin-top: .375rem; font-size: .8125rem; font-weight: 500; color: var(--ev-ink-3); }
    .ev-status { display: inline-block; margin-top: .75rem; border-radius: 999px; padding: .25rem .75rem; font-size: .75rem; font-weight: 700; }
    .ev-status-up { background: var(--ev-clay-soft); color: #8C3E1C; }
    .ev-status-done { background: var(--ev-sage-soft); color: var(--ev-sage); }
    .ev-title { font-family: 'Fraunces', serif; font-weight: 600; font-size: 1.5rem; line-height: 1.25; letter-spacing: -0.015em; color: var(--ev-ink); }
    .ev-inst { margin-top: .625rem; display: flex; align-items: center; gap: .5rem; font-size: .9375rem; font-weight: 600; color: var(--ev-ink-2); }
    .ev-inst svg { flex-shrink: 0; color: var(--ev-clay); }
    .ev-meta { margin-top: .5rem; display: flex; flex-wrap: wrap; gap: .25rem 0; font-size: .875rem; color: var(--ev-ink-3); }
    .ev-meta span { padding-right: .875rem; margin-right: .875rem; border-right: 1px solid var(--ev-rule); }
    .ev-meta span:last-child { border-right: 0; margin-right: 0; padding-right: 0; }
    .ev-desc { margin-top: 1rem; max-width: 42rem; font-size: 1rem; line-height: 1.75; color: var(--ev-ink-2); white-space: pre-line; }
    .ev-link { display: inline-flex; margin-top: 1rem; font-size: .9375rem; font-weight: 600; color: var(--ev-clay); text-underline-offset: 3px; }
    .ev-link:hover { color: #8C3E1C; text-decoration: underline; }
    .ev-link:focus-visible { outline: 2px solid var(--ev-clay); outline-offset: 3px; border-radius: 4px; }
    .ev-photos { margin-top: 1.5rem; display: grid; gap: .75rem; grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr)); max-width: 42rem; }
    .ev-photos.one { grid-template-columns: minmax(0, 28rem); max-width: 28rem; }
    .ev-photo { display: block; overflow: hidden; border-radius: .75rem; background: var(--ev-plan); aspect-ratio: 4 / 3; padding: 0; border: 0; cursor: zoom-in; }
    .ev-photo img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .4s cubic-bezier(.22, 1, .36, 1); }
    .ev-photo:hover img { transform: scale(1.03); }
    .ev-photo:focus-visible { outline: 2px solid var(--ev-clay); outline-offset: 3px; }

    .ev-empty { margin-top: 2rem; max-width: 36rem; font-size: 1rem; line-height: 1.7; color: var(--ev-ink-3); }

    /* Kurumlar için çağrı */
    .ev-cta { background: var(--ev-navy); color: #fff; padding: 4rem 0; }
    .ev-cta-inner { display: grid; gap: 2rem; align-items: center; }
    @media (min-width: 900px) { .ev-cta-inner { grid-template-columns: 1.4fr auto; } }
    .ev-cta h2 { font-family: 'Fraunces', serif; font-weight: 600; font-size: clamp(1.625rem, 3vw, 2.125rem); letter-spacing: -0.02em; line-height: 1.2; }
    .ev-cta p { margin-top: .875rem; max-width: 38rem; line-height: 1.7; color: #C3C8CF; }
    .ev-cta .ev-btn-ghost { color: #fff; box-shadow: inset 0 0 0 1.5px rgba(255, 255, 255, .3); }
    .ev-cta .ev-btn-ghost:hover { box-shadow: inset 0 0 0 1.5px #fff; }

    /* Fotoğraf büyütme */
    .ev-lightbox { position: fixed; inset: 0; z-index: 120; display: none; align-items: center; justify-content: center; background: rgba(20, 23, 29, .88); padding: 1.5rem; }
    .ev-lightbox.open { display: flex; }
    .ev-lightbox img { max-width: 100%; max-height: 85vh; border-radius: .75rem; }
    .ev-lightbox button { position: absolute; top: 1rem; right: 1rem; width: 2.75rem; height: 2.75rem; border-radius: 999px; border: 0; background: #fff; color: var(--ev-ink); cursor: pointer; font-size: 1.25rem; }
    .ev-lightbox button:focus-visible { outline: 2px solid var(--ev-clay); outline-offset: 3px; }
  </style>
</head>

<body class="bg-[#fbf8f4] text-ink-800 antialiased">
<?php readfile(__DIR__ . '/../includes/site-header.html'); ?>

  <main>
    <section class="ev-hero">
      <div class="ev-wrap ev-hero-grid">
        <div>
          <nav class="ev-crumb" aria-label="Sayfa yolu"><a href="/">Ana Sayfa</a> / Okul ve Kurum Etkinlikleri</nav>
          <h1 class="ev-h1">Okul ve kurum etkinlikleri</h1>
          <p class="ev-lead">
            Harmony, okulların, kütüphanelerin ve belediyelerin kendi mekânlarında 4–14 yaş çocuklar için şehir
            planlama, mimarlık ve maket atölyeleri düzenler. Bu sayfada birlikte çalıştığımız kurumları ve
            yaptığımız etkinlikleri tarih sırasıyla bulabilirsiniz.
          </p>
          <div class="ev-actions">
            <a class="ev-btn ev-btn-primary" href="/index.html#iletisim">Kurumunuz için teklif isteyin</a>
            <?php if ($all): ?><a class="ev-btn ev-btn-ghost" href="#etkinlikler">Etkinlikleri görün</a><?php endif; ?>
          </div>
        </div>

        <figure class="ev-plan">
          <?= plan_svg($institutions, 'wide', 'plan-title-wide') ?>
          <?= plan_svg($institutions, 'tall', 'plan-title-tall') ?>
          <figcaption class="ev-plan-caption">
            <?php if (count($institutions) > PLAN_MAX): ?>
              Planda en son çalıştığımız <?= PLAN_MAX ?> kurum yer alıyor; toplam <?= count($institutions) ?> kurumla çalıştık.
            <?php elseif ($institutions): ?>
              Birlikte atölye yaptığımız kurumlar, her biri kendi parselinde.
            <?php else: ?>
              Kurum iş birliklerimiz bu planda yer alacak.
            <?php endif; ?>
          </figcaption>
        </figure>
      </div>
    </section>

    <?php
    // Tek bir etkinlik kaydını basar.
    function render_event($e, $isUpcoming)
    {
        $images = $e['images'] ?? [];
        ?>
        <article class="ev-item" id="etkinlik-<?= (int) $e['id'] ?>">
          <div>
            <p class="ev-when"><time datetime="<?= h($e['startDate']) ?>"><?= h(events_format_dates($e['startDate'], $e['endDate'] ?? null)) ?></time></p>
            <span class="ev-status <?= $isUpcoming ? 'ev-status-up' : 'ev-status-done' ?>"><?= $isUpcoming ? 'Yaklaşan' : 'Yapıldı' ?></span>
          </div>
          <div>
            <h3 class="ev-title"><?= h($e['title']) ?></h3>
            <p class="ev-inst">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= type_icon($e['institutionType'] ?? 'diger') ?></svg>
              <?= h($e['institutionName']) ?>
            </p>
            <p class="ev-meta">
              <span><?= h(events_type_label($e['institutionType'] ?? '')) ?></span>
              <span><?= h(trim(($e['neighborhood'] ? $e['neighborhood'] . ', ' : '') . $e['district'])) ?></span>
              <?php if (!empty($e['ageGroup'])): ?><span><?= h($e['ageGroup']) ?></span><?php endif; ?>
              <?php if (!empty($e['participants'])): ?><span><?= (int) $e['participants'] ?> çocuk katıldı</span><?php endif; ?>
            </p>
            <p class="ev-desc"><?= h($e['description']) ?></p>
            <?php if (!empty($e['link'])): ?>
              <a class="ev-link" href="<?= h($e['link']) ?>" target="_blank" rel="noopener">Kurumun duyurusunu açın</a>
            <?php endif; ?>
            <?php if (!empty($e['videos'])): ?>
              <div class="ev-photos">
                <?php foreach ($e['videos'] as $video): ?>
                  <video controls playsinline preload="metadata" aria-label="<?= h($e['title']) ?> videosu" style="width:100%;border-radius:.75rem;background:#000">
                    <source src="<?= h(img_url($video['src'])) ?>" type="<?= substr($video['src'], -5) === '.webm' ? 'video/webm' : 'video/mp4' ?>">
                    Tarayıcınız video oynatmayı desteklemiyor.
                  </video>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if ($images): ?>
              <div class="ev-photos<?= count($images) === 1 ? ' one' : '' ?>">
                <?php foreach ($images as $im):
                  $alt = $im['alt'] ?: ($e['title'] . ' – ' . $e['institutionName']); ?>
                  <button type="button" class="ev-photo" data-full="<?= h(img_url($im['src'])) ?>" aria-label="Fotoğrafı büyütün: <?= h($alt) ?>">
                    <img src="<?= h(img_url($im['src'])) ?>" alt="<?= h($alt) ?>" loading="lazy" decoding="async" />
                  </button>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </article>
        <?php
    }
    ?>

    <div id="etkinlikler">
      <?php if ($upcoming): ?>
        <section class="ev-section" aria-labelledby="yaklasan-baslik">
          <div class="ev-wrap">
            <h2 class="ev-h2" id="yaklasan-baslik">Yaklaşan etkinlikler</h2>
            <?php foreach ($upcoming as $e) render_event($e, true); ?>
          </div>
        </section>
      <?php endif; ?>

      <section class="ev-section" aria-labelledby="gecmis-baslik">
        <div class="ev-wrap">
          <h2 class="ev-h2" id="gecmis-baslik"><?= $upcoming ? 'Geçmiş etkinlikler' : 'Yaptığımız etkinlikler' ?></h2>
          <?php if (!$past && !$upcoming): ?>
            <p class="ev-empty">Kurum etkinliklerimizi bu sayfaya ekliyoruz. Kurumunuzda bir atölye planlamak için
              şimdiden bize yazabilirsiniz.</p>
          <?php elseif (!$past): ?>
            <p class="ev-empty">Tamamlanan etkinlikler burada listelenecek.</p>
          <?php endif; ?>
          <?php foreach ($pastByYear as $year => $items): ?>
            <h3 class="ev-year"><span><?= h($year) ?></span></h3>
            <?php foreach ($items as $e) render_event($e, false); ?>
          <?php endforeach; ?>
        </div>
      </section>
    </div>

    <section class="ev-cta">
      <div class="ev-wrap ev-cta-inner">
        <div>
          <h2>Kurumunuzda bir atölye düzenleyelim</h2>
          <p>Okulunuz, kütüphaneniz ya da belediyeniz için yaş grubuna ve mekânınıza uygun bir program hazırlıyoruz.
            Kurum adınızı ve iletişim bilgilerinizi bırakın, size dönelim.</p>
        </div>
        <div class="ev-actions" style="margin-top:0">
          <a class="ev-btn ev-btn-primary" href="/index.html#iletisim">Kurumsal başvuru formu</a>
          <button type="button" class="ev-btn ev-btn-ghost" data-whatsapp-cta>WhatsApp'tan yazın</button>
        </div>
      </div>
    </section>
  </main>

  <div class="ev-lightbox" id="ev-lightbox" role="dialog" aria-modal="true" aria-label="Fotoğraf">
    <button type="button" id="ev-lightbox-close" aria-label="Kapat">✕</button>
    <img alt="" id="ev-lightbox-img" />
  </div>

<?php readfile(__DIR__ . '/../includes/site-footer.html'); ?>
  <script>
    (function () {
      const box = document.getElementById('ev-lightbox');
      const img = document.getElementById('ev-lightbox-img');
      const closeBtn = document.getElementById('ev-lightbox-close');
      let opener = null;
      function close() { box.classList.remove('open'); img.removeAttribute('src'); if (opener) opener.focus(); }
      document.querySelectorAll('.ev-photo').forEach((btn) => {
        btn.addEventListener('click', () => {
          opener = btn;
          const thumb = btn.querySelector('img');
          img.src = btn.dataset.full; img.alt = thumb.alt;
          box.classList.add('open'); closeBtn.focus();
        });
      });
      closeBtn.addEventListener('click', close);
      box.addEventListener('click', (e) => { if (e.target === box) close(); });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && box.classList.contains('open')) close(); });
    })();
  </script>
</body>

</html>
