import assert from 'node:assert/strict'
import test from 'node:test'
import { arrangeImageLayouts, synchronizeImageLayouts } from '../../resources/js/lib/document-image-layout.ts'

const images = [{ id: 11 }, { id: 22 }, { id: 'draft-33' }, { id: 'draft-44' }]
const positions = [[12, 18], [325, 30], [22, 301], [310, 340]]
const settings = Object.fromEntries(positions.flatMap(([x, y], index) => [
  [`image_${index + 1}_x_px`, String(x)], [`image_${index + 1}_y_px`, String(y)],
  [`image_${index + 1}_width_px`, '280'], [`image_${index + 1}_height_px`, '200'],
  [`image_${index + 1}_object_fit`, 'fill'], [`image_${index + 1}_lock_aspect`, '0'],
  [`image_${index + 1}_rotation_deg`, '0'], [`image_${index + 1}_crop_x`, '25'], [`image_${index + 1}_crop_y`, '70'],
]))

test('four independent image slots replace a stale saved map without mutating the previous undo state', () => {
  const previous = { ...settings, image_layout_by_id: JSON.stringify({ 11: { x_px: '0', y_px: '0' } }) }
  const next = synchronizeImageLayouts(previous, images)
  const map = JSON.parse(next.image_layout_by_id)
  for (const [index, image] of images.entries()) {
    assert.equal(map[image.id].x_px, String(positions[index][0]))
    assert.equal(map[image.id].y_px, String(positions[index][1]))
    assert.equal(map[image.id].width_px, '280')
    assert.equal(map[image.id].crop_y, '70')
  }
  assert.equal(JSON.parse(previous.image_layout_by_id)[11].x_px, '0')
})

test('sequential moves and resizes preserve the other three images through save serialization', () => {
  const first = synchronizeImageLayouts(settings, images)
  const moved = synchronizeImageLayouts({ ...first, image_1_x_px: '85', image_1_y_px: '95' }, images)
  const resized = synchronizeImageLayouts({ ...moved, image_4_width_px: '240', image_4_height_px: '160' }, images)
  const saved = JSON.parse(JSON.stringify(resized))
  const map = JSON.parse(saved.image_layout_by_id)
  assert.deepEqual([map[11].x_px, map[11].y_px], ['85', '95'])
  assert.deepEqual([map[22].x_px, map[22].y_px], ['325', '30'])
  assert.deepEqual([map['draft-33'].x_px, map['draft-33'].y_px], ['22', '301'])
  assert.deepEqual([map['draft-44'].width_px, map['draft-44'].height_px], ['240', '160'])
  assert.deepEqual(synchronizeImageLayouts(saved, images), saved)
})

test('deleted images do not leave stale identities in the next saved map', () => {
  const saved = synchronizeImageLayouts(settings, images)
  const next = synchronizeImageLayouts(saved, images.slice(1))
  assert.deepEqual(Object.keys(JSON.parse(next.image_layout_by_id)), ['22', 'draft-33', 'draft-44'])
  assert.deepEqual(JSON.parse(synchronizeImageLayouts(saved, []).image_layout_by_id), {})
})

for (const orientation of ['portrait', 'landscape']) {
  test(`repairing four piled images fits the ${orientation} canvas without overlap and updates their stable ids`, () => {
    const arranged = arrangeImageLayouts({ ...settings, paper_orientation: orientation, pdf_margin_left_px: '160', pdf_margin_right_px: '160', pdf_margin_top_px: '140', pdf_margin_bottom_px: '140' }, images)
    const map = Object.values(JSON.parse(arranged.image_layout_by_id))
    const canvasWidth = (orientation === 'portrait' ? 794 : 1123) - 320
    const canvasHeight = (orientation === 'portrait' ? 1123 : 794) - 280 - 54
    for (const [i, image] of map.entries()) {
      const x = Number(image.x_px), y = Number(image.y_px), w = Number(image.width_px), h = Number(image.height_px)
      assert.ok(x >= 0 && y >= 0 && x + w <= canvasWidth && y + h <= canvasHeight)
      assert.ok(w >= 60 && h >= 60)
      for (const other of map.slice(i + 1)) assert.ok(x + w <= Number(other.x_px) || Number(other.x_px) + Number(other.width_px) <= x || y + h <= Number(other.y_px) || Number(other.y_px) + Number(other.height_px) <= y)
    }
    assert.equal(settings.image_1_width_px, '280')
  })
}
