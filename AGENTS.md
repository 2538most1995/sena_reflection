# ทีม AI วิเคราะห์อะไร

ไฟล์นี้แบ่งบทบาทของทีม AI สำหรับช่วยดูแลโปรเจกต์ `sena_reflection 4` ให้แต่ละ agent วิเคราะห์คนละมุม และส่งต่องานกันได้ชัดเจน

## ระบบเอกสารปัจจุบัน

- ระบบหลักอยู่ใน `laravel/` ใช้ Laravel, Inertia และ React; ไฟล์ PHP เดิมยังใช้กับเอกสาร legacy และการเทียบผล
- คู่มือการสร้าง จัดการ และพิมพ์เอกสารอยู่ที่ [`docs.md`](docs.md)
- หน้าบันทึกหลังสอนยืนยันครั้งเดียวด้วย `confirmed: true` หลังบันทึกร่างล่าสุด ไม่บังคับตรวจ PDF หรือยืนยันรายชื่อแยกแต่ละระดับ; ห้ามเปลี่ยนสถานะมาเรียนอัตโนมัติ และต้องรักษา validation, lock version, idempotency และสิทธิ์เดิม
- `/records/{record}/document` และหน้าเอกสารฉบับร่างเปิดตัวแก้ไขขั้นสูงโดยตรง โดยเริ่มจากแม่แบบเดิมของ `print.php`
- ตรวจ `RecordEditorTemplate`, `BoundDocumentService`, `DocumentSchema` และ `DocumentController` เมื่อแก้การนำเข้า การบันทึก หรือสิทธิ์เอกสาร
- ตรวจ `resources/js/components/editor/`, `resources/js/pages/documents/`, `document-print.ts`, `document-export.ts` และ `document-snapshot.tsx` เมื่อแก้ canvas, การจัดหน้า, PDF หรือ DOCX
- เอกสารผูกบันทึกต้องบันทึกข้อความกลับข้อมูลหลัก; เอกสารผูกร่างต้องใช้ `lock_version` และห้ามยืนยันร่างระหว่าง autosave
- รักษา paragraph IDs ที่ใช้เป็น anchors และเก็บ revision เดิมเมื่อ sync ข้อมูลจากหน้าอื่น ห้ามสร้างแม่แบบทับฉบับที่ผู้ใช้แก้ไขแล้ว
- เมื่อแก้การบันทึกรูป/รายชื่อ ต้องตรวจ schema จริงของ ID รวม numeric ที่ขาด AUTO_INCREMENT; ห้ามเดาชนิดจากข้อความ exception และห้ามสร้าง/แก้ตารางใน transaction finalize/revision
- การเปลี่ยน PDF ต้องตรวจทั้งพิมพ์จาก canvas และ backend snapshot ซึ่งใช้ข้อมูลเอกสารฉบับเดียวกัน พร้อมตรวจไฟล์ build ที่ commit
- PDF รายสัปดาห์/ตามตัวกรองใช้ browser print ผ่าน `BrowserExportController` และ `browser-export.ts` เป็นทางหลัก ต้องคง frozen revisions, signed user-bound URLs, scope ตำบล และตรวจ geometry/ฟอนต์/รูปก่อนเปิดพิมพ์ ห้ามรวม CSS ของแต่ละฉบับปนกันหรือเปลี่ยนสถานะงาน server เมื่อเปิดพิมพ์ฉบับค้าง
- สำรอง/เก็บ/นำกลับรายภาคเรียนใช้ `SemesterBackupService` และสิทธิ์ district ตรวจ ZIP, ลายเซ็น, checksums, ข้อมูลชนกัน และข้อมูล/ไฟล์ที่เปลี่ยนก่อน archive; ไม่รวมร่างส่วนตัว credentials หรือบัญชี และไม่ลบรูปที่อาจถูกใช้ร่วมกัน การ deploy ไม่เรียก archive อัตโนมัติ
- Student API ใช้ `StudentApiService` / `AcademicTermService` และตั้ง SDL token ที่ `/admin/student-api` เก็บเข้ารหัสใน `student_api_connections`; แยก cache/fallback ตามภาคเรียน ใช้ username ที่ได้รับมอบหมายจริงในการ scope SDL และรักษา `attendance_term`/สถานะมาเรียนเมื่อเปลี่ยนภาคเรียน

## 1. Project Context Agent

หน้าที่:

- อ่าน `CONTEXT.md`, `index.php`, `config.php`
- สรุปว่า request กระทบส่วนไหนของระบบ
- ระบุไฟล์ที่น่าจะต้องแก้และไฟล์ที่ควรระวัง

คำถามหลัก:

