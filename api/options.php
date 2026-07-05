<?php
/**
 * API ตัวเลือก: ประเภทงาน + บริการ (ที่เปิดใช้งาน) สำหรับฟอร์ม admin และหน้าลูกค้าจองเอง
 * เป็น read-only สาธารณะ — คืนเฉพาะรายการ is_active = 1
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

// เจ้าของข้อมูล: admin = ตัวเอง, สาธารณะ = ร้านจาก ?shop=slug
if (isLoggedIn()) {
    $owner = ownerId();
} else {
    $owner = resolveShopOwner($pdo, (string) ($_GET['shop'] ?? ''));
    if (!$owner) {
        echo json_encode(['categories' => [], 'services' => [], 'staff' => [], 'error' => 'ไม่พบร้าน']);
        exit;
    }
}

$cs = $pdo->prepare("
    SELECT id, name, color_hex, price, deposit_default, duration_min, count_label
    FROM booking_categories WHERE user_id = ? AND is_active = 1 ORDER BY sort_order, id
");
$cs->execute([$owner]);
$categories = $cs->fetchAll(PDO::FETCH_ASSOC);

$ss = $pdo->prepare("
    SELECT s.id, s.name, s.price,
           (SELECT GROUP_CONCAT(l.category_id) FROM service_category_link l WHERE l.service_id = s.id) AS category_ids
    FROM booking_services s WHERE s.user_id = ? AND s.is_active = 1 ORDER BY s.sort_order, s.id
");
$ss->execute([$owner]);
$services = $ss->fetchAll(PDO::FETCH_ASSOC);

$sf = $pdo->prepare("
    SELECT id, name, color_hex
    FROM staff WHERE user_id = ? AND is_active = 1 ORDER BY sort_order, id
");
$sf->execute([$owner]);
$staff = $sf->fetchAll(PDO::FETCH_ASSOC);
foreach ($staff as &$st) { $st['id'] = (int) $st['id']; }
unset($st);

// แปลงชนิดตัวเลขให้ตรง (DECIMAL กลับมาเป็น string จาก PDO)
foreach ($categories as &$c) {
    $c['id'] = (int) $c['id'];
    $c['price'] = $c['price'] !== null ? (float) $c['price'] : null;
    $c['deposit_default'] = $c['deposit_default'] !== null ? (float) $c['deposit_default'] : null;
    $c['duration_min'] = $c['duration_min'] !== null ? (int) $c['duration_min'] : null;
}
unset($c);
foreach ($services as &$s) {
    $s['id'] = (int) $s['id'];
    $s['price'] = $s['price'] !== null ? (float) $s['price'] : null;
    $s['category_ids'] = $s['category_ids'] ? array_map('intval', explode(',', $s['category_ids'])) : [];
}
unset($s);

echo json_encode([
    'categories' => $categories,
    'services'   => $services,
    'staff'      => $staff,
]);
