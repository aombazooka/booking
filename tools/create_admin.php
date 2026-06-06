<?php
/**
 * สร้าง/รีเซ็ตผู้ใช้ admin หลังบ้าน (เก็บรหัสแบบ hash)
 *
 * วิธีใช้ (รันจาก command line):
 *   php tools/create_admin.php <username> <password>
 *
 * ตัวอย่าง:
 *   php tools/create_admin.php admin mypassword123
 *
 * ถ้า username มีอยู่แล้ว จะอัปเดตรหัสผ่านให้ (รีเซ็ตรหัส)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("สคริปต์นี้รันได้จาก command line เท่านั้น\n");
}

$username = $argv[1] ?? '';
$password = $argv[2] ?? '';

if ($username === '' || $password === '') {
    fwrite(STDERR, "ใช้งาน: php tools/create_admin.php <username> <password>\n");
    exit(1);
}
if (strlen($password) < 6) {
    fwrite(STDERR, "รหัสผ่านควรยาวอย่างน้อย 6 ตัวอักษร\n");
    exit(1);
}

$pdo = require dirname(__DIR__) . '/app/db.php';
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
    INSERT INTO app_users (username, password_hash) VALUES (:u, :h)
    ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)
");
$stmt->execute([':u' => $username, ':h' => $hash]);

echo "สร้าง/อัปเดตผู้ใช้ '{$username}' เรียบร้อย — เข้าสู่ระบบที่ login.php ได้เลย\n";
