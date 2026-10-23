import { randomUUID } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import { test, expect, type Page } from '@playwright/test'
import JSZip from 'jszip'

type JsonNode = { type: string; text?: string; attrs?: Record<string, unknown>; marks?: { type: string; attrs?: Record<string, unknown> }[]; content?: JsonNode[] }
type StoredDocument = { id: string; title: string; revision: number; content: JsonNode; objects: { id: string; type: string; x: number; content?: JsonNode }[]; assets: { id: string }[] }
const baseUrl = process.env.SENA_E2E_BASE_URL
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
  test.skip(!isLocalFixture, 'Supply SENA_E2E_BASE_URL pointing to a loopback test fixture; these tests never write to a remote deployment.')

  test.beforeEach(async ({ page }) => {
    owned.set(page, [])
    page.on('dialog', async dialog => { if (dialog.type() === 'beforeunload') await dialog.accept(); else await dialog.dismiss() })
    await page.goto('/login')
    await page.getByRole('textbox', { name: 'ชื่อผู้ใช้', exact: true }).fill(process.env.SENA_E2E_USER || 'document-demo')
    await page.getByRole('textbox', { name: 'รหัสผ่าน', exact: true }).fill(process.env.SENA_E2E_PASSWORD || 'DocumentDemo2026!')
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
    await expect(editor.locator('strong')).toHaveText(selected)
    await saved(page)
    await page.reload()
    await expect(editor).toHaveText(`${selected} และข้อความปกติ`)
    await expect(editor.locator('strong')).toHaveText(selected)
    const persisted = await api<{ document: StoredDocument }>(page, 'GET', `/api/documents/${document.id}`)
    const runs = persisted.data.document.content.content?.flatMap(node => node.content || []) || []
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

  test('the original record route uses the advanced editor with the original Thai form and saves before printing', async ({ page }) => {
    const recordId = process.env.SENA_E2E_RECORD_ID
    test.skip(!recordId, 'Provide the ID of a dedicated isolated report fixture to test the record binding.')
    await page.goto(`/records/${recordId}/document`)
    await expect(page.getByRole('textbox', { name: 'เนื้อหาเอกสาร', exact: true })).toBeVisible()
    await expect(page.locator('.de-source-note')).toContainText('จัดเอกสารก่อนพิมพ์')
    await expect(page.getByRole('button', { name: 'ตัวแก้ไขขั้นสูง / DOCX', exact: true })).toHaveCount(0)
    await expect(page.locator('.record-info-row:not(.record-info-row--compact)')).toHaveCount(6)
    await expect(page.locator('.record-signature')).toHaveCount(3)
    await expect.poll(() => page.locator('[data-document-canvas] .document-page').count()).toBe(1)
    const documentId = await page.locator('script[type="application/json"][data-page]').evaluate(element => JSON.parse(element.textContent || '{}').props.document.id) as string
    await saved(page)
    const versions = await api<{ versions: { id: string }[] }>(page, 'GET', `/api/documents/${documentId}/versions`)
    const originalVersion = versions.data.versions.at(-1)!.id
    try {
      const body = page.locator('[data-record-field="record_result"] p').first()
      expect(await body.evaluate(element => getComputedStyle(element).fontFamily)).toContain('THSarabunNew')
      await expect(page.getByRole('combobox', { name: 'แบบอักษร', exact: true })).toHaveValue('THSarabunNew')
      await body.fill('แก้ไขผลการเรียนรู้จากตัวแก้ไขขั้นสูงและบันทึกก่อนพิมพ์')
      const popupPromise = page.waitForEvent('popup')
      await page.getByRole('button', { name: 'บันทึกและพิมพ์', exact: true }).click()
      const popup = await popupPromise
      await expect(popup.getByRole('button', { name: 'พิมพ์ / บันทึก PDF', exact: true })).toBeEnabled({ timeout: 25_000 })
      await expect(popup.locator('.document-print-page')).toHaveCount(1)
      await expect(popup.locator('[data-record-field="record_result"]')).toContainText('แก้ไขผลการเรียนรู้จากตัวแก้ไขขั้นสูงและบันทึกก่อนพิมพ์')
      await expect(popup.locator('[data-print-hidden], [contenteditable], .is-selected')).toHaveCount(0)
      await popup.close()
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
    const popupPromise = page.waitForEvent('popup')
    await page.getByRole('button', { name: 'พิมพ์ / PDF', exact: true }).click()
    const popup = await popupPromise
    await expect(popup.getByRole('button', { name: 'พิมพ์ / บันทึก PDF', exact: true })).toBeEnabled({ timeout: 25_000 })
    expect(await popup.locator('.document-print-page').count()).toBeGreaterThan(1)
    await expect(popup.locator('[data-print-hidden], [contenteditable], .is-selected, .selectedCell, .show-marks')).toHaveCount(0)
    const dimensions = await popup.locator('.document-print-page').first().evaluate(element => ({ width: element.getBoundingClientRect().width, height: element.getBoundingClientRect().height }))
    expect(dimensions.width).toBeCloseTo(210 * 96 / 25.4, 0)
    expect(dimensions.height).toBeCloseTo(297 * 96 / 25.4, 0)
    const pdf = await popup.pdf({ path: testInfo.outputPath('thai-a4-pages.pdf'), preferCSSPageSize: true, printBackground: true })
    expect(pdf.subarray(0, 5).toString()).toBe('%PDF-')
    const mediaBox = /\/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)/.exec(pdf.toString('latin1'))
    expect(mediaBox, 'Chrome must emit the CSS A4 media box').not.toBeNull()
    expect(Number(mediaBox![1])).toBeCloseTo(210 * 72 / 25.4, 0)
    expect(Number(mediaBox![2])).toBeCloseTo(297 * 72 / 25.4, 0)
    await popup.close()
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
    await expect(page.getByRole('alert')).toContainText('ตำแหน่งวัตถุอาจต่าง')
  })
})
