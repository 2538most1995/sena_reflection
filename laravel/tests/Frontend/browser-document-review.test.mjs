import assert from 'node:assert/strict'
import test from 'node:test'
import { draftRequestError, waitForBrowserDocument } from '../../resources/js/lib/browser-document-review.ts'

function documentFixture({ missing = false, pages = true, fonts = [], images = [] } = {}) {
  fonts.ready = Promise.resolve()
  return { fonts, images, querySelector: selector => selector === '.page' ? (pages ? {} : null) : (missing ? {} : null) }
}

test('browser review waits for both fonts and decoded images before approval', async () => {
  let resolveFonts
  let resolveImage
  const doc = documentFixture({ images: [{ complete: true, naturalWidth: 300, decode: () => new Promise(resolve => { resolveImage = resolve }) }] })
  doc.fonts.ready = new Promise(resolve => { resolveFonts = resolve })
  let approved = false
  const pending = waitForBrowserDocument(doc).then(() => { approved = true })
  await Promise.resolve()
  assert.equal(approved, false)
  resolveFonts()
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(approved, false)
  resolveImage()
  await pending
  assert.equal(approved, true)
})

test('empty pages, missing images, broken fonts and failed image decode never approve review', async () => {
  for (const doc of [
    null,
    documentFixture({ pages: false }),
    documentFixture({ missing: true }),
    documentFixture({ fonts: [{ status: 'error' }] }),
    documentFixture({ images: [{ complete: true, naturalWidth: 0 }] }),
    documentFixture({ images: [{ complete: true, naturalWidth: 300, decode: async () => { throw new Error('decode failed') } }] }),
  ]) await assert.rejects(waitForBrowserDocument(doc))
})

test('an unresponsive font load times out instead of unlocking finalization', async t => {
  t.mock.timers.enable({ apis: ['setTimeout'] })
  const doc = documentFixture()
  doc.fonts.ready = new Promise(() => {})
  const result = assert.rejects(waitForBrowserDocument(doc), /นานเกินไป/)
  t.mock.timers.tick(20000)
  await result
})

test('non-JSON proxy errors explain recovery without exposing HTML diagnostics', () => {
  assert.match(draftRequestError(503), /ร่างยังอยู่/)
  assert.match(draftRequestError(419), /เซสชันหมดอายุ/)
  assert.match(draftRequestError(413), /ขนาดเกิน/)
  assert.match(draftRequestError(429), /รอสักครู่/)
})
