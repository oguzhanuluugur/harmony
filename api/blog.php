<?php
// ==========================================================================
// api/blog.php — Blog & Yayınlar için PHP tabanlı backend (cPanel/paylaşımlı
// hosting). api/blog.js ile aynı işi görür, ama GitHub/Vercel yerine
// doğrudan ../data/blog.json dosyasını okur/yazar.
//
// NOT: Proje halihazırda data/blog.json (tekil) dosyasını kullanıyor ve
// içinde gerçek yazılarınız var — "blogs.json" (çoğul) diye bir dosya yok.
// Karışıklık olmasın diye burada da mevcut blog.json'a bağlandım.
//
// ⚠️ GÜVENLİK NOTU: workshops.php ile aynı gerekçeyle, POST/PUT/DELETE
// için bir paylaşılan anahtar (API_WRITE_KEY) kontrolü var. Kendi
// değerinizle değiştirin — admin.html'deki API_KEY ile birebir aynı olmalı.
// ==========================================================================

header('Content-Type: application/json; charset=utf-8');

// ⚠️ DEĞİŞTİRİN: admin.html'deki API_KEY sabitiyle birebir aynı olmalı.
define('API_WRITE_KEY', 'CHANGE-ME-TO-A-LONG-RANDOM-SECRET');

define('DATA_FILE', __DIR__ . '/../data/blog.json');
define('PLACEHOLDER_IMAGE', 'https://placehold.co/600x400/f4f5f6/9aa2ad?text=G%C3%B6rsel');
define('MAX_IMAGE_LENGTH', 700000);
define('EXCERPT_LENGTH', 170);

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
    if (!$fp) send_json(500, ['error' => 'Veri dosyasına yazılamadı. data/ klasörü ve blog.json dosya izinlerini kontrol edin (bkz. CHMOD notları).']);
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

function excerpt_from($paragraphs) {
    $first = $paragraphs[0] ?? '';
    if (strlen($first) <= EXCERPT_LENGTH) return $first;
    return rtrim(substr($first, 0, EXCERPT_LENGTH)) . '...';
}

function normalize_blog_input($body) {
    $errors = [];
    $title = sanitize_text($body['title'] ?? '');
    $category = sanitize_text($body['category'] ?? '');
    $date = sanitize_text($body['date'] ?? '');

    // Paragraflar tek bir metin alanı olarak yazılır, aralarında boş satır.
    $bodyText = is_string($body['body'] ?? null) ? $body['body'] : '';
    $rawParagraphs = preg_split('/\n\s*\n/', $bodyText);
    $paragraphs = [];
    foreach ($rawParagraphs as $p) {
        $clean = sanitize_text($p);
        if ($clean !== '') $paragraphs[] = $clean;
    }

    if ($title === '') $errors[] = 'Başlık gerekli.';
    if ($category === '') $errors[] = 'Kategori gerekli.';
    if ($date === '') $errors[] = 'Tarih gerekli.';
    if (empty($paragraphs)) $errors[] = 'İçerik gerekli.';

    $image = null;
    if (!empty($body['image']) && is_string($body['image'])) {
        if (strlen($body['image']) > MAX_IMAGE_LENGTH) {
            $errors[] = 'Görsel çok büyük. Lütfen daha küçük bir görsel seçin.';
        } else {
            $image = $body['image'];
        }
    }

    return [$errors, [
        'title' => $title, 'category' => $category, 'date' => $date,
        'body' => $paragraphs, 'excerpt' => excerpt_from($paragraphs), 'image' => $image,
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
    list($errors, $post) = normalize_blog_input($body);
    if (!empty($errors)) send_json(400, ['error' => implode(' ', $errors)]);

    $nextId = 0;
    foreach ($data as $p) { if ($p['id'] > $nextId) $nextId = $p['id']; }
    $nextId += 1;

    $created = array_merge(['id' => $nextId], $post);
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
    foreach ($data as $i => $p) {
        if ((string) $p['id'] === (string) $id) { $idx = $i; break; }
    }
    if ($idx === null) send_json(404, ['error' => 'Yazı bulunamadı.']);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
    list($errors, $post) = normalize_blog_input($body);
    if (!empty($errors)) send_json(400, ['error' => implode(' ', $errors)]);

    $existing = $data[$idx];
    $updated = array_merge($existing, $post);
    if (empty($post['image'])) $updated['image'] = $existing['image'];
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
    foreach ($data as $p) {
        if ((string) $p['id'] === (string) $id) { $found = true; continue; }
        $next[] = $p;
    }
    if (!$found) send_json(404, ['error' => 'Yazı bulunamadı.']);
    write_data($next);
    send_json(200, ['ok' => true]);
}

send_json(405, ['error' => 'Desteklenmeyen metod.']);
