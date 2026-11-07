<?php
/**
 * ดูรูปสลิปมัดจำ (เฉพาะร้านที่เป็นเจ้าของสลิป) — สตรีมไฟล์จาก uploads/slips
 *   GET ?file=slip_xxxx.jpg
 * โฟลเดอร์ uploads/slips ถูกกันเข้าถึงตรงด้วย .htaccess ดูได้ผ่านที่นี่เท่านั้น
 */
require dirname(__DIR__) . '/app/auth.php';
requireLogin();

$file = basename($_GET['file'] ?? '');
if (!preg_match('/^slip_[a-f0-9]{8,}\.(jpg|jpeg|png|webp)$/i', $file)) {
    http_response_code(400);
    exit('invalid file');
}

// ต้องเป็นสลิปของคิวในร้านนี้ หรือเพิ่งอัปโหลดใน session นี้ (ยังไม่กดบันทึกคิว)
$pdo = require dirname(__DIR__) . '/app/db.php';
$owner = ownerId();
$stmt = $pdo->prepare("
    SELECT 1 FROM bookings WHERE user_id = ? AND slip_path = ?
    UNION
    SELECT 1 FROM booking_staff bs JOIN bookings b ON b.id = bs.booking_id WHERE b.user_id = ? AND bs.slip_path = ?
    LIMIT 1
");
$stmt->execute([$owner, $file, $owner, $file]);
$allowed = $stmt->fetchColumn() || in_array($file, $_SESSION['my_slips'] ?? [], true);

$path = dirname(__DIR__) . '/uploads/slips/' . $file;
if (!$allowed || !is_file($path)) {
    http_response_code(404);
    exit('not found');
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=300');
readfile($path);
