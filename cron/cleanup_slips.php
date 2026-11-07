<?php
/**
 * Cron: ลบไฟล์สลิปที่ไม่มีคิวไหนใช้แล้ว (คิวถูกลบ / เปลี่ยนสลิป / อัปโหลดแล้วไม่จองต่อ)
 * ควรรันวันละครั้ง:  php path/to/cron/cleanup_slips.php
 * ลองดูก่อนว่าจะลบไฟล์ไหน (ไม่ลบจริง):  php path/to/cron/cleanup_slips.php --dry-run
 * เก็บไฟล์ที่อายุไม่เกิน SLIP_KEEP_DAYS วันไว้เสมอ เผื่อกำลังกรอกฟอร์มจองอยู่
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("สคริปต์นี้รันได้จาก command line เท่านั้น\n");
}

const SLIP_KEEP_DAYS = 7;

$root = dirname(__DIR__);
require $root . '/app/booking_helpers.php';
$pdo = require $root . '/app/db.php';

$dryRun = in_array('--dry-run', $argv, true);

$used = [];
$rows = $pdo->query("SELECT slip_path FROM bookings WHERE slip_path IS NOT NULL
                     UNION SELECT slip_path FROM booking_staff WHERE slip_path IS NOT NULL");
foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $f) {
    $used[$f] = true;
}

$cutoff = time() - SLIP_KEEP_DAYS * 86400;
$removed = 0;
foreach (glob($root . '/uploads/slips/slip_*') ?: [] as $path) {
    if (isset($used[basename($path)]) || filemtime($path) > $cutoff) {
        continue;
    }
    if ($dryRun) {
        echo 'จะลบ: ' . basename($path) . "\n";
    } else {
        unlink($path);
    }
    $removed++;
}

echo ($dryRun ? 'จะลบ ' : 'ลบแล้ว ') . $removed . " ไฟล์\n";
