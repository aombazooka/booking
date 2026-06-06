-- =====================================================================
-- Migration v2: หลายบริการ + ลูกค้าจองเอง + ราคา/มัดจำ + กันคิวชน + login
-- รันกับ DB เดิม (makeup_booking) โดยไม่ลบข้อมูลคิวเดิม
-- ใช้ ADD COLUMN IF NOT EXISTS (รองรับ MariaDB 10.0.2+) ให้รันซ้ำได้ปลอดภัย
--   mysql -u admin -p makeup_booking < database/migration_v2.sql
-- =====================================================================

USE makeup_booking;

-- ---------------------------------------------------------------------
-- 1) ฐานข้อมูลลูกค้า
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(200) NOT NULL,
    phone      VARCHAR(50)  NOT NULL,
    note       TEXT         NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customer_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2) ผู้ใช้หลังบ้าน (admin)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- หมายเหตุ: สร้าง admin ด้วย tools/create_admin.php (จะ hash รหัสให้)

-- ---------------------------------------------------------------------
-- 3) ขยายตาราง bookings: ราคา/มัดจำ/สถานะจ่าย/ที่มา/ผูกลูกค้า
-- ---------------------------------------------------------------------
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS customer_id    INT UNSIGNED NULL AFTER id,
    ADD COLUMN IF NOT EXISTS price          DECIMAL(10,2) NULL AFTER num_people,
    ADD COLUMN IF NOT EXISTS deposit        DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER price,
    ADD COLUMN IF NOT EXISTS payment_status ENUM('unpaid','deposit_paid','paid') NOT NULL DEFAULT 'unpaid' AFTER deposit,
    ADD COLUMN IF NOT EXISTS source         ENUM('admin','customer') NOT NULL DEFAULT 'admin' AFTER status;

ALTER TABLE bookings ADD KEY IF NOT EXISTS idx_customer (customer_id);

-- FK ไป customers (ลบลูกค้าแล้วคิวยังอยู่ แต่ customer_id = NULL)
-- MariaDB ไม่รองรับ ADD CONSTRAINT IF NOT EXISTS สำหรับ FK จึงเช็คก่อนด้วย dynamic SQL (rerun ได้)
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'bookings'
      AND CONSTRAINT_NAME = 'fk_booking_customer'
);
SET @fk_sql := IF(@fk_exists = 0,
    'ALTER TABLE bookings ADD CONSTRAINT fk_booking_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL ON UPDATE RESTRICT',
    'DO 0'
);
PREPARE _fk_stmt FROM @fk_sql;
EXECUTE _fk_stmt;
DEALLOCATE PREPARE _fk_stmt;

-- ---------------------------------------------------------------------
-- 4) ขยายตาราง booking_categories: ราคา/มัดจำเริ่มต้น/ระยะเวลา/ป้ายจำนวน/เปิดปิด
-- ---------------------------------------------------------------------
ALTER TABLE booking_categories
    ADD COLUMN IF NOT EXISTS price           DECIMAL(10,2) NULL AFTER color_hex,
    ADD COLUMN IF NOT EXISTS deposit_default DECIMAL(10,2) NULL AFTER price,
    ADD COLUMN IF NOT EXISTS duration_min    SMALLINT UNSIGNED NULL AFTER deposit_default,
    ADD COLUMN IF NOT EXISTS count_label     VARCHAR(40) NULL AFTER duration_min,
    ADD COLUMN IF NOT EXISTS is_active       TINYINT(1) NOT NULL DEFAULT 1 AFTER count_label;

-- ---------------------------------------------------------------------
-- 5) ขยายตาราง booking_services: ราคา/เปิดปิด
-- ---------------------------------------------------------------------
ALTER TABLE booking_services
    ADD COLUMN IF NOT EXISTS price     DECIMAL(10,2) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER price;

-- ---------------------------------------------------------------------
-- 6) ข้อมูลตัวอย่างบริการใหม่ (ถ่ายภาพ) — ปรับเพิ่มเองได้ผ่านหน้า admin
-- ---------------------------------------------------------------------
INSERT INTO booking_categories (name, color_hex, price, deposit_default, duration_min, count_label, sort_order, is_active)
SELECT 'ถ่ายภาพ', '#10b981', NULL, NULL, 120, 'จำนวนชั่วโมง', 3, 1
WHERE NOT EXISTS (SELECT 1 FROM booking_categories WHERE name = 'ถ่ายภาพ');

-- เติม count_label เริ่มต้นให้ประเภทแต่งหน้าเดิม (ถ้ายังว่าง)
UPDATE booking_categories SET count_label = 'จำนวนคน' WHERE count_label IS NULL;
