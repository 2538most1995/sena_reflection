import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'
import test from 'node:test'

const hook = registerHooks({ resolve(specifier, context, nextResolve) {
  if (specifier === './types' && context.parentURL?.endsWith('/review-state.ts')) return nextResolve('./types.ts', context)
  return nextResolve(specifier, context)
} })
const { reviewIssues, teachingAttendanceReady, teachingLevelReady, teachingSaveSummary } = await import('../../resources/js/components/teaching-workspace/review-state.ts')
const { emptyTeachingDraft } = await import('../../resources/js/components/teaching-workspace/types.ts')
hook.deregister()

function fixture() {
  const payload = emptyTeachingDraft({ year: '2569', semester: '1', date: '2026-10-24' })
  payload.scope.tambon = 'test-tambon'
  payload.scope.week = '1'
  payload.enabled_level_codes = ['1']
  Object.assign(payload.levels['1'], { class_name: 'กลุ่มทดสอบ', class_group_code: 'G1', subject: 'ภาษาไทย', result: 'ผู้เรียนฝึกการอ่านและการสื่อสาร', attendance_mode: 'counts', total: 4, present: 2, attendance_reviewed: false })
  return payload
}

test('one final confirmation needs valid data without per-level attendance approval', () => {
  const payload = fixture()
  assert.deepEqual(reviewIssues(payload, []), [])
  assert.equal(teachingLevelReady(payload.levels['1']), true)
  payload.levels['1'].attendance_mode = 'roster'
  payload.levels['1'].attendance = [{ student_id: '001', full_name: 'นักศึกษาทดสอบ', group_code: 'G1', present: false, learning_method: null }]
  assert.deepEqual(reviewIssues(payload, []), [])
  assert.equal(teachingLevelReady(payload.levels['1']), true)
  assert.equal(payload.levels['1'].attendance_reviewed, false)
})

test('count mode keeps integer, range and present <= total validation', () => {
  const level = fixture().levels['1']
  for (const [total, present] of [[1, 2], [-1, 0], [2, -1], [10001, 0], [2.5, 1], [3, 1.5]]) assert.equal(teachingAttendanceReady({ ...level, total, present }), false)
  assert.equal(teachingAttendanceReady({ ...level, total: 0, present: 0 }), true)
})

test('roster mode rejects missing, duplicate, foreign-group and malformed attendance', () => {
  const level = { ...fixture().levels['1'], attendance_mode: 'roster' }
  const row = { student_id: '001', full_name: 'นักศึกษาทดสอบ', group_code: 'G1', present: false, learning_method: null }
  for (const attendance of [[], [row, row], [{ ...row, student_id: '' }], [{ ...row, full_name: '' }], [{ ...row, group_code: 'OTHER' }], [{ ...row, learning_method: 'invalid' }], Array.from({ length: 2001 }, (_, index) => ({ ...row, student_id: String(index) }))]) assert.equal(teachingAttendanceReady({ ...level, attendance }), false)
  assert.equal(teachingAttendanceReady({ ...level, attendance: [{ ...row, present: true, learning_method: null }] }), true)
})

test('review focuses meaningful fields and excludes unselected levels', () => {
  const payload = fixture()
  payload.levels['1'].subject = ''
  assert.deepEqual(reviewIssues(payload, []).map(issue => [issue.levelCode, issue.field]), [['1', 'subject']])
  payload.scope.week = ''
  assert.equal(reviewIssues(payload, [])[0].field, 'scope.week')
  assert.equal(reviewIssues(payload, []).some(issue => issue.levelCode === '2'), false)
})

test('images still require zero, two or four actual images for each selected level', () => {
  const payload = fixture()
  const images = count => Array.from({ length: count }, (_, index) => ({ id: String(index), level_code: '1' }))
  for (const count of [0, 2, 4]) assert.deepEqual(reviewIssues(payload, images(count)), [])
  for (const count of [1, 3, 5]) assert.equal(reviewIssues(payload, images(count)).find(issue => issue.field === 'images')?.levelCode, '1')
})

test('confirmation derives roster counts and selected classes without marking anyone present', () => {
  const payload = fixture()
  payload.enabled_level_codes = ['1', '2']
  Object.assign(payload.levels['1'], { attendance_mode: 'roster', total: 999, present: 999, attendance: [
    { student_id: '001', full_name: 'คนที่หนึ่ง', present: true, learning_method: 'พบกลุ่ม' },
    { student_id: '002', full_name: 'คนที่สอง', present: false, learning_method: null },
  ] })
  Object.assign(payload.levels['2'], { ...payload.levels['1'], class_name: '', class_group_code: 'G2', attendance_mode: 'counts', total: 5, present: 0 })
  const original = JSON.stringify(payload)
  const summary = teachingSaveSummary(payload, [{ id: 'image', level_code: '2' }])
  assert.equal(summary.length, 2)
  assert.deepEqual(summary.map(({ code, total, present, images, group }) => ({ code, total, present, images, group })), [
    { code: '1', total: 2, present: 1, images: 0, group: 'กลุ่มทดสอบ' },
    { code: '2', total: 5, present: 0, images: 1, group: 'G2' },
  ])
  assert.equal(JSON.stringify(payload), original)
})
