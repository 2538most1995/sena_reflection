export type BrowserExportRecord = {
  record_id: string; revision_id: string; revision: number; subject: string; level: string; date: string; print_url: string
}

function abortIfNeeded(signal: AbortSignal) {
  if (signal.aborted) throw new DOMException('ยกเลิกการเตรียมเอกสาร', 'AbortError')
}

async function waitUntil(check: () => boolean, signal: AbortSignal, milliseconds = 45000) {
  const deadline = Date.now() + milliseconds
  while (!check()) {
    abortIfNeeded(signal)
    if (Date.now() > deadline) throw new Error('เตรียมเอกสารเกินเวลา กรุณาลองโหลดอีกครั้ง')
    await new Promise(resolve => setTimeout(resolve, 80))
  }
}

/** Each snapshot keeps its own stylesheet tree. Global @page and fonts alone are shared. */
export function browserExportStyles(source: Document, pageName: string) {
  const global: string[] = []
  const urls = (css: string, base: string) => css.replace(/url\(\s*(['"]?)(.*?)\1\s*\)/g, (whole, _quote, url: string) => {
    if (!url || url.startsWith('#') || /^(?:data|blob):/i.test(url)) return whole
    return `url(${JSON.stringify(new URL(url, base).href)})`
  })
  const selectors = (value: string) => value
    .replace(/(^|[\s,>+~(])(?:html|:root)(?=[\s.#:[>+~),]|$)/g, '$1.browser-export-html')
    .replace(/(^|[\s,>+~(])body(?=[\s.#:[>+~),]|$)/g, '$1.browser-export-body')
  const serialize = (rules: CSSRuleList, base: string): string => Array.from(rules).map(rule => {
    if (rule.type === CSSRule.IMPORT_RULE) {
      const imported = rule as CSSImportRule
      if (!imported.styleSheet) throw new Error('โหลดรูปแบบเอกสารไม่ครบ')
      const css = serialize(imported.styleSheet.cssRules, imported.href)
      return imported.media.length ? `@media ${imported.media.mediaText}{${css}}` : css
    }
    if (rule.type === CSSRule.FONT_FACE_RULE) { global.push(urls(rule.cssText, base)); return '' }
    if (rule.type === CSSRule.PAGE_RULE) {
      const page = rule as CSSPageRule
      const suffix = page.selectorText.replace(/^[\w-]+/, '')
      global.push(`@page ${pageName}${suffix}{${page.style.cssText}}`)
      return ''
    }
    if (rule.type === CSSRule.STYLE_RULE) {
      const style = rule as CSSStyleRule
      return `${selectors(style.selectorText)}{${urls(style.style.cssText, base)}${style.cssRules?.length ? serialize(style.cssRules, base) : ''}}`
    }
    if ('cssRules' in rule) {
      const grouping = rule as CSSGroupingRule
      return `${rule.cssText.slice(0, rule.cssText.indexOf('{'))}{${serialize(grouping.cssRules, base)}}`
    }
    return urls(rule.cssText, base)
  }).join('\n')
  const css = Array.from(source.styleSheets).map(sheet => serialize(sheet.cssRules, sheet.href || source.baseURI)).join('\n')
  return { css, global: [...new Set(global)].join('\n') }
}

function cloneSnapshot(source: Document) {
  const html = document.createElement('div')
  html.className = `browser-export-html ${source.documentElement.className}`
  html.style.cssText = source.documentElement.style.cssText
  html.style.setProperty('--html-preview-scale', '1')
  const body = document.createElement('div')
  body.className = `browser-export-body ${source.body.className}`
  body.style.cssText = source.body.style.cssText
  for (const child of Array.from(source.body.childNodes)) body.append(document.importNode(child, true))
  const originals = Array.from(source.body.querySelectorAll<HTMLImageElement>('img'))
  body.querySelectorAll<HTMLImageElement>('img').forEach((image, index) => {
    image.src = originals[index]?.currentSrc || new URL(image.getAttribute('src') || '', source.baseURI).href
    image.removeAttribute('srcset'); image.loading = 'eager'
  })
  body.querySelectorAll('script,style,link,iframe,object,embed,button,input,textarea,select,.browser-print-toolbar,.document-print-toolbar,[data-print-hidden]').forEach(node => node.remove())
  for (const element of [body, ...body.querySelectorAll<HTMLElement>('*')]) {
    element.removeAttribute('contenteditable')
    for (const attribute of Array.from(element.attributes)) if (attribute.name.toLowerCase().startsWith('on')) element.removeAttribute(attribute.name)
  }
  html.append(body)
  return html
}

async function waitForImportedAssets(shadow: ShadowRoot, signal: AbortSignal) {
  let timer: ReturnType<typeof setTimeout> | undefined
  let onAbort: () => void = () => {}
  try {
    await Promise.race([
      (async () => {
        await document.fonts.ready
        await Promise.all(Array.from(shadow.querySelectorAll('img')).map(async image => {
          if (image.decode) await image.decode()
          if (!image.complete || !image.naturalWidth) throw new Error('โหลดรูปภาพในเอกสารรวมไม่สำเร็จ กรุณาลองใหม่')
        }))
      })(),
      new Promise<never>((_, reject) => {
        timer = setTimeout(() => reject(new Error('โหลดฟอนต์หรือรูปภาพเกินเวลา กรุณาลองใหม่')), 25000)
        onAbort = () => reject(new DOMException('ยกเลิกการเตรียมเอกสาร', 'AbortError'))
        signal.addEventListener('abort', onAbort, { once: true })
        if (signal.aborted) onAbort()
      }),
    ])
  } finally { clearTimeout(timer); signal.removeEventListener('abort', onAbort) }
}

function assertSnapshotGeometry(source: Document, clone: ShadowRoot) {
  const selector = source.querySelector('.document-print-page') ? '.document-print-page' : '.page'
  const originals = Array.from(source.querySelectorAll<HTMLElement>(selector))
  const copies = Array.from(clone.querySelectorAll<HTMLElement>(selector))
  const positions = (page: HTMLElement) => {
    const origin = page.getBoundingClientRect()
    const rects: number[][] = []
    const walker = page.ownerDocument.createTreeWalker(page, NodeFilter.SHOW_TEXT)
    while (walker.nextNode()) {
      const node = walker.currentNode
      if (!node.textContent?.trim()) continue
      const range = page.ownerDocument.createRange()
      range.selectNodeContents(node)
      for (const rectangle of Array.from(range.getClientRects())) {
        if (!rectangle.width || !rectangle.height || rectangle.bottom <= origin.top || rectangle.top >= origin.bottom) continue
        rects.push([rectangle.left - origin.left, rectangle.top - origin.top, rectangle.width, rectangle.height])
      }
    }
    for (const image of Array.from(page.querySelectorAll('img'))) {
      const rectangle = image.getBoundingClientRect()
      rects.push([rectangle.left - origin.left, rectangle.top - origin.top, rectangle.width, rectangle.height])
    }
    return rects
  }
  if (originals.length !== copies.length) throw new Error('จำนวนหน้าเอกสารรวมไม่ตรงกับฉบับเดิม')
  originals.forEach((page, index) => {
    const before = positions(page), after = positions(copies[index])
    if (before.length !== after.length || before.some((rect, i) => rect.some((value, j) => Math.abs(value - after[i][j]) > 0.75))) {
      throw new Error('ตำแหน่งข้อความหรือรูปในเอกสารรวมไม่ตรงกับฉบับเดิม กรุณาลองใหม่ หรือเปิดพิมพ์เอกสารทีละรายการ')
    }
  })
}

/** Read-only browser rendering: the saved revision is never rewritten or rasterized. */
export async function prepareBrowserExport(records: BrowserExportRecord[], target: HTMLElement, signal: AbortSignal, progress: (completed: number, pages: number) => void) {
  target.replaceChildren()
  let pages = 0
  // Sequential frames bound memory and avoid hammering shared hosting with concurrent renders.
  for (const [index, record] of records.entries()) {
    abortIfNeeded(signal)
    const url = new URL(record.print_url, window.location.href)
    if (url.origin !== window.location.origin) throw new Error('ลิงก์เอกสารไม่ตรงกับเว็บไซต์นี้ กรุณาเปิดหน้าสร้าง PDF ใหม่')
    const response = await fetch(url, { credentials: 'same-origin', signal, headers: { Accept: 'text/html' } })
    if (!response.ok || response.redirected) throw new Error(`เปิดเอกสาร ${record.subject} ไม่สำเร็จ (${response.status}) กรุณาเปิดหน้าสร้าง PDF ใหม่`)
    const content = await response.text()
    abortIfNeeded(signal)
    const frame = document.createElement('iframe')
    frame.title = `เตรียมเอกสาร ${index + 1}`
    frame.setAttribute('aria-hidden', 'true')
    frame.style.cssText = 'position:fixed;left:0;top:0;width:1280px;height:1000px;border:0;opacity:0;pointer-events:none;z-index:-1;'
    frame.srcdoc = content
    document.body.append(frame)
    try {
      await waitUntil(() => {
        const source = frame.contentDocument
        if (!source?.body) return false
        if (source.body.dataset.documentPrintError === 'true') throw new Error(source.querySelector('[role="alert"]')?.textContent || 'จัดหน้าเอกสารไม่สำเร็จ')
        const retry = source.querySelector<HTMLElement>('#browser-print-retry')
        if (retry && !retry.hidden) throw new Error(source.querySelector('#browser-print-status')?.textContent || 'โหลดฟอนต์หรือรูปภาพไม่สำเร็จ')
        return source.body.dataset.documentPrintReady === 'true' || source.querySelector<HTMLButtonElement>('#browser-print-button')?.disabled === false
      }, signal)
      abortIfNeeded(signal)
      const source = frame.contentDocument!
      const count = source.querySelectorAll('.document-print-page').length || source.querySelectorAll('.page').length
      if (!count) throw new Error(`ไม่พบหน้าพิมพ์ของ ${record.subject}`)
      const pageName = `sena_export_${index}`
      const styles = browserExportStyles(source, pageName)
      const global = document.createElement('style')
      global.textContent = styles.global
      const host = document.createElement('section')
      host.className = 'browser-export-print-page'
      host.setAttribute('aria-label', `${record.subject} · ${record.date}`)
      host.style.page = pageName
      // Shadow DOM prevents different revisions' legacy selectors and page variables from mixing.
      const shadow = host.attachShadow({ mode: 'open' })
      const style = document.createElement('style')
      style.textContent = `${styles.css}\n:host{all:initial;display:block;page:${pageName};} .browser-export-html{display:block;} @media print{.browser-export-html,.browser-export-body{background:white!important;}.document-print-page{box-shadow:none!important;}}`
      shadow.append(style, cloneSnapshot(source))
      target.append(global, host)
      // Force font usage before waiting: imported faces may only start loading on the first layout.
      host.getBoundingClientRect()
      await waitForImportedAssets(shadow, signal)
      abortIfNeeded(signal)
      if (Array.from(document.fonts).some(font => font.status === 'error')) throw new Error('โหลดฟอนต์เอกสารรวมไม่สำเร็จ กรุณาลองใหม่')
      assertSnapshotGeometry(source, shadow)
      pages += count
      progress(index + 1, pages)
    } catch (error) {
      if (signal.aborted) throw error
      const detail = error instanceof Error ? error.message : 'โหลดเอกสารไม่สำเร็จ'
      throw new Error(`บันทึกที่ ${index + 1}/${records.length} (${record.subject} · ${record.level} · ${record.date}): ${detail}`, { cause: error })
    } finally { frame.remove() }
  }
  abortIfNeeded(signal)
  return pages
}
