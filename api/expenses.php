<?php
/**
 * API รายจ่ายของร้านและกำไรรายเดือน (admin เท่านั้น)
 *   GET ?month=YYYY-MM              → รายการของเดือน + สรุป + แยกหมวด + ตารางทั้งปี
 *   POST {expense_date, category, amount, note, is_recurring} → เพิ่มรายการ
 *   POST ?action=copy_recurring {month} → ดึงรายจ่ายประจำจากเดือนก่อนมาเดือนนี้ (ดึงซ้ำไม่เบิ้ล)
 *   PUT {id, ...}                   → แก้ไข
 *   DELETE ?id=5                    → ลบ
 *
 * นิยามกำไร (ตามกระแสเงินจริง):
 *   รายรับ   = เงินที่รับจริงตามวันที่รับเงิน หักคืนเงินแล้ว (booking_payments)
 *   จ่ายช่าง = มัดจำจ่ายช่างของคิวในเดือนนั้น ไม่นับคิวที่ยกเลิก (booking_staff)
 *   รายจ่าย  = รายการที่ร้านบันทึกเอง (shop_expenses)
 *   กำไร     = รายรับ - จ่ายช่าง - รายจ่าย
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
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') requireCsrf();
$owner = ownerId();

function isValidMonth(string $month): bool
{
    return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month);
}

/** ตรวจข้อมูลรายการ — ไม่ผ่าน = ตอบ 400 แล้วจบ */
function expenseInput(array $in): array
{
    $date = (string) ($in['expense_date'] ?? '');
    $category = trim((string) ($in['category'] ?? ''));
    $amount = round((float) ($in['amount'] ?? 0), 2);
    $note = trim((string) ($in['note'] ?? ''));
    $error = null;
    if (!isValidDate($date)) {
        $error = 'กรุณาเลือกวันที่ให้ถูกต้อง';
    } elseif ($category === '' || mb_strlen($category) > 60) {
        $error = 'กรุณากรอกหมวดรายจ่าย (ไม่เกิน 60 ตัวอักษร)';
    } elseif ($amount <= 0 || $amount > 99999999) {
        $error = 'กรุณากรอกจำนวนเงินให้ถูกต้อง';
    } elseif (mb_strlen($note) > 200) {
        $error = 'หมายเหตุยาวเกิน 200 ตัวอักษร';
    }
    if ($error !== null) {
        http_response_code(400);
        echo json_encode(['error' => $error]);
        exit;
    }
    return [$date, $category, $amount, $note !== '' ? $note : null, empty($in['is_recurring']) ? 0 : 1];
}

