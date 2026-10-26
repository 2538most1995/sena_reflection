import type { SubjectOption } from './types'

export function subjectsForLevel(subjects: SubjectOption[], levelCode: string, levelName: string): SubjectOption[] {
  return subjects.filter(subject => !subject.level || subject.level === levelCode || subject.level === levelName)
}

export function searchSubjects(subjects: SubjectOption[], query: string): SubjectOption[] {
  const terms = query.normalize('NFC').trim().toLocaleLowerCase('th').split(/\s+/u).filter(Boolean)
  if (!terms.length) return subjects
  return subjects.filter(subject => {
    const text = `${subject.subject_code} ${subject.subject}`.normalize('NFC').toLocaleLowerCase('th')
    return terms.every(term => text.includes(term))
  })
}
