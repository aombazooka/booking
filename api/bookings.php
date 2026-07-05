<?php
/**
 * API การจอง: GET รายการตามช่วงวันที่, POST สร้างการจองใหม่
 */
header('Content-Type: application/json; charset=utf-8');

require dirname(__DIR__) . '/app/auth.php';
require dirname(__DIR__) . '/app/booking_helpers.php';

try {
    $pdo = require dirname(__DIR__) . '/app/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'เชื่อมต่อฐานข้อมูลไม่ได้ โปรดตรวจสอบ config.php และนำเข้า schema แล้ว']);
    exit;
}

function status_to_label(string $status): string
{
    $map = [
        'new' => 'ใหม่',
        'confirmed' => 'ยืนยันแล้ว',
        'done' => 'เสร็จงาน',
        'cancelled' => 'ยกเลิก',
    ];
    return $map[$status] ?? $status;
}

function status_to_color(string $status, string $fallback): string
{
    $statusColors = [
        'new' => '#93c5fd',
        'confirmed' => '#a7f3d0',
        'done' => '#d8b4fe',
        'cancelled' => '#fecdd3',
    ];
    return $statusColors[$status] ?? $fallback;
}

function payment_to_label(string $p): string
{
    $map = [
        'unpaid' => 'ยังไม่จ่าย',
        'deposit_paid' => 'จ่ายมัดจำแล้ว',
        'paid' => 'จ่ายครบแล้ว',
    ];
    return $map[$p] ?? $p;
}

// ลบรายการ — ต้องตรวจก่อน GET รายการเดียว/รายการช่วง (GET ?action=delete&id=123 หรือ method DELETE)
$isDelete = (isset($_GET['action']) && $_GET['action'] === 'delete')
    || ($_SERVER['REQUEST_METHOD'] === 'DELETE')
    || (isset($_POST['action']) && $_POST['action'] === 'delete');
if ($isDelete) {
    requireLoginApi();
    requireCsrf();
    $delId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
    if ($delId <= 0) {
        $input = @json_decode(file_get_contents('php://input'), true);
        $delId = is_array($input) && isset($input['id']) ? (int)$input['id'] : 0;
    }
    if ($delId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ต้องการ id ของรายการที่ลบ']);
        exit;
    }
    $stmt = $pdo->prepare("DELETE FROM bookings WHERE id = ? AND user_id = ?");
    $stmt->execute([$delId, ownerId()]);
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบรายการหรือลบแล้ว']);
    }
    exit;
}