if ($method === 'GET') {
    $month = (string) ($_GET['month'] ?? date('Y-m'));
    if (!isValidMonth($month)) {
        http_response_code(400);
        echo json_encode(['error' => 'ต้องการ month (YYYY-MM)']);
        exit;
    }
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));

    $stmt = $pdo->prepare("SELECT id, expense_date, category, amount, note, is_recurring
                           FROM shop_expenses WHERE user_id = ? AND expense_date BETWEEN ? AND ?
                           ORDER BY expense_date DESC, id DESC");
    $stmt->execute([$owner, $start, $end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byCategory = [];
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['amount'] = (float) $r['amount'];
        $r['is_recurring'] = (int) $r['is_recurring'];
        $byCategory[$r['category']] = ($byCategory[$r['category']] ?? 0) + $r['amount'];
    }
    unset($r);
    arsort($byCategory);
    $categories = [];
    foreach ($byCategory as $name => $amount) {
        $categories[] = ['name' => $name, 'amount' => $amount];
    }

    // ตารางทั้งปีของเดือนที่เลือก
    $year = substr($month, 0, 4);
    $range = [$owner, "$year-01-01", "$year-12-31"];
    $income = $pdo->prepare("SELECT DATE_FORMAT(p.paid_at, '%Y-%m'), SUM(IF(p.kind = 'refund', -p.amount, p.amount))
                             FROM booking_payments p JOIN bookings b ON b.id = p.booking_id
                             WHERE b.user_id = ? AND p.paid_at BETWEEN ? AND ? GROUP BY 1");
    $income->execute($range);
    $incomeBy = $income->fetchAll(PDO::FETCH_KEY_PAIR);

    $payout = $pdo->prepare("SELECT DATE_FORMAT(b.appointment_date, '%Y-%m'), SUM(bs.deposit_paid)
                             FROM booking_staff bs JOIN bookings b ON b.id = bs.booking_id
                             WHERE b.user_id = ? AND b.appointment_date BETWEEN ? AND ? AND b.status <> 'cancelled' GROUP BY 1");
    $payout->execute($range);
    $payoutBy = $payout->fetchAll(PDO::FETCH_KEY_PAIR);

    $expense = $pdo->prepare("SELECT DATE_FORMAT(expense_date, '%Y-%m'), SUM(amount)
                              FROM shop_expenses WHERE user_id = ? AND expense_date BETWEEN ? AND ? GROUP BY 1");
    $expense->execute($range);
    $expenseBy = $expense->fetchAll(PDO::FETCH_KEY_PAIR);

    $months = [];
    for ($m = 1; $m <= 12; $m++) {
        $ym = sprintf('%s-%02d', $year, $m);
        $in = (float) ($incomeBy[$ym] ?? 0);
        $pay = (float) ($payoutBy[$ym] ?? 0);
        $exp = (float) ($expenseBy[$ym] ?? 0);
        $months[$ym] = ['month' => $ym, 'income' => $in, 'staff_payout' => $pay, 'expense' => $exp, 'profit' => $in - $pay - $exp];
    }

    echo json_encode([
        'month'      => $month,
        'summary'    => $months[$month],
        'rows'       => $rows,
        'categories' => $categories,
        'year'       => array_values($months),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true);
$in = is_array($in) ? $in : [];

if ($method === 'POST' && ($_GET['action'] ?? '') === 'copy_recurring') {
    $month = (string) ($in['month'] ?? '');
    if (!isValidMonth($month)) {
        http_response_code(400);
        echo json_encode(['error' => 'ต้องการ month (YYYY-MM)']);
        exit;
    }
    $target = new DateTimeImmutable($month . '-01');
    $from = $target->modify('-1 month');
    $stmt = $pdo->prepare("
        SELECT s.id, s.expense_date, s.category, s.amount, s.note
        FROM shop_expenses s
        WHERE s.user_id = ? AND s.is_recurring = 1 AND s.expense_date BETWEEN ? AND ?
          AND NOT EXISTS (SELECT 1 FROM shop_expenses c
                          WHERE c.user_id = s.user_id AND c.copied_from = s.id AND c.expense_date BETWEEN ? AND ?)
    ");
    $stmt->execute([$owner, $from->format('Y-m-01'), $from->format('Y-m-t'), $target->format('Y-m-01'), $target->format('Y-m-t')]);
    $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ins = $pdo->prepare("INSERT INTO shop_expenses (user_id, expense_date, category, amount, note, is_recurring, copied_from)
                          VALUES (?, ?, ?, ?, ?, 1, ?)");
    foreach ($sources as $s) {
        // วันเดิมของเดือน ถ้าเดือนใหม่สั้นกว่าให้ใช้วันสุดท้ายของเดือน
        $day = min((int) substr($s['expense_date'], 8, 2), (int) $target->format('t'));
        $ins->execute([$owner, $target->format('Y-m-') . sprintf('%02d', $day), $s['category'], $s['amount'], $s['note'], (int) $s['id']]);
    }
    echo json_encode(['success' => true, 'copied' => count($sources)]);
    exit;
}

if ($method === 'POST') {
    [$date, $category, $amount, $note, $recurring] = expenseInput($in);
    $pdo->prepare("INSERT INTO shop_expenses (user_id, expense_date, category, amount, note, is_recurring) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$owner, $date, $category, $amount, $note, $recurring]);
    echo json_encode(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
    exit;
}

if ($method === 'PUT') {
    $id = (int) ($in['id'] ?? 0);
    [$date, $category, $amount, $note, $recurring] = expenseInput($in);
    $check = $pdo->prepare("SELECT id FROM shop_expenses WHERE id = ? AND user_id = ?");
    $check->execute([$id, $owner]);
    if (!$check->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบรายการ']);
        exit;
    }
    $pdo->prepare("UPDATE shop_expenses SET expense_date = ?, category = ?, amount = ?, note = ?, is_recurring = ? WHERE id = ? AND user_id = ?")
        ->execute([$date, $category, $amount, $note, $recurring, $id, $owner]);
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

if ($method === 'DELETE') {
    $stmt = $pdo->prepare("DELETE FROM shop_expenses WHERE id = ? AND user_id = ?");
    $stmt->execute([(int) ($_GET['id'] ?? 0), $owner]);
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบรายการ']);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
