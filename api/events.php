<?php
// ==========================================================================
// api/events.php — Okul ve kurum etkinlikleri (yönetim paneli + ana sayfa).
//   GET  ?public=1  → yayındaki etkinlikler { upcoming: [...], past: [...] }
//   GET             → tüm kayıtlar (yalnızca panel oturumu)
//   POST / PUT ?id= / DELETE ?id= → yalnızca panel oturumu
// Veri: ../data/events.json. Fotoğraflar ../event-photos/ klasörüne kaydedilir.
// ==========================================================================

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib/events_store.php';

define('MAX_IMAGE_LENGTH', 2200000); // base64 ~1.6 MB; panel görseli göndermeden önce küçültür
define('MAX_IMAGES', 3);
define('UPLOAD_DIR', __DIR__ . '/../event-photos');
define('UPLOAD_URL_PREFIX', 'event-photos');

function send_json($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_admin()
{
    require_once __DIR__ . '/_lib/auth.php';
    harmony_require_admin();
}

function write_data($data)
{
    $fp = @fopen(EVENTS_DATA_FILE, 'c+');
    if (!$fp) send_json(500, ['error' => 'Veri dosyasına yazılamadı. data/ klasörünün yazma iznini kontrol edin.']);
    if (!flock($fp, LOCK_EX)) send_json(500, ['error' => 'Veri dosyası kilitlenemedi.']);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function sanitize_text($value)
{
    if (!is_string($value)) return '';
    return trim(str_replace(['<', '>'], '', $value));
}

function normalize_iso_day($value)
{
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) return null;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
}

// Panelden gelen base64 görseli doğrular, gerçekten görsel mi diye içeriğe
// bakar ve diske yazar. Zaten kayıtlı bir yol geldiyse onu korur.
function save_image($value, &$errors)
{
    if (!is_string($value) || $value === '') return null;
    if (preg_match('#^' . UPLOAD_URL_PREFIX . '/[a-f0-9]{16}\.(jpg|png|webp)$#', $value)) return $value;
    if (!preg_match('/^data:image\/(jpeg|png|webp);base64,(.+)$/', $value, $m)) {
        $errors[] = 'Fotoğraf biçimi desteklenmiyor (JPG, PNG veya WebP olmalı).';
        return null;
    }
    if (strlen($value) > MAX_IMAGE_LENGTH) {
        $errors[] = 'Fotoğraflardan biri çok büyük. Daha küçük bir fotoğraf seçin.';
        return null;
    }
    $binary = base64_decode($m[2], true);
    $info = $binary !== false ? @getimagesizefromstring($binary) : false;
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($allowed[$info[2]])) {
        $errors[] = 'Fotoğraf dosyası okunamadı.';
        return null;
    }
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $filename = bin2hex(random_bytes(8)) . '.' . $allowed[$info[2]];
    file_put_contents(UPLOAD_DIR . '/' . $filename, $binary);
    return UPLOAD_URL_PREFIX . '/' . $filename;
}

// Artık hiçbir kayıtta kullanılmayan, bu API'nin yüklediği fotoğraf dosyalarını siler.
// Yalnızca event-photos/<16 hex>.<uzantı> biçimindeki yollara dokunur.
function delete_unused_images($oldImages, $keptImages)
{
    $kept = array_column($keptImages ?: [], 'src');
    foreach ($oldImages ?: [] as $im) {
        $src = $im['src'] ?? '';
        if (in_array($src, $kept, true)) continue;
        if (!preg_match('#^' . UPLOAD_URL_PREFIX . '/([a-f0-9]{16}\.(jpg|png|webp))$#', $src, $m)) continue;
        $file = UPLOAD_DIR . '/' . $m[1];
        if (is_file($file)) @unlink($file);
    }
}

function delete_unused_videos($old, $kept)
{
    $paths = array_column($kept, 'src');
    foreach ($old as $video) {
        $src = $video['src'] ?? '';
        if (!in_array($src, $paths, true) && preg_match('#^event-videos/([a-f0-9]{32}\.(mp4|webm))$#', $src, $m)) {
            @unlink(__DIR__ . '/../event-videos/' . $m[1]);
        }
    }
}

