# SDL รายวิชาตามระดับชั้น

`/subjects` เดิมส่ง code/name/student_count เท่านั้น ทั้ง class-groups และ rosters ก็ไม่มีระดับชั้น จึงไม่ควรเดาจากรหัสรายวิชาหรือชื่อกลุ่ม

Patch `subject-levels.patch` เพิ่ม `levels: [1, 2, 3]` ตามระดับของนักศึกษาที่ลงทะเบียนวิชานั้นในภาคเรียนที่ร้องขอจริง วิชาร่วมมีหลายระดับได้ รักษาขอบเขต district/ผู้ใช้/term และการตรวจ token เดิม ไม่เพิ่ม endpoint หรือสิทธิ์ ไม่เปลี่ยน code/name/student_count และไม่เพิ่ม query

## ติดตั้งและตรวจ

ตรวจ context แล้วใช้ git apply --check /path/to/subject-levels.patch จาก root ของ checkout SDL ก่อน apply รัน PHP lint และ StudentDataOmrCatalogApiTest บนฐานจำลองแยก ตรวจวิชาประถม levels [1] วิชาร่วม levels [1,3] และการเลือกย้อนหลังต้องสะท้อนนักศึกษาที่ลงทะเบียนในเทอมนั้น จากนั้นติดตั้ง Reflection รุ่นที่อ่าน levels และเปลี่ยน catalog cache key เป็น v2

Rollback: reverse patch เฉพาะการเติม _levels/levels เท่านั้น Reflection ซ่อนวิชาที่ไม่ทราบระดับไว้และยังกรอกเองได้ ไม่เดาระดับและไม่แก้ร่างเก่า

ห้าม commit token, APP_KEY หรือข้อมูลนักศึกษาจริงลงใน patch นี้
