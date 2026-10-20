@extends('layouts.app')
@section('title', 'โปรไฟล์')
@section('body')
@include('partials.navigation')
<main class="page narrow" id="main-content">
    <header class="page-heading">
        <div><span class="eyebrow">บัญชีผู้ใช้งาน</span><h1>โปรไฟล์และรหัสผ่าน</h1><p>แก้ไขชื่อ รูปโปรไฟล์ หรือเปลี่ยนรหัสผ่านของคุณ</p></div>
    </header>
    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <section class="panel">
        <div class="panel-heading"><div><span class="eyebrow">ข้อมูลส่วนตัว</span><h2>รายละเอียดบัญชี</h2></div></div>
        <form method="post" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="edit-grid">
            @csrf @method('PUT')
            <label><span>ชื่อ-สกุล *</span><input name="name" value="{{ old('name', $user->name) }}" required></label>
            <label><span>รูปโปรไฟล์</span><input type="file" name="avatar" accept="image/jpeg,image/png,image/webp"></label>
            <label class="wide"><span>รหัสผ่านปัจจุบัน (กรอกเมื่อต้องการเปลี่ยนรหัสผ่าน)</span><input type="password" name="current_password" autocomplete="current-password"></label>
            <label><span>รหัสผ่านใหม่</span><input type="password" name="password" autocomplete="new-password"></label>
            <label><span>ยืนยันรหัสผ่านใหม่</span><input type="password" name="password_confirmation" autocomplete="new-password"></label>
            <div class="wide"><button class="button button-primary">บันทึกโปรไฟล์</button></div>
        </form>
    </section>
</main>
@endsection
