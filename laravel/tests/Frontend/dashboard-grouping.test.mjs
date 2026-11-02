import assert from 'node:assert/strict'
import test from 'node:test'
import { toRecordArray, groupRecordsByWeek } from '../../resources/js/lib/dashboard-records.ts'

test('toRecordArray safely converts null, undefined, primitive, object, and array inputs', () => {
  assert.deepEqual(toRecordArray(null), [])
  assert.deepEqual(toRecordArray(undefined), [])
  assert.deepEqual(toRecordArray('not an array'), [])
  assert.deepEqual(toRecordArray(123), [])
  assert.deepEqual(toRecordArray([]), [])
  assert.deepEqual(toRecordArray({}), [])

  const sampleRecord = {
    id: 'rec-1',
    week: 1,
    year: '2569',
    semester: '2',
    tambon: 'บ้านแพน',
    level: 'มัธยมศึกษาตอนต้น',
    subject: 'เศรษฐกิจพอเพียง',
    total: 15,
    present: 4,
    images_count: 2,
  }

  // Sequential array
  assert.deepEqual(toRecordArray([sampleRecord]), [sampleRecord])

  // Non-sequential associative object (PHP json_encode representation of associative collection)
  const associativeObject = { '0': sampleRecord, '2': { ...sampleRecord, id: 'rec-2' } }
  const resultFromObj = toRecordArray(associativeObject)
  assert.equal(resultFromObj.length, 2)
  assert.equal(resultFromObj[0].id, 'rec-1')
  assert.equal(resultFromObj[1].id, 'rec-2')
})

test('groupRecordsByWeek groups records by week and tambon without throwing on any input shape', () => {
  // Non-iterable or empty inputs
  assert.deepEqual(groupRecordsByWeek(null), [])
  assert.deepEqual(groupRecordsByWeek(undefined), [])
  assert.deepEqual(groupRecordsByWeek({}), [])
  assert.deepEqual(groupRecordsByWeek('bad-input'), [])

  // Object representing records
  const records = {
    '0': {
      id: 'r1',
      week: 1,
      year: '2569',
      semester: '2',
      tambon: 'บ้านแพน',
      level: 'มัธยมศึกษาตอนปลาย',
      subject: 'เศรษฐกิจพอเพียง',
      total: 18,
      present: 4,
      images_count: 0,
      date: '2026-10-28',
    },
    '1': {
      id: 'r2',
      week: 1,
      year: '2569',
      semester: '2',
      tambon: 'บ้านแพน',
      level: 'มัธยมศึกษาตอนต้น',
      subject: 'เศรษฐกิจพอเพียง',
      total: 15,
      present: 4,
      images_count: 2,
      date: '2026-10-28',
    },
    '2': {
      id: 'r3',
      week: 1,
      year: '2569',
      semester: '2',
      tambon: 'บ้านแพน',
      level: 'ประถมศึกษา',
      subject: 'สุขศึกษา พลศึกษา',
      total: 1,
      present: 1,
      images_count: 2,
      date: '2026-10-28',
    },
  }

  const groups = groupRecordsByWeek(records)
  assert.equal(groups.length, 1, 'Should group into 1 week group')

  const week1Group = groups[0]
  assert.equal(week1Group.week, 1)
  assert.equal(week1Group.tambon, 'บ้านแพน')
  assert.equal(week1Group.totalStudents, 34)
  assert.equal(week1Group.totalPresent, 9)
  assert.equal(week1Group.totalImages, 4)
  assert.equal(week1Group.records.length, 3)

  // Order should be: ประถมศึกษา (1), มัธยมศึกษาตอนต้น (2), มัธยมศึกษาตอนปลาย (3)
  assert.equal(week1Group.records[0].level, 'ประถมศึกษา')
  assert.equal(week1Group.records[1].level, 'มัธยมศึกษาตอนต้น')
  assert.equal(week1Group.records[2].level, 'มัธยมศึกษาตอนปลาย')
})