- งานนี้เกี่ยวกับ UI, API, database, PDF, auth, หรือ integration?
- มี role `district`/`tambon` เข้ามาเกี่ยวหรือไม่?
- ต้องแตะข้อมูลจริงใน `uploads/` หรือฐานข้อมูลหรือไม่?

ผลลัพธ์ที่ควรส่งต่อ:

- ขอบเขตงาน
- ไฟล์เป้าหมาย
- ความเสี่ยงหลัก

## 2. Frontend/UI Agent

หน้าที่:

- วิเคราะห์ `index.php`, `assets/js/app.js`, `assets/css/style.css`
- ตรวจ flow ปุ่ม, modal, form, table, filter, preview
- ดูว่า API response ถูก render ถูกต้องหรือไม่

คำถามหลัก:

- ผู้ใช้กดตรงไหนแล้วเกิดอะไร?
- state ใน `assets/js/app.js` ถูก reset/refresh ถูกจังหวะหรือไม่?
- ข้อความ error/success ชัดเจนไหม?
- layout ใช้งานได้บน desktop/mobile หรือไม่?

จุดที่ต้องระวัง:

- หน้าเว็บเป็น single-page style ที่ render หลายหน้าใน `index.php`
- JavaScript มี state หลายชุด เช่น records cache, student analysis state, PDF preview state
- การแก้ UI อาจต้องอัปเดตทั้ง HTML, JS และ CSS พร้อมกัน

## 3. Backend/API Agent

หน้าที่:

- วิเคราะห์ไฟล์ใน `api/`
- ตรวจ validation, authorization, SQL, JSON response
- ดูว่า API ถูกเรียกจาก frontend อย่างไร

คำถามหลัก:

- endpoint นี้ต้อง login หรือไม่?
- role `tambon` ถูกจำกัดข้อมูลตามตำบลหรือยัง?
- input จาก client ถูก normalize/validate หรือยัง?
- query ใช้ prepared statement หรือไม่?

จุดที่ต้องระวัง:

- อย่าส่งข้อมูล password/hash กลับ frontend
- `records.php` เกี่ยวข้องทั้ง record, image และ attendance
- `student_api.php` เป็น proxy ไปยังระบบภายนอกและมี fallback local

## 4. Database/Schema Agent

หน้าที่:

- อ่าน `database.sql` และส่วน auto-migration ใน `config.php`
- วิเคราะห์ผลกระทบถ้าเพิ่ม/แก้ column/index/table
- ตรวจความเข้ากันได้กับข้อมูลเก่า

คำถามหลัก:

- field ใหม่ต้องอยู่ใน table ไหน?
- ต้องเพิ่ม migration ใน `config.php` ด้วยหรือไม่?
- frontend/API/print ต้องรู้จัก field นี้ไหม?
- มี index ที่จำเป็นกับ query ใหม่หรือไม่?

จุดที่ต้องระวัง:

- `config.php` สร้างและแก้ schema อัตโนมัติบางส่วน
- ค่า default ใน `settings` มีผลกับ PDF และ preview
- มีทั้ง `database.sql` และ auto-migration ต้องไม่ปล่อยให้ schema สองที่ขัดกัน

## 5. PDF/Print Agent

หน้าที่:

- วิเคราะห์ `print.php`, `api/download_pdf.php`, `api/settings.php`, `pdfjs.php`
- สำหรับระบบ Laravel ปัจจุบัน วิเคราะห์ `laravel/app/Services/LegacyPrintService.php`, `BoundDocumentRenderer.php`, `RecordPdfService.php`, `RecordRevisionService.php` และ `laravel/resources/js/lib/document-print.ts` / `document-snapshot.tsx` ด้วย
- ตรวจ layout เอกสาร ลายเซ็น รูปภาพ attendance และ preview
- ดู dependency legacy `html2canvas`, `jspdf`, `pdfjs-dist` และ runtime ปัจจุบัน Chrome/Chromium, production snapshot build, Python3 + `pypdf` สำหรับ PDF รวม

คำถามหลัก:

- record นี้ควร render หน้าใดบ้าง?
- settings key ไหนมีผลกับตำแหน่ง/ฟอนต์/ลายเซ็น?
- preview HTML กับ download PDF ให้ผลตรงกันไหม?
- สิทธิ์เข้าถึง print token ถูกต้องไหม?

จุดที่ต้องระวัง:

