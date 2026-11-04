import { appBasePath, resolveAppUrl, safeAppHistoryUrl } from './app-url'
import { MM_TO_PX, PAGE_GAP, pageDimensions } from './document-model'
import type { WebDocument } from './document-model'

export function documentPrintPageGeometry(model: WebDocument, pageCount: number) {
  const size = pageDimensions(model.pageSettings)
  return Array.from({ length: Math.max(1, pageCount) }, (_, index) => ({ page: index + 1, widthMm: size.width, heightMm: size.height, sourceTopPx: index * (size.height * MM_TO_PX + PAGE_GAP) }))
}

/** TablePlus puts column controls in the slider immediately before its table. */
export function removeTablePlusPrintControls(clone: HTMLElement) {
  for (const table of clone.querySelectorAll<HTMLTableElement>('table.table-plus')) {
    const slider = table.previousElementSibling
    if (slider?.tagName !== 'DIV') continue
    slider.querySelectorAll(':scope > .handle[data-index]').forEach(handle => handle.remove())
  }
}

/** Only editor chrome is removed; the actual text nodes, styles and object coordinates are retained. */
function cleanPrintClone(clone: HTMLElement) {
  removeTablePlusPrintControls(clone)
  clone.querySelectorAll('[data-print-hidden],.ProseMirror-separator,script,iframe,object,embed,button,input,textarea,select,.moveable-control-box,.document-object-handle,.document-resize-handle,.document-rotation-handle,.document-grid,.document-ruler,.document-alignment-guide,.column-resize-handle').forEach(element => element.remove())
  for (const element of [clone, ...clone.querySelectorAll<HTMLElement>('*')]) {
    element.removeAttribute('contenteditable')
    element.removeAttribute('tabindex')
    element.removeAttribute('autofocus')
    for (const attr of [...element.attributes]) if (attr.name.toLowerCase().startsWith('on')) element.removeAttribute(attr.name)
    element.classList.remove('is-selected', 'ProseMirror-focused', 'ProseMirror-selectednode', 'selectedCell', 'document-object-selected', 'show-marks')
    if (element.matches('a[href]') && /^\s*(?:javascript|data|vbscript):/i.test(element.getAttribute('href') || '')) element.removeAttribute('href')
  }
}

async function waitForImageLoad(image: HTMLImageElement): Promise<void> {
  if (image.complete) return
  await new Promise<void>((resolve, reject) => {
    const finish = (error?: Error) => {
      clearTimeout(timer)
      image.removeEventListener('load', loaded)
      image.removeEventListener('error', failed)
      if (error) reject(error)
      else resolve()
    }
    const loaded = () => finish()
    const failed = () => finish(new Error('โหลดรูปภาพไม่สำเร็จ'))
    const timer = setTimeout(() => finish(new Error('โหลดรูปภาพเกินเวลา')), 15000)
    image.addEventListener('load', loaded, { once: true })
    image.addEventListener('error', failed, { once: true })
    if (image.complete) loaded()
  })
}

/** A rejected decode is recoverable only when the browser can actually read the loaded image's pixels. */
export async function waitForPrintImages(printDocument: Document): Promise<void> {
  const images = Array.from(printDocument.images)
  const results = await Promise.allSettled(images.map(async image => {
    delete image.dataset.printImageFailed
    image.loading = 'eager'
    await waitForImageLoad(image)
    if (!image.complete || !image.naturalWidth || !image.naturalHeight) throw new Error('รูปภาพไม่สมบูรณ์')
    try {
      if (image.decode) await image.decode()
    } catch {
      const canvas = image.ownerDocument.createElement('canvas')
      canvas.width = canvas.height = 1
      try {
        const context = canvas.getContext('2d', { willReadFrequently: true })
        if (!context) throw new Error('อ่านรูปภาพไม่สำเร็จ')
        context.drawImage(image, 0, 0, 1, 1)
        context.getImageData(0, 0, 1, 1)
      } finally { canvas.width = canvas.height = 0 }
    }
    if (!image.complete || !image.naturalWidth || !image.naturalHeight) throw new Error('รูปภาพไม่สมบูรณ์')
  }))
  const sources = [...new Set(images.map((image, index) => image.currentSrc || image.src || `missing-${index}`))]
  const failures = new Set<number>()
  results.forEach((result, index) => {
    if (result.status === 'fulfilled') return
    const image = images[index]
    image.dataset.printImageFailed = 'true'
    failures.add(sources.indexOf(image.currentSrc || image.src || `missing-${index}`) + 1)
  })
  if (failures.size) throw new Error(`โหลดรูปภาพไม่สำเร็จ ${failures.size} รูป (รูปที่ ${[...failures].join(', ')}) กรุณาย้อนกลับตรวจรูป หรือลองโหลดหน้านี้ใหม่`)
}

