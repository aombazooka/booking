<?php
/**
 * API ตรวจช่วงเวลาที่ถูกจองแล้วในวันหนึ่ง (สาธารณะ, read-only)
 * ใช้ในหน้า book.php ให้ลูกค้าเห็นว่าช่วงไหนไม่ว่าง
 *   GET ?date=YYYY-MM-DD[&staff=ID|none]  → { date, busy: [ {start, end}, ... ] }
 *   - staff=ID    → เฉพาะคิวของช่างคนนั้น
 *   - staff=none  → เฉพาะคิวที่ยังไม่ระบุช่าง
 *   - ไม่ส่ง staff → ทุกคิวในวันนั้น (ภาพรวม)
 * ไม่เปิดเผยข้อมูลลูกค้า (คืนเฉพาะช่วงเวลา)
 */
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = require dirname(__DIR__) . '/app/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'เชื่อมต่อฐานข้อมูลไม่ได้']);
    exit;
}

$date = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'ต้องการ date รูปแบบ YYYY-MM-DD']);
    exit;
}

$sql = "SELECT start_time, end_time FROM bookings WHERE appointment_date = ? AND status <> 'cancelled'";
$params = [$date];
$staffParam = $_GET['staff'] ?? '';
if ($staffParam === 'none') {
    $sql .= " AND staff_id IS NULL";
} elseif ($staffParam !== '' && ctype_digit((string) $staffParam)) {
    $sql .= " AND staff_id = ?";
    $params[] = (int) $staffParam;
}
$sql .= " ORDER BY start_time";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$busy = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $busy[] = [
        'start' => substr((string) $r['start_time'], 0, 5),
        'end'   => substr((string) $r['end_time'], 0, 5),
    ];
}

echo json_encode(['date' => $date, 'busy' => $busy]);
