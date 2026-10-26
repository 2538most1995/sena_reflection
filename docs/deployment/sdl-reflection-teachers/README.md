# SDL School: รายชื่อครูสำหรับเชื่อม Reflection

บันทึกการติดตั้งวันที่ 25–26 ตุลาคม 2569 และไฟล์สำหรับตรวจสอบ/ติดตั้ง endpoint แบบอ่านอย่างเดียวบน SDL School รุ่นที่ยังไม่มี `/teachers` ไฟล์ชุดนี้เป็นเอกสารการติดตั้งต่างระบบ ไม่ถูกโหลดโดยแอป Reflection และไม่ควรคัดลอกเข้า public directory ทั้งโฟลเดอร์

## ผลการติดตั้งจริง

- เพิ่ม `ReflectionTeacherController` และ GET route `/teachers` ภายในกลุ่ม integration ที่ตรวจ token, บทบาท admin/super_admin และ rate limit เดิม
- Endpoint คือ `https://krumost.com/SDL_school/api/v1/integrations/student-data/teachers` การเรียกโดยไม่ยืนยันตัวตนตอบ 401
- ตรวจ schema จริงพบ `users.assigned_groups` เป็น text; controller รองรับทั้ง model cast เป็น array และ JSON string
- สร้าง API client ใหม่หมายเลข **4** ชื่อ `sena-reflection-20261026` ผูกผู้ออก user ID 3 กับอำเภอเสนา district ID 1
- สิทธิ์มีเฉพาะ `student-data:read` และ `sena-care:teachers` ไม่มีสิทธิ์ `sena-care:authenticate`
- Client 4 **ไม่มีวันหมดอายุอัตโนมัติ** (`expires_at = NULL`) ตรวจ metadata จริงแล้วตามคำยืนยันของผู้ใช้เมื่อ 26 ตุลาคม 2569
- ตรวจพบ middleware ของ SDL เดิมรับเฉพาะ `expires_at > now()` จึงติดตั้งการยกเว้นแบบ allowlist สำหรับ client 4 แล้ว ตรวจ source ก่อนแก้ว่าไม่เปลี่ยนจากฉบับที่อ่าน และตรวจฉบับหลังแก้ตรง artifact ทั้งไฟล์ โทเค็นไม่มีวันหมดอายุของ client อื่นยังไม่อยู่ในเงื่อนไขยกเว้น ทดสอบ client 4 หลัง deploy สำเร็จแล้ว
- สำรอง middleware เดิมไว้นอก `httpdocs` ที่ `sena-private-backups/sdl-auth-before-reflection-no-expiry-20261026.php` และสำรอง routes เดิมจากรอบวันที่ 25 ไว้แล้ว
- Client ทดลองรอบแรกหมายเลข 3 ชื่อ `sena-reflection-20261025` เป็นคนละ credential และเพิกถอนแล้วหลังยืนยันการบันทึก client 4 โดย guarded update เปลี่ยนเพียง 1 แถว ตรวจ `revoked_at` เป็น `2026-10-26 00:56:27 UTC` ไม่ใช้เป็นตัวเชื่อมต่อปัจจุบันและไม่เปลี่ยนให้เป็นโทเค็นถาวร
- Client เดิมหมายเลข 1 และ 2 คงเดิม ไม่ถูกหมุนหรือเพิกถอน
- การทดสอบจากหน้า Student API ของ Reflection สำเร็จ: ตรวจพบภาคเรียน **2/2569** และนักศึกษา **631 คน** จำนวนนี้เป็นผล ณ วันตรวจและเปลี่ยนได้เมื่อข้อมูลต้นทางเปลี่ยน
- รอบแรกทดสอบผ่านแต่บันทึกไม่ได้ เพราะฐานข้อมูล Reflection ไม่มีตาราง `student_api_connections` และไม่มี migration ledger ของตารางนี้ ติดตั้ง migration เฉพาะตารางนี้สำเร็จแล้วผ่าน PHP 8.4 ใน Plesk Run Now โดยไม่สร้างงานตามเวลาเพิ่ม (งานเดิมยัง 8 รายการ)
- บันทึก client 4 สำเร็จ หน้าเว็บแจ้ง **บันทึกการเชื่อมต่อแล้ว** และล้างช่อง token จากนั้น reload หน้าแล้วทดสอบโดยไม่กรอกค่าใหม่สำเร็จจากโทเค็นที่เก็บเข้ารหัส พบภาคเรียน 2/2569 จำนวน 631 คน SQL ตรวจ client 4 มี `expires_at = NULL`, `revoked_at = NULL` และ `last_used_at` มีค่า ไม่ต้อง clear config cache ในการติดตั้งรอบนี้เพราะ request จริงโหลด config ใหม่และทำงานแล้ว