// GET รายการเดียว (สำหรับฟอร์มแก้ไข): ?id=123 (ถ้ามี action=delete ให้ไปลบ ไม่ดึงรายการ)
$getId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($getId > 0 && empty($_GET['action'])) {
    requireLoginApi();
    $stmt = $pdo->prepare("
        SELECT b.id, b.customer_name, b.customer_phone, b.location,
               b.appointment_date, b.start_time, b.end_time, b.num_people,
               b.price, b.deposit, b.payment_status, b.slip_path, b.status, b.staff_id, b.note,
               (SELECT GROUP_CONCAT(p.category_id) FROM booking_category_pivot p WHERE p.booking_id = b.id) AS category_ids,
               (SELECT GROUP_CONCAT(p.service_id) FROM booking_service_pivot p WHERE p.booking_id = b.id) AS service_ids
        FROM bookings b WHERE b.id = ? AND b.user_id = ?
    ");
    $stmt->execute([$getId, ownerId()]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบรายการ']);
        exit;
    }
    $row['id'] = (int)$row['id'];
    $row['staff_id'] = $row['staff_id'] !== null ? (int)$row['staff_id'] : null;
    $row['category_ids'] = $row['category_ids'] ? array_map('intval', explode(',', $row['category_ids'])) : [];
    $row['service_ids'] = $row['service_ids'] ? array_map('intval', explode(',', $row['service_ids'])) : [];
    echo json_encode($row);
    exit;
}

// GET: รายการจองตามช่วงวันที่ (สำหรับปฏิทิน/ลิสต์) รองรับตัวกรอง status
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireLoginApi();
    $start = $_GET['start'] ?? null; // YYYY-MM-DD
    $end   = $_GET['end']   ?? null; // YYYY-MM-DD
    $mode  = $_GET['mode']  ?? 'calendar';
    $date  = $_GET['date']  ?? date('Y-m-d');
    $statusFilter = $_GET['status'] ?? ''; // คั่นด้วย comma เช่น new,confirmed

    if ($mode === 'today') {
        $start = $date;
        $end = $date;
    } elseif (!$start || !$end) {
        http_response_code(400);
        echo json_encode(['error' => 'ต้องการ start และ end (YYYY-MM-DD)']);
        exit;
    }

    $allowedStatuses = ['new', 'confirmed', 'done', 'cancelled'];
    $statusList = [];
    if ($statusFilter !== '') {
        foreach (array_map('trim', explode(',', $statusFilter)) as $s) {
            if (in_array($s, $allowedStatuses, true)) {
                $statusList[] = $s;
            }
        }
    }

    $sql = "
        SELECT b.id, b.customer_name, b.customer_phone, b.location,
               b.appointment_date, b.start_time, b.end_time, b.num_people,
               b.price, b.deposit, b.payment_status, b.slip_path, b.status, b.source, b.staff_id, b.note,
               st.name AS staff_name,
               (SELECT GROUP_CONCAT(c.id) FROM booking_category_pivot p
                JOIN booking_categories c ON c.id = p.category_id WHERE p.booking_id = b.id) AS category_ids,
               (SELECT GROUP_CONCAT(c.name) FROM booking_category_pivot p
                JOIN booking_categories c ON c.id = p.category_id WHERE p.booking_id = b.id) AS category_names,
               (SELECT c.color_hex FROM booking_category_pivot p
                JOIN booking_categories c ON c.id = p.category_id WHERE p.booking_id = b.id LIMIT 1) AS color_hex
        FROM bookings b
        LEFT JOIN staff st ON st.id = b.staff_id
        WHERE b.user_id = ? AND b.appointment_date BETWEEN ? AND ?
    ";
    $params = [ownerId(), $start, $end];
    if (count($statusList) > 0) {
        $placeholders = implode(',', array_fill(0, count($statusList), '?'));
        $sql .= " AND b.status IN ($placeholders)";
        foreach ($statusList as $s) {
            $params[] = $s;
        }
    }
    // ตัวกรองช่าง (ออปชัน): ?staff=ID หรือ ?staff=none (ไม่ระบุช่าง)
    $staffFilter = $_GET['staff'] ?? '';
    if ($staffFilter === 'none') {
        $sql .= " AND b.staff_id IS NULL";
    } elseif ($staffFilter !== '' && ctype_digit((string) $staffFilter)) {
        $sql .= " AND b.staff_id = ?";
        $params[] = (int) $staffFilter;
    }
    $sql .= " ORDER BY b.appointment_date, b.start_time";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // รูปแบบสำหรับ FullCalendar: title, start, end, color, extendedProps
    $events = [];
    foreach ($rows as $r) {
        $startDt = $r['appointment_date'] . ' ' . $r['start_time'];
        $endDt   = $r['appointment_date'] . ' ' . $r['end_time'];
        $events[] = [
            'id'    => (int)$r['id'],
            'title' => $r['customer_name'] . ' (' . $r['num_people'] . ' คน)',
            'start' => $startDt,
            'end'   => $endDt,
            'color' => status_to_color((string)$r['status'], $r['color_hex'] ?: '#6b7280'),
            'extendedProps' => [
                'customer_name'  => $r['customer_name'],
                'customer_phone' => $r['customer_phone'],
                'location'       => $r['location'],
                'num_people'     => (int)$r['num_people'],
                'category_names' => $r['category_names'],
                'staff_id'       => $r['staff_id'] !== null ? (int)$r['staff_id'] : null,
                'staff_name'     => $r['staff_name'],
                'price'          => $r['price'] !== null ? (float)$r['price'] : null,
                'deposit'        => (float)$r['deposit'],
                'payment_status' => $r['payment_status'],
                'payment_label'  => payment_to_label((string)$r['payment_status']),
                'slip_path'      => $r['slip_path'],
                'source'         => $r['source'],
                'status'         => $r['status'],
                'status_label'   => status_to_label((string)$r['status']),
                'note'           => $r['note'],
            ],
        ];
    }
    if ($mode === 'today') {
        $today = [];
        foreach ($events as $ev) {
            $today[] = [
                'id' => $ev['id'],
                'time' => substr((string)$ev['start'], 11, 5) . ' - ' . substr((string)$ev['end'], 11, 5),
                'title' => $ev['title'],
                'color' => $ev['color'],
                'extendedProps' => $ev['extendedProps'],
            ];
        }
        echo json_encode($today);
        exit;
    }

    echo json_encode($events);
    exit;
}

