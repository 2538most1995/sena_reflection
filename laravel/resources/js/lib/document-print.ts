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
  clone.querySelectorAll('[data-print-hidden],script,iframe,object,embed,button,input,textarea,select,.moveable-control-box,.document-object-handle,.document-resize-handle,.document-rotation-handle,.document-grid,.document-ruler,.document-alignment-guide,.column-resize-handle').forEach(element => element.remove())
  for (const element of [clone, ...clone.querySelectorAll<HTMLElement>('*')]) {
    element.removeAttribute('contenteditable')
    element.removeAttribute('tabindex')
    element.removeAttribute('autofocus')
    for (const attr of [...element.attributes]) if (attr.name.toLowerCase().startsWith('on')) element.removeAttribute(attr.name)
    element.classList.remove('is-selected', 'ProseMirror-focused', 'ProseMirror-selectednode', 'selectedCell', 'document-object-selected', 'show-marks')
    if (element.matches('a[href]') && /^\s*(?:javascript|data|vbscript):/i.test(element.getAttribute('href') || '')) element.removeAttribute('href')
  }
}

async function waitForPrintImages(printDocument: Document) {
  await Promise.all([...printDocument.images].map(async image => {
    image.loading = 'eager'
    if (image.decode) await image.decode()
    else if (!image.complete) await new Promise<void>((resolve, reject) => {
      image.addEventListener('load', () => resolve(), { once: true })
      image.addEventListener('error', () => reject(new Error('ภาพบางรูปโหลดไม่สำเร็จ')), { once: true })
    })
    if (!image.naturalWidth) throw new Error('ภาพบางรูปในตัวอย่างพิมพ์โหลดไม่สำเร็จ กรุณาลองใหม่')
  }))
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
  status.setAttribute('role', 'status')
  const toolbar = target.createElement('div')
  toolbar.className = 'document-print-toolbar'
  const title = target.createElement('strong')
  title.textContent = model.title || 'เอกสารไม่มีชื่อ'
  const button = target.createElement('button')
  button.type = 'button'
  button.textContent = 'พิมพ์ / บันทึก PDF'
  button.disabled = true
  button.addEventListener('click', () => { preview.focus(); preview.print() })
  toolbar.append(title, status, button)
  if (preview !== window) target.body.replaceChildren(toolbar)
  const size = pageDimensions(model.pageSettings)
  const canvasWidth = Number(canvas.dataset.pageWidth) || size.width * MM_TO_PX
  const printStyle = target.createElement('style')
  printStyle.textContent = `
    @page { size: ${size.width}mm ${size.height}mm; margin: 0; }
    html, body { margin: 0 !important; padding: 0 !important; min-width: 0 !important; height: auto !important; background: #e2e8f0 !important; }
    body { font-family: Sarabun, sans-serif; }
    .document-print-toolbar { position: sticky; top: 0; z-index: 2147483647; display: flex; align-items: center; gap: 20px; padding: 14px 24px; color: #0f172a; background: #fff; border-bottom: 1px solid #cbd5e1; font: 14px/1.5 sans-serif; }
    .document-print-toolbar strong { margin-right: auto; max-width: 40%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .document-print-toolbar button { border: 0; border-radius: 8px; padding: 9px 18px; color: white; background: #2563eb; cursor: pointer; }
    .document-print-toolbar button:disabled { opacity: .5; cursor: wait; }
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
    throw error
  } finally { if (timeout) clearTimeout(timeout) }
}
