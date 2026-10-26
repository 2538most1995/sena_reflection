import assert from 'node:assert/strict'
import test from 'node:test'
import { browserExportQuery } from '../../resources/js/lib/browser-export-filters.ts'

test('empty applied dashboard fields never become literal null filters in a PDF link', () => {
  const query = browserExportQuery('all', { year: null, semester: null, week: 1, tambon: '1214120011', search: null })
  assert.deepEqual(Object.fromEntries(query), { scope: 'all', week: '1', tambon: '1214120011' })
  assert.equal(browserExportQuery('all', { year: '', semester: undefined, week: null, search: '' }).toString(), 'scope=all')
})

test('weekly and filtered PDF links keep every applied filter and correctly encode the course search', () => {
  const filters = { year: '2569', semester: '1', week: '2', tambon: '1214120011', search: 'ภาษาไทย & การอ่าน + เขียน' }
  for (const scope of ['week', 'all']) {
    const query = browserExportQuery(scope, filters)
    assert.deepEqual(Object.fromEntries(new URLSearchParams(query.toString())), { scope, ...filters })
  }
  assert.equal(filters.search, 'ภาษาไทย & การอ่าน + เขียน')
})

test('only supported dashboard filters propagate and the requested export scope cannot be overwritten', () => {
  const query = browserExportQuery('all', { year: '2569', scope: 'week', page: '3', record_id: 'other-record', signature: 'unrelated' })
  assert.deepEqual(Object.fromEntries(query), { scope: 'all', year: '2569' })
})

test('explicit values remain for backend validation instead of silently broadening the selected scope', () => {
  assert.deepEqual(Object.fromEntries(browserExportQuery('all', { week: 0, search: 'null' })), { scope: 'all', week: '0', search: 'null' })
})
