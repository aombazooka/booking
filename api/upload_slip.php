<?php
/**
 * API อัปโหลดสลิปมัดจำ (สาธารณะ — ลูกค้าแนบตอนจอง)
 * POST multipart field "slip" (รูปภาพ) → คืน { success, file }
 * ปลอดภัย: รับเฉพาะรูป (jpg/png/webp), จำกัดขนาด, ตั้งชื่อไฟล์สุ่ม, เก็บใน uploads/slips
 */
header('Content-Type: application/json; charset=utf-8');

require dirname(__DIR__) . '/app/booking_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// กันสแปมอัปโหลด (กันดิสก์เต็ม): 20 ไฟล์/ชั่วโมง ต่อ IP
if (!rateLimitOk('slip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 20, 3600)) {
    http_response_code(429);
    echo json_encode(['error' => 'อัปโหลดบ่อยเกินไป กรุณารอสักครู่']);
    exit;
}

if (!isset($_FILES['slip']) || $_FILES['slip']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'ไม่พบไฟล์ หรืออัปโหลดไม่สำเร็จ']);
    exit;
}

$f = $_FILES['slip'];
$maxBytes = 5 * 1024 * 1024; // 5MB
if ($f['size'] <= 0 || $f['size'] > $maxBytes) {
    http_response_code(400);
    echo json_encode(['error' => 'ไฟล์ต้องมีขนาดไม่เกิน 5MB']);
    exit;
}

// ตรวจชนิดไฟล์จริงจากเนื้อไฟล์ (ไม่เชื่อนามสกุล/ชื่อที่ส่งมา)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($f['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowed[$mime]) || getimagesize($f['tmp_name']) === false) {
    http_response_code(400);
    echo json_encode(['error' => 'รองรับเฉพาะรูปภาพ (JPG, PNG, WEBP)']);
    exit;
}

$dir = dirname(__DIR__) . '/uploads/slips';
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['error' => 'บันทึกไฟล์ไม่ได้ (โฟลเดอร์ uploads/slips)']);
    exit;
}

$name = 'slip_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
    http_response_code(500);
    echo json_encode(['error' => 'บันทึกไฟล์ไม่สำเร็จ']);
    exit;
}

echo json_encode(['success' => true, 'file' => $name]);
