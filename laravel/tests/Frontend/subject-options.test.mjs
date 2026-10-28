import assert from 'node:assert/strict'
import test from 'node:test'
import { searchSubjects, subjectsForLevel } from '../../resources/js/components/teaching-workspace/subject-options.ts'

const subjects = [
  { subject: 'คุณธรรมจริยธรรมในการใช้สื่อสังคมออนไลน์', subject_code: 'สค020035', level: '', source: 'sdl' },
  { subject: 'ภาษาไทย', subject_code: 'พท11001', level: '1', source: 'history' },
  { subject: 'ภาษาอังกฤษเพื่อการสื่อสาร', subject_code: 'EN21001', level: 'มัธยมศึกษาตอนต้น', source: 'history' },
  { subject: 'คณิตศาสตร์', subject_code: 'พค31001', level: '3', source: 'history' },
]

test('subjects without verified level metadata are excluded without guessing from course codes', () => {
  for (const [code, name] of [['1', 'ประถมศึกษา'], ['2', 'มัธยมศึกษาตอนต้น'], ['3', 'มัธยมศึกษาตอนปลาย']]) {
    assert.equal(subjectsForLevel(subjects, code, name).includes(subjects[0]), false)
  }
  assert.deepEqual(subjectsForLevel(subjects, '2', 'มัธยมศึกษาตอนต้น'), [subjects[2]])
})

test('subject search matches Thai names, partial codes, Latin case and multiple search terms', () => {
  assert.deepEqual(searchSubjects(subjects, 'สื่อสังคม'), [subjects[0]])
  assert.deepEqual(searchSubjects(subjects, 'en21'), [subjects[2]])
  assert.deepEqual(searchSubjects(subjects, '  en21   สื่อสาร '), [subjects[2]])
  assert.deepEqual(searchSubjects(subjects, 'วิชาที่ไม่มี'), [])
})

test('filtering preserves option identity for retaining a selected course while search results change', () => {
  const selected = subjects[3]
  const originalSubjects = structuredClone(subjects)
  const levelSubjects = subjectsForLevel(subjects, '3', 'มัธยมศึกษาตอนปลาย')
  assert.equal(searchSubjects(levelSubjects, 'พค310')[0], selected)
  assert.equal(searchSubjects(levelSubjects, 'ไม่มีวิชา').includes(selected), false)
  assert.equal(searchSubjects(levelSubjects, '')[0], selected)
  assert.deepEqual(subjects, originalSubjects)
})
