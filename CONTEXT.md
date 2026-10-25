# SENA Reflection: บริบทสำหรับพัฒนา

ระบบบันทึกหลังการสอนของ สกร. อำเภอเสนา ใช้บันทึกผลการจัดการเรียนรู้ ภาพประกอบ รายชื่อ/การมาเรียน และเอกสารรายงาน ระบบหลักปัจจุบันอยู่ใน `laravel/` ใช้ Laravel 13, React 19, TypeScript และ Inertia 3 บน MySQL

คู่มือเอกสาร: [docs.md](docs.md) · วิธีติดตั้ง: [README.md](README.md) และ [laravel/README.md](laravel/README.md)

## ระบบหลักและสิทธิ์

- `district`: ผู้ดูแลอำเภอ อ่านและจัดการรายการตามสิทธิ์ผู้ดูแล
- `tambon`: อ่านและจัดการบันทึกตามขอบเขตตำบล
- ร่าง (`teaching_drafts`) เป็นข้อมูลส่วนตัวของผู้สร้าง รวมถึงภาพและเอกสารของร่าง บัญชีอำเภอไม่สามารถอ่านร่างของผู้อื่น
- settings อาจกำหนดระดับกลาง ตำบล หรือผู้ใช้ ต้องรักษาลำดับ override และค่าเฉพาะรายการ

workflow หลัก: สร้างหรือแก้ร่างรายสัปดาห์ เลือกระดับที่สอน ตรวจรายชื่อ/ยอดรวม ใส่เนื้อหาและรูปในหน้าเดียว แล้วกดบันทึกเพื่อดูสรุปและยืนยันครั้งเดียว ทุกระดับที่เลือกบันทึกใน transaction เดียว การดู PDF/จัดเอกสารเป็นทางเลือก ไม่ใช่เงื่อนไขยืนยัน การบันทึกร่างหรือจัดเอกสารร่างไม่ใช่การยืนยันรายการ

หน้าภาพรวมส่ง `summary.unique_present_students` จาก DISTINCT TRIM(student_id) เฉพาะ record_attendance.present=true ภายใต้ record scope และ filters เดียวกับรายการ ไม่ใช้ SUM(records.total) เป็นจำนวนคนไม่ซ้ำ และไม่เดารายชื่อจากยอดรวมที่ไม่มี attendance ส่วน total_students/total_present ยังคงเป็นยอดสะสมรายบันทึกสำหรับคำนวณอัตรามาเรียน

## ตัวจัดเอกสารปัจจุบัน

`/records/{record}/document` เปิด `documents/edit` โดยตรง ใช้เอกสารผูกกับรายการผ่าน `binding_key=record:<id>` ส่วนร่างใช้ `draft:<uuid>:<level>` เริ่มจากแม่แบบตำแหน่ง ฟอนต์ไทย ระยะบรรทัด ลายเซ็น ภาพ และรายชื่อของรายงานเดิม แล้วเก็บการแก้ต่อเป็น canonical JSON

- TipTap JSON เป็นแหล่งเนื้อหา; page settings และ objects เป็น JSON แยก geometry ของหน้า/วัตถุใช้มิลลิเมตร บาง attr ภายในแม่แบบรักษาหน่วย px ของ layout เดิม
- ข้อความใน node ที่มี `attrs.field` ผูกกลับ record/draft รูปแบบที่ใช้ร่วมกับ legacy เก็บใน document settings และ rich text ที่ sanitize แล้ว
- เอกสารทั่วไปใน `/documents` และสำเนาแยกไม่ส่งการแก้กลับต้นทาง
- autosave ใช้ revision และ optimistic lock ตอบ 409 เมื่อมีการแก้จากอีกแท็บ เก็บสำเนาหรือโหลดล่าสุดได้
- เมื่อต้นทางเปลี่ยนนอก editor การโหลดล่าสุดปรับเฉพาะข้อความช่องที่ต้นทางเปลี่ยน พร้อมเก็บ version และรักษาการจัดวางส่วนอื่น
- managed map ใน binding แยกรูป/แถวข้อมูล/ตารางรายชื่อที่ระบบสร้างออกจากส่วนที่ผู้ใช้เพิ่ม เพื่อซิงก์ต้นทางโดยรักษารูปแบบและ assets ของประวัติ หลังแก้ต้นทางให้เปิด editor หรือโหลดล่าสุดก่อนพิมพ์ฉบับจัดหน้า
- ยืนยันร่างสำเร็จจึงย้าย binding ไป record และเพิ่ม revision กันแท็บร่างเก่าเขียนทับ
- ภาพเอกสารอยู่ใน private storage อ่านผ่าน route ตรวจสิทธิ์ ไม่ใช้ URL ไฟล์ส่วนตัวตรง ๆ

## เส้นทางโค้ดสำคัญ

หน้าเว็บใช้ธีม Minimalist พื้นขาว/เทาและสีน้ำเงินจาก `resources/css/app.css` ร่วมกับ shared UI components; หน้าเข้าสู่ระบบใช้ `pages/auth/login.tsx` และ `resources/css/login.css` แยกจากการจัดหน้าเอกสาร ห้ามนำการปรับธีมเว็บไปเปลี่ยนฟอนต์หรือ geometry ของ PDF

