-- =====================================================================
-- Migration v4: ผูกบริการเสริมกับประเภทงาน (หลายต่อหลาย)
-- บริการที่ไม่ผูกประเภทใดเลย = บริการทั่วไป (แสดงทุกประเภท)
--   mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v4.sql
-- =====================================================================

USE makeup_booking;

CREATE TABLE IF NOT EXISTS service_category_link (
    service_id  TINYINT UNSIGNED NOT NULL,
    category_id TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (service_id, category_id),
    KEY fk_scl_service (service_id),
    KEY fk_scl_category (category_id),
    CONSTRAINT fk_scl_service  FOREIGN KEY (service_id)  REFERENCES booking_services (id)   ON DELETE CASCADE,
    CONSTRAINT fk_scl_category FOREIGN KEY (category_id) REFERENCES booking_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
