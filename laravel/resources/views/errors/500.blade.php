@extends('layouts.app')
@section('title', 'ระบบขัดข้องชั่วคราว')
@section('body')
<main class="error-page"><section><span class="error-code">500</span><h1>ระบบขัดข้องชั่วคราว</h1><p>ข้อมูลของคุณยังไม่สูญหาย กรุณาลองใหม่อีกครั้ง หากยังพบปัญหาให้ติดต่อผู้ดูแลระบบ</p><a class="button button-primary" href="{{ auth()->check() ? route('dashboard') : route('login') }}">ลองกลับหน้าหลัก</a></section></main>
@endsection