async function settlePrintPaint(target: Window) {
  await new Promise<void>(resolve => {
    let first = 0, second = 0
    const finish = () => { target.clearTimeout(timer); target.cancelAnimationFrame(first); target.cancelAnimationFrame(second); resolve() }
    const timer = target.setTimeout(finish, 50)
    first = target.requestAnimationFrame(() => { second = target.requestAnimationFrame(finish) })
  })
}

/**
 * Physical page viewports clip copies of the already-paginated live DOM. Keeping the original gaps
 * inside each copy avoids reflowing ProseMirror's float-based separators or moving anchored objects.
 * No page is rasterized: printed text remains selectable and searchable.
 */
export async function openDocumentPrint(model: WebDocument, canvas: HTMLElement, existingPreview?: Window | null): Promise<Window | null> {
  const preview = existingPreview === undefined ? window.open('', '_blank', 'popup,width=1100,height=850') : existingPreview
  if (!preview) return null
  const target = preview.document
  target.title = model.title || 'พิมพ์เอกสาร'
  target.documentElement.lang = 'th'
  const base = target.createElement('base')
  base.href = document.baseURI
  target.head.append(base)
  const status = target.createElement('span')
  status.textContent = 'กำลังเตรียมหน้าพิมพ์และโหลดฟอนต์…'
  status.id = 'browser-print-status'
  status.setAttribute('role', 'status')
  status.setAttribute('aria-live', 'polite')
  const toolbar = target.createElement('div')
  toolbar.className = 'document-print-toolbar'
  const title = target.createElement('strong')
  title.textContent = model.title || 'เอกสารไม่มีชื่อ'
  const icon = (path: string) => {
    const svg = target.createElementNS('http://www.w3.org/2000/svg', 'svg')
    for (const [key, value] of Object.entries({ viewBox: '0 0 24 24', width: '18', height: '18', fill: 'none', stroke: 'currentColor', 'stroke-width': '1.8', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true' })) svg.setAttribute(key, value)
    const line = target.createElementNS('http://www.w3.org/2000/svg', 'path')
    line.setAttribute('d', path); svg.append(line)
    return svg
  }
  const button = target.createElement('button')
  button.type = 'button'
  button.id = 'browser-print-button'
  button.append(icon('M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z'), target.createTextNode('พิมพ์ / บันทึก PDF'))
  button.disabled = true
  button.addEventListener('click', () => { preview.focus(); preview.print() })
  const back = target.createElement('a')
  back.id = 'browser-print-back'
  back.append(icon('M19 12H5m7-7-7 7 7 7'), target.createTextNode('ย้อนกลับ'))
  const printBasePath = /^(.*?)\/(?:records|exports|documents)(?:\/|$)/.exec(preview.location.pathname)?.[1] ?? appBasePath
  const recordId = model.sourceRecordId || preview.location.pathname.match(/\/records\/([^/]+)\/browser-print\/?$/)?.[1]
  back.href = resolveAppUrl(model.editorContext?.returnUrl || (recordId ? `/records/${recordId}` : '/documents'), printBasePath)
  back.addEventListener('click', event => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
    const navigation = (preview as Window & { navigation?: { currentEntry: { index: number } | null; entries: () => { index: number; url: string | null }[] } }).navigation
    const previous = navigation?.currentEntry
      ? navigation.entries().find(entry => entry.index === navigation.currentEntry!.index - 1)?.url
      : target.referrer
    if (preview.history.length > 1 && safeAppHistoryUrl(previous, preview.location.href, printBasePath)) {
      event.preventDefault()
      preview.history.back()
    }
  })
  const heading = target.createElement('div')
  heading.className = 'document-print-heading'
  heading.append(title, status)
  const actions = target.createElement('div')
  actions.className = 'document-print-actions'
  const retry = target.createElement('button')
  retry.id = 'browser-print-retry'
  retry.type = 'button'
  retry.hidden = true
  retry.textContent = 'ลองโหลดอีกครั้ง'
  retry.addEventListener('click', () => preview.location.reload())
  actions.append(back, retry, button)
  toolbar.append(heading, actions)
  if (preview !== window) target.body.replaceChildren(toolbar)
  const size = pageDimensions(model.pageSettings)
  const canvasWidth = Number(canvas.dataset.pageWidth) || size.width * MM_TO_PX
  const printStyle = target.createElement('style')
  printStyle.textContent = `
    @page { size: ${size.width}mm ${size.height}mm; margin: 0; }
    html, body { margin: 0 !important; padding: 0 !important; min-width: 0 !important; height: auto !important; background: #e2e8f0 !important; }
    body { font-family: Sarabun, sans-serif; }
    .document-print-toolbar { position:sticky;top:0;z-index:2147483647;display:flex;align-items:center;justify-content:space-between;gap:24px;padding:16px 24px;color:#0f172a;background:#fff;border-bottom:1px solid #e2e8f0;box-shadow:0 1px 4px #0f172a08;font:14px/1.5 Anuphan,Sarabun,system-ui,sans-serif; }
    .document-print-heading { min-width:0;flex:1; }
    .document-print-heading strong { display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:15px;font-weight:600; }
    .document-print-heading > span { display:block;margin-top:3px;color:#64748b;font-size:13px;line-height:1.6;overflow-wrap:anywhere; }
    .document-print-actions { display:flex;align-items:center;gap:10px;flex-shrink:0; }
    .document-print-actions a,.document-print-actions button { box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:10px 16px;border:1px solid #2563eb;border-radius:12px;background:#2563eb;color:#fff;font:600 14px/1.5 Anuphan,Sarabun,system-ui,sans-serif;text-decoration:none;white-space:nowrap;cursor:pointer;transition:transform 120ms cubic-bezier(.23,1,.32,1); }
    .document-print-actions #browser-print-back,.document-print-actions #browser-print-retry { border-color:#dbe3ee;background:#f8fafc;color:#334155;box-shadow:0 1px 2px #0f172a05; }
    .document-print-actions svg { flex-shrink:0; }
    .document-print-actions :focus-visible { outline:3px solid #93c5fd;outline-offset:3px; }
    .document-print-actions button:disabled { opacity:.5;cursor:wait; }
    .document-print-actions [hidden] { display:none!important; }
    .document-print-canvas img[data-print-image-failed] { outline:2px solid #dc2626;outline-offset:2px; }
    @media(hover:hover) and (pointer:fine) { .document-print-actions #browser-print-back:hover,.document-print-actions #browser-print-retry:hover { background:#eff6ff;border-color:#93c5fd;color:#1d4ed8; } .document-print-actions button:not(:disabled):active,.document-print-actions a:active { transform:scale(.97); } }
    @media(max-width:640px) { .document-print-toolbar { flex-wrap:wrap;gap:12px;padding:12px 16px; } .document-print-heading { flex-basis:100%; } .document-print-actions { width:100%;flex-wrap:wrap; } .document-print-actions #browser-print-button { flex:1; } }
    @media(prefers-reduced-motion:reduce) { .document-print-actions a,.document-print-actions button { transition:none;transform:none!important; } }
    .document-print-page { position: relative !important; display: block !important; width: ${size.width}mm !important; height: ${size.height}mm !important; min-height: ${size.height}mm !important; max-height: ${size.height}mm !important; margin: 24px auto; padding: 0 !important; overflow: hidden !important; background: white; box-shadow: 0 1px 10px #0002; break-inside: avoid; break-after: page; page-break-after: always; }
    .document-print-page:last-child { break-after: auto; page-break-after: auto; }
    .document-print-canvas { position: absolute !important; left: 0 !important; width: ${canvasWidth}px !important; margin: 0 !important; transform: none !important; zoom: 1 !important; outline: 0 !important; }
    .document-print-canvas .document-page { box-shadow: none !important; outline: 0 !important; }
    .document-print-canvas .document-object { outline: 0 !important; box-shadow: none !important; cursor: default !important; }
    .document-print-canvas .ProseMirror { outline: 0 !important; caret-color: transparent !important; }
    .document-print-canvas [data-print-hidden], .document-print-canvas .ProseMirror-gapcursor, .document-print-canvas .ProseMirror-dropcursor, .document-print-canvas .document-page-number-label { display: none !important; }
    .document-print-canvas * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .document-print-canvas p, .document-print-canvas li, .document-print-canvas h1, .document-print-canvas h2, .document-print-canvas h3, .document-print-canvas pre, .document-print-canvas blockquote { orphans: 1 !important; widows: 1 !important; break-inside: auto !important; page-break-inside: auto !important; }
    @media print {
      html, body { width: ${size.width}mm !important; background: white !important; }
      .document-print-toolbar { display: none !important; }
      .document-print-page { margin: 0 !important; box-shadow: none !important; }
    }
  `
  let timeout: ReturnType<typeof setTimeout> | undefined
  try {
    // Let pending selection/drag transactions and PaginationPlus finish before freezing the layout.
    await document.fonts.ready
    await settlePrintPaint(window)
    if (preview.closed) throw new Error('หน้าต่างตัวอย่างพิมพ์ถูกปิดแล้ว')
    const sourcePages = Array.from(canvas.querySelectorAll<HTMLElement>('.document-page'))
    const pages = documentPrintPageGeometry(model, sourcePages.length).map((page, index) => ({ ...page, sourceTopPx: Number.parseFloat(sourcePages[index]?.style.top || '') || page.sourceTopPx }))
    const computed = getComputedStyle(canvas)
    const cssVariables = [...computed].filter(property => property.startsWith('--')).map(property => [property, computed.getPropertyValue(property)])
    const frozen = canvas.cloneNode(true) as HTMLElement
    const stylesheets = Array.from(document.querySelectorAll<HTMLStyleElement | HTMLLinkElement>('style,link[rel="stylesheet"]'))
    if (preview === window) target.body.replaceChildren(toolbar)
    const stylesheetPromises: Promise<void>[] = []
    for (const source of stylesheets) {
      const style = target.importNode(source, true)
      if (source instanceof HTMLLinkElement) {
        const link = style as HTMLLinkElement
        link.href = source.href
        stylesheetPromises.push(new Promise<void>((resolve, reject) => {
          link.addEventListener('load', () => resolve(), { once: true })
          link.addEventListener('error', () => reject(new Error('โหลดรูปแบบหน้าพิมพ์ไม่สำเร็จ กรุณาลองใหม่')), { once: true })
        }))
      }
      target.head.append(style)
    }
    target.head.append(printStyle)
    for (const page of pages) {
      const viewport = target.createElement('section')
      viewport.className = 'document-print-page'
      viewport.setAttribute('aria-label', `หน้า ${page.page}`)
      const clone = target.importNode(frozen, true)
      cleanPrintClone(clone)
      clone.classList.add('document-print-canvas')
      clone.style.setProperty('top', `${-page.sourceTopPx}px`, 'important')
      // CSS variables configured by the live layout are inherited from the app wrapper.
      for (const [property, value] of cssVariables) clone.style.setProperty(property, value)
      // Override inherited print styles from the app: the physical viewport controls page breaks.
      clone.style.setProperty('transform', 'none', 'important')
      clone.style.setProperty('zoom', '1', 'important')
      viewport.append(clone)
      target.body.append(viewport)
    }
    const ready = async () => {
      await Promise.all(stylesheetPromises)
      await target.fonts.ready
      await waitForPrintImages(target)
      await settlePrintPaint(preview)
    }
    await Promise.race([ready(), new Promise<never>((_, reject) => { timeout = setTimeout(() => reject(new Error('เตรียมหน้าพิมพ์เกินเวลา กรุณาตรวจสอบการเชื่อมต่อแล้วลองใหม่')), 25000) })])
    if (preview.closed) throw new Error('หน้าต่างตัวอย่างพิมพ์ถูกปิดแล้ว')
    status.textContent = `พร้อมพิมพ์ ${pages.length} หน้า · เลือก Save as PDF เพื่อบันทึก PDF`
    button.disabled = false
    preview.focus()
    return preview
  } catch (error) {
    status.textContent = error instanceof Error ? error.message : 'เตรียมหน้าพิมพ์ไม่สำเร็จ กรุณาลองใหม่'
    status.style.color = '#b91c1c'
    retry.hidden = preview !== window
    throw error
  } finally { if (timeout) clearTimeout(timeout) }
}

/** Print the frozen canvas in this tab, retaining the editor and its browser history. */
export async function printDocumentInPlace(model: WebDocument, canvas: HTMLElement): Promise<void> {
  const frame = document.createElement('iframe')
  frame.dataset.documentPrintFrame = 'true'
  frame.title = 'เอกสารพร้อมพิมพ์'
  frame.setAttribute('aria-hidden', 'true')
  frame.style.cssText = 'position:fixed;left:-20000px;top:0;width:1123px;height:1500px;border:0;'
  document.body.append(frame)
  let timer: ReturnType<typeof setTimeout> | undefined
  const cleanup = () => { if (timer) clearTimeout(timer); frame.remove(); window.focus() }
  try {
    const target = frame.contentWindow
    if (!target) throw new Error('เปิดพื้นที่พิมพ์ไม่สำเร็จ กรุณาลองใหม่')
    target.addEventListener('afterprint', () => setTimeout(cleanup, 800), { once: true })
    timer = setTimeout(cleanup, 60000)
    await openDocumentPrint(model, canvas, target)
    frame.dataset.documentPrintReady = 'true'
    target.focus()
    target.print()
  } catch (error) { cleanup(); throw error }
}
