<?php
/**
 * API สรุปคิวงานรายเดือน (admin เท่านั้น) — ข้อมูลแบบย่อสำหรับหน้าประกาศคิวงาน
 *   GET ?month=YYYY-MM[&staff=ID|none]
 * คืน: ชื่อร้าน, ยอดรวมของเดือน, และรายการคิวจัดกลุ่มตามวัน (ไม่นับงานที่ยกเลิก)
 * ตั้งใจไม่ส่งเบอร์โทร/ราคา/ที่อยู่ เพราะหน้านี้ใช้แคปหน้าจอเผยแพร่
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

// มี ?shop=slug = โหมดสาธารณะเสมอ (ปฏิทินดูอย่างเดียว ไม่ส่งชื่อลูกค้า)
// ไม่มี shop = โหมดแอดมิน ต้อง login (ร้านตัวเอง + เห็นชื่อลูกค้า)
$shopParam = trim($_GET['shop'] ?? '');
if ($shopParam !== '') {
    $owner = resolveShopOwner($pdo, $shopParam);
    if (!$owner) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบร้าน']);
        exit;
    }
    $public = true;
} else {
    requireLoginApi();
    $owner = ownerId();
    $public = false;
}

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    http_response_code(400);
    echo json_encode(['error' => 'ต้องการ month (YYYY-MM)']);
    exit;
}
$start = $month . '-01';
$end   = date('Y-m-t', strtotime($start));

// ตัวกรองช่าง (ออปชัน)
[$staffSql, $staffArgs] = staffFilterSql($_GET['staff'] ?? '');

$sql = "
    SELECT b.id, b.appointment_date, b.start_time, b.end_time, b.time_slot, b.time_exact, b.customer_name,
           b.num_people, b.status,
           st.name AS staff_name, st.color_hex AS staff_color,
           (SELECT GROUP_CONCAT(c.name ORDER BY c.sort_order, c.id SEPARATOR '|') FROM booking_category_pivot p
            JOIN booking_categories c ON c.id = p.category_id WHERE p.booking_id = b.id) AS cat_names,
           (SELECT GROUP_CONCAT(c.color_hex ORDER BY c.sort_order, c.id SEPARATOR '|') FROM booking_category_pivot p
            JOIN booking_categories c ON c.id = p.category_id WHERE p.booking_id = b.id) AS cat_colors
    FROM bookings b
    LEFT JOIN staff st ON st.id = b.staff_id
    WHERE b.user_id = ? AND b.appointment_date BETWEEN ? AND ? AND b.status <> 'cancelled'" . $staffSql . "
    ORDER BY b.appointment_date, b.start_time, b.id";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$owner, $start, $end], $staffArgs));

$days = [];       // date => รายการคิวของวันนั้น
$totalPeople = 0;
$total = 0;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $names  = $r['cat_names']  !== null && $r['cat_names']  !== '' ? explode('|', $r['cat_names'])  : [];
    $colors = $r['cat_colors'] !== null && $r['cat_colors'] !== '' ? explode('|', $r['cat_colors']) : [];
    $cats = [];
    foreach ($names as $i => $n) {
        $cats[] = ['name' => $n, 'color' => $colors[$i] ?? '#9ca3af'];
    }
    $d = $r['appointment_date'];
    $days[$d][] = [
        'start'      => substr((string) $r['start_time'], 0, 5),
        'end'        => substr((string) $r['end_time'], 0, 5),
        'time_label' => bookingTimeLabel($r),
        'name'       => $public ? null : $r['customer_name'],
        'people'     => (int) $r['num_people'],
        'status'     => $r['status'],
        'categories' => $cats,
        'staff'      => $r['staff_name'] !== null
            ? ['name' => $r['staff_name'], 'color' => $r['staff_color'] ?: '#9ca3af']
            : null,
    ];
    $totalPeople += (int) $r['num_people'];
    $total++;
}

$daysOut = [];
foreach ($days as $date => $items) {
    $daysOut[] = ['date' => $date, 'items' => $items];
}

$daysInMonth = (int) date('t', strtotime($start));

// สถานะรายวัน (ว่าง/เต็ม/หยุด) — คำนวณเมื่อ cal.php ขอ (?days_status=1) เท่านั้น
// อดีตไม่คำนวณ (ไม่เกี่ยวกับการจอง) — ส่งเฉพาะวันนี้เป็นต้นไป
$dayStatus = [];
if (isset($_GET['days_status'])) {
    expireHolds($pdo, $owner);
    $dur = bookingDurationMinutes($pdo, $owner, []);
    $staffParam = trim((string) ($_GET['staff'] ?? ''));
    $dsStaff = ($staffParam !== '' && ctype_digit($staffParam)) ? (int) $staffParam : null;
    $todayStr = date('Y-m-d');
    for ($dd = 1; $dd <= $daysInMonth; $dd++) {
        $date = sprintf('%s-%02d', $month, $dd);
        if ($date < $todayStr) {
            continue;
        }
        $ds = computeDaySlots($pdo, $owner, $date, $dur, $dsStaff);
        if ($ds['closed'] !== null) {
            $dayStatus[$date] = ['s' => 'closed', 'r' => $ds['closed']];
        } elseif (count($ds['slots']) === 0) {
            $dayStatus[$date] = ['s' => 'full'];
        } else {
            $dayStatus[$date] = ['s' => 'open'];
        }
    }
}

// ชื่อร้าน (ใช้เป็นหัวโปสเตอร์)
$shopStmt = $pdo->prepare("SELECT shop_name FROM app_users WHERE id = ?");
$shopStmt->execute([$owner]);
$shopName = (string) ($shopStmt->fetchColumn() ?: '');

// รายชื่อช่าง (สำหรับ dropdown ตัวกรอง)
$soStmt = $pdo->prepare("SELECT id, name, color_hex FROM staff WHERE user_id = ? AND is_active = 1 ORDER BY sort_order, id");
$soStmt->execute([$owner]);
$staffOptions = array_map(function ($s) {
    return ['id' => (int) $s['id'], 'name' => $s['name'], 'color' => $s['color_hex']];
}, $soStmt->fetchAll(PDO::FETCH_ASSOC));

echo json_encode([
    'month'         => $month,
    'shop_name'     => $shopName,
    'range'         => ['start' => $start, 'end' => $end],
    'summary'       => [
        'total_bookings' => $total,
        'busy_days'      => count($daysOut),
        'free_days'      => $daysInMonth - count($daysOut),
        'total_people'   => $totalPeople,
        'days_in_month'  => $daysInMonth,
    ],
    'days'          => $daysOut,
    'day_status'    => $dayStatus,
    'staff_options' => $staffOptions,
], JSON_UNESCAPED_UNICODE);
