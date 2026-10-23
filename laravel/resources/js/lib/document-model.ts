import type { JSONContent } from '@tiptap/core'

export type PageSettings = {
  size: 'A4' | 'A3' | 'Letter'
  orientation: 'portrait' | 'landscape'
  margins: { top: number; right: number; bottom: number; left: number }
  header: string
  footer: string
  pageNumbers: boolean
  background: string
  border: string
  theme: string
  fontFamily?: string
  fontSize?: number
  lineHeight?: number
  template?: 'weekly-record' | null
}

export type DocumentObject = {
  id: string
  type: 'image' | 'textbox' | 'shape' | 'line'
  page: number
  x: number
  y: number
  width: number
  height: number
  rotation: number
  zIndex: number
  opacity: number
  wrap: 'inline' | 'square' | 'top-bottom'
  anchor: { kind: 'page'; page: number } | { kind: 'paragraph'; id: string; offsetY?: number }
  assetId?: string
  src?: string
  content?: JSONContent
  fill?: string
  border?: string
  borderWidth?: number
  padding?: number
  shape?: 'rect' | 'ellipse' | 'triangle'
  lockAspectRatio?: boolean
}

export type DocumentAsset = { id: string; url: string; mime: string; name: string; width: number; height: number }
export type DocumentEditorContext = { kind: 'record' | 'draft'; recordId?: string; draftId?: string; levelCode?: string; lockVersion?: number; returnUrl: string; title: string }
export type WebDocument = { id: string; title: string; content: JSONContent; pageSettings: PageSettings; objects: DocumentObject[]; revision: number; schemaVersion: number; updatedAt: string; assets: DocumentAsset[]; sourceRecordId?: string | null; editorContext?: DocumentEditorContext | null }
export type DocumentVersion = { id: string; revision: number; title: string; createdAt: string }
export const MM_TO_PX = 96 / 25.4
export const PAGE_GAP = 28
export const defaultPageSettings: PageSettings = { size: 'A4', orientation: 'portrait', margins: { top: 15, right: 20, bottom: 15, left: 20 }, header: '', footer: '', pageNumbers: true, background: '#ffffff', border: 'none', theme: 'blue' }
export function pageDimensions(settings: PageSettings) {
  const size = { A4: [210, 297], A3: [297, 420], Letter: [215.9, 279.4] }[settings.size] || [210, 297]
  return settings.orientation === 'landscape' ? { width: size[1], height: size[0] } : { width: size[0], height: size[1] }
}
export function screenToDocument(value: number, zoom = 1) { return value / zoom / MM_TO_PX }
export function documentToScreen(value: number, zoom = 1) { return value * MM_TO_PX * zoom }
export function createDocumentObject(type: DocumentObject['type'], page = 1): DocumentObject {
  return { id: crypto.randomUUID(), type, page, x: 25, y: 35, width: type === 'line' ? 75 : 60, height: type === 'line' ? 1 : 35, rotation: 0, zIndex: 1, opacity: 1, wrap: 'square', anchor: { kind: 'page', page }, fill: type === 'textbox' ? '#ffffff' : '#dbeafe', border: '#2563eb', borderWidth: 1, padding: 3, lockAspectRatio: type === 'image', shape: 'rect', content: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'กล่องข้อความ' }] }] } }
}
