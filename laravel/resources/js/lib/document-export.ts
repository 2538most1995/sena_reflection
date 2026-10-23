import type { JSONContent } from '@tiptap/core'
import {
  AlignmentType, BorderStyle, Document as DocxDocument, ExternalHyperlink, Footer, Header,
  HeadingLevel, HeightRule, HorizontalPositionRelativeFrom, ImageRun, LevelFormat, LineRuleType,
  Packer, PageBreak, PageNumber, Paragraph, ShadingType, Table, TableCell, TableRow,
  TableLayoutType, TextRun, TextWrappingSide, TextWrappingType, UnderlineType, VerticalPositionRelativeFrom, WidthType,
} from 'docx'
import type { ICropOptions, IFloating, IImageOptions, ILevelsOptions, IParagraphOptions, IRunOptions, ParagraphChild } from 'docx'
import { ShapeRun } from 'docx/shapes'
import { MM_TO_PX, pageDimensions } from './document-model'
import type { DocumentObject, WebDocument } from './document-model'

type Block = Paragraph | Table
type LoadedImage = { data: Uint8Array; type: 'png' | 'jpg'; width?: number; height?: number }
export type DocxExportOptions = {
  /** Injectable transport for tests; production permits only authenticated same-origin assets. */
  fetch?: typeof fetch
  baseUrl?: string
  convertWebp?: (blob: Blob) => Promise<Uint8Array>
}
type Context = {
  model: WebDocument
  options: DocxExportOptions
  warnings: Set<string>
  numbering: { reference: string; levels: ILevelsOptions[] }[]
  images: Map<string, Promise<LoadedImage>>
  objectsByParagraph: Map<string, DocumentObject[]>
  objectsByPage: Map<number, DocumentObject[]>
  placedObjects: Set<string>
  pagination: { explicitPage: number; pageHasAnchor: boolean }
  recordTemplate: boolean
  paragraphDefaults?: IParagraphOptions
  runDefaults?: IRunOptions
  availableWidth?: number
}
type ListContext = { reference: string; level: number; first: boolean }

const mmToTwips = (value: number) => Math.round(value * 1440 / 25.4)
const mmToEmu = (value: number) => Math.round(value * 36000)
const numberValue = (value: unknown, fallback = 0) => value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value)) ? Number(value) : fallback
const attrsOf = (node: JSONContent) => node.attrs || {}
const childrenOf = (node: JSONContent) => node.content || []
const pxToTwips = (value: number) => Math.round(value * 15)
const recordNodeTypes = new Set(['recordInfoRow', 'recordInfoCell', 'recordSectionTitle', 'recordBody', 'recordSignature', 'recordGroup'])
const noBorder = { style: BorderStyle.NONE, size: 0, color: '000000' } as const
const dottedBorder = { style: BorderStyle.DOTTED, size: 4, color: '000000', space: 0 } as const
const noTableBorders = { top: noBorder, right: noBorder, bottom: noBorder, left: noBorder, insideHorizontal: noBorder, insideVertical: noBorder }
const noCellBorders = { top: noBorder, right: noBorder, bottom: noBorder, left: noBorder }
const paragraphAlignments: Record<string, IParagraphOptions['alignment']> = { left: AlignmentType.LEFT, center: AlignmentType.CENTER, right: AlignmentType.RIGHT, justify: AlignmentType.JUSTIFIED, 'thai-distributed': AlignmentType.THAI_DISTRIBUTE }

function hexColor(value: unknown): string | undefined {
  if (typeof value !== 'string') return undefined
  const trimmed = value.trim()
  if (/^#[\da-f]{6}$/i.test(trimmed)) return trimmed.slice(1).toUpperCase()
  if (/^#[\da-f]{3}$/i.test(trimmed)) return trimmed.slice(1).split('').map(letter => letter + letter).join('').toUpperCase()
  const rgb = /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*[\d.]+)?\s*\)$/i.exec(trimmed)
  if (rgb) return rgb.slice(1, 4).map(part => Math.min(255, Number(part)).toString(16).padStart(2, '0')).join('').toUpperCase()
  return undefined
}

function textOf(node: JSONContent): string {
  if (node.type === 'text') return node.text || ''
  if (node.type === 'hardBreak') return '\n'
  return childrenOf(node).map(textOf).join(['doc', 'table', 'tableRowGroup', 'tableRow', 'bulletList', 'orderedList', 'listItem', 'recordInfoRow', 'recordBody', 'recordSignature', 'recordGroup'].includes(node.type || '') ? '\n' : '')
}

function isRecordTemplate(node: JSONContent): boolean {
  return recordNodeTypes.has(node.type || '') || attrsOf(node).role === 'recordTitle' || childrenOf(node).some(isRecordTemplate)
}

function recordOffsetWarning(node: JSONContent, context: Context) {
  const attrs = attrsOf(node)
  if (numberValue(attrs.offsetX) || numberValue(attrs.offsetY)) context.warnings.add('ตำแหน่งเลื่อนเฉพาะส่วนของแม่แบบไม่ถูกแปลงเป็นตำแหน่งลอยใน DOCX โดยยังคงเนื้อหาครบ; ใช้ PDF เมื่อต้องการคงตำแหน่งเดิม')
}

function contentWidth(context: Context): number {
  if (context.availableWidth) return context.availableWidth
  const settings = context.model.pageSettings
  return mmToTwips(pageDimensions(settings).width - settings.margins.left - settings.margins.right)
}

