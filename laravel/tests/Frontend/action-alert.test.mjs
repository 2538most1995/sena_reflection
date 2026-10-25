import assert from 'node:assert/strict'
import test from 'node:test'
import { createActionAlertStore } from '../../resources/js/lib/action-alert.ts'

test('an operation updates its own alert without replacing another concurrent operation', () => {
  const alerts = createActionAlertStore()
  const first = alerts.loading('กำลังบันทึก')
  const second = alerts.loading('กำลังเตรียมไฟล์')

  alerts.success('ส่งไฟล์ให้เบราว์เซอร์แล้ว', 'ตรวจสอบรายการดาวน์โหลด', second)
  alerts.error('บันทึกไม่สำเร็จ', 'กรุณาลองอีกครั้ง', first)

  assert.deepEqual(alerts.getSnapshot(), [
    { id: first, status: 'error', title: 'บันทึกไม่สำเร็จ', description: 'กรุณาลองอีกครั้ง' },
    { id: second, status: 'success', title: 'ส่งไฟล์ให้เบราว์เซอร์แล้ว', description: 'ตรวจสอบรายการดาวน์โหลด' },
  ])
  alerts.dismiss(first)
  assert.equal(alerts.getSnapshot()[0].id, second)
})

test('a late response for a dismissed operation does not reopen or overwrite a newer alert', () => {
  const alerts = createActionAlertStore()
  const dismissed = alerts.loading('กำลังบันทึกครั้งแรก')
  alerts.dismiss(dismissed)
  const current = alerts.loading('กำลังบันทึกครั้งใหม่')
  const snapshot = alerts.getSnapshot()

  alerts.success('บันทึกครั้งแรกสำเร็จ', undefined, dismissed)
  alerts.error('ไม่สำเร็จ', undefined, 'unknown')

  assert.equal(alerts.getSnapshot(), snapshot)
  assert.equal(alerts.getSnapshot()[0].id, current)
})

test('subscribers receive stable immutable snapshots only when a visible alert changes', () => {
  const alerts = createActionAlertStore()
  let updates = 0
  const unsubscribe = alerts.subscribe(() => updates++)
  const empty = alerts.getSnapshot()
  const id = alerts.info('เปิดหน้าต่างพิมพ์แล้ว')
  const active = alerts.getSnapshot()

  assert.deepEqual(empty, [])
  assert.equal(alerts.getSnapshot(), active)
  alerts.dismiss('unknown')
  assert.equal(updates, 1)
  alerts.dismiss()
  assert.deepEqual(alerts.getSnapshot(), [])
  assert.equal(active[0].id, id)
  assert.equal(updates, 2)
  unsubscribe()
  alerts.success('บันทึกแล้ว')
  assert.equal(updates, 2)
})
