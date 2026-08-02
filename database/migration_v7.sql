-- v7: ปฏิทินวันฤกษ์ — เก็บวันฤกษ์ที่ร้าน "กรอกเอง" (จากโหรที่เชื่อถือ)
-- ส่วนที่คำนวณได้ (จันทรคติ/กาลโยค) ไม่ต้องเก็บ เพราะคำนวณสดจาก app/thai_lunar.php
USE makeup_booking;

CREATE TABLE IF NOT EXISTS auspicious_days (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL COMMENT 'เจ้าของ (ร้าน)',
    date       DATE         NOT NULL,
    label      VARCHAR(100) NOT NULL DEFAULT 'วันฤกษ์' COMMENT 'เช่น ฤกษ์แต่งงาน / ฤกษ์ขึ้นบ้านใหม่',
    grade      ENUM('great','good','avoid') NOT NULL DEFAULT 'good' COMMENT 'มหาฤกษ์ / ฤกษ์ดี / วันควรเลี่ยง',
    source     VARCHAR(120) NULL COMMENT 'ที่มา เช่น ชื่อโหร/สำนัก — ไว้ตรวจย้อนหลัง',
    note       TEXT         NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_user_date (user_id, date),
    KEY idx_aus_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
