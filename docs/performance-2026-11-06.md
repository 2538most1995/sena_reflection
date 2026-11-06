# รายงานปรับประสิทธิภาพ SENA Reflection — 6 พฤศจิกายน 2569

## ขอบเขตและสถานะ

แก้ระบบ Laravel ปัจจุบันใน `sena_reflection 4/laravel` โดยเทียบกับ commit `35270c49196ec318e3a8b11b7f0ea1d5c3402f0a` ไม่ใช่สำเนา `sena_reflection 4 2` และไม่ใช่หน้า PHP legacy ผลงานครอบคลุมปุ่มสร้างบันทึก, Dashboard, รายการร่าง, การเปิดแก้บันทึก, การยืนยันบันทึก และ metadata ที่ใช้ร่วมกันในเมนูนักศึกษา/ตัวเลือกการสร้างบันทึก

โค้ดและ production frontend build พร้อมส่ง Git รายงานนี้ไม่ได้ยืนยันว่า deploy ขึ้นเว็บจริงแล้ว ไม่ได้แก้ฐานข้อมูลจริง, uploads จริง, credentials, dependencies หรือ schema ไม่มี migration ใหม่ และไม่ได้ย้ายการบันทึกสำคัญไป background job

หลักฐานตัวเลขและตัวอย่าง EXPLAIN อยู่ใน [performance-2026-11-06-evidence.json](performance-2026-11-06-evidence.json) มีเฉพาะข้อมูลจำลอง ตัวเลขวัด และ SQL ที่มี placeholders ไม่มีรหัสผ่านหรือ token จริง

## สภาพแวดล้อมและข้อจำกัดการวัด

- PHP CLI 8.5.9, Laravel 13.29.0, inertia-laravel 3.3.1 ตาม dependency ที่ติดตั้ง
- MySQL binary ของ MAMP เป็น 8.0.40 แต่เชื่อมต่อฐานข้อมูล local ที่ตั้งค่าไว้ไม่ได้ จึงยังตรวจ VERSION/EXPLAIN/locks/deadlocks บน MySQL ที่ให้บริการจริงไม่ได้ การลองเปิด MySQL ใหม่ใน temporary directory แยกต่างหากล้มเหลวก่อนเริ่มให้บริการ ไม่เกี่ยวกับฐานข้อมูลเดิม
- Before/After ใช้ข้อมูลจำลองและ SQLite in-memory ของ PHPUnit ส่วน browser tests มี SQLite และ storage แยกเฉพาะชุดทดสอบ ไม่ใช้ข้อมูลจริงและไม่เรียก migration กับฐานข้อมูลจริง
- HTTP kernel หมายถึงเรียกผ่าน Laravel test client รวม middleware/controller/serialization แต่ไม่รวม TCP/TLS, PHP-FPM, reverse proxy หรือ latency ของผู้ใช้งาน
- Service benchmark วัดเฉพาะ service ที่ระบุ ไม่ใช่เวลาคลิกจนแสดงผลทั้งหน้า ใช้ median ของจำนวนรอบในตาราง ไม่ใช้การผ่าน time threshold เป็น regression assertion
- Query time เป็นค่าจาก Laravel query log; ไม่ใช่เวลาประมวลผล MySQL จริง ส่วน memory เป็น PHP CLI test process หน่วย MiB และได้รับอิทธิพลจาก allocator/test runner จึงใช้วางจำนวน PHP-FPM workers ไม่ได้
- มีเพียง safe GET หน้า login จริง 5 ครั้ง: median TTFB 261.71 ms และ total 262.31 ms รวม network/TLS ไม่ใช่ตัวเลข backend หลัง deploy และไม่ใช่ load test

## ปัญหาก่อนแก้และลำดับผลกระทบ

