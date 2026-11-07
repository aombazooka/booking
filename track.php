<?php
/**
 * หน้าติดตามคิวของลูกค้า (สาธารณะ) — เข้าด้วยลิงก์ส่วนตัว track.php?c=<รหัสลับ>
 * แสดงสถานะคิว ยอดเงิน QR PromptPay และให้แนบสลิป
 */
require __DIR__ . '/app/booking_helpers.php';
require __DIR__ . '/app/promptpay.php';
require_once __DIR__ . '/app/auth.php'; // ใช้ assetVer()

header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$token = (string) ($_GET['c'] ?? '');
$booking = null;
$amountDue = 0.0;
$qrPayload = null;
$money = null;

if (preg_match('/^[a-f0-9]{20}$/', $token)) {
    $pdo = require __DIR__ . '/app/db.php';
    $stmt = $pdo->prepare("
        SELECT b.id, b.user_id, b.customer_name, b.appointment_date, b.start_time, b.end_time, b.time_slot, b.time_exact, b.price, b.deposit,
               b.travel_fee, b.payment_status, b.slip_path, b.status, b.hold_until, u.shop_name, st.name AS staff_name,
               (SELECT GROUP_CONCAT(c.name SEPARATOR ', ') FROM booking_category_pivot p
                JOIN booking_categories c ON c.id = p.category_id WHERE p.booking_id = b.id) AS category_names
        FROM bookings b
        JOIN app_users u ON u.id = b.user_id
        LEFT JOIN staff st ON st.id = b.staff_id
        WHERE b.public_token = ?
    ");
    $stmt->execute([$token]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($booking) {
    expireHolds($pdo, (int) $booking['user_id']);
    $stmt = $pdo->prepare("SELECT status, hold_until FROM bookings WHERE id = ?");
    $stmt->execute([(int) $booking['id']]);
    $booking = array_merge($booking, $stmt->fetch(PDO::FETCH_ASSOC));

    $money = bookingMoney($pdo, (int) $booking['id']);
    $open = !in_array($booking['status'], ['cancelled', 'done'], true);
    if ($open) {
        // ยังไม่จ่าย = ยอดมัดจำ, จ่ายแล้วบางส่วน = ยอดคงเหลือ (เมื่อร้านใส่ราคาแล้ว)
        if ($booking['payment_status'] === 'unpaid' && (float) $booking['deposit'] > 0) {
            $amountDue = (float) $booking['deposit'];
        } elseif ($booking['payment_status'] !== 'paid' && $money['balance'] !== null && $money['balance'] > 0) {
            $amountDue = $money['balance'];
        }
        $promptPay = getShopSettings($pdo, (int) $booking['user_id'])['promptpay_id'];
        if ($amountDue > 0 && $promptPay !== '') {
            $qrPayload = promptPayPayload($promptPay, $amountDue);
        }
    }
}

$statusText = ['new' => 'รอร้านยืนยัน', 'confirmed' => 'ยืนยันคิวแล้ว', 'done' => 'เสร็จงานแล้ว', 'cancelled' => 'ยกเลิกแล้ว'];
$h = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$baht = static fn($v) => number_format((float) $v, ((float) $v == floor((float) $v)) ? 0 : 2);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <title>ติดตามคิว</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&display=swap" rel="stylesheet">
  <link href="<?= $h($base) ?>/assets/app.css?v=<?= assetVer('assets/app.css') ?>" rel="stylesheet">
</head>
<body>
  <header class="head py-3 mb-3">
    <div class="wrap d-flex align-items-center gap-2">
      <span class="logo-badge" style="width:46px;height:46px;font-size:1.15rem;"><i class="bi bi-flower1"></i></span>
      <div>
        <div class="brand" style="font-size: 1.3rem; font-weight: 600;">ติดตามคิว</div>
        <small class="text-muted"><?= $booking ? $h($booking['shop_name']) : 'ลิงก์ส่วนตัวของลูกค้า' ?></small>
      </div>
    </div>
  </header>

  <div class="wrap pb-5 rise">
    <?php if (!$booking): ?>
    <div class="form-card p-4 text-center">
      <div style="font-size: 2.5rem;">🔎</div>
      <h2 class="h6 fw-bold mt-2">ไม่พบคิวนี้</h2>
      <p class="text-muted mb-0">โปรดเปิดจากลิงก์ที่ได้รับตอนจอง</p>
    </div>
    <?php else: ?>
    <?php if (isset($_GET['new'])): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle"></i> ส่งคำขอจองเรียบร้อย กรุณาเก็บลิงก์หน้านี้ไว้ เพื่อดูสถานะและแนบสลิป</div>
    <?php endif; ?>

    <div class="form-card p-3 p-md-4 mb-3">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <strong>รหัสจอง <?= $h(bookingRef((int) $booking['id'])) ?></strong>
        <span class="badge <?= $booking['status'] === 'cancelled' ? 'text-bg-secondary' : ($booking['status'] === 'new' ? 'text-bg-warning' : 'text-bg-success') ?>" id="statusBadge"><?= $h($statusText[$booking['status']] ?? $booking['status']) ?></span>
      </div>
      <p class="mb-1"><strong>ผู้จอง:</strong> <?= $h($booking['customer_name']) ?></p>
      <p class="mb-1"><strong>วันที่:</strong> <?= $h(date('d/m/Y', strtotime($booking['appointment_date']))) ?></p>
      <p class="mb-1"><strong>เวลา:</strong> <?= $h(bookingTimeLabel($booking)) ?></p>
      <p class="mb-1"><strong>บริการ:</strong> <?= $h($booking['category_names'] ?: '-') ?></p>
      <p class="mb-0"><strong>ช่าง:</strong> <?= $h($booking['staff_name'] ?: 'ร้านจัดให้') ?></p>
    </div>

    <div class="form-card p-3 p-md-4 mb-3">
      <div class="section-title mb-2">ยอดเงิน</div>
      <?php if ($money['total'] !== null): ?>
      <div class="d-flex justify-content-between"><span><?= $booking['status'] === 'new' ? 'ราคาประเมิน (ร้านจะยืนยันอีกครั้ง)' : 'ยอดรวม' ?></span><strong><?= $baht($money['total']) ?> บาท</strong></div>
      <div class="d-flex justify-content-between"><span>ชำระแล้ว</span><span><?= $baht($money['paid']) ?> บาท</span></div>
      <div class="d-flex justify-content-between"><span>คงเหลือ</span><strong><?= $baht($money['balance']) ?> บาท</strong></div>
      <?php else: ?>
      <div class="text-muted">ทางร้านจะแจ้งราคาอีกครั้ง</div>
      <?php endif; ?>
      <?php if ($booking['status'] === 'new' && $booking['hold_until'] && !$booking['slip_path'] && $booking['payment_status'] === 'unpaid'): ?>
      <div class="alert alert-warning mt-3 mb-0 py-2 small">กรุณาชำระมัดจำภายใน <?= $h(date('d/m/Y H:i', strtotime($booking['hold_until']))) ?> น. มิฉะนั้นคิวจะถูกปล่อยอัตโนมัติ</div>
      <?php endif; ?>
    </div>

    <?php if ($qrPayload): ?>
    <div class="form-card p-3 p-md-4 mb-3 text-center">
      <div class="section-title mb-2">สแกนจ่ายด้วย PromptPay</div>
      <div id="qrBox" class="mx-auto" style="width:220px;max-width:100%;" data-payload="<?= $h($qrPayload) ?>"></div>
      <div class="fw-semibold mt-2"><?= $baht($amountDue) ?> บาท</div>
    </div>
    <?php endif; ?>

    <?php if (!in_array($booking['status'], ['cancelled', 'done'], true) && $booking['payment_status'] !== 'paid'): ?>
    <div class="form-card p-3 p-md-4">
      <div class="section-title mb-2">แนบสลิป</div>
      <?php if ($booking['slip_path']): ?>
      <div class="text-success small mb-2"><i class="bi bi-check-circle"></i> ได้รับสลิปแล้ว รอร้านตรวจสอบ</div>
      <?php endif; ?>
      <input type="file" class="form-control soft-input" id="slipFile" accept="image/png,image/jpeg,image/webp">
      <div id="slipStatus" class="small mt-1"></div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($booking): ?>
  <?php if ($qrPayload): ?>
  <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js"></script>
  <script>
    (() => {
      const box = document.getElementById('qrBox');
      const qr = qrcode(0, 'M');
      qr.addData(box.dataset.payload);
      qr.make();
      box.innerHTML = qr.createSvgTag({ scalable: true, margin: 4 });
    })();
  </script>
  <?php endif; ?>
  <script>
    (() => {
      const input = document.getElementById('slipFile');
      if (!input) return;
      const status = document.getElementById('slipStatus');
      const API_BASE = <?= json_encode($base . '/api') ?>;
      const TOKEN = <?= json_encode($token) ?>;
      input.addEventListener('change', async function () {
        const file = this.files[0];
        if (!file) return;
        if (file.size > 5 * 1024 * 1024) { status.innerHTML = '<span class="text-danger">ไฟล์ใหญ่เกิน 5MB</span>'; this.value = ''; return; }
        status.innerHTML = '<span class="text-muted">กำลังอัปโหลด...</span>';
        try {
          const f = new FormData(); f.append('slip', file);
          const up = await fetch(API_BASE + '/upload_slip.php', { method: 'POST', body: f }).then(r => r.json());
          if (up.error || !up.file) throw new Error(up.error || 'อัปโหลดไม่สำเร็จ');
          const res = await fetch(API_BASE + '/track.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: TOKEN, slip_path: up.file }),
          }).then(r => r.json());
          if (res.error) throw new Error(res.error);
          location.reload();
        } catch (e) {
          status.textContent = e.message || 'อัปโหลดไม่สำเร็จ';
          status.className = 'small mt-1 text-danger';
        }
      });
    })();
  </script>
  <?php endif; ?>
</body>
</html>
