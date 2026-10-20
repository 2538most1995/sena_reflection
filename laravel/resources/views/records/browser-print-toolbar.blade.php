<style>
    @media screen {
        .browser-print-toolbar{position:sticky;top:0;z-index:2000;max-width:794px;box-sizing:border-box;margin:0 auto 24px;padding:18px 22px;border:1px solid #cbdad4;border-radius:12px;background:#f6faf8;color:#173e35;font:15px/1.7 system-ui,sans-serif;box-shadow:0 4px 20px #163b2b12}
        .browser-print-toolbar h1{margin:0 0 8px;font:600 20px/1.5 system-ui,sans-serif}.browser-print-toolbar p{margin:6px 0}.browser-print-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}.browser-print-actions button,.browser-print-actions a{display:inline-flex;align-items:center;min-height:44px;padding:9px 18px;border-radius:8px;border:1px solid #a5bfb4;background:white;color:#173e35;font:600 15px/1.5 system-ui,sans-serif;text-decoration:none;cursor:pointer}.browser-print-actions button{background:#143a37;color:white}.browser-print-actions button:disabled{opacity:.55;cursor:wait}.browser-print-actions :focus-visible{outline:3px solid #369b7e;outline-offset:3px}
    }
    @media print{.browser-print-toolbar{display:none!important}html{background:white!important;--html-preview-scale:1!important}html.html-preview-direct body{display:block!important;margin:0!important;padding:0!important}html.html-preview-direct .page{zoom:1!important;transform:none!important;margin:0!important}.page::before,.page::after{display:none!important}}
</style>
<section class="browser-print-toolbar" aria-labelledby="browser-print-title">
    <h1 id="browser-print-title">พิมพ์เอกสาร / บันทึกเป็น PDF ผ่านเบราว์เซอร์</h1>
    <p>โหมดนี้ใช้เอกสารและรูปแบบที่บันทึกไว้ โดยให้เบราว์เซอร์สร้างไฟล์แทนเครื่องแม่ข่าย</p>
    <p>กดปุ่มด้านล่าง แล้วเลือก <strong>บันทึกเป็น PDF (Save as PDF)</strong> ใช้กระดาษ A4 ปิดหัวกระดาษและท้ายกระดาษ และตรวจจำนวนหน้าก่อนบันทึก</p>
    <p id="browser-print-status" role="status" aria-live="polite">กำลังเตรียมฟอนต์และรูปภาพ...</p>
    <div class="browser-print-actions"><button type="button" id="browser-print-button" disabled>พิมพ์ / บันทึกเป็น PDF</button><a href="{{ $recordUrl }}">กลับไปดูบันทึก</a></div>
</section>
<script>
(() => {
    const button = document.getElementById('browser-print-button');
    const status = document.getElementById('browser-print-status');
    const ready = async () => {
        try {
            if (document.fonts) await document.fonts.ready;
            const images = Array.from(document.images);
            await Promise.all(images.map(image => image.complete ? Promise.resolve() : new Promise(resolve => {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', resolve, { once: true });
            })));
            const failed = images.some(image => image.naturalWidth === 0);
            status.textContent = failed ? 'รูปภาพบางรายการโหลดไม่สำเร็จ กรุณาตรวจเอกสารก่อนพิมพ์' : 'เอกสารพร้อมแล้ว ตรวจตัวอย่างด้านล่างก่อนพิมพ์';
            button.disabled = false;
        } catch {
            status.textContent = 'เตรียมเอกสารไม่สำเร็จ กรุณาโหลดหน้านี้ใหม่';
        }
    };
    button.addEventListener('click', () => window.print());
    if (document.readyState === 'complete') ready();
    else window.addEventListener('load', ready, { once: true });
})();
</script>
