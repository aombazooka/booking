<?php
/**
 * อนุมัติ/จัดการผู้ใช้ (เฉพาะซูเปอร์แอดมิน role=admin)
 */
require dirname(__DIR__) . '/app/auth.php';
requireSuperAdmin();
$projectBase = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
$pdo = require dirname(__DIR__) . '/app/db.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $token)) {
        $msg = 'เซสชันหมดอายุ โปรดรีเฟรช';
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $action = $_POST['action'] ?? '';
        if ($action === 'manage') {
            // สลับเข้าไปจัดการร้านนั้น (id=0 = กลับมาร้านตัวเอง)
            setActingShop($id);
            header('Location: ' . $projectBase . '/');
            exit;
        }
        $newStatus = ['approve' => 'active', 'suspend' => 'suspended', 'activate' => 'active'][$action] ?? '';
        // กันแก้สถานะตัวเอง
        if ($id > 0 && $id !== currentUserId() && $newStatus !== '') {
            $pdo->prepare("UPDATE app_users SET status = ? WHERE id = ? AND role <> 'admin'")->execute([$newStatus, $id]);
            $msg = 'อัปเดตสถานะเรียบร้อย';
        }
    }
}

$users = $pdo->query("SELECT id, username, shop_name, shop_slug, role, status, created_at FROM app_users ORDER BY (status='pending') DESC, id")->fetchAll(PDO::FETCH_ASSOC);
$csrf = csrfToken();
$statusLabel = ['pending' => '<span class="badge text-bg-warning">รออนุมัติ</span>', 'active' => '<span class="badge text-bg-success">ใช้งานได้</span>', 'suspended' => '<span class="badge text-bg-secondary">ระงับ</span>'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>จัดการผู้ใช้</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&display=swap" rel="stylesheet">
  <link href="<?= htmlspecialchars($projectBase) ?>/assets/app.css" rel="stylesheet">
  <style> table td, table th { vertical-align: middle; font-size: 0.92rem; } </style>
</head>
<body>
  <header class="head py-3 mb-3">
    <div class="wrap-wide d-flex justify-content-between align-items-center">
      <div>
        <a href="<?= htmlspecialchars($projectBase) ?>/" class="text-muted text-decoration-none small"><i class="bi bi-arrow-left"></i> กลับ</a>
        <h1 class="brand mb-0 mt-1" style="font-size: 1.35rem; font-weight: 600;">จัดการผู้ใช้ / อนุมัติร้าน</h1>
        <small class="text-muted">เฉพาะผู้ดูแลระบบ</small>
      </div>
    </div>
  </header>

  <div class="wrap-wide pb-5 rise">
    <?php if ($msg): ?><div class="alert alert-info py-2"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <div class="card-soft p-3 p-md-4">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr class="text-muted"><th>ร้าน</th><th>ผู้ใช้</th><th>ลิงก์จอง</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
          <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?= htmlspecialchars($u['shop_name'] ?? '-') ?><?= $u['role'] === 'admin' ? ' <span class="badge text-bg-dark">แอดมิน</span>' : '' ?></td>
              <td><?= htmlspecialchars($u['username']) ?></td>
              <td><a href="<?= htmlspecialchars($projectBase) ?>/book.php?shop=<?= urlencode($u['shop_slug']) ?>" target="_blank" class="small">?shop=<?= htmlspecialchars($u['shop_slug']) ?></a></td>
              <td><?= $statusLabel[$u['status']] ?? htmlspecialchars($u['status']) ?></td>
              <td class="text-end text-nowrap">
                <?php if ($u['status'] === 'active'): ?>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <button name="action" value="manage" class="btn btn-sm btn-dark"><i class="bi bi-box-arrow-in-right"></i> จัดการร้านนี้</button>
                  </form>
                <?php endif; ?>
                <?php if ($u['role'] !== 'admin' && $u['id'] !== currentUserId()): ?>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <?php if ($u['status'] === 'pending' || $u['status'] === 'suspended'): ?>
                      <button name="action" value="approve" class="btn btn-sm btn-grad"><i class="bi bi-check-lg"></i> อนุมัติ</button>
                    <?php endif; ?>
                    <?php if ($u['status'] === 'active'): ?>
                      <button name="action" value="suspend" class="btn btn-sm btn-outline-danger">ระงับ</button>
                    <?php endif; ?>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
