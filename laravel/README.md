# SENA Reflection — Laravel 13

ระบบ Laravel ใหม่วางคู่กับ legacy PHP เดิมโดยไม่ลบไฟล์หรือข้อมูลเก่า ใช้ MySQL ฐาน `sena_reflection` ร่วมกันและรองรับ schema เดิม

คู่มือผู้ใช้: [สร้าง จัดการ และพิมพ์เอกสาร](../docs.md) · ภาพรวมโค้ด: [CONTEXT.md](../CONTEXT.md)

## ตัวจัดเอกสาร 23 ตุลาคม 2569

- ปุ่ม **จัดเอกสารก่อนพิมพ์** ของ record/draft เปิด `documents/edit` โดยตรง เริ่มจากแม่แบบเดิมที่คงฟอนต์ไทย ระยะขอบ เส้นบรรทัด ช่องข้อมูลและลายเซ็น แล้วเก็บการแก้ต่อด้วย TipTap JSON
- เลือกคำเพื่อจัดรูปแบบ แก้ตาราง เพิ่มรูป กล่องข้อความ รูปร่าง เส้น และวัตถุที่อ้างหน้าหรือย่อหน้า พร้อมปรับขนาด หมุน และล้อมข้อความ
- autosave ตรวจ revision; เอกสารผูก record ซิงก์ช่องข้อมูลกลับ record ส่วนเอกสารผูกร่างบันทึกเฉพาะร่าง การยืนยันร่างจริงจึงย้ายเอกสารไปผูก record
- เอกสารทั่วไปและสำเนาเก็บแยกใน `/documents`; private assets อ่านผ่าน endpoint ที่ตรวจสิทธิ์ ประวัติรุ่นและ frozen record snapshots เก็บ canonical document สำหรับพิมพ์ย้อนหลัง
- Browser print และ server PDF ของ advanced snapshot ใช้ canvas renderer เดียวกัน Server ต้องมี production snapshot build และ Chrome/Chromium; PDF รวมต้องมี Python3 กับ `pypdf`
- DOCX เปิดแก้ใน Word ได้ แต่ pagination วัตถุลอยและเส้นบรรทัดของแม่แบบอาจต่างจาก PDF รายละเอียดและวิธีรับมือข้อขัดแย้งอยู่ในคู่มือ

## Frontend stack

- React 19 + TypeScript
- Inertia.js 3 สำหรับเชื่อม Laravel โดยไม่ต้องแยก REST frontend
- Tailwind CSS 4 และ shadcn/ui primitives
- Responsive layout, dark mode, keyboard focus และ reduced-motion support

## อัปเดต workflow 17 ตุลาคม 2569

อัปเดต 18 ตุลาคม 2569: เลือกระดับที่สอนจริง 1–3 ระดับด้วย toggle โดยไม่บังคับระดับที่ไม่มี จำกัดการสร้าง/แก้ไขสัปดาห์ 1–20 และรวม preview/พิมพ์/ดาวน์โหลดให้ใช้ snapshot renderer เดียวกัน เค้าโครงที่ตรวจแล้วถูกเก็บตามฉบับ ไม่เปลี่ยนตามค่ากลางภายหลัง ข้อมูลเก่าสัปดาห์เกิน 20 ยังอ่านและส่งออกได้

อัปเดต 24 ตุลาคม 2569: ลดขั้นตอนเป็นหน้าแก้ไขพร้อมสรุปยืนยันครั้งเดียว และให้ PDF เป็นทางเลือก

