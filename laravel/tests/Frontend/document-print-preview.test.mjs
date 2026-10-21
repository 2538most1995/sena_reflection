import assert from 'node:assert/strict'
import test from 'node:test'
import { captureDocumentForPrint } from '../../resources/js/lib/document-print-preview.ts'

function captureFixture() {
  const listeners = new Set()
  let timeout
  let request
  globalThis.window = {
    setTimeout: callback => { timeout = callback; return 1 },
    clearTimeout: () => { timeout = undefined },
    addEventListener: (_, callback) => listeners.add(callback),
    removeEventListener: (_, callback) => listeners.delete(callback),
  }
  const child = { postMessage: message => { request = message } }
  const pending = captureDocumentForPrint({ contentWindow: child })
  return { child, pending, listeners, request, expire: () => timeout(), send: (source, data) => { for (const listener of listeners) listener({ source, data }) } }
}

test('print capture accepts only the editor frame and matching request, then cleans up', async () => {
  const fixture = captureFixture()
  const result = { source: 'sena-document-print-result', requestId: fixture.request.requestId, html: '<!doctype html><html><body>current geometry</body></html>' }
  let resolved = false
  fixture.pending.then(() => { resolved = true })
  fixture.send({}, result)
  fixture.send(fixture.child, { ...result, requestId: 'previous-request' })
  await Promise.resolve()
  assert.equal(resolved, false)
  fixture.send(fixture.child, result)
  assert.equal(await fixture.pending, result.html)
  assert.equal(fixture.listeners.size, 0)
})

test('invalid capture and an unresponsive frame reject instead of printing a stale server copy', async () => {
  const malformed = captureFixture()
  malformed.send(malformed.child, { source: 'sena-document-print-result', requestId: malformed.request.requestId, html: null })
  await assert.rejects(malformed.pending, /อ่านหน้ากระดาษไม่สำเร็จ/)
  assert.equal(malformed.listeners.size, 0)
  const stalled = captureFixture()
  stalled.expire()
  await assert.rejects(stalled.pending, /ตัวอย่างยังไม่พร้อม/)
  assert.equal(stalled.listeners.size, 0)
})
