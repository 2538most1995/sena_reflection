import type { SubjectOption } from './types'

export function subjectsForLevel(subjects: SubjectOption[], levelCode: string, levelName: string): SubjectOption[] {
  return subjects.filter(subject => subject.level === levelCode || subject.level === levelName)
}

export function searchSubjects(subjects: SubjectOption[], query?: string | null): SubjectOption[] {
  const terms = (query || '').normalize('NFC').trim().toLocaleLowerCase('th').split(/\s+/u).filter(Boolean)
  if (!terms.length) return subjects
  return subjects.filter(subject => {
    const text = `${subject?.subject_code || ''} ${subject?.subject || ''}`.normalize('NFC').toLocaleLowerCase('th')
    return terms.every(term => text.includes(term))
  })
}

/** Orientation is a local activity available even when SDL has no course catalog. */
export function withOrientationSubject(subjects: SubjectOption[], levelCode: string): SubjectOption[] {
  if (subjects.some(subject => subject.subject.trim() === 'ปฐมนิเทศ' && !subject.subject_code.trim())) return subjects
  return [{ subject: 'ปฐมนิเทศ', subject_code: '', level: levelCode, source: 'activity' }, ...subjects]
}
