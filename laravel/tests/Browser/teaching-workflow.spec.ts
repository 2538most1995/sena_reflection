import { randomUUID } from 'node:crypto'
import { mkdir } from 'node:fs/promises'
import { test, expect, type Page } from '@playwright/test'
import { startWorkflowFixture, workflowBaseUrl, workflowCredentials, type WorkflowFixture } from './helpers/workflow-fixture'

type Draft = { id: string; lock_version: number; payload: { enabled_level_codes: string[]; levels: Record<string, { subject: string; result: string; present: number; total: number; attendance: { present: boolean }[] }> } }

async function draftFromPage(page: Page) {
  const id = new URL(page.url()).searchParams.get('draft')
  expect(id).toBeTruthy()
  const response = await page.request.get(`/teaching-drafts/${id}`, { headers: { Accept: 'application/json' } })
  expect(response.status()).toBe(200)
  return (await response.json()).data.draft as Draft
}

async function onlyLevels(page: Page, codes: string[]) {
  for (const [code, name] of [['1', 'ประถมศึกษา'], ['2', 'มัธยมศึกษาตอนต้น'], ['3', 'มัธยมศึกษาตอนปลาย']]) {
    const toggle = page.getByRole('switch', { name: `สอนระดับ${name}`, exact: true })
    if ((await toggle.isChecked()) !== codes.includes(code)) await toggle.click()
  }
}

async function fillLevel(page: Page, code: '1' | '3', subject: string) {
  const navigation = page.getByRole('navigation', { name: 'ระดับชั้นในชุดงาน', exact: true })
  await navigation.getByRole('button').filter({ hasText: code === '1' ? 'ประถม' : 'ม.ปลาย' }).click()
  await page.getByRole('combobox', { name: 'เลือกกลุ่มเรียน', exact: true }).selectOption({ index: 1 })
  const first = page.getByRole('checkbox', { name: new RegExp(`นักศึกษาทดสอบ ${code}-1`) })
  const second = page.getByRole('checkbox', { name: new RegExp(`นักศึกษาทดสอบ ${code}-2`) })
  await expect(first).toBeVisible()
  await expect(first).not.toBeChecked()
  await expect(second).not.toBeChecked()
  await expect(page.getByText('มาเรียน 0 จาก 2 คน', { exact: true })).toBeVisible()
  await first.check()
  await page.getByRole('textbox', { name: 'ชื่อรายวิชา *', exact: true }).fill(subject)
  await page.getByRole('textbox', { name: 'ผลการจัดการเรียนรู้ *', exact: true }).fill(`ผลการเรียนรู้ภาษาไทย ${subject}`)
  await expect(page.getByRole('button', { name: /ยืนยันตรวจรายชื่อ|ยืนยันว่าตรวจยอด/ })).toHaveCount(0)
}

