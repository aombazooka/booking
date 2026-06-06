# ระบบจองคิวหลายบริการ (แต่งหน้า / ถ่ายภาพ / อื่นๆ)

ระบบจัดการคิวงานบริการ รองรับหลายประเภทงาน (แต่งหน้า, ถ่ายภาพ และเพิ่มเองได้)
มีหน้าให้ **ลูกค้าจองคิวเอง**, ระบบ **ราคา/มัดจำ**, **กันคิวชนกัน**, **ฐานข้อมูลลูกค้า**,
หลังบ้านป้องกันด้วย **ระบบ login** และแจ้งเตือนตารางงานผ่าน **Telegram**

## ความต้องการ
- PHP 7.4+ (ใช้ `str_starts_with` → แนะนำ PHP 8.0+) และ MySQL 5.7+ / MariaDB
- ส่วนขยาย PDO MySQL

## การติดตั้ง

### 1) สร้างฐานข้อมูล
**ติดตั้งใหม่** — นำเข้า `database/schema.sql`
(แนะนำผ่าน **phpMyAdmin** เพื่อให้ภาษาไทยเป็น UTF-8 ถูกต้อง; ถ้าใช้ command line ให้ระบุ charset)

```bash
mysql -u admin -p --default-character-set=utf8mb4 < database/schema.sql
```

**อัปเกรดจากระบบเดิม (มีข้อมูลคิวอยู่แล้ว)** — รัน migration ตามลำดับ
(เพิ่มตาราง/คอลัมน์ใหม่โดยไม่ลบข้อมูลเดิม, รันซ้ำได้ปลอดภัย)

```bash
mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v2.sql
mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v3.sql
mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v4.sql
mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v5.sql
```
- `migration_v2.sql` — ลูกค้า/ราคา/มัดจำ/ล็อกอิน
- `migration_v3.sql` — ช่าง/พนักงาน + ผูกกับการจอง + กันคิวชนแยกตามช่าง
- `migration_v4.sql` — ผูกบริการเสริมกับประเภทงาน (เลือกประเภทแล้วโชว์บริการตาม)
- `migration_v5.sql` — สลิปมัดจำ (อัปโหลดรูปสลิป)

### 2) ตั้งค่าเชื่อมต่อ DB
แก้ `config.php` ให้ตรงกับ MySQL ของคุณ (host, name, user, password)

### 3) สร้างผู้ใช้ admin (สำหรับเข้าหลังบ้าน)
```bash
php tools/create_admin.php <username> <password>
```
ตัวอย่าง: `php tools/create_admin.php admin mypassword123`

### 4) เข้าใช้งาน
| หน้า | URL | ต้อง login |
|------|-----|:--:|
| ลูกค้าจองคิวเอง (สาธารณะ) | `/booking/book.php` | ไม่ |
| เข้าสู่ระบบหลังบ้าน | `/booking/login.php` | – |
| Dashboard / ปฏิทินคิวงาน | `/booking/` | ✓ |
| รายงานสรุป (ยอด/กราฟ) | `/booking/report.php` | ✓ |
| ฟอร์มเพิ่ม/แก้ไขคิว | `/booking/form.php` | ✓ |
| จัดการประเภทงาน/บริการ | `/booking/admin/services.php` | ✓ |
| ตั้งค่าแจ้งเตือน Telegram | `/booking/telegram.php` | ✓ |

## โครงสร้างฐานข้อมูล
| ตาราง | คำอธิบาย |
|--------|----------|
| `booking_categories` | ประเภทงาน + สี, ราคา, มัดจำเริ่มต้น, ระยะเวลา, ป้ายจำนวน, เปิด/ปิด |
| `booking_services` | บริการเสริม + ราคา, เปิด/ปิด |
| `bookings` | การจองหลัก (ลูกค้า, เวลา, ราคา, มัดจำ, สถานะจ่าย, สถานะงาน, ที่มา) |
| `customers` | ฐานข้อมูลลูกค้า (ผูกอัตโนมัติจากเบอร์โทร) |
| `staff` | ช่าง/พนักงาน (ผูกกับการจอง, ใช้กันคิวชนแยกตามช่าง) |
| `app_users` | ผู้ใช้หลังบ้าน (admin) — รหัสเก็บแบบ hash |
| `booking_category_pivot` / `booking_service_pivot` | ความสัมพันธ์หลายต่อหลาย |

