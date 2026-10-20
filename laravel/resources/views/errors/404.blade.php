@extends('layouts.app')
@section('title', 'ไม่พบหน้าที่ต้องการ')
@section('body')
<main class="error-page"><section><span class="error-code">404</span><h1>ไม่พบหน้าที่ต้องการ</h1><p>ลิงก์อาจไม่ถูกต้อง หรือรายการนี้ถูกย้ายไปแล้ว</p><a class="button button-primary" href="{{ auth()->check() ? route('dashboard') : route('login') }}">กลับหน้าหลัก</a></section></main>
@endsection
