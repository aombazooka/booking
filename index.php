<?php
require __DIR__ . '/app/auth.php';
requireLogin();
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$apiBase = $base . '/api';

// โหมดซูเปอร์แอดมินสลับเข้าไปจัดการร้านอื่น
$actingShop = actingShopId();
$actingShopName = '';
if ($actingShop > 0) {
    try {
        $pdo = require __DIR__ . '/app/db.php';
        $s = $pdo->prepare("SELECT shop_name FROM app_users WHERE id = ?");
        $s->execute([$actingShop]);
        $actingShopName = (string) $s->fetchColumn();
    } catch (Throwable $e) { /* ข้าม */ }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>แอปจองคิว</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&display=swap" rel="stylesheet">
  <link href="<?= htmlspecialchars($base) ?>/assets/app.css?v=<?= assetVer('assets/app.css') ?>" rel="stylesheet">
  <style>
    .container-wrap { max-width: 1140px; margin-inline: auto; padding: 18px 24px; }
    @media (max-width: 768px) {
      .container-wrap { padding: 12px; }
      .fc-toolbar { flex-direction: column; gap: .5rem; align-items: center; }
      .fc-toolbar-chunk { display: flex; justify-content: center; }
      .fc .fc-button { padding: .3rem .55rem; font-size: 0.85rem; }
    }
    @media (max-width: 380px) { .fab .fab-text { display: none; } .fab { padding: 0; min-width: 56px; } }
    /* จอกว้าง: แสดงคิววันนี้ + ปฏิทินคู่กัน, ซ่อนปุ่มสลับมุมมอง */
    @media (min-width: 992px) {
      .view-toggle { display: none !important; }
      #dashGrid { display: flex; gap: 16px; align-items: flex-start; }
      #dashGrid #todaySection { flex: 0 0 380px; margin-bottom: 0; }
      #dashGrid #calendarSection { flex: 1 1 auto; display: block !important; margin-bottom: 0; }
    }
  </style>
</head>
<body>
  <header class="topbar py-3">
    <div class="container-wrap container-fluid d-flex justify-content-between align-items-center">
      <div>
        <div class="eyebrow">แอปจองคิว</div>
        <div class="brand title" style="font-size: 1.25rem; font-weight: 600;">ตารางคิวงาน</div>
        <div class="subtitle">ระบบจัดการคิวงาน</div>
      </div>
      <div class="dropdown">
        <button class="icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="เมนู">
          <i class="bi bi-list" style="font-size:1.35rem;"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="<?= htmlspecialchars($base) ?>/monthly.php"><i class="bi bi-calendar2-week me-2"></i> สรุปคิวงานรายเดือน</a></li>
          <li><a class="dropdown-item" href="<?= htmlspecialchars($base) ?>/auspicious.php"><i class="bi bi-stars me-2"></i> ปฏิทินวันฤกษ์</a></li>
          <li><a class="dropdown-item" href="<?= htmlspecialchars($base) ?>/customers.php"><i class="bi bi-person-lines-fill me-2"></i> ลูกค้า</a></li>
          <li><a class="dropdown-item" href="<?= htmlspecialchars($base) ?>/report.php"><i class="bi bi-graph-up me-2"></i> รายงานสรุป</a></li>
          <li><a class="dropdown-item" href="<?= htmlspecialchars($base) ?>/expenses.php"><i class="bi bi-wallet2 me-2"></i> รายจ่ายและกำไร</a></li>
          <li><a class="dropdown-item" href="<?= htmlspecialchars($base) ?>/admin/services.php"><i class="bi bi-gear me-2"></i> จัดการบริการ &amp; ช่าง</a></li>
          <li><a class="dropdown-item" href="<?= htmlspecialchars($base) ?>/settings.php"><i class="bi bi-shop me-2"></i> ตั้งค่าร้าน &amp; วันหยุด</a></li>
          <li><a class="dropdown-item" href="<?= htmlspecialchars($base) ?>/telegram.php"><i class="bi bi-bell me-2"></i> แจ้งเตือน Telegram / LINE</a></li>
          <li><a class="dropdown-item" href="<?= htmlspecialchars($base) ?>/account.php"><i class="bi bi-key me-2"></i> เปลี่ยนรหัสผ่าน</a></li>
          <?php if (isSuperAdmin()): ?>
          <li><a class="dropdown-item" href="<?= htmlspecialchars($base) ?>/admin/users.php"><i class="bi bi-people me-2"></i> จัดการผู้ใช้ / อนุมัติร้าน</a></li>
          <?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="<?= htmlspecialchars($base) ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i> ออกจากระบบ</a></li>
        </ul>
      </div>
    </div>
  </header>

  <?php if ($actingShop > 0): ?>
  <div class="container-wrap" style="max-width:1140px;margin-inline:auto;padding:12px 24px 0;">
    <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2 mb-0" style="border-radius:14px;">
      <span><i class="bi bi-shield-lock"></i> โหมดแอดมิน — กำลังจัดการร้าน: <strong><?= htmlspecialchars($actingShopName ?: ('#' . $actingShop)) ?></strong></span>
      <form method="post" action="<?= htmlspecialchars($base) ?>/admin/users.php" class="m-0">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken()) ?>">
        <input type="hidden" name="id" value="0">
        <button name="action" value="manage" class="btn btn-sm btn-dark"><i class="bi bi-box-arrow-left"></i> ออกจากโหมดจัดการร้าน</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <main class="container-wrap container-fluid py-3 pb-5">
    <div class="soft-card p-3 mb-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <strong style="font-size: 1rem;">มุมมองคิวงาน</strong>
        <div class="btn-group btn-group-sm view-toggle" role="group">
          <button class="btn btn-outline-secondary active" id="btnToday" type="button">วันนี้</button>
          <button class="btn btn-outline-secondary" id="btnCalendar" type="button">ปฏิทิน</button>
        </div>
      </div>
      <div class="d-flex gap-2 flex-wrap" id="statusFilters">
        <button type="button" class="chip chip-filter active" data-status="" aria-pressed="true">ทั้งหมด</button>
        <button type="button" class="chip chip-filter" data-status="new"><span class="chip-dot" style="background:#93c5fd"></span>ใหม่</button>
        <button type="button" class="chip chip-filter" data-status="confirmed"><span class="chip-dot" style="background:#a7f3d0"></span>ยืนยันแล้ว</button>
        <button type="button" class="chip chip-filter" data-status="done"><span class="chip-dot" style="background:#ddd6fe"></span>เสร็จงาน</button>
        <button type="button" class="chip chip-filter" data-status="cancelled"><span class="chip-dot" style="background:#fecdd3"></span>ยกเลิก</button>
      </div>
      <div class="d-flex gap-2 align-items-center flex-wrap mt-2">
        <span class="small text-muted">ช่าง:</span>
        <select id="staffFilterSel" class="form-select form-select-sm" style="width:auto; min-width:130px;">
          <option value="">ทุกช่าง</option>
          <option value="none">ไม่ระบุช่าง</option>
        </select>
        <span id="staffLegend" class="d-flex gap-2 flex-wrap small"></span>
      </div>
    </div>

    <div id="dashGrid">
      <section class="soft-card p-3 mb-3" id="todaySection">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="mb-0" style="font-size: 1rem; font-weight: 600;" id="todaySectionTitle">คิววันนี้</h6>
          <span class="text-muted small" id="todayDate"></span>
        </div>
        <div id="todayList" class="mt-2"></div>
      </section>

      <section class="soft-card p-2 mb-3 d-none" id="calendarSection">
        <div id="calendar"></div>
      </section>
    </div>
  </main>

  <a href="<?= htmlspecialchars($base) ?>/form.php" class="fab" role="button"><i class="bi bi-plus-lg"></i> <span class="fab-text">เพิ่มคิว</span></a>

  <div class="modal fade" id="eventModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content" style="border-radius:16px;">
        <div class="modal-header border-0 pb-0">
          <h5 class="modal-title" style="font-size: 1.125rem;">รายละเอียดคิวงาน</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="eventDetail"></div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/locales/th.global.min.js"></script>
  <script>
    const API_BASE = <?= json_encode($apiBase) ?>;
    const CSRF = <?= json_encode(csrfToken()) ?>;
    const eventModal = new bootstrap.Modal(document.getElementById('eventModal'));
    const todayList = document.getElementById('todayList');
    const todayDate = document.getElementById('todayDate');
    const todaySectionTitle = document.getElementById('todaySectionTitle');
    const todaySection = document.getElementById('todaySection');
    const calendarSection = document.getElementById('calendarSection');
    const btnToday = document.getElementById('btnToday');
    const btnCalendar = document.getElementById('btnCalendar');

    // วันที่ตามเวลาเครื่องผู้ใช้ (toISOString เป็น UTC — ตี 0-7 จะได้วันก่อนหน้า)
    function localISO(d = new Date()) {
      return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    let viewListDate = localISO(); // วันที่ที่กำลังแสดงในมุม "รายการ"

    function formatThaiDate(isoDate) {
      const d = new Date(isoDate + 'T12:00:00');
      return d.toLocaleDateString('th-TH', { dateStyle: 'full' });
    }

    function isToday(isoDate) {
      return isoDate === localISO();
    }

    function setListHeader(isoDate) {
      if (isToday(isoDate)) {
        todaySectionTitle.textContent = 'คิววันนี้';
      } else {
        todaySectionTitle.textContent = 'คิววันที่ ' + formatThaiDate(isoDate);
      }
      todayDate.textContent = formatThaiDate(isoDate);
    }

    setListHeader(viewListDate);

    function esc(s) {
      return (s === null || s === undefined ? '' : String(s))
        .replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function statusPill(statusLabel, color) {
      return '<span class="status-pill" style="background:' + esc(color) + '">' + esc(statusLabel) + '</span>';
    }

    // โซเชียลที่เป็นลิงก์กดเปิดได้ ส่วนชื่อแสดงเป็นข้อความ
    function socialHtml(value) {
      const href = /^https?:\/\//i.test(value) ? value : (/^www\./i.test(value) ? 'https://' + value : null);
      return href ? '<a target="_blank" rel="noopener noreferrer" href="' + esc(href) + '">เปิดลิงก์</a>' : esc(value);
    }

    function renderEventDetail(bookingId, p) {
      const phoneDigits = (p.customer_phone || '').replace(/[^0-9+]/g, '');
      const phone = p.customer_phone ? '<a href="tel:' + phoneDigits + '">' + esc(p.customer_phone) + '</a>' : '-';
      const mapHref = p.location ? (p.location.startsWith('http') ? p.location : 'https://www.google.com/maps?q=' + encodeURIComponent(p.location)) : null;
      const base = <?= json_encode($base) ?>;
      const money = v => Number(v).toLocaleString('th-TH', {minimumFractionDigits: 0});
      const sourceBadge = p.source === 'customer' ? ' <span class="badge text-bg-info">ลูกค้าจองเอง</span>' : '';
      const priceLine = (p.price !== null && p.price !== undefined)
        ? '<p><strong>ราคา:</strong> ' + money(p.price) + ' บาท' + (p.deposit ? ' (มัดจำ ' + money(p.deposit) + ')' : '') + '</p>' : '';
      const travelLine = (p.travel_fee !== null && p.travel_fee !== undefined && p.travel_fee !== '')
        ? '<p><strong>ค่าเดินทาง:</strong> ' + money(p.travel_fee) + ' บาท</p>' : '';
      const hasPrice = p.price !== null && p.price !== undefined;
      const balance = Math.max(0, Number(p.price || 0) + Number(p.travel_fee || 0) - Number(p.paid_total || 0));
      const moneyLine = hasPrice
        ? '<p><strong>รับแล้ว:</strong> ' + money(p.paid_total) + ' บาท · <strong>คงเหลือ:</strong> ' + money(balance) + ' บาท</p>'
        : '<p class="text-warning-emphasis"><i class="bi bi-exclamation-circle"></i> ยังไม่ใส่ราคา' + (p.paid_total ? ' (รับแล้ว ' + money(p.paid_total) + ' บาท)' : '') + '</p>';
      const holdLine = (p.status === 'new' && p.hold_until && !p.slip_path && p.payment_status === 'unpaid')
        ? '<p class="text-warning-emphasis"><i class="bi bi-hourglass-split"></i> รอมัดจำถึง ' + esc(p.hold_until.slice(0, 16)) + '</p>' : '';
      const canConfirm = p.payment_status === 'unpaid' && Number(p.deposit) > 0 && p.status !== 'cancelled';
      // ช่างที่รับงาน: ช่างหลัก + ช่างเสริม พร้อมมัดจำที่จ่ายให้แต่ละคนและสลิปของคนนั้น
      const slipBtn = f => ' <a href="' + base + '/api/slip.php?file=' + encodeURIComponent(f) + '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><i class="bi bi-receipt"></i> ดูสลิป</a>';
      const staffList = Array.isArray(p.staff_list) ? p.staff_list : [];
      const staffLine = staffList.length
        ? '<p class="mb-1"><strong>ช่าง:</strong></p><ul class="list-unstyled ms-2 mb-3">' + staffList.map(s =>
            '<li class="mb-1">' + (s.role === 'main' ? '<i class="bi bi-star-fill text-warning"></i> ' : '<i class="bi bi-person"></i> ') +
            esc(s.name) + (s.role === 'main' ? ' <span class="text-muted small">(ช่างหลัก)</span>' : '') +
            (s.deposit_paid ? ' <span class="text-muted small">• มัดจำจ่าย ' + money(s.deposit_paid) + ' บาท</span>' : '') +
            (s.slip_path ? slipBtn(s.slip_path) : '') + '</li>').join('') + '</ul>'
        : '<p><strong>ช่าง:</strong> ' + esc(p.staff_name || 'ไม่ระบุ') + '</p>';
      document.getElementById('eventDetail').innerHTML =
        '<div class="mb-2">' + statusPill(p.status_label || 'ใหม่', p.status_color || '#93c5fd') + sourceBadge + '</div>' +
        '<p><strong>เวลา:</strong> ' + esc(p.time_label || '-') + '</p>' +
        '<p><strong>ลูกค้า:</strong> ' + esc(p.customer_name || '-') + '</p>' +
        '<p><strong>โทร:</strong> ' + phone + '</p>' +
        (p.social ? '<p><strong>โซเชียล:</strong> ' + socialHtml(p.social) + '</p>' : '') +
        '<p><strong>ประเภท:</strong> ' + esc(p.category_names || '-') + '</p>' +
        staffLine +
        '<p><strong>จำนวน:</strong> ' + esc(p.num_people || 1) + ' ' + esc(p.count_unit || 'คน') + '</p>' +
        priceLine +
        travelLine +
        moneyLine +
        holdLine +
        '<p><strong>การจ่าย:</strong> ' + esc(p.payment_label || '-') +
          (p.slip_path ? ' <a href="' + base + '/api/slip.php?file=' + encodeURIComponent(p.slip_path) + '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary ms-2"><i class="bi bi-receipt"></i> ดูสลิป</a>' : '') + '</p>' +
        '<p><strong>สถานที่:</strong> ' + (mapHref ? '<a target="_blank" rel="noopener" href="' + esc(mapHref) + '">เปิดแผนที่</a>' : '-') + '</p>' +
        (p.note ? '<p><strong>หมายเหตุ:</strong> ' + esc(p.note) + '</p>' : '') +
        '<div class="d-flex gap-2 mt-3 pt-3 border-top">' +
          '<a href="' + base + '/form.php?id=' + bookingId + '" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil"></i> แก้ไข</a>' +
          (p.public_token ? '<button type="button" class="btn btn-outline-secondary btn-sm" id="btnCopyTrack"><i class="bi bi-link-45deg"></i> ลิงก์ลูกค้า</button>' : '') +
          '<button type="button" class="btn btn-outline-danger btn-sm" id="btnDeleteBooking" data-id="' + bookingId + '"><i class="bi bi-trash"></i> ลบ</button>' +
        '</div>' +
        (canConfirm ? '<div class="d-grid mt-2"><button type="button" class="btn btn-sm btn-dark" id="btnConfirmDeposit"><i class="bi bi-check2-circle"></i> ยืนยันรับมัดจำ ' + money(p.deposit) + ' บาท และยืนยันคิว</button></div>' : '');
      eventModal.show();
      const btnCopy = document.getElementById('btnCopyTrack');
      if (btnCopy) {
        btnCopy.onclick = async () => {
          const url = location.origin + base + '/track.php?c=' + p.public_token;
          try { await navigator.clipboard.writeText(url); btnCopy.textContent = 'คัดลอกแล้ว'; } catch (e) { prompt('คัดลอกลิงก์นี้', url); }
        };
      }
      const btnConfirm = document.getElementById('btnConfirmDeposit');
      if (btnConfirm) {
        btnConfirm.onclick = () => {
          fetch(API_BASE + '/payments.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ booking_id: Number(bookingId), kind: 'deposit', amount: p.deposit, method: 'transfer', confirm: 1 }),
          }).then(r => r.json()).then(data => {
            if (data.error) { alert(data.error); return; }
            eventModal.hide();
            loadListForDate(viewListDate);
            calendar.refetchEvents();
          }).catch(() => alert('บันทึกไม่สำเร็จ'));
        };
      }
      const btnDel = document.getElementById('btnDeleteBooking');
      if (btnDel) {
        btnDel.onclick = function() {
          if (!confirm('ต้องการลบรายการนี้ใช่ไหม?')) return;
          const id = btnDel.dataset.id;
          fetch(API_BASE + '/bookings.php?id=' + id, { method: 'DELETE', headers: { 'X-CSRF-Token': CSRF } })
            .then(r => r.json())
            .then(data => {
              if (data.error) { alert(data.error); return; }
              eventModal.hide();
              loadListForDate(viewListDate);
              calendar.refetchEvents();
            })
            .catch(() => alert('ลบไม่สำเร็จ'));
        };
      }
    }

    let statusFilter = ''; // คั่นด้วย comma เช่น new,confirmed
    let staffFilter = '';  // '' = ทุกช่าง, 'none' = ไม่ระบุ, หรือ id
    const STAFF_COLOR = {}; // staff_id -> สี

    function getFilterUrl(params) {
      const q = new URLSearchParams(params);
      if (statusFilter) q.set('status', statusFilter);
      if (staffFilter) q.set('staff', staffFilter);
      return API_BASE + '/bookings.php?' + q.toString();
    }

    // โหลดช่างมาทำตัวกรอง + legend สี + แผนที่สี
    fetch(API_BASE + '/options.php').then(r => r.json()).then(data => {
      const staff = data.staff || [];
      const sel = document.getElementById('staffFilterSel');
      const legend = document.getElementById('staffLegend');
      staff.forEach(s => {
        STAFF_COLOR[s.id] = s.color_hex;
        const o = document.createElement('option'); o.value = s.id; o.textContent = s.name; sel.appendChild(o);
        legend.insertAdjacentHTML('beforeend', '<span><span class="chip-dot" style="background:' + esc(s.color_hex) + '"></span>' + esc(s.name) + '</span>');
      });
      legend.insertAdjacentHTML('beforeend', '<span><span class="chip-dot" style="background:#cbd5e1"></span>ไม่ระบุ</span>');
      sel.addEventListener('change', () => {
        staffFilter = sel.value;
        loadListForDate(viewListDate);
        calendar.refetchEvents();
      });
    }).catch(() => {});

    // สีกิจกรรมในปฏิทินตามช่าง (ไม่ระบุ = เทา)
    function colorByStaff(events) {
      events.forEach(e => {
        const sid = e.extendedProps ? e.extendedProps.staff_id : null;
        e.color = (sid && STAFF_COLOR[sid]) ? STAFF_COLOR[sid] : '#cbd5e1';
      });
      return events;
    }

    function loadListForDate(dateStr) {
      viewListDate = dateStr;
      setListHeader(dateStr);
      fetch(getFilterUrl({ mode: 'today', date: dateStr }))
        .then(r => r.json())
        .then(rows => {
          if (!Array.isArray(rows) || rows.length === 0) {
            todayList.innerHTML = '<div class="text-muted small">ยังไม่มีคิวในวันนี้</div>';
            return;
          }
          todayList.innerHTML = rows.map(r =>
            '<article class="today-list-item" style="border-left:5px solid ' + esc(r.color) + '">' +
              '<div class="today-time">' + esc(r.time) + '</div>' +
              '<div class="flex-grow-1">' +
                '<div class="today-title">' + esc(r.extendedProps.customer_name) + '</div>' +
                '<div class="today-meta">' + esc(r.extendedProps.category_names || '-') + ' • ' + esc(r.extendedProps.num_people) + ' ' + esc(r.extendedProps.count_unit || 'คน') + '</div>' +
                '<div class="mt-1">' + statusPill(r.extendedProps.status_label, r.color) +
                  (r.extendedProps.slip_path ? ' <span class="badge text-bg-secondary"><i class="bi bi-receipt"></i> สลิป</span>' : '') + '</div>' +
              '</div>' +
              '<button class="btn btn-sm btn-light border icon-only" data-id="' + r.id + '" aria-label="ดูรายละเอียด"><i class="bi bi-eye"></i></button>' +
            '</article>'
          ).join('');

          todayList.querySelectorAll('button[data-id]').forEach((btn, idx) => {
            btn.addEventListener('click', () => {
              const item = rows[idx];
              renderEventDetail(item.id, {...item.extendedProps, status_color: item.color});
            });
          });
        })
        .catch(() => {
          todayList.innerHTML = '<div class="text-danger small">โหลดข้อมูลไม่สำเร็จ</div>';
        });
    }

    function loadTodayList() {
      loadListForDate(localISO());
    }

    const calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
      locale: 'th',
      initialView: 'dayGridMonth',
      headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek' },
      buttonText: { today: 'วันนี้', month: 'เดือน', week: 'สัปดาห์' },
      height: 'auto',
      events(info, ok, fail) {
        fetch(getFilterUrl({ start: info.startStr, end: info.endStr }))
          .then(r => r.json())
          .then(events => ok(colorByStaff(events)))
          .catch(fail);
      },
      dateClick(info) {
        const clickedDate = info.dateStr;
        viewListDate = clickedDate;
        btnToday.classList.add('active');
        btnCalendar.classList.remove('active');
        todaySection.classList.remove('d-none');
        calendarSection.classList.add('d-none');
        setListHeader(clickedDate);
        loadListForDate(clickedDate);
      },
      eventClick(info) {
        const p = info.event.extendedProps;
        renderEventDetail(info.event.id, {...p, status_color: info.event.backgroundColor});
      }
    });
    calendar.render();
    if (window.matchMedia('(min-width: 992px)').matches) {
      setTimeout(() => calendar.updateSize(), 200);
    }

    btnToday.addEventListener('click', () => {
      btnToday.classList.add('active');
      btnCalendar.classList.remove('active');
      todaySection.classList.remove('d-none');
      calendarSection.classList.add('d-none');
      loadTodayList();
    });
    btnCalendar.addEventListener('click', () => {
      btnCalendar.classList.add('active');
      btnToday.classList.remove('active');
      calendarSection.classList.remove('d-none');
      todaySection.classList.add('d-none');
      calendar.updateSize();
    });

    document.querySelectorAll('#statusFilters .chip-filter').forEach(btn => {
      btn.addEventListener('click', () => {
        const status = btn.dataset.status || '';
        const isAll = (status === '');
        if (isAll) {
          document.querySelectorAll('#statusFilters .chip-filter').forEach(b => { b.classList.remove('active'); b.setAttribute('aria-pressed', 'false'); });
          btn.classList.add('active');
          btn.setAttribute('aria-pressed', 'true');
          statusFilter = '';
        } else {
          document.querySelector('#statusFilters .chip-filter[data-status=""]').classList.remove('active');
          btn.classList.toggle('active');
          const active = document.querySelectorAll('#statusFilters .chip-filter.active[data-status]');
          statusFilter = Array.from(active).map(b => b.dataset.status).filter(Boolean).join(',');
          if (!statusFilter) {
            document.querySelector('#statusFilters .chip-filter[data-status=""]').classList.add('active');
          }
        }
        btn.setAttribute('aria-pressed', btn.classList.contains('active') ? 'true' : 'false');
        loadListForDate(viewListDate);
        calendar.refetchEvents();
      });
    });

    loadTodayList();
  </script>
</body>
</html>
