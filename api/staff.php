<?php
/**
 * API จัดการช่าง/พนักงาน (admin เท่านั้น): GET list / POST create / PUT update / DELETE
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

function staff_input(): array
{
    $in = json_decode(file_get_contents('php://input'), true);
    return is_array($in) ? $in : $_POST;
}

function staff_color(?string $c): string
{
    $c = trim((string) $c);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#a78bfa';
}

if ($method === 'GET') {
    $stmt = $pdo->prepare("
        SELECT id, name, phone, color_hex, is_active, sort_order
        FROM staff WHERE user_id = ? ORDER BY sort_order, id
    ");
    $stmt->execute([$owner]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($method === 'POST' && ($_GET['action'] ?? '') !== 'delete') {
    $in = staff_input();
    $name = trim($in['name'] ?? '');
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'กรุณากรอกชื่อช่าง']);
        exit;
    }
    $stmt = $pdo->prepare("INSERT INTO staff (user_id, name, phone, color_hex, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $owner,
        $name,
        trim($in['phone'] ?? '') ?: null,
        staff_color($in['color_hex'] ?? null),
        empty($in['is_active']) ? 0 : 1,
        (int) ($in['sort_order'] ?? 0),
    ]);
    echo json_encode(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
    exit;
}

if ($method === 'PUT' || $method === 'PATCH') {
    $in = staff_input();
    $id = (int) ($in['id'] ?? 0);
    $name = trim($in['name'] ?? '');
    if ($id <= 0 || $name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'ต้องการ id และชื่อช่าง']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE staff SET name = ?, phone = ?, color_hex = ?, is_active = ?, sort_order = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([
        $name,
        trim($in['phone'] ?? '') ?: null,
        staff_color($in['color_hex'] ?? null),
        empty($in['is_active']) ? 0 : 1,
        (int) ($in['sort_order'] ?? 0),
        $id, $owner,
    ]);
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

if ($method === 'DELETE' || ($method === 'POST' && ($_GET['action'] ?? '') === 'delete')) {
    $id = (int) ($_GET['id'] ?? (staff_input()['id'] ?? 0));
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ต้องการ id']);
        exit;
    }
    // ลบช่าง → งานที่เคยผูกจะกลายเป็น "ไม่ระบุช่าง" (FK ON DELETE SET NULL)
    $pdo->prepare("DELETE FROM staff WHERE id = ? AND user_id = ?")->execute([$id, $owner]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
