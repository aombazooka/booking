<?php
/**
 * API ลูกค้า (admin เท่านั้น)
 *   GET ?q=คำค้น        → ค้นลูกค้าจากชื่อ/เบอร์ (สำหรับ autocomplete ในฟอร์ม), จำกัด 10
 *   GET ?id=123         → ข้อมูลลูกค้า + ประวัติการจอง
 *   GET (ไม่มีพารามิเตอร์) → รายชื่อลูกค้าทั้งหมด (พร้อมจำนวนครั้งที่จอง นัดล่าสุด และยอดที่จ่าย)
 *   PUT {id, note}      → บันทึกหมายเหตุลูกค้า
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

requireLoginApi();
$owner = ownerId();

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    requireCsrf();
    $in = json_decode(file_get_contents('php://input'), true);
    $pdo->prepare("UPDATE customers SET note = ? WHERE id = ? AND user_id = ?")
        ->execute([trim((string) ($in['note'] ?? '')) ?: null, (int) ($in['id'] ?? 0), $owner]);
    echo json_encode(['success' => true]);
    exit;
}

// ข้อมูลลูกค้ารายคน + ประวัติการจอง
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT id, name, phone, note, created_at FROM customers WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $owner]);
    $cust = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cust) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบลูกค้า']);
        exit;
    }
    $h = $pdo->prepare("
        SELECT id, appointment_date, start_time, end_time, time_slot, time_exact, status, price, deposit, payment_status
        FROM bookings WHERE customer_id = ? AND user_id = ? ORDER BY appointment_date DESC, start_time DESC
    ");
    $h->execute([$id, $owner]);
    $cust['bookings'] = $h->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cust['bookings'] as &$bk) {
        $bk['time_label'] = bookingTimeLabel($bk);
    }
    unset($bk);
    echo json_encode($cust);
    exit;
}

// ค้นหาสำหรับ autocomplete
if (isset($_GET['q'])) {
    $q = trim($_GET['q']);
    if ($q === '') { echo json_encode([]); exit; }
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare("
        SELECT id, name, phone FROM customers
        WHERE user_id = ? AND (name LIKE ? OR phone LIKE ?)
        ORDER BY name LIMIT 10
    ");
    $stmt->execute([$owner, $like, $like]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// รายชื่อทั้งหมด + จำนวนครั้งที่จอง
$rows = $pdo->prepare("
    SELECT c.id, c.name, c.phone, c.note, c.created_at,
           (SELECT COUNT(*) FROM bookings b WHERE b.customer_id = c.id) AS booking_count,
           (SELECT MAX(b.appointment_date) FROM bookings b WHERE b.customer_id = c.id) AS last_date,
           (SELECT COALESCE(SUM(IF(pm.kind = 'refund', -pm.amount, pm.amount)), 0)
            FROM booking_payments pm JOIN bookings b ON b.id = pm.booking_id WHERE b.customer_id = c.id) AS total_paid
    FROM customers c WHERE c.user_id = ? ORDER BY c.name
");
$rows->execute([$owner]);
echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));