ไม่มี token, token hash, รหัสผ่าน หรือข้อมูลรายตัวนักศึกษาในชุดเอกสารนี้ ให้เก็บ plaintext token เฉพาะในช่องตั้งค่า Student API ของ Reflection ซึ่งเข้ารหัสในฐานข้อมูล และเก็บสำเนาลับตามนโยบายของหน่วยงาน

## ไฟล์และตำแหน่งติดตั้ง

| ไฟล์ | วัตถุประสงค์ |
| --- | --- |
| `ReflectionTeacherController.php` | ติดตั้งที่ `httpdocs/SDL_school/laravel-app/app/Http/Controllers/Api/Integrations/ReflectionTeacherController.php` |
| `AuthenticateStudentDataClient.php` | middleware สำหรับ SDL production รุ่นที่ตรวจแล้ว เพิ่มเฉพาะการยกเว้นวันหมดอายุจาก allowlist; ตรวจ diff และสำรองฉบับจริงก่อนติดตั้ง |
| `reflection_integration.php` | ติดตั้งที่ `httpdocs/SDL_school/laravel-app/config/reflection_integration.php` อนุญาต non-expiring client ID 4 เท่านั้น ไม่ใช่ secret และเป็นค่าของ deployment นี้ |
| `route-snippet.txt` | เพิ่ม GET route ภายในกลุ่ม `integrations/student-data` เดิมใน `routes/api.php` |
| `create-client.sql.template` | SQL template สำหรับตรวจสอบขั้นตอนสร้าง client; มี placeholders เท่านั้น ไม่ใช่ migration และห้ามรันซ้ำระหว่าง deploy |
| `revoke-previous-client.sql.template` | เพิกถอนเฉพาะ client ทดลองรอบแรกหมายเลข 3 หลังบันทึกและตรวจ client 4 แล้ว ไม่แตะ client 1/2/4 |
| `ReflectionTeacherPatchTest.php` | ทดสอบใน SDL app บนเครื่องพัฒนาและ SQLite `:memory:` เท่านั้น ห้าม deploy เข้าเว็บจริง |
| `ReflectionClientExpiryPatchTest.php` | ทดสอบ middleware ทั้งฉบับกับ allowlist ว่าง/เฉพาะ 4 และเงื่อนไข auth เดิม ด้วย SQLite `:memory:` เท่านั้น |

สำรองไฟล์ routes เดิมไว้นอก public URL ก่อนแก้ คง middleware `student-data-client`, `role:admin,super_admin` และ `throttle:student-data-api` ทุกตัว หากมี route `/teachers` อยู่แล้วให้ตรวจ implementation เดิมก่อน ไม่เพิ่ม route ซ้ำหรือทับด้วย patch นี้อัตโนมัติ หากใช้ route cache ให้ refresh ด้วยวิธี deployment ของ SDL ที่ใช้อยู่ การเพิ่ม endpoint นี้ไม่ต้อง build frontend หรือ migrate schema

Middleware รุ่นเดิมต้องตั้ง authenticated user และ request attribute `district_id` ให้ตรงกับ token Controller อ่าน token ที่เก็บจริงและตรวจ `user_id`/`district_id` ซ้ำ จึงไม่ต้องพึ่ง attribute `student_api_client` ของ middleware รุ่นใหม่ หาก hosting ไม่ส่งต่อ Authorization ต้องมีการรับ `X-Student-Data-Token` ใน middleware ด้วย การรองรับ header ที่ controller เพียงจุดเดียวไม่ช่วยเมื่อถูกปฏิเสธก่อนเข้า controller

ฝั่ง Reflection ต้องมีตาราง `student_api_connections` ด้วย หากทดสอบ API ผ่านแต่บันทึกไม่ได้ ให้ตรวจ migration และตารางจริงก่อนแก้ token คำสั่งสำหรับติดตั้งเฉพาะตารางนี้จากโฟลเดอร์ `sena_reflection/laravel` คือ:

```sh
php artisan migrate --path=database/migrations/2026_10_25_140148_create_student_api_connections_table.php --force --no-interaction
```

ไม่ใช้ `migrate:fresh` และไม่สร้างตารางระหว่างบันทึกข้อมูล หากตารางมีอยู่แล้วแต่ไม่มี ledger ให้หยุดตรวจ schema/ประวัติ deployment ก่อนรันซ้ำ การติดตั้งตารางนี้ไม่เท่ากับติดตั้งหรือสั่งเก็บข้อมูลภาคเรียน

## รูปแบบข้อมูลและขอบเขต

