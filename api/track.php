<?php
/**
 * API หน้าติดตามคิวของลูกค้า (สาธารณะ — ใช้รหัสลับในลิงก์แทนการ login)
 *   POST {token, slip_path} → แนบสลิปเข้ากับคิว แล้วแจ้งร้าน
 */
header('Content-Type: application/json; charset=utf-8');

require dirname(__DIR__) . '/app/booking_helpers.php';
require dirname(__DIR__) . '/app/telegram.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!rateLimitOk('track:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 20, 3600)) {
    http_response_code(429);
    echo json_encode(['error' => 'ทำรายการบ่อยเกินไป กรุณารอสักครู่']);
    exit;
}

try {
    $pdo = require dirname(__DIR__) . '/app/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'เชื่อมต่อฐานข้อมูลไม่ได้']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$token = is_array($input) ? (string) ($input['token'] ?? '') : '';
$slip = sanitizeSlip(is_array($input) ? ($input['slip_path'] ?? null) : null);
if (!preg_match('/^[a-f0-9]{20}$/', $token) || $slip === null) {
    http_response_code(400);
    echo json_encode(['error' => 'ข้อมูลไม่ถูกต้อง']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, user_id, customer_name, appointment_date, status FROM bookings WHERE public_token = ?");
$stmt->execute([$token]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$booking || in_array($booking['status'], ['cancelled', 'done'], true)) {
    http_response_code(404);
    echo json_encode(['error' => 'ไม่พบคิว หรือคิวนี้ปิดแล้ว']);
    exit;
}

$pdo->prepare("UPDATE bookings SET slip_path = ?, hold_until = NULL WHERE id = ?")->execute([$slip, (int) $booking['id']]);

respondJsonThenContinue(['success' => true]);
$esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
notifyShopNow($pdo, (int) $booking['user_id'], "🧾 <b>ลูกค้าแนบสลิปแล้ว</b>\n" . $esc($booking['customer_name'])
    . ' · วันที่ ' . date('d/m/Y', strtotime($booking['appointment_date'])) . "\nตรวจยอดแล้วกดยืนยันรับมัดจำในหน้าคิว");
