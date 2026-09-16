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

// Sunucunun varsayılan saat dilimi (Türkticaret'te UTC) yerine Türkiye
// saatini kullan — aksi halde "saat" alanı ve gün değişimi (son_ziyaret_tarihi)
// 3 saat geriden hesaplanır.
date_default_timezone_set('Europe/Istanbul');

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
        'bugunku_ziyaretler' => [],
        'gunluk_gecmis' => [],
    ];
}

// Bugünkü ziyaret akışında (admin panelindeki "Göz" ikonu) tek bir günde
// tutulan kayıt sayısını sınırlar — anormal bir bot trafiği bile
// visitors.json'u şişirip her isteğin okuma/yazma süresini uzatmasın diye.
define('MAX_DAILY_VISIT_LOG', 300);

// ⚠️ GİZLİLİK NOTU: Burada KASITLI olarak ham IP adresi, tam User-Agent
// dizesi veya üçüncü taraf bir servise (ör. ip-api.com) konum sorgusu YOK.
// Yalnızca kaba/insan-okunur bir cihaz özeti ve trafik kaynağı çıkarılıp
// saklanıyor — KVKK/GDPR kapsamında "kişisel veri" sayılabilecek ham
// veriler hiç diske yazılmıyor. Tekil ziyaretçi sayımı için kullanılan IP
// zaten yalnızca hash'lenmiş haliyle tutuluyor (bkz. hash_ip()).
function describe_device($userAgent)
{
    $ua = (string) $userAgent;
    if ($ua === '') return 'Bilinmiyor';

    // Instagram/Facebook uygulama içi tarayıcılar önce kontrol edilmeli —
    // bunların User-Agent'ı genelde bir Chrome/Safari imzası da taşır.
    if (stripos($ua, 'Instagram') !== false) $app = 'Instagram Uygulaması';
    elseif (preg_match('/FBAN|FBAV/i', $ua)) $app = 'Facebook Uygulaması';
    else $app = null;

    if (preg_match('/iPad|Tablet/i', $ua)) $tip = 'Tablet';
    elseif (preg_match('/Mobile|Android|iPhone/i', $ua)) $tip = 'Mobil';
    else $tip = 'Masaüstü';

    if ($app) return $tip . ' · ' . $app;

    // Sıra önemli: Edge/OPR imzaları Chrome'u da içerir, Chrome imzası
    // Safari'yi de içerir — en spesifikten en genele doğru kontrol edilir.
    if (stripos($ua, 'Edg/') !== false) $tarayici = 'Edge';
    elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) $tarayici = 'Opera';
    elseif (stripos($ua, 'SamsungBrowser') !== false) $tarayici = 'Samsung Internet';
    elseif (stripos($ua, 'Firefox') !== false) $tarayici = 'Firefox';
    elseif (stripos($ua, 'Chrome') !== false) $tarayici = 'Chrome';
    elseif (stripos($ua, 'Safari') !== false) $tarayici = 'Safari';
    else $tarayici = 'Diğer';

    return $tip . ' · ' . $tarayici;
}

function describe_source($referer)
{
    $referer = trim((string) $referer);
    if ($referer === '') return 'Doğrudan / Yer İmi';

    $host = parse_url($referer, PHP_URL_HOST);
    if (!$host) return 'Doğrudan / Yer İmi';
    $host = strtolower(preg_replace('/^www\./', '', $host));

    // Kendi sitemizden gelen bir "referer" (örn. sayfa içi yönlendirme)
    // dış kaynak sayılmaz.
    if ($host === 'harmonyplanlama.com') return 'Site içi gezinme';

    $known = [
        'google.' => 'Google',
        'instagram.com' => 'Instagram',
        'facebook.com' => 'Facebook',
        'fb.com' => 'Facebook',
        'whatsapp.com' => 'WhatsApp',
        'bing.com' => 'Bing',
        'yandex.' => 'Yandex',
        't.co' => 'Twitter/X',
        'x.com' => 'Twitter/X',
    ];
    foreach ($known as $needle => $label) {
        if (strpos($host, $needle) !== false) return $label;
    }
    return $host;
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

function sanitize_page($page)
{
    $page = trim(str_replace(['<', '>'], '', (string) $page));
    if ($page === '') return '/';
    return mb_substr($page, 0, 120);
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
        $data['bugunku_ziyaretler'] = [];

        // gunluk_gecmis aksi halde sonsuza kadar büyür — dosyayı (ve her
        // isteğin okuma/yazma süresini) şişirmesin diye son 90 günle sınırla.
        if (count($data['gunluk_gecmis']) > 90) {
            ksort($data['gunluk_gecmis']);
            $data['gunluk_gecmis'] = array_slice($data['gunluk_gecmis'], -90, null, true);
        }
    }

    $isNewVisitor = !in_array($ipHash, $data['bugunku_ip_hashleri'], true);
    if ($isNewVisitor) {
        $data['bugunku_ip_hashleri'][] = $ipHash;
        $data['bugunku_ziyaretci'] += 1;
        $data['toplam_ziyaretci'] += 1;
    }

    // Sayfa/kaynak bilgisi sendBeacon ile gönderilen JSON gövdeden okunur —
    // "kaynak" burada tarayıcının document.referrer'ıdır (bu isteğin kendi
    // HTTP Referer başlığı değil, o zaten her zaman kendi sitemizi gösterir).
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
    $sayfa = sanitize_page(is_string($body['sayfa'] ?? null) ? $body['sayfa'] : '/');
    $kaynak = describe_source(is_string($body['kaynak'] ?? null) ? $body['kaynak'] : '');
    $cihaz = describe_device($_SERVER['HTTP_USER_AGENT'] ?? '');

    $data['bugunku_ziyaretler'][] = [
        'saat' => date('H:i'),
        'sayfa' => $sayfa,
        'kaynak' => $kaynak,
        'cihaz' => $cihaz,
    ];
    if (count($data['bugunku_ziyaretler']) > MAX_DAILY_VISIT_LOG) {
        $data['bugunku_ziyaretler'] = array_slice($data['bugunku_ziyaretler'], -MAX_DAILY_VISIT_LOG);
    }

    write_data($data);

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
        // En yeniler üstte gösterilsin diye ters çevriliyor.
        'bugunku_ziyaretler' => array_reverse($data['bugunku_ziyaretler']),
    ]);
}

send_json(405, ['error' => 'Desteklenmeyen metod.']);
