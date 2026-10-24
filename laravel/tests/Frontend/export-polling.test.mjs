import assert from 'node:assert/strict'
import test from 'node:test'
import { createExportPoller, exportRetrySeconds } from '../../resources/js/lib/export-polling.ts'

const flush = () => new Promise((resolve) => setImmediate(resolve))

function harness(load) {
  let visible = true
  const timers = new Map()
  let nextId = 0
  const poller = createExportPoller({
    load,
    visible: () => visible,
    schedule: (callback, delay) => { const id = ++nextId; timers.set(id, { callback, delay }); return id },
    cancel: (id) => timers.delete(id),
  })
  return { poller, timers, visibility: (value) => { visible = value }, tick: () => {
    const [id, timer] = timers.entries().next().value
    timers.delete(id)
    timer.callback()
  } }
}

test('Retry-After accepts seconds and HTTP dates with a safe default', () => {
  assert.equal(exportRetrySeconds('45'), 45)
  assert.equal(exportRetrySeconds('0'), 1)
  assert.equal(exportRetrySeconds('Sat, 24 Oct 2026 06:00:15 GMT', Date.parse('2026-10-24T06:00:00Z')), 15)
  assert.equal(exportRetrySeconds(null), 10)
  assert.equal(exportRetrySeconds('invalid'), 10)
})

test('a slow status response never overlaps requests, including repeated refreshes', async () => {
  let calls = 0
  let resolve
  const fixture = harness(() => { calls++; return new Promise((done) => { resolve = done }) })
  fixture.poller.refresh()
  fixture.poller.refresh()
  fixture.poller.refresh()
  assert.equal(calls, 1)
  resolve(true)
  await flush()
  assert.equal(fixture.timers.size, 1)
  assert.equal([...fixture.timers.values()][0].delay, 0)
  fixture.tick()
  assert.equal(calls, 2)
  fixture.poller.dispose()
  resolve(true)
  await flush()
  assert.equal(fixture.timers.size, 0)
})

test('settled exports stop polling and returning to a visible page refreshes once', async () => {
  let calls = 0
  const fixture = harness(async () => { calls++; return false })
  fixture.poller.refresh()
  await flush()
  assert.equal(calls, 1)
  assert.equal(fixture.timers.size, 0)
  fixture.visibility(false)
  fixture.poller.pause()
  fixture.poller.refresh()
  assert.equal(calls, 1)
  fixture.visibility(true)
  fixture.poller.refresh()
  await flush()
  assert.equal(calls, 2)
  assert.equal(fixture.timers.size, 0)
})

test('active exports pause while hidden and continue when visible', async () => {
  let calls = 0
  const fixture = harness(async () => { calls++; return true })
  fixture.poller.refresh()
  await flush()
  assert.equal([...fixture.timers.values()][0].delay, 3000)
  fixture.visibility(false)
  fixture.poller.pause()
  assert.equal(fixture.timers.size, 0)
  fixture.visibility(true)
  fixture.poller.refresh()
  await flush()
  assert.equal(calls, 2)
  fixture.poller.dispose()
  assert.equal(fixture.timers.size, 0)
})

test('temporary status failures back off and a successful request restores the interval', async () => {
  let failing = true
  const fixture = harness(async () => { if (failing) throw new Error('offline'); return true })
  fixture.poller.refresh()
  await flush()
  assert.equal([...fixture.timers.values()][0].delay, 6000)
  fixture.tick()
  await flush()
  assert.equal([...fixture.timers.values()][0].delay, 12000)
  failing = false
  fixture.tick()
  await flush()
  assert.equal([...fixture.timers.values()][0].delay, 3000)
  fixture.poller.dispose()
})