function read_event_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        send_json(400, ['error' => 'Etkinlik verisi sunucuya ulaşmadı. Sayfayı yenileyip tekrar deneyin.']);
    }
    $body = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($body) || substr(ltrim($raw), 0, 1) !== '{') {
        send_json(400, ['error' => 'Etkinlik isteği okunamadı. Güncel admin.html ve api/events.php dosyalarını birlikte yükleyin.']);
    }
    return $body;
}

function normalize_event_input($body)
{
    $errors = [];
    $title = sanitize_text($body['title'] ?? '');
    $institutionName = sanitize_text($body['institutionName'] ?? '');
    $institutionType = sanitize_text($body['institutionType'] ?? '');
    if (!isset(EVENT_INSTITUTION_TYPES[$institutionType])) $institutionType = 'diger';
    $district = sanitize_text($body['district'] ?? '');
    $neighborhood = sanitize_text($body['neighborhood'] ?? '');
    $ageGroup = sanitize_text($body['ageGroup'] ?? '');
    $participants = $body['participants'] ?? null;
    $participants = (is_numeric($participants) && (int) $participants > 0) ? (int) $participants : null;
    $description = trim(str_replace(['<', '>'], '', (string) ($body['description'] ?? '')));
    $link = sanitize_text($body['link'] ?? '');
    $status = (($body['status'] ?? '') === 'draft') ? 'draft' : 'published';
    $startDate = normalize_iso_day($body['startDate'] ?? null);
    $endDate = normalize_iso_day($body['endDate'] ?? null);

    if ($title === '') $errors[] = 'Etkinlik adı gerekli.';
    if ($institutionName === '') $errors[] = 'Kurum adı gerekli.';
    if ($district === '') $errors[] = 'İlçe gerekli.';
    if ($startDate === null) $errors[] = 'Başlangıç tarihi gerekli.';
    if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
        $errors[] = 'Bitiş tarihi başlangıç tarihinden önce olamaz.';
    }
    if ($endDate === $startDate) $endDate = null;
    if ($description === '') $errors[] = 'Açıklama gerekli.';
    // Link sayfada href olarak basılır: yalnızca http(s) kabul edilir.
    if ($link !== '' && (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $link))) {
        $errors[] = 'Link http:// veya https:// ile başlayan geçerli bir adres olmalı.';
    }

    $images = [];
    $altTexts = [];
    $rawImages = is_array($body['images'] ?? null) ? array_slice($body['images'], 0, MAX_IMAGES) : [];
    foreach ($rawImages as $img) {
        $src = is_array($img) ? ($img['src'] ?? '') : $img;
        $alt = is_array($img) ? sanitize_text($img['alt'] ?? '') : '';
        $saved = save_image($src, $errors);
        if ($saved) $images[] = ['src' => $saved, 'alt' => $alt];
    }

    $videos = [];
    $rawVideos = $body['videos'] ?? [];
    if (!is_array($rawVideos) || count($rawVideos) > 3) {
        $errors[] = 'En fazla 3 video ekleyebilirsiniz.';
        $rawVideos = [];
    }
    foreach ($rawVideos as $video) {
        $src = is_array($video) ? ($video['src'] ?? '') : '';
        if (!is_string($src) || !preg_match('#^event-videos/[a-f0-9]{32}\.(mp4|webm)$#', $src)
            || !is_file(__DIR__ . '/../' . $src)) {
            $errors[] = 'Video dosyası bulunamadı veya biçimi geçersiz.';
        } else { $videos[] = ['src' => $src]; }
    }

    return [$errors, [
        'title' => $title,
        'institutionName' => $institutionName,
        'institutionType' => $institutionType,
        'district' => $district,
        'neighborhood' => $neighborhood,
        'startDate' => $startDate,
        'endDate' => $endDate,
        'ageGroup' => $ageGroup,
        'participants' => $participants,
        'description' => $description,
        'images' => $images,
        'videos' => $videos,
        'link' => $link,
        'status' => $status,
    ]];
}

