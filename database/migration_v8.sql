-- =====================================================================
-- Migration v8: ค่าเดินทาง (travel_fee) ต่อการจอง
--   mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v8.sql
-- =====================================================================

USE makeup_booking;

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS travel_fee DECIMAL(10,2) NULL COMMENT 'ค่าเดินทาง' AFTER deposit;
