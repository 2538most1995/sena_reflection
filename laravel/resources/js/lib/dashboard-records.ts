import type { RecordRow } from '@/types'

export type WeekGroup = {
  key: string
  week: number
  year: string
  semester: string
  tambon: string
  tambonName: string
  date: string | null
  records: RecordRow[]
  totalStudents: number
  totalPresent: number
  totalImages: number
}

export function toRecordArray(input: unknown): RecordRow[] {
  if (!input) return []
  if (Array.isArray(input)) {
    return input.filter((r): r is RecordRow => Boolean(r && typeof r === 'object'))
  }
  if (typeof input === 'object') {
    return Object.values(input as Record<string, RecordRow>).filter((r): r is RecordRow => Boolean(r && typeof r === 'object'))
  }
  return []
}

const levelOrder: Record<string, number> = {
  'ประถมศึกษา': 1,
  'มัธยมศึกษาตอนต้น': 2,
  'มัธยมศึกษาตอนปลาย': 3,
}

export function groupRecordsByWeek(recordsInput: unknown): WeekGroup[] {
  const records = toRecordArray(recordsInput)
  const groupMap = new Map<string, WeekGroup>()

  for (const record of records) {
    if (!record || typeof record !== 'object') continue

    const key = record.weekly_submission_id
      ? `sub-${record.weekly_submission_id}`
      : `${record.year ?? ''}-${record.semester ?? ''}-${record.week ?? ''}-${record.tambon ?? ''}`

    let group = groupMap.get(key)
    if (!group) {
      group = {
        key,
        week: Number(record.week) || 0,
        year: String(record.year ?? ''),
        semester: String(record.semester ?? ''),
        tambon: String(record.tambon ?? ''),
        tambonName: record.tambon_relation?.name || record.tambon || '',
        date: record.date || null,
        records: [],
        totalStudents: 0,
        totalPresent: 0,
        totalImages: 0,
      }
      groupMap.set(key, group)
    }

    group.records.push(record)
    group.totalStudents += Number(record.total) || 0
    group.totalPresent += Number(record.present) || 0
    group.totalImages += Number(record.images_count) || 0
    if (!group.date && record.date) {
      group.date = record.date
    }
  }

  return Array.from(groupMap.values()).map((group) => {
    group.records.sort((a, b) => (levelOrder[a.level || ''] || 99) - (levelOrder[b.level || ''] || 99))
    return group
  })
}
