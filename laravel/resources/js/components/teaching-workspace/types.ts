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

export function selectedTeachingLevels(payload: TeachingDraftPayload) {
  const codes = payload.enabled_level_codes ?? teachingLevels.map((level) => level.code)
  return teachingLevels.filter((level) => codes.includes(level.code))
}
