<?php
/**
 * API จัดการบริการ (admin เท่านั้น): GET list / POST create / PUT update / DELETE
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

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') requireCsrf();
$owner = ownerId();

function svc_input(): array
{
    $in = json_decode(file_get_contents('php://input'), true);
    return is_array($in) ? $in : $_POST;
}

function svc_num_or_null($v): ?float
{
    return ($v === null || $v === '') ? null : (float) $v;
}

/** ตั้งค่าความสัมพันธ์ บริการ→ประเภทงาน ใหม่ทั้งหมด (ผูกเฉพาะประเภทของเจ้าของ) */
function syncServiceCategories(PDO $pdo, int $serviceId, int $userId, $categoryIds): void
{
    $pdo->prepare("DELETE FROM service_category_link WHERE service_id = ?")->execute([$serviceId]);
    $ins = $pdo->prepare("INSERT IGNORE INTO service_category_link (service_id, category_id)
                          SELECT ?, id FROM booking_categories WHERE id = ? AND user_id = ?");
    foreach ((array) $categoryIds as $cid) {
        $cid = (int) $cid;
        if ($cid > 0) $ins->execute([$serviceId, $cid, $userId]);
    }
}

if ($method === 'GET') {
    $stmt = $pdo->prepare("
        SELECT s.id, s.name, s.price, s.is_active, s.sort_order,
               (SELECT GROUP_CONCAT(l.category_id) FROM service_category_link l WHERE l.service_id = s.id) AS category_ids
        FROM booking_services s WHERE s.user_id = ? ORDER BY s.sort_order, s.id
    ");
    $stmt->execute([$owner]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['category_ids'] = $r['category_ids'] ? array_map('intval', explode(',', $r['category_ids'])) : [];
    }
    unset($r);
    echo json_encode($rows);
    exit;
}

if ($method === 'POST' && ($_GET['action'] ?? '') !== 'delete') {
    $in = svc_input();
    $name = trim($in['name'] ?? '');
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'กรุณากรอกชื่อบริการ']);
        exit;
    }
    $stmt = $pdo->prepare("INSERT INTO booking_services (user_id, name, price, is_active, sort_order) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $owner,
        $name,
        svc_num_or_null($in['price'] ?? null),
        empty($in['is_active']) ? 0 : 1,
        (int) ($in['sort_order'] ?? 0),
    ]);
    $newId = (int) $pdo->lastInsertId();
    syncServiceCategories($pdo, $newId, $owner, $in['category_ids'] ?? []);
    echo json_encode(['success' => true, 'id' => $newId]);
    exit;
}

if ($method === 'PUT' || $method === 'PATCH') {
    $in = svc_input();
    $id = (int) ($in['id'] ?? 0);
    $name = trim($in['name'] ?? '');
    if ($id <= 0 || $name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'ต้องการ id และชื่อบริการ']);
        exit;
    }
    // ตรวจว่าบริการเป็นของเจ้าของ
    $chk = $pdo->prepare("SELECT COUNT(*) FROM booking_services WHERE id = ? AND user_id = ?");
    $chk->execute([$id, $owner]);
    if (!$chk->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบบริการ']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE booking_services SET name = ?, price = ?, is_active = ?, sort_order = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([
        $name,
        svc_num_or_null($in['price'] ?? null),
        empty($in['is_active']) ? 0 : 1,
        (int) ($in['sort_order'] ?? 0),
        $id, $owner,
    ]);
    if (array_key_exists('category_ids', $in)) {
        syncServiceCategories($pdo, $id, $owner, $in['category_ids']);
    }
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

if ($method === 'DELETE' || ($method === 'POST' && ($_GET['action'] ?? '') === 'delete')) {
    $id = (int) ($_GET['id'] ?? (svc_input()['id'] ?? 0));
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ต้องการ id']);
        exit;
    }
    $pdo->prepare("DELETE FROM booking_services WHERE id = ? AND user_id = ?")->execute([$id, $owner]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