Response มี `data` เป็นรายการที่แต่ละคนมีเพียง `username`, `name`, `assigned_groups` และ `meta.pagination` มี `current_page`, `per_page`, `total`, `last_page` รองรับครั้งละ 1–100 คนตาม contract ของ `StudentApiService` ใน Reflection

อ่านเฉพาะครูที่ยังเปิดใช้งานในอำเภอของ token ไม่ส่งอีเมล รหัสผ่าน hash หรือข้อมูลยืนยันตัวตน ตรวจ token ที่ยังไม่หมดอายุ/ถูกเพิกถอน และกำหนดสิทธิ์ทั้งสองรายการโดยตรง ไม่เพิ่ม API สำหรับตรวจรหัสผ่านครู ไม่ให้ caller เปลี่ยนอำเภอผ่าน query/header และปฏิเสธ browser Origin ต้องเรียกผ่าน backend ของ Reflection คง `Cache-Control: private, no-store`

ค่า `expires_at = NULL` หมายถึงไม่มีวันหมดอายุอัตโนมัติ Controller รองรับค่านี้ และ SDL production ติดตั้ง allowlist ใน middleware สำหรับ client 4 แล้ว จึงไม่ใช้การแก้เป็น `whereNull(...)->orWhere(...)` แบบเปิดทุก client เพราะจะทำให้โทเค็นไม่มีวันหมดอายุของระบบอื่นที่ยังถูกปฏิเสธเริ่มเข้าถึงได้ด้วย Client 4 ถูกตรวจว่ามีค่านี้ในฐานข้อมูลจริงและใช้งานจากค่าเข้ารหัสใน Reflection ได้แล้ว ส่วน SQL template ใช้ค่านี้ตามคำขอล่าสุด

### ติดตั้งการยกเว้นวันหมดอายุเฉพาะ Reflection

สำรอง middleware เดิมไว้นอก public URL แล้วเทียบ diff กับ artifact ฉบับนี้ Production ที่ตรวจตรงกับโค้ดต้นแบบ ยกเว้น predicate วันหมดอายุและไม่มี request attribute `student_api_client`; artifact คงพฤติกรรมสองส่วนนี้ตาม production และเพิ่มเพียง allowlist สำหรับวันหมดอายุ โดยไม่ลด Origin/scope checks, authentication, rate limit, role หรือการตรวจบัญชี/อำเภอ

ติดตั้ง `AuthenticateStudentDataClient.php` ที่ `app/Http/Middleware/AuthenticateStudentDataClient.php` ของ SDL และ config ที่ `config/reflection_integration.php` จากนั้น refresh config cache ด้วยขั้นตอนเดิมของ SDL หากมี cache อยู่ เช่น `php artisan config:clear` ห้ามแก้ `.env` หรือเผย credentials เพื่อขั้นตอนนี้ ค่า config ของ deployment นี้คือ `reflection_integration.non_expiring_client_ids = [4]` หาก config ไม่มีอยู่/ว่าง/ผิดชนิดจะไม่ยกเว้นให้ client ใด ID ต้องเป็น integer บวก; string หรือ float ถูกละทิ้ง รอบติดตั้งจริงตรวจ middleware/config ตรง SHA-256 ของ artifact และ request จริงผ่านแล้ว จึงไม่ต้อง clear cache เพิ่มในรอบนี้

เงื่อนไขรับ token ยังคงต้องไม่ถูกเพิกถอน และผ่านอย่างใดอย่างหนึ่ง: วันหมดอายุเป็นอนาคต หรือไม่มีวันหมดอายุพร้อม ID อยู่ใน allowlist Client ที่มีวันหมดอายุผ่านไปแล้วหรือเท่ากับเวลาปัจจุบันยังถูกปฏิเสธแม้อยู่ใน allowlist ตรวจ config/query ให้ client 2 ที่ไม่ได้อนุมัติไม่เข้าเงื่อนไขยกเว้น โดยไม่ส่งหรือเปิดดู plaintext ของมัน ชุดทดสอบจำลองยืนยันว่า ID 2 ยังถูกปฏิเสธหลังเปิดเฉพาะ ID 4 ไม่เพิ่ม ID อื่นระหว่างแก้ปัญหา

หลังติดตั้งให้ทดสอบผ่าน Reflection บันทึกค่าและเปิดหน้าใหม่จากค่าที่เก็บไว้ก่อนเพิกถอน client 3 การ rollback ส่วนนี้ให้คืน middleware/config/cache จากสำรอง ซึ่งจะปิดการยกเว้นของ client 4 โดยไม่หมุนโทเค็นของระบบอื่น

## ออก token ใหม่และหมุนอย่างไม่กระทบระบบอื่น

