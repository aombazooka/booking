<?php
/**
 * ฟังก์ชันช่วยเกี่ยวกับการจอง: กันคิวชน + จัดการลูกค้า
 */
require_once __DIR__ . '/bootstrap.php';

/**
 * จำกัดอัตราการเรียก (per key) — กันสแปม เก็บ timestamp เป็นไฟล์ใน data/rate
 * คืน true = ยังไม่เกินโควต้า (และบันทึกครั้งนี้แล้ว), false = เกิน
 */
function rateLimitOk(string $key, int $max, int $windowSec): bool
{
    $dir = dirname(__DIR__) . '/data/rate';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/' . md5($key) . '.json';
    $now = time();
    $arr = is_file($file) ? (json_decode(@file_get_contents($file), true) ?: []) : [];
    $arr = array_values(array_filter($arr, static fn($t) => (int) $t > $now - $windowSec));
    if (count($arr) >= $max) {
        return false;
    }
    $arr[] = $now;
    @file_put_contents($file, json_encode($arr), LOCK_EX);
    return true;
}

/**
 * แปลง count_label ของประเภทงาน → หน่วยที่ใช้แสดงผลหลังบันทึก
 *   'จำนวนชั่วโมง' → 'ชั่วโมง',  'จำนวนคน' → 'คน',  ว่าง/NULL → 'คน' (ค่าเดิมของระบบ)
 * ใช้ให้ตรงกับป้ายในฟอร์มจอง เพราะบางประเภทงานนับเป็นชั่วโมง ไม่ใช่คน
 */
function countUnitFromLabel(?string $label): string
{
    $u = trim((string) $label);
    if ($u === '') {
        return 'คน';
    }
    // ตัดคำนำหน้า "จำนวน" ออก เหลือเฉพาะหน่วย
    $u = trim(preg_replace('/^จำนวน\s*/u', '', $u));
    return $u !== '' ? $u : 'คน';
}

/** SQL subquery: count_label ของประเภทงานแรกที่ตั้งป้ายไว้ (ใช้ร่วมกับ alias b ของตาราง bookings) */
const COUNT_LABEL_SUBQUERY = "(SELECT c.count_label FROM booking_category_pivot p
            JOIN booking_categories c ON c.id = p.category_id
            WHERE p.booking_id = b.id AND c.count_label IS NOT NULL AND c.count_label <> ''
            ORDER BY c.sort_order, c.id LIMIT 1)";

/**
 * ล็อกตามร้าน+วัน (advisory lock ระดับ DB) กันสองคำขอจองช่องเดียวกันพร้อมกัน (TOCTOU)
 * ล็อกทั้งวันเพราะหนึ่งคิวมีช่างได้หลายคน — ล็อกจะถูกปลดเองเมื่อจบ request (ปิด connection)
 * เรียกก่อนเช็คคิวชน+insert
 */
function acquireBookingLock(PDO $pdo, int $userId, string $date): void
{
    $key = 'bk:' . $userId . ':' . $date;
    $pdo->prepare("SELECT GET_LOCK(?, 5)")->execute([$key]);
}

/**
 * แปลง shop_slug → user_id ของเจ้าของร้านที่ "ใช้งานได้ (active)"
 * ใช้ในหน้าจองสาธารณะ เพื่อรู้ว่ากำลังจองร้านไหน
 */