## ความสามารถหลัก
- **ลูกค้าจองเอง** (`book.php`): เลือกบริการ → ดูช่วงเวลาที่ว่าง → ส่งคำขอจอง (รอร้านยืนยัน)
- **ช่างหลายคน**: มอบงานให้ช่าง (ทั้งฟอร์ม admin และตอนลูกค้าจองเอง — เลือกได้หรือ "ไม่ระบุ")
- **กันคิวชนแยกตามช่าง**: ช่างคนละคนจองเวลาทับกันได้; ช่างคนเดียวกัน (และงาน "ไม่ระบุช่าง" กลุ่มเดียวกัน) จะชน — ลูกค้า = บล็อก, admin = เตือนแต่กดบันทึกทับได้
- **รายงานต่อช่าง**: หน้ารายงานมีตัวกรองช่าง + สรุปงาน/รายได้แยกตามช่าง
- **ราคา/มัดจำ**: บันทึกด้วยมือในฟอร์ม admin; งานลูกค้าจองเองคำนวณราคาเริ่มต้นจากประเภทงาน
- **จัดการบริการเอง**: เพิ่ม/แก้/ลบ ประเภทงาน + บริการ ผ่าน `admin/services.php` (ไม่ต้องแก้ SQL)
- **ฐานข้อมูลลูกค้า**: ฟอร์มมี autocomplete จากลูกค้าเดิม (ค้นจากชื่อ/เบอร์)
- **บริการเป็นซับของประเภทงาน**: ผูกบริการกับประเภทได้หลายประเภท (ตาราง `service_category_link`) เลือกประเภทแล้วจะโชว์เฉพาะบริการที่เกี่ยว (บริการที่ไม่ผูก = แสดงทุกประเภท)
- **แผนที่ปักหมุด + ค้นหา**: ฟอร์ม/หน้าจองมีแผนที่ Leaflet + OpenStreetMap (ฟรี ไม่ต้อง API key) — ค้นหาชื่อสถานที่ (Nominatim) / ปักหมุด / ใช้ตำแหน่งปัจจุบัน → บันทึกเป็นลิงก์ Google Maps อัตโนมัติ ([assets/mappicker.js](assets/mappicker.js))
- **สลิปมัดจำ**: ลูกค้าแนบรูปสลิปตอนจอง, แอดมินดู/เปลี่ยนได้ (เก็บใน `uploads/slips` กันเข้าถึงตรง ดูผ่าน [api/slip.php](api/slip.php) ที่ต้อง login)
- **ปฏิทินสีตามช่าง + ตัวกรองช่าง** บนหน้า Dashboard; จอกว้างแสดงคิววันนี้ + ปฏิทินคู่กัน
- **เปลี่ยนรหัสผ่านในเว็บ** ([account.php](account.php)) และ **Export รายงาน CSV / พิมพ์** ([api/report_export.php](api/report_export.php))
- **ความปลอดภัย**: cookie HttpOnly+SameSite=Lax, CSRF token (admin), rate-limit หน้าจองสาธารณะ, advisory lock กันคิวชนพร้อมกัน

## API
| Endpoint | สิทธิ์ | หน้าที่ |
|----------|--------|--------|
| `api/options.php` | สาธารณะ | ประเภทงาน+บริการที่เปิดใช้งาน |
| `api/availability.php` | สาธารณะ | ช่วงเวลาที่ถูกจองแล้วของวันหนึ่ง |
| `api/bookings.php` | POST สร้างได้ทั้งสองทาง / อ่าน-แก้-ลบ ต้อง login | จัดการการจอง |
| `api/categories.php`, `api/services.php`, `api/staff.php` | admin | CRUD ประเภทงาน/บริการ/ช่าง |
| `api/customers.php` | admin | ค้น/ดูลูกค้า + ประวัติการจอง |
| `api/reports.php` | admin | สรุปยอด/ประเภทงาน/รายเดือน/การจ่าย |

## แจ้งเตือน Telegram (อัตโนมัติ)
ตั้งค่า Bot Token / Chat ID / เวลาแจ้งเตือนที่ `telegram.php` แล้วตั้ง cron:
```
*/5 * * * * php /path/to/booking/cron/send_tomorrow_schedule.php
```
ระบบจะส่งตารางงาน "วันพรุ่งนี้" ตามเวลาที่ตั้งไว้

## อนาคต (ยังไม่ทำ)
- อัปโหลดสลิป / payment gateway, แจ้งเตือนลูกค้าผ่าน LINE, ระบบหลายช่าง/หลายสิทธิ์
