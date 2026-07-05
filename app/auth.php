<?php
/**
 * ระบบ login หลังบ้าน (admin) — ใช้ session + password_hash
 */

function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // cookie flags ให้เข้มขึ้น: HttpOnly กัน JS อ่าน, SameSite=Lax กัน CSRF, Secure เมื่อเป็น HTTPS
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $secure,
        ]);
        session_start();
    }
}

/** คืน CSRF token ของ session (สร้างถ้ายังไม่มี) */
function csrfToken(): string
{
    startSession();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

/**
 * ตรวจ CSRF token สำหรับคำขอที่ "login แล้ว" และเป็นการเปลี่ยนแปลงข้อมูล
 * รับ token จาก header X-CSRF-Token หรือฟิลด์ csrf — ไม่ผ่าน = 403
 * (คำขอสาธารณะที่ไม่ได้ login ข้ามการตรวจ เพราะไม่มี session ให้โจมตี)
 */
function requireCsrf(): void
{
    if (!isLoggedIn()) {
        return;
    }
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if ($sent === '') {
        $body = json_decode(file_get_contents('php://input'), true);
        $sent = is_array($body) ? ($body['csrf'] ?? '') : '';
    }
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $sent)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token ไม่ถูกต้อง โปรดรีเฟรชหน้าแล้วลองใหม่']);
        exit;
    }
}

function isLoggedIn(): bool
{
    startSession();
    return !empty($_SESSION['admin_user_id']);
}

function currentUsername(): string
{
    startSession();
    return (string) ($_SESSION['admin_username'] ?? '');
}

function currentUserId(): int
{
    startSession();
    return (int) ($_SESSION['admin_user_id'] ?? 0);
}

function currentRole(): string
{
    startSession();
    return (string) ($_SESSION['admin_role'] ?? 'owner');
}

function isSuperAdmin(): bool
{
    return currentRole() === 'admin';
}

/**
 * id ของร้านที่กำลัง "จัดการข้อมูล" อยู่ (ใช้ scope ทุก query)
 * - ผู้ใช้ทั่วไป = ตัวเองเสมอ
 * - ซูเปอร์แอดมิน = ร้านที่เลือกสลับเข้าไปจัดการ (ถ้ามี) ไม่งั้น = ตัวเอง
 */
function ownerId(): int
{
    startSession();
    if (isSuperAdmin() && !empty($_SESSION['acting_shop_id'])) {
        return (int) $_SESSION['acting_shop_id'];
    }
    return currentUserId();
}

/** id ร้านที่ซูเปอร์แอดมินกำลังสลับเข้าไปจัดการ (0 = จัดการร้านตัวเอง) */
function actingShopId(): int
{
    startSession();
    return (isSuperAdmin() && !empty($_SESSION['acting_shop_id'])) ? (int) $_SESSION['acting_shop_id'] : 0;
}

/** ซูเปอร์แอดมินสลับเข้าไปจัดการร้านอื่น (id=0 = กลับมาร้านตัวเอง) */
function setActingShop(int $shopId): void
{
    startSession();
    if (!isSuperAdmin()) { return; }
    if ($shopId <= 0 || $shopId === currentUserId()) {
        unset($_SESSION['acting_shop_id']);
    } else {
        $_SESSION['acting_shop_id'] = $shopId;
    }
}

/** เฉพาะผู้ดูแลระบบ (role=admin) — สำหรับหน้าอนุมัติผู้ใช้ */
function requireSuperAdmin(): void
{
    requireLogin();
    if (!isSuperAdmin()) {
        http_response_code(403);
        exit('เฉพาะผู้ดูแลระบบเท่านั้น');
    }
}

/**
 * ตรวจสอบ username/password กับตาราง app_users
 * คืน true ถ้าสำเร็จ (และตั้ง session)
 */
/**
 * ตรวจ username/password + สถานะบัญชี
 * คืน: 'ok' (ตั้ง session แล้ว) | 'bad' (ผิด) | 'pending' (รออนุมัติ) | 'suspended' (ถูกระงับ)
 */
function attemptLogin(PDO $pdo, string $username, string $password): string
{
    $stmt = $pdo->prepare("SELECT id, username, password_hash, role, status FROM app_users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return 'bad';
    }
    if ($user['status'] === 'pending') {
        return 'pending';
    }
    if ($user['status'] === 'suspended') {
        return 'suspended';
    }
    startSession();
    session_regenerate_id(true);
    $_SESSION['admin_user_id'] = (int) $user['id'];
    $_SESSION['admin_username'] = $user['username'];
    $_SESSION['admin_role'] = $user['role'];
    return 'ok';
}

function logout(): void
{
    startSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * บังคับให้ต้อง login (สำหรับหน้า HTML) — ถ้ายังไม่ login จะ redirect ไป login.php
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        // ถ้าอยู่ในโฟลเดอร์ย่อย (เช่น /admin, /api) ให้ถอยขึ้นรากโปรเจกต์
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        $base = rtrim($scriptDir, '/');
        if (in_array(basename($scriptDir), ['admin', 'api'], true)) {
            $base = rtrim(dirname($scriptDir), '/');
        }
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . $base . '/login.php?next=' . $next);
        exit;
    }
}

/**
 * บังคับ login สำหรับ API (คืน JSON 401 แทน redirect)
 */
function requireLoginApi(): void
{
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'ต้องเข้าสู่ระบบก่อน']);
        exit;
    }
}
