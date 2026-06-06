<?php
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
require __DIR__ . '/app/auth.php';
require __DIR__ . '/app/booking_helpers.php';

// login อยู่แล้ว → กลับหน้าหลัก
if (isLoggedIn()) {
    header('Location: ' . $base . '/');
    exit;
}

$error = '';
$next = $_GET['next'] ?? ($_POST['next'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    // กัน brute-force: จำกัดความพยายามเข้าสู่ระบบต่อ IP (10 ครั้ง/15 นาที)
    if (!rateLimitOk('login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 10, 900)) {
        $error = 'พยายามเข้าสู่ระบบบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่';
    } else {
    try {
        $pdo = require __DIR__ . '/app/db.php';
        if (attemptLogin($pdo, $username, $password)) {
            // กัน open-redirect: ยอมเฉพาะ path ภายในเว็บนี้
            $dest = $base . '/';
            if (is_string($next) && $next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//')) {
                $dest = $next;
            }
            header('Location: ' . $dest);
            exit;
        }
        $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
    } catch (Throwable $e) {
        $error = 'เชื่อมต่อฐานข้อมูลไม่ได้ โปรดตรวจสอบ config.php';
    }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>เข้าสู่ระบบ</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&display=swap" rel="stylesheet">
  <link href="<?= htmlspecialchars($base) ?>/assets/app.css" rel="stylesheet">
  <style>
    body { min-height: 100vh; display: flex; align-items: center; }
    .login-card { max-width: 400px; width: 100%; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="mx-auto login-card p-4 p-md-5 rise">
      <div class="text-center mb-4">
        <span class="logo-badge mb-2"><i class="bi bi-flower1"></i></span>
        <div class="eyebrow mt-3">Beauty Booking</div>
        <h1 class="brand mt-1 mb-0" style="font-size: 1.6rem; font-weight: 600;">เข้าสู่ระบบ</h1>
        <small class="text-muted">สำหรับเจ้าของร้าน</small>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
        <div class="mb-3">
          <label class="form-label fw-semibold">ชื่อผู้ใช้</label>
          <input type="text" name="username" class="form-control" required autofocus autocomplete="username">
        </div>
        <div class="mb-4">
          <label class="form-label fw-semibold">รหัสผ่าน</label>
          <input type="password" name="password" class="form-control" required autocomplete="current-password">
        </div>
        <div class="d-grid">
          <button type="submit" class="btn btn-login">เข้าสู่ระบบ</button>
        </div>
      </form>

      <div class="text-center mt-4">
        <a href="<?= htmlspecialchars($base) ?>/book.php" class="text-decoration-none small text-muted">
          <i class="bi bi-box-arrow-up-right"></i> ลูกค้าจองคิว (หน้าสาธารณะ)
        </a>
      </div>
    </div>
  </div>
</body>
</html>
