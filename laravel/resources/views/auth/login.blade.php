@extends('layouts.app')

@section('title', 'เข้าสู่ระบบ • SENA Reflection')
@section('body-class', 'auth-page')

@section('body')
<main class="login-shell">
    <section class="login-intro" aria-labelledby="product-name">
        <div class="login-brand"><span class="brand-mark">SR</span><span>SENA Reflection</span></div>
        <div class="intro-copy">
            <span class="eyebrow">ระบบบันทึกหลังการสอน</span>
            <h1 id="product-name">จัดการข้อมูลรายสัปดาห์<br>ให้ครบในที่เดียว</h1>
            <p>สำหรับศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอเสนา บันทึกครบ 3 ระดับชั้นและดาวน์โหลดเอกสารได้อย่างเป็นระบบ</p>
        </div>
        <p class="login-support">ข้อมูลถูกจำกัดตามสิทธิ์ระดับอำเภอและตำบล</p>
    </section>

    <section class="login-form-panel">
        <div class="login-card">
            <header>
                <span class="status-dot" aria-hidden="true"></span>
                <span class="eyebrow">เข้าสู่ระบบ</span>
                <h2>ยินดีต้อนรับกลับ</h2>
                <p>กรอกบัญชีผู้ใช้งานเพื่อเข้าสู่ระบบ</p>
            </header>

            <form method="post" action="{{ url('/login') }}" class="stack">
                @csrf
                <label>
                    <span>ชื่อผู้ใช้</span>
                    <input name="username" value="{{ old('username') }}" autocomplete="username" required autofocus placeholder="กรอกชื่อผู้ใช้">
                </label>
                <label>
                    <span>รหัสผ่าน</span>
                    <input type="password" name="password" autocomplete="current-password" required placeholder="กรอกรหัสผ่าน">
                </label>
                @error('username')
                    <div class="alert alert-error" role="alert">{{ $message }}</div>
                @enderror
                <button class="button button-primary button-block" type="submit">เข้าสู่ระบบ <span aria-hidden="true">→</span></button>
            </form>
        </div>
    </section>
</main>
@endsection