| ส่วน | ไฟล์/ตำแหน่ง |
| --- | --- |
| Routes | `laravel/routes/web.php`, `laravel/routes/documents.php` |
| หน้าเอกสาร | `laravel/resources/js/pages/documents/`, `components/editor/` |
| Canvas / pagination / extensions | `document-canvas.tsx`, `document-extensions.ts`, `lib/document-layout.ts` |
| Autosave / API / model | `hooks/use-document-autosave.ts`, `lib/document-api.ts`, `lib/document-model.ts` |
| แจ้งเตือนการบันทึก/ส่งออก | `lib/action-alert.ts`, `components/action-alert.tsx`, `components/flash-messages.tsx` (bridge ข้อความจาก server) |
| Persistence / binding / validation | `DocumentService`, `BoundDocumentService`, `DocumentSchema` |
| แม่แบบบันทึก | `RecordEditorTemplate`, `RecordDocumentOptions`, `DocumentRichText` |
| เปิดจาก record/draft | `RecordDocumentController`, `DraftDocumentController` ใน `app/Http/Controllers/` |
| ร่าง / ยืนยัน / ประวัติ | `DraftService`, `FinalizeTeachingSubmission`, `RecordRevisionService` |
| Print / PDF | `lib/document-print.ts`, `document-snapshot.tsx`, `BoundDocumentRenderer`, `LegacyPrintService`, `RecordPdfService` |
| Word export | `lib/document-export.ts` |
| Schema | `laravel/database/migrations/` |
| Tests | `laravel/tests/Feature/`, `laravel/tests/Unit/`, `laravel/tests/Frontend/` และ Playwright ตาม `laravel/playwright.config.ts` |

ชื่อคลาสในตารางหมายถึง `laravel/app/Services/` เว้นแต่ระบุเป็น controller ส่วนไฟล์ JavaScript อยู่ใต้ `laravel/resources/js/`

## พิมพ์และส่งออก

Browser print ใช้ DOM จาก canvas เดียวกับ editor แล้วเปิดหน้าพิมพ์แยก รองรับ Save as PDF โดยคงข้อความเลือกได้ Server อ่าน immutable snapshot ที่มี `advanced_document` สำหรับเอกสารผูกกับรายการ ผ่าน canvas snapshot renderer และ Chrome/Chromium ภาพอ่านจาก private assets ตาม document ID แล้วฝังในเอกสารพิมพ์

รูปจากบันทึก (`record-image-*`) ที่ยึดกับย่อหน้า `recordTitle` ใช้ `ObjectTextFlow.afterText` สำรองพื้นที่ใต้ข้อความ เพื่อให้หัวข้ออยู่เหนือภาพ ใช้ระยะจาก `anchor.offsetY` ที่บันทึกไว้เพื่อไม่ให้การวัด DOM ระหว่างจัดหน้ากระทบหน้ารายชื่อ ไม่เขียนแม่แบบหรือ paragraph IDs ทับเอกสารเดิม

PDF รายสัปดาห์/ตามตัวกรองบนหน้าเอกสารใช้ `/exports/browser-print` เป็นทางหลัก: capture frozen revisions ตามสิทธิ์แล้วโหลด signed HTML ทีละรายการ ไม่ dispatch queue และไม่เรียก Chrome ฝั่ง server `browser-export.ts` แยก stylesheet ของแต่ละฉบับใน Shadow DOM, hoist fonts และ named @page, ตรวจ geometry ข้อความ/รูปก่อนเปิด window.print ให้ผู้ใช้ Save as PDF งานค้างใช้ `/exports/{export}/browser-print` อ่าน manifest เดิมโดยไม่ recapture/เปลี่ยนสถานะ งาน server PDF/ZIP ยังมี API เดิมเป็นทางเลือก ต้องมี Chrome พร้อม sandbox, Python3 + `pypdf` และ worker ส่วน DOCX ใช้แก้ต่อใน Word การแบ่งหน้า การล้อมข้อความ และเส้นบรรทัดบางส่วนอาจต่างจาก PDF

งานส่งออกใช้ fingerprint ต่อ user + normalized filters + frozen revision manifest + renderer/build เพื่อ reuse queued/ready job อย่างถูกฉบับ ใช้ user row lock กันคำขอซ้ำพร้อมกัน และยังจำกัดงาน active โหลด revision เป็นชุดละ 25 รายการ; PDF cache แยกผู้ใช้และรุ่น renderer ใช้ filesystem lock/atomic rename และ pruner ไม่ลบ lock ที่ยังต้องใช้ `sena:work-exports` เป็น worker แบบจำกัดรอบสำหรับ Plesk cron; scheduler เรียกทุกนาที ต้องตั้ง `schedule:run` หรือ cron คำสั่งนี้จริงบน hosting จึงจะประมวลผลงาน ไม่ใช้ polling เปิด worker

