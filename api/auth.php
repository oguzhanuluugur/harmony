<?php
// ==========================================================================
// api/auth.php — Yönetim paneli girişi.
//   GET    → { authenticated: bool }
//   POST   → { email, password } ile giriş
//   DELETE → çıkış
// Aynı IP'den 15 dakikada 5 hatalı denemeden sonra giriş geçici olarak kapanır.
// ==========================================================================

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/_lib/auth.php';
$config = require __DIR__ . '/_lib/admin_config.php';

define('ATTEMPTS_FILE', __DIR__ . '/../data/login_attempts.json');
define('MAX_ATTEMPTS', 5);
define('WINDOW_SECONDS', 15 * 60);

function send_json($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function client_key()
{
    return hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

// Hatalı denemeleri okur/günceller. $record: null → sadece oku, true → başarısız ekle, false → sıfırla.
function attempts($record = null)
{
    $fp = @fopen(ATTEMPTS_FILE, 'c+');
    if (!$fp) return 0;
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp), true);
    if (!is_array($data)) $data = [];
    $now = time();
    foreach ($data as $k => $list) {
        $data[$k] = array_values(array_filter($list, fn($t) => $now - $t < WINDOW_SECONDS));
        if (!$data[$k]) unset($data[$k]);
    }
    $key = client_key();
    if ($record === true) $data[$key][] = $now;
    if ($record === false) unset($data[$key]);
    $count = count($data[$key] ?? []);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $count;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $ok = harmony_is_admin();
    session_write_close();
    send_json(200, ['authenticated' => $ok]);
}

if ($method === 'POST') {
    if (attempts() >= MAX_ATTEMPTS) {
        send_json(429, ['error' => 'Çok fazla hatalı deneme yapıldı. 15 dakika sonra tekrar deneyin.']);
    }
    $body = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');

    $emailOk = hash_equals(strtolower($config['email']), $email);
    $passOk = password_verify($password, $config['password_hash']);
    if (!$emailOk || !$passOk) {
        attempts(true);
        send_json(401, ['error' => 'E-posta veya şifre hatalı. Lütfen tekrar deneyin.']);
    }

    attempts(false);
    harmony_session_start();
    session_regenerate_id(true);
    $_SESSION['harmony_admin'] = true;
    $_SESSION['last_seen'] = time();
    session_write_close();
    send_json(200, ['authenticated' => true]);
}

if ($method === 'DELETE') {
    harmony_session_start();
    $_SESSION = [];
    session_destroy();
    send_json(200, ['authenticated' => false]);
}

send_json(405, ['error' => 'Desteklenmeyen metod.']);
