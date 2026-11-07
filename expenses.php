<?php
/**
 * รายจ่ายของร้านและกำไรรายเดือน
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
  <title>รายจ่ายและกำไร</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&display=swap" rel="stylesheet">
  <link href="<?= htmlspecialchars($base) ?>/assets/app.css?v=<?= assetVer('assets/app.css') ?>" rel="stylesheet">
  <style> table td, table th { font-size: 0.92rem; vertical-align: middle; } </style>
</head>
<body>
  <header class="head py-3 mb-3">
    <div class="wrap-wide d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <a href="<?= htmlspecialchars($base) ?>/" class="back-btn"><i class="bi bi-arrow-left"></i> กลับ</a>
        <h1 class="brand mb-0 mt-1" style="font-size: 1.35rem; font-weight: 600;">รายจ่ายและกำไร</h1>
        <small class="text-muted">คิดจากเงินที่รับและจ่ายจริงในแต่ละเดือน</small>
      </div>
      <div class="d-flex align-items-center gap-2">
        <button class="btn btn-sm btn-light border" id="prevMonth" type="button" aria-label="เดือนก่อน"><i class="bi bi-chevron-left"></i></button>
        <input type="month" class="form-control form-control-sm" id="month" aria-label="เลือกเดือน" style="width:auto;">
        <button class="btn btn-sm btn-light border" id="nextMonth" type="button" aria-label="เดือนถัดไป"><i class="bi bi-chevron-right"></i></button>
      </div>
    </div>
  </header>

  <div class="wrap-wide pb-5 rise">
    <div id="pageMsg" class="alert py-2 d-none" role="status"></div>

    <div class="row g-2 mb-3">
      <div class="col-6 col-lg-3"><div class="stat-card" style="background:#F5F1E9;"><div class="stat-value" id="sIncome">-</div><div class="stat-label">รายรับ (บาท)</div></div></div>
      <div class="col-6 col-lg-3"><div class="stat-card" style="background:#F7F2EC;"><div class="stat-value" id="sPayout">-</div><div class="stat-label">มัดจำจ่ายช่าง (บาท)</div></div></div>
      <div class="col-6 col-lg-3"><div class="stat-card" style="background:#FAF6F2;"><div class="stat-value" id="sExpense">-</div><div class="stat-label">รายจ่ายร้าน (บาท)</div></div></div>
      <div class="col-6 col-lg-3"><div class="stat-card" style="background:#FBF3F4;"><div class="stat-value" style="color:var(--rose-deep);" id="sProfit">-</div><div class="stat-label">กำไร (บาท)</div></div></div>
    </div>

    <div class="row g-3">
      <div class="col-12 col-lg-5">
        <form class="card-soft p-3 p-md-4 mb-3" id="expenseForm">
          <input type="hidden" name="id">
          <h2 class="h6 fw-bold mb-3" id="formTitle">เพิ่มรายจ่าย</h2>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label section-title" for="expDate">วันที่</label>
              <input type="date" class="form-control soft-input" id="expDate" name="expense_date" required>
            </div>
            <div class="col-6">
              <label class="form-label section-title" for="expAmount">จำนวนเงิน (บาท)</label>
              <input type="number" step="0.01" min="0.01" class="form-control soft-input" id="expAmount" name="amount" required>
            </div>
            <div class="col-12">
              <label class="form-label section-title" for="expCategory">หมวด</label>
              <input type="text" class="form-control soft-input" id="expCategory" name="category" list="expCategories" maxlength="60" required placeholder="เลือกหรือพิมพ์เอง">
              <datalist id="expCategories">
                <option value="ค่าเช่า"><option value="เครื่องสำอาง/อุปกรณ์"><option value="ค่าเดินทาง">
                <option value="เงินเดือน"><option value="ค่าโฆษณา"><option value="ค่าน้ำ/ไฟ/อินเทอร์เน็ต"><option value="อื่นๆ">
              </datalist>
            </div>
            <div class="col-12">
              <label class="form-label section-title" for="expNote">หมายเหตุ</label>
              <input type="text" class="form-control soft-input" id="expNote" name="note" maxlength="200">
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="expRecurring" name="is_recurring">
                <label class="form-check-label" for="expRecurring">รายจ่ายประจำทุกเดือน</label>
              </div>
            </div>
          </div>
          <div class="d-grid gap-2 mt-3">
            <button type="submit" class="btn save-btn" id="expSave">บันทึกรายจ่าย</button>
            <button type="button" class="btn btn-light border d-none" id="expCancel">ยกเลิกการแก้ไข</button>
          </div>
        </form>

        <div class="card-soft p-3 mb-3">
          <h2 class="h6 fw-bold mb-2">แยกตามหมวด</h2>
          <div id="catBox" class="small text-muted">—</div>
        </div>
      </div>

      <div class="col-12 col-lg-7">
        <div class="card-soft p-3 mb-3">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <h2 class="h6 fw-bold mb-0">รายการในเดือนนี้</h2>
            <button type="button" class="quick-btn" id="copyRecurring"><i class="bi bi-arrow-repeat"></i> ดึงรายจ่ายประจำจากเดือนก่อน</button>
          </div>
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr class="text-muted"><th>วันที่</th><th>หมวด</th><th class="text-end">บาท</th><th></th></tr></thead>
              <tbody id="expBody"><tr><td colspan="4" class="text-muted small">กำลังโหลด...</td></tr></tbody>
            </table>
          </div>
        </div>

        <div class="card-soft p-3">
          <h2 class="h6 fw-bold mb-2">สรุปทั้งปี <span id="yearLabel"></span></h2>
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr class="text-muted"><th>เดือน</th><th class="text-end">รายรับ</th><th class="text-end">จ่ายช่าง</th><th class="text-end">รายจ่าย</th><th class="text-end">กำไร</th></tr></thead>
              <tbody id="yearBody"></tbody>
            </table>
          </div>
          <div class="text-muted mt-2" style="font-size:.8125rem">
            * รายรับคือเงินที่รับจริงตามวันที่รับเงิน หักคืนเงินแล้ว<br>
            * มัดจำจ่ายช่างดึงจากคิวให้อัตโนมัติ ไม่ต้องบันทึกซ้ำเป็นรายจ่าย
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    const API = <?= json_encode($base . '/api/expenses.php') ?>;
    const CSRF = <?= json_encode(csrfToken()) ?>;
    const THAI_MONTH = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    const baht = v => Number(v || 0).toLocaleString('th-TH', { maximumFractionDigits: 2 });
    const esc = s => (s === null || s === undefined ? '' : String(s)).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF };
    const monthEl = document.getElementById('month');
    const form = document.getElementById('expenseForm');
    let rows = [];

    const pad = n => String(n).padStart(2, '0');
    const currentYm = (d = new Date()) => d.getFullYear() + '-' + pad(d.getMonth() + 1);
    const today = (d = new Date()) => currentYm(d) + '-' + pad(d.getDate());

    function showMsg(text, type) {
      const el = document.getElementById('pageMsg');
      el.textContent = text;
      el.className = 'alert py-2 alert-' + type;
    }

    function shiftMonth(delta) {
      const [y, m] = monthEl.value.split('-').map(Number);
      monthEl.value = currentYm(new Date(y, m - 1 + delta, 1));
      resetForm();
      load();
    }

    // ค่าเริ่มต้นของวันที่: วันนี้ถ้าอยู่เดือนที่เลือก ไม่งั้นวันแรกของเดือนนั้น
    function defaultDate() {
      return monthEl.value === currentYm() ? today() : monthEl.value + '-01';
    }

    function resetForm() {
      form.reset();
      form.id.value = '';
      document.getElementById('pageMsg').classList.add('d-none');
      document.getElementById('expDate').value = defaultDate();
      document.getElementById('formTitle').textContent = 'เพิ่มรายจ่าย';
      document.getElementById('expSave').textContent = 'บันทึกรายจ่าย';
      document.getElementById('expCancel').classList.add('d-none');
    }

    function editRow(r) {
      form.id.value = r.id;
      form.expense_date.value = r.expense_date;
      form.amount.value = r.amount;
      form.category.value = r.category;
      form.note.value = r.note || '';
      form.is_recurring.checked = r.is_recurring === 1;
      document.getElementById('formTitle').textContent = 'แก้ไขรายจ่าย';
      document.getElementById('expSave').textContent = 'บันทึกการแก้ไข';
      document.getElementById('expCancel').classList.remove('d-none');
      form.scrollIntoView({ behavior: 'smooth', block: 'center' });
      form.amount.focus({ preventScroll: true });
    }

    async function removeRow(r) {
      if (!confirm('ลบรายจ่าย ' + r.category + ' ' + baht(r.amount) + ' บาท?')) return;
      const res = await fetch(API + '?id=' + r.id, { method: 'DELETE', headers }).then(x => x.json());
      if (res.error) { showMsg(res.error, 'danger'); return; }
      if (Number(form.id.value) === r.id) resetForm();
      load();
    }

    function render(data) {
      const s = data.summary;
      document.getElementById('sIncome').textContent = baht(s.income);
      document.getElementById('sPayout').textContent = baht(s.staff_payout);
      document.getElementById('sExpense').textContent = baht(s.expense);
      document.getElementById('sProfit').textContent = baht(s.profit);

      rows = data.rows;
      const body = document.getElementById('expBody');
      body.innerHTML = rows.length ? rows.map((r, i) =>
        '<tr><td>' + esc(r.expense_date.slice(8)) + ' ' + THAI_MONTH[Number(r.expense_date.slice(5, 7)) - 1] + '</td>' +
        '<td>' + esc(r.category) + (r.is_recurring ? ' <i class="bi bi-arrow-repeat text-muted" title="รายจ่ายประจำ"></i>' : '') +
          (r.note ? '<div class="text-muted small">' + esc(r.note) + '</div>' : '') + '</td>' +
        '<td class="text-end">' + baht(r.amount) + '</td>' +
        '<td class="text-end text-nowrap">' +
          '<button type="button" class="btn btn-sm btn-light border icon-only me-1" data-edit="' + i + '" aria-label="แก้ไข"><i class="bi bi-pencil"></i></button>' +
          '<button type="button" class="btn btn-sm btn-light border icon-only text-danger" data-del="' + i + '" aria-label="ลบ"><i class="bi bi-trash"></i></button>' +
        '</td></tr>'
      ).join('') : '<tr><td colspan="4" class="text-muted small">ยังไม่มีรายจ่ายในเดือนนี้</td></tr>';
      body.querySelectorAll('[data-edit]').forEach(b => b.addEventListener('click', () => editRow(rows[b.dataset.edit])));
      body.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', () => removeRow(rows[b.dataset.del])));

      document.getElementById('catBox').innerHTML = data.categories.length ? data.categories.map(c =>
        '<div class="d-flex justify-content-between border-bottom py-1"><span>' + esc(c.name) + '</span><span class="fw-semibold">' + baht(c.amount) + '</span></div>'
      ).join('') : 'ยังไม่มีข้อมูล';

      document.getElementById('yearLabel').textContent = data.month.slice(0, 4);
      document.getElementById('yearBody').innerHTML = data.year.map(y => {
        const selected = y.month === data.month;
        return '<tr' + (selected ? ' class="fw-semibold"' : '') + '><td>' + THAI_MONTH[Number(y.month.slice(5)) - 1] + '</td>' +
          '<td class="text-end">' + baht(y.income) + '</td><td class="text-end">' + baht(y.staff_payout) + '</td>' +
          '<td class="text-end">' + baht(y.expense) + '</td>' +
          '<td class="text-end' + (y.profit < 0 ? ' text-danger' : '') + '">' + baht(y.profit) + '</td></tr>';
      }).join('');
    }

    async function load() {
      const data = await fetch(API + '?month=' + monthEl.value).then(r => r.json());
      if (data.error) { showMsg(data.error, 'danger'); return; }
      render(data);
    }

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const id = Number(form.id.value);
      const payload = {
        id, expense_date: form.expense_date.value, amount: form.amount.value, category: form.category.value,
        note: form.note.value, is_recurring: form.is_recurring.checked ? 1 : 0,
      };
      const res = await fetch(API, { method: id ? 'PUT' : 'POST', headers, body: JSON.stringify(payload) }).then(r => r.json());
      if (res.error) { showMsg(res.error, 'danger'); return; }
      document.getElementById('pageMsg').classList.add('d-none');
      // บันทึกลงเดือนอื่น: พาไปดูเดือนนั้น ไม่งั้นรายการจะเหมือนหายไป
      monthEl.value = payload.expense_date.slice(0, 7);
      resetForm();
      load();
    });

    document.getElementById('expCancel').addEventListener('click', resetForm);
    document.getElementById('prevMonth').addEventListener('click', () => shiftMonth(-1));
    document.getElementById('nextMonth').addEventListener('click', () => shiftMonth(1));
    monthEl.addEventListener('change', () => { if (monthEl.value) { resetForm(); load(); } });
    document.getElementById('copyRecurring').addEventListener('click', async () => {
      const res = await fetch(API + '?action=copy_recurring', { method: 'POST', headers, body: JSON.stringify({ month: monthEl.value }) }).then(r => r.json());
      if (res.error) { showMsg(res.error, 'danger'); return; }
      showMsg(res.copied ? 'ดึงรายจ่ายประจำมา ' + res.copied + ' รายการ' : 'ไม่มีรายจ่ายประจำใหม่ให้ดึงจากเดือนก่อน', res.copied ? 'success' : 'secondary');
      load();
    });

    monthEl.value = currentYm();
    resetForm();
    load();
  </script>
</body>
</html>
