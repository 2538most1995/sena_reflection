@extends('layouts.app')
@section('title', 'รายละเอียดบันทึก • '.$record->subject)
@section('body')
@include('partials.navigation')
<main class="page narrow" id="main-content">
    <header class="page-heading record-heading">
        <div>
            <span class="eyebrow">บันทึกสัปดาห์ที่ {{ $record->week }}</span>
            <h1>{{ $record->subject }}</h1>
            <p>{{ $record->level }} • {{ $record->tambonRelation?->name ?? $record->tambon }}</p>
        </div>
        <div class="heading-actions">
            <a class="button button-outline" href="{{ route('dashboard') }}">กลับหน้าภาพรวม</a>
            <a class="button button-secondary" href="{{ route('records.edit', $record) }}">แก้ไข</a>
            <a class="button button-primary" href="{{ route('records.print', $record) }}" target="_blank" rel="noopener">พิมพ์ / เปิด PDF</a>
        </div>
    </header>

    <section class="panel record-detail">
        <div class="panel-heading"><div><span class="eyebrow">ข้อมูลการสอน</span><h2>รายละเอียดบันทึก</h2></div><span class="tag">{{ $record->level }}</span></div>
        <dl class="detail-grid">
            <div><dt>ปีการศึกษา / ภาคเรียน</dt><dd>{{ $record->year }} / {{ $record->semester }}</dd></div>
            <div><dt>สัปดาห์ / วันที่สอน</dt><dd>{{ $record->week }} / {{ $record->date?->format('d/m/Y') }}</dd></div>
            <div><dt>รายวิชา</dt><dd>{{ $record->subject }}</dd></div>
            <div><dt>รหัสวิชา</dt><dd>{{ $record->subject_code ?: '-' }}</dd></div>
            <div><dt>กลุ่มเรียน</dt><dd>{{ $record->class_name ?: '-' }}</dd></div>
            <div><dt>รหัสกลุ่ม</dt><dd>{{ $record->class_group_code ?: '-' }}</dd></div>
            <div><dt>ผู้เรียนทั้งหมด</dt><dd>{{ number_format($record->total) }} คน</dd></div>
            <div><dt>มาเรียน</dt><dd>{{ number_format($record->present) }} คน</dd></div>
        </dl>
        <div class="reflection-grid">
            <article><h3>ผลการจัดการเรียนรู้</h3><p>{{ $record->result ?: 'ไม่ได้ระบุ' }}</p></article>
            <article><h3>ปัญหา / อุปสรรค</h3><p>{{ $record->problem ?: 'ไม่ได้ระบุ' }}</p></article>
            <article><h3>ข้อเสนอแนะ</h3><p>{{ $record->suggestion ?: 'ไม่ได้ระบุ' }}</p></article>
        </div>
    </section>

    @if($record->images->isNotEmpty())
    <section class="panel">
        <div class="panel-heading"><div><span class="eyebrow">หลักฐานประกอบ</span><h2>รูปภาพการจัดกิจกรรม</h2></div><span class="result-count">{{ $record->images->count() }} รูป</span></div>
        <div class="record-gallery">
            @foreach($record->images as $image)
                <a href="{{ route('records.images.show', [$record, $image]) }}" target="_blank" rel="noopener"><img src="{{ route('records.images.show', [$record, $image]) }}" alt="รูปกิจกรรมที่ {{ $loop->iteration }}" loading="lazy"></a>
            @endforeach
        </div>
    </section>
    @endif

    <section class="panel">
        <div class="panel-heading"><div><span class="eyebrow">การเข้าเรียน</span><h2>รายชื่อนักศึกษา</h2></div><span class="result-count">{{ $record->attendance->count() }} คน</span></div>
        <div class="table-wrap"><table><thead><tr><th>รหัสนักศึกษา</th><th>ชื่อ-สกุล</th><th>กลุ่มเรียน</th><th>สถานะ</th><th>รูปแบบเรียน</th></tr></thead><tbody>
        @forelse($record->attendance as $student)
            <tr><td>{{ $student->student_id }}</td><td><b>{{ $student->full_name }}</b></td><td>{{ $student->class_name ?: $student->group_code ?: '-' }}</td><td><span class="attendance-status {{ $student->present ? 'present' : 'absent' }}">{{ $student->present ? 'มาเรียน' : 'ขาดเรียน' }}</span></td><td>{{ $student->learning_method ?: '-' }}</td></tr>
        @empty
            <tr><td colspan="5"><div class="empty-state"><b>ยังไม่มีรายชื่อการเข้าเรียน</b><span>สามารถเพิ่มหรือแก้ไขได้จากหน้าแก้ไขบันทึก</span></div></td></tr>
        @endforelse
        </tbody></table></div>
    </section>
</main>
@endsection
