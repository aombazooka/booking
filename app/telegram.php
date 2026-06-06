<?php
/**
 * Telegram: โหลด/บันทึกการตั้งค่า และส่งข้อความ
 */
$dataDir = dirname(__DIR__) . '/data';
$file = $dataDir . '/telegram.json';

function getTelegramSettings(): array
{
    global $file;
    $default = [
        'bot_token' => '',
        'chat_id' => '',
        'notify_time' => '18:00',
        'last_sent_date' => '',
    ];
    if (!is_file($file)) {
        return $default;
    }
    $json = @file_get_contents($file);
    if ($json === false) {
        return $default;
    }
    $data = @json_decode($json, true);
    return is_array($data) ? array_merge($default, $data) : $default;
}

function saveTelegramSettings(array $data): bool
{
    global $dataDir, $file;
    $allowed = ['bot_token', 'chat_id', 'notify_time', 'last_sent_date'];
    $out = [];
    foreach ($allowed as $k) {
        $out[$k] = isset($data[$k]) ? (string) $data[$k] : '';
    }
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0755, true);
    }
    return file_put_contents($file, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
}

function updateLastSentDate(string $date): void
{
    $s = getTelegramSettings();
    $s['last_sent_date'] = $date;
    saveTelegramSettings($s);
}

/**
 * ส่งข้อความไป Telegram
 * @return array ['ok' => bool, 'error' => string|null]
 */
function sendTelegramMessage(string $text): array
{
    $s = getTelegramSettings();
    $token = trim($s['bot_token']);
    $chatId = trim($s['chat_id']);
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
