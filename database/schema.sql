-- ระบบจองคิวหลายบริการ (แต่งหน้า / ถ่ายภาพ / อื่นๆ) - โครงสร้างฐานข้อมูลแบบติดตั้งใหม่
-- รันครั้งแรก: import ไฟล์นี้ (สร้าง DB + ตาราง + ข้อมูลเริ่มต้น)
-- ถ้ามี DB เดิมอยู่แล้ว ให้ใช้ database/migration_v2.sql แทน (ไม่ลบข้อมูลเดิม)

CREATE DATABASE IF NOT EXISTS makeup_booking CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE makeup_booking;

-- ประเภทงาน (ติ๊กได้หลายข้อ) — รองรับราคา/มัดจำ/ระยะเวลา/ป้ายจำนวน/เปิดปิด
CREATE TABLE booking_categories (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
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
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
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
    KEY idx_customer_phone (phone),
    UNIQUE KEY uniq_customer_shop_phone (user_id, phone)
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
    public_token      CHAR(20)     NULL COMMENT 'รหัสลับในลิงก์ติดตามคิวของลูกค้า',
    user_id           INT UNSIGNED NULL COMMENT 'เจ้าของ (ร้าน)',
    customer_id       INT UNSIGNED NULL,
    staff_id          INT UNSIGNED NULL,
    customer_name     VARCHAR(200) NOT NULL,
    customer_phone    VARCHAR(50)  NOT NULL,
    social            VARCHAR(500) NULL COMMENT 'ชื่อโซเชียลหรือลิงก์ของลูกค้า',
    location          TEXT         NULL COMMENT 'ที่อยู่ หรือ Google Maps Link',
    appointment_date  DATE         NOT NULL,
    start_time        TIME         NOT NULL,
    end_time          TIME         NOT NULL,
    time_slot         ENUM('morning','noon','evening','morning_noon','morning_evening','noon_evening') NULL COMMENT 'แท็กช่วงเวลา',
    time_exact        TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = ระบุเวลาจริง, 0 = มีแค่แท็กหรือยังไม่ระบุ',
    num_people        TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'จำนวน (คน/ชั่วโมง ตาม count_label)',
    price             DECIMAL(10,2) NULL,
    deposit           DECIMAL(10,2) NOT NULL DEFAULT 0,
    travel_fee        DECIMAL(10,2) NULL COMMENT 'ค่าเดินทาง',
    payment_status    ENUM('unpaid','deposit_paid','paid') NOT NULL DEFAULT 'unpaid',
    slip_path         VARCHAR(255) NULL COMMENT 'ชื่อไฟล์สลิปมัดจำใน uploads/slips',
    status            ENUM('new','confirmed','done','cancelled') NOT NULL DEFAULT 'new' COMMENT 'สถานะคิวงาน',
    hold_until        DATETIME     NULL COMMENT 'ถือคิวรอมัดจำถึงเวลานี้',
    consent_at        DATETIME     NULL COMMENT 'เวลาที่ลูกค้ายินยอมตาม PDPA',
    source            ENUM('admin','customer') NOT NULL DEFAULT 'admin' COMMENT 'ใครเป็นคนจอง',
    note              TEXT         NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_public_token (public_token),
    KEY idx_date (appointment_date),
    KEY idx_created (created_at),
    KEY idx_customer (customer_id),
    KEY idx_staff (staff_id),
    KEY idx_bk_user (user_id),
    CONSTRAINT fk_booking_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_booking_staff FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ช่างที่รับงานในคิวนี้ (ช่างหลัก 1 คน + ช่างเสริมกี่คนก็ได้)
--   deposit_paid = มัดจำที่ "จ่ายออก" ให้ช่างคนนั้น (คนละก้อนกับ bookings.deposit ที่ลูกค้าจ่ายเข้า)
--   bookings.staff_id ยังเก็บ "ช่างหลัก" ไว้เสมอ เพื่อกันคิวชน/ตัวกรอง/รายงาน
CREATE TABLE booking_staff (
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

-- การจอง <-> ประเภทงาน (หลายต่อหลาย)
CREATE TABLE booking_category_pivot (
    booking_id  INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (booking_id, category_id),
    KEY fk_cat_booking (booking_id),
    KEY fk_cat_category (category_id),
    CONSTRAINT fk_bcp_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
    CONSTRAINT fk_bcp_category FOREIGN KEY (category_id) REFERENCES booking_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- บริการ <-> ประเภทงาน (หลายต่อหลาย) — บริการที่ไม่ผูกประเภทใด = บริการทั่วไป (แสดงทุกประเภท)
CREATE TABLE service_category_link (
    service_id  INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (service_id, category_id),
    KEY fk_scl_service (service_id),
    KEY fk_scl_category (category_id),
    CONSTRAINT fk_scl_service  FOREIGN KEY (service_id)  REFERENCES booking_services (id)   ON DELETE CASCADE,
    CONSTRAINT fk_scl_category FOREIGN KEY (category_id) REFERENCES booking_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- การจอง <-> บริการ (หลายต่อหลาย)
CREATE TABLE booking_service_pivot (
    booking_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (booking_id, service_id),
    KEY fk_svc_booking (booking_id),
    KEY fk_svc_service (service_id),
    CONSTRAINT fk_bsp_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
    CONSTRAINT fk_bsp_service FOREIGN KEY (service_id) REFERENCES booking_services (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- วันฤกษ์ที่ร้านกรอกเอง (ส่วนจันทรคติ/กาลโยคคำนวณสดจาก app/thai_lunar.php)
CREATE TABLE auspicious_days (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL COMMENT 'เจ้าของ (ร้าน)',
    date       DATE         NOT NULL,
    label      VARCHAR(100) NOT NULL DEFAULT 'วันฤกษ์' COMMENT 'เช่น ฤกษ์แต่งงาน / ฤกษ์ขึ้นบ้านใหม่',
    grade      ENUM('great','good','avoid') NOT NULL DEFAULT 'good' COMMENT 'มหาฤกษ์ / ฤกษ์ดี / วันควรเลี่ยง',
    source     VARCHAR(120) NULL COMMENT 'ที่มา เช่น ชื่อโหร/สำนัก — ไว้ตรวจย้อนหลัง',
    note       TEXT         NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_user_date (user_id, date),
    KEY idx_aus_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ตั้งค่าแจ้งเตือน Telegram ต่อร้าน
CREATE TABLE shop_telegram (
    user_id        INT UNSIGNED NOT NULL COMMENT 'เจ้าของ (ร้าน)',
    bot_token      VARCHAR(100) NOT NULL DEFAULT '',
    chat_id        VARCHAR(64)  NOT NULL DEFAULT '',
    notify_time    VARCHAR(5)   NOT NULL DEFAULT '18:00',
    last_sent_date DATE         NULL,
    notify_new     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'แจ้งทันทีเมื่อมีจองใหม่/แนบสลิป',
    line_token     VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'LINE Messaging API channel access token',
    line_to        VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'userId หรือ groupId ที่รับแจ้งเตือน',
    PRIMARY KEY (user_id),
    CONSTRAINT fk_st_user FOREIGN KEY (user_id) REFERENCES app_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- เวลาทำการ ช่องเวลา เวลาเผื่อเดินทาง เวลาถือคิว และ PromptPay ของแต่ละร้าน
CREATE TABLE shop_settings (
    user_id         INT UNSIGNED NOT NULL COMMENT 'เจ้าของ (ร้าน)',
    open_time       TIME NOT NULL DEFAULT '06:00:00',
    close_time      TIME NOT NULL DEFAULT '20:00:00',
    closed_weekdays VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'วันปิดประจำสัปดาห์ 0=อาทิตย์ ... 6=เสาร์ คั่นด้วย comma',
    slot_minutes    SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    buffer_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'เวลาเผื่อเดินทางก่อน/หลังคิว',
    hold_hours      SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ถือคิวรอมัดจำกี่ชั่วโมง (0 = ไม่ปล่อยอัตโนมัติ)',
    promptpay_id    VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'เบอร์มือถือ / เลขบัตร / e-Wallet',
    PRIMARY KEY (user_id),
    CONSTRAINT fk_ss_user FOREIGN KEY (user_id) REFERENCES app_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- วันหยุดร้าน (staff_id NULL) หรือวันลาของช่าง
CREATE TABLE shop_closures (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id   INT UNSIGNED NOT NULL COMMENT 'เจ้าของ (ร้าน)',
    staff_id  INT UNSIGNED NULL COMMENT 'NULL = ปิดทั้งร้าน',
    date_from DATE NOT NULL,
    date_to   DATE NOT NULL,
    note      VARCHAR(150) NULL,
    PRIMARY KEY (id),
    KEY idx_sc_user_date (user_id, date_from, date_to),
    CONSTRAINT fk_sc_staff FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- เงินที่รับจริงต่อคิว (bookings.payment_status คำนวณจากตารางนี้)
CREATE TABLE booking_payments (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id INT UNSIGNED NOT NULL,
    kind       ENUM('deposit','balance','refund') NOT NULL DEFAULT 'deposit',
    amount     DECIMAL(10,2) NOT NULL,
    method     ENUM('transfer','cash','other') NOT NULL DEFAULT 'transfer',
    paid_at    DATE NOT NULL,
    note       VARCHAR(200) NULL,
    created_by INT UNSIGNED NULL COMMENT 'ผู้ใช้หลังบ้านที่บันทึก',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bp_booking (booking_id),
    CONSTRAINT fk_bp_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- รายจ่ายของร้าน (ใช้คำนวณกำไรรายเดือน)
CREATE TABLE shop_expenses (
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