ตรวจ hosting 24 ต.ค. 2569: deploy `e068f2d` + migration แล้ว เติม Debian 12 libraries และ pypdf ลง private `storage/app/private/document-runtime`; Chrome headless ยังติด `No usable sandbox` จึงคง task worker ปิดไว้ วันที่ 25 ต.ค. เปลี่ยนทางหลักเป็น browser PDF ตามคำขอผู้ใช้โดยไม่ต้องแจ้ง provider รักษาคิวเก่าไว้ ห้ามถือว่า Chrome --version หรือ sena:health ผ่านแล้วแปลว่าสร้าง server PDF จริงได้

Hosting จริงคือ `https://krumost.com/sena_reflection` ใช้ `npm run build:production` และ `npm run check:production-build` โดย commit `laravel/public/build/` พร้อม source ส่วน `npm run build` ใช้กับเครื่อง local/การติดตั้ง root อย่าส่ง artifact คนละ base path ปะปนกัน

## ข้อมูลและ schema

ตารางเดิมสำคัญคือ `users`, `tambons`, `records`, `record_images`, `record_attendance`, `settings` ตาราง Laravel เพิ่มร่าง รายสัปดาห์ revision งานส่งออก และ `documents` / `document_assets` / `document_versions` เอกสารผูกต้นทางใช้ `binding_key` unique nullable และ `binding_snapshot`

Laravel migrations เป็น schema หลักของโมดูลใหม่ ต้อง migrate เมื่อ deploy ควบคู่ build ตรวจฐานเป้าหมายจาก `.env` ไม่รัน migration บนข้อมูลจริงระหว่างทดสอบ การตั้งค่าฐาน/API key อยู่ใน environment หรือ config ที่ไม่ commit

## Legacy ที่ยังเก็บไว้

root `index.php`, `assets/js/app.js`, `assets/css/style.css`, `api/`, `config.php`, `database.sql`, `print.php` และ `pdfjs.php` เป็นระบบ PHP เดิม ใช้สำหรับ compatibility ส่วนที่ยังเรียกใช้ เปรียบเทียบแม่แบบ และการย้ายระบบ ไม่ใช่ frontend หลักปัจจุบัน `print.php` ยังอ้างอิง layout เก่า และ legacy renderer ใช้กับ snapshot ที่ไม่มี advanced document

`config.php` มี auto-migration ของ legacy ต้องอ่านผลกระทบก่อนแก้ ตารางโมดูล Laravel ใหม่ไม่ควรเพิ่ม auto-migration ซ้ำในไฟล์นี้ Student/teacher API ยังใช้ normalize และ fallback ผ่าน services อย่าสมมติ schema ระบบภายนอก

## ข้อควรระวังและการตรวจงาน

- `uploads/` และ storage อาจมีข้อมูลจริง ห้ามลบหรือแก้เพื่อสร้าง fixture; ใช้ฐานแยกและ fake storage ใน tests
- อย่าลด auth, role scope, print token scope หรือ private asset checks เพื่อแก้การแสดงผล
- อย่าส่ง password/hash, API key หรือ private filesystem path กลับหน้าเว็บ
- ตรวจ save/reload, draft ไม่ finalize, revision conflict, source change, ยืนยันร่าง, รูปส่วนตัว, PDF และสิทธิ์ทั้งสอง role
- ใช้ `php artisan test --compact`, `npm run typecheck`, `npm run build:production`, `npm run test:frontend` และ `npm run check:production-build` ก่อนส่งงาน hosting ตรวจ PDF จริงบนเครื่องที่มี Chrome และฟอนต์ครบ
- Playwright ใช้เฉพาะ loopback ของฐาน fixture แยก ผ่าน `SENA_E2E_BASE_URL`, `SENA_E2E_USER`, `SENA_E2E_PASSWORD` และ `SENA_E2E_RECORD_ID`; ไม่ทดสอบเขียนข้อมูลบน production
- `php artisan sena:health` ตรวจข้อมูล/ไฟล์พื้นฐาน ไม่ใช่การรับรองความเหมือนของ layout PDF

## ความเข้ากันได้ของรหัสรูปและรายชื่อ

`record_images.id` / `record_attendance.id` อาจมาจาก numeric AUTO_INCREMENT หรือ text primary key ของฐานนำเข้า ต้องอ่าน schema จริง ห้ามตีความ error 1364 ว่าเป็น text key Migration `2026_10_24_142034_restore_auto_increment_on_legacy_record_keys` คืน AUTO_INCREMENT ให้ numeric key ที่ขาดโดยรักษา ID เดิม แม้ไม่มี primary key หรือมี id=0 ซ้ำ ใช้ leading-id nonunique index แทนการบังคับ UNIQUE/เปลี่ยนรหัสเก่า และหยุดก่อน ALTER หากพบ NULL การสร้างตาราง/ซ่อม schema ทำตอน migrate เท่านั้น ไม่อยู่ใน transaction ของ finalize/revision และไม่ส่ง exception/SQL กลับหน้าเว็บ
