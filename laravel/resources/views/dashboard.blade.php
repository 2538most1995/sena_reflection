@extends('layouts.app')

@section('title', 'ภาพรวม • SENA Reflection')

@section('body')
@include('partials.navigation')

<main class="app-main" id="main-content">
    <header class="page-heading">
        <div>
            <span class="eyebrow">ภาพรวมการดำเนินงาน</span>
            <h1>บันทึกหลังการสอน</h1>
            <p>ติดตามรายการ บันทึกข้อมูลครบ 3 ระดับ และส่งออกเอกสารจากหน้าเดียว</p>
        </div>
        <a href="#weekly-form" class="button button-primary">สร้างบันทึกรายสัปดาห์ <span aria-hidden="true">＋</span></a>
    </header>

    @if(session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-error" role="alert">
            <b>ยังบันทึกข้อมูลไม่ได้ กรุณาตรวจสอบอีกครั้ง</b>
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <section class="metrics" aria-label="สรุปข้อมูล">
        <article>
            <span class="metric-label">รายการที่แสดง</span>
            <strong>{{ number_format($summary->record_count ?? 0) }}</strong>
            <small>บันทึก</small>
        </article>
        <article>
            <span class="metric-label">จำนวนสัปดาห์</span>
            <strong>{{ number_format($summary->week_count ?? 0) }}</strong>
            <small>สัปดาห์</small>
        </article>
        <article>
            <span class="metric-label">ผู้เรียนในรายการ</span>
            <strong>{{ number_format($summary->total_students ?? 0) }}</strong>
            <small>คน</small>
        </article>
        <article class="metric-highlight">
            <span class="metric-label">อัตรามาเรียน</span>
            <strong>{{ ($summary->total_students ?? 0) > 0 ? number_format(($summary->total_present / $summary->total_students) * 100, 1) : '0.0' }}%</strong>
            <small>{{ number_format($summary->total_present ?? 0) }} คน จาก {{ number_format($summary->total_students ?? 0) }} คน</small>
        </article>
    </section>

    <section class="panel records-panel">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">รายการบันทึก</span>
                <h2>ค้นหาและจัดการข้อมูล</h2>
            </div>
            <span class="result-count">{{ number_format($records->total()) }} รายการ</span>
        </div>

        <form method="get" class="filters" aria-label="ตัวกรองรายการ">
            <label><span>ปีการศึกษา</span><input name="year" value="{{ $filters['year'] ?? '' }}" inputmode="numeric" placeholder="เช่น 2569"></label>
            <label><span>ภาคเรียน</span><select name="semester"><option value="">ทุกภาคเรียน</option>@foreach(['1','2','3'] as $term)<option value="{{ $term }}" @selected(($filters['semester'] ?? '') === $term)>ภาคเรียน {{ $term }}</option>@endforeach</select></label>
            <label><span>สัปดาห์</span><select name="week"><option value="">ทุกสัปดาห์</option>@foreach($availableWeeks as $week)<option value="{{ $week }}" @selected((string)($filters['week'] ?? '') === (string)$week)>สัปดาห์ {{ $week }}</option>@endforeach</select></label>
            @if(auth()->user()->isDistrict())
                <label><span>ตำบล</span><select name="tambon"><option value="">ทุกตำบล</option>@foreach($tambonOptions as $tambonOption)<option value="{{ $tambonOption->id }}" @selected(($filters['tambon'] ?? '') === $tambonOption->id)>{{ $tambonOption->name }}</option>@endforeach</select></label>
            @endif
            <label class="filter-search"><span>ค้นหารายวิชา</span><input name="search" value="{{ $filters['search'] ?? '' }}" placeholder="ชื่อหรือรหัสวิชา"></label>
            <div class="filter-actions">
                @if(array_filter($filters))<a href="{{ route('dashboard') }}" class="button button-quiet">ล้างค่า</a>@endif
                <button class="button button-secondary" type="submit">แสดงผล</button>
            </div>
        </form>

        <div class="export-bar">
            <p><b>ส่งออกข้อมูล</b><span>ไฟล์จะรวมเฉพาะข้อมูลที่บัญชีนี้มีสิทธิ์เข้าถึง</span></p>
            <div>
                @if(($filters['year'] ?? null) && ($filters['semester'] ?? null) && ($filters['week'] ?? null))
                    <a class="button button-outline" href="{{ route('records.download', ['scope'=>'week', 'year'=>$filters['year'], 'semester'=>$filters['semester'], 'week'=>$filters['week'], 'tambon'=>$filters['tambon'] ?? null]) }}">ดาวน์โหลด PDF สัปดาห์นี้</a>
                @endif
                <a class="button button-outline" href="{{ route('records.download', ['scope'=>'all', 'tambon'=>$filters['tambon'] ?? null]) }}">ดาวน์โหลดทั้งหมด (ZIP)</a>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead><tr><th>ปี / ภาค</th><th>สัปดาห์</th><th>วันที่สอน</th><th>ตำบล</th><th>ระดับชั้น</th><th>รายวิชา</th><th>การเข้าเรียน</th><th>รูป</th><th><span class="sr-only">จัดการ</span></th></tr></thead>
                <tbody>
                @forelse($records as $record)
                    <tr>
                        <td><b>{{ $record->year }}</b><small>ภาคเรียน {{ $record->semester }}</small></td>
                        <td><span class="week-badge">{{ $record->week }}</span></td>
                        <td>{{ $record->date?->format('d/m/Y') }}</td>
                        <td>{{ $record->tambonRelation?->name ?? $record->tambon }}</td>
                        <td><span class="tag">{{ $record->level ?: '-' }}</span></td>
                        <td><b>{{ $record->subject }}</b><small>{{ $record->subject_code ?: 'ไม่ระบุรหัสวิชา' }}</small></td>
                        <td><b>{{ number_format($record->present) }}/{{ number_format($record->total) }}</b><small>มาเรียน / ทั้งหมด</small></td>
                        <td>{{ $record->images_count }}</td>
                        <td><div class="row-actions"><a class="row-action" href="{{ route('records.show', $record) }}">ดู</a><a class="row-action" href="{{ route('records.print', $record) }}" target="_blank" rel="noopener">พิมพ์</a><a class="row-action" href="{{ route('records.edit', $record) }}">แก้ไข <span aria-hidden="true">›</span></a></div></td>
                    </tr>
                @empty
                    <tr><td colspan="9"><div class="empty-state"><b>ไม่พบรายการบันทึก</b><span>ลองเปลี่ยนตัวกรอง หรือสร้างบันทึกรายสัปดาห์ใหม่</span></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $records->links() }}
    </section>

    <details class="panel weekly-composer" id="weekly-form" @if($errors->any()) open @endif>
        <summary class="panel-heading">
            <div>
                <span class="eyebrow">บันทึกชุดรายสัปดาห์</span>
                <h2>สร้างบันทึกครบ 3 ระดับชั้น</h2>
                <p>ใช้ข้อมูลสัปดาห์ร่วมกัน แล้วกรอกรายละเอียดแต่ละระดับ</p>
            </div>
            <span class="summary-action">เปิดแบบฟอร์ม <span aria-hidden="true">＋</span></span>
        </summary>

        <form method="post" action="{{ route('weekly-submissions.store') }}" enctype="multipart/form-data">
            @csrf
            <div class="form-section common-section">
                <div class="section-copy"><span>ขั้นตอนที่ 1</span><h3>ข้อมูลสัปดาห์</h3><p>ข้อมูลชุดนี้จะใช้ร่วมกันทั้ง 3 ระดับชั้น</p></div>
                <div class="common-grid">
                    @if(auth()->user()->isDistrict())
                        <label><span>ตำบล *</span><select name="tambon" required><option value="">เลือกตำบล</option>@foreach($tambonOptions as $tambonOption)<option value="{{ $tambonOption->id }}" @selected(old('tambon') === $tambonOption->id)>{{ $tambonOption->name }}</option>@endforeach</select></label>
                    @endif
                    <label><span>ปีการศึกษา *</span><input name="year" value="{{ old('year', (int)date('Y') + 543) }}" inputmode="numeric" required></label>
                    <label><span>ภาคเรียน *</span><select name="semester" required>@foreach(['1','2','3'] as $term)<option value="{{ $term }}" @selected(old('semester','1') === $term)>ภาคเรียน {{ $term }}</option>@endforeach</select></label>
                    <label><span>สัปดาห์ *</span><input type="number" min="1" max="20" name="week" value="{{ old('week') }}" placeholder="1–20" required></label>
                    <label><span>วันที่สอน *</span><input type="date" name="date" value="{{ old('date', now()->format('Y-m-d')) }}" required></label>
                </div>
            </div>

            <div class="form-section levels-section">
                <div class="section-copy"><span>ขั้นตอนที่ 2</span><h3>รายละเอียด 3 ระดับชั้น</h3><p>ต้องกรอกครบทุกระดับ ระบบจึงจะบันทึกชุดนี้ได้</p></div>
                <div class="level-grid">
                    @foreach(['ประถมศึกษา','มัธยมศึกษาตอนต้น','มัธยมศึกษาตอนปลาย'] as $index => $level)
                    <fieldset class="level-card">
                        <legend><span class="level-number">{{ $index + 1 }}</span><span><small>ระดับที่ {{ $index + 1 }}</small>{{ $level }}</span></legend>
                        <input type="hidden" name="records[{{ $index }}][level]" value="{{ $level }}">
                        <label><span>รายวิชา *</span><input name="records[{{ $index }}][subject]" value="{{ old("records.$index.subject") }}" required></label>
                        <div class="two-col"><label><span>รหัสวิชา</span><input name="records[{{ $index }}][subject_code]" value="{{ old("records.$index.subject_code") }}"></label><label><span>รหัสกลุ่ม</span><input name="records[{{ $index }}][class_group_code]" value="{{ old("records.$index.class_group_code") }}"></label></div>
                        <label><span>ชื่อกลุ่มเรียน</span><input name="records[{{ $index }}][class_name]" value="{{ old("records.$index.class_name") }}"></label>
                        <div class="two-col"><label><span>ผู้เรียนทั้งหมด *</span><input type="number" min="0" name="records[{{ $index }}][total]" value="{{ old("records.$index.total", 0) }}" required></label><label><span>มาเรียน *</span><input type="number" min="0" name="records[{{ $index }}][present]" value="{{ old("records.$index.present", 0) }}" required></label></div>
                        <label><span>ผลการจัดการเรียนรู้</span><textarea name="records[{{ $index }}][result]" rows="3">{{ old("records.$index.result") }}</textarea></label>
                        <label><span>ปัญหา / อุปสรรค</span><textarea name="records[{{ $index }}][problem]" rows="3">{{ old("records.$index.problem") }}</textarea></label>
                        <label><span>ข้อเสนอแนะ</span><textarea name="records[{{ $index }}][suggestion]" rows="3">{{ old("records.$index.suggestion") }}</textarea></label>
                        <label class="file-field"><span>รูปภาพประกอบ</span><input type="file" name="records[{{ $index }}][images][]" accept="image/jpeg,image/png,image/webp" multiple><small>เลือกได้ 0, 2 หรือ 4 รูป • JPG, PNG, WEBP</small></label>
                    </fieldset>
                    @endforeach
                </div>
            </div>

            <div class="submit-row">
                <p><b>ตรวจสอบก่อนบันทึก</b><span>หากข้อมูลระดับใดผิด ระบบจะไม่บันทึกทั้งชุดเพื่อป้องกันข้อมูลไม่ครบ</span></p>
                <button class="button button-primary" type="submit">บันทึกทั้ง 3 ระดับชั้น <span aria-hidden="true">→</span></button>
            </div>
        </form>
    </details>
</main>
@endsection
