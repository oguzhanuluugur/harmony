<?php
// ==========================================================================
// api/workshops.php — Atölyeler için PHP tabanlı backend (cPanel/paylaşımlı
// hosting). api/workshops.js ile aynı işi görür, ama GitHub/Vercel yerine
// doğrudan ../data/workshops.json dosyasını okur/yazar.
//
// ⚠️ GÜVENLİK NOTU: Orijinal workshops.js hiçbir kimlik doğrulama yapmıyordu
// (yalnızca admin.html'deki tarayıcı-taraflı giriş ekranına güveniyordu).
// GitHub üzerinde her yazma bir commit + geçmiş bırakıyordu; burada öyle bir
// güvenlik ağı yok. Bu yüzden POST/PUT/DELETE için aşağıda basit bir paylaşılan
// anahtar (API_WRITE_KEY) kontrolü ekledim — admin.html bunu her yazma
// isteğinde 'X-Api-Key' başlığıyla gönderiyor. Kendi rastgele, uzun bir
// değerle değiştirmeden canlıya almayın.
// ==========================================================================

header('Content-Type: application/json; charset=utf-8');

// ⚠️ DEĞİŞTİRİN: admin.html'deki API_KEY sabitiyle birebir aynı olmalı.
define('API_WRITE_KEY', 'CHANGE-ME-TO-A-LONG-RANDOM-SECRET');

define('DATA_FILE', __DIR__ . '/../data/workshops.json');
define('PLACEHOLDER_IMAGE', 'https://placehold.co/400x280/f4f5f6/9aa2ad?text=G%C3%B6rsel');
define('MAX_IMAGE_LENGTH', 700000); // ~700KB base64 — dosyanın çok büyümesini önler

function send_json($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_data() {
    if (!file_exists(DATA_FILE)) return [];
    $fp = @fopen(DATA_FILE, 'r');
    if (!$fp) send_json(500, ['error' => 'Veri dosyası okunamadı. Dosya/klasör izinlerini kontrol edin.']);
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function write_data($data) {
    $fp = @fopen(DATA_FILE, 'c+');
    if (!$fp) send_json(500, ['error' => 'Veri dosyasına yazılamadı. data/ klasörü ve workshops.json dosya izinlerini kontrol edin (bkz. CHMOD notları).']);
    if (!flock($fp, LOCK_EX)) send_json(500, ['error' => 'Veri dosyası kilitlenemedi.']);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function sanitize_text($value) {
    if (!is_string($value)) return '';
    return trim(str_replace(['<', '>'], '', $value));
}

function require_write_key() {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $provided = $headers['X-Api-Key'] ?? $headers['X-API-Key'] ?? ($_GET['key'] ?? '');
    if (!hash_equals(API_WRITE_KEY, (string) $provided)) {
        send_json(401, ['error' => 'Yetkisiz istek: geçersiz veya eksik API anahtarı.']);
    }
}

function normalize_workshop_input($body) {
    $errors = [];
    $name = sanitize_text($body['name'] ?? '');
    $age = sanitize_text($body['age'] ?? '');
    $month = sanitize_text($body['month'] ?? '');
    $date = sanitize_text($body['date'] ?? '');
    $time = sanitize_text($body['time'] ?? '');
    $price = sanitize_text($body['price'] ?? '');
    $status = (($body['status'] ?? '') === 'passive') ? 'passive' : 'active';

    if ($name === '') $errors[] = 'Atölye adı gerekli.';
    if ($age === '') $errors[] = 'Yaş grubu gerekli.';
    if ($month === '') $errors[] = 'Ay gerekli.';
    if ($date === '') $errors[] = 'Tarih gerekli.';
    if ($time === '') $errors[] = 'Saat gerekli.';
    if ($price === '') $errors[] = 'Fiyat gerekli.';

    $image = null;
    if (!empty($body['image']) && is_string($body['image'])) {
        if (strlen($body['image']) > MAX_IMAGE_LENGTH) {
            $errors[] = 'Görsel çok büyük. Lütfen daha küçük bir görsel seçin.';
        } else {
            $image = $body['image'];
        }
    }

    return [$errors, [
        'name' => $name, 'age' => $age, 'month' => $month, 'date' => $date,
        'time' => $time, 'price' => $price, 'status' => $status, 'image' => $image,
    ]];
}

$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? null;

if ($method === 'GET') {
    send_json(200, read_data());
}

if ($method === 'POST') {
    require_write_key();
    $data = read_data();
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
    list($errors, $workshop) = normalize_workshop_input($body);
    if (!empty($errors)) send_json(400, ['error' => implode(' ', $errors)]);

    $nextId = 0;
    foreach ($data as $w) { if ($w['id'] > $nextId) $nextId = $w['id']; }
    $nextId += 1;

    $created = array_merge(['id' => $nextId], $workshop);
    if (empty($created['image'])) $created['image'] = PLACEHOLDER_IMAGE;

    array_unshift($data, $created);
    write_data($data);
    send_json(201, $created);
}

if ($method === 'PUT') {
    require_write_key();
    if (!$id) send_json(400, ['error' => 'id parametresi gerekli.']);
    $data = read_data();
    $idx = null;
    foreach ($data as $i => $w) {
        if ((string) $w['id'] === (string) $id) { $idx = $i; break; }
    }
    if ($idx === null) send_json(404, ['error' => 'Atölye bulunamadı.']);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
    list($errors, $workshop) = normalize_workshop_input($body);
    if (!empty($errors)) send_json(400, ['error' => implode(' ', $errors)]);

    $existing = $data[$idx];
    $updated = array_merge($existing, $workshop);
    if (empty($workshop['image'])) $updated['image'] = $existing['image'];
    $data[$idx] = $updated;

    write_data($data);
    send_json(200, $updated);
}

if ($method === 'DELETE') {
    require_write_key();
    if (!$id) send_json(400, ['error' => 'id parametresi gerekli.']);
    $data = read_data();
    $found = false;
    $next = [];
    foreach ($data as $w) {
        if ((string) $w['id'] === (string) $id) { $found = true; continue; }
        $next[] = $w;
    }
    if (!$found) send_json(404, ['error' => 'Atölye bulunamadı.']);
    write_data($next);
    send_json(200, ['ok' => true]);
}

send_json(405, ['error' => 'Desteklenmeyen metod.']);