- สร้างและแก้ไขใช้ workspace เดียวกัน เก็บร่างบนเซิร์ฟเวอร์อัตโนมัติ กลับมาทำต่อจาก dashboard/URL ร่างได้
- ตรวจรายชื่อและวิธีเรียนแบบรายคนโดยไม่เลือกมาเรียนให้อัตโนมัติ หรือกรอกยอดรวมเมื่อไม่มีรายชื่อ ไม่ต้องยืนยันซ้ำแยกแต่ละระดับ
- กรอกข้อมูลในหน้าเดียว กดบันทึกเพื่อดูสรุปและยืนยันครั้งเดียวตามระดับที่เลือก โดยบันทึกใน transaction เดียว เปิด PDF/จัดเอกสารได้ตามต้องการและไม่เป็นเงื่อนไขยืนยัน
- เปิดตัวจัดเอกสารจากหน้าดูตัวอย่างเอกสารได้ก่อนสร้างรายการจริง แก้ข้อความ กระดาษ รูป และ toggle หน้ารูป/รายชื่อแยกแต่ละครั้ง
- รูปมีจุดจับ 8 ทิศ ล็อก/ปลดสัดส่วน หมุน ครอบภาพ และ undo/redo; layout เก็บตาม ID รูป จึงไม่สลับผิดเมื่อเรียงรูปใหม่
- ป้องกันร่างหลายแท็บเขียนทับกัน และแจ้งเมื่อรายการต้นทางถูกแก้จากที่อื่น
- เก็บ revision เอกสาร และส่งออก PDF รายสัปดาห์/ZIP ตามตัวกรองผ่านงานเบื้องหลัง มีสถานะ ความคืบหน้า ลองใหม่ และลิงก์ส่วนตัวหมดอายุ 24 ชั่วโมง

รายวิชาปัจจุบันเลือกจากประวัติที่มีสิทธิ์อ่าน ไม่ได้สมมติ API รายวิชาขึ้นมาใหม่ ส่วนรายชื่อ/กลุ่มใช้ API นักศึกษาเดิมพร้อมข้อมูลสำรองเมื่อปลายทางใช้ไม่ได้

## ฟีเจอร์ที่พร้อมใช้

- Login ด้วยบัญชี `district` / `tambon` เดิม และ upgrade password แบบ plaintext เป็น hash เมื่อ login
- Dashboard แบบ server-side pagination ครั้งละ 30 รายการ
- แก้ไข/ลบบันทึก, bulk-upsert attendance, รักษาขอบเขตสิทธิ์ตำบล และลบ managed image หลัง transaction สำเร็จ
- หน้าผู้ดูแลสำหรับ users, tambons และค่าเอกสาร/PDF
- ตัวจัดเอกสารของบันทึกและร่างแบบเต็มหน้า พร้อมข้อความ ตาราง ลายเซ็น รูป และ autosave; สถานะด้านบนแสดงว่าบันทึกสำเร็จแล้วหรือยัง
- ปรับกระดาษ ระยะขอบ ฟอนต์ ระยะห่าง และวัตถุในเอกสาร พร้อมบันทึกและพิมพ์จากฉบับปัจจุบัน
- โปรไฟล์, avatar และเปลี่ยนรหัสผ่านโดยตรวจรหัสปัจจุบัน
- Filter ตามปีการศึกษา + ภาคเรียน + สัปดาห์ + ตำบล โดยไม่ปนข้ามปี/ภาคเรียน
- ส่งบันทึกประจำสัปดาห์ครบ 3 ระดับชั้นใน transaction เดียว
- ฟอร์มสร้างบันทึกแบบ API-first เลือกกลุ่มแล้วเติมรหัสกลุ่ม ชื่อกลุ่ม และรายชื่อนักศึกษาอัตโนมัติ พร้อมเลือกผู้มาเรียนโดยไม่ต้องกรอกยอดเอง
- รายวิชาและรหัสวิชาเลือกซ้ำได้จากประวัติบันทึกตามสิทธิ์ของผู้ใช้ และยังกรอกวิชาใหม่ได้เมื่อจำเป็น
- ป้องกันส่งซ้ำด้วย unique key `tambon + year + semester + week`
- แนบรูปได้ไม่เกิน 4 รูปต่อระดับ และลบไฟล์ที่อัปโหลดแล้วหาก transaction ล้มเหลว
- ดาวน์โหลดรายสัปดาห์เป็น PDF รวม
- ดาวน์โหลดทั้งหมดตามสิทธิ์เป็น ZIP โดยมี PDF และ `index.csv`
- CSRF, login/export rate limit, query scope ตาม role และ validation ฝั่ง server

