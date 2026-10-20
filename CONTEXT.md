# โปรเจกต์คืออะไร

> **Laravel migration (2026-08-30):** ระบบใหม่อยู่ใน `laravel/` และใช้ MySQL ฐานเดียวกับระบบเดิม รองรับส่งบันทึกรายสัปดาห์ครบ 3 ระดับแบบ atomic, pagination, role scope และดาวน์โหลด PDF/ZIP แล้ว ดูวิธีรันและขอบเขตได้ที่ `laravel/README.md` ไฟล์ legacy ยังคงถูกเก็บไว้เพื่อเทียบผลและ rollback ระหว่างช่วงย้าย

`sena_reflection 4` คือระบบเว็บสำหรับบันทึกหลังการสอนของ สกร. อำเภอเสนา ใช้สำหรับให้ครูระดับตำบลบันทึกผลการจัดการเรียนการสอน แนบภาพประกอบ บันทึกการมาเรียนของผู้เรียน และออกเอกสารรายงาน/ PDF สำหรับตรวจสอบหรือพิมพ์ใช้งาน

## ภาพรวมระบบ

ระบบเป็น PHP application แบบไม่ใช้ framework หลัก ทำงานบน MAMP/Apache + MySQL เป็นหลัก หน้าเว็บหลักอยู่ใน `index.php` และใช้ JavaScript ใน `assets/js/app.js` เพื่อเรียก API ต่าง ๆ ในโฟลเดอร์ `api/`

ผู้ใช้หลักมี 2 กลุ่ม:

- `district`: ผู้ดูแลระดับอำเภอ เห็นภาพรวมทุกตำบล จัดการข้อมูล/ตั้งค่ากลางได้
- `tambon`: ครูหรือผู้ใช้ระดับตำบล เห็นและจัดการข้อมูลเฉพาะตำบลของตนเอง

## ฟีเจอร์สำคัญ

- Login/logout และเปลี่ยนรหัสผ่าน
- Dashboard ภาพรวมอำเภอและสถานะรายตำบล
- เพิ่ม/แก้ไข/ลบบันทึกหลังการสอน
- แนบภาพประกอบการจัดการเรียนการสอน 2 หรือ 4 รูป
- บันทึกจำนวนผู้เรียนทั้งหมดและผู้เข้าเรียน
- บันทึก attendance รายคน พร้อมวิธีเรียน
- วิเคราะห์ผู้เรียนจากข้อมูล attendance และ Student API
- sync รายชื่อครู/ผู้ใช้จากระบบภายนอก
- ตั้งค่าเอกสาร เช่น ลายเซ็น ตำแหน่ง ฟอนต์ ระยะขอบ
- สร้างหน้า print/PDF และดาวน์โหลดเอกสาร

## โครงสร้างไฟล์หลัก

- `index.php`: หน้าเว็บหลัก รวม login, dashboard, form, modal และพื้นที่แสดงผลทั้งหมด
- `assets/js/app.js`: logic ฝั่งหน้าเว็บ เช่น API calls, render table, dashboard, student analysis, print preview
- `assets/css/style.css`: style หลักของระบบ
- `config.php`: ตั้งค่าฐานข้อมูล session helper auth helper auto-migration และ utility สำคัญ
- `database.sql`: schema สำหรับติดตั้งฐานข้อมูล
- `print.php`: สร้างหน้า print/PDF จากข้อมูลบันทึกและ settings
- `api/download_pdf.php`: wrapper สำหรับสั่ง download PDF ผ่าน `print.php`
- `pdfjs.php`: เสิร์ฟไฟล์ PDF.js จาก `node_modules`

## API สำคัญ

- `api/auth.php`: login, logout, change password
- `api/records.php`: CRUD บันทึกหลังสอน, รูปภาพ, attendance รายคน
- `api/dashboard.php`: ข้อมูลสรุปภาพรวม
- `api/tambons.php`: ข้อมูลตำบล
- `api/users.php`: จัดการผู้ใช้
- `api/settings.php`: อ่าน/บันทึกค่าตั้งค่าเอกสารและลายเซ็น
- `api/student_api.php`: proxy/filter ข้อมูลนักเรียนจากระบบภายนอก
- `api/attendance_report.php`: รายงาน/สรุป attendance
- `api/teacher_directory.php`: sync ผู้ใช้ครูจากระบบภายนอก
- `api/upload_image.php`: อัปโหลดรูปภาพ
- `api/server_check.php`: ตรวจสถานะ server/environment

## ฐานข้อมูล

ระบบใช้ MySQL ผ่าน PDO โดยตั้งค่า default ใน `config.php`:

- host: `localhost`
- user: `root`
- password: `root`
- database: `sena_reflection`

ตารางสำคัญ:

- `users`: ผู้ใช้และ role
- `tambons`: รายชื่อตำบล 17 แห่ง
- `records`: บันทึกหลังการสอน
- `record_images`: รูปภาพของแต่ละบันทึก
- `record_attendance`: attendance รายคนของแต่ละบันทึก
- `settings`: ค่าตั้งค่าเอกสาร ลายเซ็น และ PDF

`config.php` มี auto-migration เพื่อสร้าง/เพิ่ม column/index บางส่วนตอนระบบเริ่มทำงาน จึงต้องระวังเมื่อแก้ schema

## Dependency

- Composer: ไม่มี dependency สำหรับ PDF ฝั่ง server
- npm: `html2canvas` + `jspdf` สำหรับสร้าง PDF ฝั่ง browser และ `pdfjs-dist` สำหรับ preview PDF
- ฟอนต์ไทยอยู่ใน `fonts/`

## Flow การใช้งานหลัก

1. ผู้ใช้ login ผ่าน `api/auth.php`
2. `index.php` โหลดหน้าระบบและส่งข้อมูลผู้ใช้ปัจจุบันให้ JavaScript
3. JavaScript โหลดตำบล dashboard records settings และข้อมูลนักเรียนตามหน้า
4. เมื่อเพิ่ม/แก้บันทึก `assets/js/app.js` ส่งข้อมูลไป `api/records.php`
5. API validate สิทธิ์ role/tambon แล้วบันทึกลงฐานข้อมูล
6. การพิมพ์หรือ PDF ใช้ `print.php` ดึง record, images, attendance, settings แล้ว render เป็นเอกสาร

## แนวทางพัฒนา

- รักษา compatibility กับ MAMP และ PHP local environment
- ใช้ PHP/PDO แบบที่โปรเจกต์ใช้อยู่แล้ว
- หลีกเลี่ยงการเพิ่ม framework ใหญ่
- ถ้าเพิ่ม field ใหม่ ต้องดูทั้ง schema, API, frontend form, table render, print/PDF
- ถ้าแก้สิทธิ์เข้าถึง ต้องทดสอบทั้ง role `district` และ `tambon`
- ถ้าแก้ Student API หรือ teacher sync ต้องเผื่อกรณีระบบภายนอกตอบช้า/ล่ม/ไม่มีข้อมูล

## จุดเสี่ยงที่ควรจำ

- `uploads/` อาจมีไฟล์จริงของผู้ใช้ ห้ามลบโดยไม่ขออนุญาต
- `settings` มี key จำนวนมากและบาง key scoped ตาม user/tambon
- `print.php` มี fallback สำหรับ server ที่ Composer autoload หรือ PHP version มีปัญหา
- ข้อมูลนักเรียน/ครูมาจากระบบภายนอก จึงไม่ควร assume schema โดยไม่ดู normalize function
- รหัสผ่านเก่าอาจถูก migrate เป็น hash ผ่าน helper ใน `config.php`
