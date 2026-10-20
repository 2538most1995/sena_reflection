# AI ทำงานอย่างไรในโปรเจกต์นี้

ไฟล์นี้เป็นคู่มือสั้นสำหรับ AI หรือผู้ช่วยเขียนโค้ดที่เข้ามาทำงานกับโปรเจกต์ `sena_reflection 4` ให้เข้าใจวิธีคิด วิธีสำรวจ และวิธีแก้ไขงานอย่างปลอดภัย

## หลักการทำงาน

1. อ่านบริบทก่อนแก้ไขเสมอ
   - เริ่มจาก `CONTEXT.md`
   - ดูไฟล์หลัก: `index.php`, `config.php`, `assets/js/app.js`
   - ดู API ที่เกี่ยวข้องในโฟลเดอร์ `api/`

2. แก้ให้ตรงจุด
   - โปรเจกต์นี้เป็น PHP แบบไม่ใช้ framework หลัก
   - หลีกเลี่ยงการย้ายโครงสร้างใหญ่ถ้าไม่จำเป็น
   - ใช้ pattern เดิมของโปรเจกต์ เช่น `jsonResponse()`, `checkAuth()`, PDO prepared statements

3. รักษาข้อมูลผู้ใช้และสิทธิ์การเข้าถึง
   - ทุก API ที่อ่าน/แก้ข้อมูลส่วนตัวควรผ่าน `checkAuth()`
   - ผู้ใช้ role `tambon` ต้องเห็นเฉพาะข้อมูลตำบลของตัวเอง
   - ผู้ใช้ role `district` เห็นภาพรวมทุกตำบลได้
   - ห้ามส่ง password/hash กลับไป frontend

4. ระวังงาน PDF และการพิมพ์
   - `print.php` เป็นไฟล์สำคัญและมี logic fallback หลายชั้น
   - การตั้งค่าลายเซ็น/ระยะ/ฟอนต์ผูกกับ table `settings`
   - ถ้าแก้ print/PDF ต้องทดสอบทั้ง preview และ download

5. ระวังการเชื่อมต่อระบบภายนอก
   - `api/student_api.php` ดึงข้อมูลนักเรียนจาก `sena_care_school`
   - `api/teacher_directory.php` sync ผู้ใช้งานครูจากแหล่งข้อมูลภายนอก
   - ถ้า API ภายนอกล่ม ระบบควรยังทำงานจากข้อมูล local ได้เท่าที่ทำได้

## ขั้นตอนแนะนำเมื่อรับงาน

1. ระบุขอบเขตงาน
   - เป็นงาน UI, API, database, PDF, auth, หรือ data sync

2. หาไฟล์ที่เกี่ยวข้อง
   - UI หลัก: `index.php`, `assets/js/app.js`, `assets/css/style.css`
   - ข้อมูลบันทึก: `api/records.php`
   - ผู้ใช้/login: `api/auth.php`, `api/users.php`, `api/teacher_directory.php`
   - นักเรียน/วิเคราะห์ผู้เรียน: `api/student_api.php`, `api/attendance_report.php`
   - ตั้งค่าเอกสาร: `api/settings.php`, `print.php`

3. ตรวจ flow จาก frontend ไป backend
   - ดูว่า JS เรียก endpoint ไหน
   - ดู payload ที่ส่ง
   - ดู schema ใน `config.php` หรือ `database.sql`

4. แก้ไขแบบเล็กและทดสอบได้
   - เพิ่ม validation ฝั่ง API ก่อนเชื่อข้อมูลจาก client
   - ใช้ prepared statement ทุกครั้งเมื่อแตะ SQL
   - อย่าแก้ค่า default หรือข้อมูล seed โดยไม่จำเป็น

5. ทดสอบหลังแก้
   - ตรวจ syntax PHP: `php -l path/to/file.php`
   - ตรวจ endpoint ผ่าน browser หรือ curl เมื่อเป็นไปได้
   - ถ้าแตะ frontend ให้เปิดผ่าน MAMP/local URL แล้วดู console

## สิ่งที่ AI ควรระวังเป็นพิเศษ

- `config.php` มี auto-migration และ helper หลายตัว แก้ผิดอาจกระทบทั้งระบบ
- `print.php` ใช้ข้อมูลจากหลาย table และมี mode preview/download/settings preview
- `uploads/` มีไฟล์ผู้ใช้จริง อย่าลบหรือจัดระเบียบเอง
- `tmp/`, `vendor/`, `node_modules/` เป็น dependency/output ไม่ควรแก้ด้วยมือถ้าไม่จำเป็น
- ฐานข้อมูลหลักคือ MySQL ชื่อ `sena_reflection` บน MAMP แต่มี `database.sqlite` อยู่ด้วย จึงต้องตรวจ code ก่อนสรุปว่าใช้ฐานไหน

## รูปแบบคำตอบหลังทำงาน

เมื่อ AI ทำงานเสร็จ ควรสรุปสั้น ๆ ว่า:

- แก้อะไร
- ไฟล์ไหนที่แตะ
- ทดสอบอะไรแล้ว
- มีข้อจำกัดหรือสิ่งที่ควรทดสอบต่อหรือไม่
