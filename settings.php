<?php
/**
 * ตั้งค่าร้าน: เวลาทำการ ช่องเวลา เวลาเผื่อเดินทาง เวลาถือคิว PromptPay และวันหยุด/วันลาช่าง
 */
require __DIR__ . '/app/auth.php';
require __DIR__ . '/app/booking_helpers.php';
require __DIR__ . '/app/promptpay.php';
requireLogin();
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$pdo = require __DIR__ . '/app/db.php';
$owner = ownerId();

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $csrf = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $csrf)) {
        $message = 'เซสชันหมดอายุ โปรดรีเฟรชหน้าแล้วลองใหม่';
        $messageType = 'danger';
    } elseif ($action === 'save') {
        $open = (string) ($_POST['open_time'] ?? '');
        $close = (string) ($_POST['close_time'] ?? '');
        $weekdays = array_intersect(array_map('strval', (array) ($_POST['closed_weekdays'] ?? [])), ['0', '1', '2', '3', '4', '5', '6']);
        $promptPay = preg_replace('/\D/', '', (string) ($_POST['promptpay_id'] ?? ''));
        if (!preg_match('/^\d{2}:\d{2}$/', $open) || !preg_match('/^\d{2}:\d{2}$/', $close) || $open >= $close) {
            $message = 'เวลาเปิดต้องน้อยกว่าเวลาปิด';
            $messageType = 'danger';
        } elseif ($promptPay !== '' && promptPayPayload($promptPay) === null) {
            $message = 'เลข PromptPay ไม่ถูกต้อง (เบอร์มือถือ 10 หลัก, เลขบัตร 13 หลัก หรือ e-Wallet 15 หลัก)';
            $messageType = 'danger';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO shop_settings (user_id, open_time, close_time, closed_weekdays, slot_minutes, buffer_minutes, hold_hours, promptpay_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE open_time = VALUES(open_time), close_time = VALUES(close_time), closed_weekdays = VALUES(closed_weekdays),
                    slot_minutes = VALUES(slot_minutes), buffer_minutes = VALUES(buffer_minutes), hold_hours = VALUES(hold_hours), promptpay_id = VALUES(promptpay_id)
            ");
            $stmt->execute([
                $owner, $open, $close, implode(',', $weekdays),
                max(10, min(240, (int) ($_POST['slot_minutes'] ?? 30))),
                max(0, min(480, (int) ($_POST['buffer_minutes'] ?? 0))),
                max(0, min(720, (int) ($_POST['hold_hours'] ?? 0))),
                $promptPay,
            ]);
            $message = 'บันทึกการตั้งค่าเรียบร้อย';
        }
    } elseif ($action === 'add_closure') {
        $from = (string) ($_POST['date_from'] ?? '');
        $to = (string) ($_POST['date_to'] ?? '') ?: $from;
        $staffId = resolveStaffId($pdo, $owner, $_POST['staff_id'] ?? null);
        if (!isValidDate($from) || !isValidDate($to) || $from > $to) {
            $message = 'ช่วงวันที่ไม่ถูกต้อง';
            $messageType = 'danger';
        } else {
            $pdo->prepare("INSERT INTO shop_closures (user_id, staff_id, date_from, date_to, note) VALUES (?, ?, ?, ?, ?)")
                ->execute([$owner, $staffId, $from, $to, trim((string) ($_POST['note'] ?? '')) ?: null]);
            $message = 'เพิ่มวันหยุดเรียบร้อย';
        }
    } elseif ($action === 'delete_closure') {
        $pdo->prepare("DELETE FROM shop_closures WHERE id = ? AND user_id = ?")->execute([(int) ($_POST['id'] ?? 0), $owner]);
        $message = 'ลบวันหยุดเรียบร้อย';
    }
}

