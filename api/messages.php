<?php
// ==========================================================================
// api/messages.php — İletişim formu mesajları için PHP tabanlı backend
// (cPanel/paylaşımlı hosting). api/messages.js ile aynı işi görür.
//
// POST herkese açıktır (sitedeki İletişim formu buraya yazar — kimlik
// doğrulama istemez). GET ve DELETE ise admin panel içindir ve aşağıdaki
// paylaşılan anahtar (API_WRITE_KEY) ile korunur.
// ==========================================================================

header('Content-Type: application/json; charset=utf-8');

// ⚠️ DEĞİŞTİRİN: admin.html'deki API_KEY sabitiyle birebir aynı olmalı.
define('API_WRITE_KEY', 'HarmonyAdmin2026!');

define('DATA_FILE', __DIR__ . '/../data/messages.json');

// ⚠️ DEĞİŞTİRİN: Yeni mesaj bildirimlerinin gideceği e-posta adresi.
$admin_email = 'kendi_epostaniz@gmail.com';

// ⚠️ DEĞİŞTİRİN: mail() başlığında kullanılan gönderen adresi — spam'e
// düşmemesi için sitenizin kendi alan adına ait bir adres olmalı.
define('MAIL_FROM_ADDRESS', 'noreply@harmonyplanlama.com');
define('MAIL_FROM_NAME', 'Harmony İletişim Formu');

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
    if (!$fp) send_json(500, ['error' => 'Veri dosyasına yazılamadı. data/ klasörü ve messages.json dosya izinlerini kontrol edin (bkz. CHMOD notları).']);
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
    // Turkticaret cPanel güvenlik duvarı özel HTTP başlıklarını (X-Api-Key)
    // sildiği için anahtar artık header yerine URL sorgu parametresinden okunuyor.
    $provided = $_GET['apikey'] ?? '';
    if (!hash_equals(API_WRITE_KEY, (string) $provided)) {
        send_json(401, ['error' => 'Yetkisiz istek: geçersiz veya eksik API anahtarı.']);
    }
}

