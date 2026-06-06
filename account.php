<?php
/**
 * หน้าบัญชีผู้ใช้: เปลี่ยนรหัสผ่าน admin (ต้อง login)
 */
require __DIR__ . '/app/auth.php';
requireLogin();
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $token)) {
        $message = 'เซสชันหมดอายุ โปรดรีเฟรชหน้าแล้วลองใหม่';
        $messageType = 'danger';
    } else {
        $cur = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        try {
            $pdo = require __DIR__ . '/app/db.php';
            $stmt = $pdo->prepare("SELECT password_hash FROM app_users WHERE id = ?");
            $stmt->execute([currentUserId()]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify($cur, $hash)) {
                $message = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
                $messageType = 'danger';
            } elseif (strlen($new) < 6) {
                $message = 'รหัสผ่านใหม่ต้องยาวอย่างน้อย 6 ตัวอักษร';
                $messageType = 'danger';
            } elseif ($new !== $confirm) {
                $message = 'รหัสผ่านใหม่กับยืนยันไม่ตรงกัน';
                $messageType = 'danger';
            } else {
                $upd = $pdo->prepare("UPDATE app_users SET password_hash = ? WHERE id = ?");
                $upd->execute([password_hash($new, PASSWORD_DEFAULT), currentUserId()]);
                $message = 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว';
                $messageType = 'success';
            }
        } catch (Throwable $e) {
            $message = 'เกิดข้อผิดพลาด โปรดลองใหม่';
            $messageType = 'danger';
        }
    }
}
$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>เปลี่ยนรหัสผ่าน</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&display=swap" rel="stylesheet">
  <link href="<?= htmlspecialchars($base) ?>/assets/app.css" rel="stylesheet">
</head>
<body>
  <header class="head py-3 mb-3">
    <div class="wrap d-flex justify-content-between align-items-center">
      <div>
        <a href="<?= htmlspecialchars($base) ?>/" class="text-muted text-decoration-none small"><i class="bi bi-arrow-left"></i> กลับ</a>
        <h1 class="brand mb-0 mt-1" style="font-size: 1.35rem; font-weight: 600;">เปลี่ยนรหัสผ่าน</h1>
        <small class="text-muted">ผู้ใช้: <?= htmlspecialchars(currentUsername()) ?></small>
      </div>
    </div>
  </header>

  <div class="wrap pb-4 rise">
    <?php if ($message): ?>
      <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <div class="card-soft p-3 p-md-4">
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <div class="mb-3">
          <label class="form-label section-title">รหัสผ่านปัจจุบัน</label>
          <input type="password" name="current_password" class="form-control soft-input" required autocomplete="current-password">
        </div>
        <div class="mb-3">
          <label class="form-label section-title">รหัสผ่านใหม่ <span class="title-hint">อย่างน้อย 6 ตัวอักษร</span></label>
          <input type="password" name="new_password" class="form-control soft-input" required autocomplete="new-password" minlength="6">
        </div>
        <div class="mb-4">
          <label class="form-label section-title">ยืนยันรหัสผ่านใหม่</label>
          <input type="password" name="confirm_password" class="form-control soft-input" required autocomplete="new-password" minlength="6">
        </div>
        <div class="d-grid">
          <button type="submit" class="btn save-btn">บันทึกรหัสผ่านใหม่</button>
        </div>
      </form>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
