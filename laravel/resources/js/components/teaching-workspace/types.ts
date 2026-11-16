import type { AttendanceRow, RecordRow, Tambon } from '@/types'

export const teachingLevels = [
  { code: '1', name: 'ประถมศึกษา', shortName: 'ประถม' },
  { code: '2', name: 'มัธยมศึกษาตอนต้น', shortName: 'ม.ต้น' },
  { code: '3', name: 'มัธยมศึกษาตอนปลาย', shortName: 'ม.ปลาย' },
] as const

export type LevelCode = (typeof teachingLevels)[number]['code']

export type GroupOption = {
  group_code: string
  class_name: string
  level: string
  level_name: string
  district_id: string
  district_name: string
}

export type SubjectOption = {
  subject: string
  subject_code: string
  level: string
  source?: 'sdl' | 'history' | 'activity'
}

export type StudentOption = {
  student_id: string
  full_name: string
  group_code: string
  class_name: string
}

export type WorkspaceAttendanceRow = Omit<AttendanceRow, 'present' | 'learning_method'> & {
  present: boolean
  learning_method: string | null
}

export type TeachingLevelDraft = {
  subject: string
  subject_code: string
  class_group_code: string
  class_name: string
  class_groups?: { group_code: string; class_name: string }[]
  total: number
  present: number
  result: string
  problem: string
  suggestion: string
  attendance_reviewed: boolean
  attendance_mode: 'roster' | 'counts'
  attendance_term?: string | null
  attendance: WorkspaceAttendanceRow[]
  image_ids: string[]
}

export type TeachingDraftPayload = {
  schema_version: 1
  scope: {
    tambon: string
    year: string
    semester: string
    week: string | number
    date: string
  }
  levels: Record<LevelCode, TeachingLevelDraft>
  enabled_level_codes?: LevelCode[]
  active_level_code: LevelCode
  document?: Record<string, unknown>
}

export type TeachingDraftImage = {
  id: string
  level_code: LevelCode
  url: string
  original_name: string
  mime_type: string
  width: number | null
  height: number | null
  size_bytes: number
  sort_order: number
}

export type TeachingDraft = {
  id: string
  status?: 'active' | 'paused' | 'finalized'
  lock_version: number
  schema_version: number
  scope: TeachingDraftPayload['scope']
  payload: TeachingDraftPayload
  images: TeachingDraftImage[]
  last_saved_at: string | null
  source_record_id?: string | null
  source_revision?: number | null
  source_level_codes?: LevelCode[]
}

export type TeachingWorkspaceBootstrap = {
  mode?: 'create' | 'edit'
  draft?: TeachingDraft | null
  submission?: { id: string; status: string; records?: RecordRow[] } | null
  activeRecordId?: string | null
}

export type TeachingWorkspaceProps = {
  mode: 'create' | 'edit'
  groups: GroupOption[]
  subjects: SubjectOption[]
  tambonOptions: Tambon[]
  studentSource: string
  studentError: string | null
  subjectSource?: string
  subjectError?: string | null
  subjectTerm?: string | null
  defaults: { year: string; semester: string; date: string }
  workspace?: TeachingWorkspaceBootstrap | null
  sourceRecord?: RecordRow | null
}

export type WorkspaceLoadState = {
  loading: boolean
  error: string | null
  source: string
}

export type DraftSaveState = 'idle' | 'saving' | 'saved' | 'error' | 'conflict'

export function emptyTeachingLevel(): TeachingLevelDraft {
  return {
    subject: '',
    subject_code: '',
    class_group_code: '',
    class_name: '',
    class_groups: [],
    total: 0,
    present: 0,
    result: '',
    problem: '',
    suggestion: '',
    attendance_reviewed: false,
    attendance_mode: 'roster',
    attendance_term: null,
    attendance: [],
    image_ids: [],
  }
}

export function emptyTeachingDraft(defaults: TeachingWorkspaceProps['defaults']): TeachingDraftPayload {
  return {
    schema_version: 1,
    scope: { tambon: '', year: defaults.year, semester: defaults.semester, week: '', date: defaults.date },
    levels: {
      '1': emptyTeachingLevel(),
      '2': emptyTeachingLevel(),
      '3': emptyTeachingLevel(),
    },
    active_level_code: '1',
    enabled_level_codes: ['1', '2', '3'],
  }
}

export function selectedTeachingLevels(payload?: TeachingDraftPayload | null) {
  if (!payload) return []
  const codes = payload.enabled_level_codes ?? teachingLevels.map((level) => level.code)
  return teachingLevels.filter((level) => codes.includes(level.code))
}

export type TeachingGroup = { group_code: string; class_name: string }

export function selectedTeachingGroups(value: Pick<TeachingLevelDraft, 'class_groups' | 'class_group_code' | 'class_name'>): TeachingGroup[] {
  const groups = value.class_groups?.length ? value.class_groups : value.class_group_code || value.class_name ? [{ group_code: value.class_group_code, class_name: value.class_name }] : []
  const seen = new Set<string>()
  return groups.map(group => ({ group_code: String(group.group_code || '').trim(), class_name: String(group.class_name || '').trim() })).filter(group => {
    const key = group.group_code || group.class_name
    if (!key || seen.has(key)) return false
    seen.add(key)
    return true
  })
}

export function teachingGroupFields(groups: TeachingGroup[]) {
  const selected = selectedTeachingGroups({ class_groups: groups, class_group_code: '', class_name: '' })
  if (selected.length > 20) throw new Error('เลือกกลุ่มเรียนได้ไม่เกิน 20 กลุ่ม')
  const name = [...new Set(selected.map(group => group.class_name || group.group_code))].join(' / ')
  if (Array.from(name).length > 255) throw new Error('ชื่อกลุ่มเรียนรวมยาวเกิน 255 ตัวอักษร กรุณาลดจำนวนกลุ่ม')
  return { class_groups: selected, class_group_code: selected[0]?.group_code || '', class_name: name }
}

/** New API rows never mark anyone present; retained students keep the teacher's own attendance. */
export function mergeTeachingGroupRosters(groups: TeachingGroup[], lists: WorkspaceAttendanceRow[][], existing: WorkspaceAttendanceRow[]) {
  const previous = new Map(existing.map(row => [row.student_id.trim(), row]))
  const merged = new Map<string, WorkspaceAttendanceRow>()
  groups.forEach((group, index) => {
    for (const row of lists[index]) {
      const id = String(row.student_id || '').trim()
      if (!id || (row.group_code?.trim() && row.group_code.trim() !== group.group_code)) throw new Error('รายชื่อที่ได้รับไม่ตรงกับกลุ่มที่เลือก ข้อมูลเดิมยังคงอยู่ กรุณาลองใหม่')
      if (merged.has(id)) continue
      const saved = previous.get(id)
      merged.set(id, { ...row, student_id: id, full_name: String(row.full_name || '').trim(), group_code: group.group_code, class_name: row.class_name?.trim() || group.class_name, present: saved?.present === true, learning_method: saved?.present ? saved.learning_method || 'พบกลุ่ม' : null })
    }
  })
  if (merged.size > 2000) throw new Error('รายชื่อรวมเกิน 2,000 คน กรุณาลดจำนวนกลุ่มเรียน')
  return [...merged.values()]
}
