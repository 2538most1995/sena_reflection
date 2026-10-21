export type User = {
  id: string
  name: string
  username: string
  role: 'district' | 'tambon'
  tambon: string | null
  avatar_path: string | null
}

export type SharedProps = {
  auth: { user: User | null }
  flash: { success?: string | null; error?: string | null }
  errors: Record<string, string>
}

export type Tambon = { id: string; name: string; icon?: string | null }

export type RecordRow = {
  id: string
  weekly_submission_id?: number | null
  tambon: string
  tambon_relation?: Tambon | null
  week: number
  date: string
  level: string
  semester: string
  year: string
  subject: string
  subject_code?: string | null
  class_group_code?: string | null
  class_name?: string | null
  total: number
  present: number
  result?: string | null
  problem?: string | null
  suggestion?: string | null
  supervisor_comment?: string | null
  images_count?: number
  images?: { id: number | string; record_id: string; file_path: string; preview_src?: string | null; view_url?: string | null }[]
  attendance?: AttendanceRow[]
}

export type AttendanceRow = {
  id?: number
  student_id: string
  full_name: string
  group_code?: string | null
  class_name?: string | null
  present: boolean
  learning_method?: 'พบกลุ่ม' | 'ออนไลน์' | null
}

export type PaginationLink = { url: string | null; label: string; active: boolean }
export type Paginated<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number; links: PaginationLink[] }
