# AI ทำงานอย่างไรในโปรเจกต์นี้

ไฟล์นี้เป็นคู่มือสั้นสำหรับ AI หรือผู้ช่วยเขียนโค้ดที่เข้ามาทำงานกับโปรเจกต์ `sena_reflection 4` ให้เข้าใจวิธีคิด วิธีสำรวจ และวิธีแก้ไขงานอย่างปลอดภัย

## หลักการทำงาน

1. อ่านบริบทก่อนแก้ไขเสมอ
   - เริ่มจาก `CONTEXT.md`
   - ระบบหลักอยู่ใน `laravel/`: อ่าน routes, controllers, services และ `resources/js/` ที่เกี่ยวข้อง
   - อ่าน [docs.md](docs.md) เมื่องานเกี่ยวกับ editor, PDF หรือ DOCX
   - ดู `index.php`, `config.php`, `assets/js/app.js` และ `api/` เมื่องานกระทบ legacy compatibility

2. แก้ให้ตรงจุด
   - ระบบหลักใช้ Laravel 13, React, TypeScript และ Inertia ส่วน PHP เดิมยังเก็บสำหรับ compatibility และแม่แบบพิมพ์
   - หลีกเลี่ยงการย้ายโครงสร้างใหญ่ถ้าไม่จำเป็น
   - ใน Laravel ใช้ Request validation, authorization และ Eloquent ตามรูปแบบเดิม ส่วน legacy ใช้ `jsonResponse()`, `checkAuth()` และ PDO prepared statements

3. รักษาข้อมูลผู้ใช้และสิทธิ์การเข้าถึง
   - ทุก API ที่อ่าน/แก้ข้อมูลส่วนตัวต้องตรวจ authentication และขอบเขตข้อมูลด้วย middleware/policy/service ของ Laravel หรือ `checkAuth()` ใน legacy
   - ผู้ใช้ role `tambon` ต้องเห็นเฉพาะข้อมูลตำบลของตัวเอง
   - ผู้ใช้ role `district` เห็นภาพรวมทุกตำบลได้
   - ร่างและไฟล์ของร่างเป็นข้อมูลส่วนตัวของผู้สร้าง แม้บัญชีอำเภอก็ต้องไม่อ่านร่างของผู้อื่น
   - ห้ามส่ง password/hash กลับไป frontend

4. ระวังงาน PDF และการพิมพ์
   - `print.php` เป็นไฟล์สำคัญและมี logic fallback หลายชั้น
   - การตั้งค่าลายเซ็น/ระยะ/ฟอนต์ผูกกับ table `settings`
   - ถ้าแก้ print/PDF ต้องทดสอบทั้ง preview และ download
   - editor ปัจจุบันเปิดจาก `/records/{record}/document` หรือหน้าร่างโดยตรง ใช้ `RecordEditorTemplate` และ `BoundDocumentService` คงตำแหน่งเริ่มต้นจากแม่แบบเดิม
   - browser print และ server PDF ของ advanced snapshot ใช้ canvas เดียวกัน ตรวจเอกสารหลายหน้า รูปส่วนตัว และข้อความที่เลือก/ค้นหาใน PDF ได้
   - ใช้ `npm run build:production` และ `npm run check:production-build` ก่อน commit build สำหรับ hosting `/sena_reflection`

5. ระวังการเชื่อมต่อระบบภายนอก
   - `api/student_api.php` ดึงข้อมูลนักเรียนจาก `sena_care_school`
   - `api/teacher_directory.php` sync ผู้ใช้งานครูจากแหล่งข้อมูลภายนอก
   - ถ้า API ภายนอกล่ม ระบบควรยังทำงานจากข้อมูล local ได้เท่าที่ทำได้

## ขั้นตอนแนะนำเมื่อรับงาน

1. ระบุขอบเขตงาน
   - เป็นงาน UI, API, database, PDF, auth, หรือ data sync

2. หาไฟล์ที่เกี่ยวข้อง
   - UI หลัก: `laravel/resources/js/pages/` และ components ที่เรียกใช้
   - ข้อมูลบันทึกและร่าง: controllers/services ใน `laravel/app/` และ `laravel/routes/web.php`
   - เอกสาร: `BoundDocumentService`, `RecordEditorTemplate`, `DocumentSchema`, `BoundDocumentRenderer` และ `resources/js/components/editor/`
   - PDF/DOCX: `LegacyPrintService`, `document-snapshot.tsx`, `document-print.ts`, `document-export.ts`
   - นักเรียน/ผู้ใช้: services ของ Laravel และ legacy `api/student_api.php`, `api/teacher_directory.php` ตามเส้นทางที่ใช้งาน

3. ตรวจ flow จาก frontend ไป backend
   - ดูว่า JS เรียก endpoint ไหน
   - ดู payload ที่ส่ง
   - ดู Laravel migrations สำหรับโมดูลปัจจุบัน ส่วน legacy ตรวจ `config.php` และ `database.sql` ด้วย ไม่สร้าง auto-migration ซ้ำของโมดูลใหม่

4. แก้ไขแบบเล็กและทดสอบได้
   - เพิ่ม validation ฝั่ง API ก่อนเชื่อข้อมูลจาก client
   - ใช้ prepared statement ทุกครั้งเมื่อแตะ SQL
   - อย่าแก้ค่า default หรือข้อมูล seed โดยไม่จำเป็น

5. ทดสอบหลังแก้
   - ตรวจ syntax PHP: `php -l path/to/file.php`
   - ตรวจ endpoint ผ่าน browser หรือ curl เมื่อเป็นไปได้
   - ถ้าแตะ frontend ให้เปิดผ่าน MAMP/local URL แล้วดู console
   - รัน `php artisan test --compact`, `npm run typecheck`, `npm run test:frontend` และตรวจ production build; browser tests ใช้ฐานและบัญชี fixture แยกเท่านั้น

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
