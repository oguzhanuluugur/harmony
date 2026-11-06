<?php
// ==========================================================================
// api/settings.php — Site ayarları (iletişim numarası, WhatsApp mesajı vb.).
// Admin panelindeki "Tüm Ayarları Kaydet" bu dosyaya yazar; site ise
// WhatsApp butonunu bu değerlerle açar. Veri: ../data/settings.json
// ==========================================================================

header('Content-Type: application/json; charset=utf-8');

// ⚠️ DEĞİŞTİRİN: admin.html'deki API_KEY ve diğer api/*.php dosyalarıyla aynı olmalı.
define('SETTINGS_FILE', __DIR__ . '/../data/settings.json');
define('MAX_VALUE_LENGTH', 2000);

// Kabul edilen alanlar ve varsayılan değerler. Buradaki anahtarlar dışındaki
// veriler kaydedilmez.
$DEFAULTS = [
    'title' => 'Harmony — Çocuklar İçin Şehir Tasarım Atölyeleri',
    'description' => 'Harmony, gözlem, keşif, hayal ve tasarım süreçleriyle çocukları şehirle buluşturan yaratıcı bir öğrenme platformudur.',
    'phone' => '0537 781 85 24',
    'instagram' => 'https://instagram.com/harmony.atolye',
    'address' => 'Postane Mah. Bağdagül Sk. No:6, Tuzla, İstanbul',
    'whatsappMessage' => 'Merhaba, Harmony atölyeleri hakkında bilgi almak ve kayıt oluşturmak istiyorum.',
    'gaId' => '',
    'metaPixelId' => '',
];

function send_json($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sanitize_text($value) {
    if (!is_string($value)) return '';
    return trim(str_replace(['<', '>'], '', $value));
}

function read_settings($defaults) {
    $stored = [];
    if (file_exists(SETTINGS_FILE)) {
        $content = @file_get_contents(SETTINGS_FILE);
        $decoded = json_decode((string) $content, true);
        if (is_array($decoded)) $stored = $decoded;
    }
    return array_merge($defaults, array_intersect_key($stored, $defaults));
}

function write_settings($settings) {
    $fp = @fopen(SETTINGS_FILE, 'c+');
    if (!$fp) send_json(500, ['error' => 'Ayarlar dosyasına yazılamadı. data/ klasörü izinlerini kontrol edin.']);
    if (!flock($fp, LOCK_EX)) send_json(500, ['error' => 'Ayarlar dosyası kilitlenemedi.']);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function require_write_key()
{
    // Yönetim paneli oturumu gerekir (bkz. api/_lib/auth.php ve api/auth.php).
    require_once __DIR__ . '/_lib/auth.php';
    harmony_require_admin();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Ayarlar sık değişmez ama kaydedilir kaydedilmez siteye yansımalı.
    header('Cache-Control: no-store');
    send_json(200, read_settings($DEFAULTS));
}

if ($method === 'PUT' || $method === 'POST') {
    require_write_key();
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) send_json(400, ['error' => 'Geçersiz veri gönderildi.']);

    $current = read_settings($DEFAULTS);
    foreach ($DEFAULTS as $key => $default) {
        if (!array_key_exists($key, $body)) continue;
        $value = sanitize_text($body[$key]);
        if (strlen($value) > MAX_VALUE_LENGTH) {
            send_json(400, ['error' => 'Bir alan çok uzun. Lütfen metni kısaltın.']);
        }
        $current[$key] = $value;
    }

    write_settings($current);
    send_json(200, $current);
}

send_json(405, ['error' => 'Desteklenmeyen metod.']);
