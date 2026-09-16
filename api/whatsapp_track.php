<?php
// ==========================================================================
// api/whatsapp_track.php — "Hemen Kaydol" / WhatsApp CTA'larına yapılan
// tıklamaları sayan hafif dönüşüm (conversion) sayacı. MySQL kullanmıyoruz;
// sayaç data/whatsapp_clicks.json dosyasında tutulur (bkz. visitors.php ile
// birebir aynı okuma/yazma/kilitleme deseni).
//
// POST herkese açıktır (index.html'deki WHATSAPP_URL'e yönlendiren her
// buton tıklamasında sessizce tetiklenir — kimlik doğrulama istemez, aksi
// halde herkese açık sayfadan çağrılamaz). GET ise admin panel içindir ve
// aşağıdaki paylaşılan anahtar (API_WRITE_KEY) ile korunur.
// ==========================================================================

header('Content-Type: application/json; charset=utf-8');

// Sunucunun varsayılan saat dilimi (Türkticaret'te UTC) yerine Türkiye
// saatini kullan — bkz. visitors.php'deki aynı düzeltme.
date_default_timezone_set('Europe/Istanbul');

// ⚠️ DEĞİŞTİRİN: admin.html'deki API_KEY sabitiyle birebir aynı olmalı.
define('API_WRITE_KEY', 'HarmonyAdmin2026!');

define('DATA_FILE', __DIR__ . '/../data/whatsapp_clicks.json');

function send_json($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function default_click_data()
{
    return [
        'toplam_tiklama' => 0,
        'son_tiklama_tarihi' => '',
        'bugunku_tiklama' => 0,
    ];
}

function read_data()
{
    if (!file_exists(DATA_FILE)) return default_click_data();
    $fp = @fopen(DATA_FILE, 'r');
    if (!$fp) send_json(500, ['error' => 'Veri dosyası okunamadı. Dosya/klasör izinlerini kontrol edin.']);
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($content, true);
    return is_array($data) ? array_merge(default_click_data(), $data) : default_click_data();
}

function write_data($data)
{
    $fp = @fopen(DATA_FILE, 'c+');
    if (!$fp) send_json(500, ['error' => 'Veri dosyasına yazılamadı. data/ klasörü ve whatsapp_clicks.json dosya izinlerini kontrol edin.']);
    if (!flock($fp, LOCK_EX)) send_json(500, ['error' => 'Veri dosyası kilitlenemedi.']);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function require_write_key()
{
    // Turkticaret cPanel güvenlik duvarı özel HTTP başlıklarını sildiği için
    // anahtar header yerine URL sorgu parametresinden okunuyor (bkz.
    // diğer api/*.php dosyalarındaki aynı desen).
    $provided = $_GET['apikey'] ?? '';
    if (!hash_equals(API_WRITE_KEY, (string) $provided)) {
        send_json(401, ['error' => 'Yetkisiz istek: geçersiz veya eksik API anahtarı.']);
    }
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Kasıtlı olarak anahtar istenmiyor — herkese açık siteden tetiklenir.
    $data = read_data();
    $today = date('Y-m-d');

    if ($data['son_tiklama_tarihi'] !== $today) {
        $data['son_tiklama_tarihi'] = $today;
        $data['bugunku_tiklama'] = 0;
    }

    $data['bugunku_tiklama'] += 1;
    $data['toplam_tiklama'] += 1;
    write_data($data);

    send_json(200, [
        'ok' => true,
        'bugunku_tiklama' => $data['bugunku_tiklama'],
        'toplam_tiklama' => $data['toplam_tiklama'],
    ]);
}

if ($method === 'GET') {
    require_write_key();
    $data = read_data();
    send_json(200, [
        'bugunku_tiklama' => $data['bugunku_tiklama'],
        'toplam_tiklama' => $data['toplam_tiklama'],
    ]);
}

send_json(405, ['error' => 'Desteklenmeyen metod.']);
