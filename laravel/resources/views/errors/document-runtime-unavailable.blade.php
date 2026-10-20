<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>ระบบสร้างเอกสารยังไม่พร้อม</title>
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f5f8f7;color:#183e39;font:16px/1.8 system-ui,sans-serif}main{max-width:560px;padding:32px;background:white;border:1px solid #dce6e1;border-radius:16px}small{color:#64766e}h1{font-size:24px;line-height:1.5;margin:8px 0 16px}p{color:#4c6057}a{display:inline-flex;align-items:center;min-height:44px;margin-top:12px;padding:8px 20px;background:#143a37;color:white;border-radius:8px;text-decoration:none}a:focus-visible{outline:3px solid #49a98c;outline-offset:4px}
    </style>
</head>
<body>
<main aria-labelledby="document-error-title">
    <small>503 · ระบบสร้างเอกสาร</small>
    <h1 id="document-error-title">ยังสร้างเอกสารไม่ได้ในขณะนี้</h1>
    <p role="status">{{ $message }}</p>
    <a href="{{ route('dashboard') }}">กลับหน้าหลัก</a>
</main>
</body>
</html>
