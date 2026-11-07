import { randomUUID } from 'node:crypto'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { test, expect, type Page } from '@playwright/test'
import JSZip from 'jszip'
import { startWorkflowFixture, workflowBaseUrl, workflowCredentials, type WorkflowFixture } from './helpers/workflow-fixture'

type JsonNode = { type: string; text?: string; attrs?: Record<string, unknown>; marks?: { type: string; attrs?: Record<string, unknown> }[]; content?: JsonNode[] }
type StoredDocument = { id: string; title: string; revision: number; content: JsonNode; objects: { id: string; type: string; x: number; content?: JsonNode }[]; assets: { id: string }[] }
const ownsFixture = process.env.SENA_DOCUMENT_E2E === '1'
const baseUrl = ownsFixture ? workflowBaseUrl : process.env.SENA_E2E_BASE_URL
const isLocalFixture = !!baseUrl && ['127.0.0.1', 'localhost', '[::1]'].includes(new URL(baseUrl).hostname)
const owned = new WeakMap<Page, string[]>()
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jhx0AAAAASUVORK5CYII=', 'base64')

async function api<T>(page: Page, method: string, path: string, body?: unknown): Promise<{ status: number; data: T }> {
  return page.evaluate(async ({ method, path, body }) => {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || ''
    const response = await fetch(path, {
      method, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: body === undefined ? undefined : JSON.stringify(body),
    })
    return { status: response.status, data: await response.json() }
  }, { method, path, body })
}

async function createDocument(page: Page, content?: JsonNode) {
  const result = await api<{ document: StoredDocument }>(page, 'POST', '/api/documents', { title: `E2E ${randomUUID()}`, ...(content ? { content } : {}) })
  expect(result.status).toBe(201)
  owned.get(page)!.push(result.data.document.id)
  await page.goto(`/documents/${result.data.document.id}/edit`)
  await expect(page.getByRole('textbox', { name: 'เนื้อหาเอกสาร', exact: true })).toBeVisible()
  await saved(page)
  return result.data.document
}

async function saved(page: Page) {
  await expect(page.locator('.de-save-status')).toHaveText('บันทึกอัตโนมัติแล้ว')
}

async function uploadImage(page: Page) {
  await page.getByRole('tab', { name: 'แทรก', exact: true }).click()
  const chooserPromise = page.waitForEvent('filechooser')
  await page.getByRole('button', { name: 'รูปภาพ', exact: true }).click()
  const chooser = await chooserPromise
  await chooser.setFiles({ name: `e2e-${randomUUID()}.png`, mimeType: 'image/png', buffer: png })
  const image = page.locator('[data-document-canvas] .document-object').filter({ has: page.locator('img') }).last()
  await expect(image.locator('img')).toBeVisible()
  await expect.poll(() => image.locator('img').evaluate(element => (element as HTMLImageElement).naturalWidth)).toBeGreaterThan(0)
  await saved(page)
  return image
}

