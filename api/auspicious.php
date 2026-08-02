<?php
/**
 * API ปฏิทินวันฤกษ์ (admin เท่านั้น)
 *   GET  ?month=YYYY-MM        → ข้อมูลรายวันของเดือนนั้น (จันทรคติ + กาลโยค + ฤกษ์ที่กรอกเอง + คิวที่มี)
 *   GET  ?stats=1&back=12      → สถิติย้อนหลัง: วันแบบไหนงานเข้าเยอะ
 *   POST {date,label,grade,source,note} → บันทึก/แก้ไขวันฤกษ์ที่กรอกเอง
 *   DELETE ?date=YYYY-MM-DD    → ลบวันฤกษ์ที่กรอกเอง
 *
 * ข้อมูลจันทรคติ/กาลโยคคำนวณสด (app/thai_lunar.php) ไม่ได้เก็บใน DB
 */
header('Content-Type: application/json; charset=utf-8');

require dirname(__DIR__) . '/app/auth.php';
require dirname(__DIR__) . '/app/thai_lunar.php';
try {
    $pdo = require dirname(__DIR__) . '/app/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'เชื่อมต่อฐานข้อมูลไม่ได้']);
    exit;
}

requireLoginApi();
$owner = ownerId();
$method = $_SERVER['REQUEST_METHOD'];

