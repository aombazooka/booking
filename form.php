<?php
require __DIR__ . '/app/auth.php';
require __DIR__ . '/app/booking_helpers.php';
requireLogin();
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$apiBase = $base . '/api';
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$booking = null;
$categories = [];
$services = [];
$staffList = [];
$bookingStaff = []; // ช่างที่รับงานในคิวนี้ (ช่างหลักมาก่อน) — ใช้เติมแถวช่างในฟอร์ม
$dbError = '';
$timeExact = true; // คิวที่มีแค่แท็กช่วงเวลา: เว้นช่องเวลาว่างไว้ ไม่แสดงเวลาที่ระบบคำนวณเอง

try {
  $pdo = require __DIR__ . '/app/db.php';
  if (!$pdo instanceof PDO) {
    throw new RuntimeException('Database not configured');
  }
  $owner = ownerId();
  // โหลดรายการที่เปิดใช้งาน + รายการที่ปิดไปแล้วแต่คิวนี้ใช้อยู่ (ไม่งั้นกดบันทึกแล้วข้อมูลเดิมของคิวจะหาย)
  $q = $pdo->prepare("SELECT id, name, color_hex, count_label, duration_min, price, deposit_default FROM booking_categories
                      WHERE user_id = ? AND (is_active = 1 OR id IN (SELECT category_id FROM booking_category_pivot WHERE booking_id = ?))
                      ORDER BY sort_order, id");
  $q->execute([$owner, $editId]);
  $categories = $q->fetchAll(PDO::FETCH_ASSOC);
  $q = $pdo->prepare("
    SELECT s.id, s.name, s.price,
           (SELECT GROUP_CONCAT(l.category_id) FROM service_category_link l WHERE l.service_id = s.id) AS category_ids
    FROM booking_services s
    WHERE s.user_id = ? AND (s.is_active = 1 OR s.id IN (SELECT service_id FROM booking_service_pivot WHERE booking_id = ?))
    ORDER BY s.sort_order, s.id
  ");
  $q->execute([$owner, $editId]);
  $services = $q->fetchAll(PDO::FETCH_ASSOC);
  $q = $pdo->prepare("SELECT id, name FROM staff
                      WHERE user_id = ? AND (is_active = 1 OR id IN (SELECT staff_id FROM booking_staff WHERE booking_id = ?))
                      ORDER BY sort_order, id");
  $q->execute([$owner, $editId]);
  $staffList = $q->fetchAll(PDO::FETCH_ASSOC);

  if ($editId > 0) {
    $stmt = $pdo->prepare("
      SELECT b.id, b.customer_name, b.customer_phone, b.social, b.location, b.appointment_date, b.start_time, b.end_time, b.time_slot, b.time_exact, b.num_people,
             b.price, b.deposit, b.travel_fee, b.payment_status, b.slip_path, b.staff_id, b.status, b.note,
             (SELECT GROUP_CONCAT(p.category_id) FROM booking_category_pivot p WHERE p.booking_id = b.id) AS category_ids,
             (SELECT GROUP_CONCAT(p.service_id) FROM booking_service_pivot p WHERE p.booking_id = b.id) AS service_ids
      FROM bookings b WHERE b.id = ? AND b.user_id = ?
    ");
    $stmt->execute([$editId, $owner]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($booking) {
      $timeExact = (int) $booking['time_exact'] === 1;
      $booking['category_ids'] = $booking['category_ids'] ? array_map('intval', explode(',', $booking['category_ids'])) : [];
      $booking['service_ids'] = $booking['service_ids'] ? array_map('intval', explode(',', $booking['service_ids'])) : [];
      $ss = $pdo->prepare("SELECT staff_id, role, deposit_paid, slip_path FROM booking_staff
                           WHERE booking_id = ? ORDER BY (role = 'main') DESC, sort_order, id");
      $ss->execute([$editId]);
      $bookingStaff = $ss->fetchAll(PDO::FETCH_ASSOC);
      // คิวเก่าที่บันทึกไว้ก่อนมีตาราง booking_staff → ใช้ staff_id เดิมเป็นช่างหลัก
      if (!$bookingStaff && !empty($booking['staff_id'])) {
        $bookingStaff = [['staff_id' => (int)$booking['staff_id'], 'role' => 'main', 'deposit_paid' => null, 'slip_path' => null]];
      }
    } else {
      $booking = null;
    }
  }
} catch (Throwable $e) {
  $dbError = 'ไม่สามารถโหลดข้อมูลได้ โปรดตรวจสอบการตั้งค่าฐานข้อมูล (config.php) และให้แน่ใจว่านำเข้า schema แล้ว';
  $categories = [];
  $services = [];
  $staffList = [];
  $booking = $editId > 0 ? null : null;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= $editId ? 'แก้ไขคิวงาน' : 'เพิ่มคิวงาน' ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&display=swap" rel="stylesheet">
  <link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
  <link href="<?= htmlspecialchars($base) ?>/assets/app.css?v=<?= assetVer('assets/app.css') ?>" rel="stylesheet">
</head>
<body>
  <header class="head py-3 mb-3">
    <div class="wrap d-flex justify-content-between align-items-center">
      <div>
        <div class="eyebrow"><?= $editId ? 'แก้ไขคิว' : 'เพิ่มคิวใหม่' ?></div>
        <div class="brand" style="font-size: 1.25rem; font-weight: 600;"><?= $editId ? 'แก้ไขคิวงาน' : 'เพิ่มคิวงาน' ?></div>
      </div>
      <a class="icon-btn btn-back" href="<?= htmlspecialchars($base) ?>/" title="กลับปฏิทิน"><i class="bi bi-calendar3"></i></a>
    </div>
  </header>

  <div class="wrap pb-4 rise">
    <?php if ($dbError): ?>
    <div class="alert alert-warning mb-3" role="alert"><?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>
    <div class="form-card p-3 p-md-4">
      <form id="bookingForm">
        <?php if ($editId): ?><input type="hidden" name="id" value="<?= $editId ?>"><?php endif; ?>
        <div class="mb-3">
          <label class="form-label section-title">ข้อมูลลูกค้า</label>
          <input type="text" class="form-control soft-input mb-2" name="customer_name" required placeholder="ชื่อ-นามสกุล" value="<?= $booking ? htmlspecialchars($booking['customer_name'] ?? '') : '' ?>">
          <input type="tel" class="form-control soft-input mb-2" name="customer_phone" required placeholder="เบอร์โทรศัพท์" value="<?= $booking ? htmlspecialchars($booking['customer_phone'] ?? '') : '' ?>">
          <input type="text" class="form-control soft-input" name="social" maxlength="500" placeholder="โซเชียล: ใส่ชื่อ หรือวางลิงก์ Facebook / LINE / IG" aria-label="ช่องทางโซเชียล" value="<?= $booking ? htmlspecialchars($booking['social'] ?? '') : '' ?>">
        </div>
        <div class="mb-3">
          <label class="form-label section-title">สถานที่ <span class="title-hint">พิมพ์ที่อยู่ หรือปักหมุดบนแผนที่</span></label>
          <input type="text" class="form-control soft-input" name="location" id="locationInput" placeholder="ที่อยู่ หรือ Google Maps Link" value="<?= $booking ? htmlspecialchars($booking['location'] ?? '') : '' ?>">
          <div class="d-flex flex-wrap gap-2 mt-2">
            <button type="button" class="quick-btn" id="toggleMap"><i class="bi bi-geo-alt"></i> ปักหมุดบนแผนที่</button>
            <button type="button" class="quick-btn" id="useMyLoc"><i class="bi bi-crosshair2"></i> ตำแหน่งปัจจุบัน</button>
          </div>
          <div id="mapBox" class="mt-2 d-none" style="height:240px;border-radius:14px;overflow:hidden;border:1px solid var(--line);"></div>
        </div>

        <div class="mb-3">
          <label class="form-label section-title">ประเภทงาน <span class="title-hint">แตะเพื่อเลือก (เลือกได้หลายอย่าง)</span></label>
          <div class="select-grid" id="categoryList">
            <?php
            $catIds = $booking ? ($booking['category_ids'] ?? []) : [];
            foreach ($categories as $c):
              $checked = in_array((int)$c['id'], $catIds, true);
            ?>
            <label class="select-card<?= $checked ? ' selected' : '' ?>" style="--accent: <?= htmlspecialchars($c['color_hex']) ?>">
              <input class="cat-check" type="checkbox" name="category_ids[]" value="<?= (int)$c['id'] ?>" data-count-label="<?= htmlspecialchars($c['count_label'] ?? '') ?>" data-duration="<?= (int)($c['duration_min'] ?? 0) ?>" data-price="<?= htmlspecialchars($c['price'] ?? '') ?>" data-deposit="<?= htmlspecialchars($c['deposit_default'] ?? '') ?>"<?= $checked ? ' checked' : '' ?>>
              <span class="sc-check"><i class="bi bi-check-lg"></i></span>
              <span class="sc-name"><span class="sc-dot" style="background: <?= htmlspecialchars($c['color_hex']) ?>"></span><?= htmlspecialchars($c['name']) ?></span>
              <?php if ($c['price'] !== null && $c['price'] !== ''): ?><span class="sc-price"><?= number_format((float)$c['price']) ?> บาท</span><?php endif; ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="mb-3" id="serviceBlock">
          <label class="form-label section-title">บริการเสริม <span class="title-hint">เลือกได้หลายอย่าง</span></label>
          <div class="select-grid" id="serviceList">
            <?php
            $svcIds = $booking ? ($booking['service_ids'] ?? []) : [];
            foreach ($services as $s):
              $checked = in_array((int)$s['id'], $svcIds, true);
            ?>
            <label class="select-card sc-plain<?= $checked ? ' selected' : '' ?>" data-cat="<?= htmlspecialchars($s['category_ids'] ?? '') ?>">
              <input type="checkbox" name="service_ids[]" value="<?= (int)$s['id'] ?>"<?= $checked ? ' checked' : '' ?>>
              <span class="sc-check"><i class="bi bi-check-lg"></i></span>
              <span class="sc-name"><?= htmlspecialchars($s['name']) ?></span>
              <?php if ($s['price'] !== null && $s['price'] !== ''): ?><span class="sc-price"><?= number_format((float)$s['price']) ?> บาท</span><?php endif; ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="row">
          <div class="col-6 mb-3">
            <label class="form-label section-title">สถานะงาน</label>
            <select name="status" class="form-select soft-input">
              <?php
              $st = $booking['status'] ?? 'new';
              foreach (['new' => 'ใหม่', 'confirmed' => 'ยืนยันแล้ว', 'done' => 'เสร็จงาน', 'cancelled' => 'ยกเลิก'] as $v => $l):
              ?><option value="<?= $v ?>"<?= $st === $v ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- ช่างที่รับงาน: แถวแรก = ช่างหลัก, กด + เพิ่มช่างเสริมได้ไม่จำกัด
             มัดจำ/สลิปในบล็อกนี้ = เงินที่ "จ่ายออก" ให้ช่าง คนละก้อนกับมัดจำที่ลูกค้าโอนเข้ามาด้านล่าง -->
        <div class="mb-3">
          <label class="form-label section-title">ช่างที่รับงาน <span class="title-hint">คนแรก = ช่างหลัก • กดปุ่ม + เพื่อเพิ่มช่างอื่น</span></label>
          <div id="staffRows"></div>
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
            <button type="button" class="quick-btn" id="staffAdd"><i class="bi bi-plus-lg"></i> เพิ่มช่าง</button>
            <div class="small text-muted" id="staffTotal"></div>
          </div>
        </div>

        <div class="row">
          <div class="col-6 col-md-3 mb-3">
            <label class="form-label section-title">ราคา (บาท)</label>
            <input type="number" step="0.01" min="0" class="form-control soft-input" name="price" placeholder="-" value="<?= $booking && $booking['price'] !== null ? htmlspecialchars($booking['price']) : '' ?>">
          </div>
          <div class="col-6 col-md-3 mb-3">
            <label class="form-label section-title">มัดจำจากลูกค้า (บาท)</label>
            <input type="number" step="0.01" min="0" class="form-control soft-input" name="deposit" placeholder="0" value="<?= $booking && isset($booking['deposit']) ? htmlspecialchars($booking['deposit']) : '' ?>">
          </div>
          <div class="col-6 col-md-3 mb-3">
            <label class="form-label section-title">ค่าเดินทาง (บาท)</label>
            <input type="number" step="0.01" min="0" class="form-control soft-input" name="travel_fee" placeholder="-" value="<?= $booking && isset($booking['travel_fee']) && $booking['travel_fee'] !== null ? htmlspecialchars($booking['travel_fee']) : '' ?>">
          </div>
          <?php if (!$editId): ?>
          <div class="col-6 col-md-3 mb-3">
            <label class="form-label section-title">การจ่าย</label>
            <select name="payment_status" class="form-select soft-input">
              <?php
              $ps = $booking['payment_status'] ?? 'unpaid';
              foreach (['unpaid' => 'ยังไม่จ่าย', 'deposit_paid' => 'จ่ายมัดจำ', 'paid' => 'จ่ายครบ'] as $v => $l):
              ?><option value="<?= $v ?>"<?= $ps === $v ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($editId): ?>
        <!-- รับเงินรายรายการ: สถานะการจ่ายคำนวณจากรายการเหล่านี้ และบันทึกทันทีไม่ต้องกดบันทึกคิว -->
        <div class="mb-3" id="payBlock">
          <label class="form-label section-title">การรับเงิน</label>
          <div id="paySummary" class="small mb-2"></div>
          <div id="payRows" class="small mb-2"></div>
          <div class="row g-2">
            <div class="col-6 col-md-3">
              <select id="payKind" class="form-select soft-input" aria-label="ประเภทรายการ">
                <option value="deposit">มัดจำ</option>
                <option value="balance">ส่วนที่เหลือ</option>
                <option value="refund">คืนเงิน</option>
              </select>
            </div>
            <div class="col-6 col-md-3"><input type="number" step="0.01" min="0" id="payAmount" class="form-control soft-input" placeholder="จำนวนเงิน" aria-label="จำนวนเงิน"></div>
            <div class="col-6 col-md-2">
              <select id="payMethod" class="form-select soft-input" aria-label="ช่องทาง">
                <option value="transfer">โอน</option>
                <option value="cash">เงินสด</option>
                <option value="other">อื่นๆ</option>
              </select>
            </div>
            <div class="col-6 col-md-2"><input type="date" id="payDate" class="form-control soft-input" value="<?= date('Y-m-d') ?>" aria-label="วันที่รับเงิน"></div>
            <div class="col-12 col-md-2 d-grid"><button type="button" class="quick-btn" id="payAdd"><i class="bi bi-plus-lg"></i> เพิ่ม</button></div>
          </div>
        </div>
        <?php endif; ?>

        <div class="mb-3">
          <label class="form-label section-title">สลิปมัดจำจากลูกค้า</label>
          <?php $curSlip = $booking['slip_path'] ?? ''; ?>
          <input type="hidden" name="slip_path" id="slipPath" value="<?= htmlspecialchars($curSlip) ?>">
          <div id="slipCurrent" class="mb-2<?= $curSlip ? '' : ' d-none' ?>">
            <a id="slipViewLink" href="<?= htmlspecialchars($base) ?>/api/slip.php?file=<?= urlencode($curSlip) ?>" target="_blank" class="btn btn-sm btn-light border"><i class="bi bi-receipt"></i> ดูสลิป</a>
            <button type="button" class="btn btn-sm btn-light border text-danger" id="slipRemove"><i class="bi bi-x-lg"></i> ลบสลิป</button>
          </div>
          <input type="file" class="form-control soft-input" id="slipFile" accept="image/png,image/jpeg,image/webp">
          <div id="slipStatus" class="small mt-1"></div>
        </div>

        <div class="row">
          <div class="col-12 mb-3">
            <label class="form-label section-title">วันที่นัดหมาย</label>
            <input type="date" class="form-control soft-input" name="appointment_date" required value="<?= $booking ? htmlspecialchars($booking['appointment_date'] ?? '') : '' ?>">
          </div>
          <div class="col-6 mb-3">
            <label class="form-label section-title">เวลาเริ่ม <span class="title-hint">ไม่บังคับ</span></label>
            <input type="time" class="form-control soft-input" name="start_time" value="<?= $timeExact && $booking && !empty($booking['start_time']) ? date('H:i', strtotime($booking['start_time'])) : '' ?>">
          </div>
          <div class="col-6 mb-3">
            <label class="form-label section-title">เวลาจบ <span class="title-hint">ไม่บังคับ</span></label>
            <input type="time" class="form-control soft-input" name="end_time" value="<?= $timeExact && $booking && !empty($booking['end_time']) ? date('H:i', strtotime($booking['end_time'])) : '' ?>">
          </div>
        </div>

        <div class="mb-3">
          <div class="section-title">ช่วงเวลา <span class="title-hint">ถ้าไม่ใส่เวลา ระบบใช้ช่วงนี้ตรวจคิวชน</span></div>
          <input type="hidden" name="time_slot" id="timeSlot" value="<?= htmlspecialchars($booking['time_slot'] ?? '') ?>">
          <div class="d-flex flex-wrap gap-2" id="timeSlotBtns">
            <?php foreach (TIME_SLOTS as $key => $slot): ?>
            <button type="button" class="quick-btn<?= ($booking['time_slot'] ?? '') === $key ? ' slot-on' : '' ?>" data-slot="<?= $key ?>" aria-pressed="<?= ($booking['time_slot'] ?? '') === $key ? 'true' : 'false' ?>"><?= htmlspecialchars($slot['label']) ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="mb-3">
          <div class="section-title">ตั้งเวลาเร็ว</div>
          <div class="d-flex flex-wrap gap-2 mb-2">
            <button class="quick-btn" type="button" data-start="06:00">เช้า 06:00</button>
            <button class="quick-btn" type="button" data-start="09:00">สาย 09:00</button>
            <button class="quick-btn" type="button" data-start="13:00">บ่าย 13:00</button>
            <button class="quick-btn" type="button" data-start="17:00">เย็น 17:00</button>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <button class="quick-btn" type="button" data-duration="60">+60 นาที</button>
            <button class="quick-btn" type="button" data-duration="90">+90 นาที</button>
            <button class="quick-btn" type="button" data-duration="120">+120 นาที</button>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label section-title" id="countLabel">จำนวน</label>
          <input type="number" class="form-control soft-input" name="num_people" value="<?= $booking ? (int)($booking['num_people'] ?? 1) : 1 ?>" min="1" max="99">
        </div>

        <div class="mb-4">
          <label class="form-label section-title">หมายเหตุ</label>
          <textarea class="form-control soft-input" name="note" rows="2" placeholder="เช่น นัดลองแต่งก่อนวันงาน"><?= $booking ? htmlspecialchars($booking['note'] ?? '') : '' ?></textarea>
        </div>

        <div class="sticky-action">
          <div class="d-grid gap-2">
            <button type="submit" class="btn save-btn"><?= $editId ? 'บันทึกการแก้ไข' : 'บันทึกการจอง' ?></button>
            <a href="<?= htmlspecialchars($base) ?>/" class="btn btn-light border">กลับหน้าปฏิทิน</a>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="toast" class="toast" role="alert">
      <div class="toast-body" id="toastBody"></div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const API_BASE = <?= json_encode($apiBase) ?>;
    const base = <?= json_encode($base) ?>;
    const CSRF = <?= json_encode(csrfToken()) ?>;
    const STAFF_OPTIONS = <?= json_encode($staffList, JSON_UNESCAPED_UNICODE) ?>;
    const STAFF_INIT = <?= json_encode($bookingStaff, JSON_UNESCAPED_UNICODE) ?>;

    document.querySelectorAll('[data-start]').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelector('[name="start_time"]').value = btn.dataset.start;
      });
    });
    document.querySelectorAll('[data-duration]').forEach(btn => {
      btn.addEventListener('click', () => {
        const start = document.querySelector('[name="start_time"]').value;
        if (!start) {
          showToast('กรุณาเลือกเวลาเริ่มก่อน', 'danger');
          return;
        }
        const addMin = parseInt(btn.dataset.duration, 10);
        const parts = start.split(':');
        const t = new Date();
        t.setHours(parseInt(parts[0], 10), parseInt(parts[1], 10), 0, 0);
        t.setMinutes(t.getMinutes() + addMin);
        const hh = String(t.getHours()).padStart(2, '0');
        const mm = String(t.getMinutes()).padStart(2, '0');
        document.querySelector('[name="end_time"]').value = hh + ':' + mm;
      });
    });

    // แท็กช่วงเวลา: เลือกได้ทีละแท็ก กดซ้ำเพื่อเอาออก
    document.querySelectorAll('#timeSlotBtns [data-slot]').forEach(btn => {
      btn.addEventListener('click', () => {
        const turnOn = !btn.classList.contains('slot-on');
        document.querySelectorAll('#timeSlotBtns [data-slot]').forEach(b => {
          b.classList.remove('slot-on');
          b.setAttribute('aria-pressed', 'false');
        });
        btn.classList.toggle('slot-on', turnOn);
        btn.setAttribute('aria-pressed', turnOn ? 'true' : 'false');
        document.getElementById('timeSlot').value = turnOn ? btn.dataset.slot : '';
      });
    });

    // ป้ายช่องจำนวน + เวลาจบอัตโนมัติ ตามประเภทงานที่เลือก
    function onCatChange() {
      const checked = Array.from(document.querySelectorAll('.cat-check:checked'));
      const withLabel = checked.find(cb => cb.dataset.countLabel);
      document.getElementById('countLabel').textContent = withLabel ? withLabel.dataset.countLabel : 'จำนวน';
      updateServiceVisibility();
    }

    // แสดงเฉพาะบริการที่อยู่ใต้ประเภทที่เลือก (หรือบริการทั่วไปที่ไม่ผูกประเภท)
    function updateServiceVisibility() {
      const selIds = Array.from(document.querySelectorAll('.cat-check:checked')).map(cb => Number(cb.value));
      let visible = 0;
      document.querySelectorAll('#serviceList .select-card').forEach(card => {
        const raw = (card.dataset.cat || '').trim();
        const cats = raw ? raw.split(',').map(Number) : [];
        const show = cats.length === 0 || cats.some(id => selIds.includes(id));
        card.style.display = show ? '' : 'none';
        if (!show) { const cb = card.querySelector('input'); if (cb.checked) { cb.checked = false; card.classList.remove('selected'); } }
        if (show) visible++;
      });
      const block = document.getElementById('serviceBlock');
      if (block) block.style.display = visible > 0 ? '' : 'none';
    }
    // ไฮไลต์การ์ดที่ถูกเลือก (ทั้งประเภทงานและบริการ)
    document.querySelectorAll('.select-card input').forEach(cb => {
      cb.addEventListener('change', () => cb.closest('.select-card').classList.toggle('selected', cb.checked));
    });
    document.querySelectorAll('.cat-check').forEach(cb => cb.addEventListener('change', onCatChange));
    onCatChange();

    // ---------- ช่างที่รับงาน: ช่างหลัก + ช่างเสริม (มัดจำจ่ายช่าง/สลิป รายคน) ----------
    let collectStaff = () => [];
    (function staffPicker() {
      const wrap = document.getElementById('staffRows');
      const addBtn = document.getElementById('staffAdd');
      const totalEl = document.getElementById('staffTotal');
      const esc = s => String(s === null || s === undefined ? '' : s)
        .replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
      const money = n => Number(n || 0).toLocaleString('th-TH', { maximumFractionDigits: 2 });

      function optionsHtml(selected) {
        return '<option value="">— เลือกช่าง —</option>' + STAFF_OPTIONS.map(s =>
          '<option value="' + s.id + '"' + (Number(selected) === Number(s.id) ? ' selected' : '') + '>' + esc(s.name) + '</option>'
        ).join('');
      }

      function addRow(data) {
        data = data || {};
        const row = document.createElement('div');
        row.className = 'staff-row';
        row.dataset.slip = data.slip_path || '';
        row.innerHTML =
          '<div class="staff-row-top">' +
            '<span class="staff-tag"></span>' +
            '<button type="button" class="staff-remove" title="ลบช่างคนนี้"><i class="bi bi-trash"></i></button>' +
          '</div>' +
          '<div class="row g-2">' +
            '<div class="col-12 col-md-5"><select class="form-select soft-input staff-select">' + optionsHtml(data.staff_id) + '</select></div>' +
            '<div class="col-7 col-md-4"><input type="number" step="0.01" min="0" class="form-control soft-input staff-deposit" placeholder="มัดจำจ่ายช่าง (บาท)" value="' + esc(data.deposit_paid === null ? '' : data.deposit_paid) + '"></div>' +
            '<div class="col-5 col-md-3">' +
              '<label class="quick-btn staff-slip-btn w-100"><i class="bi bi-paperclip"></i> เพิ่มสลิป' +
                '<input type="file" class="d-none staff-slip-file" accept="image/png,image/jpeg,image/webp">' +
              '</label>' +
            '</div>' +
          '</div>' +
          '<div class="staff-slip-state small mt-1"></div>';
        wrap.appendChild(row);
        bindRow(row);
        paintSlip(row);
        renumber();
        return row;
      }

      // แสดงสถานะสลิปของแถวนี้: มีไฟล์แล้ว = ปุ่มดู/ลบ, ยังไม่มี = ว่าง
      function paintSlip(row, msg) {
        const state = row.querySelector('.staff-slip-state');
        const file = row.dataset.slip;
        if (msg) { state.innerHTML = msg; return; }
        state.innerHTML = file
          ? '<a href="' + base + '/api/slip.php?file=' + encodeURIComponent(file) + '" target="_blank" rel="noopener" class="btn btn-sm btn-light border"><i class="bi bi-receipt"></i> ดูสลิป</a>' +
            ' <button type="button" class="btn btn-sm btn-light border text-danger staff-slip-remove"><i class="bi bi-x-lg"></i> ลบสลิป</button>'
          : '';
      }

      function bindRow(row) {
        row.querySelector('.staff-remove').addEventListener('click', () => { row.remove(); renumber(); });
        row.querySelector('.staff-deposit').addEventListener('input', renumber);
        row.querySelector('.staff-slip-file').addEventListener('change', function () {
          const file = this.files[0];
          if (!file) return;
          if (file.size > 5 * 1024 * 1024) { paintSlip(row, '<span class="text-danger">ไฟล์ใหญ่เกิน 5MB</span>'); this.value = ''; return; }
          paintSlip(row, '<span class="text-muted">กำลังอัปโหลด...</span>');
          const f = new FormData(); f.append('slip', file);
          fetch(API_BASE + '/upload_slip.php', { method: 'POST', body: f })
            .then(r => r.json())
            .then(d => {
              if (d.error || !d.file) { paintSlip(row, '<span class="text-danger">' + esc(d.error || 'อัปโหลดไม่สำเร็จ') + '</span>'); return; }
              row.dataset.slip = d.file;
              paintSlip(row);
            })
            .catch(() => paintSlip(row, '<span class="text-danger">อัปโหลดไม่สำเร็จ</span>'));
          this.value = '';
        });
        // ปุ่มลบสลิปถูกสร้างทีหลัง → ใช้ event delegation ที่ตัวแถว
        row.addEventListener('click', e => {
          if (!e.target.closest('.staff-slip-remove')) return;
          row.dataset.slip = '';
          paintSlip(row, '<span class="text-muted">นำสลิปออกแล้ว (กดบันทึกเพื่อยืนยัน)</span>');
        });
      }

      // แถวแรกเป็นช่างหลักเสมอ (ลบไม่ได้) + รวมยอดมัดจำจ่ายช่าง
      function renumber() {
        const rows = Array.from(wrap.querySelectorAll('.staff-row'));
        if (rows.length === 0) { addRow({}); return; }
        let total = 0;
        rows.forEach((row, i) => {
          row.classList.toggle('is-main', i === 0);
          row.querySelector('.staff-tag').innerHTML = i === 0
            ? '<i class="bi bi-star-fill"></i> ช่างหลัก'
            : 'ช่างเสริม';
          row.querySelector('.staff-remove').style.display = i === 0 ? 'none' : '';
          total += parseFloat(row.querySelector('.staff-deposit').value) || 0;
        });
        totalEl.textContent = total > 0 ? 'รวมมัดจำจ่ายช่าง ' + money(total) + ' บาท' : '';
      }

      addBtn.addEventListener('click', () => {
        if (wrap.querySelectorAll('.staff-row').length >= 20) { showToast('เพิ่มช่างได้สูงสุด 20 คนต่อคิว', 'danger'); return; }
        addRow({});
      });

      collectStaff = () => Array.from(wrap.querySelectorAll('.staff-row')).map(row => ({
        staff_id: row.querySelector('.staff-select').value,
        deposit_paid: row.querySelector('.staff-deposit').value,
        slip_path: row.dataset.slip || '',
      })).filter(r => r.staff_id);

      (STAFF_INIT.length ? STAFF_INIT : [{}]).forEach(addRow);
    })();

    function buildPayload(force) {
      const form = document.getElementById('bookingForm');
      const fd = new FormData(form);
      const categoryIds = Array.from(form.querySelectorAll('input[name="category_ids[]"]:checked')).map(cb => cb.value);
      const serviceIds = Array.from(form.querySelectorAll('input[name="service_ids[]"]:checked')).map(cb => cb.value);
      const id = fd.get('id') ? parseInt(fd.get('id'), 10) : 0;
      const payload = {
        customer_name: fd.get('customer_name'),
        customer_phone: fd.get('customer_phone'),
        social: fd.get('social') || '',
        location: fd.get('location') || '',
        appointment_date: fd.get('appointment_date'),
        start_time: fd.get('start_time'),
        end_time: fd.get('end_time'),
        time_slot: fd.get('time_slot') || '',
        num_people: parseInt(fd.get('num_people') || 1, 10) || 1,
        price: fd.get('price') || '',
        deposit: fd.get('deposit') || '',
        travel_fee: fd.get('travel_fee') || '',
        payment_status: fd.get('payment_status') || 'unpaid',
        staff: collectStaff(), // ลำดับแรก = ช่างหลัก (server เอาไปลง bookings.staff_id ให้)
        slip_path: fd.get('slip_path') || '',
        status: fd.get('status') || 'new',
        note: fd.get('note') || '',
        category_ids: categoryIds,
        service_ids: serviceIds,
      };
      if (id > 0) payload.id = id;
      if (force) payload.force = 1;
      return { payload, id };
    }

    function submitBooking(force) {
      const { payload, id } = buildPayload(force);
      if (payload.category_ids.length === 0) {
        showToast('กรุณาเลือกประเภทงานอย่างน้อย 1 ข้อ', 'danger');
        return;
      }
      const staffIds = payload.staff.map(s => String(s.staff_id));
      if (new Set(staffIds).size !== staffIds.length) {
        showToast('มีช่างซ้ำกัน กรุณาเลือกช่างคนละคนในแต่ละแถว', 'danger');
        return;
      }
      const method = id > 0 ? 'PUT' : 'POST';
      fetch(API_BASE + '/bookings.php', {
        method, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(payload),
      })
        .then(r => r.json().then(data => ({ status: r.status, data })))
        .then(({ status, data }) => {
          if (status === 409 && data.conflict) {
            let msg = 'ช่วงเวลานี้ชนกับคิวอื่น';
            if (Array.isArray(data.conflicts)) {
              msg += ':\n' + data.conflicts.map(c => '• ' + c.time_label + ' ' + c.customer_name).join('\n');
            }
            if (confirm(msg + '\n\nต้องการบันทึกทับคิวนี้หรือไม่?')) submitBooking(true);
            return;
          }
          if (data.error) { showToast(data.error, 'danger'); return; }
          showToast(id > 0 ? 'แก้ไขเรียบร้อย' : 'บันทึกการจองเรียบร้อย', 'success');
          setTimeout(() => { window.location.href = base + '/'; }, 1000);
        })
        .catch(() => showToast('เกิดข้อผิดพลาด', 'danger'));
    }

    document.getElementById('bookingForm').addEventListener('submit', function(e) {
      e.preventDefault();
      submitBooking(false);
    });

    // อัปโหลด/ดู/ลบ สลิปมัดจำ
    (function slipUpload() {
      const fileEl = document.getElementById('slipFile');
      const pathEl = document.getElementById('slipPath');
      const status = document.getElementById('slipStatus');
      const cur = document.getElementById('slipCurrent');
      const viewLink = document.getElementById('slipViewLink');
      fileEl.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;
        if (file.size > 5 * 1024 * 1024) { status.innerHTML = '<span class="text-danger">ไฟล์ใหญ่เกิน 5MB</span>'; this.value = ''; return; }
        status.innerHTML = '<span class="text-muted">กำลังอัปโหลด...</span>';
        const f = new FormData(); f.append('slip', file);
        fetch(API_BASE + '/upload_slip.php', { method: 'POST', body: f })
          .then(r => r.json())
          .then(d => {
            if (d.error || !d.file) { status.innerHTML = '<span class="text-danger">' + (d.error || 'อัปโหลดไม่สำเร็จ') + '</span>'; return; }
            pathEl.value = d.file;
            if (viewLink) viewLink.href = base + '/api/slip.php?file=' + encodeURIComponent(d.file);
            cur.classList.remove('d-none');
            status.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> แนบสลิปใหม่แล้ว</span>';
          })
          .catch(() => { status.innerHTML = '<span class="text-danger">อัปโหลดไม่สำเร็จ</span>'; });
      });
      const removeBtn = document.getElementById('slipRemove');
      if (removeBtn) removeBtn.addEventListener('click', () => {
        pathEl.value = ''; cur.classList.add('d-none'); fileEl.value = '';
        status.innerHTML = '<span class="text-muted">นำสลิปออกแล้ว (กดบันทึกเพื่อยืนยัน)</span>';
      });
    })();

    // รับเงินรายรายการ (เฉพาะตอนแก้ไขคิว)
    (function payments() {
      if (!document.getElementById('payBlock')) return;
      const bookingId = <?= (int) $editId ?>;
      const KIND = { deposit: 'มัดจำ', balance: 'ส่วนที่เหลือ', refund: 'คืนเงิน' };
      const METHOD = { transfer: 'โอน', cash: 'เงินสด', other: 'อื่นๆ' };
      const baht = n => Number(n || 0).toLocaleString('th-TH', { maximumFractionDigits: 2 });
      const esc = s => String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

      function render(data) {
        const s = data.summary;
        document.getElementById('paySummary').innerHTML = s.total === null
          ? 'รับแล้ว <strong>' + baht(s.paid) + '</strong> บาท · ยังไม่ใส่ราคาคิวนี้'
          : 'ยอดรวม ' + baht(s.total) + ' · รับแล้ว <strong>' + baht(s.paid) + '</strong> · คงเหลือ <strong>' + baht(s.balance) + '</strong> บาท';
        document.getElementById('payRows').innerHTML = data.rows.map(r =>
          '<div class="d-flex justify-content-between align-items-center border-top py-1">' +
          '<span>' + esc(r.paid_at) + ' · ' + esc(KIND[r.kind]) + ' · ' + esc(METHOD[r.method]) + (r.note ? ' · ' + esc(r.note) : '') + '</span>' +
          '<span>' + (r.kind === 'refund' ? '-' : '') + baht(r.amount) + ' บาท ' +
          '<button type="button" class="btn btn-sm btn-light border text-danger" data-pay-del="' + Number(r.id) + '" aria-label="ลบรายการ"><i class="bi bi-x-lg"></i></button></span></div>'
        ).join('');
        document.querySelectorAll('[data-pay-del]').forEach(b => b.addEventListener('click', () => removePayment(b.dataset.payDel)));
      }

      const load = () => fetch(API_BASE + '/payments.php?booking_id=' + bookingId).then(r => r.json()).then(render);

      function removePayment(id) {
        if (!confirm('ลบรายการรับเงินนี้?')) return;
        fetch(API_BASE + '/payments.php?id=' + id, { method: 'DELETE', headers: { 'X-CSRF-Token': CSRF } })
          .then(r => r.json()).then(d => { if (d.error) showToast(d.error, 'danger'); else load(); });
      }

      document.getElementById('payAdd').addEventListener('click', () => {
        const amountEl = document.getElementById('payAmount');
        fetch(API_BASE + '/payments.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
          body: JSON.stringify({
            booking_id: bookingId, kind: document.getElementById('payKind').value, amount: amountEl.value,
            method: document.getElementById('payMethod').value, paid_at: document.getElementById('payDate').value,
          }),
        }).then(r => r.json()).then(d => {
          if (d.error) { showToast(d.error, 'danger'); return; }
          amountEl.value = '';
          load();
        });
      });
      load();
    })();

    // autocomplete ลูกค้าจากชื่อ/เบอร์ที่เคยจอง
    (function customerAutocomplete() {
      const nameEl = document.querySelector('[name="customer_name"]');
      const phoneEl = document.querySelector('[name="customer_phone"]');
      const dl = document.createElement('datalist');
      dl.id = 'customerSuggest';
      document.body.appendChild(dl);
      nameEl.setAttribute('list', 'customerSuggest');
      let timer = null;
      const lookup = (q) => {
        if (!q || q.length < 2) { dl.innerHTML = ''; return; }
        fetch(API_BASE + '/customers.php?q=' + encodeURIComponent(q))
          .then(r => r.json())
          .then(rows => {
            if (!Array.isArray(rows)) return;
            dl.innerHTML = rows.map(c => '<option value="' + c.name.replace(/"/g,'&quot;') + '">' + c.phone + '</option>').join('');
            dl._rows = rows;
          }).catch(() => {});
      };
      nameEl.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => lookup(nameEl.value.trim()), 250); });
      // เมื่อเลือกชื่อที่ตรงกับลูกค้าเดิม → เติมเบอร์ให้
      nameEl.addEventListener('change', () => {
        const rows = dl._rows || [];
        const m = rows.find(c => c.name === nameEl.value);
        if (m && !phoneEl.value) phoneEl.value = m.phone;
      });
    })();

    function showToast(msg, type) {
      const toastEl = document.getElementById('toast');
      const body = document.getElementById('toastBody');
      body.textContent = msg;
      const bg = type === 'danger' ? 'text-bg-danger'
        : (type === 'success' ? 'text-bg-success' : 'text-bg-secondary');
      toastEl.className = 'toast show ' + bg;
      const toast = new bootstrap.Toast(toastEl);
      toast.show();
    }
  </script>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="<?= htmlspecialchars($base) ?>/assets/mappicker.js"></script>
</body>
</html>
