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

ตัวแก้ไขใช้ `.de-action-bar` ใน `pages/documents/edit.tsx` ค้างใต้ AppShell ที่ 65px; `.de-workspace` ใช้ `overflow:clip` เพื่อไม่ขวาง sticky ขณะที่ canvas/sidebars ยังคง scroll เดิม ค่าสี UI ใน `document-editor.css` ต้องเป็นช่องสี HSL เพื่อใช้กับ shared Button ผ่าน `hsl(var(...))` โดยไม่แก้สีหรือขนาดของเนื้อหาเอกสาร

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

ระบบสำรองภาคเรียนอยู่ที่ `/admin/semester-backups` สำหรับ district เท่านั้น `SemesterBackupService` จัด ZIP แบบ whitelist พร้อม manifest, checksums และลายเซ็นผูก `APP_KEY` ใน private storage ตรวจ fingerprint ข้อมูล/ไฟล์และสำเนาภายนอกก่อน archive เก็บ records/attendance/images/layouts/revisions และ canonical documents/assets/versions ไม่เก็บ credentials/settings/private drafts; active drafts และงานส่งออกที่อ้างอิงเป็น blockers รุ่น 2 รองรับรายงานไฟล์ที่สูญหายโดยต้องยืนยันทั้งก่อนสำรองและก่อน archive และยังอ่าน ZIP รุ่น 1 ได้

การคืนพื้นที่เป็นขั้นตอนแยก: `SemesterBackupStorageService` ลบเฉพาะไฟล์ที่ checksum ตรงและไม่มีข้อมูลที่เหลืออ้างอิง ตรวจภายใต้ transaction/row locks และล็อกเดียวกับ restore; รูปใช้ร่วมกันหรือเปลี่ยนไปต้องเก็บไว้ จากนั้น offload ลบเฉพาะ ZIP บนเซิร์ฟเวอร์และเก็บสถานะ offline ให้เลือกนำเข้าจากสำเนาภายนอก `SemesterBackupUploadService` แบ่งอัปโหลด ZIP ไม่เกิน 2 GB ลงไฟล์ private เดียว ตรวจทุกส่วนและ ZIP ทั้งชุดก่อนย้ายเข้ารายการ ไม่เขียนทับข้อมูลเมื่อ restore ไม่มีการ archive/release/offload ระหว่าง deploy

ปุ่มหลัก **ล้างข้อมูลที่สำรองแล้ว** เรียก closeout ซึ่งนำบันทึกออกแล้วคืนพื้นที่รูปต่อให้ในคำขอเดียว ใช้ตรวจ ZIP/ข้อมูลเดิม และล็อกร่วมกับ restore หากไฟล์ลบไม่สำเร็จต้องรายงานส่วนที่ทำสำเร็จและลอง cleanup ต่อโดยไม่ archive ซ้ำ ผู้ดูแลเลือกพักร่างที่เกี่ยวข้องโดยยืนยัน fingerprint ล่าสุดได้ สถานะ `paused` และ lock version เปลี่ยนใน transaction เดียวกับ archive โดยไม่แตะ payload/images/documents ส่วนตัว `DraftLifecycleService` และหน้า `/teaching-drafts/manage` จำกัดเฉพาะเจ้าของสำหรับ pause/resume; ร่างผูกต้นฉบับต้อง restore ต้นฉบับที่ fingerprint ตรงก่อน resume

ZIP รุ่น 3 เพิ่ม `SemesterBackupPresentation`: หน้า `index.html` ภาษาไทยสำหรับเปิดออฟไลน์, README-TH และ CSV UTF-8 BOM, รูป `images/000001.jpg` ตาม MIME จริง เอกสารอ่านง่ายทั้งหมดอยู่ใน signed manifest พร้อม checksum และขีดจำกัดรวม 8 MB; การนำกลับยังใช้ข้อมูลดิบครบ 9 ตาราง และรองรับ ZIP v1/v2 เดิม ชื่อ ZIP entry ไม่นำมาเทียบเป็นการเปลี่ยนต้นฉบับ แต่ disk/path/size/MIME/SHA ต้องตรงเดิมทุกค่า ตัวเลือก `offload_server_zip` ใน closeout ใช้ offload หลัง cleanup สำเร็จและปล่อย lock เดิมแล้วเท่านั้น คืนผลบางส่วนตามจริงเมื่อ offload ล้มเหลว UI เปิดตัวเลือกนี้เริ่มต้นโดยยังต้องยืนยันสำเนาภายนอก

`ActiveAcademicTermService` กำหนดภาคเรียนเริ่มต้นจาก SDL metadata ที่ตรวจแล้วและแคชแยก credential รวม `student_api_connections.latest_term`; ไม่เดาจาก records และไม่เรียก HTTP บน dashboard/backup GET การอ่านย้อนหลังไม่ลด latest term และ late response ของ token เก่าไม่เขียนค่าทับ connection ใหม่ หน้าแรก/ร่างที่แสดง/สร้างบันทึก/ข้อมูลนักศึกษา/วิเคราะห์เข้าเรียนเริ่มที่เทอมปัจจุบัน ผู้ใช้เลือกย้อนหลังหรือ scope=all ได้ชัดเจน การนำเข้า ZIP ไม่เปลี่ยนเทอมเริ่มต้นและมีลิงก์เปิดเทอมที่ restore แล้ว ไม่มีการลบระหว่าง API fetch/deploy

