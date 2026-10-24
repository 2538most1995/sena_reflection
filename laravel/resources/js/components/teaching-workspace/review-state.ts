import type { LevelCode, TeachingDraftImage, TeachingDraftPayload, TeachingLevelDraft, WorkspaceAttendanceRow } from './types'
import { selectedTeachingLevels } from './types'

export type LevelIssue = { levelCode: LevelCode; message: string; field: string }
export type AttendanceIssue = { index: number; field: 'student_id' | 'full_name' | 'group_code' | 'present' | 'learning_method'; message: string }

function scalarText(value: unknown): string {
  return typeof value === 'string' || typeof value === 'number' ? String(value).trim() : ''
}

export function normalizeTeachingAttendance(value: unknown): WorkspaceAttendanceRow[] {
  if (!Array.isArray(value)) return []
  return value.filter(row => row && typeof row === 'object' && !Array.isArray(row)).map(row => {
    const present = row.present === true || row.present === 1 || row.present === '1'
    const method = scalarText(row.learning_method)
    return { student_id: scalarText(row.student_id), full_name: scalarText(row.full_name), group_code: scalarText(row.group_code), class_name: scalarText(row.class_name), present, learning_method: present ? method || 'พบกลุ่ม' : null }
  })
}

export function attendanceRowIssues(rows: WorkspaceAttendanceRow[], groupCode = ''): AttendanceIssue[] {
  const issues: AttendanceIssue[] = []
  const firstIds = new Map<string, number>()
  rows.forEach((row, index) => {
    const id = scalarText(row.student_id)
    const name = scalarText(row.full_name)
    if (!id) issues.push({ index, field: 'student_id', message: 'กรอกรหัสนักศึกษาให้ตรงทะเบียน' })
    else if (id.length > 50) issues.push({ index, field: 'student_id', message: 'รหัสนักศึกษายาวเกิน 50 ตัวอักษร' })
    if (id && firstIds.has(id)) issues.push({ index, field: 'student_id', message: `รหัสนักศึกษาซ้ำกับลำดับ ${(firstIds.get(id) || 0) + 1} กรุณาตรวจรหัสให้ถูกต้อง` })
    else if (id) firstIds.set(id, index)
    if (!name) issues.push({ index, field: 'full_name', message: 'กรอกชื่อผู้เรียนให้ตรงทะเบียน' })
    else if (name.length > 255) issues.push({ index, field: 'full_name', message: 'ชื่อผู้เรียนยาวเกิน 255 ตัวอักษร' })
    if (typeof row.present !== 'boolean') issues.push({ index, field: 'present', message: 'เลือกสถานะมาเรียนให้ถูกต้อง' })
    if (row.group_code && groupCode && scalarText(row.group_code) !== scalarText(groupCode)) issues.push({ index, field: 'group_code', message: 'กลุ่มในรายชื่อไม่ตรงกับกลุ่มที่เลือก กรุณาอัปเดตรายชื่อจากกลุ่มนี้' })
    if (row.learning_method != null && row.learning_method !== 'พบกลุ่ม' && row.learning_method !== 'ออนไลน์') issues.push({ index, field: 'learning_method', message: 'เลือกวิธีเรียนเป็นพบกลุ่มหรือออนไลน์' })
  })
  return issues
}

export function teachingAttendanceCounts(level: TeachingLevelDraft) {
  return level.attendance_mode === 'roster'
    ? { total: level.attendance.length, present: level.attendance.filter(row => row.present).length }
    : { total: level.total, present: level.present }
}

export function teachingAttendanceReady(level: TeachingLevelDraft): boolean {
  if (level.attendance_mode === 'counts') return Number.isInteger(level.total) && Number.isInteger(level.present) && level.total >= 0 && level.total <= 10000 && level.present >= 0 && level.present <= level.total
  return level.attendance.length > 0 && level.attendance.length <= 2000 && attendanceRowIssues(level.attendance, level.class_group_code).length === 0
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
    if (level.attendance_mode === 'counts' && !teachingAttendanceReady(level)) issues.push({ levelCode: code, message: 'ตรวจยอดผู้เรียนทั้งหมดและจำนวนมาเรียนให้ถูกต้อง', field: 'attendance' })
    if (level.attendance_mode === 'roster') {
      if (!level.attendance.length) issues.push({ levelCode: code, message: 'ยังไม่มีรายชื่อนักศึกษา', field: 'attendance' })
      if (level.attendance.length > 2000) issues.push({ levelCode: code, message: 'รายชื่อเกิน 2,000 คน กรุณาตรวจกลุ่มเรียน', field: 'attendance' })
      for (const issue of attendanceRowIssues(level.attendance, level.class_group_code)) {
        const row = level.attendance[issue.index]
        issues.push({ levelCode: code, message: `ลำดับ ${issue.index + 1} (${scalarText(row.full_name) || scalarText(row.student_id) || 'ยังไม่มีชื่อ'}) — ${issue.message}`, field: `attendance.${issue.index}.${issue.field}` })
      }
    }
    const imageCount = images.filter(image => image.level_code === code).length
    if (![0, 2, 4].includes(imageCount)) issues.push({ levelCode: code, message: `รูปภาพต้องมี 0, 2 หรือ 4 รูป (ขณะนี้ ${imageCount})`, field: 'images' })
    return issues
  })]
}

export function teachingSaveSummary(payload: TeachingDraftPayload, images: TeachingDraftImage[]) {
  return selectedTeachingLevels(payload).map(level => ({ ...level, ...teachingAttendanceCounts(payload.levels[level.code]), subject: payload.levels[level.code].subject, group: payload.levels[level.code].class_name || payload.levels[level.code].class_group_code, images: images.filter(image => image.level_code === level.code).length }))
}
