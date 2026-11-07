<?php
/**
 * API การจอง: GET รายการตามช่วงวันที่, POST สร้างการจองใหม่
 */
header('Content-Type: application/json; charset=utf-8');

require dirname(__DIR__) . '/app/auth.php';
require dirname(__DIR__) . '/app/booking_helpers.php';
require dirname(__DIR__) . '/app/telegram.php';

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
        SELECT b.id, b.customer_name, b.customer_phone, b.social, b.location,
               b.appointment_date, b.start_time, b.end_time, b.time_slot, b.time_exact, b.num_people,
               b.price, b.deposit, b.travel_fee, b.payment_status, b.slip_path, b.status, b.staff_id, b.note,
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
    // ช่างทั้งหมดของคิว (ช่างหลักมาก่อน) พร้อมมัดจำจ่ายช่าง/สลิปรายคน
    $row['staff'] = fetchBookingStaff($pdo, [$getId])[$getId] ?? [];
    $row['category_ids'] = $row['category_ids'] ? array_map('intval', explode(',', $row['category_ids'])) : [];
    $row['service_ids'] = $row['service_ids'] ? array_map('intval', explode(',', $row['service_ids'])) : [];
    echo json_encode($row);
    exit;
}

// GET: รายการจองตามช่วงวันที่ (สำหรับปฏิทิน/ลิสต์) รองรับตัวกรอง status
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireLoginApi();
    expireHolds($pdo, ownerId());
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
        SELECT b.id, b.customer_name, b.customer_phone, b.social, b.location,
               b.appointment_date, b.start_time, b.end_time, b.time_slot, b.time_exact, b.num_people,
               b.price, b.deposit, b.travel_fee, b.payment_status, b.slip_path, b.status, b.source, b.staff_id, b.note,
               b.public_token, b.hold_until,
               " . PAID_TOTAL_SUBQUERY . " AS paid_total,
               st.name AS staff_name,
               (SELECT GROUP_CONCAT(c.id) FROM booking_category_pivot p
                JOIN booking_categories c ON c.id = p.category_id WHERE p.booking_id = b.id) AS category_ids,
               (SELECT GROUP_CONCAT(c.name) FROM booking_category_pivot p
                JOIN booking_categories c ON c.id = p.category_id WHERE p.booking_id = b.id) AS category_names,
               (SELECT c.color_hex FROM booking_category_pivot p
                JOIN booking_categories c ON c.id = p.category_id WHERE p.booking_id = b.id LIMIT 1) AS color_hex,
               " . COUNT_LABEL_SUBQUERY . " AS count_label
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
    [$staffSql, $staffArgs] = staffFilterSql($_GET['staff'] ?? '');
    $sql .= $staffSql . " ORDER BY b.appointment_date, b.start_time";
    $params = array_merge($params, $staffArgs);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $staffMap = fetchBookingStaff($pdo, array_column($rows, 'id'));

    // รูปแบบสำหรับ FullCalendar: title, start, end, color, extendedProps
    $events = [];
    foreach ($rows as $r) {
        $startDt = $r['appointment_date'] . ' ' . $r['start_time'];
        $endDt   = $r['appointment_date'] . ' ' . $r['end_time'];
        $countUnit = countUnitFromLabel($r['count_label'] ?? null);
        $exact = (int)$r['time_exact'] === 1;
        $timeLabel = bookingTimeLabel($r);
        $events[] = [
            'id'    => (int)$r['id'],
            'title' => ($exact ? '' : '[' . $timeLabel . '] ') . $r['customer_name'] . ' (' . $r['num_people'] . ' ' . $countUnit . ')',
            // คิวที่ไม่มีเวลาจริงแสดงเป็นงานทั้งวันในปฏิทิน ไม่แสดงเวลาที่ระบบคำนวณเอง
            'start' => $exact ? $startDt : $r['appointment_date'],
            'end'   => $exact ? $endDt : null,
            'allDay' => !$exact,
            'color' => status_to_color((string)$r['status'], $r['color_hex'] ?: '#6b7280'),
            'extendedProps' => [
                'customer_name'  => $r['customer_name'],
                'customer_phone' => $r['customer_phone'],
                'social'         => $r['social'],
                'time_label'     => $timeLabel,
                'location'       => $r['location'],
                'num_people'     => (int)$r['num_people'],
                'count_unit'     => $countUnit,
                'category_names' => $r['category_names'],
                'staff_id'       => $r['staff_id'] !== null ? (int)$r['staff_id'] : null,
                'staff_name'     => $r['staff_name'],
                'staff_list'     => $staffMap[(int)$r['id']] ?? [],
                'price'          => $r['price'] !== null ? (float)$r['price'] : null,
                'deposit'        => (float)$r['deposit'],
                'travel_fee'     => $r['travel_fee'] !== null ? (float)$r['travel_fee'] : null,
                'payment_status' => $r['payment_status'],
                'payment_label'  => payment_to_label((string)$r['payment_status']),
                'paid_total'     => (float)$r['paid_total'],
                'public_token'   => $r['public_token'],
                'hold_until'     => $r['hold_until'],
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
                'time' => $ev['extendedProps']['time_label'],
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
    $travelFee = isset($input['travel_fee']) && $input['travel_fee'] !== '' ? (float)$input['travel_fee'] : null;
    // ช่างที่รับงาน: staff[] = ช่างหลัก+ช่างเสริม (ลำดับแรก = ช่างหลัก), ถอยไปใช้ staff_id เดี่ยวถ้าไม่ส่งมา
    $staffRows = normalizeBookingStaff($pdo, $owner, $input['staff'] ?? null, resolveStaffId($pdo, $owner, $input['staff_id'] ?? null, false));
    $staffId = $staffRows[0]['staff_id'] ?? null; // bookings.staff_id = ช่างหลัก
    $slip   = sanitizeSlip($input['slip_path'] ?? null);
    $force  = !empty($input['force']);
    $categories = $input['category_ids'] ?? [];
    $services   = $input['service_ids'] ?? [];

    if (!$name || !$phone || !$date) {
        http_response_code(400);
        echo json_encode(['error' => 'กรุณากรอก ชื่อ, เบอร์โทร, วันที่']);
        exit;
    }
    if (!isValidDate($date)) {
        http_response_code(400);
        echo json_encode(['error' => 'รูปแบบวันที่ไม่ถูกต้อง']);
        exit;
    }
    $time = resolveBookingTime($pdo, $owner, array_map('intval', (array) $categories), $start, $end, $input['time_slot'] ?? null);
    if (isset($time['error'])) {
        http_response_code(400);
        echo json_encode(['error' => $time['error']]);
        exit;
    }
    $social = mb_substr(trim((string) ($input['social'] ?? '')), 0, 500);
    if ($num < 1) $num = 1;
    if ($num > 999) $num = 999;
    if ($deposit < 0) $deposit = 0;
    if (!in_array($status, ['new', 'confirmed', 'done', 'cancelled'], true)) $status = 'new';

    // กันคิวชน (แยกตามช่าง, ไม่นับคิวที่ยกเลิก, ข้ามตัวเอง) — admin override ได้ด้วย force
    acquireBookingLock($pdo, $owner, $date);
    if ($status !== 'cancelled' && !$force) {
        $conflicts = findTimeConflicts($pdo, $owner, $date, bookingRanges($time), array_column($staffRows, 'staff_id'), $id);
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
                customer_id = ?, staff_id = ?, customer_name = ?, customer_phone = ?, social = ?, location = ?, appointment_date = ?,
                start_time = ?, end_time = ?, time_slot = ?, time_exact = ?, num_people = ?, price = ?, deposit = ?, travel_fee = ?, slip_path = ?, status = ?, note = ?
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$customerId, $staffId, $name, $phone, $social ?: null, $location ?: null, $date, $time['start_time'], $time['end_time'], $time['time_slot'], $time['time_exact'], $num, $price, $deposit, $travelFee, $slip, $status, $note ?: null, $id, $owner]);

        $pdo->prepare("DELETE FROM booking_category_pivot WHERE booking_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM booking_service_pivot WHERE booking_id = ?")->execute([$id]);
        // ผูก pivot เฉพาะประเภท/บริการที่เป็นของเจ้าของ (กันผูกข้ามร้าน)
        $insCat = $pdo->prepare("INSERT INTO booking_category_pivot (booking_id, category_id) SELECT ?, id FROM booking_categories WHERE id = ? AND user_id = ?");
        foreach ((array)$categories as $cid) { $cid = (int)$cid; if ($cid > 0) $insCat->execute([$id, $cid, $owner]); }
        $insSvc = $pdo->prepare("INSERT INTO booking_service_pivot (booking_id, service_id) SELECT ?, id FROM booking_services WHERE id = ? AND user_id = ?");
        foreach ((array)$services as $sid) { $sid = (int)$sid; if ($sid > 0) $insSvc->execute([$id, $sid, $owner]); }
        saveBookingStaff($pdo, $id, $staffRows);
        // ราคาเปลี่ยน → คำนวณสถานะการจ่ายใหม่ (คิวเก่าที่ไม่มีรายการรับเงินคงสถานะเดิมไว้)
        $hasPayments = $pdo->prepare("SELECT EXISTS (SELECT 1 FROM booking_payments WHERE booking_id = ?)");
        $hasPayments->execute([$id]);
        if ($hasPayments->fetchColumn()) {
            syncPaymentStatus($pdo, $id);
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('bookings.php: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง']);
    }
    exit;
}