`SemesterTermRepairService` ตรวจปลายทางและ fingerprint ก่อนสร้าง ZIP ต้นทาง แล้วแก้ปี/ภาคเรียนภายใต้ transaction พร้อมเก็บ revision และรูปแบบเอกสารเดิม ไม่แก้ข้อมูลที่ชนหรือร่างส่วนตัว ทุกช่องทางบันทึกใหม่และ finalize ตรวจปี พ.ศ. รูปแบบ 25xx; UI ใช้ตัวเลือกปีเพื่อลดการพิมพ์ผิด

ตรวจจริง 27 ต.ค. 2569 หลัง deploy `4af0d04` และ `optimize:clear`: แก้ปีผิดสองกลุ่มเป็น 1/2569 โดยสำรองต้นทางก่อน ทั้งภาคเรียนมี 770 บันทึก สำรอง ZIP 104.4 MB ดาวน์โหลดและตรวจ CRC/SHA-256 ผ่านครบ 1,754 ไฟล์ที่ยังอยู่ พร้อมรายงานไฟล์สูญหายเดิม 12 ไฟล์ ไม่ archive/release/offload ข้อมูลจริง; ยังมีร่างส่วนตัวที่ใช้งาน 5 ร่างเป็น blocker ตามสิทธิ์เดิม

ตรวจจริง 28 ต.ค. 2569 หลัง deploy `25a7ece`: สร้างและตรวจ ZIP v3 ใหม่ 1/2569 (770 records/1758 assets), 1/2568 (3/8), 1/2567 (83/204) ดาวน์โหลด CRC/data/asset/readable checksums ผ่านครบแล้ว closeout/offload ทั้งสามภาคเรียน รวม 856 records พร้อมพัก private drafts 5 ร่างตามคำอนุมัติ ไม่มีภาคเรียนเก่าเหลือใน active records หน้าแรก SDL2/2569 มี 0 บันทึก Offload สำเนาเก่า 2f912532 ของ 1/2569 อีกชุดหลังตรวจสำเนาภายนอกแล้ว ZIP ทั้งสี่คืนพื้นที่235643046bytes แยกจากรูปที่ cleanup คืน รายงานต้นฉบับสูญหายเดิม12ไฟล์ยังอยู่ใน ZIP ไม่ได้กู้รูปที่ไม่มีต้นฉบับ สำเนาเก็บใน Downloads/SENA-สำรองภาคเรียน-28-10-2569 พร้อมหน้ารวมภาษาไทยและSHA ดู docs.md สำหรับผลทดสอบและขั้นตอน restore

Student API รองรับ SDL School `/api/v1/integrations/student-data/students` ผ่าน `StudentApiService` พร้อม term `2/2569` และ teachers assigned-groups mapping สำหรับ scope บัญชีตำบล ใช้ `AcademicTermService` ช่วยเลือกเทอมและแยก cache/fallback ตามเทอม ผู้ดูแลตั้งค่าและทดสอบโทเค็นผ่าน `/admin/student-api` เก็บ encrypted token ใน `student_api_connections` แยกจาก settings/เอกสาร ไม่ส่ง secret กลับ client คีย์ legacy ของ sena_care_school ใช้กับ SDL ไม่ได้ ต้องตั้งโทเค็นที่ active ก่อนเชื่อมข้อมูลจริง

ตรวจเว็บจริงวันที่ 26 ตุลาคม 2569 แล้ว: เพิ่ม API ครูแบบอ่านอย่างเดียว ติดตั้ง migration ตารางเก็บการเชื่อมต่อ และบันทึกโทเค็น Reflection เฉพาะอำเภอเสนาแบบไม่หมดอายุสำเร็จ ทดสอบหลัง reload จากโทเค็นเข้ารหัสและหน้าข้อมูลนักศึกษาพบภาคเรียน 2/2569 จำนวน 631 คน SDL production ใช้ allowlist `reflection_integration.non_expiring_client_ids` เฉพาะ client 4 เพื่อรักษาพฤติกรรมของ client อื่น; client ทดลอง 3 ถูกเพิกถอนแล้ว ดู source patch, tests และ rollback ใน `docs/deployment/sdl-reflection-teachers/README.md` ห้ามขยาย allowlist หรือใช้ SQL template ซ้ำระหว่าง deployment ปกติ

ตัวเลือกรายวิชาใช้ `StudentApiService::subjects()` เรียก SDL `/subjects` ครั้งเดียวพร้อม term ชัดเจนและตรวจ `meta.term` เพราะ endpoint นี้ไม่แบ่งหน้าและไม่ส่งระดับชั้น ไม่อนุมานระดับจากรหัสวิชา ส่งกลับเฉพาะชื่อ/รหัส/แหล่งข้อมูล แคชแยก credential fingerprint และภาคเรียน 300 วินาที รวมประวัติที่ผู้ใช้มีสิทธิ์ในเทอมเดียวกันโดยให้ชื่อ API ชนะเมื่อรหัสซ้ำ หากโหลดไม่สำเร็จแสดงคำเตือนและคงการกรอกเอง ไม่เปลี่ยนร่างหรือ attendance อัตโนมัติ

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