/* ---------- บันทึก/แก้ไขวันฤกษ์ ---------- */
if ($method === 'POST') {
    requireCsrf();
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $date = (string) ($in['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['error' => 'วันที่ไม่ถูกต้อง']);
        exit;
    }
    $grade = in_array($in['grade'] ?? '', ['great', 'good', 'avoid'], true) ? $in['grade'] : 'good';
    $label = trim((string) ($in['label'] ?? '')) ?: 'วันฤกษ์';
    $stmt = $pdo->prepare("
        INSERT INTO auspicious_days (user_id, date, label, grade, source, note)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE label = VALUES(label), grade = VALUES(grade),
                                source = VALUES(source), note = VALUES(note)");
    $stmt->execute([
        $owner, $date, mb_substr($label, 0, 100), $grade,
        (trim((string) ($in['source'] ?? '')) ?: null),
        (trim((string) ($in['note'] ?? '')) ?: null),
    ]);
    echo json_encode(['success' => true]);
    exit;
}

/* ---------- ลบวันฤกษ์ ---------- */
if ($method === 'DELETE') {
    requireCsrf();
    $date = (string) ($_GET['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['error' => 'วันที่ไม่ถูกต้อง']);
        exit;
    }
    $stmt = $pdo->prepare("DELETE FROM auspicious_days WHERE user_id = ? AND date = ?");
    $stmt->execute([$owner, $date]);
    echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
    exit;
}

/* ---------- สถิติย้อนหลัง: วันแบบไหนงานเข้าเยอะ ---------- */
if (isset($_GET['stats'])) {
    $back = max(1, min(60, (int) ($_GET['back'] ?? 12))); // ย้อนหลังกี่เดือน
    $start = (new DateTimeImmutable('first day of this month'))->modify("-$back month")->format('Y-m-d');
    $end   = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT appointment_date, COUNT(*) cnt, COALESCE(SUM(price),0) revenue
        FROM bookings
        WHERE user_id = ? AND appointment_date BETWEEN ? AND ? AND status <> 'cancelled'
        GROUP BY appointment_date");
    $stmt->execute([$owner, $start, $end]);
    $byDate = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byDate[$r['appointment_date']] = ['cnt' => (int) $r['cnt'], 'revenue' => (float) $r['revenue']];
    }

    // วันฤกษ์ที่ร้านกรอกเองในช่วงเดียวกัน
    $ms = $pdo->prepare("SELECT date, grade FROM auspicious_days WHERE user_id = ? AND date BETWEEN ? AND ?");
    $ms->execute([$owner, $start, $end]);
    $marks = [];
    foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $marks[$r['date']] = $r['grade'];
    }

    // เดินทุกวันในช่วง แล้วจัดกลุ่มตามชนิดของวัน
    $buckets = [];
    $addTo = function (string $key, string $label, array $b) use (&$buckets) {
        if (!isset($buckets[$key])) {
            $buckets[$key] = ['key' => $key, 'label' => $label, 'days' => 0, 'bookings' => 0, 'revenue' => 0.0];
        }
        $buckets[$key]['days']++;
        $buckets[$key]['bookings'] += $b['cnt'];
        $buckets[$key]['revenue']  += $b['revenue'];
    };

    $d = new DateTimeImmutable($start);
    $endD = new DateTimeImmutable($end);
    while ($d <= $endD) {
        $ds = $d->format('Y-m-d');
        $b = $byDate[$ds] ?? ['cnt' => 0, 'revenue' => 0.0];
        $k = tl_kalayok($ds);
        $lunar = tl_lunar_core($ds);

        foreach ($k['types'] as $t) {
            $addTo('kal_' . $t, 'วัน' . $t, $b);
        }
        if (count($k['types']) === 0) {
            $addTo('kal_none', 'วันปกติ (ไม่เข้ากาลโยค)', $b);
        }
        if ($lunar !== null && $lunar['is_holy']) {
            $addTo('holy', 'วันพระ', $b);
        }
        if (isset($marks[$ds])) {
            $addTo('mark_' . $marks[$ds], [
                'great' => 'วันมหาฤกษ์ (กรอกเอง)',
                'good'  => 'วันฤกษ์ดี (กรอกเอง)',
                'avoid' => 'วันควรเลี่ยง (กรอกเอง)',
            ][$marks[$ds]], $b);
        }
        $isWeekend = in_array((int) $d->format('w'), [0, 6], true);
        $addTo($isWeekend ? 'weekend' : 'weekday', $isWeekend ? 'เสาร์–อาทิตย์' : 'จันทร์–ศุกร์', $b);

        $d = $d->modify('+1 day');
    }

    // เฉลี่ยงานต่อวัน — ตัวเลขที่เทียบกันได้จริง เพราะแต่ละกลุ่มมีจำนวนวันไม่เท่ากัน
    $out = [];
    foreach ($buckets as $bk) {
        $bk['avg_per_day'] = $bk['days'] > 0 ? round($bk['bookings'] / $bk['days'], 2) : 0;
        $out[] = $bk;
    }
    usort($out, fn($a, $z) => $z['avg_per_day'] <=> $a['avg_per_day']);

    echo json_encode([
        'range'        => ['start' => $start, 'end' => $end],
        'months_back'  => $back,
        'total_bookings' => array_sum(array_column($byDate, 'cnt')),
        'buckets'      => $out,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- ข้อมูลรายวันของเดือน ---------- */
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    http_response_code(400);
    echo json_encode(['error' => 'ต้องการ month (YYYY-MM)']);
    exit;
}
$first = $month . '-01';
$last  = date('Y-m-t', strtotime($first));

// คิวที่มีอยู่ในเดือนนั้น (ไม่นับยกเลิก) — ใช้ดูว่าวันฤกษ์ถูกจองไปแล้วหรือยังว่าง
$bk = $pdo->prepare("
    SELECT b.appointment_date, COUNT(*) cnt, COUNT(DISTINCT b.staff_id) staff_used,
           GROUP_CONCAT(DISTINCT st.name ORDER BY st.name SEPARATOR ', ') staff_names
    FROM bookings b LEFT JOIN staff st ON st.id = b.staff_id
    WHERE b.user_id = ? AND b.appointment_date BETWEEN ? AND ? AND b.status <> 'cancelled'
    GROUP BY b.appointment_date");
$bk->execute([$owner, $first, $last]);
$bookings = [];
foreach ($bk->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $bookings[$r['appointment_date']] = [
        'cnt' => (int) $r['cnt'],
        'staff_used' => (int) $r['staff_used'],
        'staff_names' => $r['staff_names'],
    ];
}

// จำนวนช่างที่เปิดใช้งาน — ไว้เทียบว่าวันนั้นกำลังคนพอไหม
$staffTotal = (int) $pdo->query("SELECT COUNT(*) FROM staff WHERE user_id = " . (int) $owner . " AND is_active = 1")->fetchColumn();

// วันฤกษ์ที่กรอกเอง
$ms = $pdo->prepare("SELECT date, label, grade, source, note FROM auspicious_days WHERE user_id = ? AND date BETWEEN ? AND ?");
$ms->execute([$owner, $first, $last]);
$marks = [];
foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $marks[$r['date']] = $r;
}

$days = [];
$d = new DateTimeImmutable($first);
$endD = new DateTimeImmutable($last);
while ($d <= $endD) {
    $ds = $d->format('Y-m-d');
    $lunar = tl_lunar_core($ds);
    $k = tl_kalayok($ds);
    $b = $bookings[$ds] ?? null;
    $days[] = [
        'date'        => $ds,
        'dow'         => (int) $d->format('w'),
        'day'         => (int) $d->format('j'),
        'lunar'       => $lunar ? [
            'label'   => $lunar['label'],
            'phase'   => $lunar['phase'],
            'day'     => $lunar['day'],
            'month'   => $lunar['month'],
            'is_holy' => $lunar['is_holy'],
        ] : null,
        'kalayok'     => $k['types'],
        'mark'        => $marks[$ds] ?? null,
        'bookings'    => $b ? $b['cnt'] : 0,
        'staff_used'  => $b ? $b['staff_used'] : 0,
        'staff_names' => $b ? $b['staff_names'] : null,
    ];
    $d = $d->modify('+1 day');
}

$firstLunar = tl_lunar_date($first);
echo json_encode([
    'month'        => $month,
    'staff_total'  => $staffTotal,
    'lunar_year'   => $firstLunar ? [
        'zodiac'   => $firstLunar['zodiac'],
        'atikamas' => $firstLunar['atikamas'],
        'atikawan' => $firstLunar['atikawan'],
    ] : null,
    'chulasakarat' => $k['cs'],
    'days'         => $days,
], JSON_UNESCAPED_UNICODE);
