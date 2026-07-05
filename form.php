<?php
require __DIR__ . '/app/auth.php';
requireLogin();
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$apiBase = $base . '/api';
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$booking = null;
$categories = [];
$services = [];
$dbError = '';

try {
  $pdo = require __DIR__ . '/app/db.php';
  if (!$pdo instanceof PDO) {
    throw new RuntimeException('Database not configured');
  }
  $owner = ownerId();
  $q = $pdo->prepare("SELECT id, name, color_hex, count_label, duration_min, price, deposit_default FROM booking_categories WHERE user_id = ? AND is_active = 1 ORDER BY sort_order, id");
  $q->execute([$owner]);
  $categories = $q->fetchAll(PDO::FETCH_ASSOC);
  $q = $pdo->prepare("
    SELECT s.id, s.name, s.price,
           (SELECT GROUP_CONCAT(l.category_id) FROM service_category_link l WHERE l.service_id = s.id) AS category_ids
    FROM booking_services s WHERE s.user_id = ? AND s.is_active = 1 ORDER BY s.sort_order, s.id
  ");
  $q->execute([$owner]);
  $services = $q->fetchAll(PDO::FETCH_ASSOC);
  $q = $pdo->prepare("SELECT id, name FROM staff WHERE user_id = ? AND is_active = 1 ORDER BY sort_order, id");
  $q->execute([$owner]);
  $staffList = $q->fetchAll(PDO::FETCH_ASSOC);

  if ($editId > 0) {
    $stmt = $pdo->prepare("
      SELECT b.id, b.customer_name, b.customer_phone, b.location, b.appointment_date, b.start_time, b.end_time, b.num_people,
             b.price, b.deposit, b.payment_status, b.slip_path, b.staff_id, b.status, b.note,
             (SELECT GROUP_CONCAT(p.category_id) FROM booking_category_pivot p WHERE p.booking_id = b.id) AS category_ids,
             (SELECT GROUP_CONCAT(p.service_id) FROM booking_service_pivot p WHERE p.booking_id = b.id) AS service_ids
      FROM bookings b WHERE b.id = ? AND b.user_id = ?
    ");
    $stmt->execute([$editId, $owner]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($booking) {
      $booking['category_ids'] = $booking['category_ids'] ? array_map('intval', explode(',', $booking['category_ids'])) : [];
      $booking['service_ids'] = $booking['service_ids'] ? array_map('intval', explode(',', $booking['service_ids'])) : [];
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
  <link href="<?= htmlspecialchars($base) ?>/assets/app.css" rel="stylesheet">
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
          <input type="tel" class="form-control soft-input" name="customer_phone" required placeholder="เบอร์โทรศัพท์" value="<?= $booking ? htmlspecialchars($booking['customer_phone'] ?? '') : '' ?>">
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
          <div class="col-6 mb-3">
            <label class="form-label section-title">ช่างที่รับงาน</label>
            <select name="staff_id" class="form-select soft-input">
              <option value="">ไม่ระบุช่าง</option>
              <?php
              $selStaff = $booking['staff_id'] ?? null;
              foreach ($staffList as $sf):
              ?><option value="<?= (int)$sf['id'] ?>"<?= ((int)$selStaff === (int)$sf['id']) ? ' selected' : '' ?>><?= htmlspecialchars($sf['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="row">
          <div class="col-4 mb-3">
            <label class="form-label section-title">ราคา (บาท)</label>
            <input type="number" step="0.01" min="0" class="form-control soft-input" name="price" placeholder="-" value="<?= $booking && $booking['price'] !== null ? htmlspecialchars($booking['price']) : '' ?>">
          </div>
          <div class="col-4 mb-3">
            <label class="form-label section-title">มัดจำ (บาท)</label>
            <input type="number" step="0.01" min="0" class="form-control soft-input" name="deposit" placeholder="0" value="<?= $booking && isset($booking['deposit']) ? htmlspecialchars($booking['deposit']) : '' ?>">
          </div>
          <div class="col-4 mb-3">
            <label class="form-label section-title">การจ่าย</label>
            <select name="payment_status" class="form-select soft-input">
              <?php
              $ps = $booking['payment_status'] ?? 'unpaid';
              foreach (['unpaid' => 'ยังไม่จ่าย', 'deposit_paid' => 'จ่ายมัดจำ', 'paid' => 'จ่ายครบ'] as $v => $l):
              ?><option value="<?= $v ?>"<?= $ps === $v ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label section-title">สลิปมัดจำ</label>
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
            <label class="form-label section-title">เวลาเริ่ม</label>
            <input type="time" class="form-control soft-input" name="start_time" required value="<?= $booking && !empty($booking['start_time']) ? date('H:i', strtotime($booking['start_time'])) : '' ?>">
          </div>
          <div class="col-6 mb-3">
            <label class="form-label section-title">เวลาจบ</label>
            <input type="time" class="form-control soft-input" name="end_time" required value="<?= $booking && !empty($booking['end_time']) ? date('H:i', strtotime($booking['end_time'])) : '' ?>">
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

    function buildPayload(force) {
      const form = document.getElementById('bookingForm');
      const fd = new FormData(form);
      const categoryIds = Array.from(form.querySelectorAll('input[name="category_ids[]"]:checked')).map(cb => cb.value);
      const serviceIds = Array.from(form.querySelectorAll('input[name="service_ids[]"]:checked')).map(cb => cb.value);
      const id = fd.get('id') ? parseInt(fd.get('id'), 10) : 0;
      const payload = {
        customer_name: fd.get('customer_name'),
        customer_phone: fd.get('customer_phone'),
        location: fd.get('location') || '',
        appointment_date: fd.get('appointment_date'),
        start_time: fd.get('start_time'),
        end_time: fd.get('end_time'),
        num_people: parseInt(fd.get('num_people') || 1, 10) || 1,
        price: fd.get('price') || '',
        deposit: fd.get('deposit') || '',
        payment_status: fd.get('payment_status') || 'unpaid',
        staff_id: fd.get('staff_id') || '',
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
      const method = id > 0 ? 'PUT' : 'POST';
      fetch(API_BASE + '/bookings.php', {
        method, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(payload),
      })
        .then(r => r.json().then(data => ({ status: r.status, data })))
        .then(({ status, data }) => {
          if (status === 409 && data.conflict) {
            let msg = 'ช่วงเวลานี้ชนกับคิวอื่น';
            if (Array.isArray(data.conflicts)) {
              msg += ':\n' + data.conflicts.map(c => '• ' + c.start_time.slice(0,5) + '-' + c.end_time.slice(0,5) + ' ' + c.customer_name).join('\n');
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
