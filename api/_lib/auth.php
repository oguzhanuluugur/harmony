<?php
// ==========================================================================
// api/_lib/auth.php — Yönetim paneli için sunucu tarafı oturum kontrolü.
//
// Giriş bilgisi tarayıcıya hiç gönderilmez: e-posta + şifre özeti
// admin_config.php'de durur, giriş api/auth.php üzerinden yapılır ve PHP
// oturum çerezi (HttpOnly, SameSite=Strict) verilir. Yazma/okuma isteyen
// API uçları harmony_require_admin() ile bu oturumu kontrol eder.
// ==========================================================================

function harmony_session_start()
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('harmony_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function harmony_is_admin()
{
    harmony_session_start();
    $ok = !empty($_SESSION['harmony_admin']);
    // 8 saat işlem yapılmazsa oturum düşer.
    if ($ok && (time() - ($_SESSION['last_seen'] ?? 0)) > 8 * 3600) {
        $_SESSION = [];
        session_destroy();
        return false;
    }
    if ($ok) $_SESSION['last_seen'] = time();
    return $ok;
}

function harmony_require_admin()
{
    $ok = harmony_is_admin();
    // Oturum dosyası kilidini erken bırak — paralel istekler beklemesin.
    session_write_close();
    if (!$ok) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Oturum açılmamış veya süresi dolmuş. Lütfen tekrar giriş yapın.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