| ลำดับ | สิ่งที่พบจากโค้ด/การวัด | ผลกระทบ |
| --- | --- | --- |
| สูง | `StudentApiService::configuration()` มี property memo แต่ไม่ได้เก็บค่าที่อ่าน ทำ schema lookup/อ่าน connection/ถอดรหัสซ้ำจาก metadata methods | เพิ่ม query ซ้ำในหลายเมนูที่ใช้ Student API; microbenchmark 30 method calls ทำ 240 queries |
| สูง | Dashboard ส่ง payload ร่างทั้งชุด รวม attendance และ narrative ที่ไม่ได้แสดงใน card และเรียก wildcard validation เพื่อดูสถานะ | ตัวอย่าง 6 ร่างส่งข้อมูลประมาณ 791 KB และใช้ CPU กับข้อมูลรายชื่อที่ไม่จำเป็น |
| สูง | รายงานเข้าเรียน hydrate Eloquent models จำนวนมากจาก joined rows และสร้าง Collection intermediates หลายรอบ | fixture 12,000 แถวที่มองเห็นใช้ประมาณ 307 ms และ peak เพิ่มราว 32 MiB |
| กลาง | เปิดแก้สัปดาห์เดิมอ่าน record/attendance/images/layout ซ้ำสำหรับ payload, fingerprint และนำเข้ารูป | 26 queries ต่อ service operation ใน fixture |
| กลาง | Finalize สร้าง full preview snapshot ทุกระดับ ทั้งที่ต้องใช้เพียง document settings | 115 queries และ draft SELECT 8 ครั้งใน fixture 3 ระดับ |
| กลาง | Dashboard aggregate/count แยกกันซ้ำก่อน pagination | อ่านขอบเขต record ซ้ำโดยไม่จำเป็น |
| ด้านใช้งาน | หน้า Dashboard มีลิงก์สร้างบันทึกทั้ง header และหัวหน้า | ผู้ใช้สับสนว่าปุ่มทำงานต่างกันหรือไม่ |

## Before / After ที่วัดได้

ตัวเลขเป็น median จาก fixture เดียวกันบนเครื่องเดียวกัน; ข้อมูลของทุกช่วงวัดอยู่ในไฟล์ evidence

| งานและขนาดข้อมูล | รอบ | เวลา ms ก่อน → หลัง | Queries ก่อน → หลัง | DB ms ก่อน → หลัง |
| --- | ---: | ---: | ---: | ---: |
| Dashboard tambon, 3,600 records/18,000 attendance/3,600 images พร้อม 6 ร่าง | 7 หลัง warm-up | 14.096 → 11.429 | 11 → 10 | 4.69 → 4.68 |
| Dashboard district, fixture เดียวกัน | 7 หลัง warm-up | 15.202 → 12.106 | 12 → 11 | 5.57 → 5.31 |
| รายงานเข้าเรียน HTTP kernel, 180 records/18,000 attendance, มองเห็น 12,000 แถว | 5 | 306.603 → 57.418 | 15 → 3 | 18.52 → 6.98 |
| Finalize service, 3 ระดับ × 200 นักศึกษา, 250 settings | 3 | 122.502 → 111.071 | 115 → 100 | 4.55 → 3.90 |
| เปิดร่างแก้ไข service, 3 ระดับ × 200 นักศึกษา และ 12 รูป | 3 | 22.240 → 18.663 | 26 → 21 | 1.18 → 0.74 |
| Source configuration microbenchmark, metadata 30 method calls ต่อ sample | 7 | 8.054 → 0.256 | 240 → 2 | 2.15 → 0.02 |

240 → 2 เป็นผลของ microbenchmark ที่เรียก metadata ซ้ำ 30 ครั้ง ไม่ใช่จำนวน query ของ endpoint เดียว และไม่ได้หมายความว่า network SDL เร็วขึ้นเท่านี้

| ตัวชี้วัดอื่น | ก่อน | หลัง | ความหมาย |
| --- | ---: | ---: | --- |
| Dashboard tambon response bytes | 790,837 | 19,189 | ลดประมาณ 97.6% |
| Dashboard district response bytes | 790,939 | 19,291 | ลดประมาณ 97.6% |
| Dashboard test-process median peak MiB, tambon / district | 70.5 / 72.5 | 66.5 / 66.5 | coarse allocator peak; ไม่ใช่ FPM ต่อผู้ใช้ |
| รายงานเข้าเรียน warm peak delta MiB | 32.251 | 11.078 | ลด intermediates/model hydration |
| รายงานเข้าเรียน response bytes | 1,532,937 | 1,532,937 | คง response contract และรายละเอียดเดิม |
| Finalize draft SELECT | 8 | 5 | ตัดการอ่านร่างซ้ำจาก preview ที่ไม่ใช้ |
| Finalize median process peak MiB | 58.5 | 58.5 | ไม่พบ memory reduction ในการวัดนี้ |
| เปิดแก้ median process peak MiB | 61.516 | 61.516 | ไม่พบ memory reduction ในการวัดนี้ |

