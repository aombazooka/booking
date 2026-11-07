<?php
/**
 * หน้าสรุปคิวงานรายเดือน — ออกแบบสำหรับ "แคปหน้าจอไปประกาศ"
 * แสดงเฉพาะข้อมูลที่เผยแพร่ได้ (วัน/เวลา/ประเภทงาน/ช่าง) ไม่มีเบอร์โทร ราคา หรือที่อยู่
 */
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
  <title>สรุปคิวงานรายเดือน</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
  <link href="<?= htmlspecialchars($base) ?>/assets/app.css?v=<?= assetVer('assets/app.css') ?>" rel="stylesheet">
  <style>
    /* ---------- โปสเตอร์: กว้างคงที่ 760px เพื่อให้ภาพที่แคปได้สัดส่วนเดิมเสมอ ---------- */
    .poster-stage { overflow-x: auto; padding-bottom: 8px; }
    .poster {
      width: 760px; margin-inline: auto;
      background:
        radial-gradient(120% 70% at 12% -8%, #FFFDFB 0%, rgba(255,253,251,0) 60%),
        linear-gradient(160deg, #FDFAF6 0%, #F7EFE8 55%, #F3E5E6 100%);
      border: 1px solid var(--line);
      border-radius: 26px;
      box-shadow: 0 18px 48px rgba(26,23,20,.10);
      padding: 38px 40px 30px;
      position: relative; overflow: hidden;
    }
    /* วงกลมจางๆ มุมขวาบน ให้ดูมีมิติเวลาแคปภาพ */
    .poster::after {
      content: ''; position: absolute; top: -110px; right: -90px;
      width: 300px; height: 300px; border-radius: 50%;
      background: radial-gradient(circle, rgba(183,110,121,.16) 0%, rgba(183,110,121,0) 70%);
    }
    .poster > * { position: relative; z-index: 1; }

    .p-eyebrow { font-family: 'Anuphan', sans-serif; font-size: .74rem; letter-spacing: .22em; text-transform: uppercase; color: var(--rose); font-weight: 600; }
    .p-shop { font-family: 'Fraunces', serif; font-size: 1.45rem; font-weight: 600; color: var(--ink); line-height: 1.2; }
    .p-month { font-family: 'Fraunces', serif; font-size: 3.1rem; font-weight: 600; line-height: 1; color: var(--ink); letter-spacing: -.01em; }
    .p-year { font-family: 'Fraunces', serif; font-size: 1.5rem; font-weight: 400; color: var(--rose); }
    .p-rule { height: 1px; background: linear-gradient(90deg, var(--rose) 0%, rgba(183,110,121,.25) 40%, rgba(183,110,121,0) 100%); margin: 20px 0 22px; }

    /* ---------- แถบตัวเลขสรุป ---------- */
    .p-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
    .p-stat { background: rgba(255,255,255,.72); border: 1px solid rgba(255,255,255,.9); border-radius: 16px; padding: 14px 16px; text-align: center; }
    .p-stat-v { font-family: 'Fraunces', serif; font-size: 1.85rem; font-weight: 600; line-height: 1; color: var(--ink); }
    .p-stat-l { font-size: .78rem; color: var(--ink-soft); margin-top: 5px; }

    /* ---------- ปฏิทินย่อ ---------- */
    .cal { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
    .cal-dow { text-align: center; font-size: .72rem; font-weight: 600; color: var(--ink-soft); letter-spacing: .06em; padding-bottom: 2px; }
    .cal-cell { aspect-ratio: 1 / .92; border-radius: 13px; background: rgba(255,255,255,.55); border: 1px solid rgba(26,23,20,.05);
      display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; }
    .cal-cell.empty { background: transparent; border-color: transparent; }
    .cal-num { font-family: 'Fraunces', serif; font-size: 1.02rem; font-weight: 500; color: var(--ink-soft); line-height: 1; }
    .cal-cell.busy { background: var(--ink); border-color: var(--ink); }
    .cal-cell.busy .cal-num { color: #fff; font-weight: 600; }
    .cal-dots { display: flex; gap: 3px; height: 5px; align-items: center; }
    .cal-dot { width: 5px; height: 5px; border-radius: 50%; }
    .cal-cell.busy .cal-dot { box-shadow: 0 0 0 1px rgba(255,255,255,.35); }

    /* ---------- รายการคิวรายวัน ---------- */
    .day-row { display: flex; gap: 14px; padding: 11px 0; border-bottom: 1px dashed rgba(26,23,20,.10); }
    .day-row:last-child { border-bottom: 0; }
    .day-badge { flex: 0 0 54px; text-align: center; }
    .day-num { font-family: 'Fraunces', serif; font-size: 1.55rem; font-weight: 600; line-height: 1; color: var(--ink); }
    .day-dow { font-size: .7rem; color: var(--rose); font-weight: 600; margin-top: 2px; }
    .job { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 3px 0; }
    .job + .job { margin-top: 3px; }
    .job-time { font-family: 'Fraunces', serif; font-size: .95rem; font-weight: 600; color: var(--ink); min-width: 96px; }
    .job-tag { display: inline-flex; align-items: center; gap: 5px; font-size: .8rem; font-weight: 500; color: var(--ink);
      background: rgba(255,255,255,.75); border: 1px solid rgba(26,23,20,.07); border-radius: 999px; padding: 2px 10px; }
    .job-tag .swatch { width: 8px; height: 8px; border-radius: 50%; }
    .job-meta { font-size: .8rem; color: var(--ink-soft); }
    .empty-note { text-align: center; padding: 34px 0; color: var(--ink-soft); }

    .p-foot { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; margin-top: 24px; padding-top: 16px; border-top: 1px solid rgba(26,23,20,.08); }
    .p-foot-msg { font-family: 'Fraunces', serif; font-size: 1.02rem; color: var(--ink); }
    .p-foot-sub { font-size: .76rem; color: var(--ink-soft); margin-top: 3px; }
    .p-foot-date { font-size: .72rem; color: var(--ink-soft); white-space: nowrap; }

    .sec-title { font-size: .74rem; letter-spacing: .18em; text-transform: uppercase; color: var(--ink-soft); font-weight: 600; margin: 26px 0 12px; }

    /* หน้าจอเล็ก: ย่อโปสเตอร์ทั้งใบลง เพื่อให้ยังเห็นสัดส่วนจริงและแคปได้ */
    @media (max-width: 820px) {
      /* align-items: flex-start สำคัญ — กันไม่ให้โปสเตอร์ถูกยืด/บีบตามความสูงที่ JS ตั้งให้ stage */
      .poster-stage { display: flex; justify-content: flex-start; align-items: flex-start; }
      .poster { transform: scale(var(--poster-scale, 1)); transform-origin: top left; flex: 0 0 auto; }
    }
    @media print {
      .noshot { display: none !important; }
      body { background: #fff; }
      .poster { box-shadow: none; border: 0; }
    }
  </style>
</head>
<body>
  <header class="head py-3 mb-3 noshot">
    <div class="wrap-wide d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <a href="<?= htmlspecialchars($base) ?>/" class="back-btn"><i class="bi bi-arrow-left"></i> กลับ</a>
        <h1 class="brand mb-0 mt-1" style="font-size: 1.35rem; font-weight: 600;">สรุปคิวงานรายเดือน</h1>
        <small class="text-muted">สำหรับแคปหน้าจอไปประกาศคิวงาน</small>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" id="btnPng"><i class="bi bi-image"></i> บันทึกภาพ</button>
        <button class="btn btn-sm btn-outline-secondary" id="btnPrint"><i class="bi bi-printer"></i> พิมพ์</button>
      </div>
    </div>
  </header>

  <div class="wrap-wide pb-5">
    <!-- แถบควบคุม (ไม่ติดไปกับภาพที่แคป) -->
    <div class="card-soft p-3 mb-3 noshot">
      <div class="d-flex flex-wrap gap-2 align-items-end">
        <div class="d-flex align-items-center gap-1">
          <button class="icon-btn" id="prevMonth" aria-label="เดือนก่อน"><i class="bi bi-chevron-left"></i></button>
          <input type="month" id="month" class="form-control form-control-sm" style="min-width:150px;">
          <button class="icon-btn" id="nextMonth" aria-label="เดือนถัดไป"><i class="bi bi-chevron-right"></i></button>
        </div>
        <div>
          <label class="form-label small mb-1">ช่าง</label>
          <select id="staffFilter" class="form-select form-select-sm" style="min-width:140px;">
            <option value="">ทุกช่าง</option>
            <option value="none">ไม่ระบุช่าง</option>
          </select>
        </div>
        <div class="ms-auto d-flex flex-wrap gap-3 align-items-center">
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="optNames">
            <label class="form-check-label small" for="optNames">แสดงชื่อลูกค้า</label>
          </div>
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="optStaff" checked>
            <label class="form-check-label small" for="optStaff">แสดงชื่อช่าง</label>
          </div>
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="optCal" checked>
            <label class="form-check-label small" for="optCal">แสดงปฏิทิน</label>
          </div>
        </div>
      </div>
      <div class="mt-2">
        <label class="form-label small mb-1">ข้อความท้ายโปสเตอร์</label>
        <input type="text" id="footMsg" class="form-control form-control-sm" value="สนใจจองคิว ทักแชทได้เลย" maxlength="80">
      </div>
    </div>

    <!-- โปสเตอร์ (ส่วนที่จะถูกแคป) -->
    <div class="poster-stage">
      <div class="poster" id="poster">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <div class="p-eyebrow">ตารางคิวงาน</div>
            <div class="p-shop" id="pShop">—</div>
          </div>
          <div class="text-end">
            <div class="p-month" id="pMonth">—</div>
            <div class="p-year" id="pYear">—</div>
          </div>
        </div>

        <div class="p-rule"></div>

        <div class="p-stats">
          <div class="p-stat"><div class="p-stat-v" id="sTotal">-</div><div class="p-stat-l">คิวงานทั้งเดือน</div></div>
          <div class="p-stat"><div class="p-stat-v" id="sBusy">-</div><div class="p-stat-l">วันที่มีงาน</div></div>
          <div class="p-stat"><div class="p-stat-v" id="sFree">-</div><div class="p-stat-l">วันที่ยังว่าง</div></div>
        </div>

        <div id="calSection">
          <div class="sec-title">ภาพรวมทั้งเดือน</div>
          <div class="cal" id="calGrid"></div>
        </div>

        <div class="sec-title" id="listTitle">คิวงาน</div>
        <div id="dayList"></div>

        <div class="p-foot">
          <div>
            <div class="p-foot-msg" id="pFootMsg">สนใจจองคิว ทักแชทได้เลย</div>
            <div class="p-foot-sub" id="pFootSub">วันที่ไม่ปรากฏในรายการ = ยังว่างอยู่</div>
          </div>
          <div class="p-foot-date" id="pGen">—</div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
  <script>
    const API_BASE = <?= json_encode($apiBase) ?>;
    const TH_MONTH = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    const TH_DOW_SHORT = ['อา','จ','อ','พ','พฤ','ศ','ส'];
    const esc = s => (s === null || s === undefined ? '' : String(s)).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    let lastData = null, staffFilterReady = false;

    /** ชื่อย่อลูกค้า: "สมหญิง ใจดี" → "สมหญิง ใ." (พอให้รู้ว่าใคร แต่ไม่เปิดเผยเต็ม) */
    function shortName(full) {
      const parts = String(full || '').trim().split(/\s+/);
      if (parts.length === 0 || parts[0] === '') return '';
      return parts.length === 1 ? parts[0] : parts[0] + ' ' + parts[1].charAt(0) + '.';
    }

    async function load() {
      const month = document.getElementById('month').value;
      if (!month) return;
      const staff = document.getElementById('staffFilter').value;
      const url = API_BASE + '/month_summary.php?month=' + month + (staff ? '&staff=' + staff : '');
      const data = await fetch(url).then(r => r.json());
      if (data.error) { alert(data.error); return; }

      if (!staffFilterReady && Array.isArray(data.staff_options)) {
        const sel = document.getElementById('staffFilter');
        data.staff_options.forEach(s => {
          const o = document.createElement('option'); o.value = s.id; o.textContent = s.name; sel.appendChild(o);
        });
        staffFilterReady = true;
      }
      lastData = data;
      render();
    }

    function render() {
      if (!lastData) return;
      const d = lastData;
      const [y, m] = d.month.split('-').map(Number);
      const showNames = document.getElementById('optNames').checked;
      const showStaff = document.getElementById('optStaff').checked;
      const showCal = document.getElementById('optCal').checked;

      document.getElementById('pShop').textContent = d.shop_name || 'ตารางคิวงาน';
      document.getElementById('pMonth').textContent = TH_MONTH[m - 1];
      document.getElementById('pYear').textContent = 'พ.ศ. ' + (y + 543);
      document.getElementById('sTotal').textContent = d.summary.total_bookings.toLocaleString('th-TH');
      document.getElementById('sBusy').textContent = d.summary.busy_days.toLocaleString('th-TH');
      document.getElementById('sFree').textContent = d.summary.free_days.toLocaleString('th-TH');
      document.getElementById('calSection').style.display = showCal ? '' : 'none';

      // ---- ปฏิทินย่อ: จุดสีบอกประเภทงานของวันนั้น (สูงสุด 3 จุด) ----
      const byDate = {};
      d.days.forEach(day => { byDate[day.date] = day.items; });
      const daysInMonth = d.summary.days_in_month;
      const firstDow = new Date(y, m - 1, 1).getDay();
      let cal = TH_DOW_SHORT.map(n => '<div class="cal-dow">' + n + '</div>').join('');
      for (let i = 0; i < firstDow; i++) cal += '<div class="cal-cell empty"></div>';
      for (let dd = 1; dd <= daysInMonth; dd++) {
        const key = d.month + '-' + String(dd).padStart(2, '0');
        const items = byDate[key];
        const colors = [];
        (items || []).forEach(it => (it.categories || []).forEach(c => {
          if (colors.length < 3 && !colors.includes(c.color)) colors.push(c.color);
        }));
        const dots = colors.map(c => '<span class="cal-dot" style="background:' + esc(c) + '"></span>').join('');
        cal += '<div class="cal-cell' + (items ? ' busy' : '') + '">' +
          '<span class="cal-num">' + dd + '</span>' +
          '<span class="cal-dots">' + dots + '</span></div>';
      }
      document.getElementById('calGrid').innerHTML = cal;

      // ---- รายการคิวรายวัน ----
      const list = document.getElementById('dayList');
      document.getElementById('listTitle').textContent = 'คิวงาน ' + TH_MONTH[m - 1];
      if (d.days.length === 0) {
        list.innerHTML = '<div class="empty-note">ยังไม่มีคิวงานในเดือนนี้ — ว่างทั้งเดือน</div>';
      } else {
        list.innerHTML = d.days.map(day => {
          const dt = new Date(day.date + 'T00:00:00');
          const jobs = day.items.map(it => {
            const tags = (it.categories || []).map(c =>
              '<span class="job-tag"><span class="swatch" style="background:' + esc(c.color) + '"></span>' + esc(c.name) + '</span>'
            ).join('');
            const staffTag = (showStaff && it.staff)
              ? '<span class="job-tag"><span class="swatch" style="background:' + esc(it.staff.color) + '"></span>' + esc(it.staff.name) + '</span>'
              : '';
            const nameTag = showNames
              ? '<span class="job-meta">คุณ' + esc(shortName(it.name)) + '</span>'
              : '';
            return '<div class="job"><span class="job-time">' + esc(it.time_label) + '</span>' +
              tags + staffTag + nameTag + '</div>';
          }).join('');
          return '<div class="day-row">' +
            '<div class="day-badge"><div class="day-num">' + dt.getDate() + '</div>' +
            '<div class="day-dow">' + TH_DOW_SHORT[dt.getDay()] + '.</div></div>' +
            '<div class="flex-grow-1">' + jobs + '</div></div>';
        }).join('');
      }

      document.getElementById('pFootMsg').textContent = document.getElementById('footMsg').value;
      document.getElementById('pGen').textContent = 'ข้อมูล ณ ' + new Date().toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
      fitPoster();
    }

    /** จอแคบกว่าโปสเตอร์ (760px) → ย่อลงพอดีจอ ไม่ให้ต้องเลื่อนซ้ายขวา */
    function fitPoster() {
      const stage = document.querySelector('.poster-stage');
      const poster = document.getElementById('poster');
      stage.style.height = '';                       // ปลดความสูงเดิมก่อน แล้วค่อยวัดใหม่
      const avail = stage.clientWidth;
      if (avail >= 760) {
        poster.style.removeProperty('--poster-scale');
        return;
      }
      const scale = avail / 760;
      poster.style.setProperty('--poster-scale', scale);
      // transform ไม่กินพื้นที่ในเลย์เอาต์ → จองความสูงที่ย่อแล้วให้ stage เอง
      stage.style.height = (poster.offsetHeight * scale) + 'px';
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
    ['optNames', 'optStaff', 'optCal'].forEach(id => document.getElementById(id).addEventListener('change', render));
    document.getElementById('footMsg').addEventListener('input', render);
    document.getElementById('btnPrint').addEventListener('click', () => window.print());

    document.getElementById('btnPng').addEventListener('click', async () => {
      const btn = document.getElementById('btnPng');
      const poster = document.getElementById('poster');
      const prevScale = poster.style.getPropertyValue('--poster-scale');
      btn.disabled = true;
      poster.style.removeProperty('--poster-scale'); // แคปที่ขนาดจริงเสมอ ไม่ใช่ขนาดที่ย่อบนจอ
      try {
        await (document.fonts ? document.fonts.ready : Promise.resolve());
        const canvas = await html2canvas(poster, { scale: 2, backgroundColor: '#FBF8F4', useCORS: true });
        const a = document.createElement('a');
        a.download = 'คิวงาน-' + document.getElementById('month').value + '.png';
        a.href = canvas.toDataURL('image/png');
        a.click();
      } catch (e) {
        alert('บันทึกภาพไม่สำเร็จ ลองแคปหน้าจอโดยตรงแทนได้');
      } finally {
        if (prevScale) poster.style.setProperty('--poster-scale', prevScale);
        btn.disabled = false;
      }
    });

    window.addEventListener('resize', fitPoster);

    const now = new Date();
    document.getElementById('month').value = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    load();
  </script>
</body>
</html>
