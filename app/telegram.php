<?php
/**
 * แจ้งเตือนร้าน: โหลด/บันทึกการตั้งค่าของแต่ละร้าน (ตาราง shop_telegram) และส่งข้อความผ่าน Telegram / LINE
 */

function getTelegramSettings(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("SELECT bot_token, chat_id, notify_time, last_sent_date, notify_new, line_token, line_to
                           FROM shop_telegram WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: ['bot_token' => '', 'chat_id' => '', 'notify_time' => '18:00', 'last_sent_date' => null,
                    'notify_new' => 1, 'line_token' => '', 'line_to' => ''];
}

function saveTelegramSettings(PDO $pdo, int $userId, array $data): bool
{
    $stmt = $pdo->prepare("
        INSERT INTO shop_telegram (user_id, bot_token, chat_id, notify_time, notify_new, line_token, line_to)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE bot_token = VALUES(bot_token), chat_id = VALUES(chat_id), notify_time = VALUES(notify_time),
                                notify_new = VALUES(notify_new), line_token = VALUES(line_token), line_to = VALUES(line_to)
    ");
    return $stmt->execute([$userId, (string) $data['bot_token'], (string) $data['chat_id'], (string) $data['notify_time'],
                           empty($data['notify_new']) ? 0 : 1, (string) $data['line_token'], (string) $data['line_to']]);
}

function updateLastSentDate(PDO $pdo, int $userId, string $date): void
{
    $pdo->prepare("UPDATE shop_telegram SET last_sent_date = ? WHERE user_id = ?")->execute([$date, $userId]);
}

/**
 * ส่งข้อความไป Telegram ตามค่าของร้าน
 * @param array $settings ต้องมี bot_token และ chat_id
 * @return array ['ok' => bool, 'error' => string|null]
 */
function sendTelegramMessage(array $settings, string $text): array
{
    $token = trim($settings['bot_token']);
    $chatId = trim($settings['chat_id']);
    if ($token === '' || $chatId === '') {
        return ['ok' => false, 'error' => 'ยังไม่ได้ตั้งค่า Bot Token หรือ Chat ID'];
    }
    $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage';
    $body = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($body),
            'timeout' => 10,
        ],
    ]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) {
        return ['ok' => false, 'error' => 'เชื่อมต่อ Telegram ไม่ได้'];
    }
    $data = @json_decode($res, true);
    if (isset($data['ok']) && $data['ok'] === true) {
        return ['ok' => true, 'error' => null];
    }
    $err = $data['description'] ?? 'ส่งไม่สำเร็จ';
    return ['ok' => false, 'error' => $err];
}

/**
 * ส่งข้อความไป LINE ผ่าน Messaging API (LINE Notify ปิดบริการแล้ว)
 * @param array $settings ต้องมี line_token และ line_to
 */
function sendLineMessage(array $settings, string $text): array
{
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . trim($settings['line_token']),
            'content' => json_encode(['to' => trim($settings['line_to']), 'messages' => [['type' => 'text', 'text' => $text]]]),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $res = @file_get_contents('https://api.line.me/v2/bot/message/push', false, $ctx);
    if ($res === false) {
        return ['ok' => false, 'error' => 'เชื่อมต่อ LINE ไม่ได้'];
    }
    $status = (int) (explode(' ', $http_response_header[0] ?? '')[1] ?? 0);
    if ($status === 200) {
        return ['ok' => true, 'error' => null];
    }
    return ['ok' => false, 'error' => json_decode($res, true)['message'] ?? 'ส่ง LINE ไม่สำเร็จ'];
}

/**
 * ส่งข้อความไปทุกช่องทางที่ร้านตั้งค่าไว้ — สำเร็จเมื่อส่งได้อย่างน้อยหนึ่งช่องทาง
 * @param string $text ข้อความแบบ HTML ของ Telegram (LINE จะได้แบบตัด tag ออก)
 */
function sendShopNotification(array $settings, string $text): array
{
    $results = [];
    if (trim($settings['bot_token']) !== '' && trim($settings['chat_id']) !== '') {
        $results[] = sendTelegramMessage($settings, $text);
    }
    if (trim($settings['line_token']) !== '' && trim($settings['line_to']) !== '') {
        $results[] = sendLineMessage($settings, html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8'));
    }
    if (count($results) === 0) {
        return ['ok' => false, 'error' => 'ยังไม่ได้ตั้งค่าช่องทางแจ้งเตือน'];
    }
    $errors = array_filter(array_column($results, 'error'));
    return ['ok' => count($errors) < count($results), 'error' => $errors ? implode(' / ', $errors) : null];
}

/** แจ้งร้านทันทีเมื่อมีเหตุการณ์สำคัญ (จองใหม่/แนบสลิป) — ไม่ทำให้งานหลักล้มถ้าส่งไม่สำเร็จ */
function notifyShopNow(PDO $pdo, int $userId, string $text): void
{
    try {
        $settings = getTelegramSettings($pdo, $userId);
        if (!empty($settings['notify_new'])) {
            sendShopNotification($settings, $text);
        }
    } catch (Throwable $e) {
        error_log('notifyShopNow: ' . $e->getMessage());
    }
}
