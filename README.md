# SENA Reflection

ระบบบันทึกหลังสอน Laravel + MySQL + React 19 + TypeScript + Inertia 3 + Tailwind CSS และ shadcn/ui

โค้ดระบบใหม่อยู่ใน [`laravel/`](laravel/) ส่วน PHP เดิมเก็บไว้สำหรับ API และการสร้างเอกสารที่ยังใช้ร่วมกัน

คู่มือสร้าง จัดการ และพิมพ์เอกสารแบบ Word: [docs.md](docs.md) · ภาพรวมระบบและไฟล์ที่เกี่ยวข้อง: [CONTEXT.md](CONTEXT.md)

ปุ่ม **จัดเอกสารก่อนพิมพ์** ของบันทึกและร่างเปิดตัวจัดเอกสารโดยตรง พร้อมแม่แบบรายงานเดิม บันทึกอัตโนมัติ ประวัติรุ่น และการพิมพ์ PDF จากเอกสารฉบับเดียวกัน เอกสารทั่วไปสร้างได้จากเมนู **เอกสาร**

สร้างบันทึกหลังสอนได้ในหน้าเดียว พร้อมสรุปและยืนยันครั้งเดียวสำหรับระดับที่เลือก ไม่ต้องยืนยันรายชื่อซ้ำหรือเปิด PDF ให้ครบก่อนบันทึก ร่างยังบันทึกอัตโนมัติและเปิดจัดเอกสารได้ตามต้องการ

## เริ่มใช้งาน

ต้องมี PHP 8.4.1 ขึ้นไป, Composer, Node.js และ MySQL ปุ่ม PDF ใช้เบราว์เซอร์ของผู้ใช้ เลือก Save as PDF ได้โดยไม่ต้องใช้ Chrome บน hosting ส่วน API สร้าง PDF/ZIP ฝั่ง server เป็นทางเลือกที่ต้องมี Chrome/Chromium พร้อม sandbox และ Python3 กับ `pypdf`

```bash
cd laravel
composer install
cp .env.example .env
php artisan key:generate
```

ตั้งค่าฐานข้อมูลใน `laravel/.env` ให้ตรงกับเครื่อง จากนั้น:

```bash
php artisan migrate --force
php artisan storage:link
npm ci
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

คำสั่งข้างต้นใช้กับ local/เว็บไซต์ที่ root เมื่อ deploy ไป **https://krumost.com/sena_reflection** ให้ใช้ `npm run build:production` และ `npm run check:production-build` แทน build ทั่วไป แล้ว commit/ส่ง `laravel/public/build/` ทั้งชุดพร้อม source โปรเจกต์นี้เก็บ build สำหรับ hosting ใต้ `/sena_reflection` ไว้ใน Git

เมื่ออัปเดตจากรุ่นเก่า ต้องรัน migrations ของ `documents`, assets, versions และ binding พร้อม build ของปลายทางเพื่อสร้าง snapshot entry สำหรับ PDF สำรองฐานและ private/public storage ก่อนอัปเดต และ restart export worker หลัง deploy ดูการตั้งค่า Chrome/Python, E2E fixture และการแก้ PDF 503 ใน [คู่มือเอกสาร](docs.md)

เปิด http://localhost:8000 ปุ่ม **PDF รายสัปดาห์ / PDF ตามตัวกรอง** เปิดหน้าพิมพ์รวมและตรวจตำแหน่งจากฉบับเดิมก่อนเปิดพิมพ์ ส่วนการใช้งานส่งออกผ่าน server API ให้เปิดอีก terminal:

```bash
cd laravel
php artisan queue:work exports --queue=exports --tries=1 --timeout=1800 --sleep=1
```

เมื่อติดตั้งฐานใหม่ สามารถรัน `php artisan db:seed` เพื่อสร้างบัญชีเริ่มต้นตาม `DatabaseSeeder` แล้วเปลี่ยนรหัสผ่านทันที ห้าม seed ทับบัญชี production โดยไม่ตรวจสอบก่อน

## API นักศึกษา

คัดลอก `config/student_api.example.php` เป็น `config/student_api.php` แล้วตั้งค่า URL และ key ของระบบที่ได้รับอนุญาต หรือกำหนด `SENA_STUDENT_API_URL` / `SENA_STUDENT_API_KEY` ใน environment ของแอป ไฟล์จริงถูกยกเว้นจาก Git

หากใช้ MAMP พอร์ต 8888 สามารถคัดลอก `.htaccess.example` เป็น `.htaccess` เพื่อส่งทางเข้าหลักไป Laravel พอร์ต 8000 ได้ แต่ต้องเริ่ม Laravel ด้วยคำสั่งข้างต้นก่อน

## ข้อมูลส่วนตัว

Repository นี้ไม่มี `.env`, คีย์ API, database dump, ไฟล์สำรอง, รูปผู้เรียน, uploads, เอกสารที่สร้างแล้ว หรือ dependencies ต้องย้ายข้อมูลจริงแยกด้วยช่องทางที่ปลอดภัย ไม่ใช่ Git

ผู้ดูแลอำเภอสำรอง/นำเข้าข้อมูลรายภาคเรียนได้จาก **จัดการระบบ → สำรองข้อมูลภาคเรียน** โดยดาวน์โหลดและตรวจชุดสำรองก่อนเก็บข้อมูลเก่าออกจากฐานใช้งาน มีการตรวจไฟล์และข้อมูลชนกันก่อนนำกลับ หน้า **ตั้งค่า Student API** รองรับโทเค็น SDL School และรายชื่อแยกปี/ภาคเรียน ดูขั้นตอนและขอบเขตข้อมูลใน [คู่มือ](docs.md#เปลี่ยนภาคเรียน-รายชื่อนักศึกษา-และชุดสำรอง) ZIP ผูกกับ `APP_KEY` เดิมและไม่ทดแทนการสำรองฐานข้อมูล/กุญแจทั้งระบบ

ดูรายละเอียดเพิ่มเติมใน [คู่มือ Laravel](laravel/README.md)

แก้ปัญหาบันทึก MySQL 1364/1366: หลังอัปเดตต้องรัน `php artisan migrate --force` ใน `laravel/` เพื่อคืน AUTO_INCREMENT ที่ขาดของรหัสตัวเลข รองรับตารางไม่มี primary key และรหัส 0 ซ้ำ โดยรักษารูปและรหัสเดิม หาก migration รุ่นเดิมล้มเหลวให้อัปเดตไฟล์แล้วรันคำสั่งเดิมซ้ำ ดูขั้นตอน Plesk และการตรวจผลใน [docs.md](docs.md)
