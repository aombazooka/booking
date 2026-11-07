<?php
/**
 * Cron: ไล่ทุกร้านที่ตั้งค่า Telegram ไว้ ถ้าถึงเวลาแจ้งเตือนของร้านนั้น และวันนี้ยังไม่ได้ส่ง
 * → ส่งตารางงานวันพรุ่งนี้ของร้านนั้นไป Telegram
 * ควรรันทุก 5 นาที (cron): ใส่ตาราง "ทุก 5 นาที" ตามด้วย
 *   php path/to/cron/send_tomorrow_schedule.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("สคริปต์นี้รันได้จาก command line เท่านั้น\n");
}

$root = dirname(__DIR__);
require $root . '/app/telegram.php';
require $root . '/app/booking_helpers.php';

$pdo = require $root . '/app/db.php';

$now = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
$today = $now->format('Y-m-d');
$tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');
$nowMin = (int) $now->format('H') * 60 + (int) $now->format('i');

$shops = $pdo->query("
    SELECT t.user_id, t.bot_token, t.chat_id, t.line_token, t.line_to, t.notify_time, t.last_sent_date
    FROM shop_telegram t
    JOIN app_users u ON u.id = t.user_id
    WHERE u.status = 'active' AND ((t.bot_token <> '' AND t.chat_id <> '') OR (t.line_token <> '' AND t.line_to <> ''))
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($shops as $shop) {
    // ใช้ช่วง 5 นาที: ตั้งแจ้ง 18:00 จะส่งตอนที่ cron รันในช่วง 18:00-18:04
    $notifyParts = explode(':', $shop['notify_time']);
    $notifyMinStart = (int) ($notifyParts[0] ?? 18) * 60 + (int) ($notifyParts[1] ?? 0);
    if ($nowMin < $notifyMinStart || $nowMin >= $notifyMinStart + 5) {
        continue; // ยังไม่ถึงเวลาของร้านนี้
    }
    if ($shop['last_sent_date'] === $today) {
        continue; // ส่งไปแล้ววันนี้
    }

    $result = sendShopNotification($shop, buildTomorrowMessage($pdo, (int) $shop['user_id'], $tomorrow));
    if ($result['ok']) {
        updateLastSentDate($pdo, (int) $shop['user_id'], $today);
    }
}

function buildTomorrowMessage(PDO $pdo, int $ownerId, string $tomorrow): string
{
    $stmt = $pdo->prepare("
        SELECT b.customer_name, b.customer_phone, b.start_time, b.end_time, b.time_slot, b.time_exact, b.num_people,
               (SELECT GROUP_CONCAT(c.name) FROM booking_category_pivot p
                JOIN booking_categories c ON c.id = p.category_id WHERE p.booking_id = b.id) AS category_names,
               " . COUNT_LABEL_SUBQUERY . " AS count_label
        FROM bookings b
        WHERE b.user_id = ? AND b.appointment_date = ? AND b.status <> 'cancelled'
        ORDER BY b.start_time
    ");
    $stmt->execute([$ownerId, $tomorrow]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tomorrowThai = (new DateTime($tomorrow))->format('d/m/Y');
    $lines = ["📅 <b>ตารางงานวันพรุ่งนี้</b> ({$tomorrowThai})", ''];

    if (count($rows) === 0) {
        $lines[] = 'ไม่มีคิวงาน';
    } else {
        // escape อักขระ HTML (&, <, >) เพราะส่งด้วย parse_mode=HTML — กันข้อความล่ม/ฝัง HTML จากชื่อลูกค้า
        $esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        foreach ($rows as $i => $r) {

            $cat = $r['category_names'] ? $esc($r['category_names']) : '-';
            $unit = countUnitFromLabel($r['count_label'] ?? null);
            $lines[] = ($i + 1) . '. ' . $esc(bookingTimeLabel($r)) . ' ' . $esc($r['customer_name']) . ' (' . (int) $r['num_people'] . ' ' . $esc($unit) . ')';
            $lines[] = '   📞 ' . $esc($r['customer_phone']) . ' · ' . $cat;
            $lines[] = '';
        }
    }

    $lines[] = '— แจ้งเตือนจากแอปจองคิว';
    return implode("\n", $lines);
}
