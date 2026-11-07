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
mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v6.sql
mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v7.sql
mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v8.sql
mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v9.sql
mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v10.sql
mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v11.sql
mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v12.sql
mysql -u admin -p --default-character-set=utf8mb4 makeup_booking < database/migration_v13.sql
```
- `migration_v2.sql` — ลูกค้า/ราคา/มัดจำ/ล็อกอิน
- `migration_v3.sql` — ช่าง/พนักงาน + ผูกกับการจอง + กันคิวชนแยกตามช่าง
- `migration_v4.sql` — ผูกบริการเสริมกับประเภทงาน (เลือกประเภทแล้วโชว์บริการตาม)
- `migration_v5.sql` — สลิปมัดจำ (อัปโหลดรูปสลิป)
- `migration_v6.sql` — **ระบบหลายร้าน (multi-tenant)**: สมัครสมาชิก+อนุมัติ, ข้อมูลแยกต่อร้าน, ลิงก์จองต่อร้าน (ยกข้อมูลเดิมให้ผู้ใช้ id ต่ำสุด = ซูเปอร์แอดมิน)
- `migration_v7.sql` — ปฏิทินวันฤกษ์ที่ร้านกรอกเอง
- `migration_v8.sql` — ค่าเดินทางต่อการจอง
- `migration_v9.sql` — ช่างหลายคนต่อคิว + มัดจำจ่ายช่าง/สลิปรายคน
- `migration_v10.sql` — ขยาย id ประเภทงาน/บริการ + Telegram แยกต่อร้าน (ต้องใช้ MariaDB)
- `migration_v11.sql` — ตั้งค่าร้าน, วันหยุด/วันลา, รับเงินรายรายการ, ลิงก์ติดตามคิว, LINE (ต้องใช้ MariaDB)
- `migration_v12.sql` — รายจ่ายของร้าน
- `migration_v13.sql` — ช่องทางโซเชียล + แท็กช่วงเวลา (ต้องใช้ MariaDB)

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
| ลูกค้าจองคิวเอง (ต่อร้าน) | `/booking/book.php?shop=<ชื่อร้าน>` | ไม่ |
| สมัครเปิดร้านใหม่ | `/booking/register.php` | ไม่ |
| เข้าสู่ระบบหลังบ้าน | `/booking/login.php` | – |
| อนุมัติ/จัดการผู้ใช้ | `/booking/admin/users.php` | ✓ (ซูเปอร์แอดมิน) |
| Dashboard / ปฏิทินคิวงาน | `/booking/` | ✓ |
| รายงานสรุป (ยอด/กราฟ) | `/booking/report.php` | ✓ |
| ฟอร์มเพิ่ม/แก้ไขคิว | `/booking/form.php` | ✓ |
| จัดการประเภทงาน/บริการ | `/booking/admin/services.php` | ✓ |
| ตั้งค่าแจ้งเตือน Telegram / LINE | `/booking/telegram.php` | ✓ |
| ตั้งค่าร้าน เวลาทำการ วันหยุด PromptPay | `/booking/settings.php` | ✓ |
| ลูกค้าและประวัติการจอง | `/booking/customers.php` | ✓ |
| รายจ่ายและกำไรรายเดือน | `/booking/expenses.php` | ✓ |
| ลูกค้าติดตามคิว/แนบสลิป | `/booking/track.php?c=<รหัสลับ>` | ไม่ |

## โครงสร้างฐานข้อมูล
| ตาราง | คำอธิบาย |
|--------|----------|
| `booking_categories` | ประเภทงาน + สี, ราคา, มัดจำเริ่มต้น, ระยะเวลา, ป้ายจำนวน, เปิด/ปิด |
| `booking_services` | บริการเสริม + ราคา, เปิด/ปิด |
| `bookings` | การจองหลัก (ลูกค้า, เวลา, ราคา, มัดจำ, สถานะจ่าย, สถานะงาน, ที่มา) |
| `customers` | ฐานข้อมูลลูกค้า (ผูกอัตโนมัติจากเบอร์โทร) |
| `staff` | ช่าง/พนักงาน (ผูกกับการจอง, ใช้กันคิวชนแยกตามช่าง) |
| `app_users` | ผู้ใช้หลังบ้าน (admin) — รหัสเก็บแบบ hash |
| `booking_staff` | ช่างของแต่ละคิว (หลัก/เสริม) + มัดจำจ่ายช่าง/สลิปรายคน |
| `auspicious_days` | วันฤกษ์ที่ร้านกรอกเอง |
| `shop_telegram` | ตั้งค่าแจ้งเตือน Telegram / LINE ต่อร้าน |
| `shop_settings` | เวลาทำการ ช่องเวลา เวลาเผื่อเดินทาง เวลาถือคิว PromptPay ต่อร้าน |
| `shop_closures` | วันหยุดร้าน และวันลาของช่าง |
| `booking_payments` | เงินที่รับจริงต่อคิว (`payment_status` คำนวณจากตารางนี้) |
| `shop_expenses` | รายจ่ายของร้าน |
| `booking_category_pivot` / `booking_service_pivot` | ความสัมพันธ์หลายต่อหลาย |

## ระบบหลายร้าน (multi-tenant)
- **สมัครเปิดร้านเอง** ที่ `register.php` → บัญชีสถานะ "รออนุมัติ" จนกว่า**ซูเปอร์แอดมิน**จะอนุมัติที่ `admin/users.php`
- ข้อมูลทุกอย่าง (ประเภทงาน/บริการ/ช่าง/คิว/ลูกค้า) **แยกต่อร้าน** — แต่ละคนเห็นเฉพาะของตัวเอง
- ลูกค้าจองผ่าน **ลิงก์ต่อร้าน** `book.php?shop=<slug>` (แต่ละร้านมี slug ของตัวเอง)
- ผู้ใช้ id ต่ำสุด = ซูเปอร์แอดมิน (role=admin) เป็นผู้อนุมัติ + เจ้าของข้อมูลตัวอย่างเดิม
- **ซูเปอร์แอดมินจัดการได้ทุกร้าน**: ที่ `admin/users.php` กด "จัดการร้านนี้" → สลับเข้าไปจัดการข้อมูลของร้านนั้นได้เต็มรูปแบบ (คิว/บริการ/ช่าง/รายงาน) มี banner บอกสถานะ + ปุ่มออกจากโหมด (ผู้ใช้ทั่วไปทำไม่ได้ — ป้องกันด้วย role check)

## ความสามารถหลัก
- **ลูกค้าจองเอง** (`book.php`): เลือกบริการ → เลือกจากช่องเวลาที่ว่างจริง → ส่งคำขอจอง → ไปหน้าติดตามคิว (`track.php`) ที่มี QR PromptPay และแนบสลิปได้
- **ช่องเวลาอัตโนมัติ**: คิดจากเวลาทำการ วันปิด วันลาช่าง คิวเดิม และเวลาเผื่อเดินทาง ตั้งค่าที่ `settings.php`
- **ถือคิวรอมัดจำ**: ตั้งจำนวนชั่วโมงได้ ถ้าลูกค้าไม่แนบสลิปหรือร้านยังไม่รับเงิน ระบบจะยกเลิกคิวและปล่อยเวลาเอง (0 = ปิด)
- **รับเงินรายรายการ**: บันทึกมัดจำ/ส่วนที่เหลือ/คืนเงินในหน้าแก้ไขคิว ระบบคำนวณยอดคงเหลือและสถานะการจ่ายให้เอง
- **แจ้งเตือนทันที**: เมื่อมีจองใหม่หรือลูกค้าแนบสลิป ผ่าน Telegram และ/หรือ LINE Messaging API
- **ลูกค้า**: หน้า `customers.php` ค้นหา ดูประวัติ ยอดจ่าย และบันทึกหมายเหตุ
- **เวลาคิวแบบยืดหยุ่น (หลังบ้าน)**: เวลาเริ่ม/จบไม่บังคับ มีแท็ก เช้า / เที่ยง / เย็น / เช้าเที่ยง / เช้าเย็น / เที่ยงเย็น; ถ้าไม่ใส่เวลาจะใช้ช่วงของแท็กกันคิวชน (เช้า 06:00–11:00, เที่ยง 11:00–15:00, เย็น 15:00–21:00) และถ้าไม่ใส่อะไรเลยจะกันคิวทั้งวัน
- **รายจ่ายและกำไร**: หน้า `expenses.php` บันทึกรายจ่าย ดึงรายจ่ายประจำจากเดือนก่อน และดูกำไรรายเดือน/ทั้งปี (กำไร = เงินที่รับจริง − มัดจำจ่ายช่าง − รายจ่ายร้าน)
- **ช่างหลายคน**: มอบงานให้ช่าง (ทั้งฟอร์ม admin และตอนลูกค้าจองเอง — เลือกได้หรือ "ไม่ระบุ")
- **กันคิวชนแยกตามช่าง**: ช่างคนละคนจองเวลาทับกันได้; ช่างคนเดียวกันไม่ว่าเป็นหลักหรือเสริม (และงาน "ไม่ระบุช่าง" กลุ่มเดียวกัน) จะชน — ลูกค้า = บล็อก, admin = เตือนแต่กดบันทึกทับได้
- **รายงานต่อช่าง**: หน้ารายงานมีตัวกรองช่าง + สรุปงาน/รายได้แยกตามช่าง
- **ราคา/มัดจำ**: ร้านกรอกราคาจริงเองรายคิวในฟอร์ม admin; งานลูกค้าจองเองแสดงราคาประเมินจากประเภทงาน + บริการเสริม (ไม่คูณจำนวนคน)
- **จัดการบริการเอง**: เพิ่ม/แก้/ลบ ประเภทงาน + บริการ ผ่าน `admin/services.php` (ไม่ต้องแก้ SQL)
- **ฐานข้อมูลลูกค้า**: ฟอร์มมี autocomplete จากลูกค้าเดิม (ค้นจากชื่อ/เบอร์)
- **บริการเป็นซับของประเภทงาน**: ผูกบริการกับประเภทได้หลายประเภท (ตาราง `service_category_link`) เลือกประเภทแล้วจะโชว์เฉพาะบริการที่เกี่ยว (บริการที่ไม่ผูก = แสดงทุกประเภท)
- **แผนที่ปักหมุด + ค้นหา**: ฟอร์ม/หน้าจองมีแผนที่ Leaflet + OpenStreetMap (ฟรี ไม่ต้อง API key) — ค้นหาชื่อสถานที่ (Nominatim) / ปักหมุด / ใช้ตำแหน่งปัจจุบัน → บันทึกเป็นลิงก์ Google Maps อัตโนมัติ ([assets/mappicker.js](assets/mappicker.js))
- **สลิปมัดจำ**: ลูกค้าแนบรูปสลิปตอนจอง, แอดมินดู/เปลี่ยนได้ (เก็บใน `uploads/slips` กันเข้าถึงตรง ดูผ่าน [api/slip.php](api/slip.php) ที่ต้อง login)
- **ปฏิทินสีตามช่าง + ตัวกรองช่าง** บนหน้า Dashboard; จอกว้างแสดงคิววันนี้ + ปฏิทินคู่กัน
- **เปลี่ยนรหัสผ่านในเว็บ** ([account.php](account.php)) และ **Export รายงาน CSV / พิมพ์** ([api/report_export.php](api/report_export.php))
- **ลบข้อมูลแบบรักษาประวัติ**: ช่าง/ประเภทงาน/บริการที่มีคิวใช้แล้วจะถูกปิดใช้งานแทนการลบจริง
- **ความปลอดภัย**: cookie HttpOnly+SameSite=Lax, CSRF token (admin), rate-limit หน้าจองสาธารณะ, advisory lock กันคิวชนพร้อมกัน, บัญชีที่ถูกระงับหลุดจากระบบทันที, `.htaccess` กันโฟลเดอร์ภายใน (ถ้าใช้ nginx ต้องตั้งกฎเทียบเท่าเอง)

## API
| Endpoint | สิทธิ์ | หน้าที่ |
|----------|--------|--------|
| `api/options.php` | สาธารณะ | ประเภทงาน+บริการที่เปิดใช้งาน |
| `api/availability.php` | สาธารณะ | ช่วงเวลาที่ถูกจองแล้วของวันหนึ่ง |
| `api/bookings.php` | POST สร้างได้ทั้งสองทาง / อ่าน-แก้-ลบ ต้อง login | จัดการการจอง |
| `api/categories.php`, `api/services.php`, `api/staff.php` | admin | CRUD ประเภทงาน/บริการ/ช่าง |
| `api/customers.php` | admin | ค้น/ดูลูกค้า + ประวัติการจอง |
| `api/reports.php` | admin | สรุปยอด/ประเภทงาน/รายเดือน/การจ่าย |
| `api/payments.php` | admin | รายการรับเงินของคิว |
| `api/expenses.php` | admin | รายจ่ายของร้านและกำไรรายเดือน |
| `api/track.php` | รหัสลับของคิว | ลูกค้าแนบสลิปจากหน้าติดตามคิว |

## งานอัตโนมัติ (cron)
แต่ละร้านตั้งค่า Telegram / LINE และเวลาแจ้งเตือนของตัวเองที่ `telegram.php` แล้วตั้ง cron บนเซิร์ฟเวอร์:
```
*/5 * * * * php /path/to/booking/cron/send_tomorrow_schedule.php
0 3 * * * php /path/to/booking/cron/cleanup_slips.php
```
- `send_tomorrow_schedule.php` — ส่งตารางงาน "วันพรุ่งนี้" ของแต่ละร้านตามเวลาที่ร้านตั้งไว้
- `cleanup_slips.php` — ลบไฟล์สลิปที่ไม่มีคิวไหนใช้และเก่ากว่า 7 วัน (ใส่ `--dry-run` เพื่อดูรายการก่อนลบ)

## อนาคต (ยังไม่ทำ)
- payment gateway, แจ้งเตือนลูกค้าผ่าน LINE, บัญชีแยกสิทธิ์ของช่าง
