-- ระบบจองคิวหลายบริการ (แต่งหน้า / ถ่ายภาพ / อื่นๆ) - โครงสร้างฐานข้อมูลแบบติดตั้งใหม่
-- รันครั้งแรก: import ไฟล์นี้ (สร้าง DB + ตาราง + ข้อมูลเริ่มต้น)
-- ถ้ามี DB เดิมอยู่แล้ว ให้ใช้ database/migration_v2.sql แทน (ไม่ลบข้อมูลเดิม)

CREATE DATABASE IF NOT EXISTS makeup_booking CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE makeup_booking;

-- ประเภทงาน (ติ๊กได้หลายข้อ) — รองรับราคา/มัดจำ/ระยะเวลา/ป้ายจำนวน/เปิดปิด
CREATE TABLE booking_categories (
    id              TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NULL COMMENT 'เจ้าของ (ร้าน)',
    name            VARCHAR(100) NOT NULL,
    color_hex       VARCHAR(7) NOT NULL DEFAULT '#6b7280',
    price           DECIMAL(10,2) NULL,
    deposit_default DECIMAL(10,2) NULL,
    duration_min    SMALLINT UNSIGNED NULL COMMENT 'ระยะเวลามาตรฐาน (นาที) ช่วยคำนวณเวลาจบ/ช่องว่าง',
    count_label     VARCHAR(40) NULL COMMENT 'ป้ายช่องจำนวน เช่น จำนวนคน / จำนวนชั่วโมง',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- บริการที่เลือก (ติ๊กได้หลายข้อ)
CREATE TABLE booking_services (
    id         TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NULL COMMENT 'เจ้าของ (ร้าน)',
    name       VARCHAR(100) NOT NULL,
    price      DECIMAL(10,2) NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ช่าง/พนักงาน
CREATE TABLE staff (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NULL COMMENT 'เจ้าของ (ร้าน)',
    name       VARCHAR(150) NOT NULL,
    phone      VARCHAR(50)  NULL,
    color_hex  VARCHAR(7)   NOT NULL DEFAULT '#a78bfa',
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ฐานข้อมูลลูกค้า
CREATE TABLE customers (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NULL COMMENT 'เจ้าของ (ร้าน)',
    name       VARCHAR(200) NOT NULL,
    phone      VARCHAR(50)  NOT NULL,
    note       TEXT         NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customer_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ผู้ใช้หลังบ้าน (admin) — สร้างด้วย tools/create_admin.php
CREATE TABLE app_users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(100) NOT NULL,
    role          ENUM('admin','owner') NOT NULL DEFAULT 'owner' COMMENT 'admin = ซูเปอร์แอดมิน อนุมัติผู้ใช้ได้',
    status        ENUM('pending','active','suspended') NOT NULL DEFAULT 'active',
    shop_name     VARCHAR(150) NULL,
    shop_slug     VARCHAR(100) NULL COMMENT 'ใช้ในลิงก์จองสาธารณะ ?shop=',
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_username (username),
    UNIQUE KEY uniq_shop_slug (shop_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- การจองหลัก
CREATE TABLE bookings (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           INT UNSIGNED NULL COMMENT 'เจ้าของ (ร้าน)',
    customer_id       INT UNSIGNED NULL,
    staff_id          INT UNSIGNED NULL,
    customer_name     VARCHAR(200) NOT NULL,
    customer_phone    VARCHAR(50)  NOT NULL,
    location          TEXT         NULL COMMENT 'ที่อยู่ หรือ Google Maps Link',
    appointment_date  DATE         NOT NULL,
    start_time        TIME         NOT NULL,
    end_time          TIME         NOT NULL,
    num_people        TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'จำนวน (คน/ชั่วโมง ตาม count_label)',
    price             DECIMAL(10,2) NULL,
    deposit           DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_status    ENUM('unpaid','deposit_paid','paid') NOT NULL DEFAULT 'unpaid',
    slip_path         VARCHAR(255) NULL COMMENT 'ชื่อไฟล์สลิปมัดจำใน uploads/slips',
    status            ENUM('new','confirmed','done','cancelled') NOT NULL DEFAULT 'new' COMMENT 'สถานะคิวงาน',
    source            ENUM('admin','customer') NOT NULL DEFAULT 'admin' COMMENT 'ใครเป็นคนจอง',
    note              TEXT         NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_date (appointment_date),
    KEY idx_created (created_at),
    KEY idx_customer (customer_id),
    KEY idx_staff (staff_id),
    KEY idx_bk_user (user_id),
    CONSTRAINT fk_booking_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_booking_staff FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- การจอง <-> ประเภทงาน (หลายต่อหลาย)
CREATE TABLE booking_category_pivot (
    booking_id  INT UNSIGNED NOT NULL,
    category_id TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (booking_id, category_id),
    KEY fk_cat_booking (booking_id),
    KEY fk_cat_category (category_id),
    CONSTRAINT fk_bcp_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
    CONSTRAINT fk_bcp_category FOREIGN KEY (category_id) REFERENCES booking_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- บริการ <-> ประเภทงาน (หลายต่อหลาย) — บริการที่ไม่ผูกประเภทใด = บริการทั่วไป (แสดงทุกประเภท)
CREATE TABLE service_category_link (
    service_id  TINYINT UNSIGNED NOT NULL,
    category_id TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (service_id, category_id),
    KEY fk_scl_service (service_id),
    KEY fk_scl_category (category_id),
    CONSTRAINT fk_scl_service  FOREIGN KEY (service_id)  REFERENCES booking_services (id)   ON DELETE CASCADE,
    CONSTRAINT fk_scl_category FOREIGN KEY (category_id) REFERENCES booking_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- การจอง <-> บริการ (หลายต่อหลาย)
CREATE TABLE booking_service_pivot (
    booking_id INT UNSIGNED NOT NULL,
    service_id TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (booking_id, service_id),
    KEY fk_svc_booking (booking_id),
    KEY fk_svc_service (service_id),
    CONSTRAINT fk_bsp_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
    CONSTRAINT fk_bsp_service FOREIGN KEY (service_id) REFERENCES booking_services (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ผู้ใช้แรก = ซูเปอร์แอดมิน (เจ้าของข้อมูลตัวอย่าง)
-- password_hash ว่างไว้ → ตั้งรหัสด้วย: php tools/create_admin.php admin <รหัส>
INSERT INTO app_users (id, username, role, status, shop_name, shop_slug, password_hash) VALUES
(1, 'admin', 'admin', 'active', 'ร้านตัวอย่าง', 'admin', '');

-- ข้อมูลเริ่มต้น: ประเภทงาน (ของ admin id=1)
INSERT INTO booking_categories (id, user_id, name, color_hex, price, deposit_default, duration_min, count_label, sort_order) VALUES
(1, 1, 'แต่งหน้าทั่วไป', '#3b82f6', NULL, NULL, 90, 'จำนวนคน', 1),
(2, 1, 'แต่งหน้าเจ้าสาว', '#ec4899', NULL, NULL, 120, 'จำนวนคน', 2),
(3, 1, 'ถ่ายภาพ', '#10b981', NULL, NULL, 120, 'จำนวนชั่วโมง', 3);

-- ข้อมูลเริ่มต้น: บริการ
INSERT INTO booking_services (id, user_id, name, sort_order) VALUES
(1, 1, 'แต่งหน้า', 1),
(2, 1, 'ทำผม', 2);

-- ข้อมูลเริ่มต้น: ช่าง
INSERT INTO staff (id, user_id, name, color_hex, sort_order) VALUES
(1, 1, 'ช่างหลัก', '#ec4899', 1);