## เริ่มระบบ

```bash
cd "/Applications/MAMP/htdocs/sena_reflection 4/laravel"
php artisan migrate --force
php artisan storage:link
npm ci
npm run build
php artisan serve
```

คำสั่ง `npm run build` ด้านบนใช้กับ local หรือแอปที่ root โดยเว้น `ASSET_URL` / `VITE_APP_BASE_PATH` ว่าง สำหรับ hosting จริง **https://krumost.com/sena_reflection** ต้องตั้ง `APP_URL` / `ASSET_URL` ให้ตรง hosting และสร้าง artifact ดังนี้:

```bash
npm run build:production
npm run check:production-build
```

โปรเจกต์ commit `public/build/` สำหรับ base path `/sena_reflection` พร้อม source ต้องส่งไฟล์ build ทั้งชุด ไม่ใช้ root-local build อัปโหลดทับก่อน deploy

เปิด terminal อีกหน้าสำหรับสร้างไฟล์ส่งออก (ต้องทำงานค้างไว้):

```bash
php artisan queue:work exports --queue=exports --tries=1 --timeout=1800 --sleep=1
```

ระหว่างพัฒนาใช้ `composer run dev` เพื่อเปิดเว็บ Vite และ worker รวมกันได้ ตรวจ MySQL ของ MAMP ให้ทำงานก่อนเสมอ

เปิด `http://localhost:8000` หรือตั้ง MAMP Document Root เป็นโฟลเดอร์ `laravel/public`

บนเครื่องนี้ `.htaccess` ของโปรเจกต์จะส่ง `localhost:8888/`, `/index.php` และ `/login` ไป Laravel พอร์ต `8000` เพื่อไม่ให้เปิด UI เก่าโดยไม่ตั้งใจ โดยไม่เปลี่ยนเส้นทาง API/print/uploads เดิม หากเปลี่ยน MAMP Document Root ไป `laravel/public` โดยตรง ต้องเลือก PHP 8.4.1 ขึ้นไปให้ตรงกับ dependencies ที่ติดตั้ง (MAMP ปัจจุบันเป็น 8.3.14)

ค่าฐานข้อมูลอยู่ใน `.env` และตั้งไว้สำหรับ MAMP MySQL พอร์ต `8889` แล้ว หากเครื่องใช้พอร์ตอื่นให้แก้ `DB_PORT`

## ทดสอบ

```bash
php artisan test
npm run typecheck
npm run build:production
npm run test:frontend
npm run check:production-build
php artisan sena:health
```

frontend tests ตรวจ production build ที่ commit ด้วย หลังตรวจจึง rebuild แบบ local ได้เมื่อต้องการทดสอบเว็บ root บนเครื่อง ก่อน commit/deploy ต้องกลับมาสร้าง production build ให้ตรง source

E2E ใช้ `npm run test:e2e` โดยกำหนด `SENA_E2E_BASE_URL` เป็น loopback ของฐาน fixture แยก พร้อม `SENA_E2E_USER`, `SENA_E2E_PASSWORD` และ `SENA_E2E_RECORD_ID` ของรายการทดสอบเท่านั้น ชุดนี้เขียนข้อมูลผ่านเว็บจริง ดูตัวอย่างและข้อกำหนด Chrome ใน [docs.md](../docs.md)

ทดสอบขั้นตอนสร้างบันทึกด้วย fixture อัตโนมัติได้ด้วย `SENA_WORKFLOW_E2E=1 npx playwright test teaching-workflow.spec.ts --workers=1` โดยไม่มี Vite dev server เดิมทำงาน ชุดนี้สร้าง SQLite และ storage ชั่วคราวเอง แล้วล้างเมื่อเสร็จ

การทดสอบครอบคลุม atomic batch 3 ระดับ, missing/duplicate level, duplicate week, tambon scope, legacy password upgrade, dashboard, Student API fallback, legacy API compatibility, weekly PDF และ all-record ZIP

## ความเข้ากันได้กับระบบเดิม