function resolveShopOwner(PDO $pdo, string $slug): ?int
{
    $slug = trim($slug);
    if ($slug === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM app_users WHERE shop_slug = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$slug]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/** ช่วงย่อยของแท็กเวลา (นาทีนับจากเที่ยงคืน) ใช้กันคิวชนเมื่อคิวไม่ได้ระบุเวลาจริง */
const TIME_PARTS = ['morning' => [360, 660], 'noon' => [660, 900], 'evening' => [900, 1260]];

/** แท็กเวลาที่เลือกได้ => [ชื่อที่แสดง, ช่วงย่อย] */
const TIME_SLOTS = [
    'morning'         => ['label' => 'เช้า', 'parts' => ['morning']],
    'noon'            => ['label' => 'เที่ยง', 'parts' => ['noon']],
    'evening'         => ['label' => 'เย็น', 'parts' => ['evening']],
    'morning_noon'    => ['label' => 'เช้าเที่ยง', 'parts' => ['morning', 'noon']],
    'morning_evening' => ['label' => 'เช้าเย็น', 'parts' => ['morning', 'evening']],
    'noon_evening'    => ['label' => 'เที่ยงเย็น', 'parts' => ['noon', 'evening']],
];

/** ช่วงเวลาจริงของคิว (นาที): ระบุเวลา = ช่วงเดียว, มีแท็ก = ตามแท็ก, ไม่มีเลย = ทั้งวัน */
function bookingRanges(array $b): array
{
    if ((int) ($b['time_exact'] ?? 1) === 1) {
        return [[timeToMinutes($b['start_time']), timeToMinutes($b['end_time'])]];
    }
    $tag = $b['time_slot'] ?? null;
    if ($tag !== null && isset(TIME_SLOTS[$tag])) {
        return array_map(static fn($part) => TIME_PARTS[$part], TIME_SLOTS[$tag]['parts']);
    }
    return [[0, 1440]];
}

/** ข้อความเวลาของคิวสำหรับแสดงผล */
function bookingTimeLabel(array $b): string
{
    $tag = TIME_SLOTS[$b['time_slot'] ?? '']['label'] ?? null;
    if ((int) ($b['time_exact'] ?? 1) === 1) {
        $range = substr((string) $b['start_time'], 0, 5) . ' - ' . substr((string) $b['end_time'], 0, 5);
        return $tag ? "$range ($tag)" : $range;
    }
    return $tag ?? 'ไม่ระบุเวลา';
}

/**
 * แปลงเวลาที่กรอกในฟอร์มหลังบ้านเป็นค่าที่บันทึก — เวลาเริ่ม/จบ/แท็ก ไม่บังคับ
 * ใส่เวลาเดียว = เติมอีกค่าจากระยะเวลาของประเภทงาน, ไม่ใส่เวลา = ใช้ช่วงรวมของแท็ก (time_exact = 0)
 * @return array ['start_time','end_time','time_slot','time_exact'] หรือ ['error' => ข้อความ]
 */
function resolveBookingTime(PDO $pdo, int $userId, array $categoryIds, $start, $end, $tag): array
{
    $start = trim((string) $start);
    $end = trim((string) $end);
    $tag = trim((string) $tag);
    $tag = $tag === '' ? null : $tag;
    if ($tag !== null && !isset(TIME_SLOTS[$tag])) {
        return ['error' => 'ช่วงเวลาไม่ถูกต้อง'];
    }
    $timeRe = '/^([01]\d|2[0-3]):[0-5]\d/';
    if (($start !== '' && !preg_match($timeRe, $start)) || ($end !== '' && !preg_match($timeRe, $end))) {
        return ['error' => 'รูปแบบเวลาไม่ถูกต้อง'];
    }
    $start = substr($start, 0, 5);
    $end = substr($end, 0, 5);

    if ($start === '' && $end === '') {
        $ranges = bookingRanges(['time_exact' => 0, 'time_slot' => $tag]);
        return ['start_time' => minutesToTime($ranges[0][0]), 'end_time' => minutesToTime(min(1439, $ranges[count($ranges) - 1][1])),
                'time_slot' => $tag, 'time_exact' => 0];
    }
    $duration = bookingDurationMinutes($pdo, $userId, $categoryIds);
    if ($end === '') {
        $end = minutesToTime(min(1439, timeToMinutes($start) + $duration));
    } elseif ($start === '') {
        $start = minutesToTime(max(0, timeToMinutes($end) - $duration));
    }
    if ($start >= $end) {
        return ['error' => 'เวลาเริ่มต้องน้อยกว่าเวลาจบ'];
    }
    return ['start_time' => $start, 'end_time' => $end, 'time_slot' => $tag, 'time_exact' => 1];
}

/**
 * ตรวจว่าช่วงเวลาที่จะจองทับกับคิวอื่นในวันเดียวกันหรือไม่ (ไม่นับคิวที่ยกเลิก)
 * เทียบทีละช่วงย่อย เพราะคิวแท็ก "เช้าเย็น" ไม่ได้กินเวลาเที่ยง
 *
 * กันคิวชน "แยกตามช่าง": ชนเมื่อช่างคนใดคนหนึ่งของคิวนี้ (หลักหรือเสริม) ติดงานอื่นอยู่ ไม่ว่าจะเป็นหลักหรือเสริม
 * คิวที่ไม่ระบุช่างนับเป็นกลุ่มเดียวกัน (ชนกันเอง ไม่ชนกับคิวที่ระบุช่าง)
 *
 * @param array    $ranges    ช่วงเวลาของคิวใหม่ [[เริ่ม, จบ], ...] เป็นนาที (ดู bookingRanges)
 * @param int[]    $staffIds  ช่างทุกคนของคิวที่กำลังจะบันทึก (ว่าง = ไม่ระบุช่าง)
 * @param int|null $excludeId ข้ามคิว id นี้ (ตอนแก้ไขตัวเอง)
 * @return array  รายการคิวที่ชน (id, customer_name, start_time, end_time, ranges, time_label) — ว่าง = ไม่ชน
 */
function findTimeConflicts(PDO $pdo, int $userId, string $date, array $ranges, array $staffIds = [], ?int $excludeId = null): array
{
    $sql = "
        SELECT b.id, b.customer_name, b.start_time, b.end_time, b.time_slot, b.time_exact
        FROM bookings b
        WHERE b.user_id = ?
          AND b.appointment_date = ?
          AND b.status <> 'cancelled'
    ";
    $params = [$userId, $date];
    if (count($staffIds) === 0) {
        $sql .= " AND b.staff_id IS NULL";
    } else {
        $ph = implode(',', array_fill(0, count($staffIds), '?'));
        $sql .= " AND (b.staff_id IN ($ph) OR EXISTS (SELECT 1 FROM booking_staff bs WHERE bs.booking_id = b.id AND bs.staff_id IN ($ph)))";
        $params = array_merge($params, $staffIds, $staffIds);
    }
    if ($excludeId !== null && $excludeId > 0) {
        $sql .= " AND b.id <> ?";
        $params[] = $excludeId;
    }
    $sql .= " ORDER BY b.start_time";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $conflicts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['ranges'] = bookingRanges($row);
        $row['time_label'] = bookingTimeLabel($row);
        foreach ($row['ranges'] as [$s, $e]) {
            foreach ($ranges as [$newStart, $newEnd]) {
                if ($newStart < $e && $newEnd > $s) {
                    $conflicts[] = $row;
                    continue 3;
                }
            }
        }
    }
    return $conflicts;
}

