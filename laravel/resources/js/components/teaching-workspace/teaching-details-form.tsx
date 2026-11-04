import { ArrowClockwise, BookOpen, CaretDown, CaretUp, Check, Database, MagnifyingGlass, NotePencil, UsersThree, WarningCircle, X } from '@phosphor-icons/react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { AttendancePicker } from './attendance-picker'
import { ImageManager } from './image-manager'
import { FormField } from '@/components/form-field'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { NativeSelect } from '@/components/ui/native-select'
import { Textarea } from '@/components/ui/textarea'
import { cn } from '@/lib/utils'
import type { GroupOption, LevelCode, SubjectOption, TeachingDraftImage, TeachingLevelDraft, WorkspaceLoadState } from './types'
import { teachingLevels } from './types'
import { searchSubjects, subjectsForLevel, withOrientationSubject } from './subject-options'

type Props = {
  levelCode: LevelCode
  value: TeachingLevelDraft
  groups: GroupOption[]
  subjects: SubjectOption[]
  subjectsLoading?: boolean
  subjectSource?: string
  subjectError?: string | null
  subjectTerm?: string | null
  subjectContext?: string
  loadState: WorkspaceLoadState
  images: TeachingDraftImage[]
  imageBusy: boolean
  sourceLabel: string
  term: string
  onChange: (value: TeachingLevelDraft) => void
  onSelectGroup: (group: GroupOption) => Promise<void>
  onUploadImages: (files: File[]) => Promise<void>
  onDeleteImage: (image: TeachingDraftImage) => Promise<void>
  onReorderImages: (imageIds: string[]) => Promise<void>
  onRefreshSubjects?: () => void
}