รายงานเข้าเรียนมี report SHA-256 ก่อนและหลังตรงกัน: `7a73c0b482d2b3955c99a8e5565ffcd9772d90dd2d2e3abf3986706c74190a2a` ตรวจทั้ง summary, ordering, date string, class/student statistics และ selected timeline

## ไฟล์ที่แก้ วิธีปรับปรุง ผลทดสอบ และความเสี่ยง

| ไฟล์ | วิธีแก้และเหตุผล | ทดสอบ / ความเสี่ยง |
| --- | --- | --- |
| `laravel/resources/js/pages/dashboard.tsx` | ตัดปุ่มสร้างบันทึกที่ซ้ำในหัวเนื้อหา ใช้ปุ่มส่วน header เดิมเพียงปุ่มเดียว | Browser ตรวจ desktop/mobile 390px, คลิกไป editor หนึ่ง request และไม่ยิง record-options ซ้ำ; ความเสี่ยงต่ำ |
| `laravel/app/Http/Controllers/DashboardController.php` | รวม aggregate ตามปี/ภาค/สัปดาห์ แล้ว reuse total ใน paginate; ใช้ compact draft summaries | ตรวจยอดทั้ง tambon/district, 30 records ต่อหน้า, จำนวนหน้า, unique students, term/filter และ no external HTTP; SQL aggregate ควรวัดแผน MySQL จริงอีกครั้ง |
| `laravel/app/Services/DraftService.php` — summaries | รองรับ term filters ก่อน limit; ส่งเฉพาะข้อมูล card, ตรวจ readiness แบบ normalize/ตรวจเงื่อนไขตรงโดยไม่ขยาย wildcard validator ทั้ง roster | matrix 15 กรณีเทียบ validator เดิม รวม duplicate numeric IDs, รูปแบบชื่อ/วิธีเรียน/group; การบันทึกและ finalize ยังใช้ validation เดิม ไม่ใช้ readiness เป็นทางข้าม validation |
| `laravel/app/Services/DraftService.php` — เปิดแก้ | อ่าน source collections ครั้งเดียว reuse payload/fingerprint/images ภายใต้ transaction เดิม | fixture ตรวจ fingerprint, 600 attendance, 12 images, settings เดิม; ไม่เปลี่ยนเจ้าของร่างหรือสิทธิ์ source |
| `laravel/app/Services/DraftService.php` และ `FinalizeTeachingSubmission.php` | เพิ่ม settings-only path ภายใน logic เดียวกับ full snapshot หลีกเลี่ยงสร้าง preview record/attendance/teacher ที่ไม่ใช้ | ตรวจ settings เท่ากับ preview, reject เจ้าของร่างอื่น, totals 600/450present/3records, lock version/idempotency และ rollback tests เดิม; ไม่ลด transaction/row locks หรือย้าย critical writes ไป job |
| `laravel/app/Services/StudentApiService.php` | เติม memo assignment ของ configuration, reuse latest term ที่อ่านครั้งเดียว และ invalidate เมื่อ token/term เปลี่ยน ใช้ request identity ป้องกัน service ค้างข้าม request | metadata bounded queries, token rotation/new service/new request และ term update; ไม่มี shared credential singleton หรือ response cache ใหม่ และไม่เปลี่ยน scope/filter HTTP ของ SDL |
| `laravel/app/Services/AttendanceReportService.php` | Query Builder cursor แทน Eloquent hydration, สรุปข้อมูลหนึ่งรอบ, reuse normalized date ต่อวันที่ซ้ำ; รักษา loose numeric-string count ของ legacy | hash ก่อน/หลังตรงกัน, scope/filter/ordering/timeline และ `001`/`1` regression; PDO MySQL อาจยัง buffer raw result จึงไม่อ้างว่า memory เป็น O(1) และ response ยังใหญ่ตาม contract |
| `laravel/tests/Feature/ActiveAcademicTermTest.php` | ตรวจ compact draft scope/id และยืนยันไม่ส่ง full payload แทน assertion contract เดิม | ยังตรวจ term และ draft privacy ครบ |
| `laravel/tests/Feature/{DashboardPerformanceTest,DashboardDraftSummaryTest,StudentSourcePerformanceTest,TeachingSubmissionPerformanceTest,AttendanceReportPerformanceTest}.php` | เพิ่ม regression ที่ตรวจ query bounds/ข้อมูล/สิทธิ์ ไม่ผูก CI กับเวลา wall clock | ใช้ isolated testing DB, fake storage/HTTP; performance fixtures ทำให้ test runner ใช้ memory มากขึ้น |
| `laravel/tests/Browser/teaching-workflow.spec.ts` | เพิ่ม case ปุ่มเดียวและตรวจ request ตอนเปิดสร้าง | ผ่านพร้อม workflow เดิมรวม 8 cases |
| `laravel/public/build/` | build production ใหม่ทั้งชุดสำหรับ hosting ใต้ `/sena_reflection` | manifest/import/fonts/source fingerprint ผ่าน 5 checks; ต้อง deploy build ทั้งชุดพร้อมโค้ด |