Token เดิมเก็บเป็น SHA-256 จึงเปิดดู plaintext คืนไม่ได้ คำสั่ง SDL `system:create-student-api-token` แสดง plaintext เพียงครั้งเดียว และตัวเลือก `--sena-care` ให้สิทธิ์ตรวจรหัสผ่านครูเพิ่มด้วย สำหรับ Reflection ให้ผู้ดูแลออก client ใหม่ที่มีเพียงสองสิทธิ์ด้านบน โดยใช้ API/CLI ที่รองรับหรือ prepared insert ตาม template หลังตรวจบัญชีผู้ออกและอำเภอจริง

สร้างชื่อ client ใหม่สำหรับการหมุนแต่ละครั้ง ไม่ใช้ `--replace` กับ client ที่ระบบอื่นใช้อยู่ ก่อนเพิกถอนตัวเก่าให้กรอก token ใหม่ใน Reflection ทดสอบและบันทึกจนสำเร็จ เปิดหน้าตั้งค่าอีกครั้งเพื่อตรวจว่าแหล่งข้อมูลเป็น SDL School แล้วค่อยเพิกถอนเฉพาะหมายเลขเก่าที่ตั้งใจเลิกใช้ ห้ามใส่ plaintext/hash ใน Git, แชต, URL หรือ SQL template นี้

## เพิกถอนและ rollback

เมื่อจำเป็นต้องหยุด **การเชื่อมต่อ Reflection หมายเลข 4** ให้ผู้ดูแล SDL ตรวจชื่อและอำเภอของ client อีกครั้ง แล้วรันใน `SDL_school/laravel-app` ด้วย PHP CLI ที่ใช้กับเว็บนี้:

```sh
php artisan system:revoke-student-api-token 4
```

คำสั่งนี้ทำให้ Reflection ใช้ token นั้นไม่ได้ทันที ตรวจหมายเลขจริงก่อนรัน และไม่ใช้กับ clients 1 หรือ 2 ที่เป็นระบบอื่น ส่วน client 3 จากรอบแรกถูกเพิกถอนแล้วหลังบันทึก client 4 และทดสอบจากค่าที่เก็บสำเร็จ Template ที่เก็บไว้เป็นหลักฐานขั้นตอนที่ตรวจ ID/ชื่อ/อำเภอ ไม่ใช่คำสั่งที่ต้องรันซ้ำระหว่าง deploy เมื่อต้อง rollback endpoint ให้ลบเฉพาะ route และ controller ที่เพิ่ม พร้อม refresh route cache หากใช้อยู่ ไม่แก้บัญชีครู/นักศึกษา ตาราง หรือข้อมูลภาคเรียน

## ผลทดสอบและวิธีทวนซ้ำ

PHP lint และ Pint ผ่าน PHPUnit 12 tests / 34 assertions ผ่านใน SDL app กับ SQLite `:memory:` ครอบคลุม middleware รุ่นเก่า, header fallback, response allowlist, สิทธิ์และอำเภอ, token ผิด/หมดอายุ/เพิกถอน, token ไม่มีวันหมดอายุที่ยังจำกัดอำเภอและเพิกถอนได้, บัญชี/อำเภอปิดใช้งาน, pagination, Origin/การพยายามเปลี่ยน scope และ HTTP method ที่แก้ข้อมูล

Middleware patch เพิ่มอีก 8 tests / 24 assertions ผ่านกับฉบับ artifact ทั้งไฟล์ ครอบคลุม default ว่าง, เปิดเฉพาะ ID 4 โดยไม่เปิด ID 2, การเพิกถอน/เวลาผ่านไป, วันหมดอายุเดิม, scope/บัญชี/อำเภอ/สิทธิ์/Origin และ config ผิดชนิด รันรวมสองไฟล์ผ่าน **20 tests / 58 assertions** ไม่มีการทดสอบกับฐานข้อมูลจริง

รันจาก **SDL app บนเครื่องพัฒนา** หลังตรวจว่าไม่มี config cache ชี้ฐานจริง ใช้ path ของ checkout Reflection ในเครื่อง:

```sh
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' \
  vendor/bin/phpunit /path/to/reflection/docs/deployment/sdl-reflection-teachers
```

`Tests\TestCase` ของ SDL บังคับ testing + SQLite `:memory:` ก่อน bootstrap และปฏิเสธ config cache/ฐานอื่น ไม่รันทดสอบนี้กับ production

Controller SHA-256: `200afa430cc76de10e28819fd2cf5b7fd8b40c59d994eae721e6825966e48dbc`

Middleware SHA-256: `9bfc9ffd8a681b0d37340b6532a2c3bcdd775e5b3d3af66bc1c062102022ad85`

Config SHA-256: `ef376b494fa46d99183f349a76c10ef5ee5898b1cae42483274d6e8f7f05cfa6`
