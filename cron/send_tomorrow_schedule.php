<?php
/**
 * Cron: ตรวจสอบว่าถึงเวลาแจ้งเตือนหรือยัง (ตาม notify_time ใน data/telegram.json)
 * ถ้าถึงเวลา และยังไม่ได้ส่งวันนี้ → ส่งตารางงานวันพรุ่งนี้ไป Telegram
 * ควรรันทุก 5 นาที (cron): ใส่ตาราง "ทุก 5 นาที" ตามด้วย
 *   php path/to/cron/send_tomorrow_schedule.php
 */
$root = dirname(__DIR__);
require $root . '/app/telegram.php';

$settings = getTelegramSettings();
$notifyTime = $settings['notify_time'] ?? '18:00';
$token = trim($settings['bot_token']);
$chatId = trim($settings['chat_id']);

if ($token === '' || $chatId === '') {
    exit(0); // ไม่มีตั้งค่า ไม่ทำอะไร
}

$now = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
$current = $now->format('H:i');
$today = $now->format('Y-m-d');

// เปรียบเทียบเวลา (ถ้ารันทุก 5 นาที ให้ถือว่า 18:00, 18:05, 18:10 ... ตรงกับ 18:00 ได้ถ้าเราตั้งว่าแจ้ง 18:00)
// ใช้ช่วง 5 นาที: ถ้า current อยู่ระหว่าง notify_time ถึง notify_time+5min
$notifyParts = explode(':', $notifyTime);
$notifyHour = (int) ($notifyParts[0] ?? 18);
$notifyMin = (int) ($notifyParts[1] ?? 0);
$notifyMinStart = $notifyHour * 60 + $notifyMin;
$nowMin = (int) $now->format('H') * 60 + (int) $now->format('i');
$windowStart = $notifyMinStart;
$windowEnd = $notifyMinStart + 5; // 5 นาที

if ($nowMin < $windowStart || $nowMin >= $windowEnd) {
    exit(0); // ยังไม่ถึงเวลา
}

if (($settings['last_sent_date'] ?? '') === $today) {
    exit(0); // ส่งไปแล้ววันนี้
}

$tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');

$pdo = require $root . '/app/db.php';
$stmt = $pdo->prepare("
    SELECT b.customer_name, b.customer_phone, b.start_time, b.end_time, b.num_people,
           (SELECT GROUP_CONCAT(c.name) FROM booking_category_pivot p
            JOIN booking_categories c ON c.id = p.category_id WHERE p.booking_id = b.id) AS category_names
    FROM bookings b
    WHERE b.appointment_date = ?
    ORDER BY b.start_time
");
$stmt->execute([$tomorrow]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tomorrowThai = (new DateTime($tomorrow))->format('d/m/Y');
$lines = ["📅 <b>ตารางงานวันพรุ่งนี้</b> ({$tomorrowThai})", ''];

if (count($rows) === 0) {
    $lines[] = 'ไม่มีคิวงาน';
} else {
    // escape อักขระ HTML (&, <, >) เพราะส่งด้วย parse_mode=HTML — กันข้อความล่ม/ฝัง HTML จากชื่อลูกค้า
    $esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    foreach ($rows as $i => $r) {
        $start = date('H:i', strtotime($r['start_time']));
        $end = date('H:i', strtotime($r['end_time']));
        $cat = $r['category_names'] ? $esc($r['category_names']) : '-';
        $lines[] = ($i + 1) . '. ' . $start . '-' . $end . ' ' . $esc($r['customer_name']) . ' (' . (int) $r['num_people'] . ' คน)';
        $lines[] = '   📞 ' . $esc($r['customer_phone']) . ' · ' . $cat;
        $lines[] = '';
    }
}

$lines[] = '— ป๊อปอาย ช่างแต่งหน้าสุราษฎร์';
$text = implode("\n", $lines);

$result = sendTelegramMessage($text);
if ($result['ok']) {
    updateLastSentDate($today);
}
