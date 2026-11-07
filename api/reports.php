<?php
/**
 * API รายงานสรุป (admin เท่านั้น)
 *   GET ?start=YYYY-MM-DD&end=YYYY-MM-DD[&staff=ID|none]
 * คืนสรุป: ยอดรวม, แยกตามสถานะงาน, การจ่ายเงิน, ที่มา, ประเภทงาน, ช่าง, รายเดือน
 * นิยาม "รายได้/มูลค่างาน" = ผลรวม price ของงานที่ไม่ถูกยกเลิก
 * นิยาม "มัดจำ" = เฉพาะงานที่รับเงินแล้ว (payment_status ไม่ใช่ unpaid)
 * ตัวกรอง staff (ออปชัน): ID = งานที่ช่างนั้นร่วมทำ (หลักหรือเสริม), none = เฉพาะงานไม่ระบุช่าง
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
$owner = ownerId();

$start = $_GET['start'] ?? date('Y-m-01');
$end   = $_GET['end']   ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    http_response_code(400);
    echo json_encode(['error' => 'ต้องการ start และ end (YYYY-MM-DD)']);
    exit;
}
if ($start > $end) { [$start, $end] = [$end, $start]; }

// ตัวกรองช่าง — sf = query บนตาราง bookings ตรงๆ, sfB = query ที่ใช้ alias b.
$staffParam = $_GET['staff'] ?? '';
[$sf, $sfArgs] = staffFilterSql($staffParam, 'bookings');
[$sfB] = staffFilterSql($staffParam, 'b');
$base = [$owner, $start, $end];  // params: เจ้าของ + ช่วงวันที่
$argsMain = array_merge($base, $sfArgs);

// ยอดรวม (ไม่นับยกเลิก)
$sumStmt = $pdo->prepare("
    SELECT COUNT(*) AS total_bookings, COALESCE(SUM(b.price),0) AS total_revenue,
           COALESCE(SUM(CASE WHEN b.payment_status <> 'unpaid' THEN b.deposit END),0) AS total_deposit,
           COALESCE(SUM(b.num_people),0) AS total_people,
           COALESCE(SUM(" . PAID_TOTAL_SUBQUERY . "),0) AS total_received,
           COALESCE(SUM(CASE WHEN b.price IS NOT NULL
               THEN GREATEST(b.price + COALESCE(b.travel_fee,0) - " . PAID_TOTAL_SUBQUERY . ", 0) END),0) AS total_outstanding,
           COALESCE(SUM(b.price IS NULL),0) AS unpriced
    FROM bookings b WHERE b.user_id = ? AND b.appointment_date BETWEEN ? AND ? AND b.status <> 'cancelled'" . $sfB);
$sumStmt->execute($argsMain);
$s = $sumStmt->fetch(PDO::FETCH_ASSOC);
$summary = [
    'total_bookings' => (int) $s['total_bookings'],
    'total_revenue'  => (float) $s['total_revenue'],
    'total_deposit'  => (float) $s['total_deposit'],
    'total_people'   => (int) $s['total_people'],
    'total_received' => (float) $s['total_received'],
    'total_outstanding' => (float) $s['total_outstanding'],
    'unpriced'       => (int) $s['unpriced'],
];

// แยกตามสถานะ (รวมยกเลิก)
$statusStmt = $pdo->prepare("SELECT status, COUNT(*) c FROM bookings WHERE user_id = ? AND appointment_date BETWEEN ? AND ?" . $sf . " GROUP BY status");
$statusStmt->execute($argsMain);
$byStatus = [];
foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $byStatus[$r['status']] = (int) $r['c']; }

// แยกตามการจ่าย (ไม่นับยกเลิก)
$payStmt = $pdo->prepare("SELECT payment_status, COUNT(*) c, COALESCE(SUM(price),0) revenue, COALESCE(SUM(deposit),0) deposit
    FROM bookings WHERE user_id = ? AND appointment_date BETWEEN ? AND ? AND status <> 'cancelled'" . $sf . " GROUP BY payment_status");
$payStmt->execute($argsMain);
$byPayment = array_map(function ($p) {
    return ['payment_status' => $p['payment_status'], 'c' => (int) $p['c'], 'revenue' => (float) $p['revenue'], 'deposit' => (float) $p['deposit']];
}, $payStmt->fetchAll(PDO::FETCH_ASSOC));

// แยกตามที่มา
$srcStmt = $pdo->prepare("SELECT source, COUNT(*) c FROM bookings WHERE user_id = ? AND appointment_date BETWEEN ? AND ?" . $sf . " GROUP BY source");
$srcStmt->execute($argsMain);
$bySource = [];
foreach ($srcStmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $bySource[$r['source']] = (int) $r['c']; }

// แยกตามประเภทงาน (ผ่าน pivot) — งานหลายประเภทนับจำนวนในทุกประเภท แต่แบ่งมูลค่าเท่าๆ กัน ยอดรวมจึงไม่ซ้ำ
$catStmt = $pdo->prepare("SELECT c.id, c.name, c.color_hex, COUNT(*) cnt,
        COALESCE(SUM(b.price / (SELECT COUNT(*) FROM booking_category_pivot x WHERE x.booking_id = b.id)),0) revenue
    FROM booking_category_pivot pv
    JOIN booking_categories c ON c.id = pv.category_id
    JOIN bookings b ON b.id = pv.booking_id
    WHERE b.user_id = ? AND b.appointment_date BETWEEN ? AND ? AND b.status <> 'cancelled'" . $sfB . "
    GROUP BY c.id, c.name, c.color_hex ORDER BY cnt DESC, revenue DESC");
$catStmt->execute($argsMain);
$byCategory = array_map(function ($c) {
    return ['id' => (int) $c['id'], 'name' => $c['name'], 'color_hex' => $c['color_hex'], 'cnt' => (int) $c['cnt'], 'revenue' => (float) $c['revenue']];
}, $catStmt->fetchAll(PDO::FETCH_ASSOC));

// แยกตามช่าง (งานไม่ระบุช่าง → id NULL) — นับงานทั้งช่างหลัก+เสริม, มูลค่าลงที่ช่างหลักคนเดียวเพื่อไม่ให้ยอดซ้ำ
// payout = มัดจำที่จ่ายออกให้ช่าง; ถ้ากรองช่าง แสดงเฉพาะแถวของช่างคนนั้น
$onlyStaff = ($staffParam !== '' && $staffParam !== 'none' && count($sfArgs) > 0);
$staffStmt = $pdo->prepare("SELECT st.id, st.name, st.color_hex, COUNT(*) cnt,
        COALESCE(SUM(CASE WHEN bs.role = 'main' OR bs.id IS NULL THEN b.price END),0) revenue,
        COALESCE(SUM(bs.deposit_paid),0) payout
    FROM bookings b
    LEFT JOIN booking_staff bs ON bs.booking_id = b.id
    LEFT JOIN staff st ON st.id = bs.staff_id
    WHERE b.user_id = ? AND b.appointment_date BETWEEN ? AND ? AND b.status <> 'cancelled'" . $sfB . ($onlyStaff ? ' AND bs.staff_id = ?' : '') . "
    GROUP BY st.id, st.name, st.color_hex ORDER BY cnt DESC, revenue DESC");
$staffStmt->execute($onlyStaff ? array_merge($argsMain, [$sfArgs[0]]) : $argsMain);
$byStaff = array_map(function ($s) {
    return [
        'id'        => $s['id'] !== null ? (int) $s['id'] : null,
        'name'      => $s['name'] !== null ? $s['name'] : 'ไม่ระบุช่าง',
        'color_hex' => $s['color_hex'] ?: '#9ca3af',
        'cnt'       => (int) $s['cnt'],
        'revenue'   => (float) $s['revenue'],
        'payout'    => (float) $s['payout'],
    ];
}, $staffStmt->fetchAll(PDO::FETCH_ASSOC));

// รายเดือน (ไม่นับยกเลิก)
$monthStmt = $pdo->prepare("SELECT DATE_FORMAT(appointment_date,'%Y-%m') ym, COUNT(*) cnt, COALESCE(SUM(price),0) revenue
    FROM bookings WHERE user_id = ? AND appointment_date BETWEEN ? AND ? AND status <> 'cancelled'" . $sf . " GROUP BY ym ORDER BY ym");
$monthStmt->execute($argsMain);
$byMonth = array_map(function ($m) {
    return ['ym' => $m['ym'], 'cnt' => (int) $m['cnt'], 'revenue' => (float) $m['revenue']];
}, $monthStmt->fetchAll(PDO::FETCH_ASSOC));

// รายชื่อช่าง (สำหรับ dropdown ตัวกรอง)
$soStmt = $pdo->prepare("SELECT id, name FROM staff WHERE user_id = ? AND is_active = 1 ORDER BY sort_order, id");
$soStmt->execute([$owner]);
$staffOptions = array_map(function ($s) {
    return ['id' => (int) $s['id'], 'name' => $s['name']];
}, $soStmt->fetchAll(PDO::FETCH_ASSOC));

echo json_encode([
    'range'         => ['start' => $start, 'end' => $end],
    'summary'       => $summary,
    'by_status'     => $byStatus,
    'by_payment'    => $byPayment,
    'by_source'     => $bySource,
    'by_category'   => $byCategory,
    'by_staff'      => $byStaff,
    'by_month'      => $byMonth,
    'staff_options' => $staffOptions,
], JSON_UNESCAPED_UNICODE);
