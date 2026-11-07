-- =====================================================================
-- Migration v12: รายจ่ายของร้าน (ใช้คำนวณกำไรรายเดือน)
--   mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v12.sql
-- รันซ้ำได้ปลอดภัย
-- =====================================================================

USE makeup_booking;

CREATE TABLE IF NOT EXISTS shop_expenses (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL COMMENT 'เจ้าของ (ร้าน)',
    expense_date DATE NOT NULL,
    category     VARCHAR(60) NOT NULL,
    amount       DECIMAL(10,2) NOT NULL,
    note         VARCHAR(200) NULL,
    is_recurring TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'รายจ่ายประจำ ดึงต่อไปเดือนถัดไปได้',
    copied_from  INT UNSIGNED NULL COMMENT 'id รายการต้นทาง กันดึงซ้ำในเดือนเดียวกัน',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_exp_user_date (user_id, expense_date),
    CONSTRAINT fk_exp_user FOREIGN KEY (user_id) REFERENCES app_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