export function TeachingDetailsForm({
  levelCode,
  value,
  groups,
  subjects,
  subjectsLoading = false,
  subjectSource = 'history',
  subjectError = null,
  subjectTerm = null,
  subjectContext = '',
  loadState,
  images,
  imageBusy,
  sourceLabel,
  term,
  onChange,
  onSelectGroup,
  onUploadImages,
  onDeleteImage,
  onReorderImages,
  onRefreshSubjects,
}: Props) {
  const level = teachingLevels.find((item) => item.code === levelCode) ?? teachingLevels[0]
  const levelGroups = useMemo(() => groups.filter((group) => !group.level || group.level === levelCode), [groups, levelCode])
  const levelSubjects = useMemo(() => subjectsForLevel(subjects, levelCode, level.name), [level.name, levelCode, subjects])
  const [showAllSubjects, setShowAllSubjects] = useState(false)
  const selectableSubjects = useMemo(() => {
    return withOrientationSubject(showAllSubjects ? subjects : levelSubjects, levelCode)
  }, [showAllSubjects, levelSubjects, levelCode, subjects])

  const groupIndex = levelGroups.findIndex((group) => group.group_code === value.class_group_code && group.class_name === value.class_name)
  const subjectIndex = selectableSubjects.findIndex((subject) => subject.subject === value.subject && subject.subject_code === value.subject_code)
  const [manualGroup, setManualGroup] = useState(Boolean((value.class_group_code || value.class_name) && groupIndex < 0))
  const [manualSubject, setManualSubject] = useState(Boolean(value.subject && subjectIndex < 0))
  const [dropdownOpen, setDropdownOpen] = useState(false)
  const [openUpwards, setOpenUpwards] = useState(false)
  const comboboxRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const searchInputRef = useRef<HTMLInputElement>(null)
  const [subjectQuery, setSubjectQuery] = useState('')
  const matchingSubjects = useMemo(() => searchSubjects(selectableSubjects, subjectQuery), [selectableSubjects, subjectQuery])
  const selectedSubject = selectableSubjects[subjectIndex]

  useEffect(() => {
    setSubjectQuery('')
    setDropdownOpen(false)
  }, [levelCode, term, subjectContext, value.class_group_code])

  // Detect whether to open upwards or downwards based on available viewport space
  useEffect(() => {
    if (!dropdownOpen || !comboboxRef.current) return
    const rect = comboboxRef.current.getBoundingClientRect()
    const spaceBelow = window.innerHeight - rect.bottom
    const spaceAbove = rect.top
    setOpenUpwards(spaceBelow < 280 && spaceAbove > 240)

    const timer = setTimeout(() => {
      searchInputRef.current?.focus()
    }, 40)
    return () => clearTimeout(timer)
  }, [dropdownOpen])

  // Close dropdown on click outside or escape key
  useEffect(() => {
    if (!dropdownOpen) return
    const handleClickOutside = (event: MouseEvent) => {
      if (comboboxRef.current && !comboboxRef.current.contains(event.target as Node)) {
        setDropdownOpen(false)
      }
    }
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setDropdownOpen(false)
        triggerRef.current?.focus()
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('mousedown', handleClickOutside)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [dropdownOpen])
  const editGroup = (patch: Partial<Pick<TeachingLevelDraft, 'class_group_code' | 'class_name'>>) => onChange({ ...value, ...patch, attendance: [], attendance_reviewed: false, total: 0, present: 0 })
  const selectedGroup: GroupOption = levelGroups[groupIndex] || { group_code: value.class_group_code, class_name: value.class_name, level: levelCode, level_name: level.name, district_id: '', district_name: '' }

  return <div className="grid gap-7">
    <section className="grid gap-4" aria-labelledby={`teaching-basics-${levelCode}`}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-xl bg-secondary text-primary"><BookOpen size={21} /></span><div><h2 id={`teaching-basics-${levelCode}`} className="text-lg font-semibold tracking-[-.02em]">{level.name}</h2><p className="text-xs text-muted-foreground">เลือกกลุ่มและวิชาจากข้อมูลที่มีอยู่ หรือกรอกเองเมื่อจำเป็น</p></div></div>
        <Badge variant="outline"><Database size={14} />{sourceLabel}{value.attendance.length > 0 && value.attendance_term ? ` · ${value.attendance_term}` : ''}</Badge>
      </div>

      <div className="grid gap-4 rounded-xl border bg-muted/15 p-4 md:grid-cols-2">
        <div className="grid content-start gap-3">
          <div className="flex items-center justify-between gap-2">
            <b className="text-sm">กลุ่มเรียน</b>
            <Button type="button" variant="ghost" size="sm" onClick={() => setManualGroup((current) => !current)}>
              {manualGroup ? 'เลือกจากระบบ' : 'กรอกรหัสกลุ่ม'}
            </Button>
          </div>
          {manualGroup ? (
            <div className="grid gap-3 sm:grid-cols-2">
              <FormField label="รหัสกลุ่ม">
                <Input data-workspace-field="group" maxLength={50} value={value.class_group_code} onChange={(event) => editGroup({ class_group_code: event.target.value })} placeholder="เช่น 210007" />
              </FormField>
              <FormField label="ชื่อกลุ่มเรียน">
                <Input maxLength={255} value={value.class_name} onChange={(event) => editGroup({ class_name: event.target.value })} placeholder="ชื่อกลุ่มเรียน" />
              </FormField>
            </div>
          ) : (
            <FormField label="เลือกกลุ่มเรียน">
              <NativeSelect data-workspace-field="group" value={groupIndex >= 0 ? String(groupIndex) : ''} onChange={(event) => { if (!event.target.value) return; const group = levelGroups[Number(event.target.value)]; if (group) void onSelectGroup(group) }}>
                <option value="">{levelGroups.length ? 'เลือกกลุ่มเรียน' : 'ไม่พบกลุ่ม กรอกรหัสกลุ่มได้ด้านบน'}</option>
                {levelGroups.map((group, index) => <option key={`${group.group_code}|${group.class_name}`} value={index}>{group.class_name} ({group.group_code})</option>)}
              </NativeSelect>
            </FormField>
          )}
          <div className="flex flex-wrap items-center gap-2">
            {value.class_group_code && (
              <Button type="button" variant="outline" size="sm" disabled={loadState.loading} onClick={() => void onSelectGroup(selectedGroup)} className="gap-1.5 text-xs">
                <UsersThree size={14} />
                {loadState.loading ? 'กำลังโหลดรายชื่อ...' : value.attendance.length ? 'อัปเดตรายชื่อจากกลุ่มนี้' : 'ดึงรายชื่อจากรหัสกลุ่มนี้'}
              </Button>
            )}
          </div>
        </div>

        <div className="grid content-start gap-3">
          <div className="flex items-center justify-between gap-2">
            <b className="text-sm">รายวิชา</b>
            <Button type="button" variant="ghost" size="sm" onClick={() => setManualSubject((current) => !current)}>
              {manualSubject ? 'เลือกจากระบบ' : 'กรอกรายวิชาเอง'}
            </Button>
          </div>
          {manualSubject || (!subjectsLoading && !selectableSubjects.length) ? (
            <div className="grid gap-3 sm:grid-cols-2">
              <FormField label="ชื่อรายวิชา *">
                <Input data-workspace-field="subject" maxLength={150} value={value.subject} onChange={(event) => onChange({ ...value, subject: event.target.value })} placeholder="ระบุชื่อวิชา" />
              </FormField>
              <FormField label="รหัสวิชา">
                <Input maxLength={50} value={value.subject_code} onChange={(event) => onChange({ ...value, subject_code: event.target.value })} placeholder="เช่น ทช11002" />
              </FormField>
            </div>
          ) : (
            <FormField label="เลือกรายวิชา *">
              <div ref={comboboxRef} className="relative">
                <button
                  ref={triggerRef}
                  type="button"
                  data-workspace-field="subject"
                  disabled={!selectableSubjects.length}
                  onClick={() => setDropdownOpen((open) => !open)}
                  aria-label="เลือกรายวิชา"
                  aria-expanded={dropdownOpen}
                  aria-haspopup="listbox"
                  className={cn(
                    'flex h-11 w-full items-center justify-between rounded-lg border border-input bg-card px-3.5 text-left text-sm text-foreground transition-[border-color,box-shadow] duration-150',
                    'hover:border-primary/50 focus-visible:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/20',
                    (!selectableSubjects.length) && 'cursor-not-allowed opacity-50 bg-muted'
                  )}
                >
                  <span className={cn('truncate', !value.subject && 'text-muted-foreground')}>
                    {subjectsLoading
                      ? 'กำลังโหลดรายวิชา...'
                      : value.subject
                        ? `${value.subject_code ? `${value.subject_code} - ` : ''}${value.subject}`
                        : 'เลือกรายวิชา'}
                  </span>
                  <span className="pointer-events-none ml-2 flex shrink-0 items-center text-muted-foreground">
                    {dropdownOpen ? <CaretUp size={15} /> : <CaretDown size={15} />}
                  </span>
                </button>

                {/* Hidden select to preserve form inspections, accessibility, and focus */}
                <select
                  tabIndex={-1}
                  aria-hidden="true"
                  className="sr-only pointer-events-none"
                  value={subjectIndex >= 0 ? String(subjectIndex) : ''}
                  onChange={(event) => {
                    if (!event.target.value) return
                    const subject = selectableSubjects[Number(event.target.value)]
                    if (subject) onChange({ ...value, subject: subject.subject, subject_code: subject.subject_code })
                  }}
                >
                  <option value="">เลือกรายวิชา</option>
                  {selectableSubjects.map((subject, index) => (
                    <option key={`${subject.level}|${subject.subject_code}|${subject.subject}`} value={index}>
                      {subject.subject_code ? `${subject.subject_code} - ` : ''}{subject.subject}
                    </option>
                  ))}
                </select>

                {dropdownOpen && (
                  <div
                    role="listbox" aria-label="รายวิชาหรือกิจกรรม"
                    className={cn(
                      'absolute left-0 z-50 w-full min-w-[280px] rounded-xl border border-border bg-popover text-popover-foreground shadow-2xl',
                      openUpwards ? 'bottom-full mb-1.5' : 'top-full mt-1.5'
                    )}
                  >
                    <div className="border-b border-border/60 p-2 bg-muted/20">
                      <div className="relative">
                        <MagnifyingGlass
                          size={15}
                          className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                          ref={searchInputRef}
                          type="search"
                          value={subjectQuery}
                          onChange={(event) => setSubjectQuery(event.target.value)}
                          placeholder="พิมพ์ค้นหารหัสหรือชื่อวิชา..."
                          className="h-8 pl-8 pr-7 text-xs bg-background"
                        />
                        {subjectQuery && (
                          <button
                            type="button"
                            onClick={() => setSubjectQuery('')}
                            className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground p-0.5"
                          >
                            <X size={13} />
                          </button>
                        )}
                      </div>
                    </div>

                    <div className="max-h-60 overflow-y-auto p-1 text-xs overscroll-contain">
                      {!matchingSubjects.length ? (
                        <div className="py-6 text-center text-xs text-muted-foreground">
                          ไม่พบรายวิชาที่ค้นหา &ldquo;{subjectQuery}&rdquo;
                        </div>
                      ) : (
                        (['activity', 'sdl', 'history'] as const).map((source) => {
                          const items = matchingSubjects.filter((subject) => (subject.source || 'history') === source)
                          if (!items.length) return null
                          return (
                            <div key={source} className="mb-2 last:mb-0">
                              <div className="px-2 py-1 text-[11px] font-semibold text-muted-foreground">
                                {source === 'activity' ? 'กิจกรรม' : source === 'sdl' ? 'SDL School' : 'ประวัติบันทึก'} ({items.length})
                              </div>
                              <div className="space-y-0.5">
                                {items.map((subject) => {
                                  const isSelected = subject.subject === value.subject && subject.subject_code === value.subject_code
                                  return (
                                    <button
                                      key={`${subject.level}|${subject.subject_code}|${subject.subject}`}
                                      type="button"
                                      role="option" aria-selected={isSelected} aria-label={[subject.subject_code, subject.subject].filter(Boolean).join(' - ')}
                                      onClick={() => {
                                        onChange({ ...value, subject: subject.subject, subject_code: subject.subject_code })
                                        setDropdownOpen(false)
                                        setSubjectQuery('')
                                        triggerRef.current?.focus()
                                      }}
                                      className={cn(
                                        'flex w-full items-center justify-between gap-2 rounded-md px-2.5 py-2 text-left text-xs transition-colors',
                                        isSelected
                                          ? 'bg-primary/10 text-primary font-semibold'
                                          : 'hover:bg-muted/70 text-foreground'
                                      )}
                                    >
                                      <div className="min-w-0 flex-1 truncate">
                                        {subject.subject_code && (
                                          <span className="font-semibold text-foreground/90 mr-1.5">
                                            {subject.subject_code}
                                          </span>
                                        )}
                                        <span>{subject.subject}</span>
                                        {(showAllSubjects || levelSubjects.length === 0) && subject.level ? (
                                          <span className="ml-1.5 text-[11px] text-muted-foreground">
                                            ({subject.level})
                                          </span>
                                        ) : null}
                                      </div>
                                      {isSelected && (
                                        <Check size={14} className="shrink-0 text-primary" weight="bold" />
                                      )}
                                    </button>
                                  )
                                })}
                              </div>
                            </div>
                          )
                        })
                      )}
                    </div>
                  </div>
                )}
              </div>
            </FormField>
          )}
          <div className="flex flex-wrap items-center gap-2">
            {onRefreshSubjects && (
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={subjectsLoading}
                onClick={() => void onRefreshSubjects()}
                title="ดึงข้อมูลรายวิชาล่าสุดจากระบบ SDL School"
                className="gap-1.5 text-xs"
              >
                <ArrowClockwise size={13} className={subjectsLoading ? 'animate-spin' : ''} />
                {subjectsLoading ? 'กำลังอัปเดต...' : 'อัปเดตรายวิชา'}
              </Button>
            )}
            {levelSubjects.length > 0 && subjects.length > levelSubjects.length && (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => setShowAllSubjects((current) => !current)}
                className="text-xs text-muted-foreground"
              >
                {showAllSubjects ? `เฉพาะ${level.name}` : `วิชาทั้งหมด (${subjects.length})`}
              </Button>
            )}
            {levelSubjects.length === 0 && selectableSubjects.length > 0 && !manualSubject && (
              <span className="text-xs text-muted-foreground">แสดงวิชาทั้งหมด {selectableSubjects.length} วิชา</span>
            )}
          </div>
        </div>
        {subjectError && (
          <div role="status" className="col-span-full flex items-center gap-2 rounded-lg bg-amber-500/10 px-3 py-2 text-xs leading-5 text-amber-800 dark:text-amber-200">
            <WarningCircle size={15} className="shrink-0" />
            <span>{subjectError}</span>
          </div>
        )}
      </div>
    </section>

    <section className="grid gap-4 border-t pt-6" aria-labelledby={`teaching-content-${levelCode}`}>
      <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-xl bg-secondary text-primary"><NotePencil size={21} /></span><div><h3 id={`teaching-content-${levelCode}`} className="font-semibold">เนื้อหาหลังการสอน</h3><p className="text-xs text-muted-foreground">ส่วนสำคัญของบันทึกจะแสดงตลอดและบันทึกอัตโนมัติ</p></div></div>
      <FormField label="ผลการจัดการเรียนรู้ *" hint={`${value.result.length.toLocaleString('th-TH')} / 10,000 ตัวอักษร`}><Textarea data-workspace-field="result" rows={6} maxLength={10000} value={value.result} onChange={(event) => onChange({ ...value, result: event.target.value })} placeholder="สรุปผลการเรียนรู้ สิ่งที่ผู้เรียนทำได้ และหลักฐานที่สังเกตพบ" /></FormField>
      <div className="grid gap-4 md:grid-cols-2"><FormField label="ปัญหาและอุปสรรค" hint={`${value.problem.length.toLocaleString('th-TH')} ตัวอักษร`}><Textarea rows={5} value={value.problem} onChange={(event) => onChange({ ...value, problem: event.target.value })} placeholder="บันทึกปัญหาที่เกิดขึ้นจริง" /></FormField><FormField label="ข้อเสนอแนะและแนวทางแก้ไข" hint={`${value.suggestion.length.toLocaleString('th-TH')} ตัวอักษร`}><Textarea rows={5} value={value.suggestion} onChange={(event) => onChange({ ...value, suggestion: event.target.value })} placeholder="แนวทางสำหรับสัปดาห์ถัดไป" /></FormField></div>
    </section>

    <section className="grid gap-4 border-t pt-6" aria-labelledby={`teaching-attendance-${levelCode}`}>
      <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-xl bg-secondary text-primary"><UsersThree size={21} /></span><div><h3 id={`teaching-attendance-${levelCode}`} className="font-semibold">ตรวจรายชื่อและวิธีเรียน</h3><p className="text-xs text-muted-foreground">เลือกผู้มาเรียนและวิธีเรียนตามข้อมูลจริง ระบบเก็บร่างอัตโนมัติ</p></div></div>
      {value.attendance_mode === 'roster' && value.attendance.length > 0 && value.attendance_term !== term && <div role="status" className="grid gap-3 rounded-xl border border-amber-300/70 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"><p><b>เปลี่ยนภาคเรียนแล้ว กรุณาตรวจรายชื่อใหม่</b><br />รายชื่อนี้มาจากภาคเรียน {value.attendance_term || 'ที่ยังไม่ระบุ'} แต่บันทึกกำลังใช้ภาคเรียน {term} สถานะเช็กชื่อเดิมยังคงอยู่ กรุณาอัปเดตรายชื่อและตรวจผู้มาเรียนตามจริง</p><Button type="button" variant="outline" size="sm" className="justify-self-start" disabled={loadState.loading || !value.class_group_code} onClick={() => void onSelectGroup(selectedGroup)}>{loadState.loading ? 'กำลังโหลดรายชื่อ...' : 'อัปเดตรายชื่อภาคเรียนนี้'}</Button></div>}
      <div className="flex flex-wrap gap-2" role="group" aria-label="รูปแบบข้อมูลผู้เรียน"><Button type="button" size="sm" variant={value.attendance_mode === 'roster' ? 'secondary' : 'outline'} aria-pressed={value.attendance_mode === 'roster'} onClick={() => onChange({ ...value, attendance_mode: 'roster', total: value.attendance.length, present: value.attendance.filter((row) => row.present).length, attendance_reviewed: false })}>เช็กรายชื่อรายคน</Button><Button type="button" size="sm" variant={value.attendance_mode === 'counts' ? 'secondary' : 'outline'} aria-pressed={value.attendance_mode === 'counts'} onClick={() => onChange({ ...value, attendance_mode: 'counts', attendance_reviewed: false })}>ระบุยอดรวมแทนรายชื่อ</Button></div>
      {value.attendance_mode === 'counts' ? <div className="grid gap-4 rounded-xl border bg-muted/15 p-4">
        <div className="flex items-start gap-2 rounded-lg bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-200"><WarningCircle size={17} className="mt-0.5 shrink-0" />ระบุยอดรวมผู้เรียนและจำนวนมาเรียนตามข้อมูลจริง</div>
        <div className="grid gap-4 sm:grid-cols-2"><FormField label="ผู้เรียนทั้งหมด"><Input data-workspace-field="attendance" type="number" min={0} max={10000} value={value.total} onChange={(event) => { const total = Math.max(0, Number(event.target.value)); onChange({ ...value, total, present: Math.min(value.present, total), attendance_reviewed: false }) }} /></FormField><FormField label="มาเรียน"><Input type="number" min={0} max={value.total} value={value.present} onChange={(event) => onChange({ ...value, present: Math.max(0, Math.min(Number(event.target.value), value.total)), attendance_reviewed: false })} /></FormField></div>
      </div> : <AttendancePicker rows={value.attendance} levelCode={levelCode} groupCode={value.class_group_code} state={loadState} onReload={() => void onSelectGroup(selectedGroup)} onChange={(attendance) => onChange({ ...value, attendance, attendance_mode: 'roster', total: attendance.length, present: attendance.filter((row) => row.present).length })} />}
    </section>

    <section className="border-t pt-6">
      <ImageManager images={images} busy={imageBusy} onUpload={onUploadImages} onDelete={onDeleteImage} onReorder={onReorderImages} />
    </section>
  </div>
}
