-- =====================================================================
-- Migration v10: ขยาย id ประเภทงาน/บริการ + Telegram แยกต่อร้าน
--   mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v10.sql
--
-- id ของ booking_categories / booking_services เดิมเป็น TINYINT (สูงสุด 255) แต่ทุกร้านใช้ตารางเดียวกัน
-- ค่า Telegram เดิมอยู่ใน data/telegram.json ใช้ร่วมกันทุกร้าน → ย้ายมาเก็บในตาราง shop_telegram
-- (ต้องใช้ MariaDB เพราะ DROP FOREIGN KEY IF EXISTS; รันซ้ำได้ปลอดภัย)
-- =====================================================================

USE makeup_booking;

ALTER TABLE booking_category_pivot DROP FOREIGN KEY IF EXISTS fk_bcp_category;
ALTER TABLE booking_service_pivot  DROP FOREIGN KEY IF EXISTS fk_bsp_service;
ALTER TABLE service_category_link  DROP FOREIGN KEY IF EXISTS fk_scl_service;
ALTER TABLE service_category_link  DROP FOREIGN KEY IF EXISTS fk_scl_category;

ALTER TABLE booking_categories MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE booking_services   MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE booking_category_pivot MODIFY category_id INT UNSIGNED NOT NULL;
ALTER TABLE booking_service_pivot  MODIFY service_id  INT UNSIGNED NOT NULL;
ALTER TABLE service_category_link
    MODIFY service_id  INT UNSIGNED NOT NULL,
    MODIFY category_id INT UNSIGNED NOT NULL;

ALTER TABLE booking_category_pivot
    ADD CONSTRAINT fk_bcp_category FOREIGN KEY (category_id) REFERENCES booking_categories (id) ON DELETE CASCADE;
ALTER TABLE booking_service_pivot
    ADD CONSTRAINT fk_bsp_service FOREIGN KEY (service_id) REFERENCES booking_services (id) ON DELETE CASCADE;
ALTER TABLE service_category_link
    ADD CONSTRAINT fk_scl_service  FOREIGN KEY (service_id)  REFERENCES booking_services (id)   ON DELETE CASCADE,
    ADD CONSTRAINT fk_scl_category FOREIGN KEY (category_id) REFERENCES booking_categories (id) ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS shop_telegram (
    user_id        INT UNSIGNED NOT NULL COMMENT 'เจ้าของ (ร้าน)',
    bot_token      VARCHAR(100) NOT NULL DEFAULT '',
    chat_id        VARCHAR(64)  NOT NULL DEFAULT '',
    notify_time    VARCHAR(5)   NOT NULL DEFAULT '18:00',
    last_sent_date DATE         NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_st_user FOREIGN KEY (user_id) REFERENCES app_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
