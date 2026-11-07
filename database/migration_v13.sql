-- =====================================================================
-- Migration v13: ช่องทางโซเชียลของคิว + แท็กช่วงเวลา (เวลาเริ่ม/จบไม่บังคับ)
--   mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v13.sql
--
-- start_time/end_time ยังมีค่าเสมอ: คิวที่ไม่ได้ใส่เวลาจะเก็บช่วงรวมของแท็กไว้ (time_exact = 0)
-- การกันคิวชนใช้ช่วงจริงของแท็กจาก app/booking_helpers.php เช่น เช้าเย็นคือ 2 ช่วงแยกกัน
-- รันซ้ำได้ปลอดภัย (ต้องใช้ MariaDB)
-- =====================================================================

USE makeup_booking;

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS social VARCHAR(500) NULL COMMENT 'ชื่อโซเชียลหรือลิงก์ของลูกค้า' AFTER customer_phone,
    ADD COLUMN IF NOT EXISTS time_slot ENUM('morning','noon','evening','morning_noon','morning_evening','noon_evening') NULL COMMENT 'แท็กช่วงเวลา' AFTER end_time,
    ADD COLUMN IF NOT EXISTS time_exact TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = ระบุเวลาจริง, 0 = มีแค่แท็กหรือยังไม่ระบุ' AFTER time_slot;