/**
 * เงื่อนไข SQL ของตัวกรองช่าง ?staff=ID|none — ID รวมคิวที่ช่างคนนั้นเป็นช่างเสริมด้วย
 * @param string $table ชื่อหรือ alias ของตาราง bookings ใน query นั้น
 * @return array [ส่วน SQL ต่อท้าย WHERE, พารามิเตอร์ของส่วนนั้น]
 */
function staffFilterSql($param, string $table = 'b'): array
{
    $param = (string) $param;
    if ($param === 'none') {
        return [" AND $table.staff_id IS NULL", []];
    }
    if ($param !== '' && ctype_digit($param)) {
        $id = (int) $param;
        return [" AND ($table.staff_id = ? OR EXISTS (SELECT 1 FROM booking_staff bsf WHERE bsf.booking_id = $table.id AND bsf.staff_id = ?))", [$id, $id]];
    }
    return ['', []];
}

/**
 * ตรวจ/แปลงค่า staff_id ที่รับมา → คืน int ที่มีอยู่จริง หรือ null (ไม่ระบุช่าง)
 * @param bool $activeOnly true = ยอมเฉพาะช่างที่เปิดใช้งาน (ใช้ตอนลูกค้าจองเอง)
 */
function resolveStaffId(PDO $pdo, int $userId, $raw, bool $activeOnly = false): ?int
{
    if ($raw === null || $raw === '' || $raw === 0 || $raw === '0' || $raw === 'none') {
        return null;
    }
    $id = (int) $raw;
    if ($id <= 0) {
        return null;
    }
    $sql = "SELECT id FROM staff WHERE id = ? AND user_id = ?" . ($activeOnly ? " AND is_active = 1" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id, $userId]);
    return $stmt->fetchColumn() ? $id : null;
}