test.describe('Simplified teaching workflow with its own SQLite and private storage', () => {
  test.skip(process.env.SENA_WORKFLOW_E2E !== '1', 'Opt in with SENA_WORKFLOW_E2E=1; this suite creates and removes its own loopback SQLite fixture.')
  test.use({ baseURL: workflowBaseUrl })
  let fixture: WorkflowFixture
  const previewRequests = new WeakMap<Page, string[]>()

  test.beforeAll(async () => {
    test.setTimeout(60_000)
    fixture = await startWorkflowFixture()
    await mkdir('output/playwright', { recursive: true })
  })
  test.afterAll(async () => { await fixture?.close() })
  test.beforeEach(async ({ page }) => {
    previewRequests.set(page, [])
    page.on('request', request => {
      if (request.method() === 'POST' && /\/(?:preview|pdf-preview|browser-preview)$/.test(new URL(request.url()).pathname)) previewRequests.get(page)!.push(request.url())
    })
    page.on('dialog', async dialog => {
      try { if (dialog.type() === 'beforeunload') await dialog.accept(); else await dialog.dismiss() }
      catch (error) { if (!/Not attached to an active page|has been closed/.test(String(error))) throw error }
    })
    await page.goto('/login')
    await page.getByRole('textbox', { name: 'ชื่อผู้ใช้', exact: true }).fill(workflowCredentials.user)
    await page.getByRole('textbox', { name: 'รหัสผ่าน', exact: true }).fill(workflowCredentials.password)
    await page.getByRole('button', { name: 'เข้าสู่ระบบ', exact: true }).click()
    await expect(page).not.toHaveURL(/\/login$/)
    await page.goto('/records/create')
  })

  test('missing data focuses the required field in the editor without opening a PDF or confirming', async ({ page }) => {
    await onlyLevels(page, ['1'])
    await page.getByRole('button', { name: 'บันทึก 1 ระดับ', exact: true }).last().click()
    await expect(page.getByRole('combobox', { name: 'สัปดาห์ *', exact: true })).toBeFocused()
    await expect(page.getByRole('dialog')).toHaveCount(0)
    await page.getByRole('combobox', { name: 'สัปดาห์ *', exact: true }).selectOption('6')
    await fillLevel(page, '1', `E2E focus ${randomUUID()}`)
    const result = page.getByRole('textbox', { name: 'ผลการจัดการเรียนรู้ *', exact: true })
    await result.fill('')
    await page.getByRole('button', { name: 'บันทึก 1 ระดับ', exact: true }).last().click()
    await expect(result).toBeFocused()
    await expect.poll(() => result.evaluate(element => { const rect = element.getBoundingClientRect(); return rect.top >= 64 && rect.bottom <= window.innerHeight })).toBe(true)
    await page.screenshot({ path: 'output/playwright/teaching-workflow-required-focus.png' })
    expect(previewRequests.get(page)).toEqual([])
    expect(fixture.records()).toEqual([])
  })

  test('canceling the single confirmation on mobile retains the autosaved draft after reload', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await onlyLevels(page, ['1'])
    await page.getByRole('combobox', { name: 'สัปดาห์ *', exact: true }).selectOption('7')
    const subject = `E2E mobile ${randomUUID()}`
    await fillLevel(page, '1', subject)
    await page.getByRole('button', { name: 'บันทึก 1 ระดับ', exact: true }).last().click()
    const confirmation = page.getByRole('dialog', { name: 'ยืนยันบันทึกประจำสัปดาห์', exact: true })
    await expect(confirmation).toBeVisible()
    await expect(confirmation).toContainText(subject)
    await expect(confirmation.getByRole('button', { name: 'ยืนยันบันทึก 1 ระดับ', exact: true })).toBeEnabled()
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true).catch(async error => {
      console.log('Mobile overflow diagnostics', await page.evaluate(() => ({ viewport: innerWidth, rootWidth: document.documentElement.scrollWidth, offenders: [...document.querySelectorAll('*')].map(element => ({ element, rect: element.getBoundingClientRect() })).filter(({ rect }) => rect.width > 0 && rect.height > 0 && rect.right > innerWidth + 1).slice(-15).map(({ element, rect }) => ({ tag: element.tagName, class: element.className, text: element.textContent?.slice(0, 80), left: rect.left, right: rect.right, width: rect.width })) })))
      throw error
    })
    await page.screenshot({ path: 'output/playwright/teaching-workflow-confirm-mobile.png' })
    await confirmation.getByRole('button', { name: /^(ยกเลิก|กลับไปแก้ไข)$/ }).click()
    await expect(confirmation).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'บันทึก 1 ระดับ', exact: true })).toBeFocused()
    await expect.poll(() => new URL(page.url()).searchParams.get('draft')).toBeTruthy()
    await expect.poll(async () => (await draftFromPage(page)).payload.levels['1'].subject).toBe(subject)
    await expect(page.getByRole('status').filter({ hasText: 'บันทึกล่าสุด' })).toBeVisible()
    const saved = await draftFromPage(page)
    expect(saved.payload.levels['1'].subject).toBe(subject)
    expect(saved.payload.levels['1'].present).toBe(1)
    expect(saved.payload.enabled_level_codes).toEqual(['1'])
    await page.reload()
    await expect(page.getByRole('textbox', { name: 'ชื่อรายวิชา *', exact: true })).toHaveValue(subject)
    await expect(page.getByRole('textbox', { name: 'ผลการจัดการเรียนรู้ *', exact: true })).toHaveValue(`ผลการเรียนรู้ภาษาไทย ${subject}`)
    expect(fixture.records()).toEqual([])
    expect(previewRequests.get(page)).toEqual([])
  })

  test('one confirmation saves only the selected two levels with zero PDF previews and explicit attendance', async ({ page }) => {
    await onlyLevels(page, ['1', '3'])
    await page.getByRole('combobox', { name: 'สัปดาห์ *', exact: true }).selectOption('8')
    const marker = randomUUID()
    const subjects = [`E2E ประถม ${marker}`, `E2E ม.ปลาย ${marker}`]
    await fillLevel(page, '1', subjects[0])
    await fillLevel(page, '3', subjects[1])
    await page.getByRole('button', { name: 'บันทึก 2 ระดับ', exact: true }).last().click()
    const confirmation = page.getByRole('dialog', { name: 'ยืนยันบันทึกประจำสัปดาห์', exact: true })
    await expect(confirmation).toBeVisible()
    await expect(confirmation).toContainText(subjects[0])
    await expect(confirmation).toContainText(subjects[1])
    await expect(confirmation).not.toContainText('มัธยมศึกษาตอนต้น')
    await page.screenshot({ path: 'output/playwright/teaching-workflow-confirm-desktop.png' })
    const completed = page.waitForResponse(response => /\/teaching-drafts\/[^/]+\/finalize$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST')
    await confirmation.getByRole('button', { name: 'ยืนยันบันทึก 2 ระดับ', exact: true }).click()
    const response = await completed
    expect(response.request().postDataJSON().confirmed).toBe(true)
    expect(response.status()).toBe(200)
    const records = fixture.records()
    expect(records).toHaveLength(2)
    await expect.poll(() => new URL(page.url()).searchParams.get('week')).toBe('8')
    expect(new URL(page.url()).pathname).toBe('/')
    expect(records.map(record => record.subject)).toEqual(expect.arrayContaining(subjects))
    expect(records.map(record => record.level)).toEqual(expect.arrayContaining(['ประถมศึกษา', 'มัธยมศึกษาตอนปลาย']))
    expect(records.some(record => record.level === 'มัธยมศึกษาตอนต้น')).toBe(false)
    for (const record of records) {
      expect(record.total).toBe(2)
      expect(record.present).toBe(1)
      expect(record.attendance.filter(row => row.present)).toHaveLength(1)
    }
    expect(previewRequests.get(page)).toEqual([])
  })

  test('district edits 26 legacy students and four photos, keeps its draft after a server failure, then retries', async ({ page }) => {
    const sourceId = fixture.seedLegacyRecord()
    const original = fixture.records().find(record => record.id === sourceId)!
    expect(original.images).toHaveLength(4)
    await page.context().clearCookies()
    await page.goto('/login')
    await page.getByRole('textbox', { name: 'ชื่อผู้ใช้', exact: true }).fill('workflow-district')
    await page.getByRole('textbox', { name: 'รหัสผ่าน', exact: true }).fill(workflowCredentials.password)
    await page.getByRole('button', { name: 'เข้าสู่ระบบ', exact: true }).click()
    await expect(page).not.toHaveURL(/\/login$/)
    await page.goto(`/records/${sourceId}/edit`)
    await expect(page.getByRole('textbox', { name: 'ผลการจัดการเรียนรู้ *', exact: true })).toHaveValue('ผลการสอนเดิม')
    const result = `แก้ผลการสอน ${randomUUID()}`
    await page.getByRole('textbox', { name: 'ผลการจัดการเรียนรู้ *', exact: true }).fill(result)
    await page.getByRole('button', { name: 'บันทึก 1 ระดับ', exact: true }).last().click()
    const confirmation = page.getByRole('dialog', { name: 'ยืนยันบันทึกประจำสัปดาห์', exact: true })
    await expect(confirmation).toContainText('มาเรียน 7/26 คน')
    await expect(confirmation).toContainText('4 รูป')
    expect(fixture.records().find(record => record.id === sourceId)?.images).toEqual(original.images)
    const endpoint = '**/teaching-drafts/*/finalize'
    await page.route(endpoint, route => route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'SQLSTATE[22007]: private database diagnostic' }) }))
    await confirmation.getByRole('button', { name: 'ยืนยันบันทึก 1 ระดับ', exact: true }).click()
    await expect(confirmation.getByRole('alert')).toContainText('ระบบบันทึกขัดข้องชั่วคราว')
    await expect(confirmation).not.toContainText('SQLSTATE')
    expect(fixture.records().find(record => record.id === sourceId)?.images).toEqual(original.images)
    await page.unroute(endpoint)
    const completed = page.waitForResponse(response => /\/teaching-drafts\/[^/]+\/finalize$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST')
    await confirmation.getByRole('button', { name: 'ยืนยันบันทึก 1 ระดับ', exact: true }).click()
    expect((await completed).status()).toBe(200)
    await expect.poll(() => new URL(page.url()).searchParams.get('week')).toBe('16')
    const saved = fixture.records().find(record => record.id === sourceId)!
    expect(saved.total).toBe(26)
    expect(saved.present).toBe(7)
    expect(saved.attendance.filter(row => row.present)).toHaveLength(7)
    expect(saved.images).toHaveLength(4)
    expect(saved.images.every(image => image.id.startsWith('i'))).toBe(true)
    expect(fixture.records().filter(record => record.id === sourceId)).toHaveLength(1)
    expect(previewRequests.get(page)).toEqual([])
  })

})
