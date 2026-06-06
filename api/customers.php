<?php
/**
 * API ลูกค้า (admin เท่านั้น)
 *   GET ?q=คำค้น        → ค้นลูกค้าจากชื่อ/เบอร์ (สำหรับ autocomplete ในฟอร์ม), จำกัด 10
 *   GET ?id=123         → ข้อมูลลูกค้า + ประวัติการจอง
 *   GET (ไม่มีพารามิเตอร์) → รายชื่อลูกค้าทั้งหมด (พร้อมจำนวนครั้งที่จอง)
 */
header('Content-Type: application/json; charset=utf-8');

require dirname(__DIR__) . '/app/auth.php';
try {
    $pdo = require dirname(__DIR__) . '/app/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'เชื่อมต่อฐานข้อมูลไม่ได้']);
    exit;
}

requireLoginApi();

// ข้อมูลลูกค้ารายคน + ประวัติการจอง
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT id, name, phone, note, created_at FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    $cust = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cust) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบลูกค้า']);
        exit;
    }
    $h = $pdo->prepare("
        SELECT id, appointment_date, start_time, end_time, status, price, deposit, payment_status
        FROM bookings WHERE customer_id = ? ORDER BY appointment_date DESC, start_time DESC
    ");
    $h->execute([$id]);
    $cust['bookings'] = $h->fetchAll(PDO::FETCH_ASSOC);
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
        WHERE name LIKE ? OR phone LIKE ?
        ORDER BY name LIMIT 10
    ");
    $stmt->execute([$like, $like]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// รายชื่อทั้งหมด + จำนวนครั้งที่จอง
$rows = $pdo->query("
    SELECT c.id, c.name, c.phone, c.note, c.created_at,
           (SELECT COUNT(*) FROM bookings b WHERE b.customer_id = c.id) AS booking_count
    FROM customers c ORDER BY c.name
")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows);
