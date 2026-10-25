import type { DocumentObject, PageSettings } from './document-model'
import { MM_TO_PX, PAGE_GAP, pageDimensions } from './document-model'

export type CanvasPage = { index: number; top: number; height: number; width: number; thumbnail?: string }
export type ObjectGeometry = { x: number; y: number; width: number; height: number; page: number }

export function canvasPageDimensions(settings: PageSettings): { width: number; height: number } {
  const size = pageDimensions(settings)
  const width = size.width * MM_TO_PX, height = size.height * MM_TO_PX
  return settings.template === 'weekly-record' ? { width: Math.round(width), height: Math.round(height) } : { width, height }
}

export function canvasPages(settings: PageSettings, count: number): CanvasPage[] {
  const { width, height } = canvasPageDimensions(settings)
  return Array.from({ length: Math.max(1, count) }, (_, index) => ({ index, top: index * (height + PAGE_GAP), height, width }))
}

export function snapMillimeters(value: number, enabled: boolean, step = 5): number {
  return enabled ? Math.round(value / step) * step : value
}

export function constrainObject(geometry: ObjectGeometry, settings: PageSettings): ObjectGeometry {
  const size = pageDimensions(settings)
  const width = Math.min(size.width, Math.max(1, geometry.width))
  const height = Math.min(size.height, Math.max(0.5, geometry.height))
  return {
    page: Math.max(1, Math.round(geometry.page)),
    x: Math.min(Math.max(0, geometry.x), size.width - width),
    y: Math.min(Math.max(0, geometry.y), size.height - height),
    width,
    height,
  }
}

export function objectCanvasTop(object: Pick<DocumentObject, 'page' | 'y'>, settings: PageSettings): number {
  return (Math.max(1, object.page) - 1) * (canvasPageDimensions(settings).height + PAGE_GAP) + object.y * MM_TO_PX
}

export function objectAnchorAfterMove(object: Pick<DocumentObject, 'anchor'>, previousTop: number, geometry: ObjectGeometry, settings: PageSettings): DocumentObject['anchor'] {
  return object.anchor.kind === 'paragraph'
    ? { ...object.anchor, offsetY: (object.anchor.offsetY || 0) + (objectCanvasTop(geometry, settings) - previousTop) / MM_TO_PX }
    : { kind: 'page', page: geometry.page }
}

export function objectFlowOffset(object: Pick<DocumentObject, 'anchor'>, canvasTop: number, paragraphTop: number, afterText = false): number {
  return Math.max(0, afterText && object.anchor.kind === 'paragraph' ? (object.anchor.offsetY || 0) * MM_TO_PX : canvasTop - paragraphTop)
}

export function canvasPositionToPage(top: number, settings: PageSettings): { page: number; y: number } {
  const { height } = canvasPageDimensions(settings)
  const page = Math.max(1, Math.floor(Math.max(0, top) / (height + PAGE_GAP)) + 1)
  return { page, y: Math.max(0, top - (page - 1) * (height + PAGE_GAP)) / MM_TO_PX }
}

export function escapeHeaderText(value: string): string {
  return value.replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character] || character).replace(/\n/g, '<br>')
}
