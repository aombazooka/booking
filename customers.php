<?php
/**
 * หน้าลูกค้า: ค้นหา ดูประวัติการจอง ยอดที่จ่าย และบันทึกหมายเหตุ
 */
require __DIR__ . '/app/auth.php';
requireLogin();
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>ลูกค้า</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&display=swap" rel="stylesheet">
  <link href="<?= htmlspecialchars($base) ?>/assets/app.css?v=<?= assetVer('assets/app.css') ?>" rel="stylesheet">
</head>
<body>
  <header class="head py-3 mb-3">
    <div class="wrap-wide d-flex justify-content-between align-items-center">
      <div>
        <a href="<?= htmlspecialchars($base) ?>/" class="back-btn"><i class="bi bi-arrow-left"></i> กลับ</a>
        <h1 class="brand mb-0 mt-1" style="font-size: 1.35rem; font-weight: 600;">ลูกค้า</h1>
        <small class="text-muted" id="custCount">—</small>
      </div>
    </div>
  </header>

  <div class="wrap-wide pb-5 rise">
    <div class="card-soft p-3 p-md-4">
      <input type="search" class="form-control soft-input mb-3" id="custSearch" placeholder="ค้นหาชื่อหรือเบอร์โทร" aria-label="ค้นหาลูกค้า">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr class="text-muted"><th>ชื่อ</th><th>เบอร์โทร</th><th class="text-end">จำนวนคิว</th><th class="text-end">จ่ายแล้ว (บาท)</th><th>นัดล่าสุด</th></tr></thead>
          <tbody id="custBody"><tr><td colspan="5" class="text-muted small">กำลังโหลด...</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="modal fade" id="custModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content" style="border-radius:16px;">
        <div class="modal-header border-0 pb-0">
          <h5 class="modal-title" style="font-size: 1.125rem;" id="custTitle">ลูกค้า</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
        </div>
        <div class="modal-body">
          <div class="mb-1"><a id="custPhone"></a></div>
          <label class="form-label section-title mt-2">หมายเหตุลูกค้า</label>
          <textarea class="form-control soft-input" id="custNote" rows="2" placeholder="เช่น แพ้เครื่องสำอางบางยี่ห้อ"></textarea>
          <div class="d-flex justify-content-end mt-2"><button class="btn btn-sm btn-light border" id="custSave" type="button">บันทึกหมายเหตุ</button></div>
          <div class="section-title mt-3 mb-1">ประวัติการจอง</div>
          <div id="custHistory" class="small"></div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const API_BASE = <?= json_encode($base . '/api') ?>;
    const BASE = <?= json_encode($base) ?>;
    const CSRF = <?= json_encode(csrfToken()) ?>;
    const STATUS_LABEL = { new: 'ใหม่', confirmed: 'ยืนยันแล้ว', done: 'เสร็จงาน', cancelled: 'ยกเลิก' };
    const esc = s => (s === null || s === undefined ? '' : String(s)).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const baht = v => Number(v || 0).toLocaleString('th-TH', { maximumFractionDigits: 2 });
    const thDate = d => d ? new Date(d + 'T12:00:00').toLocaleDateString('th-TH', { dateStyle: 'medium' }) : '-';
    const modal = new bootstrap.Modal(document.getElementById('custModal'));
    let ALL = [], currentId = 0;

    function render() {
      const q = document.getElementById('custSearch').value.trim().toLowerCase();
      const rows = ALL.filter(c => !q || c.name.toLowerCase().includes(q) || c.phone.includes(q));
      document.getElementById('custCount').textContent = ALL.length.toLocaleString('th-TH') + ' คน';
      const body = document.getElementById('custBody');
      body.innerHTML = rows.length ? rows.map(c =>
        '<tr style="cursor:pointer" data-id="' + Number(c.id) + '" tabindex="0">' +
        '<td>' + esc(c.name) + '</td><td>' + esc(c.phone) + '</td>' +
        '<td class="text-end">' + Number(c.booking_count) + '</td>' +
        '<td class="text-end">' + baht(c.total_paid) + '</td>' +
        '<td>' + esc(thDate(c.last_date)) + '</td></tr>'
      ).join('') : '<tr><td colspan="5" class="text-muted small">ไม่พบลูกค้า</td></tr>';
      body.querySelectorAll('tr[data-id]').forEach(tr => {
        tr.addEventListener('click', () => openCustomer(tr.dataset.id));
        tr.addEventListener('keydown', e => { if (e.key === 'Enter') openCustomer(tr.dataset.id); });
      });
    }

    async function load() {
      const rows = await fetch(API_BASE + '/customers.php').then(r => r.json());
      ALL = Array.isArray(rows) ? rows : [];
      render();
    }

    async function openCustomer(id) {
      const c = await fetch(API_BASE + '/customers.php?id=' + id).then(r => r.json());
      if (c.error) { alert(c.error); return; }
      currentId = c.id;
      document.getElementById('custTitle').textContent = c.name;
      const phone = document.getElementById('custPhone');
      phone.textContent = c.phone;
      phone.href = 'tel:' + c.phone;
      document.getElementById('custNote').value = c.note || '';
      document.getElementById('custHistory').innerHTML = (c.bookings || []).map(b =>
        '<a class="d-flex justify-content-between border-top py-2 text-decoration-none" href="' + BASE + '/form.php?id=' + Number(b.id) + '">' +
        '<span>' + esc(thDate(b.appointment_date)) + ' ' + esc(b.time_label) + ' · ' + esc(STATUS_LABEL[b.status] || b.status) + '</span>' +
        '<span>' + (b.price !== null ? baht(b.price) + ' บาท' : 'ยังไม่ใส่ราคา') + '</span></a>'
      ).join('') || '<div class="text-muted">ยังไม่มีประวัติ</div>';
      modal.show();
    }

    document.getElementById('custSearch').addEventListener('input', render);
    document.getElementById('custSave').addEventListener('click', async () => {
      const res = await fetch(API_BASE + '/customers.php', {
        method: 'PUT', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ id: currentId, note: document.getElementById('custNote').value }),
      }).then(r => r.json());
      if (res.error) { alert(res.error); return; }
      modal.hide();
      load();
    });
    load();
  </script>
</body>
</html>
