<style id="browser-print-styles">
    @media screen {
        .browser-print-toolbar{position:sticky;top:0;z-index:2000;max-width:1123px;box-sizing:border-box;margin:0 auto 18px;padding:12px 16px;border:1px solid #cbdad4;border-radius:12px;background:#f6faf8;color:#173e35;font:14px/1.5 system-ui,sans-serif;box-shadow:0 4px 20px #163b2b12}
        .browser-print-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.browser-print-toolbar h1{margin:0;font:600 17px/1.4 system-ui,sans-serif}.browser-print-toolbar p{margin:4px 0 0;font-size:12px}.browser-print-actions{display:flex;flex-wrap:wrap;gap:8px}.browser-print-actions button,.browser-print-actions a{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:8px 14px;box-sizing:border-box;border-radius:8px;border:1px solid #a5bfb4;background:white;color:#173e35;font:600 14px/1.5 system-ui,sans-serif;text-decoration:none;cursor:pointer}.browser-print-actions button:first-child{background:#143a37;color:white}.browser-print-actions button:disabled{opacity:.5;cursor:wait}.browser-print-actions :focus-visible{outline:3px solid #369b7e;outline-offset:3px}.browser-print-toolbar details{margin-top:8px;font-size:12px;color:#49685d}.browser-print-toolbar summary{cursor:pointer}.browser-print-toolbar [hidden]{display:none!important}
        .image-free-canvas{background-image:none!important;outline:none!important}.image-free-canvas::before,.page::before,.page::after{display:none!important}.page{outline:none!important}.page.image-page{overflow:hidden!important}
        .preprint-image-resize-handle,.preprint-move-handle{display:none!important}.preprint-live-draggable,.preprint-live-editable{outline:none!important;box-shadow:none!important;background-color:transparent!important}
        @media(max-width:600px){.browser-print-toolbar{padding:10px 12px}.browser-print-actions{width:100%}.browser-print-actions button:first-child{flex:1}}
    }
    @media print{.browser-print-toolbar{display:none!important}html{background:white!important;--html-preview-scale:1!important}html.html-preview-direct body{display:block!important;margin:0!important;padding:0!important}html.html-preview-direct .page{zoom:1!important;transform:none!important;margin:0!important}.page::before,.page::after{display:none!important}}
    @media print{.image-free-canvas{background-image:none!important;outline:none!important}.image-free-canvas::before,.preprint-image-resize-handle,.preprint-move-handle{display:none!important}.preprint-live-draggable,.preprint-live-editable{outline:none!important;box-shadow:none!important;background-color:transparent!important}}
</style>
<section class="browser-print-toolbar" aria-labelledby="browser-print-title">
    <div class="browser-print-bar">
        <div><h1 id="browser-print-title">ตรวจเอกสารก่อนพิมพ์</h1><p id="browser-print-status" role="status" aria-live="polite">กำลังตรวจฟอนต์และรูปภาพ…</p></div>
        <div class="browser-print-actions"><button type="button" id="browser-print-button" disabled>พิมพ์ / บันทึก PDF</button><button type="button" id="browser-print-retry" hidden>ลองโหลดอีกครั้ง</button><a id="browser-print-edit" href="{{ $recordUrl }}" target="_top">กลับไปแก้ไข</a></div>
    </div>
    <details><summary>ตั้งค่าการพิมพ์</summary><p>เลือก A4 ขนาด 100% ปิดหัวกระดาษและท้ายกระดาษ แล้วเลือก Save as PDF หากต้องการไฟล์ PDF</p></details>
</section>
<script id="browser-print-script">
(() => {
    const button = document.getElementById('browser-print-button');
    const retry = document.getElementById('browser-print-retry');
    const status = document.getElementById('browser-print-status');
    let invalidated = false;
    window.addEventListener('message', event => {
        if (event.source !== window.parent || event.data?.source !== 'sena-print-invalidated') return;
        invalidated = true;
        button.disabled = true;
        retry.hidden = true;
        status.textContent = 'เอกสารถูกแก้ไข กรุณาอัปเดตตัวอย่างก่อนพิมพ์';
    });
    const fitPreview = () => {
        const page = document.querySelector('.page');
        if (!page) return;
        document.documentElement.classList.add('html-preview-direct');
        const pageWidth = Math.max(1, page.offsetWidth);
        const available = Math.max(1, window.innerWidth - (window.innerWidth <= 480 ? 8 : 24));
        document.documentElement.style.setProperty('--html-preview-scale', String(Math.min(1, available / pageWidth)));
    };
    const withTimeout = promise => new Promise((resolve, reject) => {
        const timer = window.setTimeout(() => reject(new Error('timeout')), 15000);
        promise.then(resolve, reject).finally(() => window.clearTimeout(timer));
    });
    const waitImage = image => withTimeout((async () => {
        if (!image.complete) await new Promise(resolve => {
            image.addEventListener('load', resolve, { once: true });
            image.addEventListener('error', resolve, { once: true });
        });
        if (!image.naturalWidth) throw new Error('image');
        if (image.decode) await image.decode();
    })());
    const ready = async () => {
        button.disabled = true;
        retry.hidden = true;
        try {
            if (document.fonts) await withTimeout(document.fonts.ready);
            const images = Array.from(document.images);
            const missing = document.querySelectorAll('.missing-image-note').length;
            const loaded = await Promise.allSettled(images.map(waitImage));
            if (invalidated) return;
            const failures = missing + loaded.filter(result => result.status === 'rejected').length;
            if (failures) {
                status.textContent = `โหลดรูปไม่สำเร็จ ${failures} จาก ${images.length + missing} รูป กรุณาโหลดใหม่ก่อนพิมพ์`;
                retry.hidden = false;
                return;
            }
            if (document.fonts && Array.from(document.fonts).some(font => font.status === 'error')) throw new Error('font');
            const pages = document.querySelectorAll('.page').length;
            status.textContent = `พร้อมพิมพ์ · ${pages} ส่วนเอกสาร · รูปภาพครบ ${images.length} รูป`;
            fitPreview();
            button.disabled = false;
        } catch {
            if (invalidated) return;
            status.textContent = 'โหลดฟอนต์ไม่สำเร็จ กรุณาโหลดใหม่ก่อนพิมพ์';
            retry.hidden = false;
        }
    };
    document.getElementById('browser-print-edit').addEventListener('click', event => {
        if (window.parent === window) return;
        event.preventDefault();
        window.parent.postMessage({ source: 'sena-print-edit-request' }, '*');
    });
    button.addEventListener('click', () => { if (!button.disabled) window.print(); });
    retry.addEventListener('click', () => window.location.reload());
    window.addEventListener('resize', fitPreview, { passive: true });
    if (document.readyState !== 'loading') ready();
    else window.addEventListener('DOMContentLoaded', ready, { once: true });
})();
</script>