function recordSpacer(height = 0, borderTop = false): Paragraph {
  return new Paragraph({
    children: [], run: { size: 2, sizeComplexScript: 2 },
    spacing: { before: 0, after: 0, line: Math.max(1, pxToTwips(height)), lineRule: LineRuleType.EXACT },
    border: borderTop ? { top: { color: '000000', style: BorderStyle.SINGLE, size: 4, space: 0 } } : undefined,
    keepNext: true,
  })
}

function recordCellChildren(blocks: Block[]): Block[] {
  return blocks.length && blocks[blocks.length - 1] instanceof Paragraph ? blocks : [...blocks, recordSpacer()]
}

function tableRows(node: JSONContent): JSONContent[] {
  return childrenOf(node).flatMap(child => child.type === 'tableRowGroup' ? tableRows(child) : child.type === 'tableRow' ? [child] : [])
}

function headerCellContent(nodes: JSONContent[]): JSONContent[] {
  return nodes.map(node => node.type === 'text'
    ? { ...node, marks: node.marks?.some(mark => mark.type === 'bold') ? node.marks : [...node.marks || [], { type: 'bold' }] }
    : { ...node, content: headerCellContent(childrenOf(node)) })
}

function runOptions(node: JSONContent): IRunOptions {
  const options: IRunOptions = { text: node.text || '', language: { value: 'th-TH' } }
  const result = { ...options }
  for (const mark of node.marks || []) {
    const attrs = mark.attrs || {}
    if (mark.type === 'bold') { result.bold = true; result.boldComplexScript = true }
    if (mark.type === 'italic') { result.italics = true; result.italicsComplexScript = true }
    if (mark.type === 'underline') result.underline = { type: UnderlineType.SINGLE }
    if (mark.type === 'strike') result.strike = true
    if (mark.type === 'superscript') result.superScript = true
    if (mark.type === 'subscript') result.subScript = true
    if (mark.type === 'code') result.font = 'Courier New'
    if (mark.type === 'textStyle') {
      if (typeof attrs.fontFamily === 'string' && attrs.fontFamily) result.font = attrs.fontFamily.split(',')[0].trim().replace(/^["']|["']$/g, '')
      if (attrs.fontSize) {
        const size = parseFloat(String(attrs.fontSize)) * (String(attrs.fontSize).endsWith('px') ? 0.75 : 1)
        if (Number.isFinite(size) && size > 0) { result.size = Math.round(size * 2); result.sizeComplexScript = Math.round(size * 2) }
      }
      if (hexColor(attrs.color)) result.color = hexColor(attrs.color)
      if (hexColor(attrs.backgroundColor)) result.shading = { type: ShadingType.CLEAR, fill: hexColor(attrs.backgroundColor) }
    }
    if (mark.type === 'highlight') result.shading = { type: ShadingType.CLEAR, fill: hexColor(attrs.color) || 'FFFF00' }
  }
  return result
}

/** Asset URLs must stay on this app; never send the user's session to a remote host. */
export function resolveDocumentAssetUrl(source: string, baseUrl: string): string {
  const base = new URL(baseUrl)
  const url = new URL(source, base)
  if (!['http:', 'https:'].includes(url.protocol) || url.origin !== base.origin || url.username || url.password) {
    throw new Error('ส่งออกรูปได้เฉพาะไฟล์ภาพที่อัปโหลดในระบบนี้')
  }
  return url.href
}

async function webpToPng(blob: Blob): Promise<Uint8Array> {
  if (typeof document === 'undefined') throw new Error('ต้องเปิดในเบราว์เซอร์เพื่อแปลงภาพ WebP เป็น PNG')
  const bitmap = await createImageBitmap(blob)
  try {
    const canvas = document.createElement('canvas')
    canvas.width = bitmap.width
    canvas.height = bitmap.height
    const context = canvas.getContext('2d')
    if (!context) throw new Error('เบราว์เซอร์นี้แปลงภาพ WebP ไม่ได้')
    context.drawImage(bitmap, 0, 0)
    const png = await new Promise<Blob>((resolve, reject) => canvas.toBlob(value => value ? resolve(value) : reject(new Error('แปลงภาพ WebP ไม่สำเร็จ')), 'image/png'))
    return new Uint8Array(await png.arrayBuffer())
  } finally { bitmap.close() }
}

function imageDimensions(data: Uint8Array, type: 'png' | 'jpg'): { width?: number; height?: number } {
  const view = new DataView(data.buffer, data.byteOffset, data.byteLength)
  if (type === 'png' && data.length >= 24) return { width: view.getUint32(16), height: view.getUint32(20) }
  if (type === 'jpg') {
    let offset = 2
    while (offset + 4 < data.length) {
      if (data[offset] !== 0xff) break
      const marker = data[offset + 1]
      if (marker === 0xd9 || marker === 0xda) break
      if (marker === 0xd8 || marker === 0x01 || marker >= 0xd0 && marker <= 0xd7) { offset += 2; continue }
      const length = view.getUint16(offset + 2)
      if (length < 2 || offset + 2 + length > data.length) break
      if ([0xc0, 0xc1, 0xc2, 0xc3, 0xc5, 0xc6, 0xc7, 0xc9, 0xca, 0xcb, 0xcd, 0xce, 0xcf].includes(marker) && length >= 7) return { height: view.getUint16(offset + 5), width: view.getUint16(offset + 7) }
      offset += length + 2
    }
  }
  return {}
}

async function loadImage(source: string, context: Context): Promise<LoadedImage> {
  const base = context.options.baseUrl || (typeof window !== 'undefined' ? window.location.href : '')
  if (!base) throw new Error('ไม่พบ URL ของระบบสำหรับโหลดภาพ')
  const url = resolveDocumentAssetUrl(source, base)
  let pending = context.images.get(url)
  if (!pending) {
    pending = (async () => {
      const response = await (context.options.fetch || fetch)(url, { credentials: 'same-origin', redirect: 'error', signal: AbortSignal.timeout(15000) })
      if (!response.ok) throw new Error(`โหลดภาพสำหรับ DOCX ไม่สำเร็จ (${response.status})`)
      const declaredLength = Number(response.headers.get('content-length') || 0)
      if (declaredLength > 15 * 1024 * 1024) throw new Error('ไฟล์ภาพใหญ่เกิน 15 MB')
      const blob = await response.blob()
      if (blob.size > 15 * 1024 * 1024) throw new Error('ไฟล์ภาพใหญ่เกิน 15 MB')
      const data = new Uint8Array(await blob.arrayBuffer())
      const png = data[0] === 0x89 && data[1] === 0x50 && data[2] === 0x4e && data[3] === 0x47
      const jpg = data[0] === 0xff && data[1] === 0xd8 && data[2] === 0xff
      const webp = new TextDecoder().decode(data.slice(0, 4)) === 'RIFF' && new TextDecoder().decode(data.slice(8, 12)) === 'WEBP'
      if (png) return { data, type: 'png' as const, ...imageDimensions(data, 'png') }
      if (jpg) return { data, type: 'jpg' as const, ...imageDimensions(data, 'jpg') }
      if (webp) {
        const converted = await (context.options.convertWebp || webpToPng)(blob)
        return { data: converted, type: 'png' as const, ...imageDimensions(converted, 'png') }
      }
      throw new Error('ชนิดภาพไม่รองรับ DOCX (รองรับ JPG, PNG และ WebP)')
    })()
    context.images.set(url, pending)
  }
  return pending
}

async function imageRun(source: string, width: number, height: number, context: Context, properties: { rotation?: number; floating?: IFloating; alt?: string; cover?: boolean; outline?: IImageOptions['outline'] } = {}): Promise<ImageRun> {
  const loaded = await loadImage(source, context)
  const intrinsicWidth = loaded.width || context.model.assets.find(asset => asset.url === source)?.width || width
  const intrinsicHeight = loaded.height || context.model.assets.find(asset => asset.url === source)?.height || height
  let crop: ICropOptions | undefined
  if (properties.cover) {
    const sourceRatio = intrinsicWidth / intrinsicHeight
    const frameRatio = width / height
    crop = sourceRatio > frameRatio
      ? { left: (1 - frameRatio / sourceRatio) * 50, right: (1 - frameRatio / sourceRatio) * 50 }
      : { top: (1 - sourceRatio / frameRatio) * 50, bottom: (1 - sourceRatio / frameRatio) * 50 }
  }
  return new ImageRun({
    data: loaded.data, type: loaded.type,
    transformation: { width: Math.max(1, width), height: Math.max(1, height), rotation: properties.rotation || 0 },
    floating: properties.floating,
    outline: properties.outline, crop,
    altText: { name: properties.alt || 'ภาพประกอบ', description: properties.alt || 'ภาพประกอบเอกสาร', title: properties.alt || 'ภาพประกอบ' },
  })
}

async function inlineChildren(node: JSONContent, context: Context): Promise<ParagraphChild[]> {
  const output: ParagraphChild[] = []
  for (const child of childrenOf(node)) {
    if (child.type === 'text') {
      if (child.marks?.some(mark => mark.type === 'textStyle' && mark.attrs?.lineHeight)) context.warnings.add('ระยะบรรทัดเฉพาะบางคำอาจต่างใน DOCX เพราะ Word กำหนดระยะบรรทัดเป็นรายย่อหน้า')
      const run = new TextRun({ ...context.runDefaults, ...runOptions(child) })
      const link = child.marks?.find(mark => mark.type === 'link')?.attrs?.href
      if (typeof link === 'string' && /^(https?:\/\/|mailto:|tel:)/i.test(link)) output.push(new ExternalHyperlink({ link, children: [run] }))
      else { if (link) context.warnings.add('ลิงก์ที่มีรูปแบบ URL ไม่ปลอดภัยถูกส่งออกเป็นข้อความธรรมดา'); output.push(run) }
    } else if (child.type === 'hardBreak') output.push(new TextRun({ ...context.runDefaults, break: 1 }))
    else if (child.type === 'image') {
      const attrs = attrsOf(child)
      const source = String(attrs.src || context.model.assets.find(asset => asset.id === attrs.assetId)?.url || '')
      if (!source) throw new Error('ภาพในเอกสารไม่มีไฟล์ต้นฉบับ กรุณาอัปโหลดภาพอีกครั้ง')
      const loaded = await loadImage(source, context)
      const dimensions = pageDimensions(context.model.pageSettings)
      const maxWidth = (dimensions.width - context.model.pageSettings.margins.left - context.model.pageSettings.margins.right) * MM_TO_PX
      const width = Math.max(1, Math.min(maxWidth, numberValue(attrs.width, Math.min(loaded.width || 240, maxWidth))))
      const height = Math.max(1, numberValue(attrs.height, loaded.height && loaded.width ? width * loaded.height / loaded.width : 160))
      output.push(await imageRun(source, width, height, context, { alt: String(attrs.alt || attrs.title || 'ภาพประกอบ') }))
    } else if (child.type === 'pageBreak') output.push(new TextRun({ children: [new PageBreak()] }))
    else {
      context.warnings.add(`รูปแบบ ${child.type || 'ไม่รู้จัก'} ถูกส่งออกโดยคงข้อความภายในไว้`)
      output.push(...await inlineChildren(child, context))
    }
  }
  return output
}

function objectFloating(object: DocumentObject): IFloating | undefined {
  if (object.wrap === 'inline') return undefined
  return {
    horizontalPosition: { relative: HorizontalPositionRelativeFrom.PAGE, offset: mmToEmu(object.x) },
    verticalPosition: {
      relative: object.anchor.kind === 'paragraph' ? VerticalPositionRelativeFrom.PARAGRAPH : VerticalPositionRelativeFrom.PAGE,
      offset: mmToEmu(object.anchor.kind === 'paragraph' ? object.anchor.offsetY || 0 : object.y),
    },
    allowOverlap: true, lockAnchor: true, layoutInCell: false,
    zIndex: Math.max(0, object.zIndex),
    wrap: { type: object.wrap === 'top-bottom' ? TextWrappingType.TOP_AND_BOTTOM : TextWrappingType.SQUARE, side: TextWrappingSide.BOTH_SIDES },
    margins: { top: mmToEmu(1), right: mmToEmu(1), bottom: mmToEmu(1), left: mmToEmu(1) },
  }
}

async function objectRun(object: DocumentObject, context: Context, forceInline = false): Promise<ParagraphChild> {
  const floating = forceInline ? undefined : objectFloating(object)
  if (object.type === 'image') {
    const source = object.src || context.model.assets.find(asset => asset.id === object.assetId)?.url
    if (!source) throw new Error('รูปภาพไม่มีไฟล์ต้นฉบับ กรุณาอัปโหลดภาพอีกครั้งก่อนส่งออก')
    if (object.opacity !== 1) context.warnings.add('ความโปร่งใสของภาพไม่ถูกแปลงใน DOCX; ใช้ PDF เมื่อต้องการคงลักษณะภาพ')
    return imageRun(source, object.width * MM_TO_PX, object.height * MM_TO_PX, context, {
      floating, rotation: object.rotation, cover: true,
      outline: object.borderWidth && hexColor(object.border) ? { type: 'solidFill', solidFillType: 'rgb', value: hexColor(object.border)!, width: Math.round(object.borderWidth * 9525) } : { type: 'noFill' },
    })
  }
  let content: Paragraph[] | undefined
  if (object.type === 'textbox') {
    const textboxContext = { ...context, objectsByParagraph: new Map(), objectsByPage: new Map(), pagination: { ...context.pagination, pageHasAnchor: true } }
    const flattenTables = (nodes: JSONContent[]): JSONContent[] => nodes.flatMap<JSONContent>(node => {
      if (node.type === 'table') {
        context.warnings.add('ตารางในกล่องข้อความ DOCX ถูกแปลงเป็นข้อความ; ตารางในเนื้อหาเอกสารยังแก้ไขได้ตามปกติ')
        return tableRows(node).map(row => ({ type: 'paragraph', content: [{ type: 'text', text: childrenOf(row).map(textOf).join('\t') }] }))
      }
      if (node.type === 'recordInfoRow') {
        context.warnings.add('โครงแม่แบบภายในกล่องข้อความ DOCX ถูกแปลงเป็นย่อหน้า โดยคงข้อความทั้งหมดไว้')
        return [{ type: 'paragraph', content: [{ type: 'text', text: childrenOf(node).map(textOf).join('\t') }] }]
      }
      if (['recordBody', 'recordSignature', 'recordGroup'].includes(node.type || '')) {
        context.warnings.add('โครงแม่แบบภายในกล่องข้อความ DOCX ถูกแปลงเป็นย่อหน้า โดยคงข้อความทั้งหมดไว้')
        return flattenTables(childrenOf(node))
      }
      return [{ ...node, content: flattenTables(childrenOf(node)) }]
    })
    const blocks = await convertBlocks(flattenTables(childrenOf(object.content || { type: 'doc' })), textboxContext)
    content = blocks.filter((block): block is Paragraph => block instanceof Paragraph)
    if (!content.length) content = [new Paragraph('')]
  }
  const transparency = Math.round((1 - object.opacity) * 100)
  const padding = numberValue(object.padding, 0) * 72 / 25.4
  if (object.type === 'textbox' && object.opacity !== 1) context.warnings.add('DOCX คงความโปร่งใสพื้นหลังและเส้นกรอบของกล่องข้อความ แต่ความโปร่งใสตัวอักษรอาจต่างจากหน้าเว็บ')
  return new ShapeRun({
    type: object.type === 'line' ? 'line' : object.type === 'textbox' || object.shape === 'rect' ? 'rectangle' : object.shape === 'ellipse' ? 'ellipse' : 'triangle',
    transformation: { width: object.width * MM_TO_PX, height: object.type === 'line' ? 0 : object.height * MM_TO_PX, rotation: object.rotation },
    floating,
    fill: object.type === 'line' || !hexColor(object.fill) ? 'none' : { color: hexColor(object.fill)!, transparency },
    line: object.borderWidth === 0 || !hexColor(object.border) ? 'none' : { color: hexColor(object.border)!, width: numberValue(object.borderWidth, 1) * 0.75, transparency },
    children: content,
    textOptions: { verticalAlignment: 'top', margins: { top: padding, right: padding, bottom: padding, left: padding }, wrap: true },
    altText: { name: object.type === 'textbox' ? 'กล่องข้อความ' : 'รูปทรง', description: object.type === 'textbox' ? textOf(object.content || {}) : 'รูปทรงในเอกสาร', title: 'วัตถุเอกสาร' },
  })
}

async function paragraph(node: JSONContent, context: Context, list?: ListContext): Promise<Paragraph> {
  const attrs = attrsOf(node)
  recordOffsetWarning(node, context)
  const isTitle = attrs.role === 'recordTitle'
  const isSignaturePart = ['signatureName', 'signaturePosition', 'signatureLine'].includes(String(attrs.role))
  const defaults = context.paragraphDefaults || {}
  const runs = await inlineChildren(node, isTitle ? { ...context, runDefaults: { ...context.runDefaults, font: 'THSarabunNew', size: 40, sizeComplexScript: 40, bold: true, boldComplexScript: true } } : context)
  const attached = [...context.objectsByParagraph.get(String(attrs.id || '')) || []]
  if (!context.pagination.pageHasAnchor) {
    attached.push(...context.objectsByPage.get(context.pagination.explicitPage) || [])
    context.pagination.pageHasAnchor = true
  }
  for (const object of attached) {
    if (context.placedObjects.has(object.id)) continue
    runs.push(await objectRun(object, context))
    context.placedObjects.add(object.id)
  }
  const heading = node.type === 'heading' ? [HeadingLevel.HEADING_1, HeadingLevel.HEADING_2, HeadingLevel.HEADING_3, HeadingLevel.HEADING_4, HeadingLevel.HEADING_5, HeadingLevel.HEADING_6][Math.max(0, Math.min(5, numberValue(attrs.level, 1) - 1))] : undefined
  const alignment = paragraphAlignments[String(attrs.textAlign)] || (isTitle || isSignaturePart ? AlignmentType.CENTER : defaults.alignment)
  const defaultLine = isTitle ? 264 : defaults.spacing?.line ?? Math.round((heading ? [1.3, 1.35, 1.4][Math.max(0, Math.min(2, numberValue(attrs.level, 1) - 1))] : 1.5) * 240)
  const indent = {
    ...defaults.indent,
    ...(list && !list.first ? { left: 360 * (list.level + 1) } : {}),
    ...(attrs.indent ? { left: mmToTwips(numberValue(attrs.indent)) } : {}),
    ...(attrs.firstIndent !== null && attrs.firstIndent !== undefined ? { firstLine: pxToTwips(numberValue(attrs.firstIndent)) } : {}),
  }
  const options: IParagraphOptions = {
    ...defaults,
    children: runs, heading, alignment,
    spacing: {
      before: Math.max(0, numberValue(attrs.spaceBefore, numberValue(defaults.spacing?.before) / 20)) * 20,
      after: Math.max(0, numberValue(attrs.spaceAfter, isTitle ? 6 : isSignaturePart ? 0 : numberValue(defaults.spacing?.after, 160) / 20)) * 20,
      line: attrs.lineHeight ? Math.round(Math.max(0.5, numberValue(attrs.lineHeight, 1.5)) * 240) : defaultLine,
      lineRule: attrs.lineHeight || isTitle ? LineRuleType.AUTO : defaults.spacing?.lineRule || LineRuleType.AUTO,
    },
    indent: Object.keys(indent).length ? indent : undefined,
    numbering: list?.first ? { reference: list.reference, level: Math.min(8, list.level) } : undefined,
    border: attrs.recordLine ? { ...defaults.border, bottom: dottedBorder } : defaults.border,
    widowControl: true,
  }
  if (list) list.first = false
  return new Paragraph(options)
}

async function recordInfoRow(node: JSONContent, context: Context): Promise<Table | null> {
  const attrs = attrsOf(node)
  const sourceCells = childrenOf(node)
  if (!sourceCells.length) return null
  const compact = !!attrs.compact
  const width = contentWidth(context)
  const requested = sourceCells.map(cell => {
    const attrs = attrsOf(cell)
    return Math.max(0, attrs.unit === 'px' ? pxToTwips(numberValue(attrs.width)) : width * numberValue(attrs.width) / 100)
  })
  const total = requested.reduce((sum, value) => sum + value, 0)
  const autoCount = requested.filter(value => !value).length
  const columnWidths = requested.map(value => Math.max(1, Math.round(value ? value * (total > width ? width / total : 1) : Math.max(0, width - total) / Math.max(1, autoCount))))
  const cells: TableCell[] = []
  for (const [index, cell] of sourceCells.entries()) {
    const cellAttrs = attrsOf(cell)
    recordOffsetWarning(cell, context)
    const childContext: Context = {
      ...context,
      paragraphDefaults: {
        alignment: AlignmentType.LEFT, keepLines: true, wordWrap: cellAttrs.nowrap ? false : undefined,
        spacing: { before: 0, after: 0, line: pxToTwips(26), lineRule: LineRuleType.AT_LEAST },
      },
      runDefaults: { font: 'THSarabunNew', size: 32, sizeComplexScript: 32, color: '000000' },
    }
    const content = await paragraph({ ...cell, type: 'paragraph', attrs: { ...cellAttrs, spaceBefore: 0, spaceAfter: 0 } }, childContext)
    cells.push(new TableCell({
      children: [content],
      width: compact
        ? requested[index] ? { type: WidthType.DXA, size: Math.round(requested[index]) } : { type: WidthType.AUTO, size: 0 }
        : { type: WidthType.DXA, size: columnWidths[index] },
      borders: { ...noCellBorders, bottom: cellAttrs.dotted && !cellAttrs.label ? dottedBorder : noBorder },
      margins: { top: 0, right: 0, bottom: 0, left: 0 },
    }))
  }
  return new Table({
    rows: [new TableRow({ children: cells, cantSplit: true, height: { value: pxToTwips(26), rule: HeightRule.ATLEAST } })],
    width: { type: compact ? WidthType.AUTO : WidthType.DXA, size: compact ? 0 : width },
    alignment: compact ? AlignmentType.CENTER : AlignmentType.LEFT,
    layout: compact ? TableLayoutType.AUTOFIT : TableLayoutType.FIXED,
    columnWidths: compact ? undefined : columnWidths,
    borders: noTableBorders, margins: { top: 0, right: 0, bottom: 0, left: 0 },
  })
}

async function recordBlocks(node: JSONContent, context: Context): Promise<Block[]> {
  const attrs = attrsOf(node)
  recordOffsetWarning(node, context)
  if (node.type === 'recordInfoRow') {
    const row = await recordInfoRow(node, context)
    return row ? [row] : []
  }
  if (node.type === 'recordSectionTitle') {
    return [await paragraph({ ...node, type: 'paragraph', attrs: { ...attrs, spaceBefore: undefined } }, {
      ...context,
      runDefaults: { font: 'THSarabunNew', size: 34, sizeComplexScript: 34, bold: true, boldComplexScript: true, color: '000000' },
      paragraphDefaults: { keepNext: true, keepLines: true, spacing: { before: pxToTwips(Math.max(0, numberValue(attrs.spaceBefore))), after: 0, line: pxToTwips(26), lineRule: LineRuleType.AT_LEAST } },
    })]
  }
  if (node.type === 'recordBody') {
    const line = pxToTwips(Math.max(1, numberValue(attrs.lineSpacing, 26)))
    const alignment = paragraphAlignments[String(attrs.align)] || AlignmentType.JUSTIFIED
    const bodyContext: Context = {
      ...context,
      runDefaults: { font: 'THSarabunNew', size: 32, sizeComplexScript: 32, color: '000000' },
      paragraphDefaults: {
        alignment, keepNext: false, keepLines: false,
        spacing: { before: 0, after: pxToTwips(Math.max(0, numberValue(attrs.spaceAfter))), line, lineRule: LineRuleType.AT_LEAST },
        indent: { firstLine: pxToTwips(Math.max(0, numberValue(attrs.firstIndent, 42))) },
        border: { bottom: dottedBorder },
      },
    }
    context.warnings.add('DOCX ใช้เส้นจุดใต้ย่อหน้าและขอบพื้นที่เนื้อหาแทนเส้นบรรทัดซ้ำของแม่แบบ; ใช้ PDF เมื่อต้องการคงเส้นบรรทัดเดิม')
    const output: Block[] = []
    let pending: JSONContent[] = []
    let first = true
    const flush = async () => {
      if (!pending.length) return
      const blocks = await convertBlocks(pending, bodyContext)
      output.push(new Table({
        rows: [new TableRow({
          children: [new TableCell({ children: recordCellChildren(blocks), borders: { ...noCellBorders, bottom: dottedBorder }, margins: { top: 0, right: 0, bottom: 0, left: 0 } })],
          height: { value: (first ? Math.max(1, Math.ceil(numberValue(attrs.minLines, 1))) : 1) * line, rule: HeightRule.ATLEAST },
        })],
        width: { type: WidthType.DXA, size: contentWidth(context) },
        columnWidths: [contentWidth(context)], borders: noTableBorders,
        margins: { top: 0, right: 0, bottom: 0, left: 0 }, layout: TableLayoutType.FIXED,
      }))
      pending = []
      first = false
    }
    for (const child of childrenOf(node)) {
      if (child.type === 'pageBreak') {
        await flush()
        output.push(...await convertBlocks([child], context))
      } else pending.push(child)
    }
    await flush()
    return output
  }
  if (node.type === 'recordSignature') {
    const width = Math.max(1, Math.min(contentWidth(context), pxToTwips(numberValue(attrs.width, 430))))
    const blocks = await convertBlocks(childrenOf(node), {
      ...context, availableWidth: width,
      runDefaults: { font: 'THSarabunNew', size: 32, sizeComplexScript: 32, color: '000000' },
      paragraphDefaults: { alignment: AlignmentType.CENTER, keepLines: true, spacing: { before: 0, after: 0, line: pxToTwips(26), lineRule: LineRuleType.AT_LEAST } },
    })
    const alignment = ({ left: AlignmentType.LEFT, center: AlignmentType.CENTER, right: AlignmentType.RIGHT } as Record<string, IParagraphOptions['alignment']>)[String(attrs.align)] || AlignmentType.RIGHT
    const top = Math.max(0, numberValue(attrs.marginTop, 16))
    return [...top ? [recordSpacer(top)] : [], new Table({
      rows: [new TableRow({ children: [new TableCell({ children: recordCellChildren(blocks), width: { type: WidthType.DXA, size: width }, borders: noCellBorders, margins: { top: 0, right: 0, bottom: 0, left: 0 } })], cantSplit: true })],
      width: { type: WidthType.DXA, size: width }, columnWidths: [width],
      alignment, borders: noTableBorders, layout: TableLayoutType.FIXED,
      margins: { top: 0, right: 0, bottom: 0, left: 0 },
    })]
  }
  if (node.type === 'recordGroup') {
    const top = Math.max(0, numberValue(attrs.spaceBefore))
    const borderTop = !!attrs.borderTop && attrs.borderTop !== 'none'
    return [...top || borderTop ? [recordSpacer(top, borderTop)] : [], ...await convertBlocks(childrenOf(node), context)]
  }
  return [await paragraph({ ...node, type: 'paragraph' }, context)]
}

async function convertBlocks(nodes: JSONContent[], context: Context, list?: ListContext): Promise<Block[]> {
  const output: Block[] = []
  for (const node of nodes) {
    if (recordNodeTypes.has(node.type || '')) output.push(...await recordBlocks(node, context))
    else if (['paragraph', 'heading'].includes(node.type || '')) output.push(await paragraph(node, context, list))
    else if (node.type === 'pageBreak') {
      output.push(new Paragraph({ children: [new TextRun({ children: [new PageBreak()] })], spacing: { before: 0, after: 0 } }))
      context.pagination.explicitPage++
      context.pagination.pageHasAnchor = false
    } else if (node.type === 'bulletList' || node.type === 'orderedList') {
      const level = list ? Math.min(8, list.level + 1) : 0
      const reference = `editor-list-${context.numbering.length + 1}`
      const start = Math.max(1, numberValue(attrsOf(node).start, 1))
      context.numbering.push({ reference, levels: Array.from({ length: 9 }, (_, index) => ({
        level: index, format: node.type === 'bulletList' ? LevelFormat.BULLET : LevelFormat.DECIMAL,
        text: node.type === 'bulletList' ? ['•', '◦', '▪'][index % 3] : `%${index + 1}.`, start,
        alignment: AlignmentType.LEFT,
        style: { run: { font: context.recordTemplate ? 'THSarabunNew' : 'Sarabun' }, paragraph: { indent: { left: 360 * (index + 1), hanging: 240 } } },
      })) })
      for (const item of childrenOf(node)) output.push(...await convertBlocks(childrenOf(item), context, { reference, level, first: true }))
    } else if (node.type === 'table') {
      const rows: TableRow[] = []
      for (const row of tableRows(node)) {
        const cells: TableCell[] = []
        for (const cell of childrenOf(row)) {
          const attrs = attrsOf(cell)
          const alignment = paragraphAlignments[String(attrs.textAlign ?? attrs.align)]
          const cellContext = alignment ? { ...context, paragraphDefaults: { ...context.paragraphDefaults, alignment } } : context
          const content = await convertBlocks(cell.type === 'tableHeader' ? headerCellContent(childrenOf(cell)) : childrenOf(cell), cellContext)
          if (!content.length || content[content.length - 1] instanceof Table) content.push(new Paragraph(''))
          cells.push(new TableCell({
            children: content,
            columnSpan: Math.max(1, numberValue(attrs.colspan, 1)), rowSpan: Math.max(1, numberValue(attrs.rowspan, 1)),
            shading: cell.type === 'tableHeader' || attrs.backgroundColor ? { type: ShadingType.CLEAR, fill: hexColor(attrs.backgroundColor) || 'EFF6FF' } : undefined,
            margins: { top: 80, right: 100, bottom: 80, left: 100 },
          }))
        }
        if (cells.length) rows.push(new TableRow({ children: cells, tableHeader: childrenOf(row).every(cell => cell.type === 'tableHeader') }))
      }
      const dimensions = pageDimensions(context.model.pageSettings)
      const contentWidth = mmToTwips(dimensions.width - context.model.pageSettings.margins.left - context.model.pageSettings.margins.right)
      const percentages = String(attrsOf(node).columnSize || '').split(',').map(Number).filter(value => Number.isFinite(value) && value > 0)
      const total = percentages.reduce((sum, value) => sum + value, 0)
      const columnWidths = total > 0 ? percentages.map(value => Math.round(contentWidth * value / total)) : undefined
      if (rows.length) output.push(new Table({ rows, width: { size: 100, type: WidthType.PERCENTAGE }, columnWidths, layout: columnWidths ? TableLayoutType.FIXED : undefined }))
    } else if (node.type === 'image') output.push(new Paragraph({ children: await inlineChildren({ type: 'paragraph', content: [node] }, context) }))
    else if (node.type === 'horizontalRule') output.push(new Paragraph({ border: { bottom: { color: '94A3B8', style: BorderStyle.SINGLE, size: 6 } } }))
    else if (node.type === 'blockquote') {
      const quoted = await convertBlocks(childrenOf(node), context)
      output.push(...quoted)
      context.warnings.add('รูปแบบกรอบย่อหน้าอ้างอิงใน DOCX อาจต่างจากหน้าเว็บ โดยคงข้อความและรูปแบบตัวอักษรไว้')
    } else if (node.type === 'codeBlock') output.push(new Paragraph({ children: [new TextRun({ text: textOf(node), font: 'Courier New' })] }))
    else {
      context.warnings.add(`รูปแบบ ${node.type || 'ไม่รู้จัก'} ถูกส่งออกโดยคงข้อความภายในไว้`)
      if (node.type === 'text') output.push(new Paragraph({ children: [new TextRun(runOptions(node))] }))
      else output.push(...await convertBlocks(childrenOf(node), context, list))
    }
  }
  return output
}

/** Convert the canonical JSON to editable Word paragraphs/tables/drawings, without HTML or screenshots. */
export async function buildDocumentDocx(model: WebDocument, options: DocxExportOptions = {}): Promise<{ document: DocxDocument; warnings: string[] }> {
  const context: Context = { model, options, warnings: new Set(), numbering: [], images: new Map(), objectsByParagraph: new Map(), objectsByPage: new Map(), placedObjects: new Set(), pagination: { explicitPage: 1, pageHasAnchor: false }, recordTemplate: isRecordTemplate(model.content) }
  for (const object of [...model.objects].sort((left, right) => left.zIndex - right.zIndex)) {
    if (object.anchor.kind === 'paragraph') {
      const objects = context.objectsByParagraph.get(object.anchor.id) || []
      objects.push(object)
      context.objectsByParagraph.set(object.anchor.id, objects)
    } else {
      const objects = context.objectsByPage.get(object.anchor.page) || []
      objects.push(object)
      context.objectsByPage.set(object.anchor.page, objects)
    }
  }
  const blocks = await convertBlocks(childrenOf(model.content), context)
  if (!blocks.length) blocks.push(await paragraph({ type: 'paragraph' }, context))
  for (const object of model.objects) {
    if (context.placedObjects.has(object.id)) continue
    blocks.push(new Paragraph({ children: [await objectRun(object, context, true)] }))
    context.warnings.add(object.anchor.kind === 'paragraph'
      ? 'วัตถุที่ไม่พบย่อหน้าอ้างอิงถูกวางท้ายเอกสารแบบในบรรทัดเพื่อรักษาเนื้อหาทั้งหมด'
      : `วัตถุหน้า ${object.anchor.page} ถูกวางท้ายเอกสารแบบในบรรทัด เพราะ DOCX ไม่ทราบจุดแบ่งหน้าอัตโนมัติของเว็บ`)
  }
  if (model.objects.some(object => object.wrap !== 'inline')) context.warnings.add('DOCX ใช้วัตถุที่แก้ไขได้และการล้อมข้อความของ Word; การแบ่งหน้าและตำแหน่งวัตถุอาจต่างจากหน้าเว็บ ควรใช้ PDF เมื่อต้องการคงหน้าพิมพ์')
  const settings = model.pageSettings
  const dimensions = pageDimensions(settings)
  const borderColor = hexColor(settings.border)
  const border = borderColor ? { color: borderColor, size: 8, style: BorderStyle.SINGLE, space: 12 } : undefined
  const footerRuns: TextRun[] = []
  if (settings.footer) footerRuns.push(new TextRun(settings.footer))
  if (settings.pageNumbers) {
    if (footerRuns.length) footerRuns.push(new TextRun('  |  '))
    footerRuns.push(new TextRun({ children: ['หน้า ', PageNumber.CURRENT, ' / ', PageNumber.TOTAL_PAGES] }))
  }
  const document = new DocxDocument({
    title: model.title, creator: 'Sena Reflection', revision: model.revision,
    background: { color: hexColor(settings.background) || 'FFFFFF' },
    styles: { default: {
      document: { run: { font: context.recordTemplate ? 'THSarabunNew' : 'Sarabun', size: 32, sizeComplexScript: 32, color: context.recordTemplate ? '000000' : '172033', language: { value: 'th-TH' } }, paragraph: { spacing: { after: 160, line: 360, lineRule: LineRuleType.AUTO } } },
      heading1: { run: { font: context.recordTemplate ? 'THSarabunNew' : 'Sarabun', size: 60, sizeComplexScript: 60, bold: true, boldComplexScript: true, color: '172033' }, paragraph: { spacing: { before: 280, after: 160 } } },
      heading2: { run: { font: context.recordTemplate ? 'THSarabunNew' : 'Sarabun', size: 46, sizeComplexScript: 46, bold: true, boldComplexScript: true, color: '172033' }, paragraph: { spacing: { before: 240, after: 160 } } },
      heading3: { run: { font: context.recordTemplate ? 'THSarabunNew' : 'Sarabun', size: 38, sizeComplexScript: 38, bold: true, boldComplexScript: true, color: '172033' }, paragraph: { spacing: { before: 200, after: 160 } } },
    } },
    numbering: { config: context.numbering },
    sections: [{
      properties: { page: {
        // docx applies landscape itself; provide the physical portrait pair to avoid swapping twice.
        size: { width: mmToTwips(Math.min(dimensions.width, dimensions.height)), height: mmToTwips(Math.max(dimensions.width, dimensions.height)), orientation: settings.orientation },
        margin: { top: mmToTwips(settings.margins.top), right: mmToTwips(settings.margins.right), bottom: mmToTwips(settings.margins.bottom), left: mmToTwips(settings.margins.left), header: mmToTwips(Math.min(8, settings.margins.top / 2)), footer: mmToTwips(Math.min(8, settings.margins.bottom / 2)) },
        borders: border ? { pageBorderTop: border, pageBorderRight: border, pageBorderBottom: border, pageBorderLeft: border } : undefined,
      } },
      headers: settings.header ? { default: new Header({ children: [new Paragraph({ children: [new TextRun(settings.header)], alignment: AlignmentType.LEFT })] }) } : undefined,
      footers: footerRuns.length ? { default: new Footer({ children: [new Paragraph({ children: footerRuns, alignment: AlignmentType.RIGHT })] }) } : undefined,
      children: blocks,
    }],
  })
  return { document, warnings: [...context.warnings] }
}

export async function exportDocx(model: WebDocument): Promise<{ blob: Blob; warnings: string[] }> {
  const result = await buildDocumentDocx(model)
  return { blob: await Packer.toBlob(result.document), warnings: result.warnings }
}

export { documentPrintPageGeometry, openDocumentPrint } from './document-print'
