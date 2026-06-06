<?php
require dirname(__DIR__) . '/app/auth.php';
requireLogin();
// โปรเจกต์รากอยู่เหนือโฟลเดอร์ admin หนึ่งชั้น
$projectBase = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
$apiBase = $projectBase . '/api';
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>จัดการบริการ</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&display=swap" rel="stylesheet">
  <link href="<?= htmlspecialchars($projectBase) ?>/assets/app.css" rel="stylesheet">
  <style> table td, table th { vertical-align: middle; font-size: 0.92rem; } </style>
</head>
<body>
  <header class="head py-3 mb-3">
    <div class="wrap-wide d-flex justify-content-between align-items-center">
      <div>
        <a href="<?= htmlspecialchars($projectBase) ?>/" class="text-muted text-decoration-none small"><i class="bi bi-arrow-left"></i> กลับ</a>
        <h1 class="brand mb-0 mt-1" style="font-size: 1.35rem; font-weight: 600;">จัดการบริการ</h1>
        <small class="text-muted">ประเภทงาน &amp; บริการ &amp; ช่าง — เพิ่ม/แก้/ลบได้เอง</small>
      </div>
    </div>
  </header>

  <div class="wrap-wide pb-5 rise">
    <!-- ประเภทงาน -->
    <div class="card-soft p-3 p-md-4 mb-3">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 fw-bold mb-0">ประเภทงาน</h2>
        <button class="btn btn-sm btn-grad" id="addCat"><i class="bi bi-plus-lg"></i> เพิ่มประเภท</button>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr class="text-muted">
            <th>ชื่อ</th><th>สี</th><th class="text-end">ราคา</th><th class="text-end">มัดจำ</th>
            <th class="text-end">นาที</th><th>ป้ายจำนวน</th><th>สถานะ</th><th></th>
          </tr></thead>
          <tbody id="catBody"><tr><td colspan="8" class="text-muted small">กำลังโหลด...</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- บริการ -->
    <div class="card-soft p-3 p-md-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 fw-bold mb-0">บริการ</h2>
        <button class="btn btn-sm btn-grad" id="addSvc"><i class="bi bi-plus-lg"></i> เพิ่มบริการ</button>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr class="text-muted"><th>ชื่อ</th><th class="text-end">ราคา</th><th>สถานะ</th><th></th></tr></thead>
          <tbody id="svcBody"><tr><td colspan="4" class="text-muted small">กำลังโหลด...</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- ช่าง -->
    <div class="card-soft p-3 p-md-4 mt-3">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 fw-bold mb-0">ช่าง / พนักงาน</h2>
        <button class="btn btn-sm btn-grad" id="addStaff"><i class="bi bi-plus-lg"></i> เพิ่มช่าง</button>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr class="text-muted"><th>ชื่อ</th><th>เบอร์</th><th>สี</th><th>สถานะ</th><th></th></tr></thead>
          <tbody id="staffBody"><tr><td colspan="5" class="text-muted small">กำลังโหลด...</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Modal ประเภทงาน -->
  <div class="modal fade" id="catModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;">
      <div class="modal-header border-0"><h5 class="modal-title" id="catModalTitle">เพิ่มประเภทงาน</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="catForm">
          <input type="hidden" name="id">
          <div class="mb-2"><label class="form-label small">ชื่อประเภทงาน *</label>
            <input type="text" class="form-control" name="name" required></div>
          <div class="row g-2">
            <div class="col-6 mb-2"><label class="form-label small">สี</label>
              <input type="color" class="form-control form-control-color w-100" name="color_hex" value="#6b7280"></div>
            <div class="col-6 mb-2"><label class="form-label small">ระยะเวลา (นาที)</label>
              <input type="number" class="form-control" name="duration_min" min="0" placeholder="เช่น 90"></div>
            <div class="col-6 mb-2"><label class="form-label small">ราคา (บาท)</label>
              <input type="number" step="0.01" class="form-control" name="price" placeholder="ว่าง = ไม่กำหนด"></div>
            <div class="col-6 mb-2"><label class="form-label small">มัดจำเริ่มต้น (บาท)</label>
              <input type="number" step="0.01" class="form-control" name="deposit_default" placeholder="ว่าง = 0"></div>
            <div class="col-6 mb-2"><label class="form-label small">ป้ายช่องจำนวน</label>
              <input type="text" class="form-control" name="count_label" placeholder="เช่น จำนวนคน"></div>
            <div class="col-6 mb-2"><label class="form-label small">ลำดับ</label>
              <input type="number" class="form-control" name="sort_order" value="0"></div>
          </div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="catActive" checked>
            <label class="form-check-label" for="catActive">เปิดใช้งาน</label></div>
        </form>
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="button" class="btn btn-grad" id="catSave">บันทึก</button>
      </div>
    </div></div></div>

  <!-- Modal บริการ -->
  <div class="modal fade" id="svcModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;">
      <div class="modal-header border-0"><h5 class="modal-title" id="svcModalTitle">เพิ่มบริการ</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="svcForm">
          <input type="hidden" name="id">
          <div class="mb-2"><label class="form-label small">ชื่อบริการ *</label>
            <input type="text" class="form-control" name="name" required></div>
          <div class="row g-2">
            <div class="col-6 mb-2"><label class="form-label small">ราคา (บาท)</label>
              <input type="number" step="0.01" class="form-control" name="price" placeholder="ว่าง = ไม่กำหนด"></div>
            <div class="col-6 mb-2"><label class="form-label small">ลำดับ</label>
              <input type="number" class="form-control" name="sort_order" value="0"></div>
          </div>
          <div class="mb-2">
            <label class="form-label small">อยู่ใต้ประเภทงาน <span class="text-muted">(ไม่เลือก = แสดงทุกประเภท)</span></label>
            <div id="svcCatChecks" class="d-flex flex-wrap gap-2"></div>
          </div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="svcActive" checked>
            <label class="form-check-label" for="svcActive">เปิดใช้งาน</label></div>
        </form>
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="button" class="btn btn-grad" id="svcSave">บันทึก</button>
      </div>
    </div></div></div>

  <!-- Modal ช่าง -->
  <div class="modal fade" id="staffModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;">
      <div class="modal-header border-0"><h5 class="modal-title" id="staffModalTitle">เพิ่มช่าง</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="staffForm">
          <input type="hidden" name="id">
          <div class="mb-2"><label class="form-label small">ชื่อช่าง *</label>
            <input type="text" class="form-control" name="name" required></div>
          <div class="row g-2">
            <div class="col-6 mb-2"><label class="form-label small">เบอร์โทร</label>
              <input type="tel" class="form-control" name="phone" placeholder="ไม่บังคับ"></div>
            <div class="col-3 mb-2"><label class="form-label small">สี</label>
              <input type="color" class="form-control form-control-color w-100" name="color_hex" value="#a78bfa"></div>
            <div class="col-3 mb-2"><label class="form-label small">ลำดับ</label>
              <input type="number" class="form-control" name="sort_order" value="0"></div>
          </div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="staffActive" checked>
            <label class="form-check-label" for="staffActive">เปิดใช้งาน</label></div>
        </form>
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="button" class="btn btn-grad" id="staffSave">บันทึก</button>
      </div>
    </div></div></div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const API = <?= json_encode($apiBase) ?>;
    const CSRF = <?= json_encode(csrfToken()) ?>;
    const catModal = new bootstrap.Modal(document.getElementById('catModal'));
    const svcModal = new bootstrap.Modal(document.getElementById('svcModal'));
    const money = v => (v === null || v === undefined || v === '') ? '-' : Number(v).toLocaleString('th-TH', {minimumFractionDigits: 0});
    const esc = s => (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    let CATS = []; // เก็บประเภทงานไว้สร้าง checkbox ในฟอร์มบริการ

    function activePill(a) {
      return Number(a) === 1
        ? '<span class="badge text-bg-success muted-pill">เปิด</span>'
        : '<span class="badge text-bg-secondary muted-pill">ปิด</span>';
    }

    // ---------- ประเภทงาน ----------
    async function loadCats() {
      const rows = await fetch(API + '/categories.php').then(r => r.json());
      CATS = Array.isArray(rows) ? rows : [];
      const body = document.getElementById('catBody');
      if (!Array.isArray(rows) || rows.length === 0) { body.innerHTML = '<tr><td colspan="8" class="text-muted small">ยังไม่มีข้อมูล</td></tr>'; return; }
      body.innerHTML = rows.map(c =>
        '<tr>' +
        '<td>' + esc(c.name) + '</td>' +
        '<td><span class="color-dot" style="background:' + esc(c.color_hex) + '"></span></td>' +
        '<td class="text-end">' + money(c.price) + '</td>' +
        '<td class="text-end">' + money(c.deposit_default) + '</td>' +
        '<td class="text-end">' + (c.duration_min ?? '-') + '</td>' +
        '<td>' + esc(c.count_label || '-') + '</td>' +
        '<td>' + activePill(c.is_active) + '</td>' +
        '<td class="text-end text-nowrap">' +
          '<button class="btn btn-sm btn-light border me-1" data-edit=\'' + JSON.stringify(c) + '\'><i class="bi bi-pencil"></i></button>' +
          '<button class="btn btn-sm btn-light border text-danger" data-del="' + c.id + '"><i class="bi bi-trash"></i></button>' +
        '</td></tr>'
      ).join('');
      body.querySelectorAll('[data-edit]').forEach(b => b.onclick = () => openCat(JSON.parse(b.dataset.edit)));
      body.querySelectorAll('[data-del]').forEach(b => b.onclick = () => delCat(b.dataset.del));
    }

    function openCat(c) {
      const f = document.getElementById('catForm');
      document.getElementById('catModalTitle').textContent = c ? 'แก้ไขประเภทงาน' : 'เพิ่มประเภทงาน';
      f.id.value = c?.id || '';
      f.name.value = c?.name || '';
      f.color_hex.value = c?.color_hex || '#6b7280';
      f.duration_min.value = c?.duration_min ?? '';
      f.price.value = c?.price ?? '';
      f.deposit_default.value = c?.deposit_default ?? '';
      f.count_label.value = c?.count_label || '';
      f.sort_order.value = c?.sort_order ?? 0;
      f.is_active.checked = c ? Number(c.is_active) === 1 : true;
      catModal.show();
    }

    document.getElementById('addCat').onclick = () => openCat(null);
    document.getElementById('catSave').onclick = async () => {
      const f = document.getElementById('catForm');
      if (!f.name.value.trim()) { alert('กรุณากรอกชื่อประเภทงาน'); return; }
      const payload = {
        id: f.id.value ? Number(f.id.value) : undefined,
        name: f.name.value.trim(), color_hex: f.color_hex.value,
        duration_min: f.duration_min.value, price: f.price.value,
        deposit_default: f.deposit_default.value, count_label: f.count_label.value,
        sort_order: f.sort_order.value, is_active: f.is_active.checked ? 1 : 0,
      };
      const method = payload.id ? 'PUT' : 'POST';
      const res = await fetch(API + '/categories.php', { method, headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF}, body: JSON.stringify(payload) }).then(r => r.json());
      if (res.error) { alert(res.error); return; }
      catModal.hide(); loadCats();
    };
    async function delCat(id) {
      if (!confirm('ลบประเภทงานนี้? คิวที่เคยใช้ประเภทนี้จะถูกถอดประเภทออก')) return;
      const res = await fetch(API + '/categories.php?id=' + id, { method: 'DELETE', headers: { 'X-CSRF-Token': CSRF } }).then(r => r.json());
      if (res.error) { alert(res.error); return; }
      loadCats();
    }

    // ---------- บริการ ----------
    async function loadSvcs() {
      const rows = await fetch(API + '/services.php').then(r => r.json());
      const body = document.getElementById('svcBody');
      if (!Array.isArray(rows) || rows.length === 0) { body.innerHTML = '<tr><td colspan="4" class="text-muted small">ยังไม่มีข้อมูล</td></tr>'; return; }
      body.innerHTML = rows.map(s =>
        '<tr>' +
        '<td>' + esc(s.name) + '</td>' +
        '<td class="text-end">' + money(s.price) + '</td>' +
        '<td>' + activePill(s.is_active) + '</td>' +
        '<td class="text-end text-nowrap">' +
          '<button class="btn btn-sm btn-light border me-1" data-edit=\'' + JSON.stringify(s) + '\'><i class="bi bi-pencil"></i></button>' +
          '<button class="btn btn-sm btn-light border text-danger" data-del="' + s.id + '"><i class="bi bi-trash"></i></button>' +
        '</td></tr>'
      ).join('');
      body.querySelectorAll('[data-edit]').forEach(b => b.onclick = () => openSvc(JSON.parse(b.dataset.edit)));
      body.querySelectorAll('[data-del]').forEach(b => b.onclick = () => delSvc(b.dataset.del));
    }

    function openSvc(s) {
      const f = document.getElementById('svcForm');
      document.getElementById('svcModalTitle').textContent = s ? 'แก้ไขบริการ' : 'เพิ่มบริการ';
      f.id.value = s?.id || '';
      f.name.value = s?.name || '';
      f.price.value = s?.price ?? '';
      f.sort_order.value = s?.sort_order ?? 0;
      f.is_active.checked = s ? Number(s.is_active) === 1 : true;
      // สร้าง checkbox ประเภทงาน + ติ๊กตามที่บริการนี้ผูกไว้
      const linked = (s && Array.isArray(s.category_ids)) ? s.category_ids.map(Number) : [];
      document.getElementById('svcCatChecks').innerHTML = CATS.length
        ? CATS.map(c => '<label class="choice-pill" style="cursor:pointer"><input type="checkbox" class="svc-cat" value="' + c.id + '"' + (linked.includes(Number(c.id)) ? ' checked' : '') + '> ' + esc(c.name) + '</label>').join('')
        : '<span class="text-muted small">ยังไม่มีประเภทงาน</span>';
      svcModal.show();
    }

    document.getElementById('addSvc').onclick = () => openSvc(null);
    document.getElementById('svcSave').onclick = async () => {
      const f = document.getElementById('svcForm');
      if (!f.name.value.trim()) { alert('กรุณากรอกชื่อบริการ'); return; }
      const categoryIds = Array.from(document.querySelectorAll('#svcCatChecks .svc-cat:checked')).map(cb => Number(cb.value));
      const payload = {
        id: f.id.value ? Number(f.id.value) : undefined,
        name: f.name.value.trim(), price: f.price.value,
        sort_order: f.sort_order.value, is_active: f.is_active.checked ? 1 : 0,
        category_ids: categoryIds,
      };
      const method = payload.id ? 'PUT' : 'POST';
      const res = await fetch(API + '/services.php', { method, headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF}, body: JSON.stringify(payload) }).then(r => r.json());
      if (res.error) { alert(res.error); return; }
      svcModal.hide(); loadSvcs();
    };
    async function delSvc(id) {
      if (!confirm('ลบบริการนี้?')) return;
      const res = await fetch(API + '/services.php?id=' + id, { method: 'DELETE', headers: { 'X-CSRF-Token': CSRF } }).then(r => r.json());
      if (res.error) { alert(res.error); return; }
      loadSvcs();
    }

    // ---------- ช่าง ----------
    const staffModal = new bootstrap.Modal(document.getElementById('staffModal'));
    async function loadStaff() {
      const rows = await fetch(API + '/staff.php').then(r => r.json());
      const body = document.getElementById('staffBody');
      if (!Array.isArray(rows) || rows.length === 0) { body.innerHTML = '<tr><td colspan="5" class="text-muted small">ยังไม่มีช่าง</td></tr>'; return; }
      body.innerHTML = rows.map(s =>
        '<tr>' +
        '<td>' + esc(s.name) + '</td>' +
        '<td>' + esc(s.phone || '-') + '</td>' +
        '<td><span class="color-dot" style="background:' + esc(s.color_hex) + '"></span></td>' +
        '<td>' + activePill(s.is_active) + '</td>' +
        '<td class="text-end text-nowrap">' +
          '<button class="btn btn-sm btn-light border me-1" data-edit=\'' + JSON.stringify(s) + '\'><i class="bi bi-pencil"></i></button>' +
          '<button class="btn btn-sm btn-light border text-danger" data-del="' + s.id + '"><i class="bi bi-trash"></i></button>' +
        '</td></tr>'
      ).join('');
      body.querySelectorAll('[data-edit]').forEach(b => b.onclick = () => openStaff(JSON.parse(b.dataset.edit)));
      body.querySelectorAll('[data-del]').forEach(b => b.onclick = () => delStaff(b.dataset.del));
    }

    function openStaff(s) {
      const f = document.getElementById('staffForm');
      document.getElementById('staffModalTitle').textContent = s ? 'แก้ไขช่าง' : 'เพิ่มช่าง';
      f.id.value = s?.id || '';
      f.name.value = s?.name || '';
      f.phone.value = s?.phone || '';
      f.color_hex.value = s?.color_hex || '#a78bfa';
      f.sort_order.value = s?.sort_order ?? 0;
      f.is_active.checked = s ? Number(s.is_active) === 1 : true;
      staffModal.show();
    }

    document.getElementById('addStaff').onclick = () => openStaff(null);
    document.getElementById('staffSave').onclick = async () => {
      const f = document.getElementById('staffForm');
      if (!f.name.value.trim()) { alert('กรุณากรอกชื่อช่าง'); return; }
      const payload = {
        id: f.id.value ? Number(f.id.value) : undefined,
        name: f.name.value.trim(), phone: f.phone.value.trim(), color_hex: f.color_hex.value,
        sort_order: f.sort_order.value, is_active: f.is_active.checked ? 1 : 0,
      };
      const method = payload.id ? 'PUT' : 'POST';
      const res = await fetch(API + '/staff.php', { method, headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF}, body: JSON.stringify(payload) }).then(r => r.json());
      if (res.error) { alert(res.error); return; }
      staffModal.hide(); loadStaff();
    };
    async function delStaff(id) {
      if (!confirm('ลบช่างคนนี้? งานที่เคยมอบให้ช่างนี้จะกลายเป็น "ไม่ระบุช่าง"')) return;
      const res = await fetch(API + '/staff.php?id=' + id, { method: 'DELETE', headers: { 'X-CSRF-Token': CSRF } }).then(r => r.json());
      if (res.error) { alert(res.error); return; }
      loadStaff();
    }

    loadCats();
    loadSvcs();
    loadStaff();
  </script>
</body>
</html>
