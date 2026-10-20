<?php

namespace App\Services;

use App\Models\TeachingRecord;
use App\Models\User;
use Illuminate\Support\Collection;

class AttendanceReportService
{
    public function build(User $user, array $filters = []): array
    {
        $rows = TeachingRecord::query()
            ->visibleTo($user)
            ->when($user->isDistrict() && ! empty($filters['tambon']), fn ($query) => $query->where('records.tambon', $filters['tambon']))
            ->when(! empty($filters['level']), fn ($query) => $query->where('records.level', $filters['level']))
            ->join('record_attendance as ra', 'ra.record_id', '=', 'records.id')
            ->leftJoin('tambons as t', 't.id', '=', 'records.tambon')
            ->when(! empty($filters['class_group']), function ($query) use ($filters) {
                $value = $filters['class_group'];
                $query->where(fn ($inner) => $inner->whereRaw("COALESCE(NULLIF(ra.group_code, ''), records.class_group_code, '') = ?", [$value])
                    ->orWhereRaw("COALESCE(NULLIF(ra.class_name, ''), records.class_name, '') = ?", [$value]));
            })
            ->when(! empty($filters['search']), function ($query) use ($filters) {
                $search = addcslashes($filters['search'], '%_\\');
                $query->where(fn ($inner) => $inner->where('ra.student_id', 'like', "%{$search}%")
                    ->orWhere('ra.full_name', 'like', "%{$search}%")
                    ->orWhere('ra.class_name', 'like', "%{$search}%")
                    ->orWhere('records.class_name', 'like', "%{$search}%"));
            })
            ->where('ra.student_id', '<>', '')
            ->selectRaw("records.id as record_id, records.tambon, t.name as tambon_name, records.week, records.date, records.level, records.subject, records.subject_code, COALESCE(NULLIF(ra.group_code, ''), records.class_group_code, '') as group_code, COALESCE(NULLIF(ra.class_name, ''), records.class_name, '') as class_name, ra.student_id, ra.full_name, ra.present, ra.learning_method")
            ->orderBy('records.date')->orderBy('records.week')->orderBy('class_name')->orderBy('ra.full_name')
            ->get();

        return $this->aggregate($rows, (string) ($filters['student_id'] ?? ''));
    }

    private function aggregate(Collection $rows, string $selectedStudent): array
    {
        $summary = ['sessions' => $rows->pluck('record_id')->unique()->count(), 'students' => $rows->pluck('student_id')->unique()->count(), 'present' => 0, 'absent' => 0, 'onsite' => 0, 'online' => 0, 'rate' => 0];
        $levels = [];
        $classes = [];
        $students = [];
        $timeline = [];

        foreach ($rows as $row) {
            $present = (bool) $row->present;
            $method = $row->learning_method === 'ออนไลน์' ? 'ออนไลน์' : 'พบกลุ่ม';
            $present ? $summary['present']++ : $summary['absent']++;
            if ($present) {
                $method === 'ออนไลน์' ? $summary['online']++ : $summary['onsite']++;
            }

            $levelKey = $row->level ?: 'ไม่ระบุระดับ';
            $classKey = implode('|', [$row->group_code ?: '', $row->class_name ?: 'ไม่ระบุกลุ่ม', $row->level ?: '']);
            $levels[$levelKey] ??= $this->emptyGroup($levelKey, '', $row->level ?: '');
            $classes[$classKey] ??= $this->emptyGroup($row->class_name ?: 'ไม่ระบุกลุ่ม', $row->group_code ?: '', $row->level ?: '');
            $this->updateGroup($levels[$levelKey], $row, $present, $method);
            $this->updateGroup($classes[$classKey], $row, $present, $method);

            $key = (string) $row->student_id;
            $students[$key] ??= [
                'student_id' => $key, 'full_name' => $row->full_name, 'level' => $row->level ?: '',
                'group_code' => $row->group_code ?: '', 'class_name' => $row->class_name ?: '',
                'tambon' => $row->tambon, 'tambon_name' => $row->tambon_name ?: '',
                'sessions' => 0, 'present' => 0, 'absent' => 0, 'onsite' => 0, 'online' => 0,
                'rate' => 0, 'absences' => [],
            ];
            $students[$key]['sessions']++;
            if ($present) {
                $students[$key]['present']++;
                $method === 'ออนไลน์' ? $students[$key]['online']++ : $students[$key]['onsite']++;
            } else {
                $students[$key]['absent']++;
                $students[$key]['absences'][] = $this->timelineItem($row, false, $method);
            }
            if ($selectedStudent !== '' && $selectedStudent === $key) {
                $timeline[] = $this->timelineItem($row, $present, $method);
            }
        }

        $summary['rate'] = $this->rate($summary['present'], $summary['present'] + $summary['absent']);
        foreach ($levels as &$group) {
            $this->finishGroup($group);
        }
        foreach ($classes as &$group) {
            $this->finishGroup($group);
        }
        foreach ($students as &$student) {
            $student['rate'] = $this->rate($student['present'], $student['sessions']);
        }

        $classes = array_values($classes);
        $students = array_values($students);
        usort($classes, fn ($a, $b) => [$a['level'], $a['name']] <=> [$b['level'], $b['name']]);
        usort($students, fn ($a, $b) => [$b['absent'], $a['class_name'], $a['full_name']] <=> [$a['absent'], $b['class_name'], $b['full_name']]);

        return ['summary' => $summary, 'levels' => array_values($levels), 'classes' => $classes, 'students' => $students, 'selected_timeline' => $timeline];
    }

    private function emptyGroup(string $name, string $code, string $level): array
    {
        return ['name' => $name, 'code' => $code, 'level' => $level, '_sessions' => [], '_students' => [], 'present' => 0, 'absent' => 0, 'onsite' => 0, 'online' => 0, 'rate' => 0];
    }

    private function updateGroup(array &$group, object $row, bool $present, string $method): void
    {
        $group['_sessions'][(string) $row->record_id] = true;
        $group['_students'][(string) $row->student_id] = true;
        if ($present) {
            $group['present']++;
            $method === 'ออนไลน์' ? $group['online']++ : $group['onsite']++;
        } else {
            $group['absent']++;
        }
    }

    private function finishGroup(array &$group): void
    {
        $group['sessions'] = count($group['_sessions']);
        $group['students'] = count($group['_students']);
        unset($group['_sessions'], $group['_students']);
        $group['rate'] = $this->rate($group['present'], $group['present'] + $group['absent']);
    }

    private function timelineItem(object $row, bool $present, string $method): array
    {
        return ['record_id' => $row->record_id, 'week' => (int) $row->week, 'date' => (string) $row->date, 'subject' => $row->subject ?: '', 'subject_code' => $row->subject_code ?: '', 'level' => $row->level ?: '', 'class_name' => $row->class_name ?: '', 'present' => $present, 'learning_method' => $present ? $method : ''];
    }

    private function rate(int $present, int $total): float|int
    {
        return $total > 0 ? round(($present / $total) * 100, 1) : 0;
    }
}
