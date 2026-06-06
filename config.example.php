<?php
/**
 * ตัวอย่างไฟล์ตั้งค่า — คัดลอกเป็น config.php แล้วแก้ค่าให้ตรงกับ MySQL ของคุณ
 *   copy config.example.php config.php   (Windows)
 *   cp   config.example.php config.php   (Linux/Mac)
 */
return [
    'db' => [
        'host'    => '127.0.0.1',
        'name'    => 'makeup_booking',
        'user'    => 'YOUR_DB_USER',
        'pass'    => 'YOUR_DB_PASSWORD',
        'charset' => 'utf8mb4',
    ],
];
