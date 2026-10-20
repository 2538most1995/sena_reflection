@extends('layouts.app')
@section('title', 'ข้อมูลนักศึกษา')
@section('body')
@include('partials.navigation')
<main class="page" id="main-content">
    <header class="page-heading">
        <div><span class="eyebrow">ทะเบียนข้อมูล</span><h1>ข้อมูลนักศึกษา</h1><p>ค้นหานักศึกษา กลุ่มเรียน ระดับชั้น และครูผู้สอนตามสิทธิ์บัญชี</p></div>
        <span class="source-badge {{ $source === 'student_api' ? 'online' : 'fallback' }}">{{ $source === 'student_api' ? 'Student API พร้อมใช้งาน' : 'ข้อมูลสำรองในระบบ' }}</span>
    </header>

    @if($notice)<div class="alert alert-warning" role="status">{{ $notice }}</div>@endif
    @if($error)<div class="alert alert-error" role="alert">{{ $error }}</div>@endif

    <section class="panel">
        <div class="panel-heading"><div><span class="eyebrow">ค้นหาข้อมูล</span><h2>รายชื่อนักศึกษา</h2></div><span class="result-count">{{ number_format($items->total()) }} รายการ</span></div>
        <form class="filters student-filters">
            <label class="filter-search"><span>ค้นหา</span><input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ชื่อ รหัสนักศึกษา หรือกลุ่มเรียน"></label>
            <label><span>ระดับชั้น</span><select name="level"><option value="all">ทุกระดับ</option><option value="1" @selected(($filters['level'] ?? '') === '1')>ประถมศึกษา</option><option value="2" @selected(($filters['level'] ?? '') === '2')>มัธยมศึกษาตอนต้น</option><option value="3" @selected(($filters['level'] ?? '') === '3')>มัธยมศึกษาตอนปลาย</option></select></label>
            <div class="filter-actions">@if(array_filter($filters))<a href="{{ route('students') }}" class="button button-quiet">ล้างค่า</a>@endif<button class="button button-secondary">แสดงผล</button></div>
        </form>

        <div class="table-wrap"><table><thead><tr><th>รหัสนักศึกษา</th><th>ชื่อ-สกุล</th><th>ระดับชั้น</th><th>กลุ่มเรียน</th><th>รหัสกลุ่ม</th><th>ครูผู้สอน</th></tr></thead><tbody>
        @forelse($items as $item)
            <tr>
                <td>{{ $item['student_id'] ?: '-' }}</td>
                <td><b>{{ $item['full_name'] ?: 'ข้อมูลกลุ่มเรียน' }}</b></td>
                <td><span class="tag">{{ $item['level_name'] ?: $item['level'] ?: '-' }}</span></td>
                <td>{{ $item['class_name'] ?: '-' }}</td>
                <td>{{ $item['group_code'] ?: '-' }}</td>
                <td>{{ collect($item['teachers'] ?? [])->pluck('name')->filter()->join(', ') ?: ($item['teacher_name'] ?: '-') }}</td>
            </tr>
        @empty
            <tr><td colspan="6"><div class="empty-state"><b>ไม่พบข้อมูลนักศึกษา</b><span>ลองเปลี่ยนคำค้นหา ระดับชั้น หรือติดต่อผู้ดูแลระบบ</span></div></td></tr>
        @endforelse
        </tbody></table></div>
        {{ $items->links() }}
    </section>
</main>
@endsection