$s = getShopSettings($pdo, $owner);
$closedDays = $s['closed_weekdays'] === '' ? [] : explode(',', $s['closed_weekdays']);
$stmt = $pdo->prepare("SELECT id, name FROM staff WHERE user_id = ? AND is_active = 1 ORDER BY sort_order, id");
$stmt->execute([$owner]);
$staffList = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare("SELECT c.id, c.date_from, c.date_to, c.note, st.name AS staff_name
                       FROM shop_closures c LEFT JOIN staff st ON st.id = c.staff_id
                       WHERE c.user_id = ? AND c.date_to >= ? ORDER BY c.date_from");
$stmt->execute([$owner, date('Y-m-d')]);
$closures = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare("SELECT shop_slug FROM app_users WHERE id = ?");
$stmt->execute([$owner]);
$slug = (string) $stmt->fetchColumn();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$bookingLink = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . '/book.php?shop=' . urlencode($slug);
$calLink     = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . '/cal.php?shop=' . urlencode($slug);
$csrf = csrfToken();
$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$dayNames = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>ตั้งค่าร้าน</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&display=swap" rel="stylesheet">
  <link href="<?= $h($base) ?>/assets/app.css?v=<?= assetVer('assets/app.css') ?>" rel="stylesheet">
</head>
<body>
  <header class="head py-3 mb-3">
    <div class="wrap d-flex justify-content-between align-items-center">
      <div>
        <a href="<?= $h($base) ?>/" class="back-btn"><i class="bi bi-arrow-left"></i> กลับ</a>
        <h1 class="brand mb-0 mt-1" style="font-size: 1.35rem; font-weight: 600;">ตั้งค่าร้าน</h1>
        <small class="text-muted">เวลาทำการ การรับจอง และวันหยุด</small>
      </div>
    </div>
  </header>

  <div class="wrap pb-5 rise">
    <?php if ($message): ?><div class="alert alert-<?= $h($messageType) ?> py-2"><?= $h($message) ?></div><?php endif; ?>

    <div class="card-soft p-3 p-md-4 mb-3">
      <div class="section-title mb-2">ลิงก์จองของร้าน <span class="text-muted" style="font-weight:400;">— ส่งให้ลูกค้าจองคิวเอง</span></div>
      <div class="input-group mb-3">
        <input type="text" class="form-control soft-input" id="bookLink" readonly value="<?= $h($bookingLink) ?>">
        <button class="btn btn-light border" type="button" id="copyLink"><i class="bi bi-clipboard"></i> คัดลอก</button>
      </div>
      <div class="section-title mb-2">ลิงก์ปฏิทินคิวงาน (สาธารณะ) <span class="text-muted" style="font-weight:400;">— ส่งให้ดูตารางคิว (ดูอย่างเดียว ไม่เห็นข้อมูลลูกค้า)</span></div>
      <div class="input-group">
        <input type="text" class="form-control soft-input" id="calLink" readonly value="<?= $h($calLink) ?>">
        <a class="btn btn-light border" href="<?= $h($calLink) ?>" target="_blank" title="เปิดดู"><i class="bi bi-box-arrow-up-right"></i></a>
        <button class="btn btn-light border" type="button" id="copyCal"><i class="bi bi-clipboard"></i> คัดลอก</button>
      </div>
    </div>

    <form method="post" class="card-soft p-3 p-md-4 mb-3">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <input type="hidden" name="action" value="save">
      <div class="row">
        <div class="col-6 mb-3">
          <label class="form-label section-title">เปิดรับคิวตั้งแต่</label>
          <input type="time" class="form-control soft-input" name="open_time" required value="<?= $h(substr($s['open_time'], 0, 5)) ?>">
        </div>
        <div class="col-6 mb-3">
          <label class="form-label section-title">ถึงเวลา</label>
          <input type="time" class="form-control soft-input" name="close_time" required value="<?= $h(substr($s['close_time'], 0, 5)) ?>">
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label section-title">วันปิดประจำสัปดาห์</label>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($dayNames as $i => $name): ?>
          <label class="choice-pill" style="cursor:pointer"><input type="checkbox" name="closed_weekdays[]" value="<?= $i ?>"<?= in_array((string) $i, $closedDays, true) ? ' checked' : '' ?>> <?= $h($name) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="row">
        <div class="col-6 col-md-4 mb-3">
          <label class="form-label section-title">ช่องเวลาทุกกี่นาที</label>
          <input type="number" class="form-control soft-input" name="slot_minutes" min="10" max="240" value="<?= (int) $s['slot_minutes'] ?>">
        </div>
        <div class="col-6 col-md-4 mb-3">
          <label class="form-label section-title">เผื่อเดินทาง (นาที)</label>
          <input type="number" class="form-control soft-input" name="buffer_minutes" min="0" max="480" value="<?= (int) $s['buffer_minutes'] ?>">
        </div>
        <div class="col-12 col-md-4 mb-3">
          <label class="form-label section-title">ถือคิวรอมัดจำ (ชั่วโมง)</label>
          <input type="number" class="form-control soft-input" name="hold_hours" min="0" max="720" value="<?= (int) $s['hold_hours'] ?>">
          <small class="text-muted">0 = ไม่ปล่อยคิวอัตโนมัติ</small>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label section-title">PromptPay ของร้าน</label>
        <input type="text" class="form-control soft-input" name="promptpay_id" inputmode="numeric" value="<?= $h($s['promptpay_id']) ?>" placeholder="เบอร์มือถือ หรือเลขบัตรประชาชน">
        <small class="text-muted">ใช้สร้าง QR ตามยอดในหน้าติดตามคิวของลูกค้า</small>
      </div>
      <div class="d-grid"><button type="submit" class="btn save-btn">บันทึก</button></div>
    </form>

    <div class="card-soft p-3 p-md-4">
      <div class="section-title mb-2">วันหยุดร้าน / วันลาช่าง</div>
      <form method="post" class="row g-2 mb-3">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="action" value="add_closure">
        <div class="col-6 col-md-3"><input type="date" class="form-control soft-input" name="date_from" required aria-label="ตั้งแต่วันที่"></div>
        <div class="col-6 col-md-3"><input type="date" class="form-control soft-input" name="date_to" aria-label="ถึงวันที่"></div>
        <div class="col-12 col-md-3">
          <select name="staff_id" class="form-select soft-input" aria-label="ใคร">
            <option value="">ปิดทั้งร้าน</option>
            <?php foreach ($staffList as $st): ?><option value="<?= (int) $st['id'] ?>"><?= $h($st['name']) ?> ลา</option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-8 col-md-2"><input type="text" class="form-control soft-input" name="note" maxlength="150" placeholder="หมายเหตุ"></div>
        <div class="col-4 col-md-1 d-grid"><button type="submit" class="btn btn-light border" aria-label="เพิ่มวันหยุด"><i class="bi bi-plus-lg"></i></button></div>
      </form>
      <?php if (!$closures): ?>
      <div class="text-muted small">ยังไม่มีวันหยุดที่กำลังจะถึง</div>
      <?php endif; ?>
      <?php foreach ($closures as $c): ?>
      <form method="post" class="d-flex justify-content-between align-items-center border-top py-2 gap-2">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="action" value="delete_closure">
        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <span class="small"><?= $h(date('d/m/Y', strtotime($c['date_from']))) ?><?= $c['date_to'] !== $c['date_from'] ? ' - ' . $h(date('d/m/Y', strtotime($c['date_to']))) : '' ?> · <?= $h($c['staff_name'] ? $c['staff_name'] . ' ลา' : 'ปิดทั้งร้าน') ?><?= $c['note'] ? ' · ' . $h($c['note']) : '' ?></span>
        <button type="submit" class="btn btn-sm btn-light border text-danger" aria-label="ลบวันหยุด"><i class="bi bi-trash"></i></button>
      </form>
      <?php endforeach; ?>
    </div>
  </div>

  <script>
    function copyFrom(btn, inputId) {
      const input = document.getElementById(inputId);
      input.select();
      const done = () => { const o = btn.innerHTML; btn.innerHTML = '<i class="bi bi-check-lg"></i> คัดลอกแล้ว'; setTimeout(() => btn.innerHTML = o, 1500); };
      navigator.clipboard.writeText(input.value).then(done).catch(() => { document.execCommand('copy'); done(); });
    }
    document.getElementById('copyLink').addEventListener('click', function () { copyFrom(this, 'bookLink'); });
    const copyCal = document.getElementById('copyCal');
    if (copyCal) copyCal.addEventListener('click', function () { copyFrom(this, 'calLink'); });
  </script>
</body>
</html>
