-- =====================================================================
-- Migration v11: ตั้งค่าร้าน + ช่องเวลา + รับเงินรายรายการ + หน้าติดตามคิว
--   mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v11.sql
-- รันซ้ำได้ปลอดภัย (ต้องใช้ MariaDB)
-- =====================================================================

USE makeup_booking;

-- เวลาทำการ ช่องเวลา เวลาเผื่อเดินทาง เวลาถือคิว และ PromptPay ของแต่ละร้าน
CREATE TABLE IF NOT EXISTS shop_settings (
    user_id         INT UNSIGNED NOT NULL COMMENT 'เจ้าของ (ร้าน)',
    open_time       TIME NOT NULL DEFAULT '06:00:00',
    close_time      TIME NOT NULL DEFAULT '20:00:00',
    closed_weekdays VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'วันปิดประจำสัปดาห์ 0=อาทิตย์ ... 6=เสาร์ คั่นด้วย comma',
    slot_minutes    SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    buffer_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'เวลาเผื่อเดินทางก่อน/หลังคิว',
    hold_hours      SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ถือคิวรอมัดจำกี่ชั่วโมง (0 = ไม่ปล่อยอัตโนมัติ)',
    promptpay_id    VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'เบอร์มือถือ / เลขบัตร / e-Wallet',
    PRIMARY KEY (user_id),
    CONSTRAINT fk_ss_user FOREIGN KEY (user_id) REFERENCES app_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- วันหยุดร้าน (staff_id NULL) หรือวันลาของช่าง
CREATE TABLE IF NOT EXISTS shop_closures (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id   INT UNSIGNED NOT NULL COMMENT 'เจ้าของ (ร้าน)',
    staff_id  INT UNSIGNED NULL COMMENT 'NULL = ปิดทั้งร้าน',
    date_from DATE NOT NULL,
    date_to   DATE NOT NULL,
    note      VARCHAR(150) NULL,
    PRIMARY KEY (id),
    KEY idx_sc_user_date (user_id, date_from, date_to),
    CONSTRAINT fk_sc_staff FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- เงินที่รับจริงต่อคิว (bookings.payment_status คำนวณจากตารางนี้)
CREATE TABLE IF NOT EXISTS booking_payments (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id INT UNSIGNED NOT NULL,
    kind       ENUM('deposit','balance','refund') NOT NULL DEFAULT 'deposit',
    amount     DECIMAL(10,2) NOT NULL,
    method     ENUM('transfer','cash','other') NOT NULL DEFAULT 'transfer',
    paid_at    DATE NOT NULL,
    note       VARCHAR(200) NULL,
    created_by INT UNSIGNED NULL COMMENT 'ผู้ใช้หลังบ้านที่บันทึก',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bp_booking (booking_id),
    CONSTRAINT fk_bp_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS public_token CHAR(20) NULL COMMENT 'รหัสลับในลิงก์ติดตามคิวของลูกค้า' AFTER id,
    ADD COLUMN IF NOT EXISTS hold_until DATETIME NULL COMMENT 'ถือคิวรอมัดจำถึงเวลานี้' AFTER status,
    ADD COLUMN IF NOT EXISTS consent_at DATETIME NULL COMMENT 'เวลาที่ลูกค้ายินยอมตาม PDPA' AFTER hold_until,
    ADD UNIQUE INDEX IF NOT EXISTS uniq_public_token (public_token);

UPDATE bookings SET public_token = LEFT(SHA2(CONCAT(UUID(), RAND(), id), 256), 20) WHERE public_token IS NULL;

ALTER TABLE shop_telegram
    ADD COLUMN IF NOT EXISTS notify_new TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'แจ้งทันทีเมื่อมีจองใหม่/แนบสลิป',
    ADD COLUMN IF NOT EXISTS line_token VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'LINE Messaging API channel access token',
    ADD COLUMN IF NOT EXISTS line_to VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'userId หรือ groupId ที่รับแจ้งเตือน';

-- ยกยอดการจ่ายเดิมเข้าตารางรับเงิน เพื่อให้ยอดคงค้างถูกต้องตั้งแต่วันแรก
INSERT INTO booking_payments (booking_id, kind, amount, method, paid_at, note)
SELECT b.id, 'deposit', b.deposit, 'other', b.appointment_date, 'ยกยอดจากระบบเดิม'
FROM bookings b
WHERE b.payment_status = 'deposit_paid' AND b.deposit > 0
  AND NOT EXISTS (SELECT 1 FROM booking_payments p WHERE p.booking_id = b.id);

INSERT INTO booking_payments (booking_id, kind, amount, method, paid_at, note)
SELECT b.id, 'balance', COALESCE(b.price, 0) + COALESCE(b.travel_fee, 0), 'other', b.appointment_date, 'ยกยอดจากระบบเดิม'
FROM bookings b
WHERE b.payment_status = 'paid' AND COALESCE(b.price, 0) + COALESCE(b.travel_fee, 0) > 0
  AND NOT EXISTS (SELECT 1 FROM booking_payments p WHERE p.booking_id = b.id);

-- จัดรูปแบบเบอร์ลูกค้า รวมรายการเบอร์ซ้ำ แล้วกันซ้ำต่อร้าน
UPDATE customers SET phone = REGEXP_REPLACE(phone, '[^0-9+]', '');

UPDATE bookings b
JOIN customers c ON c.id = b.customer_id
JOIN (SELECT user_id, phone, MIN(id) keep_id FROM customers GROUP BY user_id, phone) k
  ON k.user_id = c.user_id AND k.phone = c.phone
SET b.customer_id = k.keep_id
WHERE b.customer_id <> k.keep_id;

DELETE c FROM customers c
JOIN (SELECT user_id, phone, MIN(id) keep_id FROM customers GROUP BY user_id, phone) k
  ON k.user_id = c.user_id AND k.phone = c.phone
WHERE c.id <> k.keep_id;

ALTER TABLE customers ADD UNIQUE INDEX IF NOT EXISTS uniq_customer_shop_phone (user_id, phone);
