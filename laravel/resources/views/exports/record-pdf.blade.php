<!doctype html>
<html lang="th">
<head><meta charset="utf-8"><style>
@font-face{font-family:THSarabun;src:url('{{ 'file://'.realpath(base_path('../fonts/THSarabunNew.ttf')) }}') format('truetype');font-weight:400}
@font-face{font-family:THSarabun;src:url('{{ 'file://'.realpath(base_path('../fonts/THSarabunNew-Bold.ttf')) }}') format('truetype');font-weight:700}
@page{margin:{{ $settings['pdf_margin_top_px'] ?? 57 }}px {{ $settings['pdf_margin_right_px'] ?? 76 }}px {{ $settings['pdf_margin_bottom_px'] ?? 38 }}px {{ $settings['pdf_margin_left_px'] ?? 76 }}px}*{box-sizing:border-box}body{font-family:THSarabun,DejaVu Sans,sans-serif;font-size:{{ $settings['pdf_font_size_pt'] ?? 16 }}pt;line-height:1.22;color:#000;margin:0}.record{page-break-after:always}.record:last-child{page-break-after:auto}h1{text-align:center;font-size:22pt;margin:0 0 2mm}h2{text-align:center;font-size:19pt;margin:0 0 4mm;font-weight:400}.meta{width:100%;border-collapse:collapse;margin-bottom:3mm}.meta td{border-bottom:1px dotted #666;padding:1mm 2mm;width:50%}.section{margin:2mm 0;page-break-inside:avoid}.section b{display:block}.text-box{min-height:14mm;border:1px solid #777;padding:2mm;white-space:pre-wrap;word-wrap:break-word;overflow-wrap:anywhere}.photos{width:100%;border-collapse:collapse;margin-top:2mm;page-break-inside:avoid}.photos td{width:50%;height:42mm;padding:1.2mm;text-align:center;vertical-align:middle}.photos img{max-width:100%;max-height:39mm}.sign{margin-top:5mm;text-align:right;padding-right:15mm;page-break-inside:avoid}.muted{color:#555}
</style></head>
<body>
@foreach($records as $record)
<section class="record">
    <h1>แบบบันทึกหลังการจัดการเรียนรู้</h1>
    <h2>ศกร.ระดับตำบล{{ $record->tambonRelation?->name ?? $record->tambon }}</h2>
    <table class="meta"><tr><td><b>ปีการศึกษา:</b> {{ $record->year }} ภาคเรียนที่ {{ $record->semester }}</td><td><b>สัปดาห์ที่:</b> {{ $record->week }} วันที่ {{ $record->date?->format('d/m/Y') }}</td></tr><tr><td><b>ระดับ:</b> {{ $record->level }}</td><td><b>กลุ่มเรียน:</b> {{ $record->class_name ?: '-' }}</td></tr><tr><td><b>วิชา:</b> {{ $record->subject }}</td><td><b>รหัสวิชา:</b> {{ $record->subject_code ?: '-' }}</td></tr><tr><td><b>ผู้เรียน:</b> {{ $record->total }} คน</td><td><b>มาเรียน:</b> {{ $record->present }} คน</td></tr></table>
    <div class="section"><b>ผลการจัดการเรียนรู้</b><div class="text-box">{{ $record->result ?: '-' }}</div></div>
    <div class="section"><b>ปัญหา/อุปสรรค</b><div class="text-box">{{ $record->problem ?: '-' }}</div></div>
    <div class="section"><b>ข้อเสนอแนะ</b><div class="text-box">{{ $record->suggestion ?: '-' }}</div></div>
    @if(($images[$record->id] ?? collect())->isNotEmpty())<table class="photos">@foreach(($images[$record->id] ?? collect())->chunk(2) as $row)<tr>@foreach($row as $image)<td><img src="{{ $image }}"></td>@endforeach @if($row->count() === 1)<td></td>@endif</tr>@endforeach</table>@endif
    <div class="sign">ลงชื่อ .................................................... ผู้บันทึก<br><span>({{ $settings['sig1_name'] ?? '....................................................' }})</span><br><span class="muted">{{ $settings['sig1_pos'] ?? 'ครูผู้สอน' }}</span></div>
</section>
@endforeach
</body></html>
