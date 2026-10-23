import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'
import test from 'node:test'

const hook = registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier === './document-model' && context.parentURL?.endsWith('/document-layout.ts')) return nextResolve('./document-model.ts', context)
    return nextResolve(specifier, context)
  },
})
const { canvasPageDimensions, canvasPages, canvasPositionToPage, constrainObject, escapeHeaderText, objectCanvasTop, snapMillimeters } = await import('../../resources/js/lib/document-layout.ts')
const { defaultPageSettings, documentToScreen, MM_TO_PX, pageDimensions, screenToDocument } = await import('../../resources/js/lib/document-model.ts')
hook.deregister()

test('A4 dimensions are physical millimeters, independent of the editor zoom', () => {
  const pages = canvasPages(defaultPageSettings, 3)
  assert.equal(pages.length, 3)
  assert.equal(pages[0].width, 210 * MM_TO_PX)
  assert.equal(pages[0].height, 297 * MM_TO_PX)
  assert.equal(pages[1].top, pages[0].height + 28)
  for (const zoom of [0.5, 0.75, 1, 1.5, 2]) {
    assert.ok(Math.abs(screenToDocument(documentToScreen(42.75, zoom), zoom) - 42.75) < 0.00001)
  }
})

test('weekly record template retains the legacy whole-pixel page geometry without changing A4 millimeters', () => {
  const settings = { ...defaultPageSettings, template: 'weekly-record' }
  assert.deepEqual(pageDimensions(settings), { width: 210, height: 297 })
  assert.deepEqual(canvasPageDimensions(settings), { width: 794, height: 1123 })
  assert.equal(canvasPages(settings, 3)[2].top, (1123 + 28) * 2)
  const position = { page: 3, y: 50 }
  const restored = canvasPositionToPage(objectCanvasTop(position, settings), settings)
  assert.equal(restored.page, position.page)
  assert.ok(Math.abs(restored.y - position.y) < 0.00001)
})

test('objects round trip from page coordinates to the continuous canvas without changing their position', () => {
  for (const orientation of ['portrait', 'landscape']) {
    const settings = { ...defaultPageSettings, orientation }
    for (const page of [1, 2, 5]) {
      const object = { page, y: 41.5 }
      const restored = canvasPositionToPage(objectCanvasTop(object, settings), settings)
      assert.equal(restored.page, page)
      assert.ok(Math.abs(restored.y - object.y) < 0.00001)
    }
  }
})

test('drag/resize constraints keep objects on the selected physical page, including landscape', () => {
  for (const orientation of ['portrait', 'landscape']) {
    const settings = { ...defaultPageSettings, orientation }
    const dimensions = pageDimensions(settings)
    const moved = constrainObject({ page: -2, x: 1000, y: -3, width: 85, height: 50 }, settings)
    assert.equal(moved.page, 1)
    assert.equal(moved.x + moved.width, dimensions.width)
    assert.equal(moved.y, 0)
    const oversized = constrainObject({ page: 2, x: 3, y: 4, width: 10000, height: 10000 }, settings)
    assert.deepEqual(oversized, { page: 2, x: 0, y: 0, width: dimensions.width, height: dimensions.height })
  }
})

test('5 mm snap and header/footer text escaping preserve Thai and prevent HTML injection', () => {
  assert.equal(snapMillimeters(13.1, true), 15)
  assert.equal(snapMillimeters(13.1, false), 13.1)
  assert.equal(escapeHeaderText('ภาษาไทย\n<img src=x onerror="alert(1)">'), 'ภาษาไทย<br>&lt;img src=x onerror=&quot;alert(1)&quot;&gt;')
})
