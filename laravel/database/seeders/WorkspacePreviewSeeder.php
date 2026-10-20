<?php

namespace Database\Seeders;

use App\Models\RecordAttendance;
use App\Models\RecordImage;
use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Models\WeeklySubmission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class WorkspacePreviewSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! str_starts_with(DB::connection()->getDatabaseName(), 'sena_workspace_qa_')) {
            throw new RuntimeException('This seeder is restricted to a separate sena_workspace_qa_ database.');
        }
        Tambon::firstOrCreate(['id' => 'qatambon'], ['name' => 'เสนาทดสอบ']);
        foreach (['teacher' => 'tambon', 'district' => 'district'] as $name => $role) {
            User::updateOrCreate(['id' => 'qa'.$name], [
                'username' => 'qa'.$name, 'name' => 'บัญชีทดสอบ '. $name,
                'password' => Hash::make('Workspace-QA-6901'), 'role' => $role,
                'tambon' => $role === 'tambon' ? 'qatambon' : null,
            ]);
        }
        $submission = WeeklySubmission::firstOrCreate([
            'tambon' => 'qatambon', 'year' => '2569', 'semester' => '1', 'week' => 10,
        ], ['submitted_by' => 'qateacher', 'status' => 'complete']);
        foreach (['ประถมศึกษา', 'มัธยมศึกษาตอนต้น', 'มัธยมศึกษาตอนปลาย'] as $index => $level) {
            $record = TeachingRecord::updateOrCreate(['id' => 'qarecord'.($index + 1)], [
                'weekly_submission_id' => $submission->id, 'tambon' => 'qatambon', 'created_by' => 'qateacher',
                'year' => '2569', 'semester' => '1', 'week' => 10, 'date' => '2026-10-15', 'level' => $level,
                'subject' => 'ทักษะการเรียนรู้', 'subject_code' => 'ทช'.($index + 1).'1001',
                'class_group_code' => 'QA'.($index + 1), 'class_name' => 'กลุ่มเสนาทดสอบ '.$level,
                'total' => 3, 'present' => 2,
                'result' => 'ผู้เรียนฝึกตั้งคำถาม สืบค้นข้อมูล และนำเสนอสิ่งที่ได้เรียนรู้ร่วมกัน ผู้เรียนส่วนใหญ่สามารถสรุปประเด็นสำคัญด้วยคำพูดของตนเองได้',
                'problem' => 'ผู้เรียนบางคนยังต้องการเวลาในการสรุปข้อมูลเพิ่มเติม',
                'suggestion' => 'จัดกิจกรรมกลุ่มย่อยและให้ตัวอย่างการสรุปประเด็นก่อนลงมือปฏิบัติ',
            ]);
            foreach (range(1, 3) as $student) {
                RecordAttendance::updateOrCreate(['record_id' => $record->id, 'student_id' => 'QA'.($index + 1).'00'.$student], [
                    'full_name' => 'ผู้เรียนทดสอบ '.$student, 'group_code' => 'QA'.($index + 1),
                    'class_name' => $record->class_name, 'present' => $student < 3, 'learning_method' => $student < 3 ? 'พบกลุ่ม' : null,
                ]);
            }
            foreach (range(1, 4) as $position) {
                $path = 'workspace-qa-fixtures/'.$record->id.'-'.$position.'.jpg';
                Storage::disk('public')->makeDirectory('workspace-qa-fixtures');
                $image = imagecreatetruecolor($position % 2 ? 960 : 640, $position % 2 ? 640 : 960);
                $background = imagecolorallocate($image, 220 + $position * 4, 239, 236);
                imagefill($image, 0, 0, $background);
                $ink = imagecolorallocate($image, 28, 88, 82);
                imagefilledrectangle($image, 40, 100, 450, 380, $ink);
                imagestring($image, 5, 55, 45, 'TEACHING ACTIVITY QA - IMAGE '.$position, $ink);
                imagejpeg($image, Storage::disk('public')->path($path), 85);
                imagedestroy($image);
                RecordImage::firstOrCreate(['record_id' => $record->id, 'file_path' => 'storage/'.$path], ['created_at' => now()]);
            }
        }
    }
}
