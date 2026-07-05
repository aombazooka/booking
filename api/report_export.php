<?php
/**
 * ส่งออกรายการจองเป็น CSV (admin) — สำหรับทำบัญชี/ส่งต่อ
 *   GET ?start=YYYY-MM-DD&end=YYYY-MM-DD[&staff=ID|none]
 * ใส่ BOM ให้ Excel เปิดภาษาไทยถูกต้อง
 */
require dirname(__DIR__) . '/app/auth.php';
requireLogin();

$start = $_GET['start'] ?? date('Y-m-01');
$end   = $_GET['end']   ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    http_response_code(400);
    exit('invalid range');
}
if ($start > $end) { [$start, $end] = [$end, $start]; }

$pdo = require dirname(__DIR__) . '/app/db.php';
$owner = ownerId();

$sql = "
    SELECT b.appointment_date, b.start_time, b.end_time, b.customer_name, b.customer_phone,
           b.num_people, b.price, b.deposit, b.payment_status, b.status, b.source,
           st.name AS staff_name,
           (SELECT GROUP_CONCAT(c.name SEPARATOR ', ') FROM booking_category_pivot p
            JOIN booking_categories c ON c.id = p.category_id WHERE p.booking_id = b.id) AS category_names
    FROM bookings b
    LEFT JOIN staff st ON st.id = b.staff_id
    WHERE b.user_id = ? AND b.appointment_date BETWEEN ? AND ?
";
$params = [$owner, $start, $end];
$staff = $_GET['staff'] ?? '';
if ($staff === 'none') { $sql .= " AND b.staff_id IS NULL"; }
elseif ($staff !== '' && ctype_digit((string) $staff)) { $sql .= " AND b.staff_id = ?"; $params[] = (int) $staff; }
$sql .= " ORDER BY b.appointment_date, b.start_time";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$statusMap  = ['new' => 'ใหม่', 'confirmed' => 'ยืนยันแล้ว', 'done' => 'เสร็จงาน', 'cancelled' => 'ยกเลิก'];
$payMap     = ['unpaid' => 'ยังไม่จ่าย', 'deposit_paid' => 'จ่ายมัดจำ', 'paid' => 'จ่ายครบ'];
$srcMap     = ['admin' => 'ร้านบันทึก', 'customer' => 'ลูกค้าจองเอง'];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="bookings_' . $start . '_to_' . $end . '.csv"');

// กัน CSV/formula injection: ขึ้นต้นด้วย = + - @ tab หรือ CR → นำหน้าด้วย ' (เมื่อเปิดใน Excel จะไม่รันเป็นสูตร)
$csvSafe = static function ($v) {
    $s = (string) $v;
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $s;
    }
    return $s;
};

echo "\xEF\xBB\xBF"; // BOM
$out = fopen('php://output', 'w');
fputcsv($out, ['วันที่', 'เวลาเริ่ม', 'เวลาจบ', 'ลูกค้า', 'เบอร์โทร', 'ประเภทงาน', 'ช่าง', 'จำนวน', 'ราคา', 'มัดจำ', 'การจ่าย', 'สถานะ', 'ที่มา']);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    fputcsv($out, [
        $r['appointment_date'],
        substr((string) $r['start_time'], 0, 5),
        substr((string) $r['end_time'], 0, 5),
        $csvSafe($r['customer_name']),
        $csvSafe($r['customer_phone']),
        $csvSafe($r['category_names'] ?? ''),
        $csvSafe($r['staff_name'] ?? 'ไม่ระบุ'),
        (int) $r['num_people'],
        $r['price'] !== null ? (float) $r['price'] : '',
        (float) $r['deposit'],
        $payMap[$r['payment_status']] ?? $r['payment_status'],
        $statusMap[$r['status']] ?? $r['status'],
        $srcMap[$r['source']] ?? $r['source'],
    ]);
}
fclose($out);