/** จำนวนช่างสูงสุดต่อคิว (กันส่งข้อมูลมั่วจนแถวบานปลาย) */
const BOOKING_STAFF_MAX = 20;

/**
 * ตรวจ/จัดระเบียบรายชื่อช่างของคิว (ช่างหลัก + ช่างเสริม) ที่รับมาจาก client
 *
 * รับ $raw = [ ['staff_id'=>1,'deposit_paid'=>500,'slip_path'=>'slip_xx.jpg'], ... ]
 * ลำดับแรกในผลลัพธ์ = ช่างหลัก (role main) ที่เหลือ = ช่างเสริม
 * ถ้าไม่ได้ส่ง $raw มา (เช่น ลูกค้าจองเองผ่าน book.php) จะถอยไปใช้ $fallbackStaffId เป็นช่างหลักคนเดียว
 *
 * ตัดทิ้ง: แถวที่ไม่ได้เลือกช่าง, ช่างที่ไม่ใช่ของร้านนี้, ช่างซ้ำ (เก็บแถวแรก)
 * หมายเหตุ: deposit_paid = มัดจำที่จ่ายออกให้ช่าง คนละก้อนกับ bookings.deposit ที่ลูกค้าจ่ายเข้า
 */
function normalizeBookingStaff(PDO $pdo, int $userId, $raw, ?int $fallbackStaffId, bool $activeOnly = false): array
{
    if (!is_array($raw)) {
        return $fallbackStaffId ? [['staff_id' => $fallbackStaffId, 'deposit_paid' => null, 'slip_path' => null]] : [];
    }
    $rows = [];
    $seen = [];
    foreach ($raw as $item) {
        if (count($rows) >= BOOKING_STAFF_MAX) {
            break;
        }
        if (!is_array($item)) {
            $item = ['staff_id' => $item];
        }
        $sid = resolveStaffId($pdo, $userId, $item['staff_id'] ?? null, $activeOnly);
        if ($sid === null || isset($seen[$sid])) {
            continue;
        }
        $seen[$sid] = true;
        $dep = isset($item['deposit_paid']) && $item['deposit_paid'] !== '' ? (float) $item['deposit_paid'] : null;
        if ($dep !== null && $dep < 0) {
            $dep = 0;
        }
        $rows[] = [
            'staff_id'     => $sid,
            'deposit_paid' => $dep,
            'slip_path'    => sanitizeSlip($item['slip_path'] ?? null),
        ];
    }
    return $rows;
}

/**
 * เขียนรายชื่อช่างของคิวลง booking_staff (ลบของเดิมแล้วใส่ใหม่ทั้งชุด)
 * ต้องเรียกในทรานแซกชันเดียวกับการบันทึกคิว — แถวลำดับ 0 = ช่างหลัก
 */