test.describe('Web document editor against an isolated Laravel fixture', () => {
  test.use({ baseURL: baseUrl || 'http://127.0.0.1:8765' })
  let fixture: WorkflowFixture | undefined
  let legacyRecordId: string | undefined
  test.beforeAll(async () => {
    if (ownsFixture) { test.setTimeout(60_000); fixture = await startWorkflowFixture() }
  })
  test.afterAll(async () => { await fixture?.close() })
  test.skip(!isLocalFixture, 'Supply SENA_E2E_BASE_URL pointing to a loopback test fixture; these tests never write to a remote deployment.')

  test.beforeEach(async ({ page }) => {
    owned.set(page, [])
    fixture?.resetLoginAttempts()
    await page.addInitScript(() => {
      if (window !== window.top) window.print = () => { document.body.dataset.testPrintCalled = 'true' }
    })
    page.on('dialog', async dialog => { if (dialog.type() === 'beforeunload') await dialog.accept(); else await dialog.dismiss() })
    await page.goto('/login')
    await page.getByRole('textbox', { name: 'ชื่อผู้ใช้', exact: true }).fill(process.env.SENA_E2E_USER || (ownsFixture ? workflowCredentials.user : 'document-demo'))
    await page.getByRole('textbox', { name: 'รหัสผ่าน', exact: true }).fill(process.env.SENA_E2E_PASSWORD || (ownsFixture ? workflowCredentials.password : 'DocumentDemo2026!'))
    await page.getByRole('button', { name: 'เข้าสู่ระบบ', exact: true }).click()
    await expect(page).not.toHaveURL(/\/login$/)
  })

  test.afterEach(async ({ page }) => {
    // Stop autosave before deleting only the IDs created by this test, including conflict copies.
    if (page.isClosed() || !owned.get(page)?.length) return
    await page.goto('/documents')
    for (const id of owned.get(page) || []) {
      const result = await api(page, 'DELETE', `/api/documents/${id}`)
      expect([200, 404]).toContain(result.status)
    }
  })

  test('Enter advances one record line above anchored photos and retains the caption after save and reload', async ({ page }) => {
    const errors: string[] = []
    page.on('pageerror', error => errors.push(error.message))
    const document = await createDocument(page, { type: 'doc', content: [
      { type: 'recordBody', attrs: { field: 'record_result', lineSpacing: 26, minLines: 1 }, content: [{ type: 'paragraph', attrs: { id: 'result-line' }, content: [{ type: 'text', text: 'บรรทัดแรก' }] }] },
      { type: 'paragraph', attrs: { id: 'photo-heading', role: 'recordTitle' }, content: [{ type: 'text', text: 'ภาพประกอบการจัดการเรียนการสอน' }] },
      { type: 'paragraph', content: [{ type: 'text', text: 'หลังรูปกิจกรรม' }] },
    ] })
    await uploadImage(page)
    const current = (await api<{ document: StoredDocument & { pageSettings: Record<string, unknown> } }>(page, 'GET', `/api/documents/${document.id}`)).data.document
    const updated = await api(page, 'PUT', `/api/documents/${document.id}`, {
      revision: current.revision,
      pageSettings: { ...current.pageSettings, template: 'weekly-record', fontFamily: 'THSarabunNew', fontSize: 16 },
      objects: current.objects.map(object => ({ ...object, id: 'record-image-fixture', page: 1, x: 25, y: 40, width: 60, height: 35, wrap: 'square', anchor: { kind: 'paragraph', id: 'photo-heading', offsetY: 16 } })),
    })
    expect(updated.status).toBe(200)
    await page.reload()
    const canvas = page.locator('[data-document-canvas]')
    const caption = canvas.locator('[data-paragraph-id="photo-heading"]')
    const image = canvas.locator('[data-object-id="record-image-fixture"]')
    await expect(caption).toBeVisible()
    await expect(image.locator('img')).toBeVisible()
    const positionAtEnd = async (selector: string) => page.locator(selector).evaluate(element => {
      element.closest<HTMLElement>('[contenteditable=true]')!.focus()
      const range = window.document.createRange()
      range.selectNodeContents(element); range.collapse(false)
      const selection = window.getSelection()!; selection.removeAllRanges(); selection.addRange(range)
      window.document.dispatchEvent(new Event('selectionchange'))
    })
    const cursorLineTop = () => page.evaluate(() => {
      const selection = window.getSelection()!
      const range = selection.getRangeAt(0).cloneRange()
      range.setStart(range.startContainer, Math.max(0, range.startOffset - 1))
      return range.getBoundingClientRect().top
    })
    await positionAtEnd('[data-paragraph-id="photo-heading"]')
    const firstTop = await cursorLineTop()
    const photoTop = (await image.boundingBox())!.y
    await page.keyboard.press('Enter')
    await page.keyboard.insertText('บรรทัดที่สอง')
    const secondTop = await cursorLineTop()
    const scale = await canvas.evaluate(element => element.getBoundingClientRect().width / (element as HTMLElement).offsetWidth)
    expect((secondTop - firstTop) / scale).toBeGreaterThan(15)
    expect((secondTop - firstTop) / scale).toBeLessThan(40)
    await expect(caption.locator('br:not(.ProseMirror-trailingBreak)')).toHaveCount(0)
    await expect(caption.locator('xpath=following-sibling::p[1]')).toContainText('บรรทัดที่สอง')
    await expect.poll(async () => (await image.boundingBox())!.y).toBeCloseTo(photoTop, 0)
    await positionAtEnd('[data-paragraph-id="result-line"]')
    await page.keyboard.press('Enter')
    await page.keyboard.insertText('ต่อเนื้อหาบรรทัดเดียว')
    await saved(page)
    await page.reload()
    await expect(caption.locator('xpath=following-sibling::p[1]')).toContainText('บรรทัดที่สอง')
    await expect(canvas.locator('[data-record-field="record_result"]')).toContainText('ต่อเนื้อหาบรรทัดเดียว')
    const persisted = (await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)).data.document
    const title = persisted.content.content!.find(node => node.attrs?.id === 'photo-heading')!
    expect(title.content!.filter(node => node.type === 'hardBreak')).toHaveLength(0)
    expect(persisted.content.content![persisted.content.content!.indexOf(title) + 1].content).toEqual(expect.arrayContaining([expect.objectContaining({ type: 'text', text: 'บรรทัดที่สอง' })]))
    expect(persisted.objects).toEqual(expect.arrayContaining([expect.objectContaining({ id: 'record-image-fixture', anchor: { kind: 'paragraph', id: 'photo-heading', offsetY: 16 } })]))
    expect(errors).toEqual([])
  })

  test('horizontal ruler previews four indent handles at different zooms, cancels and saves one undoable gesture', async ({ page }, testInfo) => {
    const errors: string[] = []
    page.on('pageerror', error => errors.push(error.message))
    const document = await createDocument(page, { type: 'doc', content: [
      { type: 'recordBody', attrs: { field: 'record_result', firstIndent: 42 }, content: [{ type: 'paragraph', attrs: { id: 'ruler-body' }, content: [{ type: 'text', text: 'ข้อความภาษาไทยสำหรับปรับระยะย่อหน้าและทดสอบการตัดบรรทัด '.repeat(6) }] }] },
      { type: 'paragraph', attrs: { id: 'ruler-untouched' }, content: [{ type: 'text', text: 'ย่อหน้านี้ต้องไม่เปลี่ยน' }] },
    ] })
    const paragraph = page.locator('[data-paragraph-id=ruler-body]')
    await paragraph.click()
    const first = page.getByRole('slider', { name: 'เยื้องบรรทัดแรก', exact: true })
    await expect(first).toBeEnabled()
    const initial = (await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)).data.document
    const drag = async (name: string, mm: number, zoom: number, cancel = false) => {
      const handle = page.getByRole('slider', { name, exact: true })
      await handle.scrollIntoViewIfNeeded()
      const box = (await handle.boundingBox())!
      await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2)
      await page.mouse.down()
      await page.mouse.move(box.x + box.width / 2 + mm * 96 / 25.4 * zoom, box.y + box.height / 2, { steps: 8 })
      await expect(page.locator('.document-indent-guide')).toBeVisible()
      const valueBox = (await page.locator('.document-indent-value').boundingBox())!
      const hostBox = (await page.locator('.de-canvas-host').boundingBox())!
      expect(valueBox.y).toBeGreaterThanOrEqual(hostBox.y)
      await expect(paragraph).toHaveAttribute('style', /text-indent:/)
      if (cancel) await page.keyboard.press('Escape')
      await page.mouse.up()
    }
    await page.getByRole('slider', { name: 'ซูมเอกสาร', exact: true }).fill('0.5')
    await drag('เยื้องบรรทัดแรก', 10, 0.5, true)
    await expect(paragraph).not.toHaveAttribute('data-first-indent', /.+/)
    expect((await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)).data.document.content).toEqual(initial.content)
    await drag('เยื้องบรรทัดแรก', 10, 0.5)
    await expect.poll(() => paragraph.getAttribute('data-first-indent').then(Number)).toBeCloseTo(79.795, 2)
    await page.getByRole('slider', { name: 'ซูมเอกสาร', exact: true }).fill('1.5')
    await drag('เยื้องแบบห้อย', 30, 1.5)
    await expect(paragraph).toHaveAttribute('data-indent', '30')
    await expect.poll(() => paragraph.getAttribute('data-first-indent').then(Number)).toBeCloseTo(-33.591, 2)
    await drag('เยื้องซ้ายทั้งย่อหน้า', 5, 1.5)
    await expect(paragraph).toHaveAttribute('data-indent', '35')
    await drag('เยื้องขวา', -15, 1.5)
    await expect(paragraph).toHaveAttribute('data-right-indent', '15')
    await page.getByRole('button', { name: 'ย้อนกลับ', exact: true }).click()
    await expect(paragraph).not.toHaveAttribute('data-right-indent', /.+/)
    await expect(paragraph).toHaveAttribute('data-indent', '35')
    await page.getByRole('button', { name: 'ทำซ้ำ', exact: true }).click()
    await expect(paragraph).toHaveAttribute('data-right-indent', '15')
    await saved(page)
    const stored = (await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)).data.document
    expect(stored.content.content![1]).toEqual(initial.content.content![1])
    await page.reload()
    await expect(paragraph).toHaveAttribute('data-indent', '35')
    await expect(paragraph).toHaveAttribute('data-right-indent', '15')
    await paragraph.click()
    await page.getByRole('slider', { name: 'ซูมเอกสาร', exact: true }).fill('1')
    await first.focus()
    await page.screenshot({ path: testInfo.outputPath('horizontal-ruler-indents.png'), fullPage: false })
    await page.keyboard.press('Escape')
    await page.getByRole('button', { name: 'พิมพ์ / PDF', exact: true }).click()
    await expect(page.locator('iframe[data-document-print-ready=true]')).toHaveCount(1)
    const print = page.frameLocator('iframe[data-document-print-frame]')
    await expect(print.locator('.document-ruler,.document-indent-guide')).toHaveCount(0)
    await expect(print.locator('[data-paragraph-id=ruler-body]')).toHaveAttribute('data-right-indent', '15')
    const sourceWidth = (await paragraph.boundingBox())!.width
    expect((await print.locator('[data-paragraph-id=ruler-body]').first().boundingBox())!.width).toBeCloseTo(sourceWidth, 0)
    const offsets = await print.locator('[data-paragraph-id=ruler-body]').first().evaluate(element => {
      const style = getComputedStyle(element)
      return { left: parseFloat(style.marginLeft), right: parseFloat(style.marginRight), first: parseFloat(style.textIndent) }
    })
    expect(offsets.left).toBeCloseTo(132.283, 2)
    expect(offsets.right).toBeCloseTo(56.693, 2)
    expect(offsets.first).toBeCloseTo(-33.591, 2)
    const printHandle = await page.locator('iframe[data-document-print-frame]').elementHandle()
    const printDocument = await printHandle!.contentFrame()
    const verification = await page.context().newPage()
    await verification.goto('/')
    await verification.setContent(await printDocument!.content())
    await verification.evaluate(() => window.document.fonts.ready)
    await verification.emulateMedia({ media: 'print' })
    await writeFile('output/pdf/horizontal-ruler-print.html', await verification.content())
    expect((await verification.locator('[data-paragraph-id=ruler-body]').first().boundingBox())!.width).toBeCloseTo(sourceWidth, 0)
    await mkdir('output/pdf', { recursive: true })
    const pdf = await verification.pdf({ path: 'output/pdf/horizontal-ruler-indents.pdf', preferCSSPageSize: true, printBackground: true })
    expect(pdf.subarray(0, 5).toString()).toBe('%PDF-')
    await verification.close()
    expect(errors).toEqual([])
  })

  test('ruler keyboard controls retain a multiple paragraph selection and leave the next paragraph alone', async ({ page }) => {
    const document = await createDocument(page, { type: 'doc', content: ['หนึ่ง', 'สอง', 'สาม'].map((text, index) => ({ type: 'paragraph', attrs: { id: `ruler-multi-${index}` }, content: [{ type: 'text', text }] })) })
    const initial = (await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)).data.document
    await page.locator('[data-paragraph-id=ruler-multi-0]').evaluate(element => {
      element.closest<HTMLElement>('[contenteditable=true]')!.focus()
      const end = window.document.querySelector('[data-paragraph-id=ruler-multi-1]')!
      const range = window.document.createRange()
      range.setStart(element.firstChild!, 0); range.setEnd(end.firstChild!, end.firstChild!.textContent!.length)
      const selection = window.getSelection()!; selection.removeAllRanges(); selection.addRange(range)
      window.document.dispatchEvent(new Event('selectionchange'))
    })
    const left = page.getByRole('slider', { name: 'เยื้องซ้ายทั้งย่อหน้า', exact: true })
    await expect(left).toBeEnabled()
    await left.focus()
    await page.keyboard.press('Home')
    await saved(page)
    expect((await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)).data.document.content).toEqual(initial.content)
    await page.keyboard.press('Shift+ArrowRight')
    for (const index of [0, 1]) await expect(page.locator(`[data-paragraph-id=ruler-multi-${index}]`)).toHaveAttribute('data-indent', '5')
    await expect(page.locator('[data-paragraph-id=ruler-multi-2]')).toHaveAttribute('data-indent', '0')
    await saved(page)
  })

  test('the ruler follows the active textbox and uses its own width without changing body paragraphs', async ({ page }) => {
    const document = await createDocument(page)
    await page.getByRole('tab', { name: 'แทรก', exact: true }).click()
    await page.getByRole('button', { name: 'กล่องข้อความ', exact: true }).click()
    const object = page.locator('[data-document-canvas] .document-object').first()
    await object.dblclick({ position: { x: 30, y: 30 } })
    const text = page.getByRole('textbox', { name: 'ข้อความในกล่องข้อความ', exact: true })
    await text.fill('ข้อความภายในกล่อง')
    const left = page.getByRole('slider', { name: 'เยื้องซ้ายทั้งย่อหน้า', exact: true })
    await expect(left).toBeEnabled()
    await left.focus()
    await page.keyboard.press('Shift+ArrowRight')
    await expect(text.locator('p')).toHaveAttribute('data-indent', '5')
    await expect(page.getByRole('textbox', { name: 'เนื้อหาเอกสาร', exact: true }).locator('p')).toHaveAttribute('data-indent', '0')
    await saved(page)
    const stored = (await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)).data.document
    expect(stored.objects[0].content!.content![0].attrs!.indent).toBe(5)
  })

  test('clicking before a Thai photo caption inside a signature places the caret before its first character at different zooms', async ({ page }, testInfo) => {
    const title = 'ภาพประกอบการจัดการเรียนการสอน'
    const document = await createDocument(page, { type: 'doc', content: [
      { type: 'recordSignature', attrs: { index: 3, width: 430 }, content: [
        { type: 'paragraph', content: [{ type: 'text', text: 'ผู้อำนวยการสถานศึกษา' }] },
        { type: 'paragraph', attrs: { id: 'nested-photo-heading', role: 'recordTitle' }, content: [{ type: 'text', text: title, marks: [{ type: 'bold' }, { type: 'textStyle', attrs: { fontSize: '20pt' } }] }] },
      ] },
      { type: 'paragraph' },
    ] })
    await uploadImage(page)
    const current = (await api<{ document: StoredDocument & { pageSettings: Record<string, unknown> } }>(page, 'GET', `/api/documents/${document.id}`)).data.document
    expect((await api(page, 'PUT', `/api/documents/${document.id}`, {
      revision: current.revision,
      pageSettings: { ...current.pageSettings, template: 'weekly-record', fontFamily: 'THSarabunNew' },
      objects: current.objects.map(object => ({ ...object, page: 1, x: 100, y: 65, width: 60, height: 35, wrap: 'inline', anchor: { kind: 'paragraph', id: 'nested-photo-heading', offsetY: 16 } })),
    })).status).toBe(200)
    await page.reload()
    const caption = page.locator('[data-paragraph-id="nested-photo-heading"]')
    const caret = () => caption.evaluate(element => {
      const selection = window.getSelection()!
      if (!selection.isCollapsed || !element.contains(selection.anchorNode)) return null
      const before = window.document.createRange()
      before.selectNodeContents(element)
      before.setEnd(selection.anchorNode!, selection.anchorOffset)
      return before.toString().length
    })
    for (const zoomIn of [0, 4]) {
      for (let step = 0; step < zoomIn; step++) await page.getByRole('button', { name: 'ซูมเข้า', exact: true }).click()
      await caption.scrollIntoViewIfNeeded()
      await page.evaluate(() => window.document.fonts.ready)
      const first = await caption.evaluate(element => {
        const text = window.document.createTreeWalker(element, NodeFilter.SHOW_TEXT).nextNode()!
        const range = window.document.createRange()
        range.setStart(text, 0); range.setEnd(text, 1)
        const rect = range.getBoundingClientRect()
        return { left: rect.left, y: (rect.top + rect.bottom) / 2 }
      })
      await page.mouse.click(first.left - 2, first.y)
      await expect.poll(caret).toBe(0)
      if (!zoomIn) await page.screenshot({ path: testInfo.outputPath('caret-before-first-thai-character.png'), caret: 'initial' })
      await page.keyboard.press('ArrowRight')
      await expect.poll(caret).toBeGreaterThan(0)
      await page.keyboard.press('ArrowLeft')
      await expect.poll(caret).toBe(0)
    }
    await page.keyboard.insertText('ก่อน')
    await expect(caption).toHaveText(`ก่อน${title}`)
    await saved(page)
    await page.reload()
    await expect(caption).toHaveText(`ก่อน${title}`)
    await expect(page.locator('.record-signature [data-paragraph-id="nested-photo-heading"]')).toHaveCount(1)
    const persisted = (await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)).data.document
    expect(persisted.objects[0]).toMatchObject({ anchor: { kind: 'paragraph', id: 'nested-photo-heading', offsetY: 16 } })
  })

  test('formatting marks include blank Enter lines, headings, cells and signatures without changing content or print layout', async ({ page }, testInfo) => {
    const document = await createDocument(page, { type: 'doc', content: [
      { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'หัวข้อทั่วไป' }] },
      { type: 'recordSectionTitle', content: [{ type: 'text', text: 'ข้อคิดเห็นของผู้อำนวยการสถานศึกษา' }] },
      { type: 'recordInfoRow', content: [{ type: 'recordInfoCell', content: [{ type: 'text', text: 'ลงชื่อ' }] }, { type: 'recordInfoCell' }] },
      { type: 'recordBody', content: [{ type: 'paragraph', attrs: { id: 'empty-body' } }] },
      { type: 'recordSignature', attrs: { index: 3, width: 430 }, content: [
        { type: 'paragraph', attrs: { id: 'signature-position', role: 'signaturePosition' }, content: [{ type: 'text', text: 'ครู รักษาการในตำแหน่ง' }, { type: 'hardBreak' }, { type: 'text', text: 'ผู้อำนวยการสถานศึกษา' }] },
        { type: 'paragraph', attrs: { id: 'empty-signature' } },
      ] },
      { type: 'table', content: [{ type: 'tableRow', content: [{ type: 'tableCell', content: [{ type: 'paragraph' }] }] }] },
      { type: 'paragraph', attrs: { id: 'long-line' }, content: [{ type: 'text', text: 'ข้อความภาษาไทยที่ตัดบรรทัดตามความกว้างกระดาษ '.repeat(10) }] },
      { type: 'paragraph', attrs: { id: 'empty-end' } },
    ] })
    const created = (await api<{ document: StoredDocument & { pageSettings: Record<string, unknown> } }>(page, 'GET', `/api/documents/${document.id}`)).data.document
    expect((await api(page, 'PUT', `/api/documents/${document.id}`, { revision: created.revision, pageSettings: { ...created.pageSettings, template: 'weekly-record', fontFamily: 'THSarabunNew' } })).status).toBe(200)
    await page.reload()
    const root = page.getByRole('textbox', { name: 'เนื้อหาเอกสาร', exact: true })
    const canvas = page.locator('[data-document-canvas]')
    await expect.poll(async () => (await root.locator('[data-paragraph-id=empty-end]').boundingBox())!.height).toBeGreaterThan(10)
    const geometry = () => root.evaluate(element => [...element.querySelectorAll('p,h2,.record-section-title,.record-info-cell,.record-signature')].map(node => {
      const rect = node.getBoundingClientRect()
      return { x: rect.x, y: rect.y, width: rect.width, height: rect.height }
    }))
    await page.evaluate(() => window.document.fonts.ready)
    const before = await geometry()
    const initial = (await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)).data.document
    await page.getByRole('tab', { name: 'ตรวจทาน', exact: true }).click()
    await page.getByRole('button', { name: 'แสดงอักขระ', exact: true }).click()
    await expect(canvas).toHaveClass(/show-marks/)
    await expect(root.locator('h2 > [data-formatting-mark=paragraph]')).toHaveCount(1)
    await expect(root.locator('.record-section-title > [data-formatting-mark=paragraph]')).toHaveCount(1)
    await expect(root.locator('.record-info-cell > [data-formatting-mark=cell]')).toHaveCount(2)
    await expect(root.locator('td:not(.record-info-cell) p > [data-formatting-mark=cell]')).toHaveCount(1)
    for (const id of ['empty-body', 'empty-signature', 'empty-end', 'long-line']) await expect(root.locator(`[data-paragraph-id="${id}"] > [data-formatting-mark=paragraph]`)).toHaveCount(1)
    await expect(root.locator('[data-paragraph-id=signature-position] > [data-formatting-mark=line-break]')).toHaveCount(1)
    await expect(root.locator('[data-paragraph-id=signature-position] > [data-formatting-mark=paragraph]')).toHaveCount(1)
    expect(await geometry()).toEqual(before)
    expect((await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)).data.document.content).toEqual(initial.content)
    const empty = root.locator('[data-paragraph-id=empty-signature]')
    await empty.evaluate(element => {
      element.closest<HTMLElement>('[contenteditable=true]')!.focus()
      const range = window.document.createRange()
      range.selectNodeContents(element); range.collapse(true)
      const selection = window.getSelection()!; selection.removeAllRanges(); selection.addRange(range)
      window.document.dispatchEvent(new Event('selectionchange'))
    })
    await page.keyboard.press('Enter')
    await page.keyboard.press('Enter')
    await page.keyboard.press('Enter')
    const blank = root.locator('.record-signature p').filter({ hasNotText: /\S/ })
    await expect(blank).toHaveCount(4)
    await expect(blank.locator('[data-formatting-mark=paragraph]')).toHaveCount(4)
    await expect(blank.locator('[data-formatting-mark=line-break]')).toHaveCount(0)
    const lines = await blank.evaluateAll(elements => elements.map(element => element.getBoundingClientRect().top))
    const lineHeight = await empty.evaluate(element => parseFloat(getComputedStyle(element).lineHeight) * element.getBoundingClientRect().width / (element as HTMLElement).offsetWidth)
    for (let index = 1; index < lines.length; index++) expect(Math.abs(lines[index] - lines[index - 1] - lineHeight)).toBeLessThan(3)
    await saved(page)
    await page.screenshot({ path: testInfo.outputPath('paragraph-marks-empty-lines.png'), caret: 'initial' })
    const stored = (await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)).data.document
    expect(JSON.stringify(stored.content)).not.toContain('¶')
    expect(await root.textContent()).not.toContain('¶')
    const withBlankLines = await geometry()
    await page.getByRole('button', { name: 'ซ่อนอักขระ', exact: true }).click()
    expect(await geometry()).toEqual(withBlankLines)
    await expect(canvas).not.toHaveClass(/show-marks/)
    const glyphs = () => root.locator('[data-formatting-mark]').evaluateAll(elements => elements.map(element => getComputedStyle(element, '::after').content))
    expect((await glyphs()).every(content => content === 'none' || content === 'normal')).toBe(true)
    await page.getByRole('button', { name: 'แสดงอักขระ', exact: true }).click()
    expect((await glyphs()).every(content => ['"¶"', '"↵"', '"¤"', '"·"', '"→"'].includes(content))).toBe(true)
    await page.getByRole('button', { name: 'พิมพ์ / PDF', exact: true }).click()
    await expect(page.locator('iframe[data-document-print-ready=true]')).toHaveCount(1)
    const print = page.frameLocator('iframe[data-document-print-frame]')
    await expect(print.locator('[data-formatting-mark],.ProseMirror-separator,.show-marks')).toHaveCount(0)
    await page.locator('iframe[data-document-print-frame]').evaluate(element => element.remove())
    await page.reload()
    const persisted = (await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)).data.document
    expect(persisted.content).toEqual(stored.content)
  })

  test('Word-like marks distinguish real paragraph ends, manual breaks, spaces and tabs at the Thai caret across zoom levels', async ({ page }, testInfo) => {
    const errors: string[] = []
    page.on('pageerror', error => errors.push(error.message))
    const document = await createDocument(page, { type: 'doc', content: [
      { type: 'recordBody', attrs: { field: 'record_result', lineSpacing: 26, align: 'justify', firstIndent: 42 }, content: [
        { type: 'paragraph', attrs: { id: 'word-legacy' }, content: [
          { type: 'text', text: '- ปฐมนิเทศ ชี้แจงหลักสูตร/วิธีเรียน/การจัดการเรียนการสอน   ' }, { type: 'hardBreak' },
          { type: 'text', text: 'แนะแนว' }, { type: 'hardBreak' }, { type: 'hardBreak' },
          { type: 'text', text: '- ชี้แจงการทำกิจกรรม กพช.' }, { type: 'hardBreak' }, { type: 'text', text: '- แนะแนวทางการจัดกระบวนการเรียนรู้ ตามหลักสูตร' },
        ] },
        { type: 'paragraph', attrs: { id: 'word-empty' } },
        { type: 'paragraph', attrs: { id: 'word-spaces' }, content: [{ type: 'text', text: 'คำ  ไทย\tถัดไป' }] },
        { type: 'paragraph', attrs: { id: 'word-wrap' }, content: [{ type: 'text', text: 'ข้อความไทยที่ตัดบรรทัดอัตโนมัติ '.repeat(15) }] },
      ] },
    ] })
    const initial = (await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)).data.document
    const root = page.getByRole('textbox', { name: 'เนื้อหาเอกสาร', exact: true })
    const canvas = page.locator('[data-document-canvas]')
    const geometry = () => root.evaluate(element => {
      const origin = element.getBoundingClientRect()
      return [...element.querySelectorAll('p')].map(paragraph => {
        const rect = paragraph.getBoundingClientRect()
        return { x: rect.x - origin.x, y: rect.y - origin.y, width: rect.width, height: rect.height }
      })
    })
    await page.getByRole('tab', { name: 'ตรวจทาน', exact: true }).click()
    for (const zoom of ['0.5', '1', '1.5']) {
      await page.getByRole('slider', { name: 'ซูมเอกสาร', exact: true }).fill(zoom)
      await page.evaluate(() => window.document.fonts.ready)
      const before = await geometry()
      const decorationLayout = await root.locator('.record-body').evaluate(element => {
        const clone = element.cloneNode(true) as HTMLElement
        clone.querySelectorAll('[data-formatting-mark]').forEach(marker => marker.remove())
        Object.assign(clone.style, { position: 'absolute', left: '0', top: '0', width: `${(element as HTMLElement).offsetWidth}px`, visibility: 'hidden' })
        element.parentElement!.append(clone)
        const original = [...element.querySelectorAll('p')].map(paragraph => paragraph.getBoundingClientRect().height)
        const clean = [...clone.querySelectorAll('p')].map(paragraph => paragraph.getBoundingClientRect().height)
        clone.remove()
        return { original, clean }
      })
      expect(decorationLayout.original).toEqual(decorationLayout.clean)
      await page.getByRole('button', { name: 'แสดงอักขระ', exact: true }).click()
      await expect(root.locator('[data-paragraph-id=word-legacy] > [data-formatting-mark=paragraph]')).toHaveCount(1)
      await expect(root.locator('[data-paragraph-id=word-legacy] > [data-formatting-mark=line-break]')).toHaveCount(4)
      await expect(root.locator('[data-paragraph-id=word-spaces] > [data-formatting-mark=space]')).toHaveCount(2)
      await expect(root.locator('[data-paragraph-id=word-spaces] > [data-formatting-mark=tab]')).toHaveCount(1)
      await expect(root.locator('[data-paragraph-id=word-wrap] > [data-formatting-mark=paragraph]')).toHaveCount(1)
      const alignmentErrors = () => root.locator('[data-paragraph-id=word-spaces]').evaluate(element => {
        const scale = element.getBoundingClientRect().width / (element as HTMLElement).offsetWidth
        return [...element.querySelectorAll<HTMLElement>('[data-formatting-mark=space],[data-formatting-mark=tab]')].map(marker => {
          const x = marker.style.getPropertyValue('--formatting-mark-x')
          const width = marker.style.getPropertyValue('--formatting-mark-width')
          if (!x || !width) return 999
          let next: Node | null = marker.nextSibling
          while (next && !next.textContent) next = next.nextSibling
          if (!next || next.nodeType !== Node.TEXT_NODE) return 999
          const range = window.document.createRange()
          range.setStart(next, 0); range.setEnd(next, 1)
          const rect = range.getBoundingClientRect()
          return Math.abs(marker.getBoundingClientRect().left + (parseFloat(x) + parseFloat(width) / 2) * scale - (rect.left + rect.right) / 2)
        })
      })
      await expect.poll(async () => Math.max(...await alignmentErrors())).toBeLessThan(2)
      await expect.poll(() => root.locator('[data-paragraph-id=word-legacy]').evaluate(element => {
        const marker = element.querySelector<HTMLElement>('[data-formatting-mark=paragraph]')!
        const scale = element.getBoundingClientRect().width / (element as HTMLElement).offsetWidth
        if (!marker.style.getPropertyValue('--formatting-mark-y')) return 999
        const walker = window.document.createTreeWalker(element, NodeFilter.SHOW_TEXT)
        let last: Node | null = null
        for (let node = walker.nextNode(); node; node = walker.nextNode()) if (node.textContent?.length) last = node
        if (!last) return 999
        const range = window.document.createRange()
        range.setStart(last, last.textContent!.length - 1); range.setEnd(last, last.textContent!.length)
        const rect = range.getBoundingClientRect()
        const markerRect = marker.getBoundingClientRect()
        const x = markerRect.left + parseFloat(marker.style.getPropertyValue('--formatting-mark-x')) * scale
        const y = markerRect.top + parseFloat(marker.style.getPropertyValue('--formatting-mark-y')) * scale
        return Math.max(Math.abs(x - rect.right), Math.abs(y - rect.top))
      })).toBeLessThan(3).catch(async error => {
        console.log('Paragraph end geometry', await root.locator('[data-paragraph-id=word-legacy]').evaluate(element => {
          const marker = element.querySelector<HTMLElement>('[data-formatting-mark=paragraph]')!
          const scale = element.getBoundingClientRect().width / (element as HTMLElement).offsetWidth
          const walker = window.document.createTreeWalker(element, NodeFilter.SHOW_TEXT)
          let last: Node | null = null
          for (let node = walker.nextNode(); node; node = walker.nextNode()) if (node.textContent?.length) last = node
          const range = window.document.createRange()
          range.setStart(last!, last!.textContent!.length - 1); range.setEnd(last!, last!.textContent!.length)
          const rect = range.getBoundingClientRect(), box = marker.getBoundingClientRect()
          return { scale, marker: { x: box.x, y: box.y }, reference: { x: rect.right, y: rect.top, height: rect.height }, x: marker.style.getPropertyValue('--formatting-mark-x'), y: marker.style.getPropertyValue('--formatting-mark-y'), height: marker.style.getPropertyValue('--formatting-mark-height'), paragraph: element.getBoundingClientRect().toJSON(), text: last!.textContent }
        }))
        throw error
      })

      expect(await geometry()).toEqual(before)
      if (zoom === '1') await page.screenshot({ path: testInfo.outputPath('word-like-formatting-marks.png'), fullPage: false })
      await page.getByRole('button', { name: 'ซ่อนอักขระ', exact: true }).click()
      expect(await geometry()).toEqual(before)
    }
    const line = root.locator('[data-paragraph-id=word-spaces]')
    await line.evaluate(element => {
      element.closest<HTMLElement>('[contenteditable=true]')!.focus()
      const range = window.document.createRange(); range.selectNodeContents(element); range.collapse(false)
      const selection = window.getSelection()!; selection.removeAllRanges(); selection.addRange(range)
      window.document.dispatchEvent(new Event('selectionchange'))
    })
    await page.keyboard.press('Tab')
    await expect(line).toHaveText('คำ  ไทย\tถัดไป\t')
    await page.keyboard.press('Shift+Enter')
    await page.keyboard.insertText('ยังเป็นย่อหน้าเดิม')
    await expect(line.locator('br:not(.ProseMirror-trailingBreak)')).toHaveCount(1)
    await page.keyboard.press('Enter')
    await page.keyboard.insertText('ย่อหน้าใหม่')
    await expect(line.locator('xpath=following-sibling::p[1]')).toHaveText('ย่อหน้าใหม่')
    await saved(page)
    const stored = (await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)).data.document
    expect(stored.content.content![0].content![0]).toEqual(initial.content.content![0].content![0])
    expect(JSON.stringify(stored.content)).not.toMatch(/[¶↵¤·→]/)
    await page.getByRole('button', { name: 'แสดงอักขระ', exact: true }).click()
    await page.getByRole('button', { name: 'พิมพ์ / PDF', exact: true }).click()
    await expect(page.locator('iframe[data-document-print-ready=true]')).toHaveCount(1)
    const print = page.frameLocator('iframe[data-document-print-frame]')
    await expect(print.locator('[data-formatting-mark],.show-marks')).toHaveCount(0)
    await expect(print.locator('[data-paragraph-id=word-spaces]')).toHaveText('คำ  ไทย\tถัดไป\tยังเป็นย่อหน้าเดิม')
    await page.locator('iframe[data-document-print-frame]').evaluate(frame => frame.remove())
    await page.reload()
    expect((await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)).data.document.content).toEqual(stored.content)
    expect(errors).toEqual([])
  })

  test('formatting marks also cover empty paragraphs in a floating textbox while it is not being edited', async ({ page }) => {
    await createDocument(page)
    await page.getByRole('tab', { name: 'แทรก', exact: true }).click()
    await page.getByRole('button', { name: 'กล่องข้อความ', exact: true }).click()
    const textbox = page.getByRole('textbox', { name: 'ข้อความในกล่องข้อความ', exact: true })
    await expect(textbox).toHaveAttribute('contenteditable', 'false')
    await page.getByRole('tab', { name: 'ตรวจทาน', exact: true }).click()
    await page.getByRole('button', { name: 'แสดงอักขระ', exact: true }).click()
    await expect(textbox.locator('[data-formatting-mark=paragraph]')).toHaveCount(1)
    expect(await textbox.locator('[data-formatting-mark]').evaluate(element => getComputedStyle(element, '::after').content)).toBe('"¶"')
    expect(await textbox.textContent()).not.toContain('¶')
  })

  test('Thai partial formatting survives autosave and a real reload', async ({ page }) => {
    const document = await createDocument(page)
    const editor = page.getByRole('textbox', { name: 'เนื้อหาเอกสาร', exact: true })
    const selected = 'ส่วนที่เลือก'
    await editor.fill(`${selected} และข้อความปกติ`)
    await editor.evaluate((element, selected) => {
      element.focus()
      const walker = window.document.createTreeWalker(element, NodeFilter.SHOW_TEXT)
      let node: Node | null
      while ((node = walker.nextNode())) {
        const start = node.textContent?.indexOf(selected) ?? -1
        if (start < 0) continue
        const range = window.document.createRange()
        range.setStart(node, start); range.setEnd(node, start + selected.length)
        const selection = window.getSelection()!
        selection.removeAllRanges(); selection.addRange(range)
        window.document.dispatchEvent(new Event('selectionchange'))
        break
      }
    }, selected)
    await expect.poll(() => page.evaluate(() => window.getSelection()?.toString())).toBe(selected)
    await page.getByRole('button', { name: 'ตัวหนา', exact: true }).click()
    await page.getByRole('combobox', { name: 'ขนาดตัวอักษร', exact: true }).selectOption('24')
    await page.getByRole('textbox', { name: 'สีตัวอักษร', exact: true }).fill('#2563eb')
    await page.getByRole('button', { name: 'กระจายแบบไทย (Thai Distributed)', exact: true }).click()
    await expect(editor.locator('strong')).toHaveText(selected)
    await expect(editor.locator('p').first()).toHaveCSS('text-align', 'justify')
    await expect(editor.locator('p').first()).toHaveCSS('text-justify', 'inter-character')
    await saved(page)
    await page.reload()
    await expect(editor).toHaveText(`${selected} และข้อความปกติ`)
    await expect(editor.locator('strong')).toHaveText(selected)
    const persisted = await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)
    const runs = persisted.data.document.content.content?.flatMap(node => node.content || []) || []
    expect(persisted.data.document.content.content?.[0].attrs?.textAlign).toBe('thai-distributed')
    const formatted = runs.find(node => node.text === selected)
    expect(formatted?.marks).toEqual(expect.arrayContaining([
      expect.objectContaining({ type: 'bold' }),
      expect.objectContaining({ type: 'textStyle', attrs: expect.objectContaining({ fontSize: '24pt', color: '#2563eb' }) }),
    ]))
    expect(runs.find(node => node.text?.includes('ข้อความปกติ'))?.marks || []).not.toEqual(expect.arrayContaining([expect.objectContaining({ type: 'bold' })]))
  })

  test('editable text boxes and uploaded images use millimeter positions with undo and redo', async ({ page }) => {
    const document = await createDocument(page)
    await page.getByRole('tab', { name: 'แทรก', exact: true }).click()
    await page.getByRole('button', { name: 'กล่องข้อความ', exact: true }).click()
    const textbox = page.locator('[data-document-canvas] .document-object').first()
    await page.getByRole('spinbutton', { name: 'ตำแหน่ง X', exact: true }).fill('45')
    await expect.poll(() => textbox.evaluate(element => parseFloat((element as HTMLElement).style.left))).toBeCloseTo(45 * 96 / 25.4, 2)
    await textbox.dblclick({ position: { x: 30, y: 30 } })
    const textboxEditor = page.getByRole('textbox', { name: 'ข้อความในกล่องข้อความ', exact: true })
    await expect(textboxEditor).toHaveAttribute('contenteditable', 'true')
    await textboxEditor.fill('ข้อความในกล่องทดสอบ')
    await page.getByRole('tab', { name: 'แทรก', exact: true }).click()
    await saved(page)
    const image = await uploadImage(page)
    await page.getByRole('spinbutton', { name: 'ตำแหน่ง X', exact: true }).fill('90')
    await expect.poll(() => image.evaluate(element => parseFloat((element as HTMLElement).style.left))).toBeCloseTo(90 * 96 / 25.4, 2)
    await saved(page)
    await page.getByRole('button', { name: 'ย้อนกลับ', exact: true }).click()
    await expect.poll(() => image.evaluate(element => parseFloat((element as HTMLElement).style.left))).toBeCloseTo(25 * 96 / 25.4, 2)
    await page.getByRole('button', { name: 'ทำซ้ำ', exact: true }).click()
    await expect.poll(() => image.evaluate(element => parseFloat((element as HTMLElement).style.left))).toBeCloseTo(90 * 96 / 25.4, 2)
    await saved(page)
    const persisted = await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)
    expect(persisted.data.document.objects.find(object => object.type === 'image')?.x).toBe(90)
    expect(JSON.stringify(persisted.data.document.objects.find(object => object.type === 'textbox')?.content)).toContain('ข้อความในกล่องทดสอบ')
    expect(persisted.data.document.assets).toHaveLength(1)
  })

  test('a real revision conflict retains local edits and saves a separate copy', async ({ page }) => {
    const document = await createDocument(page)
    const latest = await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)
    const remoteTitle = `E2E other window ${randomUUID()}`
    expect((await api(page, 'PUT', `/api/documents/${document.id}`, { revision: latest.data.document.revision, title: remoteTitle })).status).toBe(200)
    const localTitle = `E2E local edits ${randomUUID()}`
    const conflict = page.waitForResponse(response => response.url().endsWith(`/api/documents/${document.id}`) && response.request().method() === 'PUT' && response.status() === 409)
    await page.getByRole('textbox', { name: 'ชื่อเอกสาร', exact: true }).fill(localTitle)
    await conflict
    await expect(page.locator('.de-save-status')).toHaveText('เอกสารมีรุ่นใหม่จากอีกหน้าต่าง')
    await page.getByRole('alertdialog', { name: 'เอกสารมีรุ่นใหม่', exact: true }).getByRole('button', { name: 'ตกลง', exact: true }).click()
    await expect(page.getByRole('textbox', { name: 'ชื่อเอกสาร', exact: true })).toHaveValue(localTitle)
    const copyResponse = page.waitForResponse(response => response.url().endsWith(`/api/documents/${document.id}/duplicate`) && response.status() === 201)
    await page.getByRole('button', { name: 'เก็บเป็นเอกสารใหม่', exact: true }).click()
    const copied = (await (await copyResponse).json()) as { document: StoredDocument }
    owned.get(page)!.push(copied.document.id)
    await expect(page).toHaveURL(new RegExp(`/documents/${copied.document.id}/edit$`))
    await expect(page.getByRole('textbox', { name: 'ชื่อเอกสาร', exact: true })).toHaveValue(`${localTitle} (สำเนา)`)
    expect((await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)).data.document.title).toBe(remoteTitle)
    expect(copied.document.id).not.toBe(document.id)
  })

  test('signature and image body drags keep physical positions across zoom, undo and reload', async ({ page }) => {
    const id = process.env.SENA_E2E_DOCUMENT_ID
    test.skip(!id, 'Provide a dedicated native-template document fixture to test signature dragging.')
    await page.goto(`/documents/${id}/edit`)
    const editor = page.getByRole('textbox', { name: 'เนื้อหาเอกสาร', exact: true })
    await expect(editor).toBeVisible()
    await saved(page)
    const original = (await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${id}`)).data.document
    const versions = await api<{ versions: { id: string; revision: number }[] }>(page, 'GET', `/api/documents/${id}/versions`)
    const originalVersion = versions.data.versions.find(version => version.revision === original.revision)!.id
    const drag = async (element: ReturnType<Page['locator']>, x: number, y: number) => {
      await element.scrollIntoViewIfNeeded()
      const box = (await element.boundingBox())!
      const start = { x: box.x + box.width / 2, y: box.y + box.height / 2 }
      await page.mouse.move(start.x, start.y)
      await page.mouse.down()
      await page.mouse.move(start.x + x, start.y + y, { steps: 12 })
      await page.mouse.up()
    }
    try {
      const zoom = page.getByRole('slider', { name: 'ซูมเอกสาร', exact: true })
      await zoom.focus(); await zoom.press('Home')
      await expect(zoom).toHaveValue('0.5')
      const signature = page.locator('.record-signature').first()
      const handle = signature.locator('.record-signature-move-handle')
      await expect(handle).toBeVisible()
      const offset = () => signature.evaluate(element => [Number((element as HTMLElement).dataset.recordOffsetX), Number((element as HTMLElement).dataset.recordOffsetY)])
      const before = await offset()
      await drag(handle, 40, 15)
      await expect.poll(offset).toEqual([before[0] + 80, before[1] + 30])
      await saved(page)
      await page.getByRole('button', { name: 'ย้อนกลับ', exact: true }).click()
      await expect.poll(offset).toEqual(before)
      await page.getByRole('button', { name: 'ทำซ้ำ', exact: true }).click()
      await expect.poll(offset).toEqual([before[0] + 80, before[1] + 30])
      await saved(page)
      await page.reload()
      await expect.poll(offset).toEqual([before[0] + 80, before[1] + 30])
      await zoom.focus(); await zoom.press('End')
      await expect(zoom).toHaveValue('2')
      const image = await uploadImage(page)
      const imageId = await image.getAttribute('data-object-id')
      const imageBefore = (await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${id}`)).data.document.objects.find(object => object.id === imageId) as { x: number; y: number }
      await editor.locator('p').first().click()
      await drag(image.locator('.document-object-body'), 40, 20)
      await saved(page)
      const moved = (await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${id}`)).data.document.objects.find(object => object.id === imageId) as { x: number; y: number }
      expect(moved.x).toBeCloseTo(imageBefore.x + 40 / 2 * 25.4 / 96, 2)
      expect(moved.y).toBeCloseTo(imageBefore.y + 20 / 2 * 25.4 / 96, 2)
      await page.getByRole('button', { name: 'ย้อนกลับ', exact: true }).click()
      await expect.poll(() => image.evaluate(element => parseFloat((element as HTMLElement).style.left))).toBeCloseTo(imageBefore.x * 96 / 25.4, 2)
      await page.getByRole('button', { name: 'ทำซ้ำ', exact: true }).click()
      await saved(page)
      await page.reload()
      await expect.poll(() => image.evaluate(element => parseFloat((element as HTMLElement).style.left))).toBeCloseTo(moved.x * 96 / 25.4, 2)
    } finally {
      const current = (await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${id}`)).data.document
      expect((await api(page, 'POST', `/api/documents/${id}/versions/${originalVersion}/restore`, { revision: current.revision })).status).toBe(200)
    }
  })

  test('a transient image decode error still prints after pixel validation and the new back button works on mobile', async ({ page }, testInfo) => {
    const recordId = ownsFixture ? (legacyRecordId ||= fixture!.seedLegacyRecord()) : process.env.SENA_E2E_RECORD_ID
    test.skip(!recordId, 'Provide an isolated record fixture with images.')
    await page.goto(`/records/${recordId}/document`)
    await expect(page.getByRole('textbox', { name: 'เนื้อหาเอกสาร', exact: true })).toBeVisible()
    await saved(page)
    await page.addInitScript(() => { HTMLImageElement.prototype.decode = async () => { throw new DOMException('The source image cannot be decoded.', 'EncodingError') } })
    await page.goto(`/records/${recordId}`)
    const returnUrl = page.url()
    await page.getByRole('link', { name: 'พิมพ์ / บันทึก PDF', exact: true }).click()
    await expect(page.locator('body')).toHaveAttribute('data-document-print-ready', 'true', { timeout: 25000 })
    await expect(page.locator('#browser-print-button')).toBeEnabled()
    await expect(page.locator('#browser-print-status')).toContainText('พร้อมพิมพ์')
    await expect(page.locator('.document-print-page img')).not.toHaveCount(0)
    await expect(page.locator('img[data-print-image-failed],img.ProseMirror-separator')).toHaveCount(0)
    const back = page.getByRole('link', { name: 'ย้อนกลับ', exact: true })
    await expect(back.locator('svg[aria-hidden=true]')).toHaveCount(1)
    expect((await back.boundingBox())!.height).toBeGreaterThanOrEqual(44)
    await page.screenshot({ path: testInfo.outputPath('print-toolbar-desktop.png') })
    await page.setViewportSize({ width: 390, height: 844 })
    const bounds = (await back.boundingBox())!
    expect(bounds.x).toBeGreaterThanOrEqual(0)
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(390)
    await expect(page.locator('#browser-print-button')).toBeInViewport()
    await page.screenshot({ path: testInfo.outputPath('print-toolbar-mobile.png') })
    await back.focus()
    await page.keyboard.press('Enter')
    await expect(page).toHaveURL(returnUrl)
  })

  test('genuinely unreadable print images stay blocked with Thai diagnostics and can be retried', async ({ page }) => {
    const recordId = ownsFixture ? (legacyRecordId ||= fixture!.seedLegacyRecord()) : process.env.SENA_E2E_RECORD_ID
    test.skip(!recordId, 'Provide an isolated record fixture with images.')
    await page.goto(`/records/${recordId}/document`)
    await expect(page.getByRole('textbox', { name: 'เนื้อหาเอกสาร', exact: true })).toBeVisible()
    await saved(page)
    const printRoute = `**/records/${recordId}/browser-print`
    await page.route(printRoute, async route => {
      const response = await route.fetch()
      const html = await response.text()
      const payload = /<script id="document-snapshot-data" type="application\/json">([\s\S]*?)<\/script>/.exec(html)![1]
      let corrupted = 0
      const damaged = JSON.stringify(JSON.parse(payload), (_key, value) => {
        if (typeof value === 'string' && value.startsWith('data:image/')) { corrupted++; return 'data:image/png;base64,aW52YWxpZC1pbWFnZQ==' }
        return value
      }).replace(/</g, '\\u003c')
      expect(corrupted).toBeGreaterThan(0)
      await route.fulfill({ response, body: html.replace(payload, damaged) })
    })
    await page.goto(`/records/${recordId}/browser-print`)
    await expect(page.locator('#browser-print-status')).toContainText('โหลดรูปภาพไม่สำเร็จ', { timeout: 25000 })
    await expect(page.locator('#browser-print-status')).toContainText('รูปที่ 1')
    await expect(page.locator('#browser-print-status')).not.toContainText('cannot be decoded')
    await expect(page.locator('#browser-print-button')).toBeDisabled()
    await expect(page.locator('#browser-print-retry')).toBeVisible()
    expect(await page.locator('img[data-print-image-failed]').count()).toBeGreaterThan(0)
    await page.unroute(printRoute)
    await page.getByRole('button', { name: 'ลองโหลดอีกครั้ง', exact: true }).click()
    await expect(page.locator('body')).toHaveAttribute('data-document-print-ready', 'true', { timeout: 25000 })
    await expect(page.locator('#browser-print-button')).toBeEnabled()
    await expect(page.locator('#browser-print-retry')).toBeHidden()
  })

  test('the learning center shows the selected group without a group row in both canvas print and server PDF', async ({ page }) => {
    test.setTimeout(90_000)
    const name = 'ศกร.ระดับตำบลบางนมโค'
    const created = await api<{ data: { draft: { id: string; lock_version: number } } }>(page, 'POST', '/teaching-drafts', { payload: {
      scope: { tambon: 'workflow-tambon', year: '2569', semester: '2', week: 15, date: '2026-11-05' },
      enabled_level_codes: ['1'], active_level_code: '1', levels: { '1': { subject: 'ปฐมนิเทศ', subject_code: '', class_name: name, class_group_code: 'CENTER-1', result: 'จัดกิจกรรมปฐมนิเทศให้ผู้เรียน', problem: '', suggestion: '', attendance_mode: 'counts', total: 2, present: 2 } },
    } })
    expect(created.status).toBe(201)
    const draft = created.data.data.draft
    const finalized = await api<{ data: { record_ids: string[] } }>(page, 'POST', `/teaching-drafts/${draft.id}/finalize`, { lock_version: draft.lock_version, idempotency_key: randomUUID(), confirmed: true })
    expect(finalized.status).toBe(200)
    const recordId = finalized.data.data.record_ids[0]
    await page.goto(`/records/${recordId}/document`)
    await expect(page.getByRole('textbox', { name: 'เนื้อหาเอกสาร', exact: true })).toBeVisible()
    const header = page.locator('.record-info-row:not(.record-info-row--compact)').first()
    await page.locator('.record-body > p').first().click()
    const leftIndent = page.getByRole('slider', { name: 'เยื้องซ้ายทั้งย่อหน้า', exact: true })
    await expect(leftIndent).toBeEnabled()
    await leftIndent.focus()
    await page.keyboard.press('Shift+ArrowRight')
    await page.keyboard.press('Shift+ArrowRight')
    await page.getByRole('slider', { name: 'เยื้องขวา', exact: true }).focus()
    await page.keyboard.press('Shift+ArrowLeft')
    await expect(header.locator('[data-record-field="record_class_name"]')).toHaveText(name)
    await expect(page.locator('.record-info-cell--label').filter({ hasText: /^กลุ่มเรียน$/ })).toHaveCount(0)
    await saved(page)
    await page.getByRole('button', { name: 'บันทึกและพิมพ์', exact: true }).click()
    const frame = page.frameLocator('iframe[data-document-print-frame]')
    await expect(page.locator('iframe[data-document-print-ready=true]')).toHaveCount(1)
    await expect(frame.locator('.record-info-row:not(.record-info-row--compact)').first()).toContainText(name)
    await expect(frame.locator('.record-info-cell--label').filter({ hasText: /^กลุ่มเรียน$/ })).toHaveCount(0)
    const verification = await page.context().newPage()
    await verification.goto(`/records/${recordId}/browser-print`)
    await expect(verification.locator('body')).toHaveAttribute('data-document-print-ready', 'true', { timeout: 25_000 })
    await expect(verification.locator('.record-info-row:not(.record-info-row--compact)').first()).toContainText(name)
    await expect(verification.locator('.record-info-cell--label').filter({ hasText: /^กลุ่มเรียน$/ })).toHaveCount(0)
    const indents = await verification.locator('.record-body > p').first().evaluate(element => ({ left: parseFloat(getComputedStyle(element).marginLeft), right: parseFloat(getComputedStyle(element).marginRight) }))
    expect(indents.left).toBeCloseTo(37.795, 2)
    expect(indents.right).toBeCloseTo(18.898, 2)
    const geometry = await verification.locator('[data-record-field="record_class_name"]').first().evaluate(cell => ({ client: cell.clientWidth, scroll: cell.scrollWidth }))
    expect(geometry.scroll).toBeLessThanOrEqual(geometry.client + 1)
    await mkdir('output/pdf', { recursive: true })
    const browserPdf = await verification.pdf({ path: 'output/pdf/learning-center-browser.pdf', preferCSSPageSize: true, printBackground: true })
    expect(browserPdf.subarray(0, 5).toString()).toBe('%PDF-')
    await verification.close()
    await page.locator('iframe[data-document-print-frame]').evaluate(frame => frame.remove())
    const server = await page.request.get(`/records/${recordId}/print`, { headers: { Accept: 'application/pdf' }, timeout: 60_000 })
    expect(server.status()).toBe(200)
    expect(server.headers()['content-type']).toBe('application/pdf')
    const serverPdf = await server.body()
    expect(serverPdf.subarray(0, 5).toString()).toBe('%PDF-')
    await writeFile('output/pdf/learning-center-server.pdf', serverPdf)
  })

  test('the original record route uses the advanced editor with the original Thai form and saves before printing', async ({ page }) => {
    const recordId = ownsFixture ? (legacyRecordId ||= fixture!.seedLegacyRecord()) : process.env.SENA_E2E_RECORD_ID
    test.skip(!recordId, 'Provide the ID of a dedicated isolated report fixture to test the record binding.')
    await page.goto(`/records/${recordId}/document`)
    await expect(page.getByRole('textbox', { name: 'เนื้อหาเอกสาร', exact: true })).toBeVisible()
    await expect(page.locator('.de-source-note')).toContainText('จัดเอกสารก่อนพิมพ์')
    await expect(page.getByRole('button', { name: 'ตัวแก้ไขขั้นสูง / DOCX', exact: true })).toHaveCount(0)
    await expect(page.locator('.record-info-row:not(.record-info-row--compact)')).toHaveCount(5)
    await expect(page.locator('.record-signature')).toHaveCount(3)
    await expect(page.locator('.record-info-row:not(.record-info-row--compact)').first()).toContainText('ศูนย์การเรียนรู้')
    await expect(page.locator('.record-info-row:not(.record-info-row--compact)').first().locator('[data-record-field="record_class_name"]')).toHaveText('กลุ่มทดสอบ มัธยมศึกษาตอนปลาย')
    await expect(page.locator('.record-info-cell--label').filter({ hasText: /^กลุ่มเรียน$/ })).toHaveCount(0)
    await expect.poll(() => page.locator('[data-document-canvas] .document-page').count()).toBeGreaterThan(0)
    const documentId = await page.locator('script[type="application/json"][data-page]').evaluate(element => JSON.parse(element.textContent || '{}').props.document.id) as string
    await saved(page)
    const versions = await api<{ versions: { id: string }[] }>(page, 'GET', `/api/documents/${documentId}/versions`)
    const originalVersion = versions.data.versions.at(-1)!.id
    try {
      const body = page.locator('[data-record-field="record_result"] p').first()
      expect(await body.evaluate(element => getComputedStyle(element).fontFamily)).toContain('THSarabunNew')
      await expect(page.getByRole('combobox', { name: 'แบบอักษร', exact: true })).toHaveValue('THSarabunNew')
      await body.fill('แก้ไขผลการเรียนรู้จากตัวแก้ไขขั้นสูงและบันทึกก่อนพิมพ์')
      await page.getByRole('button', { name: 'บันทึกและพิมพ์', exact: true }).click()
      const tabs = page.context().pages().length
      const popup = page.frameLocator('iframe[data-document-print-frame]')
      await expect(page.locator('iframe[data-document-print-ready=true]')).toHaveCount(1)
      await expect(popup.locator('body')).toHaveAttribute('data-test-print-called', 'true')
      expect(page.context().pages()).toHaveLength(tabs)
      await expect(popup.getByRole('button', { name: 'พิมพ์ / บันทึก PDF', exact: true })).toBeEnabled({ timeout: 25_000 })
      await expect(popup.locator('.document-print-page')).toHaveCount(await page.locator('[data-document-canvas] .document-page').count())
      await expect(popup.locator('[data-record-field="record_result"]').first()).toContainText('แก้ไขผลการเรียนรู้จากตัวแก้ไขขั้นสูงและบันทึกก่อนพิมพ์')
      await expect(popup.locator('[data-print-hidden], [contenteditable], .is-selected')).toHaveCount(0)
      await page.locator('iframe[data-document-print-frame]').evaluate(frame => frame.remove())
      await saved(page)
      await page.reload()
      await expect(page.locator('[data-record-field="record_result"]')).toHaveText('แก้ไขผลการเรียนรู้จากตัวแก้ไขขั้นสูงและบันทึกก่อนพิมพ์')
      const model = await api<{ document: StoredDocument & { editorContext: { kind: string; recordId: string } } }>(page, 'GET', `/api/documents/${documentId}`)
      expect(model.data.document.editorContext).toEqual(expect.objectContaining({ kind: 'record', recordId }))
      expect((await api(page, 'DELETE', `/api/documents/${documentId}`)).status).toBe(422)
    } finally {
      const latest = await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${documentId}`)
      expect((await api(page, 'POST', `/api/documents/${documentId}/versions/${originalVersion}/restore`, { revision: latest.data.document.revision })).status).toBe(200)
    }
  })

  test('multi-page print uses physical A4 and downloads DOCX with the uploaded image', async ({ page }, testInfo) => {
    const text = 'ข้อความภาษาไทยสำหรับตรวจหน้าพิมพ์'
    await createDocument(page, { type: 'doc', content: Array.from({ length: 45 }, (_, index) => ({ type: 'paragraph', content: [{ type: 'text', text: `${text} ${index + 1} เนื้อหาต้องไม่หายเมื่อแบ่งหน้าอัตโนมัติ` }] })) })
    await uploadImage(page)
    await expect.poll(() => page.locator('[data-document-canvas] .document-page').count()).toBeGreaterThan(1)
    await page.getByRole('button', { name: 'พิมพ์ / PDF', exact: true }).click()
    const popup = page.frameLocator('iframe[data-document-print-frame]')
    await expect(page.locator('iframe[data-document-print-ready=true]')).toHaveCount(1)
    await expect(popup.locator('body')).toHaveAttribute('data-test-print-called', 'true')
    await expect(popup.getByRole('button', { name: 'พิมพ์ / บันทึก PDF', exact: true })).toBeEnabled({ timeout: 25_000 })
    expect(await popup.locator('.document-print-page').count()).toBeGreaterThan(1)
    await expect(popup.locator('[data-print-hidden], [contenteditable], .is-selected, .selectedCell, .show-marks')).toHaveCount(0)
    const dimensions = await popup.locator('.document-print-page').first().evaluate(element => ({ width: element.getBoundingClientRect().width, height: element.getBoundingClientRect().height }))
    expect(dimensions.width).toBeCloseTo(210 * 96 / 25.4, 0)
    expect(dimensions.height).toBeCloseTo(297 * 96 / 25.4, 0)
    const printFrame = await page.locator('iframe[data-document-print-frame]').elementHandle()
    const printDocument = await printFrame!.contentFrame()
    const verificationPage = await page.context().newPage()
    await verificationPage.goto('/')
    await verificationPage.setContent(await printDocument!.content())
    await expect.poll(() => verificationPage.locator('img').evaluateAll(images => images.every(image => (image as HTMLImageElement).complete && (image as HTMLImageElement).naturalWidth > 0))).toBe(true)
    await verificationPage.evaluate(() => document.fonts.ready)
    const pdf = await verificationPage.pdf({ path: testInfo.outputPath('thai-a4-pages.pdf'), preferCSSPageSize: true, printBackground: true })
    await verificationPage.close()
    expect(pdf.subarray(0, 5).toString()).toBe('%PDF-')
    const mediaBox = /\/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)/.exec(pdf.toString('latin1'))
    expect(mediaBox, 'Chrome must emit the CSS A4 media box').not.toBeNull()
    expect(Number(mediaBox![1])).toBeCloseTo(210 * 72 / 25.4, 0)
    expect(Number(mediaBox![2])).toBeCloseTo(297 * 72 / 25.4, 0)
    await page.locator('iframe[data-document-print-frame]').evaluate(frame => frame.remove())
    const downloadPromise = page.waitForEvent('download')
    await page.getByRole('button', { name: 'DOCX', exact: true }).click()
    const download = await downloadPromise
    expect(download.suggestedFilename()).toMatch(/\.docx$/)
    const filename = testInfo.outputPath('thai-document.docx')
    await download.saveAs(filename)
    const archive = await JSZip.loadAsync(await readFile(filename))
    const xml = await archive.file('word/document.xml')!.async('string')
    expect(xml).toContain(`${text} 1`)
    expect(xml).toContain(`${text} 45`)
    expect(Object.keys(archive.files).filter(name => /^word\/media\/.+\.png$/.test(name))).toHaveLength(1)
    await expect(page.getByRole('dialog', { name: 'เตรียม DOCX แล้ว มีข้อควรตรวจ', exact: true })).toContainText('ตำแหน่งวัตถุอาจต่าง')
  })
})
