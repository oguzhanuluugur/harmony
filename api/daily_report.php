<?php
// ==========================================================================
// api/daily_report.php — Günlük Ziyaretçi Raporu (cPanel Cron Job).
//
// Tarayıcıdan/siteden ÇAĞRILMAZ — yalnızca cPanel'in "Cron Jobs" bölümünden,
// her gece belirli bir saatte (örn. 23:59) komut satırı PHP ile çalıştırılır:
//
//   /usr/local/bin/php /home/KULLANICI_ADINIZ/public_html/api/daily_report.php
//
// (Gerçek yolu cPanel > Cron Jobs sayfasındaki "PHP Yolu" örneğinden alın —
// her hosting'te birebir aynı olmayabilir.)
//
// data/visitors.json'daki "bugünkü" sayıyı okuyup admin_email'e HTML bir
// özet e-postası gönderir; JSON okuma ve SMTP mantığı messages.php ile
// birebir aynı standarttadır.
// ==========================================================================

define('VISITORS_DATA_FILE', __DIR__ . '/../data/visitors.json');

// Gmail SMTP ayarları ve $admin_email — messages.php ile ortak.
require __DIR__ . '/smtp_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function read_visitor_snapshot()
{
    if (!file_exists(VISITORS_DATA_FILE)) {
        return ['toplam_ziyaretci' => 0, 'bugunku_ziyaretci' => 0, 'son_ziyaret_tarihi' => date('Y-m-d')];
    }
    $content = @file_get_contents(VISITORS_DATA_FILE);
    $data = $content !== false ? json_decode($content, true) : null;
    if (!is_array($data)) {
        return ['toplam_ziyaretci' => 0, 'bugunku_ziyaretci' => 0, 'son_ziyaret_tarihi' => date('Y-m-d')];
    }
    return [
        'toplam_ziyaretci' => (int) ($data['toplam_ziyaretci'] ?? 0),
        'bugunku_ziyaretci' => (int) ($data['bugunku_ziyaretci'] ?? 0),
        'son_ziyaret_tarihi' => $data['son_ziyaret_tarihi'] ?? date('Y-m-d'),
    ];
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function build_report_email($snapshot)
{
    $dateLabel = $snapshot['son_ziyaret_tarihi'];
    try {
        $dateLabel = (new DateTime($snapshot['son_ziyaret_tarihi']))->format('d.m.Y');
    } catch (\Exception $e) {
        // Tarih formatı beklenmedikse ham değeri göster.
    }

    $subject = '[Harmony] Günlük Ziyaretçi Raporu (' . $dateLabel . ')';

    $body = '<!doctype html><html lang="tr"><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:Arial,Helvetica,sans-serif;background:#f4f5f6;padding:24px;margin:0;">'
        . '<div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e4e6e9;">'
        . '<div style="background:#1A2E40;padding:20px 24px;">'
        . '<h1 style="color:#ffffff;font-size:18px;margin:0;">Günlük Ziyaretçi Raporu</h1>'
        . '<p style="color:#c3c8cf;font-size:13px;margin:4px 0 0;">' . e($dateLabel) . '</p>'
        . '</div>'
        . '<table style="width:100%;border-collapse:collapse;font-size:14px;color:#1d2129;">'
        . '<tr><td style="padding:14px 24px;font-weight:bold;color:#4c5561;">Bugün Giren Ziyaretçi</td><td style="padding:14px 24px;text-align:right;font-size:18px;font-weight:bold;color:#d1652c;">' . e(number_format($snapshot['bugunku_ziyaretci'], 0, ',', '.')) . '</td></tr>'
        . '<tr><td style="padding:14px 24px;font-weight:bold;color:#4c5561;border-top:1px solid #e4e6e9;">Toplam Ziyaretçi (Tüm Zamanlar)</td><td style="padding:14px 24px;text-align:right;font-size:18px;font-weight:bold;color:#1A2E40;border-top:1px solid #e4e6e9;">' . e(number_format($snapshot['toplam_ziyaretci'], 0, ',', '.')) . '</td></tr>'
        . '</table>'
        . '<div style="padding:12px 24px;font-size:12px;color:#9aa2ad;border-top:1px solid #e4e6e9;">Harmony Planlama ve Kentsel Tasarım Atölyesi — harmonyplanlama.com — bu e-posta cPanel Cron Job tarafından otomatik gönderildi.</div>'
        . '</div>'
        . '</body></html>';

    return [$subject, $body];
}

function send_report_email($snapshot, $admin_email)
{
    if (empty($admin_email)) return false;

    list($subject, $body) = build_report_email($snapshot);

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        $mail->setFrom(SMTP_USERNAME, MAIL_FROM_NAME);
        $mail->addAddress($admin_email);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Harmony günlük rapor gönderilemedi: ' . $e->getMessage() . ' — ' . $mail->ErrorInfo);
        return false;
    }
}

$snapshot = read_visitor_snapshot();
$sent = send_report_email($snapshot, $admin_email);

// Cron çıktısı e-posta olarak da gelebileceğinden (cPanel ayarına göre) kısa
// bir satır yazdırıyoruz — asıl rapor zaten $admin_email'e gitti.
echo $sent
    ? "Günlük rapor gönderildi ({$snapshot['bugunku_ziyaretci']} bugün / {$snapshot['toplam_ziyaretci']} toplam).\n"
    : "Günlük rapor gönderilemedi — hata için error_log'a bakın.\n";
