-- =====================================================================
-- Migration v5: สลิปมัดจำ (เก็บชื่อไฟล์รูปสลิปที่อัปโหลด)
--   mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v5.sql
-- =====================================================================

USE makeup_booking;

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS slip_path VARCHAR(255) NULL AFTER payment_status;
