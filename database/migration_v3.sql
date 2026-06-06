-- =====================================================================
-- Migration v3: ช่าง/พนักงาน (staff) + ผูกกับการจอง + กันคิวชนแยกตามช่าง
-- รันต่อจาก migration_v2.sql โดยไม่ลบข้อมูลเดิม (รันซ้ำได้ปลอดภัย)
--   mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v3.sql
-- =====================================================================

USE makeup_booking;

-- ตารางช่าง/พนักงาน
CREATE TABLE IF NOT EXISTS staff (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(150) NOT NULL,
    phone      VARCHAR(50)  NULL,
    color_hex  VARCHAR(7)   NOT NULL DEFAULT '#a78bfa',
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ผูกการจองกับช่าง (NULL = ยังไม่ระบุช่าง)
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS staff_id INT UNSIGNED NULL AFTER customer_id;

ALTER TABLE bookings ADD KEY IF NOT EXISTS idx_staff (staff_id);

-- FK ไป staff (ลบช่างแล้วงานยังอยู่ แต่ staff_id = NULL) — เช็คก่อนเพื่อ rerun ได้
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'bookings'
      AND CONSTRAINT_NAME = 'fk_booking_staff'
);
SET @fk_sql := IF(@fk_exists = 0,
    'ALTER TABLE bookings ADD CONSTRAINT fk_booking_staff FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE SET NULL ON UPDATE RESTRICT',
    'DO 0'
);
PREPARE _fk_stmt FROM @fk_sql;
EXECUTE _fk_stmt;
DEALLOCATE PREPARE _fk_stmt;

-- ช่างเริ่มต้น 1 คน (ชื่อร้าน) — แก้ไข/เพิ่มได้ผ่านหน้า admin
INSERT INTO staff (name, color_hex, sort_order)
SELECT 'ป๊อปอาย', '#ec4899', 1
WHERE NOT EXISTS (SELECT 1 FROM staff);