// POST: สร้างการจองใหม่ (ทั้งจาก admin หลังบ้าน และลูกค้าจองเองผ่าน book.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['action']) && !isset($_POST['action'])) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    // ส่ง shop มา = จองผ่านหน้าสาธารณะเสมอ แม้เบราว์เซอร์นั้นล็อกอินหลังบ้านค้างไว้ (เช่น เจ้าของร้านลองลิงก์จองตัวเอง)
    $shopSlug = (string) ($input['shop'] ?? $_GET['shop'] ?? '');
    $isAdmin = $shopSlug === '' && isLoggedIn();
    if ($isAdmin) {
        requireCsrf();
    }

    // เจ้าของคิว: admin = ตัวเอง, ลูกค้าสาธารณะ = ร้านจาก ?shop / body.shop
    if ($isAdmin) {
        $owner = ownerId();
    } else {
        $owner = resolveShopOwner($pdo, $shopSlug);
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

    if (!$name || !$phone || !$date || (!$isAdmin && !$start)) {
        http_response_code(400);
        echo json_encode(['error' => $isAdmin ? 'กรุณากรอก ชื่อ, เบอร์โทร, วันที่' : 'กรุณากรอก ชื่อ, เบอร์โทร, วันที่, เวลา']);
        exit;
    }
    if (!isValidDate($date) || (!$isAdmin && !preg_match('/^\d{2}:\d{2}/', $start))) {
        http_response_code(400);
        echo json_encode(['error' => 'รูปแบบวันที่หรือเวลาไม่ถูกต้อง']);
        exit;
    }
    if ($num < 1) $num = 1;
    if ($num > 999) $num = 999;
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
        // ช่างที่รับงาน: staff[] = ช่างหลัก+ช่างเสริม (ลำดับแรก = ช่างหลัก), ถอยไปใช้ staff_id เดี่ยวถ้าไม่ส่งมา
        $staffRows = normalizeBookingStaff($pdo, $owner, $input['staff'] ?? null, resolveStaffId($pdo, $owner, $input['staff_id'] ?? null, false));
        $force = !empty($input['force']);
        // เวลาเริ่ม/จบ/แท็กไม่บังคับ — ลูกค้าจองเองต้องเลือกช่องเวลาจริงเสมอ
        $time = resolveBookingTime($pdo, $owner, $categories, $start, $end, $input['time_slot'] ?? null);
        if (isset($time['error'])) {
            http_response_code(400);
            echo json_encode(['error' => $time['error']]);
            exit;
        }
        $start = $time['start_time'];
        $end = $time['end_time'];
        $social = mb_substr(trim((string) ($input['social'] ?? '')), 0, 500);
    } else {
        $time = ['time_slot' => null, 'time_exact' => 1];
        $social = '';
        // ลูกค้าจองเอง: บังคับเป็นคำขอใหม่, ไม่เชื่อราคาจาก client, กันคิวชนแบบบล็อกแข็ง
        if (strlen(preg_replace('/\D/', '', $phone)) < 9) {
            http_response_code(400);
            echo json_encode(['error' => 'กรุณากรอกเบอร์โทรให้ถูกต้อง']);
            exit;
        }
        if (empty($input['consent'])) {
            http_response_code(400);
            echo json_encode(['error' => 'กรุณายอมรับการเก็บข้อมูลก่อนจอง']);
            exit;
        }
        $status = 'new';
        $source = 'customer';
        $paymentStatus = 'unpaid';
        // ลูกค้าจองเองเลือกช่างได้คนเดียว (ช่างหลัก) — ช่างเสริม/มัดจำจ่ายช่าง เป็นงานหลังบ้านเท่านั้น
        $staffRows = normalizeBookingStaff($pdo, $owner, null, resolveStaffId($pdo, $owner, $input['staff_id'] ?? null, true), true);
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
        // บวกราคาบริการเสริมที่เลือก (เฉพาะของร้านนี้ + เปิดใช้งาน)
        if (count($services) > 0) {
            $ph = implode(',', array_fill(0, count($services), '?'));
            $sstmt = $pdo->prepare("SELECT SUM(price) FROM booking_services WHERE user_id = ? AND is_active = 1 AND id IN ($ph)");
            $sstmt->execute(array_merge([$owner], $services));
            $svcPrice = $sstmt->fetchColumn();
            if ($svcPrice !== null) {
                $price = (float) $price + (float) $svcPrice;
            }
        }
    }

    $staffId = $staffRows[0]['staff_id'] ?? null; // bookings.staff_id = ช่างหลัก
    $slip = sanitizeSlip($input['slip_path'] ?? null);
    // ค่าเดินทาง — กรอกได้ทั้งลูกค้าและแอดมิน (ว่าง = ไม่มี)
    $travelFee = isset($input['travel_fee']) && $input['travel_fee'] !== '' ? (float)$input['travel_fee'] : null;
    if ($travelFee !== null && $travelFee < 0) $travelFee = 0;

    // กันคิวชน + ล็อกกัน race — ลูกค้าต้องเลือกช่องเวลาที่ว่างจริง ส่วน admin ชนได้ด้วย force
    acquireBookingLock($pdo, $owner, $date);
    $holdUntil = null;
    if ($isAdmin) {
        if (!$force) {
            $conflicts = findTimeConflicts($pdo, $owner, $date, bookingRanges($time), array_column($staffRows, 'staff_id'), null);
            if (count($conflicts) > 0) {
                http_response_code(409);
                echo json_encode(['error' => 'ช่วงเวลานี้ชนกับคิวอื่น', 'conflict' => true, 'conflicts' => $conflicts]);
                exit;
            }
        }
    } else {
        expireHolds($pdo, $owner);
        $day = computeDaySlots($pdo, $owner, $date, bookingDurationMinutes($pdo, $owner, $categories), $staffId);
        $slot = null;
        foreach ($day['slots'] as $s) {
            if ($s['start'] === substr($start, 0, 5)) {
                $slot = $s;
                break;
            }
        }
        if ($slot === null) {
            http_response_code(409);
            echo json_encode(['error' => $day['closed'] ?? 'ขออภัย ช่วงเวลานี้ไม่ว่างแล้ว กรุณาเลือกเวลาอื่น', 'conflict' => true]);
            exit;
        }
        $start = $slot['start'];
        $end = $slot['end']; // เวลาจบคิดจากระยะเวลาของประเภทงาน ไม่เชื่อค่าจาก client
        $holdHours = (int) getShopSettings($pdo, $owner)['hold_hours'];
        if ($holdHours > 0 && $deposit > 0 && $slip === null) {
            $holdUntil = date('Y-m-d H:i:s', time() + $holdHours * 3600);
        }
    }

    $pdo->beginTransaction();
    try {
        $customerId = upsertCustomer($pdo, $owner, $name, $phone, $isAdmin);
        $stmt = $pdo->prepare("
            INSERT INTO bookings (public_token, user_id, customer_id, staff_id, customer_name, customer_phone, social, location, appointment_date, start_time, end_time, time_slot, time_exact, num_people, price, deposit, travel_fee, payment_status, slip_path, status, hold_until, consent_at, source, note)
            VALUES (:public_token, :user_id, :customer_id, :staff_id, :customer_name, :customer_phone, :social, :location, :appointment_date, :start_time, :end_time, :time_slot, :time_exact, :num_people, :price, :deposit, :travel_fee, :payment_status, :slip_path, :status, :hold_until, :consent_at, :source, :note)
        ");
        $publicToken = newPublicToken();
        $stmt->execute([
            ':public_token'    => $publicToken,
            ':hold_until'      => $holdUntil,
            ':consent_at'      => $isAdmin ? null : date('Y-m-d H:i:s'),
            ':user_id'         => $owner,
            ':customer_id'     => $customerId,
            ':staff_id'        => $staffId,
            ':customer_name'   => $name,
            ':customer_phone'  => $phone,
            ':social'          => $social ?: null,
            ':time_slot'       => $time['time_slot'],
            ':time_exact'      => $time['time_exact'],
            ':location'        => $location ?: null,
            ':appointment_date'=> $date,
            ':start_time'      => $start,
            ':end_time'        => $end,
            ':num_people'      => $num,
            ':price'           => $price,
            ':deposit'         => $deposit,
            ':travel_fee'      => $travelFee,
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
        saveBookingStaff($pdo, $bookingId, $staffRows);
        // admin บันทึกว่ารับเงินแล้วตอนสร้างคิว → ลงรายการรับเงินให้ยอดคงเหลือถูกต้อง
        if ($isAdmin && $paymentStatus !== 'unpaid') {
            $received = $paymentStatus === 'paid' ? (float) $price + (float) $travelFee : (float) $deposit;
            if ($received > 0) {
                recordPayment($pdo, $bookingId, $paymentStatus === 'paid' ? 'balance' : 'deposit', $received, 'other', date('Y-m-d'), null, currentUserId());
            }
        }
        $pdo->commit();
        respondJsonThenContinue(['success' => true, 'id' => $bookingId, 'token' => $publicToken]);
        if (!$isAdmin) {
            $esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
            notifyShopNow($pdo, $owner, "🆕 <b>มีคำขอจองใหม่</b> " . bookingRef($bookingId) . "\n"
                . $esc($name) . ' · ' . $esc($phone) . "\n"
                . date('d/m/Y', strtotime($date)) . ' เวลา ' . $start . '-' . $end
                . ($deposit > 0 ? "\nมัดจำ " . number_format((float) $deposit) . ' บาท' . ($slip ? ' (แนบสลิปแล้ว)' : '') : ''));
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('bookings.php: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
