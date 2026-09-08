<?php
// ==========================================================================
// api/visitors.php — Hafif ziyaretçi sayacı (cPanel/paylaşımlı hosting).
// MySQL kullanmıyoruz; sayaç verisi data/visitors.json dosyasında tutulur.
//
// POST herkese açıktır (ana sayfa yüklenince arka planda sessizce çağırır —
// kimlik doğrulama istemez, aksi halde herkese açık sayfadan tetiklenemez).
// GET ise admin panel içindir ve aşağıdaki paylaşılan anahtar
// (API_WRITE_KEY) ile korunur — messages.php/workshops.php/blog.php ile
// birebir aynı desen.
// ==========================================================================

header('Content-Type: application/json; charset=utf-8');

// ⚠️ DEĞİŞTİRİN: admin.html'deki API_KEY sabitiyle birebir aynı olmalı.
define('API_WRITE_KEY', 'HarmonyAdmin2026!');

define('DATA_FILE', __DIR__ . '/../data/visitors.json');

function send_json($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function default_visitor_data()
{
    return [
        'toplam_ziyaretci' => 0,
        'son_ziyaret_tarihi' => '',
        'bugunku_ziyaretci' => 0,
        'bugunku_ip_hashleri' => [],
        'gunluk_gecmis' => [],
    ];
}

function read_data()
{
    if (!file_exists(DATA_FILE)) return default_visitor_data();
    $fp = @fopen(DATA_FILE, 'r');
    if (!$fp) send_json(500, ['error' => 'Veri dosyası okunamadı. Dosya/klasör izinlerini kontrol edin.']);
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($content, true);
    return is_array($data) ? array_merge(default_visitor_data(), $data) : default_visitor_data();
}

function write_data($data)
{
    $fp = @fopen(DATA_FILE, 'c+');
    if (!$fp) send_json(500, ['error' => 'Veri dosyasına yazılamadı. data/ klasörü ve visitors.json dosya izinlerini kontrol edin (bkz. CHMOD notları).']);
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
    $provided = $_GET['apikey'] ?? '';
    if (!hash_equals(API_WRITE_KEY, (string) $provided)) {
        send_json(401, ['error' => 'Yetkisiz istek: geçersiz veya eksik API anahtarı.']);
    }
}

function get_client_ip()
{
    // Kasıtlı olarak yalnızca REMOTE_ADDR — X-Forwarded-For / Client-IP gibi
    // istemcinin gönderdiği başlıklar kolayca sahtelenip sayacı manipüle
    // etmek için kullanılabilir. (Cloudflare arkasındaysanız bunun yerine
    // HTTP_CF_CONNECTING_IP kullanmayı düşünebilirsiniz.)
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function hash_ip($ip)
{
    // Ham IP adresini JSON dosyasında saklamamak için hash'liyoruz — sadece
    // "bugün bu IP'yi zaten saydık mı?" kontrolü için kullanılıyor.
    // API_WRITE_KEY burada salt (tuzlama) amacıyla kullanılıyor.
    return hash('sha256', $ip . API_WRITE_KEY);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Kasıtlı olarak anahtar istenmiyor — ana sayfa herkes için tetikler.
    $data = read_data();
    $today = date('Y-m-d');
    $ipHash = hash_ip(get_client_ip());

    if ($data['son_ziyaret_tarihi'] !== $today) {
        // Gün değişti: dünün sayısını geçmişe arşivle, bugünü sıfırla.
        if ($data['son_ziyaret_tarihi'] !== '') {
            $data['gunluk_gecmis'][$data['son_ziyaret_tarihi']] = $data['bugunku_ziyaretci'];
        }
        $data['son_ziyaret_tarihi'] = $today;
        $data['bugunku_ziyaretci'] = 0;
        $data['bugunku_ip_hashleri'] = [];
    }

    $isNewVisitor = !in_array($ipHash, $data['bugunku_ip_hashleri'], true);
    if ($isNewVisitor) {
        $data['bugunku_ip_hashleri'][] = $ipHash;
        $data['bugunku_ziyaretci'] += 1;
        $data['toplam_ziyaretci'] += 1;
        write_data($data);
    }

    send_json(200, [
        'ok' => true,
        'yeni_ziyaretci' => $isNewVisitor,
        'bugunku_ziyaretci' => $data['bugunku_ziyaretci'],
        'toplam_ziyaretci' => $data['toplam_ziyaretci'],
    ]);
}

if ($method === 'GET') {
    require_write_key();
    $data = read_data();
    send_json(200, [
        'toplam_ziyaretci' => $data['toplam_ziyaretci'],
        'bugunku_ziyaretci' => $data['bugunku_ziyaretci'],
        'tarih' => $data['son_ziyaret_tarihi'],
        'gunluk_gecmis' => $data['gunluk_gecmis'],
    ]);
}

send_json(405, ['error' => 'Desteklenmeyen metod.']);
