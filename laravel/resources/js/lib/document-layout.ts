import type { DocumentObject, PageSettings } from './document-model'
import { MM_TO_PX, PAGE_GAP, pageDimensions } from './document-model'

export type CanvasPage = { index: number; top: number; height: number; width: number; thumbnail?: string }
export type ObjectGeometry = { x: number; y: number; width: number; height: number; page: number }

export function canvasPages(settings: PageSettings, count: number): CanvasPage[] {
  const size = pageDimensions(settings)
  const height = size.height * MM_TO_PX
  return Array.from({ length: Math.max(1, count) }, (_, index) => ({ index, top: index * (height + PAGE_GAP), height, width: size.width * MM_TO_PX }))
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
  return (Math.max(1, object.page) - 1) * (pageDimensions(settings).height * MM_TO_PX + PAGE_GAP) + object.y * MM_TO_PX
}

export function canvasPositionToPage(top: number, settings: PageSettings): { page: number; y: number } {
  const height = pageDimensions(settings).height * MM_TO_PX
  const page = Math.max(1, Math.floor(Math.max(0, top) / (height + PAGE_GAP)) + 1)
  return { page, y: Math.max(0, top - (page - 1) * (height + PAGE_GAP)) / MM_TO_PX }
}

export function escapeHeaderText(value: string): string {
  return value.replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character] || character).replace(/\n/g, '<br>')
}