- ไม่ลบ/เปลี่ยน `uploads/` เดิม
- เก็บ primary key แบบ string ของ user/tambon/record
- PDF service อ่านรูปเก่าจาก root `uploads/` และรูปใหม่จาก Laravel storage
- URL เดิมในรูปแบบ `/api/*.php` และ `/print.php` ยังเรียกใช้งานได้
- Student API อ่านค่าจาก `.env` หรือ `../config/student_api.php` และ fallback เป็นข้อมูล attendance ในระบบเมื่อปลายทางล่ม
- migration มี `down()` แบบ non-destructive เพื่อไม่ลบข้อมูล legacy โดยพลาด

ไฟล์สำรองก่อน migration เดิมอยู่ที่ `../backups/sena_reflection_pre_laravel_20260830.sql` และก่อนเพิ่ม draft/revision/export อยู่ที่ `../backups/sena_before_workspace_20261016.sql`

ตาราง workspace ใหม่ใช้ Laravel migrations เป็นแหล่ง schema หลัก ไม่ได้สร้างผ่าน auto-migration ของ legacy `config.php`; ต้องรัน `php artisan migrate --force` เมื่อติดตั้ง Laravel จากฐาน legacy ใหม่

โมดูล editor เพิ่มตาราง `documents`, `document_assets`, `document_versions` และคอลัมน์ `binding_key` / `binding_snapshot` ต้อง deploy migrations, backend และ build ของปลายทางพร้อมกัน สำหรับ hosting จริงใช้ `npm run build:production` + `npm run check:production-build` build ต้องมี entry `resources/js/document-snapshot.tsx` จึงใช้ server PDF ของ advanced snapshot ได้ หลัง deploy ให้ restart worker เพื่อนำโค้ดและ config ใหม่มาใช้

กำหนด `SENA_CHROME_BIN`, `SENA_PYTHON_BIN` และ `PHP_CLI_BINARY` เป็น absolute executable paths ได้ใน environment ตาม `config/services.php` ดูขั้นตอนติดตั้ง แก้ฟอนต์ไทยและ PDF 503 ใน [docs.md](../docs.md)

คำสั่ง `php artisan sena:backfill-weekly` ใช้ preview การจัดกลุ่มข้อมูลเก่า และเพิ่ม `--apply` เมื่อต้องการเชื่อมรายการ legacy เข้ากับ weekly parent ใหม่

## ก่อน production

- ตั้ง `APP_ENV=production` และ `APP_DEBUG=false`
- ย้าย DB password ออกจากค่า default ของ MAMP
- รัน `php artisan optimize`
- ดูแล export worker ด้วย Supervisor/launchd และตั้ง Laravel scheduler ทุกนาที เพื่อเรียกงานล้าง export ที่หมดอายุรายวัน (`sena:prune-exports`)
- เครื่องสร้างเอกสารต้องมี Chrome/Chromium และ Python3 พร้อม `pypdf` สำหรับรวม PDF; ไฟล์ส่งออกและร่างเก็บ private local storage ต้องรวมในนโยบายสำรองข้อมูล
- ทดสอบปลายทาง API ภายนอกจริงด้วยบัญชี/เครือข่ายใช้งาน และทดลองกับครูกลุ่มเล็กก่อนเปิดทั้งสถานศึกษา

### ซ่อมรหัสรูป/รายชื่อสำหรับฐานเก่า (24 ตุลาคม 2569)

รัน `php artisan migrate --force` ตามด้วย `php artisan optimize:clear` และ `php artisan sena:health` หลัง deploy รุ่นแก้บันทึก Migration ตรวจชนิดคอลัมน์จริงและคืน AUTO_INCREMENT เฉพาะ numeric primary key ที่ขาด โดยไม่เปลี่ยนรหัสเดิมหรือแปลง text key โมเดลไม่เดาชนิดจาก exception และไม่สร้างตารางระหว่าง transaction บันทึก ดูรายละเอียด MySQL 1364/1366 และการรันผ่าน Plesk ใน [docs.md](../docs.md)
