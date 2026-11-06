<?php
// ==========================================================================
// api/gemini.php — Gemini'den Tuzla'daki kreş/anaokulu listesi ister.
// API anahtarı yalnızca sunucuda tutulur; tarayıcıya gönderilmez.
//
// ⚠️ Kurulum: GEMINI_API_KEY değerini kendi anahtarınızla değiştirin ve bu
// dosyayı git'e commit etmeyin (sunucudaki kopyayı cPanel'den düzenleyin).
// ⚠️ Yapay zeka kurum bilgisini uydurabilir; tabloya eklenen kayıtları
// telefon ve adreslerini arayarak doğrulayın.
// ==========================================================================

header('Content-Type: application/json; charset=utf-8');

define('GEMINI_API_KEY', 'BURAYA_GEMINI_ANAHTARINIZI_YAZIN');
define('GEMINI_MODEL', 'gemini-flash-lite-latest'); // model adı eskiyse burada güncelleyin

function send_json($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Yalnızca yönetim panelinde oturum açmış kullanıcı çağırabilir — aksi halde
// herkes Gemini kotasını harcayabilirdi.
require __DIR__ . '/_lib/auth.php';
harmony_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(405, ['error' => 'Desteklenmeyen metod.']);
}

if (GEMINI_API_KEY === 'BURAYA_GEMINI_ANAHTARINIZI_YAZIN') {
    send_json(500, ['error' => 'Gemini anahtarı sunucuda tanımlı değil (api/gemini.php).']);
}

$prompt = 'Tuzla, İstanbul bölgesindeki gerçek kreş, anaokulu ve çocuk gelişim merkezlerinden 10 tanesini bul. '
    . 'Sadece JSON formatında şu yapıda bir dizi döndür: [{"ad": "Kurum Adı", "mahalle": "Mahalle", "telefon": "Telefon"}]. '
    . 'ÇOK ÖNEMLİ KURAL: Telefon numaralarını KESİNLİKLE uydurma. Sadece internette gerçekten var olan, doğrulanmış '
    . 'telefon numaralarını yaz. Eğer bir kurumun gerçek numarasını bulamıyorsan veya %100 emin değilsen, telefon '
    . 'kısmına sadece "Bulunamadı" yaz. Sahte, rastgele veya 555\'li numara üretmen kesinlikle yasaktır.';

$payload = [
    'contents' => [['parts' => [['text' => $prompt]]]],
    // Yanıtın doğrudan JSON olmasını ister; yine de aşağıda temizlik yapılır.
    'generationConfig' => ['responseMimeType' => 'application/json'],
];

$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent';

// Geçici hatalarda (yoğunluk, kota, sunucu hatası) en fazla 3 kez denenir; aralarda 1 ve 2 sn beklenir.
$body = false;
$status = 0;
$maxAttempts = 3;
for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        // Anahtar URL'de değil başlıkta gönderilir (sunucu günlüklerinde görünmesin).
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . GEMINI_API_KEY,
        ],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $retryable = $body === false || in_array($status, [429, 500, 502, 503, 504], true);
    if (!$retryable || $attempt === $maxAttempts) break;
    sleep($attempt);
}

if ($body === false || $status !== 200) {
    $detail = '';
    if (is_string($body)) {
        $errorBody = json_decode($body, true);
        $detail = $errorBody['error']['message'] ?? '';
    }
    send_json(502, ['error' => 'Gemini yanıt vermedi (HTTP ' . $status . ')' . ($detail !== '' ? ': ' . $detail : '') . '.']);
}

$data = json_decode($body, true);
$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

// Model yine de ``` kod bloğu koyarsa işaretleri temizle.
$text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)));
$items = json_decode($text, true);

if (!is_array($items)) {
    send_json(502, ['error' => 'Yapay zeka geçerli bir JSON listesi döndürmedi. Tekrar deneyin.']);
}

$results = [];
foreach ($items as $item) {
    if (!is_array($item)) continue;
    $name = trim((string) ($item['ad'] ?? ''));
    $district = trim((string) ($item['mahalle'] ?? ''));
    $phone = trim((string) ($item['telefon'] ?? ''));
    // "Bulunamadı"/"Bilinmiyor" gibi yanıtları ve rakam içermeyen her şeyi boş say —
    // tabloya yalnızca gerçek görünen numaralar girsin, geri kalanı WhatsApp
    // butonunda "numara eksik" uyarısına düşsün.
    $normalizedPhone = mb_strtolower($phone, 'UTF-8');
    if ($normalizedPhone === 'bulunamadı' || $normalizedPhone === 'bilinmiyor' || !preg_match('/\d/', $phone)) {
        $phone = '';
    }
    $results[] = [
        'name' => $name,
        'location' => $district !== '' ? $district . ', Tuzla' : 'Tuzla',
        'phone' => $phone,
    ];
}

send_json(200, ['results' => $results]);
