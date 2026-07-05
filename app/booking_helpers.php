<?php
/**
 * ฟังก์ชันช่วยเกี่ยวกับการจอง: กันคิวชน + จัดการลูกค้า
 */

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
 * ล็อกตามวัน+ช่าง (advisory lock ระดับ DB) กันสองคำขอจองช่องเดียวกันพร้อมกัน (TOCTOU)
 * ล็อกจะถูกปลดเองเมื่อจบ request (ปิด connection) — เรียกก่อนเช็คคิวชน+insert
 */
function acquireBookingLock(PDO $pdo, int $userId, string $date, ?int $staffId): void
{
    $key = 'bk:' . $userId . ':' . $date . ':' . ($staffId ?? 0);
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

/**
 * ตรวจว่าช่วงเวลาที่จะจองทับกับคิวอื่นในวันเดียวกันหรือไม่ (ไม่นับคิวที่ยกเลิก)
 * เงื่อนไข overlap: ใหม่.start < เดิม.end  AND  ใหม่.end > เดิม.start
 *
 * กันคิวชน "แยกตามช่าง": ชนเฉพาะคิวของช่างคนเดียวกัน (NULL = ไม่ระบุช่าง นับเป็นกลุ่มเดียวกัน
 * ด้วยตัวดำเนินการ NULL-safe `<=>`)
 *
 * @param int|null $staffId   ช่างของคิวที่กำลังจะบันทึก (NULL = ไม่ระบุ)
 * @param int|null $excludeId ข้ามคิว id นี้ (ตอนแก้ไขตัวเอง)
 * @return array  รายการคิวที่ชน (id, customer_name, start_time, end_time) — ว่าง = ไม่ชน
 */
function findTimeConflicts(PDO $pdo, int $userId, string $date, string $start, string $end, ?int $staffId = null, ?int $excludeId = null): array
{
    $sql = "
        SELECT id, customer_name, start_time, end_time
        FROM bookings
        WHERE user_id = ?
          AND appointment_date = ?
          AND status <> 'cancelled'
          AND staff_id <=> ?
          AND start_time < ?
          AND end_time > ?
    ";
    $params = [$userId, $date, $staffId, $end, $start];
    if ($excludeId !== null && $excludeId > 0) {
        $sql .= " AND id <> ?";
        $params[] = $excludeId;
    }
    $sql .= " ORDER BY start_time";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

/**
 * หา/สร้างลูกค้าจากเบอร์โทร แล้วคืน customer_id
 * ถ้าเบอร์ตรงกับลูกค้าเดิม → ใช้ id เดิม (อัปเดตชื่อถ้าต่าง), ไม่งั้นสร้างใหม่
 */
function upsertCustomer(PDO $pdo, int $userId, string $name, string $phone): ?int
{
    $phone = trim($phone);
    $name = trim($name);
    if ($phone === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ? AND phone = ? LIMIT 1");
    $stmt->execute([$userId, $phone]);
    $id = $stmt->fetchColumn();
    if ($id) {
        if ($name !== '') {
            $pdo->prepare("UPDATE customers SET name = ? WHERE id = ?")->execute([$name, (int)$id]);
        }
        return (int) $id;
    }
    $pdo->prepare("INSERT INTO customers (user_id, name, phone) VALUES (?, ?, ?)")->execute([$userId, $name, $phone]);
    return (int) $pdo->lastInsertId();
}
