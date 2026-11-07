<?php
/**
 * API ตรวจช่วงเวลาที่ถูกจองแล้วในวันหนึ่ง (สาธารณะ, read-only)
 * ใช้ในหน้า book.php ให้ลูกค้าเห็นว่าช่วงไหนไม่ว่าง
 *   GET ?date=YYYY-MM-DD[&staff=ID|none][&categories=1,2]
 *     → { date, busy: [ {start, end}, ... ], closed: เหตุผลที่ปิด|null, slots: [ {start, end}, ... ], duration }
 *   - staff=ID    → เฉพาะคิวของช่างคนนั้น (ทั้งช่างหลักและช่างเสริม)
 *   - staff=none  → เฉพาะคิวที่ยังไม่ระบุช่าง
 *   - ไม่ส่ง staff → ทุกคิวในวันนั้น (ภาพรวม)
 * ไม่เปิดเผยข้อมูลลูกค้า (คืนเฉพาะช่วงเวลา)
 */
header('Content-Type: application/json; charset=utf-8');

require dirname(__DIR__) . '/app/auth.php';
require dirname(__DIR__) . '/app/booking_helpers.php';

try {
    $pdo = require dirname(__DIR__) . '/app/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'เชื่อมต่อฐานข้อมูลไม่ได้']);
    exit;
}

// เจ้าของ: ?shop=slug = ร้านนั้นเสมอ, ไม่ส่ง shop = ร้านของผู้ที่ล็อกอิน
$shopSlug = (string) ($_GET['shop'] ?? '');
$owner = ($shopSlug === '' && isLoggedIn()) ? ownerId() : resolveShopOwner($pdo, $shopSlug);
if (!$owner) {
    echo json_encode(['date' => $_GET['date'] ?? '', 'busy' => []]);
    exit;
}

$date = $_GET['date'] ?? '';
if (!isValidDate($date)) {
    http_response_code(400);
    echo json_encode(['error' => 'ต้องการ date รูปแบบ YYYY-MM-DD']);
    exit;
}

$sql = "SELECT start_time, end_time FROM bookings WHERE user_id = ? AND appointment_date = ? AND status <> 'cancelled'";
expireHolds($pdo, $owner);

[$staffSql, $staffArgs] = staffFilterSql($_GET['staff'] ?? '', 'bookings');
$sql .= $staffSql . " ORDER BY start_time";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$owner, $date], $staffArgs));

$busy = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $busy[] = [
        'start' => substr((string) $r['start_time'], 0, 5),
        'end'   => substr((string) $r['end_time'], 0, 5),
    ];
}

$categories = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['categories'] ?? '')))));
$duration = bookingDurationMinutes($pdo, $owner, $categories);
$day = computeDaySlots($pdo, $owner, $date, $duration, resolveStaffId($pdo, $owner, $_GET['staff'] ?? null, true));

echo json_encode(['date' => $date, 'busy' => $busy, 'closed' => $day['closed'], 'slots' => $day['slots'], 'duration' => $duration], JSON_UNESCAPED_UNICODE);
