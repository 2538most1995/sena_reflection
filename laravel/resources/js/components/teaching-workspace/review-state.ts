import type { LevelCode, TeachingDraftImage, TeachingDraftPayload, TeachingLevelDraft } from './types'
import { selectedTeachingLevels } from './types'

export type LevelIssue = { levelCode: LevelCode; message: string; field: string }

export function teachingAttendanceCounts(level: TeachingLevelDraft) {
  return level.attendance_mode === 'roster'
    ? { total: level.attendance.length, present: level.attendance.filter(row => row.present).length }
    : { total: level.total, present: level.present }
}

export function teachingAttendanceReady(level: TeachingLevelDraft): boolean {
  if (level.attendance_mode === 'counts') return Number.isInteger(level.total) && Number.isInteger(level.present) && level.total >= 0 && level.total <= 10000 && level.present >= 0 && level.present <= level.total
  return level.attendance.length > 0 && level.attendance.length <= 2000 && new Set(level.attendance.map(row => row.student_id)).size === level.attendance.length && level.attendance.every(row => row.student_id.trim() && row.student_id.length <= 50 && row.full_name.trim() && row.full_name.length <= 255 && typeof row.present === 'boolean' && (!row.group_code || !level.class_group_code || row.group_code === level.class_group_code) && (row.learning_method == null || row.learning_method === 'พบกลุ่ม' || row.learning_method === 'ออนไลน์'))
}

export function teachingLevelReady(level: TeachingLevelDraft, imageCount = level.image_ids.length): boolean {
  return Boolean((level.class_name.trim() || level.class_group_code.trim()) && level.subject.trim() && level.result.trim() && teachingAttendanceReady(level) && [0, 2, 4].includes(imageCount))
}

export function reviewIssues(payload: TeachingDraftPayload, images: TeachingDraftImage[]): LevelIssue[] {
  const selectedLevels = selectedTeachingLevels(payload)
  const scopeLevel = selectedLevels[0]?.code || '1'
  const scopeIssues: LevelIssue[] = []
  if (!selectedLevels.length) scopeIssues.push({ levelCode: scopeLevel, message: 'เลือกระดับชั้นที่สอนอย่างน้อย 1 ระดับ', field: 'scope.levels' })
  if (!payload.scope.tambon) scopeIssues.push({ levelCode: scopeLevel, message: 'ยังไม่ได้เลือกตำบลของชุดงาน', field: 'scope.tambon' })
  if (!/^25\d{2}$/.test(payload.scope.year)) scopeIssues.push({ levelCode: scopeLevel, message: 'ระบุปีการศึกษาเป็น พ.ศ. 4 หลัก', field: 'scope.year' })
  if (!['1', '2', '3'].includes(payload.scope.semester)) scopeIssues.push({ levelCode: scopeLevel, message: 'เลือกภาคเรียน', field: 'scope.semester' })
  if (!payload.scope.week || !Number.isInteger(Number(payload.scope.week)) || Number(payload.scope.week) < 1 || Number(payload.scope.week) > 20) scopeIssues.push({ levelCode: scopeLevel, message: 'เลือกสัปดาห์ที่ 1-20', field: 'scope.week' })
  if (!payload.scope.date) scopeIssues.push({ levelCode: scopeLevel, message: 'เลือกวันที่สอน', field: 'scope.date' })
  return [...scopeIssues, ...selectedLevels.flatMap(({ code }) => {
    const level = payload.levels[code]
    const issues: LevelIssue[] = []
    if (!level.class_name.trim() && !level.class_group_code.trim()) issues.push({ levelCode: code, message: 'ยังไม่ได้เลือกกลุ่มเรียน', field: 'group' })
    if (!level.subject.trim()) issues.push({ levelCode: code, message: 'ยังไม่ได้ระบุรายวิชา', field: 'subject' })
    if (!level.result.trim()) issues.push({ levelCode: code, message: 'ยังไม่ได้บันทึกผลการจัดการเรียนรู้', field: 'result' })
    if (!teachingAttendanceReady(level)) issues.push({ levelCode: code, message: level.attendance_mode === 'roster' ? level.attendance.length ? 'ตรวจรายชื่อและวิธีเรียนให้ถูกต้อง' : 'ยังไม่มีรายชื่อนักศึกษา' : 'ตรวจยอดผู้เรียนทั้งหมดและจำนวนมาเรียนให้ถูกต้อง', field: 'attendance' })
    const imageCount = images.filter(image => image.level_code === code).length
    if (![0, 2, 4].includes(imageCount)) issues.push({ levelCode: code, message: `รูปภาพต้องมี 0, 2 หรือ 4 รูป (ขณะนี้ ${imageCount})`, field: 'images' })
    return issues
  })]
}

export function teachingSaveSummary(payload: TeachingDraftPayload, images: TeachingDraftImage[]) {
  return selectedTeachingLevels(payload).map(level => ({ ...level, ...teachingAttendanceCounts(payload.levels[level.code]), subject: payload.levels[level.code].subject, group: payload.levels[level.code].class_name || payload.levels[level.code].class_group_code, images: images.filter(image => image.level_code === level.code).length }))
}