## ผลตรวจฐานข้อมูล, routes, cache, frontend และ server

- ตรวจเส้นทาง runtime จาก `routes/web.php`, workspace/documents/student_api/exports/semester-backups และ controller/service/model ที่เกี่ยวข้อง พร้อมชุดทดสอบเดิมที่ตรวจ auth, document revisions, exports, backups และ integration ไม่เปลี่ยน middleware หรือ role policy
- Query หลักยังใช้ bindings/Eloquent visibility scope เดิม; ไม่เพิ่ม query ใน loop attendance และไม่เปลี่ยนชื่อ/วิธีนับผู้มาเรียนหรือ default term
- ตรวจ migrations เดิมพบ index ตาม tambon/year/semester/week, creator/year/semester/week และ covering indexes ใน `2026_08_30_000400_add_dashboard_covering_indexes.php`; ไม่เพิ่ม index ซ้ำโดยไม่มี MySQL EXPLAIN จริง
- SQLite EXPLAIN ใน evidence แสดง lookup ด้วย `idx_records_scope_week`, `idx_records_period_date` และ `idx_record_images_record_id`; บางรายการยังใช้ temporary ORDER BY และ correlated image count แผนนี้ยืนยันได้เฉพาะ fixture ไม่ใช้แทน MySQL production plan
- Query count ลดลงเกิดจากการรวม queries, reuse data และ configuration memo; ไม่อ้างว่าแก้ N+1 ทั้งระบบจากหลักฐานบางเส้นทาง
- Local configuration: APP_ENV local, APP_DEBUG true, file cache/session, sync queue; config/route cache ยังไม่ได้สร้าง ซึ่งเหมาะกับบริบทพัฒนา ไม่ใช่หลักฐานว่า production ตั้งค่าเหมือนกัน ไม่แก้ env จริง
- CLI OPcache มี enable=1 แต่ enable_cli=0; ยังตรวจ FPM OPcache/worker count/memory/CPU/Disk I/O/connection saturation จริงไม่ได้
- ไม่เพิ่ม cache Dashboard/attendance จึงไม่มี TTL ใหม่ที่ทำให้ยอดหลังบันทึกค้าง ส่วน existing SDL caches ยังแยก source credentials/term/query และมี refresh/fallback เดิม
- Frontend build ยังมี warning chunks เอกสารบางชุดเกิน 500 KB; ไม่เปลี่ยน document/export engine หรือ dependency เพื่อไล่ลดขนาดโดยไม่มีผลวัดบน flow นั้น

## สิ่งที่ยังวัดหรือปรับบน production ไม่ได้

1. MySQL slow query log/EXPLAIN ANALYZE, row locks/deadlocks, wait time และ CPU/RAM/Disk/connection statistics ต้องตรวจบน staging ที่ขนาดข้อมูลใกล้จริงก่อนตัดสินใจเพิ่ม index หรือปรับ pool
2. Cold SDL requests ยังพึ่ง network, pagination, teachers และ subjects ตาม contract เดิม memo configuration ลด local queries แต่ไม่ลบ latency ของ external API การ refresh ที่ผู้ใช้ร้องขอยังต้องดึงข้อมูลสด
3. หน้านักศึกษายัง normalize/filter source data ก่อน pagination ภายในเพื่อรักษาการ scope/compatibility; ไม่ได้เปลี่ยนเป็น remote pagination ที่เสี่ยงทำให้ยอดหรือสิทธิ์ผิด หน้าเอกสารมี limit 60 และ versions 100 เดิม ส่วนรายงานเข้าเรียนยังส่งรายละเอียดทั้งหมดตามเดิม
4. ไม่ได้ load test ผู้ใช้พร้อมกันบน production จึงยังระบุจำนวนผู้ใช้ที่รองรับหรือรับประกันทุก API ต่ำกว่า 200 ms ไม่ได้ ผล SQLite/CLI ใช้ยืนยัน regression และทิศทางการแก้เท่านั้น
5. ไม่มี Before/After การ deploy จริงในรายงานนี้ ต้องวัด authenticated endpoints หลัง deploy จึงสรุปเวลาที่ผู้ใช้ได้รับจริงได้

