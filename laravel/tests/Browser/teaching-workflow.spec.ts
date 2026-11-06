import { randomUUID } from 'node:crypto'
import { mkdir } from 'node:fs/promises'
import { test, expect, type Page } from '@playwright/test'
import { startWorkflowFixture, workflowBaseUrl, workflowCredentials, type WorkflowFixture } from './helpers/workflow-fixture'

type Draft = { id: string; lock_version: number; payload: { enabled_level_codes: string[]; levels: Record<string, { subject: string; subject_code: string; class_group_code: string; result: string; present: number; total: number; attendance: { present: boolean }[] }> } }

async function draftFromPage(page: Page) {
  await expect.poll(() => new URL(page.url()).searchParams.get('draft')).toBeTruthy()
  const id = new URL(page.url()).searchParams.get('draft')
  expect(id).toBeTruthy()
  const response = await page.request.get(`/teaching-drafts/${id}`, { headers: { Accept: 'application/json' } })
  expect(response.status()).toBe(200)
  return (await response.json()).data.draft as Draft
}

async function closeActionNotice(page: Page, title: string) {
  await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'ปิดการแจ้งเตือน', exact: true }).click()
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
  const manualSubject = page.getByRole('button', { name: 'กรอกรายวิชาเอง', exact: true })
  if (await manualSubject.isVisible()) await manualSubject.click()
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
    fixture?.resetLoginAttempts()
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

  test('dashboard offers one create-record button at desktop and mobile sizes and opens the editor without duplicate options requests', async ({ page }) => {
    await page.goto('/')
    const create = page.locator('a[href="/records/create"]')
    await expect(create).toHaveCount(1)
    await expect(create).toBeVisible()
    await page.setViewportSize({ width: 390, height: 844 })
    await expect(create).toHaveCount(1)
    await expect(create).toBeVisible()
    const requests: string[] = []
    page.on('request', request => {
      const path = new URL(request.url()).pathname
      if (path === '/records/create' || path.startsWith('/record-options/')) requests.push(path)
    })
    await create.click()
    await expect(page).toHaveURL(/\/records\/create$/)
    await expect(page.getByRole('combobox', { name: 'สัปดาห์ *', exact: true })).toBeVisible()
    expect(requests).toEqual(['/records/create'])
    await expect(page.locator('a[href="/records/create"]')).toHaveCount(0)
  })

  test('the subject picker accepts a typed name and optional code without history suggestions and keeps them after reload', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await onlyLevels(page, ['1'])
    await page.getByRole('combobox', { name: 'สัปดาห์ *', exact: true }).selectOption('10')
    await page.route('**/record-options/workspace?**', route => route.fulfill({ json: { status: 'success', data: {
      groups: [{ group_code: 'WORKFLOW-1', class_name: 'กลุ่มทดสอบ ประถมศึกษา', level: '1' }],
      subjects: [{ subject: 'วิชาจากระบบ', subject_code: 'API1', level: '1', source: 'sdl' }, { subject: 'วิชาประวัติที่ไม่ต้องแสดง', subject_code: 'OLD', level: '1', source: 'history' }],
      subject_source: 'sdl', subject_term: '2/2569', subject_error: null, error_code: null,
    } } }))
    const basics = page.locator('section[aria-labelledby="teaching-basics-1"]')
    await basics.getByRole('button', { name: 'อัปเดตรายวิชา', exact: true }).click()
    await closeActionNotice(page, 'อัปเดตรายวิชาจากระบบเรียบร้อยแล้ว')
    await expect(basics.getByRole('button', { name: 'อัปเดตรายวิชา', exact: true })).toBeEnabled()
    await basics.getByRole('button', { name: 'เลือกรายวิชา', exact: true }).click()
    await expect(page.getByRole('option', { name: /วิชาประวัติ/ })).toHaveCount(0)
    await expect(basics.getByText(/ประวัติบันทึก/)).toHaveCount(0)
    const name = `รายวิชากรอกเอง ${randomUUID()}`
    await page.getByRole('searchbox', { name: 'ค้นหาหรือกรอกชื่อรายวิชา', exact: true }).fill(name)
    await page.getByRole('searchbox', { name: 'ค้นหาหรือกรอกชื่อรายวิชา', exact: true }).press('Enter')
    const subject = page.getByRole('textbox', { name: 'ชื่อรายวิชา *', exact: true })
    await expect(subject).toHaveValue(name)
    await expect(subject).toBeFocused()
    await expect(page.getByRole('textbox', { name: 'รหัสวิชา', exact: true })).toHaveValue('')
    await page.getByRole('textbox', { name: 'รหัสวิชา', exact: true }).fill('MANUAL-1')
    await page.getByRole('button', { name: 'เก็บร่างตอนนี้', exact: true }).click()
    await closeActionNotice(page, 'เก็บฉบับร่างแล้ว')
    await expect.poll(async () => (await draftFromPage(page)).payload.levels['1'].subject_code).toBe('MANUAL-1')
    await page.reload()
    await expect(subject).toHaveValue(name)
    await expect(page.getByRole('textbox', { name: 'รหัสวิชา', exact: true })).toHaveValue('MANUAL-1')
    await page.getByRole('button', { name: 'เลือกจากระบบ', exact: true }).click()
    await page.getByRole('button', { name: 'เลือกรายวิชา', exact: true }).click()
    await page.getByRole('listbox', { name: 'รายวิชาหรือกิจกรรม', exact: true }).getByRole('button', { name: 'กรอกรายวิชาเอง', exact: true }).click()
    await expect(subject).toHaveValue(name)
    await expect(page.getByRole('textbox', { name: 'รหัสวิชา', exact: true })).toHaveValue('MANUAL-1')
    await page.screenshot({ path: 'output/playwright/subject-manual-mobile.png', fullPage: false })
  })

  test('switching the active level alone leaves the clean workspace without an unsaved warning', async ({ page }) => {
    const dialogs: string[] = []
    page.on('dialog', dialog => { dialogs.push(dialog.message()) })
    await page.getByRole('navigation', { name: 'ระดับชั้นในชุดงาน', exact: true }).getByRole('button').filter({ hasText: 'ม.ต้น' }).click()
    await page.getByRole('navigation', { name: 'เมนูหลัก', exact: true }).getByRole('link', { name: 'เอกสาร', exact: true }).first().click()
    await expect(page).toHaveURL(/\/documents$/)
    expect(dialogs).toEqual([])
  })

  test('hover prefetch never warns but leaving unsaved work warns once and saving removes the warning', async ({ page }) => {
    const dialogs: string[] = []
    page.on('dialog', dialog => { dialogs.push(dialog.message()) })
    await page.getByRole('button', { name: 'กรอกรายวิชาเอง', exact: true }).click()
    await page.getByRole('textbox', { name: 'ชื่อรายวิชา *', exact: true }).fill('ข้อความที่ยังไม่ได้เก็บร่าง')
    const documents = page.getByRole('navigation', { name: 'เมนูหลัก', exact: true }).getByRole('link', { name: 'เอกสาร', exact: true }).first()
    await Promise.all([page.waitForRequest(request => new URL(request.url()).pathname === '/documents'), documents.hover()])
    expect(dialogs).toEqual([])
    await expect(page).toHaveURL(/\/records\/create$/)
    await documents.click()
    await expect.poll(() => dialogs.length).toBe(1)
    await expect(page).toHaveURL(/\/records\/create$/)
    await page.getByRole('button', { name: 'เก็บร่างตอนนี้', exact: true }).click()
    await closeActionNotice(page, 'เก็บฉบับร่างแล้ว')
    await expect(page.getByRole('status').filter({ hasText: 'บันทึกล่าสุด' })).toBeVisible()
    await page.reload()
    await expect(page.getByRole('textbox', { name: 'ชื่อรายวิชา *', exact: true })).toHaveValue('ข้อความที่ยังไม่ได้เก็บร่าง')
    await documents.click()
    await expect(page).toHaveURL(/\/documents$/)
    expect(dialogs).toHaveLength(1)
  })

  test('catalogue refresh retains the selected group, course and attendance when options disappear or the source fails', async ({ page }) => {
    await onlyLevels(page, ['1'])
    await page.getByRole('combobox', { name: 'สัปดาห์ *', exact: true }).selectOption('11')
    await fillLevel(page, '1', 'รายวิชาที่เลือกไว้')
    await page.getByRole('button', { name: 'เก็บร่างตอนนี้', exact: true }).click()
    await closeActionNotice(page, 'เก็บฉบับร่างแล้ว')
    const before = structuredClone((await draftFromPage(page)).payload.levels['1'])
    let update = 0
    await page.route('**/record-options/workspace?**', route => {
      update++
      if (update === 3) return route.fulfill({ status: 503, json: { message: 'ต้นทางไม่พร้อม' } })
      return route.fulfill({ json: { status: update === 2 ? 'error' : 'success', message: 'โหลดกลุ่มเรียนไม่สำเร็จ', data: {
        groups: [], subjects: update === 2 ? [] : [{ subject: 'รายวิชาใหม่จากระบบ', subject_code: 'NEW1', level: '1', source: 'sdl' }],
        subject_source: update === 2 ? 'unavailable' : 'sdl', subject_term: '2/2569', subject_error: update === 2 ? 'โหลดรายวิชาไม่สำเร็จ' : null, error_code: update === 2 ? 'student_source_unavailable' : null,
      } } })
    })
    for (let refresh = 1; refresh <= 3; refresh++) {
      await page.getByRole('button', { name: 'อัปเดตรายวิชา', exact: true }).click()
      await closeActionNotice(page, refresh === 1 ? 'อัปเดตรายวิชาจากระบบเรียบร้อยแล้ว' : refresh === 2 ? 'อัปเดตข้อมูลยังไม่ครบ' : 'อัปเดตรายวิชาไม่สำเร็จ')
      await expect(page.getByRole('button', { name: 'อัปเดตรายวิชา', exact: true })).toBeEnabled()
      const group = page.getByRole('combobox', { name: 'เลือกกลุ่มเรียน', exact: true })
      await expect(group.locator('option:checked')).toHaveText('กลุ่มทดสอบ ประถมศึกษา (WORKFLOW-1)')
      await expect(page.getByText('มาเรียน 1 จาก 2 คน', { exact: true })).toBeVisible()
      await expect(page.getByRole('textbox', { name: 'ชื่อรายวิชา *', exact: true })).toHaveValue(before.subject)
      await page.getByRole('button', { name: 'เลือกจากระบบ', exact: true }).click()
      await page.getByRole('button', { name: 'เลือกรายวิชา', exact: true }).click()
      await expect(page.getByRole('option', { name: 'NEW1 - รายวิชาใหม่จากระบบ', exact: true })).toBeVisible()
      await page.keyboard.press('Escape')
      await page.getByRole('button', { name: 'กรอกรายวิชาเอง', exact: true }).click()
      expect((await draftFromPage(page)).payload.levels['1']).toEqual(before)
    }
  })

  test('an empty roster refresh keeps existing checked students instead of silently erasing them', async ({ page }) => {
    await onlyLevels(page, ['1'])
    await page.getByRole('combobox', { name: 'สัปดาห์ *', exact: true }).selectOption('12')
    await fillLevel(page, '1', 'รายวิชาทดสอบรายชื่อว่าง')
    await page.getByRole('button', { name: 'เก็บร่างตอนนี้', exact: true }).click()
    await closeActionNotice(page, 'เก็บฉบับร่างแล้ว')
    const before = structuredClone((await draftFromPage(page)).payload.levels['1'])
    await page.route('**/record-options/students?**', route => route.fulfill({ json: { status: 'success', data: { items: [], source: 'student_api' } } }))
    await page.getByRole('button', { name: 'อัปเดตรายชื่อจากกลุ่มนี้', exact: true }).click()
    await expect(page.getByRole('alert')).toContainText('รายชื่อและสถานะเช็กชื่อเดิมยังคงอยู่')
    await expect(page.getByText('มาเรียน 1 จาก 2 คน', { exact: true })).toBeVisible()
    expect((await draftFromPage(page)).payload.levels['1']).toEqual(before)
  })

  test('changing terms rapidly ignores the late catalogue and reloads options when returning to the original term', async ({ page }) => {
    let releaseOld!: () => void
    let oldStarted!: () => void
    const waiting = new Promise<void>(resolve => { releaseOld = resolve })
    const started = new Promise<void>(resolve => { oldStarted = resolve })
    await page.route('**/record-options/workspace?**', async route => {
      const term = new URL(route.request().url()).searchParams.get('semester')
      if (term === '1') { oldStarted(); await waiting }
      await route.fulfill({ json: { status: 'success', data: {
        groups: [{ group_code: `TERM-${term}`, class_name: `กลุ่มภาคเรียน ${term}`, level: '1' }],
        subjects: [{ subject: `วิชาภาคเรียน ${term}`, subject_code: `TERM-${term}`, level: '1', source: 'sdl' }],
        subject_source: 'sdl', subject_term: `${term}/2569`, subject_error: null, error_code: null,
      } } }).catch(() => undefined)
    })
    const semester = page.getByRole('combobox', { name: 'ภาคเรียน *', exact: true })
    await semester.selectOption('1')
    await started
    await semester.selectOption('2')
    await expect(page.getByRole('button', { name: 'อัปเดตรายวิชา', exact: true })).toBeEnabled()
    releaseOld()
    await expect(page.getByRole('combobox', { name: 'เลือกกลุ่มเรียน', exact: true }).getByRole('option', { name: 'กลุ่มภาคเรียน 2 (TERM-2)', exact: true })).toHaveCount(1)
    await page.getByRole('button', { name: 'เลือกรายวิชา', exact: true }).click()
    await expect(page.getByRole('option', { name: 'TERM-2 - วิชาภาคเรียน 2', exact: true })).toBeVisible()
    await expect(page.getByRole('option', { name: 'TERM-1 - วิชาภาคเรียน 1', exact: true })).toHaveCount(0)
    await page.keyboard.press('Escape')
  })

  test('orientation can be selected without a subject catalog and saves its name without an invented code', async ({ page }) => {
    await onlyLevels(page, ['1'])
    await page.getByRole('combobox', { name: 'สัปดาห์ *', exact: true }).selectOption('4')
    await fillLevel(page, '1', 'ทดสอบตัวเลือกกิจกรรม')
    const basics = page.locator('section[aria-labelledby="teaching-basics-1"]')
    await basics.getByRole('button', { name: 'เลือกจากระบบ', exact: true }).click()
    await basics.getByRole('button', { name: 'เลือกรายวิชา', exact: true }).click()
    await page.getByRole('option', { name: 'ปฐมนิเทศ', exact: true }).click()
    await expect(basics.getByRole('button', { name: 'เลือกรายวิชา', exact: true })).toContainText('ปฐมนิเทศ')
    await page.getByRole('button', { name: 'เก็บร่างตอนนี้', exact: true }).click()
    await expect.poll(async () => (await draftFromPage(page)).payload.levels['1'].subject).toBe('ปฐมนิเทศ')
    expect((await draftFromPage(page)).payload.levels['1'].subject_code || '').toBe('')
  })

  test('a failed roster refresh retains the existing group and checked attendance', async ({ page }) => {
    await onlyLevels(page, ['1'])
    await page.getByRole('combobox', { name: 'สัปดาห์ *', exact: true }).selectOption('5')
    await fillLevel(page, '1', 'ทดสอบรายชื่อคงอยู่')
    await page.getByRole('button', { name: 'เก็บร่างตอนนี้', exact: true }).click()
    await expect.poll(async () => (await draftFromPage(page)).payload.levels['1'].attendance.length).toBe(2)
    const before = structuredClone((await draftFromPage(page)).payload.levels['1'])
    await page.route('**/record-options/students?**', route => route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'ต้นทางไม่พร้อมทดสอบ' }) }))
    await page.getByRole('button', { name: 'อัปเดตรายชื่อจากกลุ่มนี้', exact: true }).click()
    await expect(page.getByRole('alert')).toContainText('ต้นทางไม่พร้อมทดสอบ')
    await expect(page.getByText('มาเรียน 1 จาก 2 คน', { exact: true })).toBeVisible()
    await page.getByRole('button', { name: 'เก็บร่างตอนนี้', exact: true }).click()
    const after = (await draftFromPage(page)).payload.levels['1']
    expect(after.attendance).toEqual(before.attendance)
    expect(after.class_group_code).toBe(before.class_group_code)
    expect(after.present).toBe(before.present)
  })

  test('back saves the latest draft and does not rewrite the destination URL after leaving the form', async ({ page }) => {
    await onlyLevels(page, ['1'])
    await page.getByRole('combobox', { name: 'สัปดาห์ *', exact: true }).selectOption('9')
    const subject = `ก่อนย้อนกลับ ${randomUUID()}`
    await fillLevel(page, '1', subject)
    await page.getByRole('textbox', { name: 'ผลการจัดการเรียนรู้ *', exact: true }).fill('ข้อความล่าสุดก่อนย้อนกลับ')
    await page.getByRole('link', { name: 'ย้อนกลับ', exact: true }).click()
    await expect.poll(() => new URL(page.url()).pathname).toBe('/')
    const response = await page.request.get('/teaching-drafts', { headers: { Accept: 'application/json' } })
    expect(response.status()).toBe(200)
    const summaries = (await response.json()).data.drafts as { id: string }[]
    const drafts: Draft[] = await Promise.all(summaries.map(async item => (await (await page.request.get(`/teaching-drafts/${item.id}`, { headers: { Accept: 'application/json' } })).json()).data.draft))
    const saved = drafts.find(item => item.payload.levels['1'].subject === subject)
    expect(saved?.payload.levels['1'].result).toBe('ข้อความล่าสุดก่อนย้อนกลับ')
    expect(new URL(page.url()).searchParams.has('draft')).toBe(false)
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
    test.setTimeout(90_000)
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
    await expect(page.getByRole('alertdialog')).toContainText('ระบบบันทึกขัดข้องชั่วคราว')
    await page.getByRole('alertdialog').getByRole('button', { name: 'ตกลง', exact: true }).click()
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
    await page.goto(`/records/${sourceId}`)
    const recordUrl = page.url()
    await page.getByRole('link', { name: 'จัดเอกสารก่อนพิมพ์', exact: true }).click()
    await expect(page.getByRole('textbox', { name: 'เนื้อหาเอกสาร', exact: true })).toBeVisible()
    await page.getByRole('link', { name: 'กลับหน้าบันทึก', exact: true }).click()
    await expect(page).toHaveURL(recordUrl)
    const tabCount = page.context().pages().length
    await page.getByRole('link', { name: 'พิมพ์ / บันทึก PDF', exact: true }).click()
    await expect(page.locator('#browser-print-back')).toBeVisible()
    expect(page.context().pages()).toHaveLength(tabCount)
    await page.locator('#browser-print-back').click()
    await expect(page).toHaveURL(recordUrl)
    await page.getByRole('link', { name: 'กลับรายการ', exact: true }).click()
    await expect.poll(() => new URL(page.url()).searchParams.get('week')).toBe('16')
    const filteredList = page.url()
    await page.getByRole('link', { name: 'PDF สัปดาห์นี้', exact: true }).click()
    await expect(page).toHaveURL(/\/exports\/browser-print\?/)
    expect(page.context().pages()).toHaveLength(tabCount)
    await page.getByRole('link', { name: 'กลับรายการ', exact: true }).click()
    await expect(page).toHaveURL(filteredList)
  })

})
