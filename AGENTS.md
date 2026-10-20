# ทีม AI วิเคราะห์อะไร

ไฟล์นี้แบ่งบทบาทของทีม AI สำหรับช่วยดูแลโปรเจกต์ `sena_reflection 4` ให้แต่ละ agent วิเคราะห์คนละมุม และส่งต่องานกันได้ชัดเจน

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
- ตรวจ layout เอกสาร ลายเซ็น รูปภาพ attendance และ preview
- ดู dependency `html2canvas`, `jspdf` และ `pdfjs-dist`

คำถามหลัก:

- record นี้ควร render หน้าใดบ้าง?
- settings key ไหนมีผลกับตำแหน่ง/ฟอนต์/ลายเซ็น?
- preview HTML กับ download PDF ให้ผลตรงกันไหม?
- สิทธิ์เข้าถึง print token ถูกต้องไหม?

จุดที่ต้องระวัง:

- `print.php` มี mode หลายแบบ เช่น download, settings preview, html preview
- PDF download ใช้ browser สร้างจาก HTML preview; ไม่มี fallback ฝั่ง server
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
- ตรวจการเชื่อมต่อ `sena_care_school`
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