## การทดสอบที่เสร็จแล้ว

- `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --no-progress`: 372 tests, ผ่าน 362, ข้าม 10, 5,006 assertions
- การรัน full suite ด้วย CLI limit 128 MiB ครั้งแรกชน memory limit ใน oversized archive fixture ของ `SemesterBackupPresentationTest`; รันซ้ำด้วย 512 MiB ผ่าน ไม่ได้เพิ่ม production memory limit หรือปิด assertion เพื่อให้ผ่าน
- `npm run test:frontend`: 112 ผ่าน
- `SENA_WORKFLOW_E2E=1 npx playwright test tests/Browser/teaching-workflow.spec.ts --reporter=line`: 8 ผ่าน (38.7 วินาที)
- Browser ครอบคลุม single create button desktop/mobile, ปฐมนิเทศ, refresh roster ล้มเหลวแล้วข้อมูลเดิมคงอยู่, ย้อนกลับเก็บร่าง, focus validation, ยกเลิกยืนยันแล้ว reload, บันทึก 2 ระดับครั้งเดียว และ district แก้ legacy พร้อม 4 รูป/ลองใหม่เมื่อเซิร์ฟเวอร์ผิดพลาด
- `npm run typecheck`, `vendor/bin/pint --dirty --format agent`, `php -l` ไฟล์ PHP ที่แก้: ผ่าน
- `npm run build:production`, `npm run check:production-build`: ผ่าน (production checks 5)
- `git diff --check`: ผ่าน

## ส่งขึ้นใช้งานและ rollback

Deploy โค้ดและ `laravel/public/build/` ทั้งชุดจาก commit เดียวกันตามขั้นตอนเดิมของระบบ ไม่ต้องเพิ่ม/แก้ schema สำหรับ patch นี้ ก่อนทำงานใดที่กระทบฐานข้อมูลจริงให้มี backup และตรวจ restore ได้ตามคำขอผู้ใช้

ฝั่ง production ควรตรวจ APP_ENV=production และ APP_DEBUG=false, สร้าง config/route/view caches ผ่าน `php artisan optimize` ใน deployment environment ที่ถูกต้อง และ reload workers ตามรุ่นที่ใช้งานจริง ตรวจ OPcache/FPM ให้รองรับจำนวน workers ตาม memory ที่วัดได้ ไม่ใช้ค่าจาก PHPUnit ไปกำหนดกำลังเครื่องโดยตรง อ้างอิง [Laravel 13 deployment](https://laravel.com/framework/docs/13.x/deployment)

เก็บ per-route response/query timing และ database connection/slow-query metrics โดยไม่ log bindings, token หรือข้อมูลส่วนตัว ใช้แผน query บน MySQL จริงเลือก index และวัด write overhead ก่อน deploy index อ้างอิง [Laravel 13 database monitoring](https://laravel.com/framework/docs/13.x/database)

พิจารณา Redis เมื่อทราบ availability, memory/eviction และขอบเขตข้อมูลจริงแล้ว ไม่เปลี่ยน cache driver ระหว่างงานนี้ ส่วน service memo จำกัด request ด้วย request identity; ไม่ผูกเป็น singleton ที่เก็บ credentials ข้าม request ตามหลัก lifecycle ของ [Laravel container](https://laravel.com/framework/docs/13.x/container)

Rollback ด้วย `git revert <commit ของ patch นี้>` แล้ว build/deploy โค้ดกับ assets ให้ตรงกัน หรือ redeploy release ก่อนหน้าทั้งชุด ไม่มี schema rollback และไม่ต้องลบข้อมูลบันทึกที่สร้างหลัง deploy หลีกเลี่ยงการ clear cache ทั้งระบบโดยไม่ทราบว่ามี locks/idempotency/review state อะไรใช้งานอยู่