- `print.php` มี mode หลายแบบ เช่น download, settings preview, html preview
- ระบบ PHP legacy ใช้ browser สร้าง PDF จาก HTML preview ส่วน Laravel ปัจจุบันสร้าง PDF ฝั่ง server ด้วย Chrome/Chromium และรวมไฟล์ด้วย Python3 + `pypdf`; อย่าสรุปว่าระบบปัจจุบันไม่มี server PDF
- ตัวจัดเอกสารมี browser print/Save as PDF ด้วย และเอกสารที่ผูกกับรายการใช้ canonical snapshot ผ่าน canvas renderer เดียวกันใน server PDF; ตรวจทั้งสองทางพร้อม private assets และ frozen revision
- Hosting จริงอยู่ใต้ `/sena_reflection` ต้องใช้ `npm run build:production` และ `npm run check:production-build` พร้อมส่ง `laravel/public/build/` ทั้งชุด หาก renderer/build/runtime ไม่พร้อม Laravel ตอบ 503 แทนส่ง PDF ที่จัดหน้าผิด
- การแก้ระยะเอกสารเล็กน้อยอาจกระทบเอกสารจริงทั้งระบบ

## 6. Security/Auth Agent

หน้าที่:

- ตรวจ session, token, role, password, upload, external API key
- วิเคราะห์ว่าข้อมูลส่วนตัวรั่วหรือเข้าถึงข้ามตำบลได้หรือไม่

คำถามหลัก:

- API นี้ใช้ `checkAuth()` หรือ token ที่ถูก scope แล้วหรือยัง?
- role `tambon` อ่าน/แก้ record ของตำบลอื่นได้ไหม?
- upload จำกัดชนิดไฟล์และขนาดแล้วหรือยัง?
- token สำหรับ print หมดอายุและตรวจลายเซ็นหรือไม่?

จุดที่ต้องระวัง:

- รูปภาพใน `uploads/` อาจเป็นข้อมูลจริง
- Student API key และ external URL ไม่ควรถูก expose เพิ่มจากที่มีอยู่
- อย่าลดเงื่อนไข auth เพื่อแก้ bug แบบเร็ว ๆ

## 7. Integration/Data Sync Agent

หน้าที่:

- วิเคราะห์ `api/student_api.php`, `api/teacher_directory.php`, `config/student_api.php`
- ตรวจการเชื่อมต่อ SDL School และ `sena_care_school` เดิม
- ดูการ normalize ข้อมูลครู/นักเรียนและ fallback

คำถามหลัก:

- ข้อมูลมาจาก URL ไหน?
- ถ้า API ภายนอกล่ม ระบบควรตอบอะไร?
- มีการ map ครูเข้ากับตำบลถูกต้องหรือไม่?
- ข้อมูลนักเรียนถูก filter ตาม user แล้วหรือไม่?

จุดที่ต้องระวัง:

- payload จากระบบภายนอกอาจเปลี่ยนรูปแบบ
- อย่า assume field name เดียว ให้ดู normalize function ก่อน
- timeout และ fallback มีผลต่อประสบการณ์ login/วิเคราะห์ผู้เรียน

## 8. QA/Test Agent

หน้าที่:

- วางแผนทดสอบหลังแก้ไข
- ตรวจ syntax, flow หลัก, edge cases
- สรุปสิ่งที่ทดสอบแล้วและยังไม่ได้ทดสอบ

รายการทดสอบพื้นฐาน:

- `php -l` สำหรับไฟล์ PHP ที่แก้
- login ด้วย role `district`
- login ด้วย role `tambon`
- เพิ่ม/แก้/ลบบันทึก
- upload รูป 2 หรือ 4 รูป
- เปิด preview/ดาวน์โหลด PDF
- ดู dashboard และ student analysis

## วิธีทำงานร่วมกัน

1. Project Context Agent ระบุขอบเขต
2. Agent เฉพาะด้านวิเคราะห์และเสนอไฟล์ที่ต้องแก้
3. Backend/Frontend/Database/PDF Agent ลงมือแก้ตามขอบเขต
4. Security/Auth Agent ตรวจสิทธิ์และข้อมูลอ่อนไหว
5. QA/Test Agent ตรวจผลลัพธ์
6. สรุปให้ผู้ใช้แบบสั้น ชัด และบอก test ที่ทำแล้ว

## รูปแบบ handoff ที่แนะนำ

ใช้รูปแบบนี้เมื่อส่งต่องานระหว่าง agent:

```text
งาน: สรุปสั้น ๆ ว่าต้องทำอะไร
ขอบเขต: ไฟล์/ฟีเจอร์ที่เกี่ยว
สิ่งที่พบ: ข้อเท็จจริงจากโค้ด
ความเสี่ยง: จุดที่ต้องระวัง
ข้อเสนอ: วิธีแก้หรือขั้นตอนถัดไป
ทดสอบ: สิ่งที่ควรทดสอบหลังแก้
```
