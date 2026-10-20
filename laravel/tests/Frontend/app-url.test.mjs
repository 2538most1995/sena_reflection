import assert from 'node:assert/strict'
import test from 'node:test'
import { normalizeAppBasePath, resolveAppPathname, resolveAppUrl } from '../../resources/js/lib/app-url.ts'

test('root installation keeps existing navigation and relative asset URLs', () => {
  assert.equal(resolveAppUrl('/login'), '/login')
  assert.equal(resolveAppUrl('/'), '/')
  assert.equal(resolveAppUrl('storage/avatar.png'), '/storage/avatar.png')
})

test('subdirectory scopes login, API, images and print URLs without duplicate prefixes', () => {
  const base = '/sena_reflection'
  for (const value of ['/login', '/record-options/students?level=1', '/records/r1/images/2', '/records/r1/print', '/exports/e1/download']) {
    assert.equal(resolveAppUrl(value, base), `${base}${value}`)
    assert.equal(resolveAppUrl(`${base}${value}`, base), `${base}${value}`)
  }
  assert.equal(resolveAppUrl('/', base), '/sena_reflection/')
  assert.equal(resolveAppUrl('/sena_reflection?review=1', base), '/sena_reflection?review=1')
  assert.equal(resolveAppUrl('/sena_reflection2/login', base), '/sena_reflection/sena_reflection2/login')
  assert.equal(resolveAppUrl('storage/photo.png', base), '/sena_reflection/storage/photo.png')
})

test('external, embedded media and fragment URLs are unchanged', () => {
  for (const value of ['', 'https://example.com/photo.png', 'http://example.com', '//cdn.example.com/x', 'data:image/png;base64,x', 'blob:https://example.com/id', '#main-content', '?page=2', 'mailto:teacher@example.com']) {
    assert.equal(resolveAppUrl(value, '/sena_reflection'), value)
  }
})

test('navigation matching strips only this application mount and ignores query parameters', () => {
  assert.equal(resolveAppPathname('/sena_reflection/?week=1', '/sena_reflection'), '/')
  assert.equal(resolveAppPathname('/sena_reflection/records/r1?review=1', '/sena_reflection'), '/records/r1')
  assert.equal(resolveAppPathname('https://krumost.com/sena_reflection/students?page=2', '/sena_reflection'), '/students')
  assert.equal(resolveAppPathname('/sena_reflection2/students', '/sena_reflection'), '/sena_reflection2/students')
})

test('mount normalization accepts empty/root and rejects a URL or traversal as a mount', () => {
  assert.equal(normalizeAppBasePath('/'), '')
  assert.equal(normalizeAppBasePath(' /sena_reflection/ '), '/sena_reflection')
  for (const value of ['https://example.com', '/sena_reflection/../other', '/sena_reflection?x=1']) assert.throws(() => normalizeAppBasePath(value))
})
