<?php
/**
 * สมัครเปิดร้านใหม่ (สาธารณะ) — สร้างบัญชีสถานะ "pending" รอผู้ดูแลระบบอนุมัติ
 */
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
require __DIR__ . '/app/auth.php';
require __DIR__ . '/app/booking_helpers.php';

if (isLoggedIn()) {
    header('Location: ' . $base . '/');
    exit;
}

$error = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rateLimitOk('register:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 5, 3600)) {
        $error = 'สมัครบ่อยเกินไป กรุณารอสักครู่';
    } else {
        $username = trim($_POST['username'] ?? '');
        $shopName = trim($_POST['shop_name'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['confirm_password'] ?? '');

        if ($username === '' || $shopName === '') {
            $error = 'กรุณากรอกชื่อผู้ใช้และชื่อร้าน';
        } elseif (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $username)) {
            $error = 'ชื่อผู้ใช้ใช้ได้เฉพาะ a-z, 0-9, _ . ยาว 3-50 ตัว';
        } elseif (strlen($password) < 6) {
            $error = 'รหัสผ่านต้องยาวอย่างน้อย 6 ตัวอักษร';
        } elseif ($password !== $confirm) {
            $error = 'รหัสผ่านกับยืนยันไม่ตรงกัน';
        } else {
            try {
                $pdo = require __DIR__ . '/app/db.php';
                // ชื่อผู้ใช้ซ้ำ?
                $c = $pdo->prepare("SELECT COUNT(*) FROM app_users WHERE username = ?");
                $c->execute([$username]);
                if ($c->fetchColumn() > 0) {
                    $error = 'ชื่อผู้ใช้นี้มีคนใช้แล้ว';
                } else {
                    // สร้าง slug ไม่ซ้ำ
                    $slugBase = preg_replace('/[^a-z0-9_-]/', '', strtolower($username));
                    if ($slugBase === '') { $slugBase = 'shop'; }
                    $slug = $slugBase;
                    $s = $pdo->prepare("SELECT COUNT(*) FROM app_users WHERE shop_slug = ?");
                    $i = 1;
                    while (true) {
                        $s->execute([$slug]);
                        if ($s->fetchColumn() == 0) break;
                        $slug = $slugBase . $i;
                        $i++;
                    }
                    $ins = $pdo->prepare("INSERT INTO app_users (username, role, status, shop_name, shop_slug, password_hash)
                                          VALUES (?, 'owner', 'pending', ?, ?, ?)");
                    $ins->execute([$username, $shopName, $slug, password_hash($password, PASSWORD_DEFAULT)]);
                    $done = true;
                }
            } catch (Throwable $e) {
                $error = 'เกิดข้อผิดพลาด โปรดลองใหม่';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>สมัครเปิดร้าน</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&display=swap" rel="stylesheet">
  <link href="<?= htmlspecialchars($base) ?>/assets/app.css" rel="stylesheet">
  <style> body { min-height: 100vh; display: flex; align-items: center; } .login-card { max-width: 440px; width: 100%; } </style>
</head>
<body>
  <div class="wrap">
    <div class="mx-auto login-card p-4 p-md-5 rise">
      <div class="text-center mb-4">
        <span class="logo-badge mb-2"><i class="bi bi-shop"></i></span>
        <div class="eyebrow mt-3">แอปจองคิว</div>
        <h1 class="brand mt-1 mb-0" style="font-size: 1.6rem; font-weight: 600;">สมัครเปิดร้าน</h1>
        <small class="text-muted">สร้างร้านของคุณเอง — ข้อมูลแยกเป็นส่วนตัว</small>
      </div>

      <?php if ($done): ?>
        <div class="alert alert-success">
          <i class="bi bi-check-circle"></i> สมัครเรียบร้อย! บัญชีของคุณกำลัง<strong>รอผู้ดูแลระบบอนุมัติ</strong> เมื่ออนุมัติแล้วจึงเข้าใช้งานได้
        </div>
        <div class="d-grid"><a href="<?= htmlspecialchars($base) ?>/login.php" class="btn btn-light border">ไปหน้าเข้าสู่ระบบ</a></div>
      <?php else: ?>
        <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
          <div class="mb-3">
            <label class="form-label section-title">ชื่อร้าน</label>
            <input type="text" name="shop_name" class="form-control soft-input" required placeholder="เช่น สตูดิโอแต่งหน้าของฉัน" value="<?= htmlspecialchars($_POST['shop_name'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label section-title">ชื่อผู้ใช้ <span class="title-hint">a-z, 0-9, _ .</span></label>
            <input type="text" name="username" class="form-control soft-input" required placeholder="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label section-title">รหัสผ่าน <span class="title-hint">อย่างน้อย 6 ตัว</span></label>
            <input type="password" name="password" class="form-control soft-input" required minlength="6">
          </div>
          <div class="mb-4">
            <label class="form-label section-title">ยืนยันรหัสผ่าน</label>
            <input type="password" name="confirm_password" class="form-control soft-input" required minlength="6">
          </div>
          <div class="d-grid"><button type="submit" class="btn save-btn">สมัคร</button></div>
        </form>
        <div class="text-center mt-4">
          <a href="<?= htmlspecialchars($base) ?>/login.php" class="text-decoration-none small text-muted">มีบัญชีแล้ว? เข้าสู่ระบบ</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
