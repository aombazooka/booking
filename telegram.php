<?php
/**
 * หน้าเชื่อมต่อ Telegram (แยกต่อร้าน): ตั้งค่า Bot Token, Chat ID, เวลาแจ้งเตือน
 * ปุ่ม เทส / บันทึก
 */
require __DIR__ . '/app/auth.php';
requireLogin();
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
require __DIR__ . '/app/telegram.php';

$pdo = require __DIR__ . '/app/db.php';
$owner = ownerId();
$settings = getTelegramSettings($pdo, $owner);
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $csrf = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $csrf)) {
        $message = 'เซสชันหมดอายุ โปรดรีเฟรชหน้าแล้วลองใหม่';
        $messageType = 'danger';
    } else {
        $settings['bot_token'] = trim($_POST['bot_token'] ?? '');
        $settings['chat_id'] = trim($_POST['chat_id'] ?? '');
        $settings['line_token'] = trim($_POST['line_token'] ?? '');
        $settings['line_to'] = trim($_POST['line_to'] ?? '');
        $settings['notify_new'] = isset($_POST['notify_new']) ? 1 : 0;
        $settings['notify_time'] = trim($_POST['notify_time'] ?? '18:00');
        if (!preg_match('/^\d{1,2}:\d{2}$/', $settings['notify_time'])) {
            $settings['notify_time'] = '18:00';
        }
        if ($action === 'test') {
            saveTelegramSettings($pdo, $owner, $settings);
            $result = sendShopNotification($settings, "🔔 <b>ทดสอบแจ้งเตือน</b>\nแอปจองคิว — การเชื่อมต่อใช้งานได้");
            $message = $result['ok'] ? 'ส่งข้อความทดสอบแล้ว' : ($result['error'] ?? 'ส่งไม่สำเร็จ');
            $messageType = $result['ok'] ? 'success' : 'danger';
        } elseif (saveTelegramSettings($pdo, $owner, $settings)) {
            $message = 'บันทึกการตั้งค่าเรียบร้อย';
            $messageType = 'success';
        } else {
            $message = 'บันทึกไม่สำเร็จ';
            $messageType = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>แจ้งเตือน Telegram / LINE</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&display=swap" rel="stylesheet">
  <link href="<?= htmlspecialchars($base) ?>/assets/app.css?v=<?= assetVer('assets/app.css') ?>" rel="stylesheet">
</head>
<body>
  <header class="head py-3 mb-3">
    <div class="wrap d-flex justify-content-between align-items-center">
      <div>
        <a href="<?= htmlspecialchars($base) ?>/" class="back-btn"><i class="bi bi-arrow-left"></i> กลับ</a>
        <h1 class="brand mb-0 mt-1" style="font-size: 1.35rem; font-weight: 600;">แจ้งเตือน Telegram / LINE</h1>
        <small class="text-muted">แจ้งคิวใหม่ทันที และส่งตารางงานวันพรุ่งนี้</small>
      </div>
    </div>
  </header>

  <div class="wrap pb-4 rise">
    <?php if ($message): ?>
      <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <div class="card-soft p-3 p-md-4">
      <form method="post" id="telegramForm">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken()) ?>">
        <div class="mb-3">
          <label class="form-label fw-semibold">Bot Token <span class="text-danger">*</span></label>
          <input type="password" class="form-control" name="bot_token" value="<?= htmlspecialchars($settings['bot_token']) ?>" placeholder="จาก @BotFather" autocomplete="off">
          <small class="text-muted">สร้างบอทที่ Telegram แล้ววาง Token ตรงนี้</small>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Chat ID <span class="text-danger">*</span></label>
          <input type="text" class="form-control" name="chat_id" value="<?= htmlspecialchars($settings['chat_id']) ?>" placeholder="เช่น -1001234567890 หรือเลข user">
          <small class="text-muted">เลข Chat/กลุ่มที่จะรับแจ้งเตือน (หาจาก @userinfobot หรือกลุ่ม)</small>
        </div>
        <div class="mb-4">
          <label class="form-label fw-semibold">เวลาแจ้งเตือน (ตารางวันพรุ่งนี้)</label>
          <input type="time" class="form-control" name="notify_time" value="<?= htmlspecialchars($settings['notify_time']) ?>" style="max-width: 140px;">
          <small class="text-muted">ระบบจะส่งตารางงานของวันพรุ่งนี้ในเวลานี้ของทุกวัน (เช่น 18:00 = 6 โมงเย็น)</small>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold">LINE Channel Access Token</label>
          <input type="password" class="form-control" name="line_token" value="<?= htmlspecialchars($settings['line_token']) ?>" placeholder="จาก LINE Developers (Messaging API)" autocomplete="off">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">LINE User ID / Group ID</label>
          <input type="text" class="form-control" name="line_to" value="<?= htmlspecialchars($settings['line_to']) ?>" placeholder="ขึ้นต้นด้วย U หรือ C">
          <small class="text-muted">ใช้ช่องทางไหนก็กรอกเฉพาะช่องทางนั้น หรือกรอกทั้งคู่ก็ได้</small>
        </div>
        <div class="form-check mb-4">
          <input class="form-check-input" type="checkbox" name="notify_new" id="notifyNew"<?= !empty($settings['notify_new']) ? ' checked' : '' ?>>
          <label class="form-check-label" for="notifyNew">แจ้งทันทีเมื่อลูกค้าจองใหม่หรือแนบสลิป</label>
        </div>

        <div class="d-flex flex-wrap gap-2">
          <button type="submit" name="action" value="save" class="btn btn-save px-4">บันทึก</button>
          <button type="submit" name="action" value="test" class="btn btn-outline-primary btn-test">เทส</button>
        </div>
      </form>
    </div>

    <div class="card-soft p-3 mt-3">
      <h6 class="fw-semibold mb-2">วิธีตั้งค่า Cron (ให้แจ้งเตือนอัตโนมัติ)</h6>
      <p class="small text-muted mb-1">รันสคริปต์ทุก 5 นาที เพื่อตรวจสอบว่าถึงเวลาแจ้งเตือนหรือยัง (ตามเวลาที่ตั้งด้านบน)</p>
      <code class="d-block bg-light p-2 rounded small">*/5 * * * * php <?= str_replace('\\', '/', __DIR__) ?>/cron/send_tomorrow_schedule.php</code>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
