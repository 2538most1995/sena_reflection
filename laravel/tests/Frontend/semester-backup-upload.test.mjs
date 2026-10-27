import assert from 'node:assert/strict'
import test from 'node:test'
import { uploadSemesterZip } from '../../resources/js/lib/semester-backup-upload.ts'

const id = 'c31505da-5547-4ab4-9ff3-d720243398ce'
const bytes = new Uint8Array(150000).map((_, i) => i % 251)
const file = new File([bytes], 'semester.zip', { type: 'application/zip' })
const session = { id, chunk_size: 65536, parts: 3, uploaded_parts: 0 }

test('a complete ZIP uploads sequential bounded slices before the server verifies the whole file', async () => {
  const sent = [], progress = []
  let active = 0
  const result = await uploadSemesterZip(file, async (path, init) => {
    assert.equal(active++, 0)
    try {
      if (path.endsWith('/uploads')) return session
      if (path.endsWith('/finish')) { assert.equal(sent.length, 3); return { backup: { id: 'verified' } } }
      const chunk = init.body.get('chunk')
      assert.ok(chunk.size <= 65536)
      sent.push(new Uint8Array(await chunk.arrayBuffer()))
      return { uploaded_parts: sent.length }
    } finally { active-- }
  }, (done, total) => progress.push([done, total]))
  assert.deepEqual(new Uint8Array(await new Blob(sent).arrayBuffer()), bytes)
  assert.deepEqual(progress, [[0, 3], [1, 3], [2, 3], [3, 3]])
  assert.deepEqual(result, { backup: { id: 'verified' } })
})

test('a terminal chunk rejection cancels its own staging session without finalizing or retrying validation failures', async () => {
  const paths = []
  await assert.rejects(uploadSemesterZip(file, async (path, init) => {
    paths.push([path, init.method])
    if (path.endsWith('/uploads')) return session
    if (init.method === 'DELETE') return { cancelled: true }
    throw Object.assign(new Error('checksum rejected'), { status: 422 })
  }, () => {}), /checksum rejected/)
  assert.deepEqual(paths.map(([, method]) => method), ['POST', 'POST', 'DELETE'])
  assert.ok(paths.every(([path]) => !path.endsWith('/finish')))
})

test('invalid chunk metadata cannot cause large reads or requests outside the upload session', async () => {
  const paths = []
  await assert.rejects(uploadSemesterZip(file, async path => {
    paths.push(path)
    return { ...session, chunk_size: 2 ** 31 }
  }, () => {}), /ไม่ถูกต้อง/)
  assert.equal(paths.length, 2)
  assert.ok(paths[1].endsWith(id))
})

test('aborting an upload retains current application data and only cancels the staged session', async () => {
  const controller = new AbortController(), methods = []
  await assert.rejects(uploadSemesterZip(file, async (_path, init) => {
    methods.push(init.method)
    if (methods.length === 1) { controller.abort(); return session }
    return { cancelled: true }
  }, () => {}, controller.signal), { name: 'AbortError' })
  assert.deepEqual(methods, ['POST', 'DELETE'])
})