function normalize_message_input($body) {
    $errors = [];
    $type = sanitize_text($body['type'] ?? 'bireysel');
    if ($type !== 'kurumsal') $type = 'bireysel';

    $email = sanitize_text($body['email'] ?? '');
    $phone = sanitize_text($body['phone'] ?? '');
    $message = sanitize_text($body['message'] ?? '');

    if ($email === '') {
        $errors[] = 'E-posta gerekli.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'E-posta adresi geçerli değil.';
    }
    if ($phone === '') $errors[] = 'Telefon numarası gerekli.';
    if ($message === '') $errors[] = 'Mesaj gerekli.';

    $entry = ['type' => $type, 'email' => $email, 'phone' => $phone, 'message' => $message];

    if ($type === 'kurumsal') {
        $institutionName = sanitize_text($body['institutionName'] ?? '');
        $authorizedPerson = sanitize_text($body['authorizedPerson'] ?? '');
        if ($institutionName === '') $errors[] = 'Kurum/Okul adı gerekli.';
        if ($authorizedPerson === '') $errors[] = 'Yetkili adı soyadı gerekli.';
        $entry['institutionName'] = $institutionName;
        $entry['authorizedPerson'] = $authorizedPerson;
        $entry['name'] = $authorizedPerson;
    } else {
        $name = sanitize_text($body['name'] ?? '');
        if ($name === '') $errors[] = 'Ad Soyad gerekli.';
        $entry['name'] = $name;
    }

    return [$errors, $entry];
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function build_notification_email($entry) {
    $isCorporate = $entry['type'] === 'kurumsal';
    $typeLabel = $isCorporate ? 'Kurumsal' : 'Bireysel';

    $rows = '';
    if ($isCorporate) {
        $rows .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#4c5561;">Kurum/Okul Adı</td><td style="padding:8px 12px;">' . e($entry['institutionName']) . '</td></tr>';
        $rows .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#4c5561;">Yetkili Kişi</td><td style="padding:8px 12px;">' . e($entry['authorizedPerson']) . '</td></tr>';
    } else {
        $rows .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#4c5561;">Ad Soyad</td><td style="padding:8px 12px;">' . e($entry['name']) . '</td></tr>';
    }
    $rows .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#4c5561;">E-posta</td><td style="padding:8px 12px;"><a href="mailto:' . e($entry['email']) . '">' . e($entry['email']) . '</a></td></tr>';
    $rows .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#4c5561;">Telefon</td><td style="padding:8px 12px;"><a href="tel:' . e($entry['phone']) . '">' . e($entry['phone']) . '</a></td></tr>';
    $rows .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#4c5561;vertical-align:top;">' . ($isCorporate ? 'İşbirliği Konusu' : 'Mesaj') . '</td><td style="padding:8px 12px;white-space:pre-line;">' . e($entry['message']) . '</td></tr>';

    $subject = '[Harmony] Yeni İletişim Mesajı (' . $typeLabel . ')';

    $body = '<!doctype html><html lang="tr"><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:Arial,Helvetica,sans-serif;background:#f4f5f6;padding:24px;margin:0;">'
        . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e4e6e9;">'
        . '<div style="background:#1A2E40;padding:20px 24px;">'
        . '<span style="display:inline-block;background:' . ($isCorporate ? '#d1652c' : '#5c8a6b') . ';color:#ffffff;font-size:12px;font-weight:bold;padding:4px 10px;border-radius:999px;">' . e($typeLabel) . '</span>'
        . '<h1 style="color:#ffffff;font-size:18px;margin:12px 0 0;">Yeni İletişim Formu Mesajı</h1>'
        . '</div>'
        . '<table style="width:100%;border-collapse:collapse;font-size:14px;color:#1d2129;">' . $rows . '</table>'
        . '<div style="padding:12px 24px;font-size:12px;color:#9aa2ad;border-top:1px solid #e4e6e9;">Harmony Planlama ve Kentsel Tasarım Atölyesi — harmonyplanlama.com</div>'
        . '</div>'
        . '</body></html>';

    return [$subject, $body];
}

function send_message_notification($entry, $admin_email) {
    if (empty($admin_email)) return;

    list($subject, $body) = build_notification_email($entry);

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . ">\r\n";
    $headers .= 'Reply-To: ' . $entry['email'] . "\r\n";

    // Bildirim e-postası gönderilemese bile (paylaşımlı hosting'te mail()
    // güvenilmez olabilir) mesaj zaten JSON'a kaydedildi — bu yüzden hata
    // burada sessizce yutulur, isteğin başarısını etkilemez.
    @mail($admin_email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? null;

if ($method === 'GET') {
    require_write_key();
    send_json(200, read_data());
}

if ($method === 'POST') {
    // Kasıtlı olarak anahtar istenmiyor — sitedeki herkes mesaj gönderebilmeli.
    $data = read_data();
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
    list($errors, $entry) = normalize_message_input($body);
    if (!empty($errors)) send_json(400, ['error' => implode(' ', $errors)]);

    $nextId = 0;
    foreach ($data as $m) { if ($m['id'] > $nextId) $nextId = $m['id']; }
    $nextId += 1;

    $created = array_merge(['id' => $nextId], $entry, ['createdAt' => date('c')]);
    array_unshift($data, $created);
    write_data($data);

    send_message_notification($created, $admin_email);

    send_json(201, $created);
}

if ($method === 'DELETE') {
    require_write_key();
    if (!$id) send_json(400, ['error' => 'id parametresi gerekli.']);
    $data = read_data();
    $found = false;
    $next = [];
    foreach ($data as $m) {
        if ((string) $m['id'] === (string) $id) { $found = true; continue; }
        $next[] = $m;
    }
    if (!$found) send_json(404, ['error' => 'Mesaj bulunamadı.']);
    write_data($next);
    send_json(200, ['ok' => true]);
}

send_json(405, ['error' => 'Desteklenmeyen metod.']);
