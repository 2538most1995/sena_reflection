<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class SemesterBackupPresentation
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const ENTRIES = ['index.html', 'README-TH.txt', 'records.csv', 'attendance.csv', 'missing-files.csv'];

    public static function imageEntry(int $index, string $mime): string
    {
        $extension = match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        };

        return 'images/'.str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT).'.'.$extension;
    }

    /** @return array<string, string> */
    public function build(array $tables, array $files, array $missing, string $year, string $semester): array
    {
        $records = $tables['records'];
        usort($records, static fn (array $left, array $right): int => [(int) $left['week'], (string) $left['date'], (string) $left['id']] <=> [(int) $right['week'], (string) $right['date'], (string) $right['id']]);
        $byId = array_column($records, null, 'id');
        $contents = [
            'README-TH.txt' => $this->readme($year, $semester, count($records), count($files), count($missing)),
            'records.csv' => $this->csv(
                ['รหัสบันทึก', 'วันที่สอน', 'ปีการศึกษา', 'ภาคเรียน', 'สัปดาห์', 'รหัสตำบล', 'ระดับชั้น', 'กลุ่มเรียน', 'รหัสวิชา', 'รายวิชา', 'ผู้เรียนทั้งหมด', 'มาเรียน', 'ผลการจัดการเรียนการสอน', 'สภาพปัญหาและอุปสรรค', 'ข้อเสนอแนะ', 'ความคิดเห็นหัวหน้างาน'],
                array_map(static fn (array $row): array => array_map(static fn (string $key): mixed => $row[$key] ?? '', ['id', 'date', 'year', 'semester', 'week', 'tambon', 'level', 'class_name', 'subject_code', 'subject', 'total', 'present', 'result', 'problem', 'suggestion', 'supervisor_comment']), $records),
            ),
            'attendance.csv' => $this->csv(
                ['รหัสบันทึก', 'สัปดาห์', 'ระดับชั้น', 'รายวิชา', 'รหัสนักศึกษา', 'ชื่อผู้เรียน', 'รหัสกลุ่ม', 'สถานะเข้าเรียน', 'วิธีเรียน'],
                array_map(static fn (array $row): array => [$row['record_id'], $byId[$row['record_id']]['week'] ?? '', $byId[$row['record_id']]['level'] ?? '', $byId[$row['record_id']]['subject'] ?? '', "'".$row['student_id'], $row['full_name'], $row['group_code'] ?? '', $row['present'] ? 'มาเรียน' : 'ขาดเรียน', $row['learning_method'] ?? ''], $tables['record_attendance']),
            ),
            'missing-files.csv' => $this->csv(['พื้นที่จัดเก็บเดิม', 'ที่อยู่ไฟล์เดิม', 'สถานะ'], array_map(static fn (array $file): array => [$file['disk'], $file['path'], 'สูญหายก่อนสำรอง ไม่สามารถกู้จาก ZIP นี้'], $missing)),
        ];
        $this->checkTotal($contents);
        $contents['index.html'] = $this->html($tables, $records, $files, $missing, $year, $semester);
        $this->checkTotal($contents);

        return array_replace(array_fill_keys(self::ENTRIES, ''), $contents);
    }

    private function readme(string $year, string $semester, int $records, int $files, int $missing): string
    {
        return "ชุดสำรอง SENA Reflection ภาคเรียน {$semester}/{$year}\n\n"
            ."วิธีเปิดอ่านในเครื่อง\n1. เก็บ ZIP ต้นฉบับไว้ครบ ห้ามแก้ไฟล์ภายใน ZIP ที่จะใช้นำกลับ\n2. แตก ZIP ลงในโฟลเดอร์ แล้วเปิด index.html ด้วย Chrome, Edge หรือ Safari\n3. รูปจริงอยู่ใน images/ มีนามสกุล .jpg .png .webp หรือ .gif เปิดด้วยโปรแกรมดูภาพได้\n4. เปิด records.csv หรือ attendance.csv ด้วย Excel เพื่อดูข้อความและรายชื่อภาษาไทย\n\n"
            ."มี {$records} บันทึก และไฟล์ภาพที่ตรวจสอบแล้ว {$files} ไฟล์\nต้นฉบับสูญหายก่อนสำรอง {$missing} ไฟล์ ดูรายการใน missing-files.csv ภาพที่สูญหายไม่มีใน ZIP นี้\n\n"
            ."วิธีนำกลับเข้าระบบ\nเข้าสู่ระบบบัญชีอำเภอ เปิด จัดการระบบ > สำรองข้อมูลภาคเรียน เลือก ZIP ต้นฉบับ รอให้ตรวจไฟล์ครบ แล้วกดตรวจและนำกลับ\nระบบตรวจลายเซ็นและ checksum ก่อนนำกลับ ไม่เขียนทับข้อมูลที่ชนกัน ต้องใช้ APP_KEY เดิมของการติดตั้ง\n\n"
            ."manifest.json และ data.json เป็นข้อมูลสำหรับให้ระบบตรวจและกู้คืน ไม่จำเป็นต้องเปิดอ่านด้วยโปรแกรมข้อความ\nหน้า index.html เป็นสำเนาสำหรับอ่านข้อมูลและรูป ไม่ใช่ PDF หรือเอกสารจัดหน้าฉบับพิมพ์ เมื่อนำกลับระบบจะใช้เอกสารและประวัติที่สำรองไว้ตามเดิม\nร่างส่วนตัว บัญชีผู้ใช้ รหัสผ่าน คีย์ API และการตั้งค่าระบบไม่รวมในชุดนี้\nชุดนี้มีข้อมูลนักศึกษา โปรดเก็บในพื้นที่สำรองที่จำกัดผู้เข้าถึง\n";
    }

    private function html(array $tables, array $records, array $files, array $missing, string $year, string $semester): string
    {
        $fileMap = [];
        foreach ($files as $file) {
            $fileMap[$file['disk'].':'.$file['path']] = $file;
        }
        $photos = [];
        foreach ($tables['record_images'] as $image) {
            $photos[$image['record_id']][] = $image;
        }
        $attendance = [];
        foreach ($tables['record_attendance'] as $row) {
            $attendance[$row['record_id']][] = $row;
        }
        $html = '<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src \'self\' file:; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\'; object-src \'none\'"><title>ชุดสำรองภาคเรียน '.$this->escape($semester.'/'.$year).'</title><style>body{font-family:Tahoma,Arial,sans-serif;line-height:1.7;margin:0;background:#f5f7fa;color:#172033}main{max-width:1100px;margin:auto;padding:28px 18px}a{color:#1d4ed8}article,section{background:white;border:1px solid #dbe2ea;border-radius:12px;margin:20px 0;padding:24px;overflow-wrap:anywhere}h1{font-size:1.8rem}h2{font-size:1.3rem}h3{font-size:1rem;margin-bottom:4px}.notice{padding:16px;background:#fff4db;border-radius:10px}.meta{color:#586575}.text{white-space:pre-wrap;margin-top:4px}.gallery{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:14px}figure{margin:0;max-width:100%}img{display:block;width:100%;height:180px;object-fit:contain;background:#f4f6f8;border:1px solid #e2e8f0}figcaption{font-size:.85rem}table{border-collapse:collapse;width:100%;font-size:.9rem}th,td{text-align:left;border-bottom:1px solid #e2e8f0;padding:8px}.scroll{overflow-x:auto}.stats{display:flex;gap:20px;flex-wrap:wrap}@media print{body{background:white}main{max-width:none;padding:0}article,section{break-inside:avoid}img{height:150px}}</style></head><body><main><header><h1>ชุดสำรองภาคเรียน '.$this->escape($semester.'/'.$year).'</h1><p>บันทึกหลังสอนและภาพประกอบสำหรับเปิดอ่านในเครื่อง ใช้ค้นหาด้วย Ctrl+F / Cmd+F</p><div class="stats"><b>'.count($records).' บันทึก</b><b>'.count($files).' ไฟล์ภาพ</b><b>'.count($tables['record_attendance']).' รายชื่อเข้าเรียน</b></div><p><a href="README-TH.txt">วิธีใช้งานภาษาไทย</a> · <a href="records.csv">ตารางบันทึก CSV</a> · <a href="attendance.csv">รายชื่อเข้าเรียน CSV</a> · <a href="#all-images">ภาพทั้งหมด</a></p></header>';
        if ($missing !== []) {
            $this->append($html, '<p class="notice">มีต้นฉบับสูญหายก่อนสำรอง '.count($missing).' ไฟล์ จึงไม่มีภาพเหล่านี้ใน ZIP นี้ <a href="missing-files.csv">ดูรายการไฟล์ที่ขาด</a> ข้อความและข้อมูลอ้างอิงเดิมยังถูกเก็บไว้</p>');
        }
        $this->append($html, '<p class="meta">เก็บ ZIP ต้นฉบับไว้สำหรับนำกลับเข้าระบบ สำเนาหน้านี้ใช้เปิดอ่านข้อมูลและรูป การจัดหน้าเอกสารฉบับพิมพ์เรียกใช้ได้หลังนำ ZIP กลับเข้าระบบ</p>');
        foreach ($records as $record) {
            $this->append($html, '<article><h2>สัปดาห์ที่ '.$this->escape($record['week']).' · '.$this->escape($record['subject']).'</h2><p class="meta">'.$this->escape($record['date']).' · '.$this->escape($record['level']).' · '.$this->escape($record['class_name'] ?? $record['tambon']).' · รหัสวิชา '.$this->escape($record['subject_code'] ?? '').'</p><p>มาเรียน '.$this->escape($record['present']).' / '.$this->escape($record['total']).' คน · รหัสบันทึก '.$this->escape($record['id']).'</p>');
            foreach (['result' => 'ผลการจัดการเรียนการสอน', 'problem' => 'สภาพปัญหาและอุปสรรค', 'suggestion' => 'ข้อเสนอแนะ / แนวทางแก้ปัญหา', 'supervisor_comment' => 'ความคิดเห็นหัวหน้างาน'] as $field => $label) {
                $this->append($html, '<h3>'.$label.'</h3><p class="text">'.$this->escape($record[$field] ?? '').'</p>');
            }
            $this->append($html, '<div class="gallery">');
            foreach ($photos[$record['id']] ?? [] as $index => $photo) {
                $stored = RecordImageAssets::normalizeStoredPath($photo['file_path']);
                $key = str_starts_with($stored ?? '', 'storage/') ? 'public:'.substr($stored, 8) : 'legacy:'.$stored;
                $file = $fileMap[$key] ?? null;
                $this->append($html, $file ? $this->figure($file['entry'], 'ภาพกิจกรรม '.($index + 1)) : '<p class="notice">ภาพกิจกรรม '.($index + 1).' สูญหายก่อนสำรอง</p>');
            }
            $this->append($html, '</div>');
            if (($attendance[$record['id']] ?? []) !== []) {
                $this->append($html, '<details><summary>รายชื่อเข้าเรียน '.count($attendance[$record['id']]).' คน</summary><div class="scroll"><table><thead><tr><th>รหัสนักศึกษา</th><th>ชื่อผู้เรียน</th><th>สถานะ</th><th>วิธีเรียน</th></tr></thead><tbody>');
                foreach ($attendance[$record['id']] as $row) {
                    $this->append($html, '<tr><td>'.$this->escape($row['student_id']).'</td><td>'.$this->escape($row['full_name']).'</td><td>'.($row['present'] ? 'มาเรียน' : 'ขาดเรียน').'</td><td>'.$this->escape($row['learning_method'] ?? '').'</td></tr>');
                }
                $this->append($html, '</tbody></table></div></details>');
            }
            $this->append($html, '</article>');
        }
        $this->append($html, '<section id="all-images"><h2>ภาพทั้งหมด รวมไฟล์เอกสารและภาพในประวัติ</h2><p>รูปมีนามสกุลมาตรฐาน เปิดดูภาพขนาดเต็มได้โดยคลิกที่รูป</p><div class="gallery">');
        foreach ($files as $index => $file) {
            $this->append($html, $this->figure($file['entry'], 'ไฟล์ภาพ '.($index + 1).' · '.basename($file['path'])));
        }
        $this->append($html, '</div></section></main></body></html>');

        return $html;
    }

    private function figure(string $entry, string $caption): string
    {
        return '<figure><a href="'.$this->escape($entry).'"><img src="'.$this->escape($entry).'" loading="lazy" alt="'.$this->escape($caption).'"></a><figcaption>'.$this->escape($caption).'</figcaption></figure>';
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** Prefix spreadsheet formulas; raw restoration values remain unchanged in data.json. */
    private function csv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            foreach ([$headers, ...$rows] as $row) {
                $cells = array_map(static function (mixed $value): string {
                    $text = (string) $value;

                    return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $text) ? "'".$text : $text;
                }, $row);
                fputcsv($stream, $cells, ',', '"', '');
                if (ftell($stream) > self::MAX_BYTES) {
                    $this->tooLarge();
                }
            }
            rewind($stream);

            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    private function append(string &$html, string $fragment): void
    {
        if (strlen($html) + strlen($fragment) > self::MAX_BYTES) {
            $this->tooLarge();
        }
        $html .= $fragment;
    }

    private function checkTotal(array $contents): void
    {
        if (array_sum(array_map(strlen(...), $contents)) > self::MAX_BYTES) {
            $this->tooLarge();
        }
    }

    private function tooLarge(): never
    {
        throw ValidationException::withMessages(['archive' => 'ข้อมูลสำหรับเปิดอ่านใน ZIP มีขนาดเกิน 8 MB กรุณาแบ่งชุดสำรองก่อนดำเนินการ']);
    }
}
