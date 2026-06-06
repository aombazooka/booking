<?php
/**
 * ดูรูปสลิปมัดจำ (เฉพาะ admin) — สตรีมไฟล์จาก uploads/slips
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

$path = dirname(__DIR__) . '/uploads/slips/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit('not found');
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=300');
readfile($path);
