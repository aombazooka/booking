-- =====================================================================
-- Migration v9: ช่างหลายคนต่อคิว + มัดจำจ่ายช่าง/สลิปรายคน
--   mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v9.sql
--
-- สำคัญ — เงินคนละก้อนกัน อย่าสับสน:
--   bookings.deposit           = มัดจำที่ "ลูกค้าโอนเข้า" ร้าน (ของเดิม ไม่แตะ)
--   booking_staff.deposit_paid = มัดจำที่ "จ่ายออก" ให้ช่างแต่ละคน (ของใหม่)
--
-- bookings.staff_id ยังคงอยู่และเก็บ "ช่างหลัก" เสมอ เพื่อให้กันคิวชน/ตัวกรอง/
-- สีตามช่าง/รายงาน ทำงานเหมือนเดิม
-- =====================================================================

USE makeup_booking;

CREATE TABLE IF NOT EXISTS booking_staff (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id   INT UNSIGNED NOT NULL,
    staff_id     INT UNSIGNED NOT NULL,
    role         ENUM('main','assist') NOT NULL DEFAULT 'assist' COMMENT 'main = ช่างหลัก (มีได้แถวเดียวต่อคิว)',
    deposit_paid DECIMAL(10,2) NULL COMMENT 'มัดจำที่จ่ายให้ช่างคนนี้',
    slip_path    VARCHAR(255) NULL COMMENT 'ชื่อไฟล์สลิปโอนให้ช่างคนนี้ใน uploads/slips',
    sort_order   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_booking_staff (booking_id, staff_id),
    KEY idx_bs_staff (staff_id),
    CONSTRAINT fk_bs_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
    CONSTRAINT fk_bs_staff   FOREIGN KEY (staff_id)   REFERENCES staff (id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ย้ายข้อมูลเดิม: ทุกคิวที่เคยระบุช่างไว้ → กลายเป็นแถว "ช่างหลัก"
-- (มัดจำ/สลิปเว้นว่าง เพราะของเดิมเป็นเงินที่ลูกค้าจ่ายเข้า คนละก้อนกับมัดจำจ่ายช่าง)
INSERT IGNORE INTO booking_staff (booking_id, staff_id, role, sort_order)
SELECT id, staff_id, 'main', 0 FROM bookings WHERE staff_id IS NOT NULL;
