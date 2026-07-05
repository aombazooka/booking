-- =====================================================================
-- Migration v6: ระบบหลายร้าน (multi-tenant) — แต่ละผู้ใช้เป็นเจ้าของข้อมูลของตัวเอง
--   mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v6.sql
-- ข้อมูลเดิมทั้งหมดยกให้ผู้ใช้ id ต่ำสุด (admin) และตั้งเป็นซูเปอร์แอดมิน
-- =====================================================================

USE makeup_booking;

-- 1) ขยาย app_users: บทบาท, สถานะอนุมัติ, ชื่อร้าน, slug สำหรับลิงก์จอง
ALTER TABLE app_users
    ADD COLUMN IF NOT EXISTS role      ENUM('admin','owner')               NOT NULL DEFAULT 'owner'  AFTER username,
    ADD COLUMN IF NOT EXISTS status    ENUM('pending','active','suspended') NOT NULL DEFAULT 'active' AFTER role,
    ADD COLUMN IF NOT EXISTS shop_name VARCHAR(150) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS shop_slug VARCHAR(100) NULL AFTER shop_name;

ALTER TABLE app_users ADD UNIQUE KEY IF NOT EXISTS uniq_shop_slug (shop_slug);

-- 2) เพิ่ม user_id (เจ้าของ) ในทุกตารางข้อมูล
ALTER TABLE booking_categories ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NULL AFTER id;
ALTER TABLE booking_services   ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NULL AFTER id;
ALTER TABLE staff              ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NULL AFTER id;
ALTER TABLE bookings           ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NULL AFTER id;
ALTER TABLE customers          ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NULL AFTER id;

ALTER TABLE booking_categories ADD KEY IF NOT EXISTS idx_bc_user (user_id);
ALTER TABLE booking_services   ADD KEY IF NOT EXISTS idx_bs_user (user_id);
ALTER TABLE staff              ADD KEY IF NOT EXISTS idx_st_user (user_id);
ALTER TABLE bookings           ADD KEY IF NOT EXISTS idx_bk_user (user_id);
ALTER TABLE customers          ADD KEY IF NOT EXISTS idx_cu_user (user_id);

-- 3) ตั้งผู้ใช้แรกเป็นซูเปอร์แอดมิน + ยกข้อมูลเดิมทั้งหมดให้
SET @first := (SELECT MIN(id) FROM app_users);

UPDATE app_users SET role = 'admin', status = 'active' WHERE id = @first;
UPDATE app_users SET shop_slug = username WHERE shop_slug IS NULL OR shop_slug = '';
UPDATE app_users SET shop_name = CONCAT('ร้านของ ', username) WHERE shop_name IS NULL OR shop_name = '';

UPDATE booking_categories SET user_id = @first WHERE user_id IS NULL;
UPDATE booking_services   SET user_id = @first WHERE user_id IS NULL;
UPDATE staff              SET user_id = @first WHERE user_id IS NULL;
UPDATE bookings           SET user_id = @first WHERE user_id IS NULL;
UPDATE customers          SET user_id = @first WHERE user_id IS NULL;
