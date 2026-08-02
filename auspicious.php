<?php
/**
 * ปฏิทินวันฤกษ์ — ดูวันมงคลล่วงหน้าคู่กับคิวงานที่มีอยู่ เพื่อวางแผนรับงาน
 *
 * ข้อมูลในหน้านี้มี 2 ชั้น แยกกันชัดเจน:
 *   1. คำนวณได้ (จันทรคติ + กาลโยค) — ตรวจสอบกับปฏิทินจริงแล้ว
 *   2. กรอกเอง (วันฤกษ์จากโหรที่ร้านเชื่อถือ) — ถือเป็นข้อมูลหลักในการตัดสินใจ
 */
require __DIR__ . '/app/auth.php';
requireLogin();
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$apiBase = $base . '/api';
$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>ปฏิทินวันฤกษ์</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&display=swap" rel="stylesheet">
  <link href="<?= htmlspecialchars($base) ?>/assets/app.css?v=<?= assetVer('assets/app.css') ?>" rel="stylesheet">
  <style>
    .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
    .cal-head { text-align: center; font-size: .78rem; font-weight: 700; color: var(--ink-soft); padding: 6px 0; }
    .cal-head.wknd { color: var(--rose); }
    .day {
      position: relative; min-height: 104px; border-radius: var(--r-sm);
      border: 1px solid var(--line); background: var(--paper);
      padding: 7px 8px 8px; text-align: left; width: 100%;
      display: flex; flex-direction: column; gap: 3px;
      transition: border-color .15s, box-shadow .15s, transform .12s;
    }
    .day:hover { border-color: var(--rose); box-shadow: var(--shadow-sm); }
    .day:active { transform: scale(.99); }
    .day.blank { background: transparent; border-color: transparent; pointer-events: none; }
    .day.today { box-shadow: 0 0 0 2px var(--ink) inset; }
    .day-n { font-family: 'Fraunces', serif; font-size: 1.15rem; font-weight: 600; line-height: 1; color: var(--ink); }
    .day-lunar { font-size: .68rem; color: var(--stone); line-height: 1.25; }
    .day-tags { display: flex; flex-wrap: wrap; gap: 3px; margin-top: auto; }
    .tag { font-size: .62rem; font-weight: 700; border-radius: 999px; padding: 1px 6px; line-height: 1.5; white-space: nowrap; }
    .t-good  { background: #E8F1E6; color: #3F5B3A; }
    .t-bad   { background: #FBEDEC; color: #A4423C; }
    .t-holy  { background: #F1EDF7; color: #5B4A73; }
    .t-mark  { background: var(--ink); color: var(--cream); }
    .t-book  { background: var(--rose-tint); color: var(--rose-deep); }
    /* วันที่ทั้งเป็นฤกษ์ดีและยังไม่มีคิว = โอกาสรับงาน */
    .day.opportunity { background: linear-gradient(160deg, #FFFDF8 0%, #FBF4E9 100%); border-color: #E8D4A8; }
    .legend { display: flex; flex-wrap: wrap; gap: 10px; font-size: .8rem; color: var(--ink-soft); }
    .legend span.sw { width: 11px; height: 11px; border-radius: 3px; display: inline-block; margin-right: 5px; vertical-align: -1px; }
    .stat-row { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; padding: 8px 0; border-bottom: 1px dashed var(--line); }
    .stat-row:last-child { border-bottom: 0; }
    .bar { height: 6px; border-radius: 3px; background: var(--rose); display: block; margin-top: 4px; }
    @media (max-width: 640px) {
      .day { min-height: 76px; padding: 5px; }
      .day-lunar { display: none; }
      .day-n { font-size: 1rem; }
      .tag { font-size: .58rem; padding: 1px 4px; }
      .cal-grid { gap: 3px; }
    }
  </style>
</head>
<body>
  <header class="head py-3 mb-3">
    <div class="wrap-wide d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <a href="<?= htmlspecialchars($base) ?>/" class="back-btn"><i class="bi bi-arrow-left"></i> กลับ</a>
        <h1 class="brand mb-0 mt-1" style="font-size: 1.35rem; font-weight: 600;">ปฏิทินวันฤกษ์</h1>
        <small class="text-muted" id="subLabel">ดูวันมงคลล่วงหน้าคู่กับคิวที่มีอยู่</small>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <button class="icon-btn" id="prevM" aria-label="เดือนก่อน"><i class="bi bi-chevron-left"></i></button>
        <input type="month" id="month" class="form-control form-control-sm" style="min-width:150px;">
        <button class="icon-btn" id="nextM" aria-label="เดือนถัดไป"><i class="bi bi-chevron-right"></i></button>
      </div>
    </div>
  </header>

  <div class="wrap-wide pb-5">
    <!-- คำเตือนเรื่องที่มาของข้อมูล — สำคัญ อย่าลบ -->
    <div class="alert alert-warning d-flex gap-2 align-items-start mb-3" style="border-radius:14px;">
      <i class="bi bi-info-circle mt-1"></i>
      <div class="small">
        <strong>วันธงชัย/อธิบดี/อุบาทว์/โลกาวินาศ</strong> คำนวณจากกาลโยคตามตำราโหราศาสตร์ไทย และ
        <strong>ขึ้น-แรมค่ำ</strong> จากปฏิทินจันทรคติหลวง — ทั้งสองอย่างตรวจสอบกับปฏิทินจริงแล้ว
        แต่ <strong>ฤกษ์มงคลของแต่ละสำนักโหรไม่ตรงกัน</strong> ถ้าจะยึดฤกษ์ของโหรที่คุณเชื่อถือ
        ให้กดที่วันนั้นแล้วบันทึกเป็น "วันฤกษ์" เอง ระบบจะถือว่าอันที่กรอกเองสำคัญกว่า
      </div>
    </div>

    <div class="row g-3">
      <div class="col-12 col-lg-8">
        <div class="card-soft p-3">
          <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
            <h2 class="h6 fw-bold mb-0" id="monthTitle">—</h2>
            <div class="legend">
              <span><span class="sw" style="background:#E8F1E6"></span>ฤกษ์ดี</span>
              <span><span class="sw" style="background:#FBEDEC"></span>ควรเลี่ยง</span>
              <span><span class="sw" style="background:#F1EDF7"></span>วันพระ</span>
              <span><span class="sw" style="background:#E8D4A8"></span>ฤกษ์ดี+ยังว่าง</span>
            </div>
          </div>
          <div class="cal-grid mb-1" id="calHead"></div>
          <div class="cal-grid" id="calGrid"></div>
          <div class="text-muted mt-2" style="font-size:.78rem;" id="yearNote"></div>
        </div>
      </div>

      <div class="col-12 col-lg-4">
        <div class="card-soft p-3 mb-3">
          <h2 class="h6 fw-bold mb-1">สรุปเดือนนี้</h2>
          <div id="monthSummary" class="small text-muted">—</div>
        </div>
        <div class="card-soft p-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <h2 class="h6 fw-bold mb-0">สถิติย้อนหลัง</h2>
            <select id="backMonths" class="form-select form-select-sm" style="width:auto;">
              <option value="6">6 เดือน</option>
              <option value="12" selected>12 เดือน</option>
              <option value="24">24 เดือน</option>
            </select>
          </div>
          <div class="text-muted mb-2" style="font-size:.78rem;">เฉลี่ยงานต่อวัน แยกตามชนิดของวัน — ยิ่งสูงยิ่งเป็นวันที่งานเข้าเยอะ</div>
          <div id="statsBox" class="small">—</div>
        </div>
      </div>
    </div>
  </div>

  <!-- โมดัลบันทึกวันฤกษ์ -->
  <div class="modal fade" id="dayModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header border-0 pb-1">
          <div>
            <div class="modal-title fw-bold" id="mTitle">—</div>
            <div class="text-muted small" id="mLunar">—</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
        </div>
        <div class="modal-body pt-2">
          <div id="mInfo" class="mb-3"></div>
          <hr class="my-3">
          <div class="fw-bold mb-2" style="font-size:.92rem;">บันทึกวันฤกษ์เอง</div>
          <div class="mb-2">
            <label class="form-label small mb-1">ประเภท</label>
            <select id="mGrade" class="form-select form-select-sm">
              <option value="great">วันมหาฤกษ์</option>
              <option value="good" selected>วันฤกษ์ดี</option>
              <option value="avoid">วันควรเลี่ยง</option>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label small mb-1">ชื่อฤกษ์</label>
            <input type="text" id="mLabel" class="form-control form-control-sm" placeholder="เช่น ฤกษ์แต่งงาน, ฤกษ์ขึ้นบ้านใหม่" maxlength="100">
          </div>
          <div class="mb-2">
            <label class="form-label small mb-1">ที่มา <span class="text-muted">(ไว้ตรวจย้อนหลังว่าใครให้ฤกษ์)</span></label>
            <input type="text" id="mSource" class="form-control form-control-sm" placeholder="เช่น ปฏิทินหลวง, อ.สมชาย" maxlength="120">
          </div>
          <div class="mb-3">
            <label class="form-label small mb-1">หมายเหตุ</label>
            <textarea id="mNote" class="form-control form-control-sm" rows="2"></textarea>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-primary flex-grow-1" id="mSave"><i class="bi bi-check-lg"></i> บันทึก</button>
            <button class="btn btn-sm btn-outline-danger d-none" id="mDelete"><i class="bi bi-trash"></i> ลบ</button>
            <a class="btn btn-sm btn-outline-secondary" id="mAddBooking" href="#"><i class="bi bi-plus-lg"></i> เพิ่มคิว</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const API_BASE = <?= json_encode($apiBase) ?>;
    const BASE = <?= json_encode($base) ?>;
    const CSRF = <?= json_encode($csrf) ?>;
    const DOW = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
    const TH_MONTH = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    const GRADE_LABEL = { great: 'วันมหาฤกษ์', good: 'วันฤกษ์ดี', avoid: 'วันควรเลี่ยง' };
    const GOOD = ['ธงชัย', 'อธิบดี'], BAD = ['อุบาทว์', 'โลกาวินาศ'];
    const esc = s => (s === null || s === undefined ? '' : String(s)).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const dayModal = new bootstrap.Modal(document.getElementById('dayModal'));
    let current = null, currentDay = null;

    document.getElementById('calHead').innerHTML = DOW
      .map((d, i) => '<div class="cal-head' + (i === 0 || i === 6 ? ' wknd' : '') + '">' + d + '</div>').join('');

    async function load() {
      const month = document.getElementById('month').value;
      if (!month) return;
      const data = await fetch(API_BASE + '/auspicious.php?month=' + month).then(r => r.json());
      if (data.error) { alert(data.error); return; }
      current = data;
      render();
    }

    function render() {
      const d = current;
      const [y, m] = d.month.split('-').map(Number);
      document.getElementById('monthTitle').textContent = TH_MONTH[m - 1] + ' ' + (y + 543);

      const ly = d.lunar_year;
      document.getElementById('yearNote').textContent =
        'ปี' + (ly ? ly.zodiac : '-') + ' · จ.ศ. ' + d.chulasakarat +
        (ly && ly.atikamas ? ' · ปีอธิกมาส (มีเดือน 8 สองหน)' : '') +
        (ly && ly.atikawan ? ' · ปีอธิกวาร (เดือน 7 มี 30 วัน)' : '');

      const todayStr = new Date().toLocaleDateString('sv-SE'); // YYYY-MM-DD ตามเวลาเครื่อง
      let html = '';
      for (let i = 0; i < d.days[0].dow; i++) html += '<div class="day blank"></div>';

      d.days.forEach(day => {
        const good = day.kalayok.filter(t => GOOD.includes(t));
        const bad = day.kalayok.filter(t => BAD.includes(t));
        const marked = day.mark;
        // "โอกาสรับงาน" = เป็นฤกษ์ดี (คำนวณหรือกรอกเอง) และยังไม่มีคิวเลย
        const isGoodDay = (marked && marked.grade !== 'avoid') || (good.length > 0 && bad.length === 0);
        const opportunity = isGoodDay && day.bookings === 0;

        let tags = '';
        if (marked) tags += '<span class="tag t-mark">' + esc(marked.label) + '</span>';
        good.forEach(t => tags += '<span class="tag t-good">' + t + '</span>');
        bad.forEach(t => tags += '<span class="tag t-bad">' + t + '</span>');
        if (day.lunar && day.lunar.is_holy) tags += '<span class="tag t-holy">พระ</span>';
        if (day.bookings > 0) tags += '<span class="tag t-book">' + day.bookings + ' คิว</span>';

        html += '<button type="button" class="day' + (opportunity ? ' opportunity' : '') +
          (day.date === todayStr ? ' today' : '') + '" data-date="' + day.date + '">' +
          '<span class="day-n">' + day.day + '</span>' +
          '<span class="day-lunar">' + (day.lunar ? esc(day.lunar.phase + ' ' + day.lunar.day + ' ค่ำ') : '') + '</span>' +
          '<span class="day-tags">' + tags + '</span>' +
          '</button>';
      });
      document.getElementById('calGrid').innerHTML = html;
      document.querySelectorAll('.day[data-date]').forEach(b =>
        b.addEventListener('click', () => openDay(b.dataset.date)));

      // สรุปเดือน
      const marks = d.days.filter(x => x.mark && x.mark.grade !== 'avoid');
      const marksFree = marks.filter(x => x.bookings === 0);
      const goodDays = d.days.filter(x => {
        const g = x.kalayok.filter(t => GOOD.includes(t)), b = x.kalayok.filter(t => BAD.includes(t));
        return g.length > 0 && b.length === 0;
      });
      const busiest = d.days.reduce((a, x) => (x.bookings > (a ? a.bookings : 0) ? x : a), null);
      const overStaff = d.days.filter(x => d.staff_total > 0 && x.bookings > d.staff_total);
      document.getElementById('monthSummary').innerHTML =
        row('วันฤกษ์ที่บันทึกเอง', marks.length + ' วัน') +
        row('— ในนั้นยังไม่มีคิว', '<strong>' + marksFree.length + ' วัน</strong>') +
        row('วันฤกษ์ดีตามกาลโยค', goodDays.length + ' วัน') +
        row('วันที่มีคิวมากสุด', busiest && busiest.bookings > 0
            ? (busiest.day + ' — ' + busiest.bookings + ' คิว') : 'ยังไม่มีคิว') +
        row('ช่างที่เปิดใช้งาน', d.staff_total + ' คน') +
        (overStaff.length > 0
          ? '<div class="alert alert-warning mt-2 mb-0 py-2 px-2" style="font-size:.78rem;border-radius:10px;">' +
            '<i class="bi bi-exclamation-triangle"></i> มี ' + overStaff.length +
            ' วันที่คิวมากกว่าจำนวนช่าง (วันที่ ' + overStaff.map(x => x.day).join(', ') + ') — อาจต้องหาช่างเสริม</div>'
          : '');
    }

    function row(k, v) {
      return '<div class="stat-row"><span>' + k + '</span><span>' + v + '</span></div>';
    }

    function openDay(dateStr) {
      const day = current.days.find(x => x.date === dateStr);
      if (!day) return;
      currentDay = day;
      const dt = new Date(dateStr + 'T00:00:00');
      const full = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'][dt.getDay()];
      document.getElementById('mTitle').textContent =
        'วัน' + full + 'ที่ ' + dt.getDate() + ' ' + TH_MONTH[dt.getMonth()] + ' ' + (dt.getFullYear() + 543);
      document.getElementById('mLunar').textContent = day.lunar ? day.lunar.label : 'คำนวณจันทรคติไม่ได้';

      const good = day.kalayok.filter(t => GOOD.includes(t));
      const bad = day.kalayok.filter(t => BAD.includes(t));
      let info = '<div class="d-flex flex-wrap gap-1 mb-2">';
      good.forEach(t => info += '<span class="tag t-good">วัน' + t + '</span>');
      bad.forEach(t => info += '<span class="tag t-bad">วัน' + t + '</span>');
      if (day.lunar && day.lunar.is_holy) info += '<span class="tag t-holy">วันพระ</span>';
      if (good.length === 0 && bad.length === 0) info += '<span class="text-muted small">ไม่เข้าเกณฑ์กาลโยคใด</span>';
      info += '</div>';
      info += '<div class="small text-muted">คิวในวันนี้: <strong class="text-dark">' + day.bookings + ' คิว</strong>' +
        (day.staff_names ? ' (' + esc(day.staff_names) + ')' : '') + '</div>';
      document.getElementById('mInfo').innerHTML = info;

      const mk = day.mark;
      document.getElementById('mGrade').value = mk ? mk.grade : 'good';
      document.getElementById('mLabel').value = mk ? mk.label : '';
      document.getElementById('mSource').value = mk && mk.source ? mk.source : '';
      document.getElementById('mNote').value = mk && mk.note ? mk.note : '';
      document.getElementById('mDelete').classList.toggle('d-none', !mk);
      document.getElementById('mAddBooking').href = BASE + '/form.php?date=' + dateStr;
      dayModal.show();
    }

    document.getElementById('mSave').addEventListener('click', async () => {
      const body = {
        date: currentDay.date,
        grade: document.getElementById('mGrade').value,
        label: document.getElementById('mLabel').value.trim() || GRADE_LABEL[document.getElementById('mGrade').value],
        source: document.getElementById('mSource').value.trim(),
        note: document.getElementById('mNote').value.trim(),
        csrf: CSRF,
      };
      const r = await fetch(API_BASE + '/auspicious.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify(body),
      }).then(x => x.json());
      if (r.error) { alert(r.error); return; }
      dayModal.hide(); await load(); loadStats();
    });

    document.getElementById('mDelete').addEventListener('click', async () => {
      if (!confirm('ลบวันฤกษ์ของวันนี้?')) return;
      const r = await fetch(API_BASE + '/auspicious.php?date=' + currentDay.date, {
        method: 'DELETE', headers: { 'X-CSRF-Token': CSRF },
      }).then(x => x.json());
      if (r.error) { alert(r.error); return; }
      dayModal.hide(); await load(); loadStats();
    });

    async function loadStats() {
      const back = document.getElementById('backMonths').value;
      const d = await fetch(API_BASE + '/auspicious.php?stats=1&back=' + back).then(r => r.json());
      if (d.error) return;
      const box = document.getElementById('statsBox');
      if (!d.buckets || d.buckets.length === 0 || d.total_bookings === 0) {
        box.innerHTML = '<div class="text-muted">ยังไม่มีข้อมูลงานย้อนหลังพอจะสรุปได้</div>';
        return;
      }
      const max = Math.max(...d.buckets.map(b => b.avg_per_day)) || 1;
      box.innerHTML = d.buckets.map(b =>
        '<div class="stat-row d-block">' +
          '<div class="d-flex justify-content-between"><span>' + esc(b.label) + '</span>' +
          '<span><strong>' + b.avg_per_day.toFixed(2) + '</strong> <span class="text-muted">งาน/วัน</span></span></div>' +
          '<span class="bar" style="width:' + Math.max(2, (b.avg_per_day / max) * 100) + '%"></span>' +
          '<div class="text-muted" style="font-size:.72rem;">' + b.bookings + ' งาน จาก ' + b.days + ' วัน</div>' +
        '</div>').join('') +
        '<div class="text-muted mt-2" style="font-size:.72rem;">อ้างอิงงานทั้งหมด ' + d.total_bookings +
        ' รายการ ตั้งแต่ ' + d.range.start + ' ถึง ' + d.range.end + '</div>';
    }

    function shiftMonth(delta) {
      const el = document.getElementById('month');
      const [y, m] = el.value.split('-').map(Number);
      const dt = new Date(y, m - 1 + delta, 1);
      el.value = dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0');
      load();
    }
    document.getElementById('prevM').addEventListener('click', () => shiftMonth(-1));
    document.getElementById('nextM').addEventListener('click', () => shiftMonth(1));
    document.getElementById('month').addEventListener('change', load);
    document.getElementById('backMonths').addEventListener('change', loadStats);

    const now = new Date();
    document.getElementById('month').value = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    load(); loadStats();
  </script>
</body>
</html>