$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? null;

if ($method === 'GET' && isset($_GET['capabilities'])) {
    require_admin();
    header('Cache-Control: no-store');
    send_json(200, ['mediaUploadVersion' => 1]);
}

if ($method === 'GET') {
    if (($_GET['public'] ?? '') === '1') {
        [$upcoming, $past] = events_public_split();
        $shape = function ($e) {
            return $e + [
                'dateLabel' => events_format_dates($e['startDate'], $e['endDate'] ?? null),
                'typeLabel' => events_type_label($e['institutionType'] ?? ''),
            ];
        };
        header('Cache-Control: public, max-age=60');
        send_json(200, ['upcoming' => array_map($shape, $upcoming), 'past' => array_map($shape, $past)]);
    }
    require_admin();
    send_json(200, events_read_all());
}

if ($method === 'POST' && isset($_GET['upload_video'])) {
    require_admin();
    $file = $_FILES['video'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) send_json(400, ['error' => 'Video yüklenemedi. Sunucunun upload_max_filesize ve post_max_size sınırlarını kontrol edin.']);
    if ($file['size'] <= 0 || $file['size'] > 20 * 1024 * 1024) send_json(400, ['error' => 'Video en fazla 20 MB olabilir.']);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = ['video/mp4' => 'mp4', 'video/webm' => 'webm'];
    if (!isset($allowed[$mime])) send_json(400, ['error' => 'Yalnızca MP4 ve WebM videoları desteklenir.']);
    $dir = __DIR__ . '/../event-videos';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) send_json(500, ['error' => 'Video klasörü oluşturulamadı.']);
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) send_json(500, ['error' => 'Video kaydedilemedi.']);
    send_json(201, ['src' => 'event-videos/' . $name]);
}

if ($method === 'POST') {
    require_admin();
    $body = read_event_body();
    [$errors, $event] = normalize_event_input($body);
    if ($errors) send_json(400, ['error' => implode(' ', array_unique($errors))]);
    $data = events_read_all();
    $nextId = 0;
    foreach ($data as $e) if ($e['id'] > $nextId) $nextId = $e['id'];
    $created = ['id' => $nextId + 1] + $event + ['createdAt' => date('c')];
    array_unshift($data, $created);
    write_data($data);
    send_json(201, $created);
}

if ($method === 'PUT') {
    require_admin();
    if (!$id) send_json(400, ['error' => 'id parametresi gerekli.']);
    $body = read_event_body();
    [$errors, $event] = normalize_event_input($body);
    if ($errors) send_json(400, ['error' => implode(' ', array_unique($errors))]);
    $data = events_read_all();
    foreach ($data as $i => $e) {
        if ((string) $e['id'] === (string) $id) {
            $data[$i] = array_merge($e, $event, ['updatedAt' => date('c')]);
            write_data($data);
            delete_unused_images($e['images'] ?? [], $event['images']);
            delete_unused_videos($e['videos'] ?? [], $event['videos']);
            send_json(200, $data[$i]);
        }
    }
    send_json(404, ['error' => 'Etkinlik bulunamadı.']);
}

if ($method === 'DELETE') {
    require_admin();
    if (!$id) send_json(400, ['error' => 'id parametresi gerekli.']);
    $data = events_read_all();
    $removed = array_values(array_filter($data, fn($e) => (string) $e['id'] === (string) $id));
    $next = array_values(array_filter($data, fn($e) => (string) $e['id'] !== (string) $id));
    if (!$removed) send_json(404, ['error' => 'Etkinlik bulunamadı.']);
    write_data($next);
    delete_unused_images($removed[0]['images'] ?? [], []);
    delete_unused_videos($removed[0]['videos'] ?? [], []);
    send_json(200, ['ok' => true]);
}

send_json(405, ['error' => 'Desteklenmeyen metod.']);
