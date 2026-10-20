# SENA Reflection

ระบบบันทึกหลังสอน Laravel + MySQL + React 19 + TypeScript + Inertia 3 + Tailwind CSS และ shadcn/ui

โค้ดระบบใหม่อยู่ใน [`laravel/`](laravel/) ส่วน PHP เดิมเก็บไว้สำหรับ API และการสร้างเอกสารที่ยังใช้ร่วมกัน

## เริ่มใช้งาน

ต้องมี PHP 8.4.1 ขึ้นไป, Composer, Node.js และ MySQL สำหรับ PDF ต้องมี Chrome/Chromium และ Python3 พร้อม `pypdf`

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

เปิด http://localhost:8000 และเปิดอีก terminal สำหรับงานส่งออก:

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

ดูรายละเอียดเพิ่มเติมใน [คู่มือ Laravel](laravel/README.md)
