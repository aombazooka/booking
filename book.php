<?php
/**
 * หน้าลูกค้าจองคิวเอง (สาธารณะ ไม่ต้อง login) — ต่อร้านผ่าน ?shop=slug
 * ส่งคำขอจอง → บันทึกเป็น status=new, source=customer แล้วรอร้านยืนยัน
 */
require __DIR__ . '/app/booking_helpers.php';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$apiBase = $base . '/api';

$shopSlug = trim($_GET['shop'] ?? '');
$shopName = '';
$shopOk = false;
try {
    $pdo = require __DIR__ . '/app/db.php';
    if ($shopSlug !== '') {
        $st = $pdo->prepare("SELECT shop_name FROM app_users WHERE shop_slug = ? AND status = 'active' LIMIT 1");
        $st->execute([$shopSlug]);
        $sn = $st->fetchColumn();
        if ($sn !== false) { $shopName = (string) $sn; $shopOk = true; }
    }
} catch (Throwable $e) { /* แสดงหน้าไม่พบร้านด้านล่าง */ }
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>จองคิวออนไลน์</title>
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
    <div class="wrap d-flex align-items-center gap-2">
      <span class="logo-badge" style="width:46px;height:46px;font-size:1.15rem;"><i class="bi bi-flower1"></i></span>
      <div>
        <div class="brand" style="font-size: 1.3rem; font-weight: 600;">จองคิวออนไลน์</div>
        <small class="text-muted"><?= $shopOk ? 'กรอกข้อมูลเพื่อส่งคำขอจอง' : 'เปิดลิงก์จองจากผู้ให้บริการ' ?></small>
      </div>
    </div>
  </header>

  <?php if (!$shopOk): ?>
  <div class="wrap pb-5 rise">
    <div class="form-card p-4 text-center">
      <div style="font-size: 2.5rem;">🔎</div>
      <h2 class="h6 fw-bold mt-2">ไม่พบร้านที่ต้องการจอง</h2>
      <p class="text-muted mb-0">โปรดเปิดลิงก์จองที่ทางร้านให้มา (เช่น <code>?shop=ชื่อร้าน</code>)</p>
    </div>
  </div>
  <?php else: ?>
  <div class="wrap pb-5 rise">
    <!-- หน้าสำเร็จ -->
    <div class="form-card p-4 text-center d-none" id="successCard">
      <div style="font-size: 3rem;">✅</div>
      <h2 class="h5 fw-bold mt-2">ส่งคำขอจองเรียบร้อย</h2>
      <p class="text-muted mb-3">ทางร้านจะติดต่อกลับเพื่อยืนยันคิวอีกครั้ง ขอบคุณค่ะ 🌸</p>
      <button class="btn btn-light border" onclick="location.reload()">จองคิวเพิ่ม</button>
    </div>

    <div class="form-card p-3 p-md-4" id="formCard">
      <form id="bookForm">
        <div class="mb-3">
          <label class="form-label section-title">ข้อมูลผู้จอง</label>
          <input type="text" class="form-control soft-input mb-2" name="customer_name" required placeholder="ชื่อ-นามสกุล">
          <input type="tel" class="form-control soft-input" name="customer_phone" required placeholder="เบอร์โทรศัพท์">
        </div>

        <div class="mb-3">
          <label class="form-label section-title">ประเภทงานที่ต้องการ <span class="title-hint">แตะเพื่อเลือก (เลือกได้หลายอย่าง)</span></label>
          <div class="select-grid" id="categoryList"><span class="text-muted small">กำลังโหลด...</span></div>
        </div>

        <div class="mb-3" id="serviceBlock">
          <label class="form-label section-title">บริการเสริม <span class="title-hint">เลือกได้หลายอย่าง</span></label>
          <div class="select-grid" id="serviceList"><span class="text-muted small">กำลังโหลด...</span></div>
        </div>

        <div class="mb-3">
          <label class="form-label section-title">เลือกช่าง</label>
          <select class="form-select soft-input" name="staff_id" id="staffSelect">
            <option value="">ไม่ระบุ (ร้านจัดให้)</option>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label section-title">สถานที่ <span class="title-hint">พิมพ์ที่อยู่ หรือปักหมุดบนแผนที่</span></label>
          <input type="text" class="form-control soft-input" name="location" id="locationInput" placeholder="ที่อยู่ หรือ Google Maps Link">
          <div class="d-flex flex-wrap gap-2 mt-2">
            <button type="button" class="quick-btn" id="toggleMap"><i class="bi bi-geo-alt"></i> ปักหมุดบนแผนที่</button>
            <button type="button" class="quick-btn" id="useMyLoc"><i class="bi bi-crosshair2"></i> ตำแหน่งปัจจุบัน</button>
          </div>
          <div id="mapBox" class="mt-2 d-none" style="height:240px;border-radius:14px;overflow:hidden;border:1px solid var(--line);"></div>
        </div>

        <div class="row">
          <div class="col-12 mb-3">
            <label class="form-label section-title">วันที่ต้องการจอง</label>
            <input type="date" class="form-control soft-input" name="appointment_date" required>
          </div>
          <div class="col-12 mb-2">
            <div class="section-title mb-1">ช่วงเวลาที่มีคิวแล้วในวันนี้</div>
            <div id="busyList" class="text-muted small">เลือกวันที่เพื่อดูคิวที่ถูกจอง</div>
          </div>
          <div class="col-6 mb-3">
            <label class="form-label section-title">เวลาเริ่ม</label>
            <input type="time" class="form-control soft-input" name="start_time" required>
          </div>
          <div class="col-6 mb-3">
            <label class="form-label section-title">เวลาจบ</label>
            <input type="time" class="form-control soft-input" name="end_time" required>
          </div>
        </div>

        <div class="mb-3">
          <div class="section-title">ตั้งเวลาเร็ว</div>
          <div class="d-flex flex-wrap gap-2 mb-2">
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
          <label class="form-label section-title" id="countLabel">จำนวนคน</label>
          <input type="number" class="form-control soft-input" name="num_people" value="1" min="1" max="99">
        </div>

        <div class="mb-3">
          <label class="form-label section-title">หมายเหตุ</label>
          <textarea class="form-control soft-input" name="note" rows="2" placeholder="เช่น ต้องการลองแต่งก่อนวันงาน"></textarea>
        </div>

        <div class="price-box mb-3 d-none" id="priceBox"></div>

        <div class="mb-3">
          <label class="form-label section-title">สลิปมัดจำ <span class="title-hint">ถ้าโอนแล้ว แนบรูปสลิป (ไม่บังคับ)</span></label>
          <input type="hidden" name="slip_path" id="slipPath">
          <input type="file" class="form-control soft-input" id="slipFile" accept="image/png,image/jpeg,image/webp">
          <div id="slipStatus" class="small mt-1"></div>
        </div>

        <div class="d-grid gap-2">
          <button type="submit" class="btn save-btn" id="submitBtn">ส่งคำขอจอง</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="toast" class="toast" role="alert"><div class="toast-body" id="toastBody"></div></div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <?php if ($shopOk): ?>
  <script>
    const API_BASE = <?= json_encode($apiBase) ?>;
    const SHOP = <?= json_encode($shopSlug) ?>;
    let CATEGORIES = [], SERVICES = [], STAFF = [];
    const money = v => Number(v).toLocaleString('th-TH', {minimumFractionDigits: 0});

    function showToast(msg, type) {
      const el = document.getElementById('toast');
      const bg = type === 'danger' ? 'text-bg-danger' : (type === 'success' ? 'text-bg-success' : 'text-bg-secondary');
      document.getElementById('toastBody').textContent = msg;
      el.className = 'toast show ' + bg;
      new bootstrap.Toast(el).show();
    }

    // โหลดประเภทงาน + บริการ (ของร้านนี้)
    fetch(API_BASE + '/options.php?shop=' + encodeURIComponent(SHOP)).then(r => r.json()).then(data => {
      CATEGORIES = data.categories || [];
      SERVICES = data.services || [];
      STAFF = data.staff || [];
      const ss = document.getElementById('staffSelect');
      STAFF.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id; opt.textContent = s.name;
        ss.appendChild(opt);
      });
      ss.addEventListener('change', loadBusy);
      const cl = document.getElementById('categoryList');
      cl.innerHTML = CATEGORIES.map(c => {
        const color = c.color_hex || '#9ca3af';
        const price = (c.price !== null && c.price !== undefined) ? '<span class="sc-price">' + money(c.price) + ' บาท</span>' : '';
        return '<label class="select-card" style="--accent:' + color + '">' +
          '<input type="checkbox" name="category_ids[]" value="' + c.id + '">' +
          '<span class="sc-check"><i class="bi bi-check-lg"></i></span>' +
          '<span class="sc-name"><span class="sc-dot" style="background:' + color + '"></span>' + escapeHtml(c.name) + '</span>' +
          price + '</label>';
      }).join('') || '<span class="text-muted small">ยังไม่มีประเภทงาน</span>';
      const sl = document.getElementById('serviceList');
      sl.innerHTML = SERVICES.map(s => {
        const price = (s.price !== null && s.price !== undefined) ? '<span class="sc-price">' + money(s.price) + ' บาท</span>' : '';
        const cats = (s.category_ids || []).join(',');
        return '<label class="select-card sc-plain" data-cat="' + cats + '">' +
          '<input type="checkbox" name="service_ids[]" value="' + s.id + '">' +
          '<span class="sc-check"><i class="bi bi-check-lg"></i></span>' +
          '<span class="sc-name">' + escapeHtml(s.name) + '</span>' +
          price + '</label>';
      }).join('') || '<span class="text-muted small">ไม่มีบริการเสริม</span>';
      // ไฮไลต์การ์ดที่เลือก + ผูก event ประเภทงาน
      document.querySelectorAll('#categoryList .select-card input, #serviceList .select-card input').forEach(cb => {
        cb.addEventListener('change', () => cb.closest('.select-card').classList.toggle('selected', cb.checked));
      });
      cl.querySelectorAll('input').forEach(cb => cb.addEventListener('change', onCategoryChange));
      updateServiceVisibility();
    });

    // แสดงเฉพาะบริการที่อยู่ใต้ประเภทที่เลือก (หรือบริการทั่วไปที่ไม่ผูกประเภท)
    function updateServiceVisibility() {
      const selIds = Array.from(document.querySelectorAll('input[name="category_ids[]"]:checked')).map(cb => Number(cb.value));
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

    function escapeHtml(s) { return (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    function selectedCategories() {
      const ids = Array.from(document.querySelectorAll('input[name="category_ids[]"]:checked')).map(cb => Number(cb.value));
      return CATEGORIES.filter(c => ids.includes(c.id));
    }

    // เมื่อเลือกประเภท: ปรับ label จำนวน, แนะนำเวลาจบจาก duration, แสดงราคา/มัดจำประเมิน
    function onCategoryChange() {
      const sel = selectedCategories();
      updateServiceVisibility();
      // ป้ายจำนวน (ใช้ของประเภทแรกที่มี count_label)
      const withLabel = sel.find(c => c.count_label);
      document.getElementById('countLabel').textContent = withLabel ? withLabel.count_label : 'จำนวน';
      // คำนวณเวลาจบจาก duration รวม ถ้ามีเวลาเริ่มแล้ว
      const totalDur = sel.reduce((a, c) => a + (c.duration_min || 0), 0);
      const startEl = document.querySelector('[name="start_time"]');
      if (totalDur > 0 && startEl.value) setEndFromDuration(totalDur);
      // ราคา/มัดจำประเมิน
      const price = sel.reduce((a, c) => a + (c.price || 0), 0);
      const deposit = sel.reduce((a, c) => a + (c.deposit_default || 0), 0);
      const box = document.getElementById('priceBox');
      if (price > 0 || deposit > 0) {
        box.classList.remove('d-none');
        box.innerHTML = (price > 0 ? '💰 ราคาประเมิน: <strong>' + money(price) + '</strong> บาท' : '') +
          (deposit > 0 ? '<br>มัดจำ: <strong>' + money(deposit) + '</strong> บาท' : '') +
          '<div class="text-muted mt-1" style="font-size:.8125rem">* ราคาจริงทางร้านจะยืนยันอีกครั้ง</div>';
      } else {
        box.classList.add('d-none');
      }
    }

    function setEndFromDuration(min) {
      const start = document.querySelector('[name="start_time"]').value;
      if (!start) return;
      const [h, m] = start.split(':').map(Number);
      const t = new Date(); t.setHours(h, m + min, 0, 0);
      document.querySelector('[name="end_time"]').value =
        String(t.getHours()).padStart(2, '0') + ':' + String(t.getMinutes()).padStart(2, '0');
    }

    document.querySelectorAll('[data-start]').forEach(b => b.addEventListener('click', () => {
      document.querySelector('[name="start_time"]').value = b.dataset.start;
      const dur = selectedCategories().reduce((a, c) => a + (c.duration_min || 0), 0);
      if (dur > 0) setEndFromDuration(dur);
    }));
    document.querySelectorAll('[data-duration]').forEach(b => b.addEventListener('click', () => {
      if (!document.querySelector('[name="start_time"]').value) { showToast('กรุณาเลือกเวลาเริ่มก่อน', 'danger'); return; }
      setEndFromDuration(Number(b.dataset.duration));
    }));

    // โหลดช่วงเวลาที่ถูกจองเมื่อเปลี่ยนวัน
    (() => { const d = document.querySelector('[name="appointment_date"]'); d.min = new Date().toISOString().slice(0, 10); })();
    document.querySelector('[name="appointment_date"]').addEventListener('change', loadBusy);
    function loadBusy() {
      const date = document.querySelector('[name="appointment_date"]').value;
      const el = document.getElementById('busyList');
      if (!date) { el.textContent = 'เลือกวันที่เพื่อดูคิวที่ถูกจอง'; return; }
      const staff = document.getElementById('staffSelect').value;
      const staffParam = staff ? ('&staff=' + staff) : '&staff=none';
      fetch(API_BASE + '/availability.php?shop=' + encodeURIComponent(SHOP) + '&date=' + date + staffParam).then(r => r.json()).then(d => {
        if (!d.busy || d.busy.length === 0) { el.innerHTML = '<span class="text-success">ช่วงนี้ยังว่าง 🎉</span>'; return; }
        el.innerHTML = d.busy.map(b => '<span class="busy-chip">' + b.start + ' - ' + b.end + '</span>').join('');
      }).catch(() => { el.textContent = 'โหลดข้อมูลคิวไม่สำเร็จ'; });
    }

    // ส่งคำขอจอง
    document.getElementById('bookForm').addEventListener('submit', function(e) {
      e.preventDefault();
      const form = e.target;
      const categoryIds = Array.from(form.querySelectorAll('input[name="category_ids[]"]:checked')).map(cb => cb.value);
      if (categoryIds.length === 0) { showToast('กรุณาเลือกประเภทงานอย่างน้อย 1 ข้อ', 'danger'); return; }
      const serviceIds = Array.from(form.querySelectorAll('input[name="service_ids[]"]:checked')).map(cb => cb.value);
      const fd = new FormData(form);
      if (fd.get('start_time') >= fd.get('end_time')) { showToast('เวลาเริ่มต้องน้อยกว่าเวลาจบ', 'danger'); return; }

      const payload = {
        customer_name: fd.get('customer_name'),
        customer_phone: fd.get('customer_phone'),
        location: fd.get('location') || '',
        appointment_date: fd.get('appointment_date'),
        start_time: fd.get('start_time'),
        end_time: fd.get('end_time'),
        num_people: parseInt(fd.get('num_people') || 1, 10) || 1,
        staff_id: fd.get('staff_id') || '',
        slip_path: fd.get('slip_path') || '',
        note: fd.get('note') || '',
        shop: SHOP,
        category_ids: categoryIds,
        service_ids: serviceIds,
      };
      fetch(API_BASE + '/bookings.php', {
        method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload),
      }).then(r => r.json().then(data => ({ ok: r.ok, data })))
        .then(({ ok, data }) => {
          if (!ok || data.error) {
            showToast(data.error || 'เกิดข้อผิดพลาด', 'danger');
            if (data.conflict) loadBusy();
            return;
          }
          document.getElementById('formCard').classList.add('d-none');
          document.getElementById('successCard').classList.remove('d-none');
          window.scrollTo(0, 0);
        })
        .catch(() => showToast('เกิดข้อผิดพลาดในการส่ง', 'danger'));
    });

    // อัปโหลดสลิปมัดจำ
    document.getElementById('slipFile').addEventListener('change', function () {
      const file = this.files[0];
      const status = document.getElementById('slipStatus');
      const submitBtn = document.getElementById('submitBtn');
      if (!file) { document.getElementById('slipPath').value = ''; status.innerHTML = ''; return; }
      if (file.size > 5 * 1024 * 1024) { status.innerHTML = '<span class="text-danger">ไฟล์ใหญ่เกิน 5MB</span>'; this.value = ''; return; }
      status.innerHTML = '<span class="text-muted">กำลังอัปโหลด...</span>';
      submitBtn.disabled = true;
      const f = new FormData(); f.append('slip', file);
      fetch(API_BASE + '/upload_slip.php', { method: 'POST', body: f })
        .then(r => r.json())
        .then(d => {
          if (d.error || !d.file) { status.innerHTML = '<span class="text-danger">' + (d.error || 'อัปโหลดไม่สำเร็จ') + '</span>'; document.getElementById('slipPath').value = ''; this.value = ''; }
          else { document.getElementById('slipPath').value = d.file; status.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> แนบสลิปแล้ว</span>'; }
        })
        .catch(() => { status.innerHTML = '<span class="text-danger">อัปโหลดไม่สำเร็จ</span>'; })
        .finally(() => { submitBtn.disabled = false; });
    });
  </script>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="<?= htmlspecialchars($base) ?>/assets/mappicker.js"></script>
  <?php endif; ?>
</body>
</html>
