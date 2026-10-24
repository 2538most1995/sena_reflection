import { BookOpen, Database, NotePencil, UsersThree, WarningCircle } from '@phosphor-icons/react'
import { useMemo, useState } from 'react'
import { AttendancePicker } from './attendance-picker'
import { ImageManager } from './image-manager'
import { FormField } from '@/components/form-field'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { NativeSelect } from '@/components/ui/native-select'
import { Textarea } from '@/components/ui/textarea'
import type { GroupOption, LevelCode, SubjectOption, TeachingDraftImage, TeachingLevelDraft, WorkspaceLoadState } from './types'
import { teachingLevels } from './types'

type Props = {
  levelCode: LevelCode
  value: TeachingLevelDraft
  groups: GroupOption[]
  subjects: SubjectOption[]
  loadState: WorkspaceLoadState
  images: TeachingDraftImage[]
  imageBusy: boolean
  sourceLabel: string
  onChange: (value: TeachingLevelDraft) => void
  onSelectGroup: (group: GroupOption) => Promise<void>
  onUploadImages: (files: File[]) => Promise<void>
  onDeleteImage: (image: TeachingDraftImage) => Promise<void>
  onReorderImages: (imageIds: string[]) => Promise<void>
}

export function TeachingDetailsForm({
  levelCode,
  value,
  groups,
  subjects,
  loadState,
  images,
  imageBusy,
  sourceLabel,
  onChange,
  onSelectGroup,
  onUploadImages,
  onDeleteImage,
  onReorderImages,
}: Props) {
  const level = teachingLevels.find((item) => item.code === levelCode) ?? teachingLevels[0]
  const levelGroups = useMemo(() => groups.filter((group) => !group.level || group.level === levelCode), [groups, levelCode])
  const levelSubjects = useMemo(() => subjects.filter((subject) => !subject.level || subject.level === levelCode || subject.level === level.name), [level.name, levelCode, subjects])
  const groupIndex = levelGroups.findIndex((group) => group.group_code === value.class_group_code && group.class_name === value.class_name)
  const subjectIndex = levelSubjects.findIndex((subject) => subject.subject === value.subject && subject.subject_code === value.subject_code)
  const [manualGroup, setManualGroup] = useState(Boolean((value.class_group_code || value.class_name) && groupIndex < 0))
  const [manualSubject, setManualSubject] = useState(Boolean(value.subject && subjectIndex < 0))
  const editGroup = (patch: Partial<Pick<TeachingLevelDraft, 'class_group_code' | 'class_name'>>) => onChange({ ...value, ...patch, attendance: [], attendance_reviewed: false, total: 0, present: 0 })
  const selectedGroup: GroupOption = levelGroups[groupIndex] || { group_code: value.class_group_code, class_name: value.class_name, level: levelCode, level_name: level.name, district_id: '', district_name: '' }

  return <div className="grid gap-7">
    <section className="grid gap-4" aria-labelledby={`teaching-basics-${levelCode}`}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-xl bg-secondary text-primary"><BookOpen size={21} /></span><div><h2 id={`teaching-basics-${levelCode}`} className="text-lg font-semibold tracking-[-.02em]">{level.name}</h2><p className="text-xs text-muted-foreground">เลือกกลุ่มและวิชาจากข้อมูลที่มีอยู่ หรือกรอกเองเมื่อจำเป็น</p></div></div>
        <Badge variant="outline"><Database size={14} />{sourceLabel}</Badge>
      </div>

      <div className="grid gap-4 rounded-xl border bg-muted/15 p-4 md:grid-cols-2">
        <div className="grid content-start gap-3">
          <div className="flex items-center justify-between gap-2"><b className="text-sm">กลุ่มเรียน</b><Button type="button" variant="ghost" size="sm" onClick={() => setManualGroup((current) => !current)}>{manualGroup ? 'เลือกจากระบบ' : 'กรอกรหัสกลุ่ม'}</Button></div>
          {manualGroup ? <div className="grid gap-3 sm:grid-cols-2"><FormField label="รหัสกลุ่ม"><Input data-workspace-field="group" maxLength={50} value={value.class_group_code} onChange={(event) => editGroup({ class_group_code: event.target.value })} /></FormField><FormField label="ชื่อกลุ่มเรียน"><Input maxLength={255} value={value.class_name} onChange={(event) => editGroup({ class_name: event.target.value })} /></FormField></div> : <FormField label="เลือกกลุ่มเรียน"><NativeSelect data-workspace-field="group" value={groupIndex >= 0 ? String(groupIndex) : ''} onChange={(event) => { if (!event.target.value) return; const group = levelGroups[Number(event.target.value)]; if (group) void onSelectGroup(group) }}><option value="">{levelGroups.length ? 'เลือกกลุ่มเรียน' : 'ไม่พบกลุ่ม กรอกรหัสกลุ่มได้ด้านบน'}</option>{levelGroups.map((group, index) => <option key={`${group.group_code}|${group.class_name}`} value={index}>{group.class_name} ({group.group_code})</option>)}</NativeSelect></FormField>}
          {value.class_group_code && <Button type="button" variant="outline" size="sm" disabled={loadState.loading} onClick={() => void onSelectGroup(selectedGroup)}>{loadState.loading ? 'กำลังโหลดรายชื่อ...' : value.attendance.length ? 'อัปเดตรายชื่อจากกลุ่มนี้' : 'ดึงรายชื่อจากรหัสกลุ่มนี้'}</Button>}
        </div>

        <div className="grid content-start gap-3">
          <div className="flex items-center justify-between gap-2"><b className="text-sm">รายวิชา</b><Button type="button" variant="ghost" size="sm" onClick={() => setManualSubject((current) => !current)}>{manualSubject ? 'เลือกจากประวัติ' : 'เพิ่มวิชาใหม่'}</Button></div>
          {manualSubject || !levelSubjects.length ? <div className="grid gap-3 sm:grid-cols-2"><FormField label="ชื่อรายวิชา *"><Input data-workspace-field="subject" maxLength={150} value={value.subject} onChange={(event) => onChange({ ...value, subject: event.target.value })} /></FormField><FormField label="รหัสวิชา"><Input maxLength={50} value={value.subject_code} onChange={(event) => onChange({ ...value, subject_code: event.target.value })} /></FormField></div> : <FormField label="เลือกรายวิชาที่มีอยู่"><NativeSelect data-workspace-field="subject" value={subjectIndex >= 0 ? String(subjectIndex) : ''} onChange={(event) => { if (!event.target.value) return; const subject = levelSubjects[Number(event.target.value)]; if (subject) onChange({ ...value, subject: subject.subject, subject_code: subject.subject_code }) }}><option value="">เลือกรายวิชา</option>{levelSubjects.map((subject, index) => <option key={`${subject.subject_code}|${subject.subject}`} value={index}>{subject.subject_code ? `${subject.subject_code} - ` : ''}{subject.subject}</option>)}</NativeSelect></FormField>}
        </div>
      </div>
    </section>

    <section className="grid gap-4 border-t pt-6" aria-labelledby={`teaching-content-${levelCode}`}>
      <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-xl bg-secondary text-primary"><NotePencil size={21} /></span><div><h3 id={`teaching-content-${levelCode}`} className="font-semibold">เนื้อหาหลังการสอน</h3><p className="text-xs text-muted-foreground">ส่วนสำคัญของบันทึกจะแสดงตลอดและบันทึกอัตโนมัติ</p></div></div>
      <FormField label="ผลการจัดการเรียนรู้ *" hint={`${value.result.length.toLocaleString('th-TH')} / 10,000 ตัวอักษร`}><Textarea data-workspace-field="result" rows={6} maxLength={10000} value={value.result} onChange={(event) => onChange({ ...value, result: event.target.value })} placeholder="สรุปผลการเรียนรู้ สิ่งที่ผู้เรียนทำได้ และหลักฐานที่สังเกตพบ" /></FormField>
      <div className="grid gap-4 md:grid-cols-2"><FormField label="ปัญหาและอุปสรรค" hint={`${value.problem.length.toLocaleString('th-TH')} ตัวอักษร`}><Textarea rows={5} value={value.problem} onChange={(event) => onChange({ ...value, problem: event.target.value })} placeholder="บันทึกปัญหาที่เกิดขึ้นจริง" /></FormField><FormField label="ข้อเสนอแนะและแนวทางแก้ไข" hint={`${value.suggestion.length.toLocaleString('th-TH')} ตัวอักษร`}><Textarea rows={5} value={value.suggestion} onChange={(event) => onChange({ ...value, suggestion: event.target.value })} placeholder="แนวทางสำหรับสัปดาห์ถัดไป" /></FormField></div>
    </section>

    <section className="grid gap-4 border-t pt-6" aria-labelledby={`teaching-attendance-${levelCode}`}>
      <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-xl bg-secondary text-primary"><UsersThree size={21} /></span><div><h3 id={`teaching-attendance-${levelCode}`} className="font-semibold">ตรวจรายชื่อและวิธีเรียน</h3><p className="text-xs text-muted-foreground">เลือกผู้มาเรียนและวิธีเรียนตามข้อมูลจริง ระบบเก็บร่างอัตโนมัติ</p></div></div>
      <div className="flex flex-wrap gap-2" role="group" aria-label="รูปแบบข้อมูลผู้เรียน"><Button type="button" size="sm" variant={value.attendance_mode === 'roster' ? 'secondary' : 'outline'} aria-pressed={value.attendance_mode === 'roster'} onClick={() => onChange({ ...value, attendance_mode: 'roster', total: value.attendance.length, present: value.attendance.filter((row) => row.present).length, attendance_reviewed: false })}>เช็กรายชื่อรายคน</Button><Button type="button" size="sm" variant={value.attendance_mode === 'counts' ? 'secondary' : 'outline'} aria-pressed={value.attendance_mode === 'counts'} onClick={() => onChange({ ...value, attendance_mode: 'counts', attendance_reviewed: false })}>ระบุยอดรวมแทนรายชื่อ</Button></div>
      {value.attendance_mode === 'counts' ? <div className="grid gap-4 rounded-xl border bg-muted/15 p-4">
        <div className="flex items-start gap-2 rounded-lg bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-200"><WarningCircle size={17} className="mt-0.5 shrink-0" />ระบุยอดรวมผู้เรียนและจำนวนมาเรียนตามข้อมูลจริง</div>
        <div className="grid gap-4 sm:grid-cols-2"><FormField label="ผู้เรียนทั้งหมด"><Input data-workspace-field="attendance" type="number" min={0} max={10000} value={value.total} onChange={(event) => { const total = Math.max(0, Number(event.target.value)); onChange({ ...value, total, present: Math.min(value.present, total), attendance_reviewed: false }) }} /></FormField><FormField label="มาเรียน"><Input type="number" min={0} max={value.total} value={value.present} onChange={(event) => onChange({ ...value, present: Math.max(0, Math.min(Number(event.target.value), value.total)), attendance_reviewed: false })} /></FormField></div>
      </div> : <AttendancePicker rows={value.attendance} state={loadState} onChange={(attendance) => onChange({ ...value, attendance, attendance_mode: 'roster', total: attendance.length, present: attendance.filter((row) => row.present).length })} />}
    </section>

    <section className="border-t pt-6">
      <ImageManager images={images} busy={imageBusy} onUpload={onUploadImages} onDelete={onDeleteImage} onReorder={onReorderImages} />
    </section>
  </div>
}