function saveBookingStaff(PDO $pdo, int $bookingId, array $rows): void
{
    $pdo->prepare("DELETE FROM booking_staff WHERE booking_id = ?")->execute([$bookingId]);
    if (count($rows) === 0) {
        return;
    }
    $ins = $pdo->prepare("INSERT INTO booking_staff (booking_id, staff_id, role, deposit_paid, slip_path, sort_order)
                          VALUES (?, ?, ?, ?, ?, ?)");
    foreach (array_values($rows) as $i => $r) {
        $ins->execute([$bookingId, $r['staff_id'], $i === 0 ? 'main' : 'assist', $r['deposit_paid'], $r['slip_path'], $i]);
    }
}

/**
 * ดึงรายชื่อช่างของคิวหลายคิวพร้อมกัน → [booking_id => [ ['staff_id','name','role','deposit_paid','slip_path'], ... ]]
 * ช่างหลักมาก่อนเสมอ
 */
function fetchBookingStaff(PDO $pdo, array $bookingIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $bookingIds))));
    if (count($ids) === 0) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT bs.booking_id, bs.staff_id, bs.role, bs.deposit_paid, bs.slip_path, st.name
        FROM booking_staff bs
        JOIN staff st ON st.id = bs.staff_id
        WHERE bs.booking_id IN ($ph)
        ORDER BY (bs.role = 'main') DESC, bs.sort_order, bs.id
    ");
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['booking_id']][] = [
            'staff_id'     => (int) $r['staff_id'],
            'name'         => $r['name'],
            'role'         => $r['role'],
            'deposit_paid' => $r['deposit_paid'] !== null ? (float) $r['deposit_paid'] : null,
            'slip_path'    => $r['slip_path'],
        ];
    }
    return $out;
}

/**
 * ตรวจชื่อไฟล์สลิป → คืนชื่อไฟล์ที่ปลอดภัยและมีอยู่จริง หรือ null
 * (กัน path traversal + ต้องตรงรูปแบบที่ upload_slip.php สร้าง + ต้องมีไฟล์จริง)
 */
function sanitizeSlip($raw): ?string
{
    $f = basename((string) $raw);
    if (!preg_match('/^slip_[a-f0-9]{8,}\.(jpg|jpeg|png|webp)$/i', $f)) {
        return null;
    }
    return is_file(dirname(__DIR__) . '/uploads/slips/' . $f) ? $f : null;
}

/** เก็บเบอร์โทรรูปแบบเดียว (ตัวเลขและ + เท่านั้น) เพื่อให้จับคู่ลูกค้าเดิมได้แม้พิมพ์ขีด/เว้นวรรคต่างกัน */
function normalizePhone(string $phone): string
{
    return preg_replace('/[^0-9+]/', '', $phone);
}

