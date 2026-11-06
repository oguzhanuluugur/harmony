<?php
// ==========================================================================
// api/_lib/events_store.php — Okul ve kurum etkinliklerinin ortak okuyucusu.
// Hem api/events.php (panel) hem okul-ve-kurum-etkinlikleri/index.php
// (sunucuda üretilen herkese açık sayfa) bunu kullanır.
// Veri: ../../data/events.json (data/ klasörü .htaccess ile dışarıya kapalı).
// ==========================================================================

define('EVENTS_DATA_FILE', __DIR__ . '/../../data/events.json');

const EVENT_INSTITUTION_TYPES = [
    'okul' => 'Okul',
    'anaokulu' => 'Anaokulu / Kreş',
    'kutuphane' => 'Kütüphane',
    'belediye' => 'Belediye',
    'muze' => 'Müze / Kültür Merkezi',
    'diger' => 'Diğer Kurum',
];

const EVENT_MONTHS_TR = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz',
    'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

function events_read_all()
{
    if (!file_exists(EVENTS_DATA_FILE)) return [];
    $fp = @fopen(EVENTS_DATA_FILE, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function events_today()
{
    return (new DateTime('now', new DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
}

// Herkese açık liste: yalnızca yayındakiler. Yaklaşanlar (bugün veya sonrası
// biten) en yakın tarihten, geçmişler en yeniden başlayarak sıralanır.
function events_public_split()
{
    $today = events_today();
    $upcoming = [];
    $past = [];
    foreach (events_read_all() as $e) {
        if (($e['status'] ?? '') !== 'published') continue;
        $end = $e['endDate'] ?: $e['startDate'];
        if ($end >= $today) $upcoming[] = $e; else $past[] = $e;
    }
    usort($upcoming, fn($a, $b) => strcmp($a['startDate'], $b['startDate']));
    usort($past, fn($a, $b) => strcmp($b['startDate'], $a['startDate']));
    return [$upcoming, $past];
}

// "14 Kasım 2026", "14–16 Kasım 2026", "28 Ekim – 2 Kasım 2026"
function events_format_dates($start, $end = null)
{
    $s = DateTime::createFromFormat('!Y-m-d', $start);
    if (!$s) return '';
    $sd = (int) $s->format('j'); $sm = (int) $s->format('n'); $sy = $s->format('Y');
    $e = $end ? DateTime::createFromFormat('!Y-m-d', $end) : null;
    if (!$e || $end === $start) return $sd . ' ' . EVENT_MONTHS_TR[$sm] . ' ' . $sy;
    $ed = (int) $e->format('j'); $em = (int) $e->format('n'); $ey = $e->format('Y');
    if ($sy !== $ey) return $sd . ' ' . EVENT_MONTHS_TR[$sm] . ' ' . $sy . ' – ' . $ed . ' ' . EVENT_MONTHS_TR[$em] . ' ' . $ey;
    if ($sm !== $em) return $sd . ' ' . EVENT_MONTHS_TR[$sm] . ' – ' . $ed . ' ' . EVENT_MONTHS_TR[$em] . ' ' . $ey;
    return $sd . '–' . $ed . ' ' . EVENT_MONTHS_TR[$sm] . ' ' . $sy;
}

function events_type_label($type)
{
    return EVENT_INSTITUTION_TYPES[$type] ?? EVENT_INSTITUTION_TYPES['diger'];
}