// PUT/PATCH: แก้ไขการจอง (ส่ง id ใน body)
if ($_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'PATCH' || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_method']) && in_array(strtoupper($_POST['_method']), ['PUT', 'PATCH'], true))) {
    requireLoginApi();
    requireCsrf();
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = $_POST;
    }
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ต้องการ id ของรายการที่แก้ไข']);
        exit;
    }
    $owner = ownerId();
    // ตรวจว่าคิวนี้เป็นของผู้ใช้จริง (กันแก้ข้ามร้าน)
    $ownStmt = $pdo->prepare("SELECT user_id FROM bookings WHERE id = ?");
    $ownStmt->execute([$id]);
    if ((int) $ownStmt->fetchColumn() !== $owner) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบรายการ']);
        exit;
    }
    $name   = trim($input['customer_name'] ?? '');
    $phone  = trim($input['customer_phone'] ?? '');
    $location = trim($input['location'] ?? '');
    $date   = $input['appointment_date'] ?? '';
    $start  = $input['start_time'] ?? '';
    $end    = $input['end_time'] ?? '';
    $num    = (int)($input['num_people'] ?? 1);
    $note   = trim($input['note'] ?? '');
    $status = trim($input['status'] ?? 'new');
    $price  = isset($input['price']) && $input['price'] !== '' ? (float)$input['price'] : null;
    $deposit = isset($input['deposit']) && $input['deposit'] !== '' ? (float)$input['deposit'] : 0;
    $paymentStatus = trim($input['payment_status'] ?? 'unpaid');
    $staffId = resolveStaffId($pdo, $owner, $input['staff_id'] ?? null, false);
    $slip   = sanitizeSlip($input['slip_path'] ?? null);
    $force  = !empty($input['force']);
    $categories = $input['category_ids'] ?? [];
    $services   = $input['service_ids'] ?? [];

    if (!$name || !$phone || !$date || !$start || !$end) {
        http_response_code(400);
        echo json_encode(['error' => 'กรุณากรอก ชื่อ, เบอร์โทร, วันที่, เวลาเริ่ม, เวลาสิ้นสุด']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}/', $start) || !preg_match('/^\d{2}:\d{2}/', $end)) {
        http_response_code(400);
        echo json_encode(['error' => 'รูปแบบวันที่หรือเวลาไม่ถูกต้อง']);
        exit;
    }
    if ($start >= $end) {
        http_response_code(400);
        echo json_encode(['error' => 'เวลาเริ่มต้องน้อยกว่าเวลาจบ']);
        exit;
    }
    if ($num < 1) $num = 1;
    if ($num > 999) $num = 999;
    if ($deposit < 0) $deposit = 0;
    if (!in_array($status, ['new', 'confirmed', 'done', 'cancelled'], true)) $status = 'new';
    if (!in_array($paymentStatus, ['unpaid', 'deposit_paid', 'paid'], true)) $paymentStatus = 'unpaid';

    // กันคิวชน (แยกตามช่าง, ไม่นับคิวที่ยกเลิก, ข้ามตัวเอง) — admin override ได้ด้วย force
    acquireBookingLock($pdo, $owner, $date, $staffId);
    if ($status !== 'cancelled' && !$force) {
        $conflicts = findTimeConflicts($pdo, $owner, $date, $start, $end, $staffId, $id);
        if (count($conflicts) > 0) {
            http_response_code(409);
            echo json_encode([
                'error' => 'ช่วงเวลานี้ชนกับคิวอื่น',
                'conflict' => true,
                'conflicts' => $conflicts,
            ]);
            exit;
        }
    }

    $pdo->beginTransaction();
    try {
        $customerId = upsertCustomer($pdo, $owner, $name, $phone);
        $stmt = $pdo->prepare("
            UPDATE bookings SET
                customer_id = ?, staff_id = ?, customer_name = ?, customer_phone = ?, location = ?, appointment_date = ?,
                start_time = ?, end_time = ?, num_people = ?, price = ?, deposit = ?, payment_status = ?, slip_path = ?, status = ?, note = ?
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$customerId, $staffId, $name, $phone, $location ?: null, $date, $start, $end, $num, $price, $deposit, $paymentStatus, $slip, $status, $note ?: null, $id, $owner]);

        $pdo->prepare("DELETE FROM booking_category_pivot WHERE booking_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM booking_service_pivot WHERE booking_id = ?")->execute([$id]);
        // ผูก pivot เฉพาะประเภท/บริการที่เป็นของเจ้าของ (กันผูกข้ามร้าน)
        $insCat = $pdo->prepare("INSERT INTO booking_category_pivot (booking_id, category_id) SELECT ?, id FROM booking_categories WHERE id = ? AND user_id = ?");
        foreach ((array)$categories as $cid) { $cid = (int)$cid; if ($cid > 0) $insCat->execute([$id, $cid, $owner]); }
        $insSvc = $pdo->prepare("INSERT INTO booking_service_pivot (booking_id, service_id) SELECT ?, id FROM booking_services WHERE id = ? AND user_id = ?");
        foreach ((array)$services as $sid) { $sid = (int)$sid; if ($sid > 0) $insSvc->execute([$id, $sid, $owner]); }
        $pdo->commit();
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// POST: สร้างการจองใหม่ (ทั้งจาก admin หลังบ้าน และลูกค้าจองเองผ่าน book.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['action']) && !isset($_POST['action'])) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $isAdmin = isLoggedIn();
    requireCsrf(); // บังคับเฉพาะตอน login (admin) — ลูกค้าสาธารณะข้าม

    // เจ้าของคิว: admin = ตัวเอง, ลูกค้าสาธารณะ = ร้านจาก ?shop / body.shop
    if ($isAdmin) {
        $owner = ownerId();
    } else {
        $owner = resolveShopOwner($pdo, (string) ($input['shop'] ?? $_GET['shop'] ?? ''));
        if (!$owner) {
            http_response_code(400);
            echo json_encode(['error' => 'ไม่พบร้านที่ต้องการจอง']);
            exit;
        }
    }

    // rate-limit เฉพาะลูกค้าจองเอง (กันสแปม) — สูงสุด 6 ครั้ง/15 นาที ต่อ IP
    if (!$isAdmin) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!rateLimitOk('book:' . $ip, 6, 900)) {
            http_response_code(429);
            echo json_encode(['error' => 'คุณส่งคำขอจองบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่']);
            exit;
        }
    }

    $name   = trim($input['customer_name'] ?? '');
    $phone  = trim($input['customer_phone'] ?? '');
    $location = trim($input['location'] ?? '');
    $date   = $input['appointment_date'] ?? '';
    $start  = $input['start_time'] ?? '';
    $end    = $input['end_time'] ?? '';
    $num    = (int)($input['num_people'] ?? 1);
    $note   = trim($input['note'] ?? '');
    $categories = array_values(array_filter(array_map('intval', (array)($input['category_ids'] ?? []))));
    $services   = array_values(array_filter(array_map('intval', (array)($input['service_ids'] ?? []))));

    if (!$name || !$phone || !$date || !$start || !$end) {
        http_response_code(400);
        echo json_encode(['error' => 'กรุณากรอก ชื่อ, เบอร์โทร, วันที่, เวลาเริ่ม, เวลาสิ้นสุด']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}/', $start) || !preg_match('/^\d{2}:\d{2}/', $end)) {
        http_response_code(400);
        echo json_encode(['error' => 'รูปแบบวันที่หรือเวลาไม่ถูกต้อง']);
        exit;
    }
    if ($num < 1) $num = 1;
    if ($num > 999) $num = 999;
    if ($start >= $end) {
        http_response_code(400);
        echo json_encode(['error' => 'เวลาเริ่มต้องน้อยกว่าเวลาจบ']);
        exit;
    }
    if (count($categories) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'กรุณาเลือกประเภทงานอย่างน้อย 1 ข้อ']);
        exit;
    }

    if ($isAdmin) {
        // admin: เชื่อค่าที่ส่งมา + override คิวชนได้ด้วย force
        $status = trim($input['status'] ?? 'new');
        if (!in_array($status, ['new', 'confirmed', 'done', 'cancelled'], true)) $status = 'new';
        $source = 'admin';
        $price  = isset($input['price']) && $input['price'] !== '' ? (float)$input['price'] : null;
        $deposit = isset($input['deposit']) && $input['deposit'] !== '' ? (float)$input['deposit'] : 0;
        $paymentStatus = trim($input['payment_status'] ?? 'unpaid');
        if (!in_array($paymentStatus, ['unpaid', 'deposit_paid', 'paid'], true)) $paymentStatus = 'unpaid';
        if ($deposit < 0) $deposit = 0;
        $staffId = resolveStaffId($pdo, $owner, $input['staff_id'] ?? null, false);
        $force = !empty($input['force']);
    } else {
        // ลูกค้าจองเอง: บังคับเป็นคำขอใหม่, ไม่เชื่อราคาจาก client, กันคิวชนแบบบล็อกแข็ง
        $status = 'new';
        $source = 'customer';
        $paymentStatus = 'unpaid';
        $staffId = resolveStaffId($pdo, $owner, $input['staff_id'] ?? null, true);
        $force = false;
        // กันลูกค้าจองวันที่ย้อนหลัง (เทียบเวลาไทย)
        if ($date < (new DateTime('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d')) {
            http_response_code(400);
            echo json_encode(['error' => 'กรุณาเลือกวันที่ตั้งแต่วันนี้เป็นต้นไป']);
            exit;
        }
        // คำนวณราคา/มัดจำจากค่าตั้งต้นของประเภทงานที่เลือก (เฉพาะของร้านนี้ + เปิดใช้งาน)
        $ph = implode(',', array_fill(0, count($categories), '?'));
        $cstmt = $pdo->prepare("SELECT SUM(price) AS p, SUM(deposit_default) AS d, COUNT(*) AS n
                                FROM booking_categories WHERE user_id = ? AND is_active = 1 AND id IN ($ph)");
        $cstmt->execute(array_merge([$owner], $categories));
        $crow = $cstmt->fetch(PDO::FETCH_ASSOC);
        if ((int)$crow['n'] === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'ประเภทงานที่เลือกไม่ถูกต้อง']);
            exit;
        }
        $price = $crow['p'] !== null ? (float)$crow['p'] : null;
        $deposit = $crow['d'] !== null ? (float)$crow['d'] : 0;
    }

    $slip = sanitizeSlip($input['slip_path'] ?? null);

    // กันคิวชน (แยกตามช่าง) + ล็อกกัน race
    acquireBookingLock($pdo, $owner, $date, $staffId);
    if (!$force) {
        $conflicts = findTimeConflicts($pdo, $owner, $date, $start, $end, $staffId, null);
        if (count($conflicts) > 0) {
            http_response_code(409);
            echo json_encode([
                'error' => $isAdmin ? 'ช่วงเวลานี้ชนกับคิวอื่น' : 'ขออภัย ช่วงเวลานี้มีคิวแล้ว กรุณาเลือกเวลาอื่น',
                'conflict' => true,
                'conflicts' => $isAdmin ? $conflicts : true,
            ]);
            exit;
        }
    }

    $pdo->beginTransaction();
    try {
        $customerId = upsertCustomer($pdo, $owner, $name, $phone);
        $stmt = $pdo->prepare("
            INSERT INTO bookings (user_id, customer_id, staff_id, customer_name, customer_phone, location, appointment_date, start_time, end_time, num_people, price, deposit, payment_status, slip_path, status, source, note)
            VALUES (:user_id, :customer_id, :staff_id, :customer_name, :customer_phone, :location, :appointment_date, :start_time, :end_time, :num_people, :price, :deposit, :payment_status, :slip_path, :status, :source, :note)
        ");
        $stmt->execute([
            ':user_id'         => $owner,
            ':customer_id'     => $customerId,
            ':staff_id'        => $staffId,
            ':customer_name'   => $name,
            ':customer_phone'  => $phone,
            ':location'        => $location ?: null,
            ':appointment_date'=> $date,
            ':start_time'      => $start,
            ':end_time'        => $end,
            ':num_people'      => $num,
            ':price'           => $price,
            ':deposit'         => $deposit,
            ':payment_status'  => $paymentStatus,
            ':slip_path'       => $slip,
            ':status'          => $status,
            ':source'          => $source,
            ':note'            => $note ?: null,
        ]);
        $bookingId = (int) $pdo->lastInsertId();

        // ผูก pivot เฉพาะประเภท/บริการที่เป็นของร้านนี้ (กันผูกข้ามร้าน)
        $insCat = $pdo->prepare("INSERT INTO booking_category_pivot (booking_id, category_id) SELECT ?, id FROM booking_categories WHERE id = ? AND user_id = ?");
        foreach ((array)$categories as $cid) { $cid = (int)$cid; if ($cid > 0) $insCat->execute([$bookingId, $cid, $owner]); }
        $insSvc = $pdo->prepare("INSERT INTO booking_service_pivot (booking_id, service_id) SELECT ?, id FROM booking_services WHERE id = ? AND user_id = ?");
        foreach ((array)$services as $sid) { $sid = (int)$sid; if ($sid > 0) $insSvc->execute([$bookingId, $sid, $owner]); }
        $pdo->commit();
        echo json_encode(['success' => true, 'id' => $bookingId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
