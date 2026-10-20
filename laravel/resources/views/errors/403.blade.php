@extends('layouts.app')
@section('title', 'ไม่มีสิทธิ์เข้าถึง')
@section('body')
<main class="error-page"><section><span class="error-code">403</span><h1>บัญชีนี้ไม่มีสิทธิ์เข้าถึง</h1><p>ข้อมูลบางส่วนเปิดให้เฉพาะผู้ดูแลระดับอำเภอ กรุณากลับไปยังหน้าหลัก</p><a class="button button-primary" href="{{ auth()->check() ? route('dashboard') : route('login') }}">กลับหน้าหลัก</a></section></main>
@endsection