/** วันที่รูปแบบ YYYY-MM-DD ที่มีอยู่จริงในปฏิทิน */
function isValidDate(string $date): bool
{
    return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/**
 * หา/สร้างลูกค้าจากเบอร์โทร แล้วคืน customer_id
 * ถ้าเบอร์ตรงกับลูกค้าเดิม → ใช้ id เดิม, ไม่งั้นสร้างใหม่
 * @param bool $updateName อัปเดตชื่อลูกค้าเดิมด้วยไหม — false สำหรับหน้าจองสาธารณะ (คนนอกห้ามแก้ข้อมูลลูกค้า)
 */
function upsertCustomer(PDO $pdo, int $userId, string $name, string $phone, bool $updateName = true): ?int
{
    $phone = normalizePhone($phone);
    $name = trim($name);
    if ($phone === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ? AND phone = ? LIMIT 1");
    $stmt->execute([$userId, $phone]);
    $id = $stmt->fetchColumn();
    if ($id) {
        if ($updateName && $name !== '') {
            $pdo->prepare("UPDATE customers SET name = ? WHERE id = ?")->execute([$name, (int)$id]);
        }
        return (int) $id;
    }
    // ON DUPLICATE: สองคำขอเบอร์เดียวกันพร้อมกัน ให้ใช้แถวเดิม
    $pdo->prepare("INSERT INTO customers (user_id, name, phone) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)")
        ->execute([$userId, $name, $phone]);
    return (int) $pdo->lastInsertId();
}

/** เลขจองที่แสดงให้ลูกค้าเห็น */
function bookingRef(int $bookingId): string
{
    return 'BK' . str_pad((string) $bookingId, 6, '0', STR_PAD_LEFT);
}

/** รหัสลับในลิงก์ติดตามคิว */
function newPublicToken(): string
{
    return bin2hex(random_bytes(10));
}

/**
 * ตอบ JSON ให้ผู้ใช้ทันที แล้วทำงานต่อหลังปิดการเชื่อมต่อ
 * ใช้กับการแจ้งเตือนร้าน เพื่อไม่ให้ลูกค้าต้องรอ API ภายนอก
 */
function respondJsonThenContinue(array $data): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ignore_user_abort(true);
    $body = json_encode($data, JSON_UNESCAPED_UNICODE);
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    echo $body;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

/** ค่าตั้งร้าน พร้อมค่าเริ่มต้นสำหรับร้านที่ยังไม่เคยตั้ง */
function getShopSettings(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("SELECT open_time, close_time, closed_weekdays, slot_minutes, buffer_minutes, hold_hours, promptpay_id
                           FROM shop_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'open_time' => '06:00:00', 'close_time' => '20:00:00', 'closed_weekdays' => '',
        'slot_minutes' => 30, 'buffer_minutes' => 0, 'hold_hours' => 0, 'promptpay_id' => '',
    ];
}

/** ระยะเวลางานรวมของประเภทงานที่เลือก (นาที) — ประเภทที่ไม่ได้ตั้งเวลาไว้ถือเป็น 60 นาที */
function bookingDurationMinutes(PDO $pdo, int $userId, array $categoryIds): int
{
    if (count($categoryIds) === 0) {
        return 60;
    }
    $ph = implode(',', array_fill(0, count($categoryIds), '?'));
    $stmt = $pdo->prepare("SELECT SUM(COALESCE(NULLIF(duration_min, 0), 60)) FROM booking_categories WHERE user_id = ? AND id IN ($ph)");
    $stmt->execute(array_merge([$userId], $categoryIds));
    return (int) $stmt->fetchColumn() ?: 60;
}

/** 'HH:MM[:SS]' → นาทีนับจากเที่ยงคืน */
function timeToMinutes(string $time): int
{
    return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
}

function minutesToTime(int $minutes): string
{
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

/**
 * ช่องเวลาที่จองได้ของวันหนึ่ง คิดจากเวลาทำการ วันหยุด วันลาช่าง คิวเดิม และเวลาเผื่อเดินทาง
 * @param int|null $staffId ช่างที่เลือก (null = ไม่ระบุช่าง)
 * @return array ['closed' => เหตุผลที่ปิด|null, 'slots' => [['start' => 'HH:MM', 'end' => 'HH:MM'], ...]]
 */
function computeDaySlots(PDO $pdo, int $userId, string $date, int $duration, ?int $staffId): array
{
    $shop = getShopSettings($pdo, $userId);
    $closedDays = $shop['closed_weekdays'] === '' ? [] : explode(',', $shop['closed_weekdays']);
    if (in_array(date('w', strtotime($date)), $closedDays, true)) {
        return ['closed' => 'ร้านปิดทำการวันนี้', 'slots' => []];
    }

    $stmt = $pdo->prepare("SELECT staff_id, note FROM shop_closures
                           WHERE user_id = ? AND ? BETWEEN date_from AND date_to AND (staff_id IS NULL OR staff_id = ?)
                           ORDER BY staff_id IS NULL DESC LIMIT 1");
    $stmt->execute([$userId, $date, $staffId]);
    $closure = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($closure) {
        return ['closed' => $closure['note'] ?: ($closure['staff_id'] === null ? 'ร้านปิดทำการวันนี้' : 'ช่างคนนี้ไม่รับงานวันนี้'), 'slots' => []];
    }

    $busy = findTimeConflicts($pdo, $userId, $date, [[0, 1440]], $staffId ? [$staffId] : []);
    $buffer = (int) $shop['buffer_minutes'];
    $step = max(10, (int) $shop['slot_minutes']);
    $close = timeToMinutes($shop['close_time']);
    $now = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
    $earliest = $date === $now->format('Y-m-d') ? (int) $now->format('H') * 60 + (int) $now->format('i') + 1 : 0;

    $slots = [];
    for ($start = timeToMinutes($shop['open_time']); $start + $duration <= $close; $start += $step) {
        if ($start < $earliest) {
            continue;
        }
        $free = true;
        foreach ($busy as $b) {
            foreach ($b['ranges'] as [$busyStart, $busyEnd]) {
                if ($start - $buffer < $busyEnd && $start + $duration + $buffer > $busyStart) {
                    $free = false;
                    break 2;
                }
            }
        }
        if ($free) {
            $slots[] = ['start' => minutesToTime($start), 'end' => minutesToTime($start + $duration)];
        }
    }
    return ['closed' => null, 'slots' => $slots];
}

/** ปล่อยคิวที่ลูกค้าไม่จ่ายมัดจำ/ไม่แนบสลิปภายในเวลาที่ถือไว้ — เรียกทุกครั้งก่อนอ่านคิวของร้าน */
function expireHolds(PDO $pdo, int $userId): void
{
    $pdo->prepare("UPDATE bookings
                   SET status = 'cancelled', note = CONCAT_WS('\n', note, 'ระบบยกเลิกอัตโนมัติ: ไม่ได้ชำระมัดจำภายในเวลาที่กำหนด')
                   WHERE user_id = ? AND status = 'new' AND payment_status = 'unpaid' AND slip_path IS NULL
                     AND hold_until IS NOT NULL AND hold_until < ?")
        ->execute([$userId, date('Y-m-d H:i:s')]);
}

/** SQL subquery: เงินรับสุทธิของคิว (หักคืนเงินแล้ว) ใช้ร่วมกับ alias b ของตาราง bookings */
const PAID_TOTAL_SUBQUERY = "(SELECT COALESCE(SUM(IF(pm.kind = 'refund', -pm.amount, pm.amount)), 0)
            FROM booking_payments pm WHERE pm.booking_id = b.id)";

/**
 * สรุปยอดเงินของคิว
 * @return array ['total' => ยอดรวม|null, 'paid' => รับแล้ว, 'balance' => คงเหลือ|null] — null เมื่อร้านยังไม่ใส่ราคา
 */
function bookingMoney(PDO $pdo, int $bookingId): array
{
    $stmt = $pdo->prepare("SELECT b.price, b.travel_fee, " . PAID_TOTAL_SUBQUERY . " AS paid FROM bookings b WHERE b.id = ?");
    $stmt->execute([$bookingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $paid = (float) $row['paid'];
    if ($row['price'] === null) {
        return ['total' => null, 'paid' => $paid, 'balance' => null];
    }
    $total = (float) $row['price'] + (float) $row['travel_fee'];
    return ['total' => $total, 'paid' => $paid, 'balance' => max(0, $total - $paid)];
}

function recordPayment(PDO $pdo, int $bookingId, string $kind, float $amount, string $method, string $paidAt, ?string $note, ?int $createdBy): void
{
    $pdo->prepare("INSERT INTO booking_payments (booking_id, kind, amount, method, paid_at, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$bookingId, $kind, $amount, $method, $paidAt, $note, $createdBy]);
}

/** คำนวณสถานะการจ่ายของคิวจากเงินที่รับจริง — ยังไม่ใส่ราคาแต่รับเงินแล้ว นับเป็นจ่ายมัดจำ */
function syncPaymentStatus(PDO $pdo, int $bookingId): void
{
    $m = bookingMoney($pdo, $bookingId);
    $status = 'unpaid';
    if ($m['paid'] > 0) {
        $status = ($m['total'] !== null && $m['total'] > 0 && $m['paid'] >= $m['total']) ? 'paid' : 'deposit_paid';
    }
    $pdo->prepare("UPDATE bookings SET payment_status = ? WHERE id = ?")->execute([$status, $bookingId]);
}
