<?php
/**
 * API รับเงินรายรายการของคิว (admin เท่านั้น)
 *   GET ?booking_id=123                → รายการรับเงิน + สรุปยอด
 *   POST {booking_id, kind, amount, method, paid_at, note, confirm} → เพิ่มรายการ (confirm=1 = ยืนยันคิวด้วย)
 *   DELETE ?id=5                       → ลบรายการ
 * ทุกครั้งที่เปลี่ยนจะคำนวณ bookings.payment_status ใหม่จากยอดจริง
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
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') requireCsrf();
$owner = ownerId();

/** ยืนยันว่าคิวเป็นของร้านนี้ — ไม่ใช่ = 404 */
function requireOwnBooking(PDO $pdo, int $bookingId, int $owner): void
{
    $stmt = $pdo->prepare("SELECT id FROM bookings WHERE id = ? AND user_id = ?");
    $stmt->execute([$bookingId, $owner]);
    if (!$stmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบรายการ']);
        exit;
    }
}

if ($method === 'GET') {
    $bookingId = (int) ($_GET['booking_id'] ?? 0);
    requireOwnBooking($pdo, $bookingId, $owner);
    $stmt = $pdo->prepare("SELECT id, kind, amount, method, paid_at, note FROM booking_payments WHERE booking_id = ? ORDER BY paid_at, id");
    $stmt->execute([$bookingId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['amount'] = (float) $r['amount'];
    }
    unset($r);
    echo json_encode(['rows' => $rows, 'summary' => bookingMoney($pdo, $bookingId)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    $in = is_array($in) ? $in : $_POST;
    $bookingId = (int) ($in['booking_id'] ?? 0);
    requireOwnBooking($pdo, $bookingId, $owner);

    $kind = (string) ($in['kind'] ?? 'deposit');
    $payMethod = (string) ($in['method'] ?? 'transfer');
    $amount = round((float) ($in['amount'] ?? 0), 2);
    $paidAt = (string) ($in['paid_at'] ?? '') ?: date('Y-m-d');
    if (!in_array($kind, ['deposit', 'balance', 'refund'], true) || !in_array($payMethod, ['transfer', 'cash', 'other'], true) || !isValidDate($paidAt)) {
        http_response_code(400);
        echo json_encode(['error' => 'ข้อมูลไม่ถูกต้อง']);
        exit;
    }
    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'กรุณากรอกจำนวนเงินมากกว่า 0']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        recordPayment($pdo, $bookingId, $kind, $amount, $payMethod, $paidAt, trim((string) ($in['note'] ?? '')) ?: null, currentUserId());
        if (!empty($in['confirm'])) {
            $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ? AND status = 'new'")->execute([$bookingId]);
        }
        syncPaymentStatus($pdo, $bookingId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('payments.php: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง']);
        exit;
    }
    echo json_encode(['success' => true, 'summary' => bookingMoney($pdo, $bookingId)]);
    exit;
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT p.booking_id FROM booking_payments p JOIN bookings b ON b.id = p.booking_id WHERE p.id = ? AND b.user_id = ?");
    $stmt->execute([$id, $owner]);
    $bookingId = (int) $stmt->fetchColumn();
    if ($bookingId === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบรายการ']);
        exit;
    }
    $pdo->prepare("DELETE FROM booking_payments WHERE id = ?")->execute([$id]);
    syncPaymentStatus($pdo, $bookingId);
    echo json_encode(['success' => true, 'summary' => bookingMoney($pdo, $bookingId)]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
