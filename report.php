<?php
require __DIR__ . '/app/auth.php';
requireLogin();
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$apiBase = $base . '/api';
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>รายงานสรุป</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&display=swap" rel="stylesheet">
  <link href="<?= htmlspecialchars($base) ?>/assets/app.css" rel="stylesheet">
  <style>
    table td, table th { font-size: 0.92rem; vertical-align: middle; }
    /* จำกัดความสูงกราฟ กัน Chart.js ยืดไม่สิ้นสุด (maintainAspectRatio:false) */
    .chart-box { position: relative; height: 280px; width: 100%; }
    @media print {
      .no-print { display: none !important; }
      body { background: #fff; }
      .head { position: static; }
      .card-soft { box-shadow: none; border-color: #ccc; }
    }
  </style>
</head>
<body>
  <header class="head py-3 mb-3">
    <div class="wrap-wide d-flex justify-content-between align-items-center">
      <div>
        <a href="<?= htmlspecialchars($base) ?>/" class="text-muted text-decoration-none small"><i class="bi bi-arrow-left"></i> กลับ</a>
        <h1 class="brand mb-0 mt-1" style="font-size: 1.35rem; font-weight: 600;">รายงานสรุป</h1>
        <small class="text-muted" id="rangeLabel">—</small>
      </div>
      <div class="d-flex gap-2 no-print">
        <button class="btn btn-sm btn-outline-secondary" id="btnExport"><i class="bi bi-download"></i> CSV</button>
        <button class="btn btn-sm btn-outline-secondary" id="btnPrint"><i class="bi bi-printer"></i> พิมพ์</button>
      </div>
    </div>
  </header>

  <div class="wrap-wide pb-5 rise">
    <!-- ตัวเลือกช่วงเวลา -->
    <div class="card-soft p-3 mb-3 no-print">
      <div class="d-flex flex-wrap gap-2 align-items-end">
        <div class="d-flex flex-wrap gap-2" id="presets">
          <button class="btn btn-sm btn-outline-secondary preset-btn" data-preset="this_month">เดือนนี้</button>
          <button class="btn btn-sm btn-outline-secondary preset-btn" data-preset="last_month">เดือนที่แล้ว</button>
          <button class="btn btn-sm btn-outline-secondary preset-btn" data-preset="this_year">ปีนี้</button>
          <button class="btn btn-sm btn-outline-secondary preset-btn" data-preset="all">ทั้งหมด</button>
        </div>
        <div class="ms-auto d-flex gap-2 align-items-end flex-wrap">
          <div><label class="form-label small mb-1">ช่าง</label>
            <select id="staffFilter" class="form-select form-select-sm" style="min-width:130px;">
              <option value="">ทุกช่าง</option>
              <option value="none">ไม่ระบุช่าง</option>
            </select>
          </div>
          <div><label class="form-label small mb-1">ตั้งแต่</label><input type="date" id="start" class="form-control form-control-sm"></div>
          <div><label class="form-label small mb-1">ถึง</label><input type="date" id="end" class="form-control form-control-sm"></div>
          <button class="btn btn-sm btn-dark" id="applyBtn" style="border-radius:12px;">ดูรายงาน</button>
        </div>
      </div>
    </div>

    <!-- การ์ดสรุป -->
    <div class="row g-2 mb-3">
      <div class="col-6 col-lg-3"><div class="stat-card" style="background:#FBF3F4;"><div class="stat-value" id="sBookings">-</div><div class="stat-label">งานทั้งหมด (ไม่นับยกเลิก)</div></div></div>
      <div class="col-6 col-lg-3"><div class="stat-card" style="background:#F5F1E9;"><div class="stat-value" style="color:var(--rose-deep);" id="sRevenue">-</div><div class="stat-label">มูลค่างานรวม (บาท)</div></div></div>
      <div class="col-6 col-lg-3"><div class="stat-card" style="background:#F7F2EC;"><div class="stat-value" id="sDeposit">-</div><div class="stat-label">มัดจำรวม (บาท)</div></div></div>
      <div class="col-6 col-lg-3"><div class="stat-card" style="background:#FAF6F2;"><div class="stat-value" id="sCancelled">-</div><div class="stat-label">งานที่ยกเลิก</div></div></div>
    </div>

    <div class="row g-3">
      <!-- กราฟรายเดือน -->
      <div class="col-12 col-lg-7"><div class="card-soft p-3 h-100">
        <h2 class="h6 fw-bold mb-3">มูลค่างาน &amp; จำนวนงานรายเดือน</h2>
        <div class="chart-box"><canvas id="monthChart"></canvas></div>
      </div></div>
      <!-- สัดส่วนประเภทงาน -->
      <div class="col-12 col-lg-5"><div class="card-soft p-3 h-100">
        <h2 class="h6 fw-bold mb-3">สัดส่วนตามประเภทงาน</h2>
        <div class="chart-box"><canvas id="catChart"></canvas></div>
      </div></div>
    </div>

    <div class="row g-3 mt-0">
      <!-- ตารางประเภทงาน -->
      <div class="col-12 col-lg-7"><div class="card-soft p-3">
        <h2 class="h6 fw-bold mb-2">รายได้ตามประเภทงาน</h2>
        <div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr class="text-muted"><th>ประเภทงาน</th><th class="text-end">จำนวนงาน</th><th class="text-end">มูลค่า (บาท)</th></tr></thead>
          <tbody id="catBody"><tr><td colspan="3" class="text-muted small">—</td></tr></tbody>
        </table></div>
        <div class="text-muted" style="font-size:.8125rem">* งานที่เลือกหลายประเภทจะถูกนับในทุกประเภท</div>
      </div>
      <div class="card-soft p-3 mt-3">
        <h2 class="h6 fw-bold mb-2">งาน &amp; รายได้แยกตามช่าง</h2>
        <div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr class="text-muted"><th>ช่าง</th><th class="text-end">จำนวนงาน</th><th class="text-end">มูลค่า (บาท)</th></tr></thead>
          <tbody id="staffBody"><tr><td colspan="3" class="text-muted small">—</td></tr></tbody>
        </table></div>
      </div></div>
      <!-- สถานะ + การจ่าย + ที่มา -->
      <div class="col-12 col-lg-5"><div class="card-soft p-3">
        <h2 class="h6 fw-bold mb-2">สถานะงาน</h2>
        <div id="statusBox" class="mb-3 small text-muted">—</div>
        <h2 class="h6 fw-bold mb-2">การจ่ายเงิน</h2>
        <div id="paymentBox" class="mb-3 small text-muted">—</div>
        <h2 class="h6 fw-bold mb-2">ที่มาการจอง</h2>
        <div id="sourceBox" class="small text-muted">—</div>
      </div></div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script>
    const API_BASE = <?= json_encode($apiBase) ?>;
    const baht = v => Number(v || 0).toLocaleString('th-TH', {minimumFractionDigits: 0});
    const esc = s => (s === null || s === undefined ? '' : String(s)).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const STATUS_LABEL = { new: 'ใหม่', confirmed: 'ยืนยันแล้ว', done: 'เสร็จงาน', cancelled: 'ยกเลิก' };
    const PAY_LABEL = { unpaid: 'ยังไม่จ่าย', deposit_paid: 'จ่ายมัดจำ', paid: 'จ่ายครบ' };
    const SRC_LABEL = { admin: 'ร้านบันทึกเอง', customer: 'ลูกค้าจองเอง' };
    let monthChart = null, catChart = null;

    function fmtDate(d) { return d.toISOString().slice(0, 10); }

    function setPreset(preset) {
      const now = new Date();
      let s, e;
      if (preset === 'this_month') { s = new Date(now.getFullYear(), now.getMonth(), 1); e = new Date(now.getFullYear(), now.getMonth() + 1, 0); }
      else if (preset === 'last_month') { s = new Date(now.getFullYear(), now.getMonth() - 1, 1); e = new Date(now.getFullYear(), now.getMonth(), 0); }
      else if (preset === 'this_year') { s = new Date(now.getFullYear(), 0, 1); e = new Date(now.getFullYear(), 11, 31); }
      else { s = new Date(2000, 0, 1); e = new Date(now.getFullYear() + 1, 11, 31); }
      document.getElementById('start').value = fmtDate(s);
      document.getElementById('end').value = fmtDate(e);
      load();
    }

    function pillRow(label, count, extra) {
      return '<div class="d-flex justify-content-between border-bottom py-1">' +
        '<span>' + label + '</span><span class="fw-semibold">' + count + (extra ? ' <span class="text-muted fw-normal">' + extra + '</span>' : '') + '</span></div>';
    }

    let staffFilterReady = false;
    async function load() {
      const start = document.getElementById('start').value;
      const end = document.getElementById('end').value;
      if (!start || !end) return;
      const staff = document.getElementById('staffFilter').value;
      const staffParam = staff ? ('&staff=' + staff) : '';
      const data = await fetch(API_BASE + '/reports.php?start=' + start + '&end=' + end + staffParam).then(r => r.json());
      if (data.error) { alert(data.error); return; }

      // เติมตัวเลือกช่างใน dropdown ครั้งแรก
      if (!staffFilterReady && Array.isArray(data.staff_options)) {
        const sel = document.getElementById('staffFilter');
        data.staff_options.forEach(s => {
          const o = document.createElement('option'); o.value = s.id; o.textContent = s.name; sel.appendChild(o);
        });
        staffFilterReady = true;
      }

      document.getElementById('rangeLabel').textContent = data.range.start + ' ถึง ' + data.range.end;
      document.getElementById('sBookings').textContent = data.summary.total_bookings.toLocaleString('th-TH');
      document.getElementById('sRevenue').textContent = baht(data.summary.total_revenue);
      document.getElementById('sDeposit').textContent = baht(data.summary.total_deposit);
      document.getElementById('sCancelled').textContent = (data.by_status.cancelled || 0).toLocaleString('th-TH');

      // ตารางประเภทงาน
      const cb = document.getElementById('catBody');
      cb.innerHTML = (data.by_category.length === 0)
        ? '<tr><td colspan="3" class="text-muted small">ไม่มีข้อมูลในช่วงนี้</td></tr>'
        : data.by_category.map(c =>
            '<tr><td><span class="color-dot" style="background:' + esc(c.color_hex) + '"></span>' + esc(c.name) + '</td>' +
            '<td class="text-end">' + c.cnt + '</td><td class="text-end">' + baht(c.revenue) + '</td></tr>'
          ).join('');

      // กล่องสถานะ/การจ่าย/ที่มา
      document.getElementById('statusBox').innerHTML = Object.keys(STATUS_LABEL)
        .map(k => pillRow(STATUS_LABEL[k], (data.by_status[k] || 0))).join('');
      const payMap = {}; (data.by_payment || []).forEach(p => payMap[p.payment_status] = p);
      document.getElementById('paymentBox').innerHTML = Object.keys(PAY_LABEL)
        .map(k => pillRow(PAY_LABEL[k], (payMap[k] ? payMap[k].c : 0), payMap[k] && payMap[k].revenue ? baht(payMap[k].revenue) + ' บาท' : '')).join('');
      document.getElementById('sourceBox').innerHTML = Object.keys(SRC_LABEL)
        .map(k => pillRow(SRC_LABEL[k], (data.by_source[k] || 0))).join('');

      // ตารางช่าง
      const sb = document.getElementById('staffBody');
      sb.innerHTML = (data.by_staff.length === 0)
        ? '<tr><td colspan="3" class="text-muted small">ไม่มีข้อมูลในช่วงนี้</td></tr>'
        : data.by_staff.map(s =>
            '<tr><td><span class="color-dot" style="background:' + esc(s.color_hex) + '"></span>' + esc(s.name) + '</td>' +
            '<td class="text-end">' + s.cnt + '</td><td class="text-end">' + baht(s.revenue) + '</td></tr>'
          ).join('');

      renderMonth(data.by_month);
      renderCat(data.by_category);
    }

    function renderMonth(rows) {
      const labels = rows.map(r => r.ym);
      const revenue = rows.map(r => r.revenue);
      const counts = rows.map(r => r.cnt);
      if (monthChart) monthChart.destroy();
      monthChart = new Chart(document.getElementById('monthChart'), {
        data: {
          labels,
          datasets: [
            { type: 'bar', label: 'มูลค่างาน (บาท)', data: revenue, backgroundColor: '#93c5fd', borderRadius: 6, yAxisID: 'y' },
            { type: 'line', label: 'จำนวนงาน', data: counts, borderColor: '#ec4899', backgroundColor: '#ec4899', tension: 0.3, yAxisID: 'y1' },
          ],
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          scales: {
            y: { beginAtZero: true, position: 'left', title: { display: true, text: 'บาท' } },
            y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'งาน' }, ticks: { precision: 0 } },
          },
        },
      });
    }

    function renderCat(rows) {
      if (catChart) catChart.destroy();
      if (rows.length === 0) {
        catChart = new Chart(document.getElementById('catChart'), { type: 'doughnut', data: { labels: ['ไม่มีข้อมูล'], datasets: [{ data: [1], backgroundColor: ['#e5e7eb'] }] }, options: { responsive: true, maintainAspectRatio: false } });
        return;
      }
      catChart = new Chart(document.getElementById('catChart'), {
        type: 'doughnut',
        data: { labels: rows.map(r => r.name), datasets: [{ data: rows.map(r => r.cnt), backgroundColor: rows.map(r => r.color_hex) }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } },
      });
    }

    document.querySelectorAll('[data-preset]').forEach(b => b.addEventListener('click', () => setPreset(b.dataset.preset)));
    document.getElementById('applyBtn').addEventListener('click', load);
    document.getElementById('staffFilter').addEventListener('change', load);
    document.getElementById('btnPrint').addEventListener('click', () => window.print());
    document.getElementById('btnExport').addEventListener('click', () => {
      const start = document.getElementById('start').value;
      const end = document.getElementById('end').value;
      const staff = document.getElementById('staffFilter').value;
      if (!start || !end) return;
      let url = API_BASE + '/report_export.php?start=' + start + '&end=' + end;
      if (staff) url += '&staff=' + staff;
      window.open(url, '_blank');
    });
    setPreset('this_month');
  </script>
</body>
</html>
