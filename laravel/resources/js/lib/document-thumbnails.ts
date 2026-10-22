export type DocumentPageView = { index: number; top: number; width: number; height: number; thumbnail?: string }
const embeddedAssets = new Map<string, Promise<string>>()
async function embed(url: string): Promise<string> {
  if (url.startsWith('data:')) return url
  const resolved = new URL(url, location.href)
  if (resolved.origin !== location.origin) return url
  if (!embeddedAssets.has(resolved.href)) embeddedAssets.set(resolved.href, (async () => {
    const response = await fetch(resolved.href, { credentials: 'same-origin' })
    if (!response.ok) throw new Error('อ่านไฟล์สำหรับภาพตัวอย่างไม่สำเร็จ')
    const blob = await response.blob()
    return new Promise<string>((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(String(reader.result)); reader.onerror = reject; reader.readAsDataURL(blob) })
  })())
  return embeddedAssets.get(resolved.href)!
}
export async function createDocumentThumbnails(canvas: HTMLElement, pages: DocumentPageView[]): Promise<string[]> {
  const clone = canvas.cloneNode(true) as HTMLElement
  clone.querySelectorAll('[data-print-hidden], [data-editor-ui], .document-object-handle, .document-rotation-handle').forEach((node) => node.remove())
  clone.querySelectorAll('[contenteditable]').forEach((node) => node.removeAttribute('contenteditable'))
  await Promise.allSettled(Array.from(clone.querySelectorAll('img')).map(async (image) => { image.src = await embed(image.src) }))
  let css = ''
  for (const sheet of Array.from(document.styleSheets)) { try { css += Array.from(sheet.cssRules).map((rule) => rule.cssText).join('\n') } catch { /* Cross-origin optional styles do not affect the local editor. */ } }
  const urls = [...new Set(Array.from(css.matchAll(/url\(["']?([^\)"']+)["']?\)/g)).map((match) => match[1]).filter((url) => /\.(woff2?|ttf)(?:\?|$)/.test(url)))]
  await Promise.allSettled(urls.map(async (url) => { const data = await embed(url); css = css.split(url).join(data) }))
  return pages.map((page) => {
    const svgNamespace = 'http://www.w3.org/2000/svg'
    const htmlNamespace = 'http://www.w3.org/1999/xhtml'
    const tree = document.implementation.createDocument(svgNamespace, 'svg')
    const svg = tree.documentElement
    svg.setAttribute('width', '120'); svg.setAttribute('height', String(Math.round(120 * page.height / page.width))); svg.setAttribute('viewBox', `0 0 ${page.width} ${page.height}`)
    const foreign = tree.createElementNS(svgNamespace, 'foreignObject')
    foreign.setAttribute('width', String(page.width)); foreign.setAttribute('height', String(page.height))
    const viewport = tree.createElementNS(htmlNamespace, 'div')
    viewport.setAttribute('style', `width:${page.width}px;height:${page.height}px;overflow:hidden;background:white`)
    const style = tree.createElementNS(htmlNamespace, 'style')
    style.textContent = `${css}\n.document-object.is-selected:after{display:none!important}.document-flow-reservation{outline:none!important}`
    const offset = tree.createElementNS(htmlNamespace, 'div')
    offset.setAttribute('style', `transform:translateY(-${page.top}px);transform-origin:top left;width:${page.width}px`)
    offset.appendChild(tree.importNode(clone, true)); viewport.append(style, offset); foreign.appendChild(viewport); svg.appendChild(foreign)
    return URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(tree)], { type: 'image/svg+xml;charset=utf-8' }))
  })
}
