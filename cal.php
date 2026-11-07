<?php
/**
 * ปฏิทินคิวงานสาธารณะต่อร้าน (ดูอย่างเดียว ไม่ต้องล็อกอิน) — ?shop=slug
 * ใช้ส่งต่อให้ลูกค้า/ช่าง ดูตารางคิวงาน ไม่แสดงชื่อลูกค้า/เบอร์/ราคา
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/auth.php';
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
  <title><?= $shopOk ? htmlspecialchars($shopName) . ' — ตารางคิวงาน' : 'ตารางคิวงาน' ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&display=swap" rel="stylesheet">
  <link href="<?= htmlspecialchars($base) ?>/assets/app.css?v=<?= assetVer('assets/app.css') ?>" rel="stylesheet">
  <style>
    .cal { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; }
    .cal-dow { text-align: center; font-size: .72rem; font-weight: 600; color: var(--stone); padding-bottom: 2px; }
    .cal-cell { aspect-ratio: 1 / .96; border-radius: 12px; background: var(--paper); border: 1px solid var(--line);
      display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; cursor: default; }
    .cal-cell.empty { background: transparent; border-color: transparent; }
    .cal-cell.today { outline: 2px solid var(--rose); outline-offset: -2px; }
    .cal-num { font-family: 'Fraunces', serif; font-size: 1rem; font-weight: 500; color: var(--stone); line-height: 1; }
    .cal-cell.busy { background: var(--ink); border-color: var(--ink); cursor: pointer; }
    .cal-cell.busy .cal-num { color: #fff; font-weight: 600; }
    .cal-dots { display: flex; gap: 3px; height: 5px; align-items: center; }
    .cal-dot { width: 5px; height: 5px; border-radius: 50%; }
    .cal-cell.busy .cal-dot { box-shadow: 0 0 0 1px rgba(255,255,255,.35); }
    /* สถานะรายวัน (ว่าง/เต็ม/หยุด) — เฉพาะวันนี้เป็นต้นไป */
    .cal-cell.st-open   { background: #f0f7f1; border-color: #cfe6d3; }
    .cal-cell.st-full   { background: #fbeceb; border-color: #f0cfcc; cursor: pointer; }
    .cal-cell.st-closed { background: #f3f2f0; border-color: var(--line); opacity: .62; }
    .cal-stat { font-size: .58rem; font-weight: 700; line-height: 1; letter-spacing: .01em; }
    .cal-cell.st-open   .cal-stat { color: #2e7d32; }
    .cal-cell.st-full   .cal-stat { color: #c0392b; }
    .cal-cell.st-closed .cal-stat { color: var(--stone); }
    .cal-legend { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 12px; font-size: .78rem; color: var(--stone); }
    .cal-legend span { display: inline-flex; align-items: center; gap: 5px; }
    .lg-sw { width: 11px; height: 11px; border-radius: 4px; border: 1px solid var(--line); }
    .lg-open { background: #f0f7f1; border-color: #cfe6d3; }
    .lg-full { background: #fbeceb; border-color: #f0cfcc; }
    .lg-closed { background: #f3f2f0; }

    .day-row { display: flex; gap: 14px; padding: 12px 0; border-bottom: 1px dashed var(--line); }
    .day-row:last-child { border-bottom: 0; }
    .day-badge { flex: 0 0 48px; text-align: center; }
    .day-num { font-family: 'Fraunces', serif; font-size: 1.5rem; font-weight: 600; line-height: 1; color: var(--ink); }
    .day-dow { font-size: .7rem; color: var(--rose); font-weight: 600; margin-top: 2px; }
    .job { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 3px 0; }
    .job + .job { margin-top: 3px; }
    .job-time { font-family: 'Fraunces', serif; font-size: .95rem; font-weight: 600; color: var(--ink); min-width: 92px; }
    .job-tag { display: inline-flex; align-items: center; gap: 5px; font-size: .8rem; font-weight: 500; color: var(--ink);
      background: var(--cream-2); border: 1px solid var(--line); border-radius: 999px; padding: 2px 10px; }
    .job-tag .swatch { width: 8px; height: 8px; border-radius: 50%; }
    .empty-note { text-align: center; padding: 34px 0; color: var(--stone); }
  </style>
</head>
<body>
  <header class="head py-3 mb-3">
    <div class="wrap d-flex align-items-center gap-2">
      <span class="logo-badge" style="width:46px;height:46px;font-size:1.15rem;"><i class="bi bi-calendar-week"></i></span>
      <div>
        <div class="brand" style="font-size: 1.3rem; font-weight: 600;"><?= $shopOk ? htmlspecialchars($shopName) : 'ตารางคิวงาน' ?></div>
        <small class="text-muted"><?= $shopOk ? 'ตารางคิวงาน (ดูอย่างเดียว)' : 'ไม่พบร้าน' ?></small>
      </div>
    </div>
  </header>

  <?php if (!$shopOk): ?>
  <div class="wrap pb-5 rise">
    <div class="card-soft p-4 text-center">
      <div style="font-size: 2.5rem;">🔎</div>
      <h2 class="h6 fw-bold mt-2">ไม่พบตารางคิวงานของร้านนี้</h2>
      <p class="text-muted mb-0">โปรดเปิดลิงก์ปฏิทินที่ทางร้านให้มา</p>
    </div>
  </div>
  <?php else: ?>
  <div class="wrap pb-5 rise">
    <div class="card-soft p-3 mb-3">
      <div class="d-flex flex-wrap gap-2 align-items-end">
        <div class="d-flex align-items-center gap-1">
          <button class="icon-btn" id="prevMonth" aria-label="เดือนก่อน"><i class="bi bi-chevron-left"></i></button>
          <input type="month" id="month" class="form-control form-control-sm" style="min-width:150px;">
          <button class="icon-btn" id="nextMonth" aria-label="เดือนถัดไป"><i class="bi bi-chevron-right"></i></button>
        </div>
        <div class="ms-auto">
          <label class="form-label small mb-1">ช่าง</label>
          <select id="staffFilter" class="form-select form-select-sm" style="min-width:140px;">
            <option value="">ทุกช่าง</option>
            <option value="none">ไม่ระบุช่าง</option>
          </select>
        </div>
      </div>
    </div>

    <div class="card-soft p-3 p-md-4 mb-3">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 fw-bold mb-0" id="calTitle">—</h2>
        <span class="text-muted small" id="calSummary"></span>
      </div>
      <div class="cal" id="calGrid"></div>
      <div class="cal-legend">
        <span><i class="lg-sw lg-open"></i> ว่าง (จองได้)</span>
        <span><i class="lg-sw lg-full"></i> เต็ม</span>
        <span><i class="lg-sw lg-closed"></i> หยุด</span>
      </div>
    </div>

    <div class="card-soft p-3 p-md-4">
      <h2 class="h6 fw-bold mb-2" id="listTitle">คิวงาน</h2>
      <div id="dayList"></div>
    </div>
  </div>
  <?php endif; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <?php if ($shopOk): ?>
  <script>
    const API_BASE = <?= json_encode($apiBase) ?>;
    const SHOP = <?= json_encode($shopSlug) ?>;
    const TH_MONTH = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    const TH_DOW = ['อา','จ','อ','พ','พฤ','ศ','ส'];
    const esc = s => (s === null || s === undefined ? '' : String(s)).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const todayKey = new Date().toISOString().slice(0, 10);
    let lastData = null, staffReady = false;

    async function load() {
      const month = document.getElementById('month').value;
      if (!month) return;
      const staff = document.getElementById('staffFilter').value;
      const url = API_BASE + '/month_summary.php?shop=' + encodeURIComponent(SHOP) + '&month=' + month + '&days_status=1' + (staff ? '&staff=' + staff : '');
      let data;
      try { data = await fetch(url).then(r => r.json()); } catch (e) { return; }
      if (data.error) { return; }
      if (!staffReady && Array.isArray(data.staff_options)) {
        const sel = document.getElementById('staffFilter');
        data.staff_options.forEach(s => { const o = document.createElement('option'); o.value = s.id; o.textContent = s.name; sel.appendChild(o); });
        staffReady = true;
      }
      lastData = data;
      render();
    }

    function render() {
      if (!lastData) return;
      const d = lastData;
      const [y, m] = d.month.split('-').map(Number);
      document.getElementById('calTitle').textContent = TH_MONTH[m - 1] + ' ' + (y + 543);
      document.getElementById('calSummary').textContent = d.summary.total_bookings > 0
        ? (d.summary.total_bookings + ' คิว · ว่าง ' + d.summary.free_days + ' วัน') : 'ยังไม่มีคิว';

      const byDate = {};
      d.days.forEach(day => { byDate[day.date] = day.items; });
      const status = d.day_status || {};
      const STAT_LABEL = { open: 'ว่าง', full: 'เต็ม', closed: 'หยุด' };
      const firstDow = new Date(y, m - 1, 1).getDay();
      let cal = TH_DOW.map(n => '<div class="cal-dow">' + n + '</div>').join('');
      for (let i = 0; i < firstDow; i++) cal += '<div class="cal-cell empty"></div>';
      for (let dd = 1; dd <= d.summary.days_in_month; dd++) {
        const key = d.month + '-' + String(dd).padStart(2, '0');
        const items = byDate[key];
        const st = status[key];                 // {s:'open'|'full'|'closed', r?} เฉพาะวันนี้เป็นต้นไป
        const colors = [];
        (items || []).forEach(it => (it.categories || []).forEach(c => { if (colors.length < 3 && !colors.includes(c.color)) colors.push(c.color); }));
        const dots = colors.map(c => '<span class="cal-dot" style="background:' + esc(c) + '"></span>').join('');
        // วันในอดีตที่มีคิว = ดำ (busy); วันนี้เป็นต้นไป = สีตามสถานะ
        const cls = st ? ('st-' + st.s) : (items ? 'busy' : '');
        const clickable = items ? ' onclick="scrollToDay(\'' + key + '\')"' : '';
        const statLabel = st ? '<span class="cal-stat">' + STAT_LABEL[st.s] + '</span>' : '';
        cal += '<div class="cal-cell' + (cls ? ' ' + cls : '') + (key === todayKey ? ' today' : '') + '"' + clickable + '>' +
          '<span class="cal-num">' + dd + '</span><span class="cal-dots">' + dots + '</span>' + statLabel + '</div>';
      }
      document.getElementById('calGrid').innerHTML = cal;

      const list = document.getElementById('dayList');
      document.getElementById('listTitle').textContent = 'คิวงาน ' + TH_MONTH[m - 1];
      if (d.days.length === 0) {
        list.innerHTML = '<div class="empty-note">ยังไม่มีคิวงานในเดือนนี้ — ว่างทั้งเดือน</div>';
      } else {
        list.innerHTML = d.days.map(day => {
          const dt = new Date(day.date + 'T00:00:00');
          const jobs = day.items.map(it => {
            const tags = (it.categories || []).map(c =>
              '<span class="job-tag"><span class="swatch" style="background:' + esc(c.color) + '"></span>' + esc(c.name) + '</span>').join('');
            const staffTag = it.staff
              ? '<span class="job-tag"><span class="swatch" style="background:' + esc(it.staff.color) + '"></span>' + esc(it.staff.name) + '</span>' : '';
            return '<div class="job"><span class="job-time">' + esc(it.time_label) + '</span>' + tags + staffTag + '</div>';
          }).join('');
          return '<div class="day-row" id="day-' + day.date + '">' +
            '<div class="day-badge"><div class="day-num">' + dt.getDate() + '</div><div class="day-dow">' + TH_DOW[dt.getDay()] + '.</div></div>' +
            '<div class="flex-grow-1">' + jobs + '</div></div>';
        }).join('');
      }
    }

    function scrollToDay(key) {
      const el = document.getElementById('day-' + key);
      if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); el.style.transition = 'background .3s'; el.style.background = 'var(--rose-tint)'; setTimeout(() => el.style.background = '', 900); }
    }

    function shiftMonth(delta) {
      const el = document.getElementById('month');
      const [y, m] = el.value.split('-').map(Number);
      const dt = new Date(y, m - 1 + delta, 1);
      el.value = dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0');
      load();
    }
    document.getElementById('prevMonth').addEventListener('click', () => shiftMonth(-1));
    document.getElementById('nextMonth').addEventListener('click', () => shiftMonth(1));
    document.getElementById('month').addEventListener('change', load);
    document.getElementById('staffFilter').addEventListener('change', load);

    const now = new Date();
    document.getElementById('month').value = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    load();
  </script>
  <?php endif; ?>
</body>
</html>
